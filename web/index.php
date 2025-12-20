<?php
declare(strict_types=1);

// load DB helper
require_once __DIR__ . '/db.php';

$pdo = function_exists('db_get_pdo') ? db_get_pdo() : (function_exists('db_connect') ? db_connect() : null);

if (!$pdo) {
    $cfg = function_exists('load_db_config') ? load_db_config() : [];
    $dsn = $cfg['pdo_dsn'] ?? "mysql:host=127.0.0.1;dbname=test;charset=utf8mb4";
    try {
        $tmp = new PDO($dsn, $cfg['user'] ?? '', $cfg['pass'] ?? '', [
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

// Daten laden: Computerübersicht
$computers = $pdo->query("
    SELECT c.name AS computer_name, c.current_state, cu.name AS customer_name
    FROM computers c
    LEFT JOIN sessions s ON s.computer_id = c.id
    LEFT JOIN customers cu ON s.customer_id = cu.id AND s.end_time IS NULL
    ORDER BY c.name
")->fetchAll();

// Daten laden: Kunden mit offenen Beträgen
$customers_with_open_balance = $pdo->query("
    SELECT 
        c.id, 
        c.name,
        COALESCE(SUM(t.total_amount), 0) AS open_balance
    FROM customers c
    LEFT JOIN transactions t ON t.customer_id = c.id
    LEFT JOIN invoice_transactions it ON t.id = it.transaction_id
    WHERE t.id IS NOT NULL AND it.transaction_id IS NULL
    GROUP BY c.id
    HAVING open_balance > 0
    ORDER BY c.name
")->fetchAll();

// page-specific settings for header
$page_title = 'Internet Cafe — Dashboard';
$headerFile = __DIR__ . '/header.php';
if (is_file($headerFile)) {
    require_once $headerFile;
} else {
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>' . htmlspecialchars((string)$page_title, ENT_QUOTES | ENT_HTML5) . '</title></head><body><main class="container">';
}
?>

<style>
  body { font-family: sans-serif; margin: 1rem; }
  .dashboard { display: flex; gap: 20px; align-items: flex-start; }

  /* Allgemeine Tabellenstile */
  table { border-collapse: collapse; width: 100%; }
  th, td { border: 1px solid #e6e6e6; padding: 0.5rem; text-align: left; vertical-align: middle; }
  th { background: #f9f9f9; }
  .empty { color: #999; font-style: italic; }

  /* Computer-Tabelle */
  .computers-table { flex: 2; }
  .computers-table th:first-child,
  .computers-table td:first-child { width: 40%; } /* Name des Computers */
  .computers-table th:nth-child(2),
  .computers-table td:nth-child(2) { width: 30%; } /* Nutzer */
  .computers-table th:last-child,
  .computers-table td:last-child { width: 30%; } /* Status */

  /* Kunden-Tabelle */
  .customers-table { flex: 1; }
  .customers-table th:first-child,
  .customers-table td:first-child { width: 60%; } /* Name des Kunden */
  .customers-table th:nth-child(2),
  .customers-table td:nth-child(2) { width: 40%; } /* Offener Betrag */
</style>

<div class="dashboard">
  <!-- Computerübersicht -->
  <section class="computers-table">
    <h2>Computerübersicht</h2>
    <?php if (empty($computers)): ?>
      <div class="empty">Keine Computer gefunden.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Nutzer</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($computers as $computer): ?>
            <tr>
              <td><?php echo htmlspecialchars((string)$computer['computer_name'], ENT_QUOTES | ENT_HTML5); ?></td>
              <td>
                <?php echo $computer['customer_name']
                    ? htmlspecialchars((string)$computer['customer_name'], ENT_QUOTES | ENT_HTML5)
                    : ''; ?>
              </td>
              <td><?php echo htmlspecialchars((string)$computer['current_state'], ENT_QUOTES | ENT_HTML5); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <!-- Kundenübersicht -->
  <aside class="customers-table">
    <h2>Kunden mit offenen Beträgen</h2>
    <?php if (empty($customers_with_open_balance)): ?>
      <div class="empty">Keine Kunden mit offenen Rechnungen gefunden.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Offener Gesamtbetrag</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($customers_with_open_balance as $customer): ?>
            <tr>
              <td>
                <a href="admin/bestellung.php?customer_id=<?php echo htmlspecialchars((string)$customer['id'], ENT_QUOTES | ENT_HTML5); ?>">
                  <?php echo htmlspecialchars((string)$customer['name'], ENT_QUOTES | ENT_HTML5); ?>
                </a>
              </td>
              <td><?php echo number_format((float)$customer['open_balance'], 2, ',', '.'); ?> €</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </aside>
</div>

<?php
$footerFile = __DIR__ . '/footer.php';
if (is_file($footerFile)) {
    require_once $footerFile;
} else {
    echo '</main></body></html>';
}
?>
