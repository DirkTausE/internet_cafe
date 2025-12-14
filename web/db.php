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
    // minimal sane defaults only
    $cfg = [
        'host'    => getenv('DB_HOST') ?: null,
        'port'    => getenv('DB_PORT') ?: null,
        'name'    => getenv('DB_NAME') ?: null,
        'user'    => getenv('DB_USER') ?: null,
        'pass'    => getenv('DB_PASS') ?: null,
        'charset' => 'utf8mb4',
    ];

    // corrected path: config.php is in project root (one level up from web/)
    $cfgPath = __DIR__ . '/../config.php';
    if (file_exists($cfgPath)) {
        // capture return value if config.php returns an array
        $maybe = @include $cfgPath;

        // If config.php returned an array, merge sensible keys (support uppercase or lowercase)
        if (is_array($maybe)) {
            // try common key names in the returned array
            $map = [
                'DB_HOST' => 'host', 'db_host' => 'host',
                'DB_PORT' => 'port', 'db_port' => 'port',
                'DB_NAME' => 'name', 'db_name' => 'name',
                'DB_USER' => 'user', 'db_user' => 'user',
                'DB_PASS' => 'pass', 'db_pass' => 'pass',
                'PDO_DSN' => 'pdo_dsn',
            ];
            // merge recognized keys
            foreach ($maybe as $k => $v) {
                if (isset($map[$k])) {
                    $cfg[$map[$k]] = $v;
                    continue;
                }
                $lkLower = strtolower((string)$k);
                if (in_array($lkLower, ['db_host','db_port','db_name','db_user','db_pass','pdo_dsn'], true)) {
                    $target = str_replace('db_', '', $lkLower);
                    if ($target === 'dsn') $target = 'pdo_dsn';
                    $cfg[$target] = $v;
                }
            }
        }

        // Some projects might define constants instead of returning array
        if (defined('DB_HOST') && DB_HOST !== null) $cfg['host'] = DB_HOST;
        if (defined('DB_PORT') && DB_PORT !== null) $cfg['port'] = DB_PORT;
        if (defined('DB_NAME') && DB_NAME !== null) $cfg['name'] = DB_NAME;
        if (defined('DB_USER') && DB_USER !== null) $cfg['user'] = DB_USER;
        if (defined('DB_PASS') && DB_PASS !== null) $cfg['pass'] = DB_PASS;
    }

    // Also try a local overrides file in web/ (optional)
    $localWeb = __DIR__ . '/config.local.php';
    if (file_exists($localWeb)) {
        $maybeLocal = @include $localWeb;
        if (is_array($maybeLocal)) {
            foreach (['host','port','name','user','pass','charset','pdo_dsn'] as $k) {
                if (array_key_exists($k, $maybeLocal)) $cfg[$k] = $maybeLocal[$k];
            }
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
    if (empty($cfg['host'])) {
        // prefer explicit host; fallback to localhost
        $cfg['host'] = '127.0.0.1';
    }

    // if a full PDO_DSN was provided, prefer it
    $dsn = $cfg['pdo_dsn'] ?? null;
    if (empty($dsn)) {
        // include port if present
        if (!empty($cfg['port'])) {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']);
        } else {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['name'], $cfg['charset']);
        }
    }

    try {
        $pdo = new PDO($dsn, $cfg['user'] ?? '', $cfg['pass'] ?? '', [
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
    if ($s === '') return null;
    $map = [
        'starting' => 'starting', 'start' => 'starting', 'booting' => 'starting',
        'frei' => 'frei', 'free' => 'frei', 'available' => 'frei', 'idle' => 'frei',
        'gast' => 'gast', 'guest' => 'gast',
        'pause' => 'pause', 'paused' => 'pause', 'break' => 'pause',
        'wartung' => 'wartung', 'maintenance' => 'wartung', 'maint' => 'wartung',
        'stop' => 'stop', 'stopped' => 'stop',
        'off' => 'off', 'poweroff' => 'off', 'poweredoff' => 'off', 'shutdown' => 'off',
    ];
    if (isset($map[$s])) return $map[$s];
    foreach ($map as $k => $v) {
        if (strpos($s, $k) !== false) return $v;
    }
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

                    // Determine admin-set state (column 'state' or 'status') separately from current_state
                    $admin_raw = null;
                    if (array_key_exists('state', $r) && $r['state'] !== null) $admin_raw = $r['state'];
                    elseif (array_key_exists('status', $r) && $r['status'] !== null) $admin_raw = $r['status'];

                    // Determine current_state (prefer explicit column 'current_state')
                    $current_raw = null;
                    if (array_key_exists('current_state', $r) && $r['current_state'] !== null) {
                        $current_raw = $r['current_state'];
                    } else {
                        // fallback: try any of the usual columns to infer current state
                        foreach (['state','status','mode'] as $cname) {
                            if (array_key_exists($cname, $r) && $r[$cname] !== null) {
                                $current_raw = $r[$cname];
                                break;
                            }
                        }
                        // boolean-like fallbacks
                        if ($current_raw === null) {
                            foreach (['is_on','online','powered','power'] as $k) {
                                if (array_key_exists($k, $r)) {
                                    $v = $r[$k];
                                    if (in_array($v, [1,'1',true,'true','on','online','up'], true)) {
                                        $current_raw = 'frei';
                                    } elseif (in_array($v, [0,'0',false,'false','off','down'], true)) {
                                        $current_raw = 'off';
                                    }
                                    break;
                                }
                            }
                        }
                    }

                    $state = normalize_state($admin_raw);
                    $current_state = normalize_state($current_raw);

                    $occupied_by = $r['occupied_by'] ?? $r['client_id'] ?? $r['user_id'] ?? null;
                    $out[] = [
                        'id' => $id,
                        'name' => $name,
                        'state' => $state,               // admin-controlled canonical state (may be null)
                        'current_state' => $current_state, // actual/current canonical state (may be null)
                        'occupied_by' => $occupied_by,
                        'raw' => $r,
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
    $pdo = $pdo ?? db_connect();
    if (!$pdo) return [];

    // Try common customer/user table names
    $candidates = ['customers','users','clients','customers_public','accounts'];
    foreach ($candidates as $t) {
        if (table_exists($pdo, $t)) {
            try {
                $sql = "SELECT * FROM `" . str_replace('`','', $t) . "` ORDER BY id ASC LIMIT 1000";
                $rows = $pdo->query($sql)->fetchAll();
                $out = [];
                foreach ($rows as $r) {
                    // map typical fields with fallbacks
                    $id = $r['id'] ?? $r['user_id'] ?? $r['client_id'] ?? null;
                    $name = $r['name'] ?? $r['full_name'] ?? $r['username'] ?? $r['display_name'] ?? '';
                    $email = $r['email'] ?? $r['mail'] ?? '';
                    $balance = $r['balance'] ?? $r['credit'] ?? $r['amount'] ?? null;
                    $notes = $r['notes'] ?? $r['status'] ?? $r['comment'] ?? null;

                    $out[] = [
                        'id' => $id,
                        'name' => $name,
                        'email' => $email,
                        'balance' => $balance,
                        'notes' => $notes,
                        'raw' => $r,
                    ];
                }
                return $out;
            } catch (Throwable $e) {
                error_log('fetch_customers query failed: ' . $e->getMessage());
                return [];
            }
        }
    }

    return [];
}

// aliases if needed
if (!function_exists('fetchComputers') && function_exists('fetch_computers')) {
    function fetchComputers(...$args) { return fetch_computers(...$args); }
}
if (!function_exists('fetchCustomers') && function_exists('fetch_customers')) {
    function fetchCustomers(...$args) { return fetch_customers(...$args); }
}

function db_get_pdo(): ?PDO {
    return db_connect();
}
