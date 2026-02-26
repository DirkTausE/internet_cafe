<?php
// Kurzer DB‑Test - NICHT in Produktion belassen
$dbname = getenv('DB_NAME') ?: 'internetcafe';
$user = getenv('DB_USER') ?: 'internetcafe_user';
$pass = getenv('DB_PASS') ?: 'internetcafe_pass';
$host = '127.0.0.1';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema='$dbname'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "OK: DB '$dbname' erreichbar. Tabellenanzahl: " . ($row['cnt'] ?? '0');
} catch (PDOException $e) {
    http_response_code(500);
    echo "DB Fehler: " . $e->getMessage();
}