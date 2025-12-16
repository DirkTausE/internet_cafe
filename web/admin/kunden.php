<?php
/**
 * web/admin/clients.php
 *
 * Zugriffskontrolle entfernt — wird vom Webserver übernommen.
 * Die alte PHP-basierte Zugriffskontrolle bleibt hier auskommentiert als Referenz.
 *
 * TODO:
 * - Sicherstellen, dass der Webserver (z. B. nginx/Apache) den Pfad /web/admin/* absichert.
 * - Bei Bedarf Logging/Audit anpassen oder zentralisieren.
 */

declare(strict_types=1);

// Session starten, falls die Anwendung Sessions nutzt
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Auskommentierte Zugriffskontrolle (als Backup / Hinweis) ---
// Bitte nur entfernen, wenn wirklich vom Webserver geschützt.
// Beispiel für eine mögliche PHP-basierte Prüfung:
// if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
//     header('HTTP/1.1 403 Forbidden');
//     echo 'Zugriff verweigert.';
//     exit;
// }

// --- Audit-Log (freiwillig, kann entfernt werden) ---
$remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$xf = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
$who = $xf ? sprintf('%s (%s)', $remote, $xf) : $remote;
error_log(sprintf('[AUDIT] %s accessed %s by %s', date('c'), '/web/admin/clients.php', $who));

// --- Laden von Hilfsfunktionen / Modulen ---
// Passe den Pfad an deine Projektstruktur an.
$libPath = __DIR__ . '/../../lib/clients.php';
if (file_exists($libPath)) {
    require_once $libPath;
} else {
    // Wenn es kein zentrales lib gibt, definieren wir Platzhalterfunktionen
    // damit die Datei unabhängig getestet werden kann.
    if (!function_exists('get_all_clients')) {
        function get_all_clients(): array
        {
            // Platzhalterbeispiele
            return [
                ['id' => 1, 'name' => 'Client A', 'email' => 'a@example.org', 'created' => '2024-01-10'],
                ['id' => 2, 'name' => 'Client B', 'email' => 'b@example.org', 'created' => '2024-02-15'],
            ];
        }
    }
    if (!function_exists('delete_client')) {
        function delete_client(int $id): bool
        {
            // Platzhalter: Implementiere hier die echte Lösch-Logik.
            return true;
        }
    }
}

// --- Anfrageverarbeitung (Beispiel: Löschen) ---
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Beispiel: Löschaktion, CSRF-Prüfung sollte hier stattfinden (falls relevant)
    if (!empty($_POST['action']) && $_POST['action'] === 'delete' && !empty($_POST['client_id'])) {
        $clientId = (int)$_POST['client_id'];
        // Hinweis: In produktivem Code -> CSRF-Token prüfen!
        if (function_exists('delete_client') && delete_client($clientId)) {
            $flash = 'Client erfolgreich gelöscht.';
            error_log(sprintf('[AUDIT] Client %d gelöscht von %s', $clientId, $who));
        } else {
            $flash = 'Löschen fehlgeschlagen.';
        }
    }
}

// --- Daten laden ---
$clients = [];
if (function_exists('get_all_clients')) {
    $clients = get_all_clients();
}

// --- Ausgabe (einfaches HTML, passe an dein Template-System an) ---
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Admin: Clients</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; }
        th { background: #f4f4f4; text-align: left; }
        .flash { padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; background: #f9f9f9; }
        .actions form { display:inline; margin:0; }
    </style>
</head>
<body>
    <h1>Clients</h1>

    <?php if ($flash): ?>
        <div class="flash"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (empty($clients)): ?>
        <p>Keine Clients vorhanden.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>E-Mail</th>
                    <th>Erstellt</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clients as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($c['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($c['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($c['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($c['created'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="actions">
                            <!-- Beispiel-Aktionen: Edit / Delete -->
                            <a href="edit_client.php?id=<?= urlencode((string)$c['id']) ?>">Bearbeiten</a>
                            <!-- Lösch-Formular: CSRF-Token ergänzen in produktivem Einsatz -->
                            <form method="post" onsubmit="return confirm('Diesen Client wirklich löschen?');" style="display:inline">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="client_id" value="<?= htmlspecialchars((string)($c['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit">Löschen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p><a href="create_client.php">Neuen Client anlegen</a></p>

    <hr>
    <small>Hinweis: Diese Seite delegiert den Zugriffsschutz an den Webserver. Prüfe die Serverkonfiguration (z. B. nginx/Apache) um sicherzustellen, dass nur berechtigte Nutzer Zugriff haben.</small>
</body>
</html>
