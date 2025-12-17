<?php
// web/admin/kunden.php
// Kundenliste — nutzt web/db.php -> db_get_pdo() / db_connect().
// Zeigt: id, name, email, balance
declare(strict_types=1);
session_start();

$dbFile = __DIR__ . '/../db.php';
if (!file_exists($dbFile)) {
    die('DB-File nicht gefunden: ' . htmlspecialchars((string)$dbFile));
}
require_once $dbFile;

$pdo = null;
if (function_exists('db_get_pdo')) {
    $pdo = db_get_pdo();
} elseif (function_exists('db_connect')) {
    $pdo = db_connect();
}

if (!($pdo instanceof PDO)) {
    die('Keine gültige PDO-Verbindung in ' . htmlspecialchars((string)$dbFile) . ' — bitte prüfen Sie web/db.php und Konfiguration.');
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

try {
    $stmt = $pdo->query('SELECT id, name, email, balance FROM customers ORDER BY name');
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['flash'] = 'Fehler beim Laden der Kunden: ' . $e->getMessage();
    $customers = [];
}

// prepare page title for header.php
$page_title = 'Kundenverwaltung';
$title = $page_title;

// include shared header
$headerFile = __DIR__ . '/../header.php';
if (!file_exists($headerFile)) {
    // Fallback: render minimal head if header.php fehlt
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>' . htmlspecialchars((string)$page_title) . '</title></head><body>';
} else {
    require_once $headerFile;
}
?>
<main>
    <h1><?php echo htmlspecialchars((string)$page_title); ?></h1>

    <?php if ($flash): ?>
        <div style="background:#dfd;padding:8px;margin-bottom:12px;"><?php echo htmlspecialchars((string)$flash); ?></div>
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
                    <td style="border:1px solid #ddd;padding:8px"><?php echo htmlspecialchars((string)($c['id'] ?? '')); ?></td>
                    <td style="border:1px solid #ddd;padding:8px"><?php echo htmlspecialchars((string)($c['name'] ?? '')); ?></td>
                    <td style="border:1px solid #ddd;padding:8px"><?php echo htmlspecialchars((string)($c['email'] ?? '')); ?></td>
                    <td style="border:1px solid #ddd;padding:8px;text-align:right"><?php echo htmlspecialchars((string)($c['balance'] ?? '0.00')); ?></td>
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
// include shared footer
$footerFile = __DIR__ . '/../footer.php';
if (!file_exists($footerFile)) {
    echo '</body></html>';
} else {
    require_once $footerFile;
}
?>
