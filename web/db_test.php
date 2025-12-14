<?php
// web/db_test.php
// Quick tester for web/db.php — run via: php -S 127.0.0.1:8000 -t web
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';

echo '<pre>' . 'Starting DB test...' . PHP_EOL;

$pdo = db_get_pdo();
if (!$pdo) {
    echo 'db_get_pdo() returned null — no connection. Check ../config.php and credentials.' . PHP_EOL;
    exit(1);
}

try {
    $ver = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    echo 'Connected to MySQL version: ' . htmlspecialchars((string)$ver) . PHP_EOL;
} catch (Throwable $e) {
    echo 'PDO attribute read failed: ' . $e->getMessage() . PHP_EOL;
}

$tables = ['users','computers','customers','invoices','sessions'];
foreach ($tables as $t) {
    try {
        $exists = table_exists($pdo, $t) ? 'yes' : 'no';
        echo "Table '{$t}': {$exists}" . PHP_EOL;
    } catch (Throwable $e) {
        echo "table_exists({$t}) error: " . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL . 'Sample fetch_computers() output (first 5 rows):' . PHP_EOL;
$pcs = fetch_computers($pdo);
if (empty($pcs)) {
    echo 'No computers returned or an error occurred. Check logs.' . PHP_EOL;
} else {
    $slice = array_slice($pcs, 0, 5);
    echo htmlspecialchars(print_r($slice, true)) . PHP_EOL;
}

echo PHP_EOL . 'Sample fetch_customers() output (first 5 rows):' . PHP_EOL;
$cus = fetch_customers($pdo);
if (empty($cus)) {
    echo 'No customers returned or an error occurred.' . PHP_EOL;
} else {
    $slice = array_slice($cus, 0, 5);
    echo htmlspecialchars(print_r($slice, true)) . PHP_EOL;
}

echo PHP_EOL . 'DB test finished.' . PHP_EOL . '</pre>';
