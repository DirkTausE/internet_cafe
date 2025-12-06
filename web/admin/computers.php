<?php
// web/admin/computers.php
// Simple admin page for computer control. Provides per-computer action buttons and a simple server-side POST
// handler that forwards commands to the /api endpoint (if available) or simulates action for local testing.

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../head.php';

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

// POST handler: forward to internal API or simulate
$server_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $action = $_POST['action'] ?? '';
    $id = (string)$id;
    $action = (string)$action;
    if ($id === '' || $action === '') {
        $server_msg = 'Missing id or action';
    } else {
        // prefer server-side API: /api/computers/{id}/action
        $api_url = "/api/computers/".rawurlencode($id)."/action";
        // Try local POST (relative URL) using curl (server-side)
        $ch = curl_init();
        $payload = json_encode(['action' => $action]);
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
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
            // simulation: write to temp file for demo
            @mkdir(sys_get_temp_dir().'/clientd-demo', 0755, true);
            file_put_contents(sys_get_temp_dir().'/clientd-demo/'.rawurlencode($id).'_'.$action.'.txt', date('c') . " simulated\n");
        } else {
            $server_msg = "API returned HTTP $http_code: " . esc(substr($resp, 0, 400));
        }
    }
}
?>

<h2>Computersteuerung (Admin)</h2>
<?php if ($server_msg !== ''): ?>
  <div style="background:#fff7cc;padding:8px;border:1px solid #f0e6b8;margin-bottom:8px;"><?= esc($server_msg) ?></div>
<?php endif; ?>

<?php if (empty($computers)): ?>
  <p class="muted">Keine Rechner gefunden.</p>
<?php else: ?>
  <table>
    <thead><tr><th>Rechner</th><th>Status</th><th>Benutzer</th><th>Aktion</th></tr></thead>
    <tbody>
<?php foreach ($computers as $c):
    $id = $c['id'] ?? '';
    $name = $c['name'] ?? $c['hostname'] ?? "pc-{$id}";
    $is_on = $c['is_on'] ?? null;
    $occ = $c['occupied_by'] ?? $c['client_id'] ?? $c['user_id'] ?? null;
?>
    <tr id="pc-<?= esc((string)$id) ?>">
      <td><?= esc((string)$name) ?></td>
      <td><?= $is_on === true ? '<span class="status-on">ON</span>' : ($is_on === false ? '<span class="muted">OFF</span>' : '<span class="muted">—</span>') ?></td>
      <td><?= $occ ? esc((string)$occ) : '<span class="muted">frei</span>' ?></td>
      <td>
        <form method="post" style="display:inline-block;margin-right:6px;">
          <input type="hidden" name="id" value="<?= esc((string)$id) ?>">
          <input type="hidden" name="action" value="start">
          <button type="submit">Start</button>
        </form>
        <form method="post" style="display:inline-block;margin-right:6px;">
          <input type="hidden" name="id" value="<?= esc((string)$id) ?>">
          <input type="hidden" name="action" value="stop">
          <button type="submit">Stop</button>
        </form>
        <form method="post" style="display:inline-block;">
          <input type="hidden" name="id" value="<?= esc((string)$id) ?>">
          <input type="hidden" name="action" value="restart">
          <button type="submit">Restart</button>
        </form>
      </td>
    </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<p style="margin-top:14px;"><a href="/">Zurück zur Übersicht</a></p>

<?php
echo "</div>\n</body>\n</html>\n";