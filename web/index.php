<?php
// web/index.php
// Saubere, defensive Statusseite — nutzt web/db.php und web/head.php

declare(strict_types=1);

// Load helpers and head fragment
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/head.php';

// Helper: safe escaping that accepts null / non-string
function esc($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Ensure $dbNotice exists
$dbNotice = '';

// Get PDO from db.php (db_get_pdo is provided there)
$pdo = function_exists('db_get_pdo') ? db_get_pdo() : null;

// Fetch data using db.php helpers if available
$computers = [];
$customers = [];

if ($pdo) {
    try {
        if (function_exists('fetch_computers')) {
            $computers = fetch_computers($pdo);
        }
        if (function_exists('fetch_customers')) {
            $customers = fetch_customers($pdo);
        }
        // show DB name if possible
        try {
            $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
            $dbNotice = 'Connected to DB: ' . ($dbName ?? '');
        } catch (Throwable $e) {
            // ignore
        }
    } catch (Throwable $e) {
        error_log('index.php fetch error: ' . $e->getMessage());
        $dbNotice = 'DB error: ' . $e->getMessage();
    }
}

// Fallback demo data when DB/tables missing or empty
if (empty($computers)) {
    $computers = [
        ['id'=>1,'name'=>'PC-01','is_on'=>true,'occupied_by'=>101],
        ['id'=>2,'name'=>'PC-02','is_on'=>true,'occupied_by'=>null],
        ['id'=>3,'name'=>'PC-03','is_on'=>false,'occupied_by'=>null],
    ];
    $dbNotice = $dbNotice !== '' ? $dbNotice : 'No computers table found — showing demo data';
}
if (empty($customers)) {
    $customers = [
        ['id'=>101,'name'=>'Alice Meier','due'=>12.50],
        ['id'=>102,'name'=>'Bernd Schulz','due'=>5.00],
    ];
    $dbNotice = $dbNotice !== '' ? $dbNotice : 'No unpaid customers found or DB tables missing — showing demo data';
}

// Normalize and filter only powered-on computers
$onComputers = array_values(array_filter($computers, function($c){
    return isset($c['is_on']) && $c['is_on'] === true;
}));

// Print optional DB notice (head.php already printed header)
if ($dbNotice !== '') {
    echo '<p class="small muted">' . esc($dbNotice) . '</p>';
}
?>
<section aria-labelledby="computers-heading">
  <h2 id="computers-heading">Eingeschaltete Computer</h2>
  <table aria-describedby="computers">
    <thead>
      <tr><th>#</th><th>Rechner</th><th>Status</th><th>Benutzer</th><th>Info</th></tr>
    </thead>
    <tbody>
<?php if (empty($onComputers)): ?>
      <tr><td colspan="5" class="muted small">Keine eingeschalteten Computer gefunden.</td></tr>
<?php else: foreach ($onComputers as $c):
    $id = $c['id'] ?? '';
    $name = $c['name'] ?? $c['hostname'] ?? "pc-{$id}";
    $occ = $c['occupied_by'] ?? $c['client_id'] ?? $c['user_id'] ?? null;
    $info = '';
    if (!empty($c['raw']) && is_array($c['raw'])) {
        $r = $c['raw'];
        $parts = [];
        if (!empty($r['ip']) || !empty($r['ip_address'])) $parts[] = 'ip:' . ($r['ip'] ?? $r['ip_address']);
        if (!empty($r['mac']) || !empty($r['mac_address'])) $parts[] = 'mac:' . ($r['mac'] ?? $r['mac_address']);
        $info = implode(' ', $parts);
    }
?>
      <tr>
        <td><?= esc((string)$id) ?></td>
        <td><?= esc((string)$name) ?></td>
        <td><span class="status-on">ON</span></td>
        <td><?= $occ ? esc((string)$occ) : '<span class="muted">frei</span>' ?></td>
        <td class="small"><?= esc((string)$info) ?></td>
      </tr>
<?php endforeach; endif; ?>
    </tbody>
  </table>
</section>

<section aria-labelledby="customers-heading">
  <h2 id="customers-heading">Kunden mit offenen Rechnungen</h2>
  <table aria-describedby="customers">
    <thead><tr><th>#</th><th>Kunde</th><th>Betrag</th></tr></thead>
    <tbody>
<?php if (empty($customers)): ?>
      <tr><td colspan="3" class="muted small">Keine offenen Rechnungen gefunden.</td></tr>
<?php else: foreach ($customers as $cust): ?>
      <tr>
        <td><?= esc((string)($cust['id'] ?? '')) ?></td>
        <td><?= esc((string)($cust['name'] ?? '—')) ?></td>
        <td><?= esc(number_format((float)($cust['due'] ?? 0), 2, ',', '.')) ?> €</td>
      </tr>
<?php endforeach; endif; ?>
    </tbody>
  </table>
</section>

<?php
// close wrapper opened in head.php
echo "</div>\n</body>\n</html>\n";
