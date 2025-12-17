<?php
// web/admin/computers.php
// Admin page to set one of the 7 states for a computer.
// Anpassung: nutzt canonical header.php statt head.php

declare(strict_types=1);

// load DB helper
require_once __DIR__ . '/../db.php';

// include canonical header (öffnet <main class="container">)
$headerFile = __DIR__ . '/../header.php';
if (is_file($headerFile)) {
    require_once $headerFile;
} else {
    // fallback minimal HTML if header.php missing
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Admin — Computer</title></head><body><main class="container">';
}

function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pdo = function_exists('db_get_pdo') ? db_get_pdo() : null;
$computers = [];

if ($pdo && function_exists('fetch_computers')) {
    try {
        $computers = fetch_computers($pdo);
    } catch (Throwable $e) {
        error_log('admin/computers fetch error: ' . $e->getMessage());
        $computers = [];
    }
}

// POST handler: forward set_state payload to internal API or simulate
$server_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $state = $_POST['state'] ?? '';
    $occupied = $_POST['occupied'] ?? null;
    $id = (string)$id;
    $state = (string)$state;
    if ($id === '' || $state === '') {
        $server_msg = 'Missing id or state';
    } else {
        $api_url = "/api/computers/".rawurlencode($id)."/action";
        $payload = ['action'=>'set_state','state'=>$state];
        if ($occupied !== null && $occupied !== '') $payload['occupied'] = (string)$occupied;

        $ch = curl_init();
        $json = json_encode($payload);
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $resp = curl_exec($ch);
        $curl_err = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $curl_err) {
            $server_msg = "API not reachable (curl error: " . esc($curl_err) . "). Action simulated.";
            @mkdir(sys_get_temp_dir().'/clientd-demo', 0755, true);
            file_put_contents(sys_get_temp_dir().'/clientd-demo/'.rawurlencode($id).'_setstate_'.$state.'.txt', date('c') . " simulated; payload=" . $json . PHP_EOL);
        } else {
            $server_msg = "API returned HTTP $http_code: " . esc(substr($resp, 0, 400));
        }
    }
}
?>

<h2>Computersteuerung (Admin)</h2>
<?php if ($server_msg !== ''): ?>
  <div class="muted"><?php echo esc($server_msg); ?></div>
<?php endif; ?>

<?php if (empty($computers)): ?>
  <div class="empty">Keine Computer gefunden.</div>
<?php else: ?>
  <table>
    <thead><tr><th>ID</th><th>Name</th><th>Status</th><th>Aktion</th></tr></thead>
    <tbody>
      <?php foreach ($computers as $c): ?>
        <tr>
          <td><?php echo esc($c['id'] ?? ''); ?></td>
          <td><?php echo esc($c['name'] ?? $c['hostname'] ?? ''); ?></td>
          <td><?php echo esc($c['current_state'] ?? $c['state'] ?? ''); ?></td>
          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="id" value="<?php echo esc($c['id'] ?? ''); ?>">
              <input type="hidden" name="state" value="frei">
              <button type="submit">Setze frei</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php
// include shared footer (schließt </main></body></html>)
$footerFile = __DIR__ . '/../footer.php';
if (is_file($footerFile)) {
    require_once $footerFile;
} else {
    echo '</main></body></html>';
}
?>
