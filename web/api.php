<?php
// api.php - einfache API Endpoints (Erweiterung des Skeletons).
// Benutze als: http://server.local/api.php?q=<endpoint>
// WICHTIG: Vor Produktion Authentifizierung & Input Validation ergänzen.

require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/config.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getDb();
$path = $_GET['q'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

/* Simple API key check for clients */
function require_api_key() {
    $headers = getallheaders();
    $provided = $_SERVER['HTTP_X_API_KEY'] ?? ($headers['X-API-KEY'] ?? ($_GET['api_key'] ?? null));
    if (!$provided || $provided !== API_KEY) {
        http_response_code(403);
        echo json_encode(['error' => 'invalid_api_key']);
        exit;
    }
}

/* GET pc/get_state?host=... */
if ($path === 'pc/get_state' && $method === 'GET') {
    $hostname = $_GET['host'] ?? null;
    if (!$hostname) { http_response_code(400); echo json_encode(['error'=>'host required']); exit; }
    $stmt = $pdo->prepare("SELECT id, hostname, current_state, last_checkin FROM computers WHERE hostname = ?");
    $stmt->execute([$hostname]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); echo json_encode(['error'=>'not found']); exit; }
    echo json_encode(['computer'=>$row]);
    exit;
}

/* POST pc/set_state  (client should use X-API-KEY)  */
if ($path === 'pc/set_state' && $method === 'POST') {
    require_api_key();
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['host']) || empty($data['state'])) { http_response_code(400); echo json_encode(['error'=>'host,state required']); exit; }
    $allowed = ['starting','frei','gast','pause','wartung','STOP','OFF'];
    if (!in_array($data['state'],$allowed)) { http_response_code(400); echo json_encode(['error'=>'bad state']); exit; }
    $stmt = $pdo->prepare("UPDATE computers SET current_state=?, last_checkin=NOW() WHERE hostname=?");
    $stmt->execute([$data['state'], $data['host']]);
    echo json_encode(['ok'=>true]);
    exit;
}

/* POST session/start -> startet Session, legt Session row an. Optional: is_diako flag. */
if ($path === 'session/start' && $method === 'POST') {
    require_api_key();
    $d = json_decode(file_get_contents('php://input'), true);
    if (empty($d['host'])) { http_response_code(400); echo json_encode(['error'=>'host required']); exit; }
    $is_diako = !empty($d['is_diako']);
    // find computer
    $stmt = $pdo->prepare("SELECT id FROM computers WHERE hostname=?");
    $stmt->execute([$d['host']]);
    $comp = $stmt->fetch();
    if (!$comp) { http_response_code(404); echo json_encode(['error'=>'unknown host']); exit; }
    // determine price_per_min from tariffs (price_types + tariffs)
    $typeCode = $is_diako ? 'diako' : 'normal';
    $stmt = $pdo->prepare("SELECT t.price FROM tariffs t JOIN price_types pt ON pt.id=t.price_type_id WHERE pt.code=? AND t.service_code='pc_minute' LIMIT 1");
    $stmt->execute([$typeCode]);
    $row = $stmt->fetch();
    $ppm = $row['price'] ?? 0.0;
    $stmt = $pdo->prepare("INSERT INTO sessions (computer_id, customer_id, started_at, price_per_min) VALUES (?, NULL, NOW(), ?)");
    $stmt->execute([$comp['id'], $ppm]);
    $sid = $pdo->lastInsertId();
    // set computer state to gast
    $pdo->prepare("UPDATE computers SET current_state='gast', last_checkin=NOW() WHERE id=?")->execute([$comp['id']]);
    echo json_encode(['session_id'=>$sid, 'price_per_min' => $ppm]);
    exit;
}

/* POST session/end -> beendet Session und berechnet billed_minutes und total_price */
if ($path === 'session/end' && $method === 'POST') {
    require_api_key();
    $d = json_decode(file_get_contents('php://input'), true);
    if (empty($d['session_id'])) { http_response_code(400); echo json_encode(['error'=>'session_id required']); exit; }
    // fetch session
    $stmt = $pdo->prepare("SELECT * FROM sessions WHERE id=?");
    $stmt->execute([$d['session_id']]);
    $s = $stmt->fetch();
    if (!$s) { http_response_code(404); echo json_encode(['error'=>'session not found']); exit; }
    if ($s['ended_at']) { http_response_code(400); echo json_encode(['error'=>'already ended']); exit; }
    $started = new DateTime($s['started_at']);
    $ended = new DateTime(); // now
    $mins = max(1, (int)ceil(($ended->getTimestamp() - $started->getTimestamp())/60));
    $total = $mins * (float)$s['price_per_min'];
    $stmt = $pdo->prepare("UPDATE sessions SET ended_at=NOW(), billed_minutes=?, total_price=? WHERE id=?");
    $stmt->execute([$mins, $total, $d['session_id']]);
    echo json_encode(['ok'=>true, 'billed_minutes'=>$mins, 'total_price'=>$total]);
    exit;
}

/* POST print/job - Client meldet Druckauftrag mit meta */
if ($path === 'print/job' && $method === 'POST') {
    require_api_key();
    $d = json_decode(file_get_contents('php://input'), true);
    if (empty($d['host']) || !isset($d['pages'])) { http_response_code(400); echo json_encode(['error'=>'host and pages required']); exit; }
    // find computer
    $stmt = $pdo->prepare("SELECT id FROM computers WHERE hostname=?");
    $stmt->execute([$d['host']]);
    $comp = $stmt->fetch();
    if (!$comp) { http_response_code(404); echo json_encode(['error'=>'unknown host']); exit; }
    // determine price: choose price_type normal by default unless is_diako flag
    $is_diako = !empty($d['is_diako']);
    $typeCode = $is_diako ? 'diako' : 'normal';
    $priceTypeStmt = $pdo->prepare("SELECT id FROM price_types WHERE code=? LIMIT 1");
    $priceTypeStmt->execute([$typeCode]);
    $pt = $priceTypeStmt->fetch();
    $ptid = $pt['id'] ?? null;
    // get per-page prices
    $stmt = $pdo->prepare("SELECT service_code, price FROM tariffs WHERE price_type_id=? AND service_code IN ('print_bw','print_color','scan_page')");
    $stmt->execute([$ptid]);
    $tariffs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $pages = (int)$d['pages'];
    $color = !empty($d['color']) ? (int)$d['color'] : 0;
    $bw = $pages - $color;
    $price = ($bw * ($tariffs['print_bw'] ?? 0.0)) + ($color * ($tariffs['print_color'] ?? 0.0));
    // insert print_job
    $stmt = $pdo->prepare("INSERT INTO print_jobs (computer_id, user_description, pages, color_pages, bw_pages, copies, price, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$comp['id'], $d['job_name'] ?? null, $pages, $color, $bw, $d['copies'] ?? 1, $price]);
    $jobId = $pdo->lastInsertId();
    echo json_encode(['job_id'=>$jobId, 'price'=>$price]);
    exit;
}

/* GET blocklist/get -> gibt aktive Muster zurück */
if ($path === 'blocklist/get' && $method === 'GET') {
    $stmt = $pdo->query("SELECT pattern FROM blocked_sites WHERE enabled=1");
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['patterns'=>$rows]);
    exit;
}

/* Simple fallback */
http_response_code(404);
echo json_encode(['error'=>'unknown endpoint']);
exit;
