<?php
// web/index.php — Dashboard (angepasst auf canonical header.php)
declare(strict_types=1);

// load DB helper
require_once __DIR__ . '/db.php';

// obtain PDO (db_get_pdo/db_connect)
$pdo = function_exists('db_get_pdo') ? db_get_pdo() : (function_exists('db_connect') ? db_connect() : null);

if (!$pdo) {
    $cfg = function_exists('load_db_config') ? load_db_config() : [];
    $host = $cfg['host'] ?? '127.0.0.1';
    $port = $cfg['port'] ?? null;
    $name = $cfg['name'] ?? '';
    $user = $cfg['user'] ?? '';
    $pass = $cfg['pass'] ?? '';
    $dsn = $cfg['pdo_dsn'] ?? ($port ? "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4" : "mysql:host={$host};dbname={$name};charset=utf8mb4");

    try {
        $tmp = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo = $tmp;
    } catch (Throwable $e) {
        $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>Datenbankfehler</title></head><body>\n";
        echo "<h1>Verbindung zur Datenbank fehlgeschlagen</h1>\n";
        echo "<p><strong>Fehler:</strong> {$msg}</p>\n";
        echo "</body></html>\n";
        exit(1);
    }
}

// Daten laden
$computers = fetch_computers($pdo);
$customers = fetch_customers($pdo);

// page-specific settings for header
$page_title = 'Internet Cafe — Dashboard';
$page_css = []; // optional: füge hier zusätzliche CSS-Dateien hinzu
$page_js = [];  // optional: füge hier zusätzliche JS-Dateien hinzu

// include canonical header (öffnet <main class="container">)
$headerFile = __DIR__ . '/header.php';
if (is_file($headerFile)) {
    require_once $headerFile;
} else {
    // fallback minimal HTML if header.php missing
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>' . htmlspecialchars((string)$page_title, ENT_QUOTES | ENT_HTML5) . '</title></head><body><main class="container">';
}
?>
<style>
  /* page-local styles (kept inline for compatibility) */
  body { font-family: sans-serif; margin: 1rem; background:#fafafa; color:#222; }
  .grid { display: grid; grid-template-columns: 1fr 420px; gap: 1rem; align-items: start; }
  section { background: #fff; padding: 0.8rem; border: 1px solid #e6e6e6; border-radius: 4px; }
  table { border-collapse: collapse; width: 100%; }
  th, td { border: 1px solid #e6e6e6; padding: 0.45rem 0.6rem; vertical-align: top; }
  th { background: #f2f2f2; text-align: left; }
  pre { margin: 0; font-family: monospace; font-size: 0.85rem; white-space: pre-wrap; word-break:break-word; }
  .state { font-weight: 700; }
  .empty { color: #666; padding: 1rem 0; }
  .small { font-size: 0.95rem; color:#333; }
  .pc-btn { background:#1976d2;color:#fff;border:none;padding:0.35rem 0.6rem;border-radius:4px;cursor:pointer;font-weight:600;font-size:0.95rem; }
</style>

<div class="grid">
  <section>
    <h2>Computerübersicht</h2>
    <?php if (empty($computers)): ?>
      <div class="empty">Keine Computer gefunden.</div>
    <?php else: ?>
      <table>
        <thead><tr><th>ID</th><th>Name</th><th>Status</th><th>Besetzt</th></tr></thead>
        <tbody>
        <?php foreach ($computers as $c): ?>
          <tr>
            <td><?php echo htmlspecialchars((string)($c['id'] ?? ''), ENT_QUOTES | ENT_HTML5); ?></td>
            <td><?php echo htmlspecialchars((string)($c['name'] ?? ''), ENT_QUOTES | ENT_HTML5); ?></td>
            <td><?php echo htmlspecialchars((string)($c['current_state'] ?? $c['state'] ?? ''), ENT_QUOTES | ENT_HTML5); ?></td>
            <td><?php echo htmlspecialchars((string)($c['occupied_by'] ?? ''), ENT_QUOTES | ENT_HTML5); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <aside>
    <h3>Kunden (Auszug)</h3>
    <?php if (empty($customers)): ?>
      <div class="empty">Keine Kunden gefunden.</div>
    <?php else: ?>
      <ul>
      <?php foreach (array_slice($customers, 0, 20) as $cu): ?>
        <li><?php echo htmlspecialchars((string)($cu['name'] ?? ''), ENT_QUOTES | ENT_HTML5); ?> — <?php echo htmlspecialchars((string)($cu['email'] ?? ''), ENT_QUOTES | ENT_HTML5); ?></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </aside>
</div>

<?php
// include shared footer (schließt </main></body></html>)
$footerFile = __DIR__ . '/footer.php';
if (is_file($footerFile)) {
    require_once $footerFile;
} else {
    echo '</main></body></html>';
}
?>
