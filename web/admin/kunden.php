<?php
// web/admin/kunden.php
// Kundenliste — nutzt web/db.php -> db_get_pdo() / db_connect().
// Zeigt: id, name, email, balance
declare(strict_types=1);

// session sicher starten (vermeidet "headers already sent" / warnings)
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$dbFile = __DIR__ . '/../db.php';
if (!file_exists($dbFile)) {
    error_log('kunden.php: db.php not found: ' . $dbFile);
    http_response_code(500);
    echo 'Interner Serverfehler (DB-Config fehlt).';
    exit;
}
require_once $dbFile;

$pdo = null;
if (function_exists('db_get_pdo')) {
    $pdo = db_get_pdo();
} elseif (function_exists('db_connect')) {
    $pdo = db_connect();
}

if (!($pdo instanceof PDO)) {
    // Log intern, zeige dem Nutzer eine allgemeine Meldung
    error_log('kunden.php: keine gültige PDO-Verbindung (db_get_pdo/db_connect lieferten null).');
    http_response_code(500);
    echo 'Interner Serverfehler (keine DB-Verbindung).';
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

try {
    $stmt = $pdo->query('SELECT id, name, email, balance FROM customers ORDER BY name');
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('kunden.php: Fehler beim Laden der Kunden: ' . $e->getMessage());
    $_SESSION['flash'] = 'Fehler beim Laden der Kunden.';
    $customers = [];
}

// prepare page title for header.php
$page_title = 'Kundenverwaltung';
$title = $page_title;

// include shared header (mit Fallback)
$headerFile = __DIR__ . '/../header.php';
if (!file_exists($headerFile)) {
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>' . htmlspecialchars((string)$page_title, ENT_QUOTES | ENT_HTML5) . '</title></head><body>';
} else {
    require_once $headerFile;
}
?>
<main>
    <h1><?php echo htmlspecialchars((string)$page_title, ENT_QUOTES | ENT_HTML5); ?></h1>

    <?php if ($flash): ?>
        <div style="background:#dfd;padding:8px;margin-bottom:12px;"><?php echo htmlspecialchars((string)$flash, ENT_QUOTES | ENT_HTML5); ?></div>
    <?php endif; ?>

    <p><a href="kunde.php?id=0">Neuen Kunden anlegen</a></p>

    <table style="border-collapse:collapse;width:100%">
        <thead>
            <tr>
                <th style="border:1px solid #ddd;padding:8px;text-align:left">ID</th>
                <th style="border:1px solid #ddd;padding:8px;text-align:left">Name</th>
                <th style="border:1px solid #ddd;padding:8px;text-align:left">E-Mail</th>
                <th style="border:1px solid #ddd;padding:8px;text-align:right">Guthaben</th>
                <th style="border:1px solid #ddd;padding:8px;text-align:left">Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($customers)): ?>
                <tr><td colspan="5" style="padding:8px">Keine Kunden gefunden.</td></tr>
            <?php else: ?>
                <?php foreach ($customers as $c): ?>
                <tr>
                    <td style="border:1px solid #ddd;padding:8px"><?php echo htmlspecialchars((string)($c['id'] ?? ''), ENT_QUOTES | ENT_HTML5); ?></td>
                    <td style="border:1px solid #ddd;padding:8px"><?php echo htmlspecialchars((string)($c['name'] ?? ''), ENT_QUOTES | ENT_HTML5); ?></td>
                    <td style="border:1px solid #ddd;padding:8px"><?php echo htmlspecialchars((string)($c['email'] ?? ''), ENT_QUOTES | ENT_HTML5); ?></td>
                    <td style="border:1px solid #ddd;padding:8px;text-align:right"><?php echo htmlspecialchars((string)($c['balance'] ?? '0.00'), ENT_QUOTES | ENT_HTML5); ?></td>
                    <td style="border:1px solid #ddd;padding:8px">
                        <a href="kunde.php?id=<?php echo urlencode((string)($c['id'] ?? '')); ?>">Bearbeiten</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</main>
<?php
// include shared footer (mit Fallback)
$footerFile = __DIR__ . '/../footer.php';
if (!file_exists($footerFile)) {
    echo '</body></html>';
} else {
    require_once $footerFile;
}
?>
