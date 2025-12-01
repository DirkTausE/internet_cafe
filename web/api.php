<?php
// web/api.php - Platzhalter API (erweitern mit echten Endpoints)
// Beispiel: GET /web/api.php?q=ping
header('Content-Type: application/json; charset=utf-8');
$q = $_GET['q'] ?? '';
if ($q === 'ping') {
    echo json_encode(['ok' => true, 'time' => date('c')]);
    exit;
}
echo json_encode(['error'=>'unknown endpoint', 'requested'=>$q]);
