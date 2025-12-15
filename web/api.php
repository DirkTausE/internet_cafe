<?php
// web/api.php - minimal API for internet_cafe
// Endpoints: ?q=ping, ?q=status, ?q=version
// Also supports action=... for client.d requests (POST or GET)
// API key support: X-API-KEY header or ?api_key=... if configured in config.php
//
// Security: client agents using the API_KEY may only READ admin 'state' and may only
// write 'current_state'. Any attempt by a client (API_KEY) to set the admin 'state'
// is rejected. Admin operations that change the admin 'state' must use the
// authenticated admin endpoints (e.g. web/api/computers.php with Bearer token).

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Load optional config (do not raise errors if not present)
$expectedApiKey = null;
$apiKeyRequired = false;
$appVersion = '0.1.0';
$appName = 'internetcafe';
$configPath = __DIR__ . '/../config.php';
if (file_exists($configPath)) {
    @include $configPath;
    if (defined('API_KEY')) {
        $expectedApiKey = API_KEY;
        $apiKeyRequired = true;
    } elseif (isset($config) && is_array($config) && isset($config['api_key'])) {
        $expectedApiKey = $config['api_key'];
        $apiKeyRequired = true;
    }
    if (defined('APP_VERSION')) {
        $appVersion = APP_VERSION;
    } elseif (isset($config['version'])) {
        $appVersion = (string)$config['version'];
    }
    if (defined('APP_NAME')) {
        $appName = APP_NAME;
    } elseif (isset($config['name'])) {
        $appName = (string)$config['name'];
    }
}

// Helper: read git short sha if available (optional)
$gitSha = null;
$gitHeadFile = __DIR__ . '/../.git/HEAD';
if (is_readable($gitHeadFile)) {
    $head = trim(@file_get_contents($gitHeadFile));
    if (preg_match('/^ref: (.+)$/', $head, $m)) {
        $ref = __DIR__ . '/../.git/' . $m[1];
        if (is_readable($ref)) {
            $gitSha = substr(trim(@file_get_contents($ref)), 0, 12);
        }
    }
}

// API key check (if configured, require it for requests)
$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
if ($apiKeyRequired && !$providedKey) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'api_key_required'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($apiKeyRequired && $expectedApiKey !== null && $providedKey !== $expectedApiKey) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_api_key'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Read parameters (GET/POST), also accept JSON body for POST
$q = $_GET['q'] ?? $_POST['q'] ?? null;
$action = $_GET['action'] ?? $_POST['action'] ?? null;
$inputJson = null;
if (empty($q) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $inputJson = $decoded;
            if (isset($decoded['q'])) {
                $q = $decoded['q'];
            }
            if (isset($decoded['action'])) {
                $action = $decoded['action'];
            }
        }
    }
}

// Simple Action handler for client.d requests
class ActionHandler
{
    // Handle action and return an array to be json-encoded
    public function handle(string $action, $params = null): array
    {
        // Make the expectedApiKey available if present to decide client vs admin
        $expectedApiKey = $GLOBALS['expectedApiKey'] ?? null;

        switch ($action) {
            case 'echo':
                // returns whatever was sent
                return ['ok' => true, 'action' => 'echo', 'data' => $params];

            case 'status_check':
                // lightweight status check for clients
                return ['ok' => true, 'action' => 'status_check', 'time' => date('c')];

            // pc/get_state: used by clientd to read administrative 'state' and current_state
            case 'pc/get_state':
                // params expected: ['host' => '<hostname>'] or ['id' => <id>]
                $host = $params['host'] ?? $_GET['host'] ?? $_POST['host'] ?? null;
                $id = $params['id'] ?? $_GET['id'] ?? $_POST['id'] ?? null;
                if (empty($host) && empty($id)) {
                    return ['ok' => false, 'error' => 'missing host or id'];
                }
                if (!function_exists('db_get_pdo')) {
                    return ['ok' => false, 'error' => 'db helper not available'];
                }
                $pdo = db_get_pdo();
                if (!$pdo) return ['ok' => false, 'error' => 'db unavailable'];
                try {
                    if (!empty($id) && ctype_digit((string)$id)) {
                        $stmt = $pdo->prepare('SELECT * FROM computers WHERE id = ? LIMIT 1');
                        $stmt->execute([(int)$id]);
                    } else {
                        $stmt = $pdo->prepare('SELECT * FROM computers WHERE hostname = ? OR name = ? LIMIT 1');
                        $stmt->execute([$host, $host]);
                    }
                    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    if (!$row) return ['ok' => false, 'error' => 'computer not found'];
                    // return both admin state and current_state (if present)
                    $resp = [
                        'ok' => true,
                        'computer' => [
                            'id' => $row['id'] ?? null,
                            'name' => $row['name'] ?? ($row['hostname'] ?? null),
                            'state' => $row['state'] ?? $row['status'] ?? null,      // admin state
                            'current_state' => $row['current_state'] ?? null,       // actual state
                            'raw' => $row,
                        ],
                    ];
                    return $resp;
                } catch (Throwable $e) {
                    return ['ok' => false, 'error' => 'db error: '.$e->getMessage()];
                }

            // pc/set_state: used by clientd to report measured current_state.
            // Clients authenticated with API_KEY are allowed to write only 'current_state'.
            // Admin changes to the admin 'state' must go through admin endpoints (e.g. web/api/computers.php).
            case 'pc/set_state':
                // params may include: host, id, current_state (client report)
                $host = $params['host'] ?? $_GET['host'] ?? $_POST['host'] ?? null;
                $id = $params['id'] ?? $_GET['id'] ?? $_POST['id'] ?? null;
                $incoming_state = isset($params['state']) ? $params['state'] : (isset($_POST['state']) ? $_POST['state'] : null);
                $incoming_current = isset($params['current_state']) ? $params['current_state'] : (isset($_POST['current_state']) ? $_POST['current_state'] : null);

                if (empty($host) && empty($id)) {
                    return ['ok' => false, 'error' => 'missing host or id'];
                }
                if (!function_exists('db_get_pdo')) {
                    return ['ok' => false, 'error' => 'db helper not available'];
                }

                // Determine whether request is client-authenticated by API_KEY (valid)
                $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
                $clientAuth = false;
                if ($providedKey !== null && $expectedApiKey !== null) {
                    // use hash_equals if available
                    $clientAuth = function_exists('hash_equals') ? hash_equals((string)$expectedApiKey, (string)$providedKey) : ($expectedApiKey === $providedKey);
                }

                // If client-authenticated, reject any attempt to set admin 'state'
                if ($clientAuth && $incoming_state !== null) {
                    return ['ok' => false, 'error' => 'clients are not allowed to set admin state', 'code' => 'forbidden_client_write'];
                }

                // Only allow 'current_state' writes here (client reports)
                if ($incoming_current === null) {
                    // nothing for client to write; instruct to use admin endpoint if incoming_state provided but not allowed
                    return ['ok' => false, 'error' => 'missing current_state', 'note' => 'clients may only write current_state'];
                }

                // Proceed to update DB: prefer current_state column, fallback to state/status if needed
                $pdo = db_get_pdo();
                if (!$pdo) return ['ok' => false, 'error' => 'db unavailable'];
                try {
                    if (!empty($id) && ctype_digit((string)$id)) {
                        $stmt = $pdo->prepare('SELECT * FROM computers WHERE id = ? LIMIT 1');
                        $stmt->execute([(int)$id]);
                    } else {
                        $stmt = $pdo->prepare('SELECT * FROM computers WHERE hostname = ? OR name = ? LIMIT 1');
                        $stmt->execute([$host, $host]);
                    }
                    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    if (!$row) return ['ok' => false, 'error' => 'computer not found'];

                    $cols = array_map('strtolower', array_keys($row));
                    if (in_array('current_state', $cols, true)) {
                        $target = 'current_state';
                    } elseif (in_array('state', $cols, true)) {
                        $target = 'state';
                    } elseif (in_array('status', $cols, true)) {
                        $target = 'status';
                    } else {
                        return ['ok' => false, 'error' => 'no suitable column to write current_state'];
                    }

                    $u = $pdo->prepare("UPDATE `" . str_replace('`','', 'computers') . "` SET `$target` = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? LIMIT 1");
                    $u->execute([(string)$incoming_current, $row['id']]);
                    return ['ok' => true, 'updated_column' => $target, 'value' => $incoming_current];
                } catch (Throwable $e) {
                    return ['ok' => false, 'error' => 'db error: '.$e->getMessage()];
                }

            default:
                return ['ok' => false, 'error' => 'unknown_action', 'action' => $action];
        }
    }
}

// Handler for q endpoints
if ($q === 'ping') {
    echo json_encode(['status' => 'ok', 'time' => date('c')], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($q === 'status') {
    // Try to get system uptime (Linux) as an extra field, optional
    $uptime = null;
    if (is_readable('/proc/uptime')) {
        $parts = preg_split('/\s+/', trim(@file_get_contents('/proc/uptime')));
        if (isset($parts[0])) {
            $uptime = (float)$parts[0];
        }
    }
    $payload = [
        'status' => 'ok',
        'time' => date('c'),
        'name' => $appName,
        'version' => $appVersion,
        'git' => $gitSha,
        'uptime_seconds' => $uptime,
    ];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($q === 'version') {
    $payload = [
        'name' => $appName,
        'version' => $appVersion,
        'git' => $gitSha,
        'time' => date('c'),
    ];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle action=... (client.d)
if (!empty($action)) {
    $handler = new ActionHandler();
    // Prefer JSON body params if available, else GET/POST params
    $params = $inputJson['params'] ?? $_POST['params'] ?? $_GET['params'] ?? null;
    // If params is JSON string, attempt decode
    if (is_string($params)) {
        $decoded = json_decode($params, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $params = $decoded;
        }
    }
    $result = $handler->handle((string)$action, $params);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// Default: invalid request
http_response_code(400);
echo json_encode(['error' => 'invalid_request', 'usage' => '?q=ping|status|version or action=...'], JSON_UNESCAPED_UNICODE);
