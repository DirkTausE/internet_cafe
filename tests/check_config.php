<?php
// tests/check_config.php — prüft config und PDO-Factory
// Liefert menschenlesbare Ausgabe (mit Maskierung sensibler Werte).
// WARNUNG: Dieses Script kann sensible Infos anzeigen. Nur lokal / per localhost aufrufen.

$allowedLocal = ['127.0.0.1', '::1', 'localhost'];
$remote = $_SERVER['REMOTE_ADDR'] ?? 'cli';
$sapi = PHP_SAPI;

// Erlaube CLI und lokale HTTP-Requests
if ($sapi !== 'cli' && !in_array($remote, $allowedLocal, true)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

function mask_value(string $key, $val) {
    // Maskiere sensible Schlüssel
    if (is_string($val) && $val === '') {
        return '<EMPTY>';
    }
    if (preg_match('/(pass|secret|key|token|hash|pwd)/i', $key)) {
        if (is_string($val)) {
            return '***MASKED(' . strlen($val) . ')***';
        }
        return '***MASKED***';
    }
    // Für größere Arrays / Objekte nur Typ/Größe zeigen
    if (is_array($val)) {
        return 'array(' . count($val) . ')';
    }
    if (is_object($val)) {
        return 'object(' . get_class($val) . ')';
    }
    return $val;
}

echo "===== tests/check_config.php =====\n";
echo "SAPI: $sapi\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "Loaded php.ini: " . (php_ini_loaded_file() ?: '<none>') . "\n";
echo "Extensions: pdo=" . (extension_loaded('pdo') ? 'yes' : 'no') . " pdo_mysql=" . (extension_loaded('pdo_mysql') ? 'yes' : 'no') . "\n\n";

$paths = [
    getcwd() . '/web/config.php',
    getcwd() . '/config.php',
    getcwd() . '/config.local.php',
    getcwd() . '/web/config.local.php',
];

$config = null;
$foundPath = null;

echo "Checking config candidate paths:\n";
foreach ($paths as $p) {
    $exists = is_file($p) ? 'exists' : 'missing';
    echo " - $p => $exists\n";
    if (is_file($p)) {
        $st = stat($p);
        echo "   owner(uid/gid): {$st['uid']}/{$st['gid']} perms: " . substr(sprintf('%o', $st['mode']), -4) . "\n";
    }
}

echo "\nLoading first existing config file...\n";
foreach ($paths as $p) {
    if (is_file($p)) {
        $foundPath = $p;
        try {
            $maybe = require $p;
        } catch (Throwable $e) {
            echo "Error loading config file $p: " . $e->getMessage() . "\n";
            $maybe = null;
        }
        if ($maybe === null) {
            echo "Config $p returned null or threw an error.\n";
            continue;
        }
        $config = $maybe;
        echo "Loaded config from: $p\n";
        break;
    }
}

if (!is_array($config)) {
    echo "No valid config array loaded. Type: " . gettype($config) . "\n";
    exit(1);
}

// Zeige Keys und maskierte Werte
echo "\nConfig keys and (masked) values:\n";
foreach ($config as $k => $v) {
    $mv = mask_value($k, $v);
    // Für callable/closure nur anzeigen, nicht ausführen
    if (is_callable($v) && !is_string($v)) {
        $mv = 'callable';
    }
    echo sprintf(" - %-20s : %s\n", $k, is_string($mv) ? $mv : print_r($mv, true));
}

// Besondere Infos
echo "\nDB_USER: " . ($config['DB_USER'] ?? '<missing>') . "\n";
echo "DB_PASS set? " . ((isset($config['DB_PASS']) && $config['DB_PASS'] !== '') ? 'YES' : 'NO') . "\n";
echo "PDO_DSN: " . ($config['PDO_DSN'] ?? '<missing>') . "\n";
echo "create_pdo present and callable? " . (isset($config['create_pdo']) && is_callable($config['create_pdo']) ? 'YES' : 'NO') . "\n";

if (isset($config['create_pdo']) && is_callable($config['create_pdo'])) {
    echo "\nCalling create_pdo() (will catch exceptions)...\n";
    try {
        $pdo = $config['create_pdo']();
        if ($pdo instanceof PDO) {
            echo "create_pdo(): connection OK\n";
        } else {
            echo "create_pdo() returned non-PDO type: " . gettype($pdo) . "\n";
        }
    } catch (Throwable $e) {
        echo "create_pdo() Exception: " . $e->getMessage() . "\n";
    }
} else {
    echo "\ncreate_pdo not present or not callable — attempting manual PDO with available keys...\n";
    $dsn = $config['PDO_DSN'] ?? sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $config['DB_HOST'] ?? '127.0.0.1',
        $config['DB_PORT'] ?? '3306',
        $config['DB_NAME'] ?? 'internetcafe'
    );
    $user = $config['DB_USER'] ?? 'internetcafe';
    $pass = $config['DB_PASS'] ?? '';
    echo "Attempting DSN: $dsn\n";
    echo "DB user: $user (password " . ($pass === '' ? "NOT SET" : "SET") . ")\n";
    try {
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "Manual PDO: connection OK\n";
    } catch (Throwable $e) {
        echo "Manual PDO Exception: " . $e->getMessage() . "\n";
    }
}

echo "\n==== end ====\n";
