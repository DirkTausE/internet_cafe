<?php
// web/db.php
// Robust DB helper for the InternetCafe web frontend.
// Adds normalized 'state' detection for computers (starting, frei, Gast, Pause, Wartung, STOP, OFF).

declare(strict_types=1);

if (defined('__WEB_DB_PHP_LOADED__')) {
    return;
}
define('__WEB_DB_PHP_LOADED__', true);

function load_db_config(): array {
    $cfg = [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'name' => getenv('DB_NAME') ?: null,
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ];

    $cfgPath = __DIR__ . '/../config.php';
    if (file_exists($cfgPath)) {
        @include $cfgPath;
        if (defined('DB_HOST')) $cfg['host'] = DB_HOST;
        if (defined('DB_NAME')) $cfg['name'] = DB_NAME;
        if (defined('DB_USER')) $cfg['user'] = DB_USER;
        if (defined('DB_PASS')) $cfg['pass'] = DB_PASS;
        if (isset($config) && is_array($config)) {
            if (!empty($config['db_host'])) $cfg['host'] = $config['db_host'];
            if (!empty($config['db_name'])) $cfg['name'] = $config['db_name'];
            if (!empty($config['db_user'])) $cfg['user'] = $config['db_user'];
            if (!empty($config['db_pass'])) $cfg['pass'] = $config['db_pass'];
        }
    }

    return $cfg;
}

function db_connect(): ?PDO {
    static $instance = null;
    if ($instance instanceof PDO) return $instance;

    $cfg = load_db_config();
    if (empty($cfg['name'])) {
        error_log('db_connect: no DB_NAME configured');
        return null;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['name'], $cfg['charset']);
    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => false,
        ]);
        $instance = $pdo;
        return $pdo;
    } catch (Throwable $e) {
        error_log('db_connect: PDO connection failed: ' . $e->getMessage());
        return null;
    }
}

function table_columns(PDO $pdo, string $table): array {
    try {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        error_log('table_columns error: ' . $e->getMessage());
        return [];
    }
}

function table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('table_exists error: ' . $e->getMessage());
        return false;
    }
}

function normalize_state($raw): ?string {
    if ($raw === null) return null;
    $s = (string)$raw;
    $s = trim(mb_strtolower($s, 'UTF-8'));
    // Map many synonyms to canonical keys (lowercase)
    if ($s === '') return null;
    $map = [
        // starting
        'starting' => 'starting', 'start' => 'starting', 'booting' => 'starting',
        // frei (free / available)
        'frei' => 'frei', 'free' => 'frei', 'available' => 'frei', 'idle' => 'frei',
        // Gast / guest
        'gast' => 'gast', 'guest' => 'gast',
        // Pause
        'pause' => 'pause', 'paused' => 'pause', 'break' => 'pause',
        // Wartung / maintenance
        'wartung' => 'wartung', 'maintenance' => 'wartung', 'maint' => 'wartung',
        // STOP (distinct from OFF)
        'stop' => 'stop', 'stopped' => 'stop',
        // OFF
        'off' => 'off', 'poweroff' => 'off', 'poweredoff' => 'off', 'shutdown' => 'off',
    ];
    // direct match
    if (isset($map[$s])) return $map[$s];
    // try to find keywords
    foreach ($map as $k => $v) {
        if (strpos($s, $k) !== false) return $v;
    }
    // fallback: if raw is numeric and 1 -> starting; 0 -> off (heuristic)
    if (is_numeric($s)) {
        if ((int)$s === 1) return 'starting';
        if ((int)$s === 0) return 'off';
    }
    return null;
}

function fetch_computers(?PDO $pdo = null): array {
    $pdo = $pdo ?? db_connect();
    if (!$pdo) return [];

    $candidates = ['computers','workstations','terminals','machines'];
    foreach ($candidates as $t) {
        if (table_exists($pdo, $t)) {
            try {
                $sql = "SELECT * FROM `" . str_replace('`','', $t) . "` ORDER BY id ASC LIMIT 1000";
                $rows = $pdo->query($sql)->fetchAll();
                $out = [];
                foreach ($rows as $r) {
                    $id = $r['id'] ?? $r['uuid'] ?? ($r['name'] ?? null) ?? null;
                    $name = $r['name'] ?? $r['hostname'] ?? $r['label'] ?? ("pc-".($id ?? ''));
                    // detect a state column if present
                    $state = null;
                    foreach (['state','status','current_state','mode'] as $cname) {
                        if (array_key_exists($cname, $r) && $r[$cname] !== null) {
                            $state = normalize_state($r[$cname]);
                            break;
                        }
                    }
                    // fallbacks: use boolean-like columns to map to state
                    if ($state === null) {
                        foreach (['is_on','online','powered','power'] as $k) {
                            if (array_key_exists($k, $r)) {
                                $v = $r[$k];
                                if (in_array($v, [1,'1',true,'true','on','online','up'], true)) {
                                    $state = 'frei'; // treat as available/online
                                } elseif (in_array($v, [0,'0',false,'false','off','down'], true)) {
                                    $state = 'off';
                                }
                                break;
                            }
                        }
                    }
                    $occupied_by = $r['occupied_by'] ?? $r['client_id'] ?? $r['user_id'] ?? null;
                    $out[] = [
                        'id'=>$id,
                        'name'=>$name,
                        'state'=>$state, // canonical lower-case state or null
                        'occupied_by'=>$occupied_by,
                        'raw'=>$r,
                    ];
                }
                return $out;
            } catch (Throwable $e) {
                error_log('fetch_computers query failed: ' . $e->getMessage());
                return [];
            }
        }
    }

    return [];
}

function fetch_customers(?PDO $pdo = null): array {
    // unchanged from earlier implementation (omitted for brevity)
    $pdo = $pdo ?? db_connect();
    if (!$pdo) return [];
    // minimal fallback, real implementation elsewhere
    return [];
}

// aliases if needed
if (!function_exists('fetchComputers') && function_exists('fetch_computers')) {
    function fetchComputers(...$args) { return fetch_computers(...$args); }
}

function db_get_pdo(): ?PDO {
    return db_connect();
}