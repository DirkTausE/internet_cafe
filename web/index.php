<?php
// web/index.php
// Simple status page showing computers and customers with unpaid invoices.
//
// Behavior:
// - Tries to load DB connection info from config.php (supports constants DB_HOST/DB_NAME/DB_USER/DB_PASS
//   or a $config array with keys host/db/user/pass/name/version).
// - If a DB is available and expected tables exist (computers/clients/invoices etc.) it will query them.
// - If DB or tables are missing, it falls back to demo sample data so you can see the UI.
// - Auto refreshes every 30s (meta refresh). Keep it simple — no router required.
//
// Security note: This is a read-only status page. Do not expose sensitive DB credentials in public.

declare(strict_types=1);

function getConfigFromFile(): array {
    $c = [
        'host' => '127.0.0.1',
        'name' => null,
        'user' => 'root',
        'pass' => '',
        'version' => null,
        'name_app' => 'internetcafe',
    ];
    $cfgPath = __DIR__ . '/../config.php';
    if (!file_exists($cfgPath)) {
        return $c;
    }
    // Include in isolated scope - config.php may set constants or $config array
    @include $cfgPath;
    // constants style
    if (defined('DB_HOST')) $c['host'] = DB_HOST;
    if (defined('DB_NAME')) $c['name'] = DB_NAME;
    if (defined('DB_USER')) $c['user'] = DB_USER;
    if (defined('DB_PASS')) $c['pass'] = DB_PASS;
    if (defined('APP_VERSION')) $c['version'] = APP_VERSION;
    if (defined('APP_NAME')) $c['name_app'] = APP_NAME;
    // array style
    if (isset($config) && is_array($config)) {
        if (!empty($config['db_host'])) $c['host'] = $config['db_host'];
        if (!empty($config['db_name'])) $c['name'] = $config['db_name'];
        if (!empty($config['db_user'])) $c['user'] = $config['db_user'];
        if (!empty($config['db_pass'])) $c['pass'] = $config['db_pass'];
        if (!empty($config['version'])) $c['version'] = $config['version'];
        if (!empty($config['name'])) $c['name_app'] = $config['name'];
    }
    return $c;
}

function tryConnectPDO(array $cfg): ?PDO {
    if (empty($cfg['name'])) {
        return null;
    }
    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['name']);
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function fetchComputers(PDO $pdo): array {
    // Try common table names
    $candidates = ['computers','workstations','terminals','machines'];
    foreach ($candidates as $t) {
        if (tableExists($pdo, $t)) {
            $rows = $pdo->query("SELECT * FROM `$t` ORDER BY id ASC LIMIT 500")->fetchAll();
            return mapComputersRows($rows);
        }
    }
    return [];
}

function mapComputersRows(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $id = $r['id'] ?? $r['uuid'] ?? ($r['name'] ?? null) ?? null;
        $name = $r['name'] ?? $r['label'] ?? "pc-{$id}";
        // detect status fields
        $is_on = null;
        foreach (['is_on','online','powered','power','status'] as $k) {
            if (array_key_exists($k, $r)) {
                $v = $r[$k];
                $is_on = in_array($v, [1,'1',true,'true','on','online','up'], true) ? true : (in_array($v, [0,'0',false,'false','off','down'], true) ? false : null);
                break;
            }
        }
        // occupied / client ref
        $occupied_by = $r['occupied_by'] ?? $r['client_id'] ?? $r['user_id'] ?? null;
        $out[] = [
            'id' => $id,
            'name' => $name,
            'is_on' => $is_on,
            'occupied_by' => $occupied_by,
            'raw' => $r,
        ];
    }
    return $out;
}

function fetchUnpaidCustomers(PDO $pdo): array {
    // Try invoices-based approach
    if (tableExists($pdo, 'invoices')) {
        // attempt common column names
        $sql = "SELECT i.customer_id AS cid, SUM(i.amount) AS due\n                FROM invoices i\n                WHERE (i.paid = 0 OR i.status IS NULL OR i.status != 'paid')\n                GROUP BY i.customer_id\n                HAVING due > 0\n                LIMIT 500";
        try {
            $rows = $pdo->query($sql)->fetchAll();
            $customers = [];
            // try to enrich with customers table if exists
            foreach ($rows as $r) {
                $cid = $r['cid'];
                $due = $r['due'];
                $name = null;
                if (tableExists($pdo, 'customers')) {
                    $stmt = $pdo->prepare('SELECT name, username, email FROM customers WHERE id = ? LIMIT 1');
                    $stmt->execute([$cid]);
                    $c = $stmt->fetch();
                    if ($c) $name = $c['name'] ?? $c['username'] ?? $c['email'] ?? null;
                } elseif (tableExists($pdo, 'clients')) {
                    $stmt = $pdo->prepare('SELECT name, username, email FROM clients WHERE id = ? LIMIT 1');
                    $stmt->execute([$cid]);
                    $c = $stmt->fetch();
                    if ($c) $name = $c['name'] ?? $c['username'] ?? $c['email'] ?? null;
                }
                $customers[] = ['id' => $cid, 'name' => $name ?? "customer-{$cid}", 'due' => (float)$due];
            }
            return $customers;
        } catch (Throwable $e) {
            // fallthrough to other strategies
        }
    }
    // Try customers/clients table with balance column
    $candidates = ['customers','clients','users'];
    foreach ($candidates as $t) {
        if (tableExists($pdo, $t)) {
            try {
                $rows = $pdo->query("SELECT * FROM `$t` WHERE (balance IS NOT NULL AND balance > 0) OR (due_amount IS NOT NULL AND due_amount > 0) LIMIT 500")->fetchAll();
                $out = [];
                foreach ($rows as $r) {
                    $id = $r['id'] ?? null;
                    $name = $r['name'] ?? $r['username'] ?? $r['email'] ?? "id-{$id}";
                    $due = $r['balance'] ?? $r['due_amount'] ?? 0;
                    $out[] = ['id' => $id, 'name' => $name, 'due' => (float)$due];
                }
                if (!empty($out)) return $out;
            } catch (Throwable $e) {
                // ignore
            }
        }
    }
    return [];
}

function demoComputers(): array {
    return [
        ['id'=>1,'name'=>'PC-01','is_on'=>true,'occupied_by'=>101],
        ['id'=>2,'name'=>'PC-02','is_on'=>true,'occupied_by'=>null],
        ['id'=>3,'name'=>'PC-03','is_on'=>false,'occupied_by'=>null],
        ['id'=>4,'name'=>'PC-04','is_on'=>true,'occupied_by'=>102],
    ];
}
function demoCustomers(): array {
    return [
        ['id'=>101,'name'=>'Alice Meier','due'=>12.50],
        ['id'=>102,'name'=>'Bernd Schulz','due'=>5.00],
    ];
}

// --- main
$config = getConfigFromFile();
$pdo = tryConnectPDO($config);
$computers = [];
$customers = [];
$dbNotice = null;
if ($pdo) {
    try {
        $computers = fetchComputers($pdo);
        $customers = fetchUnpaidCustomers($pdo);
        $dbNotice = 'Connected to DB: ' . htmlspecialchars($config['name']);
    } catch (Throwable $e) {
        $dbNotice = 'DB error: ' . htmlspecialchars($e->getMessage());
    }
}
if (empty($computers)) {
    $computers = demoComputers();
    $dbNotice = $dbNotice ?? 'No computers table found — showing demo data';
}
if (empty($customers)) {
    $customers = demoCustomers();
    $dbNotice = $dbNotice ?? 'No unpaid customers found or DB tables missing — showing demo data';
}

// Simple helper
function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>InternetCafe — Übersicht</title>
<meta http-equiv="refresh" content="30"> <!-- refresh every 30s -->
<style>
    body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; margin: 16px; color:#222; background:#f7f7f7;}
    header { margin-bottom: 12px;}
    h1 { margin: 0 0 4px 0; font-size: 18px;}
    .notice { font-size: 13px; color:#555; margin-bottom: 12px; }
    .grid { display: grid; grid-template-columns: 1fr 360px; gap: 16px; align-items: start; }
    table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 0 0 1px rgba(0,0,0,0.04); }
    th, td { padding: 8px 10px; border-bottom: 1px solid #eee; text-align: left; font-size: 14px; }
    th { background: #fafafa; font-weight: 600; }
    tr:last-child td { border-bottom: none; }
    .status-on { color: #0a0; font-weight: 600; }
    .status-off { color: #a00; font-weight: 600; }
    .small { font-size: 12px; color:#666; }
    .muted { color:#777; font-size:13px; }
    footer { margin-top: 16px; font-size: 13px; color:#666; }
</style>
</head>
<body>
<header>
  <h1><?= esc($config['name_app'] ?? 'internetcafe') ?> — Übersicht</h1>
  <div class="notice"><?= esc($dbNotice) ?></div>
</header>

<div class="grid">
  <section>
    <h2 style="font-size:15px;margin:0 0 8px 0;">Eingeschaltete Computer</h2>
    <table aria-describedby="computers">
      <thead>
        <tr><th>#</th><th>Rechner</th><th>Status</th><th>Benutzer</th><th>Info</th></tr>
      </thead>
      <tbody>
<?php foreach ($computers as $c): 
    $id = $c['id'] ?? '';
    $name = $c['name'] ?? "pc-{$id}";
    $is_on = $c['is_on'] ?? null;
    $occ = $c['occupied_by'] ?? null;
    $info = '';
    if (isset($c['raw']) && is_array($c['raw'])) {
        // show some raw columns if helpful
        $raw = $c['raw'];
        $cols = [];
        if (isset($raw['ip'])) $cols[] = 'ip:'.$raw['ip'];
        if (isset($raw['mac'])) $cols[] = 'mac:'.$raw['mac'];
        $info = implode(' ', $cols);
    }
?>
<tr>
  <td><?= esc($id) ?></td>
  <td><?= esc($name) ?></td>
  <td>
    <?php if ($is_on === true): ?>
      <span class="status-on">ON</span>
    <?php elseif ($is_on === false): ?>
      <span class="status-off">OFF</span>
    <?php else: ?>
      <span class="muted">unknown</span>
    <?php endif; ?>
  </td>
  <td><?= $occ ? esc($occ) : '<span class="muted">frei</span>' ?></td>
  <td class="small"><?= esc($info) ?></td>
</tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <aside>
    <h2 style="font-size:15px;margin:0 0 8px 0;">Kunden mit offenen Rechnungen</h2>
    <table aria-describedby="customers">
      <thead><tr><th>#</th><th>Kunde</th><th>Betrag</th></tr></thead>
      <tbody>
<?php foreach ($customers as $cust): ?>
<tr>
  <td><?= esc($cust['id'] ?? '') ?></td>
  <td><?= esc($cust['name'] ?? '—') ?></td>
  <td><?= number_format((float)($cust['due'] ?? 0), 2, ',', '.') ?> €</td>
</tr>
<?php endforeach; ?>
      </tbody>
    </table>

    <div style="margin-top:12px;" class="small muted">
      Letzte Aktualisierung: <?= esc(date('Y-m-d H:i:s')) ?><br>
      Auto-Refresh jede 30s.
    </div>
  </aside>
</div>

<footer>
  <div class="small muted">Wenn du andere Spalten brauchst (z. B. IP, Session, Timeout), passe web/index.php an oder erweitere die DB‑Queries.</div>
</footer>
</body>
</html>