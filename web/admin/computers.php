<?php
// web/admin/computers.php
// Admin page to set one of the 7 states for a computer.
// Sends JSON { action: "set_state", state: "...", occupied: "..." } to /api/computers/{id}/action (server-side).

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../head.php';

function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$state_labels = [
  'starting' => 'starting',
  'frei' => 'frei',
  'gast' => 'Gast',
  'pause' => 'Pause',
  'wartung' => 'Wartung',
  'stop' => 'STOP',
  'off' => 'OFF',
];

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
    $state = $c['state'] ?? '';
    $label = $state !== '' && isset($state_labels[$state]) ? $state_labels[$state] : ($state ?: '—');
    $occ = $c['occupied_by'] ?? $c['client_id'] ?? $c['user_id'] ?? null;
?>
    <tr id="pc-<?= esc((string)$id) ?>">
      <td><?= esc((string)$name) ?></td>
      <td><?= esc($label) ?></td>
      <td><?= $occ ? esc((string)$occ) : '<span class="muted">frei</span>' ?></td>
      <td>
        <form method="post" style="display:inline-block;margin-right:6px;">
          <input type="hidden" name="id" value="<?= esc((string)$id) ?>">
          <select name="state" style="margin-right:6px;">
            <?php foreach ($state_labels as $k => $lab): ?>
              <option value="<?= esc($k) ?>" <?= $k === $state ? 'selected' : '' ?>><?= esc($lab) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="occupied" placeholder="Belegung (optional)" style="width:110px;margin-right:6px;">
          <button type="submit">Setzen</button>
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
?>