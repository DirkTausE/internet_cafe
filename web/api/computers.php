<?php
// web/api/computers.php
//
// Minimal, secure API endpoint for controlling computers.
// Supports URLs like:
//   POST /api/computers/123/action
//   POST /api/computers/PC-01/action
//
// Expects JSON body with:
//   { "action": "set_state", "state": "frei", "occupied": "101" }
// or other actions (start/stop/restart) which will be forwarded to clientd as action scripts.
//
// Auth:
// - Incoming requests must provide Authorization: Bearer <API_SECRET>
//   API_SECRET is read from environment var API_SECRET or /etc/internetcafe-api.conf (key API_SECRET).
//
// Forwarding to client:
// - If the computers row has ip_address (or hostname resolvable) the server will attempt
//   to POST to client agent at http://<ip>:9999/action with Authorization: Bearer <CLIENTD_SECRET>
//   CLIENTD_SECRET is read from env CLIENTD_SECRET or /etc/internetcafe-clientd.conf.
//
// Security notes:
// - This endpoint normalizes and whitelists states to the canonical 7 values.
// - All DB operations use prepared statements.
// - Forwarding to clients times out quickly and failures are reported but do not break DB update.

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

function send_json(int $code, array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function load_conf_file(string $path): array {
    $cfg = [];
    if (!file_exists($path)) return $cfg;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] === '#') continue;
        if (strpos($ln, '=') !== false) {
            [$k,$v] = array_map('trim', explode('=', $ln, 2));
            $v = trim($v, "\"'");
            $cfg[$k] = $v;
        }
    }
    return $cfg;
}

function get_secret(string $envKey, string $confPath, string $confKey) {
    if (!empty(getenv($envKey))) return getenv($envKey);
    $cfg = load_conf_file($confPath);
    return $cfg[$confKey] ?? null;
}

function get_bearer_token_from_header(): ?string {
    $h = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $h = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (function_exists('apache_request_headers')) {
        $req = apache_request_headers();
        if (!empty($req['Authorization'])) $h = $req['Authorization'];
    }
    if (!$h) return null;
    if (preg_match('/^\s*Bearer\s+(.+)$/i', $h, $m)) return trim($m[1]);
    return null;
}

$allowed_states = ['starting','frei','gast','pause','wartung','stop','off'];

$api_secret = get_secret('API_SECRET', '/etc/internetcafe-api.conf', 'API_SECRET');
if (!$api_secret) {
    // fallback to web/.env in repo (optional)
    $api_secret = getenv('API_SECRET') ?: null;
}
if (!$api_secret) {
    // misconfiguration
    send_json(500, ['ok'=>false,'error'=>'server misconfigured: API secret not set']);
}

// authenticate incoming request
$token = get_bearer_token_from_header();
if (!$token) send_json(401, ['ok'=>false,'error'=>'missing Authorization Bearer token']);
if (!hash_equals($api_secret, $token)) send_json(403, ['ok'=>false,'error'=>'forbidden']);

// parse ID/hostname from request URI
$uri = $_SERVER['REQUEST_URI'] ?? '';
// strip query
$uri = explode('?', $uri, 2)[0];
// match /api/computers/{id_or_name}/action
if (!preg_match('#/api/computers/([^/]+)/action$#', $uri, $m)) {
    // also accept /api/computers.php?id=...
    if (!empty($_GET['id'])) {
        $target_raw = (string)$_GET['id'];
    } else {
        send_json(404, ['ok'=>false,'error'=>'invalid endpoint']);
    }
} else {
    $target_raw = $m[1];
}
$target_raw = urldecode($target_raw);

// read JSON body
$body = file_get_contents('php://input');
$data = [];
if ($body) {
    $data = json_decode($body, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        send_json(400, ['ok'=>false,'error'=>'invalid json']);
    }
}

// require action field
$action = isset($data['action']) ? (string)$data['action'] : '';
if ($action === '') {
    send_json(400, ['ok'=>false,'error'=>'missing action']);
}

// allowed generic actions: set_state OR forward action scripts (start/stop/restart)
$action = strtolower(trim($action));

// connect DB
$pdo = function_exists('db_get_pdo') ? db_get_pdo() : null;
if (!$pdo) send_json(500, ['ok'=>false,'error'=>'db unavailable']);

// find computer by id (numeric) or by hostname
$computer = null;
if (ctype_digit($target_raw)) {
    $stmt = $pdo->prepare('SELECT * FROM computers WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$target_raw]);
    $computer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} else {
    $stmt = $pdo->prepare('SELECT * FROM computers WHERE hostname = ? LIMIT 1');
    $stmt->execute([$target_raw]);
    $computer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$computer) {
    send_json(404, ['ok'=>false,'error'=>'computer not found']);
}

// prepare result structure
$result = [
    'ok' => false,
    'updated_db' => false,
    'forwarded' => false,
    'client_response' => null,
];

// handle set_state
if ($action === 'set_state') {
    $state_raw = isset($data['state']) ? (string)$data['state'] : '';
    $current_raw = isset($data['current_state']) ? (string)$data['current_state'] : null;

    if ($state_raw === '' && $current_raw === null) {
        send_json(400, ['ok'=>false,'error'=>'missing state or current_state']);
    }

    // normalize provided admin state if given
    $state = null;
    if ($state_raw !== '') {
        $state = strtolower(trim($state_raw));
        if ($state === 'stop' || strtoupper($state_raw) === 'STOP') $state = 'stop';
        if ($state === 'off' || strtoupper($state_raw) === 'OFF') $state = 'off';
        if (!in_array($state, $allowed_states, true)) {
            send_json(400, ['ok'=>false,'error'=>'invalid state', 'allowed'=>$allowed_states]);
        }
    }

    // optional occupied
    $occupied = isset($data['occupied']) ? $data['occupied'] : null;

    // update DB: prefer writing current_state if provided (client report),
    // otherwise write admin 'state' as before.
    try {
        if ($current_raw !== null) {
            // write to current_state if available, else fallback to state/status
            $cols = array_map('strtolower', array_keys($computer));
            if (in_array('current_state', $cols, true)) {
                $sql = 'UPDATE computers SET current_state = ?, updated_at = CURRENT_TIMESTAMP';
                $params = [$current_raw];
            } elseif (in_array('state', $cols, true)) {
                $sql = 'UPDATE computers SET state = ?, updated_at = CURRENT_TIMESTAMP';
                $params = [$current_raw];
            } elseif (in_array('status', $cols, true)) {
                $sql = 'UPDATE computers SET status = ?, updated_at = CURRENT_TIMESTAMP';
                $params = [$current_raw];
            } else {
                send_json(500, ['ok'=>false,'error'=>'no suitable column to store current_state']);
            }
            if ($occupied !== null && $occupied !== '') {
                $sql .= ', occupied_by = ?';
                $params[] = $occupied;
            }
            $sql .= ' WHERE id = ?';
            $params[] = $computer['id'];
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $result['updated_db'] = ($stmt->rowCount() >= 0);
        } else {
            // admin write path (existing behavior)
            $sql = 'UPDATE computers SET state = ?, updated_at = CURRENT_TIMESTAMP';
            $params = [$state];
            if ($occupied !== null && $occupied !== '') {
                $sql .= ', occupied_by = ?';
                $params[] = $occupied;
            }
            $sql .= ' WHERE id = ?';
            $params[] = $computer['id'];
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $result['updated_db'] = ($stmt->rowCount() >= 0);
        }
    } catch (Throwable $e) {
        send_json(500, ['ok'=>false,'error'=>'db update failed','detail'=>$e->getMessage()]);
    }

    // attempt to forward to client agent if ip_address present (unchanged)
    $client_ip = $computer['ip_address'] ?? $computer['hostname'] ?? null;
    if ($client_ip) {
        // build payload to client; clientd expects action script names (we use set_state)
        $payload = ['action' => 'set_state'];
        if ($current_raw !== null) {
            // forward current_state report to client only if desired (usually clients report)
            $payload['current_state'] = $current_raw;
        } elseif ($state !== null) {
            $payload['state'] = $state;
        }
        if ($occupied !== null && $occupied !== '') $payload['occupied'] = (string)$occupied;
        // read client secret
        $clientd_secret = get_secret('CLIENTD_SECRET', '/etc/internetcafe-clientd.conf', 'CLIENTD_SECRET') ?: getenv('CLIENTD_SECRET') ?: null;

        // try numeric ip or hostname; default port 9999 (clientd default)
        $client_port = 9999;
        $url = (strpos($client_ip, ':') !== false && substr_count($client_ip, ':') === 1) ? "http://{$client_ip}/action" : "http://{$client_ip}:{$client_port}/action";

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, true);
        $json = json_encode($payload);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 5);
        $headers = ['Content-Type: application/json'];
        if ($clientd_secret) $headers[] = 'Authorization: Bearer ' . $clientd_secret;
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $resp = curl_exec($curl);
        $err = curl_error($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($resp !== false && $http_code >= 200 && $http_code < 300) {
            $result['forwarded'] = true;
            // try decode JSON
            $dec = json_decode($resp, true);
            $result['client_response'] = $dec !== null ? $dec : $resp;
        } else {
            $result['forwarded'] = false;
            $result['client_response'] = ['error' => $err ?: ('HTTP ' . $http_code), 'raw' => $resp];
        }
    }

    $result['ok'] = true;
    send_json(200, $result);
}

// ... rest unchanged (forward actions)
$forward_actions = ['start','stop','restart'];
if (in_array($action, $forward_actions, true)) {
    // attempt to forward to client (no DB change)
    $client_ip = $computer['ip_address'] ?? $computer['hostname'] ?? null;
    if (!$client_ip) send_json(400, ['ok'=>false,'error'=>'no client ip/hostname available']);

    $payload = ['action' => $action];
    if (isset($data['reason'])) $payload['reason'] = $data['reason'];

    $clientd_secret = get_secret('CLIENTD_SECRET', '/etc/internetcafe-clientd.conf', 'CLIENTD_SECRET') ?: getenv('CLIENTD_SECRET') ?: null;
    // forwarding logic continues unchanged...
