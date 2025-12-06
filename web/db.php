<?php
// web/db.php
// Robust DB helper for the InternetCafe web frontend.
// - Detects available columns to avoid SQL "Unknown column" errors.
// - Provides fetch_computers(), fetch_customers(), db_get_pdo().

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
    if ($instance instanceof PDO) {
        return $instance;
    }

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
                    $is_on = null;
                    foreach (['is_on','online','powered','power','status','current_state'] as $k) {
                        if (array_key_exists($k, $r)) {
                            $v = $r[$k];
                            $is_on = in_array($v, [1,'1',true,'true','on','online','up','frei','gast'], true)
                                ? true
                                : (in_array($v, [0,'0',false,'false','off','down','OFF','STOP'], true) ? false : null);
                            break;
                        }
                    }
                    $occupied_by = $r['occupied_by'] ?? $r['client_id'] ?? $r['user_id'] ?? null;
                    $out[] = ['id'=>$id,'name'=>$name,'is_on'=>$is_on,'occupied_by'=>$occupied_by,'raw'=>$r];
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

    // 1) invoices aggregation (only if safe columns detected)
    if (table_exists($pdo, 'invoices')) {
        $invCols = table_columns($pdo, 'invoices');

        // detect amount-like column
        $amountCol = null;
        foreach (['total_amount','total','amount','price','sum','net_total'] as $c) {
            if (in_array($c, $invCols, true)) { $amountCol = $c; break; }
        }

        // unpaid indicators
        $unpaidChecks = [];
        if (in_array('paid', $invCols, true)) {
            $unpaidChecks[] = "(i.paid = 0 OR i.paid IS NULL)";
        }
        if (in_array('status', $invCols, true)) {
            $unpaidChecks[] = "(LOWER(COALESCE(i.status, '')) NOT IN ('paid','settled','done'))";
        }
        if (in_array('is_paid', $invCols, true)) {
            $unpaidChecks[] = "(i.is_paid = 0 OR i.is_paid IS NULL)";
        }

        if ($amountCol !== null && !empty($unpaidChecks)) {
            $whereClause = '(' . implode(' OR ', $unpaidChecks) . ')';
            $sql = sprintf(
                "SELECT i.customer_id AS cid, SUM(COALESCE(i.`%s`,0)) AS due
                 FROM invoices i
                 WHERE %s
                 GROUP BY i.customer_id
                 HAVING due > 0
                 LIMIT 500",
                 $amountCol,
                 $whereClause
            );
            try {
                $rows = $pdo->query($sql)->fetchAll();
                $customers = [];
                foreach ($rows as $r) {
                    $cid = $r['cid'];
                    $due = (float)($r['due'] ?? 0);
                    $name = null;
                    if (table_exists($pdo, 'customers')) {
                        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
                        $stmt->execute([$cid]);
                        $c = $stmt->fetch();
                        if ($c) $name = $c['name'] ?? $c['username'] ?? $c['email'] ?? null;
                    } elseif (table_exists($pdo, 'clients')) {
                        $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ? LIMIT 1');
                        $stmt->execute([$cid]);
                        $c = $stmt->fetch();
                        if ($c) $name = $c['name'] ?? $c['username'] ?? $c['email'] ?? null;
                    }
                    $customers[] = ['id'=>$cid,'name'=>$name ?? ('customer-'.($cid ?? '')),'due'=>$due];
                }
                if (!empty($customers)) return $customers;
            } catch (Throwable $e) {
                error_log('fetch_customers invoices query failed: ' . $e->getMessage());
            }
        } else {
            error_log('fetch_customers: skipping invoice aggregation; amountCol=' . ($amountCol ?? 'NULL') . ' unpaidChecks=' . json_encode($unpaidChecks));
        }
    }

    // 2) Fallback: select * and evaluate in PHP to avoid unknown-column errors
    $candidates = ['customers','clients','users'];
    foreach ($candidates as $t) {
        if (table_exists($pdo, $t)) {
            try {
                $rows = $pdo->query("SELECT * FROM `" . str_replace('`','', $t) . "` LIMIT 1000")->fetchAll();
                $out = [];
                foreach ($rows as $r) {
                    $id = $r['id'] ?? null;
                    $name = $r['name'] ?? $r['username'] ?? $r['email'] ?? ("id-".($id ?? ''));
                    $due = 0.0;
                    if (array_key_exists('balance', $r) && is_numeric($r['balance'])) $due = (float)$r['balance'];
                    elseif (array_key_exists('due_amount', $r) && is_numeric($r['due_amount'])) $due = (float)$r['due_amount'];
                    elseif (array_key_exists('debt', $r) && is_numeric($r['debt'])) $due = (float)$r['debt'];
                    if ($due > 0.0) $out[] = ['id'=>$id,'name'=>$name,'due'=>$due];
                }
                if (!empty($out)) return $out;
            } catch (Throwable $e) {
                error_log('fetch_customers fallback query failed: ' . $e->getMessage());
            }
        }
    }

    return [];
}

// camelCase aliases
if (!function_exists('fetchComputers') && function_exists('fetch_computers')) {
    function fetchComputers(...$args) { return fetch_computers(...$args); }
}
if (!function_exists('fetchCustomers') && function_exists('fetch_customers')) {
    function fetchCustomers(...$args) { return fetch_customers(...$args); }
}

function db_get_pdo(): ?PDO {
    return db_connect();
}