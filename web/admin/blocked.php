<?php
// web/admin/blocked.php
// Verwaltung gesperrter Websites (Tabelle: blocked_sites)
// + Exportfunktion: ?export=csv  -> CSV download
//                   ?export=txt  -> plain text (pattern \t note)
//                   ?export=list -> simple list: one pattern per line
// Spalten: id, pattern, note, enabled, created_at

declare(strict_types=1);

// Session sicher starten
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// DB helper
$dbFile = __DIR__ . '/../db.php';
if (!file_exists($dbFile)) {
    error_log('blocked.php: db.php not found: ' . $dbFile);
    http_response_code(500);
    echo 'Interner Serverfehler (DB-Config fehlt).';
    exit;
}
require_once $dbFile;

// obtain PDO
$pdo = null;
if (function_exists('db_get_pdo')) {
    $pdo = db_get_pdo();
} elseif (function_exists('db_connect')) {
    $pdo = db_connect();
}
if (!($pdo instanceof PDO)) {
    error_log('blocked.php: keine gültige PDO-Verbindung');
    http_response_code(500);
    echo 'Interner Serverfehler (keine DB-Verbindung).';
    exit;
}

// Helpers (nur definieren, wenn noch nicht vorhanden)
if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5); }
}
if (!function_exists('redirect')) {
    function redirect(string $url): void { header('Location: ' . $url); exit; }
}

// EXPORT HANDLER
$export = isset($_GET['export']) ? (string)$_GET['export'] : '';
if ($export !== '') {
    // Query all rows
    try {
        $stmt = $pdo->query('SELECT id, pattern, note, enabled, created_at FROM blocked_sites ORDER BY created_at DESC, id DESC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('blocked.php export query error: ' . $e->getMessage());
        http_response_code(500);
        echo 'Interner Serverfehler beim Export.';
        exit;
    }

    // Choose output format
    if ($export === 'csv') {
        $filename = 'blocked_sites.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        // optional: emit UTF-8 BOM for Excel compatibility (comment out if undesired)
        // echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        // header row
        fputcsv($out, ['id', 'pattern', 'note', 'enabled', 'created_at']);
        foreach ($rows as $r) {
            // ensure scalar strings
            $line = [
                (string)($r['id'] ?? ''),
                (string)($r['pattern'] ?? ''),
                (string)($r['note'] ?? ''),
                (string)($r['enabled'] ?? ''),
                (string)($r['created_at'] ?? ''),
            ];
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    } elseif ($export === 'txt') {
        $filename = 'blocked_sites.txt';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        foreach ($rows as $r) {
            $pattern = (string)($r['pattern'] ?? '');
            $note = (string)($r['note'] ?? '');
            // tab separated
            fwrite($out, $pattern . "\t" . $note . "\n");
        }
        fclose($out);
        exit;
    } elseif ($export === 'list') {
        $filename = 'blocked_sites_list.txt';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        foreach ($rows as $r) {
            $pattern = (string)($r['pattern'] ?? '');
            fwrite($out, $pattern . "\n");
        }
        fclose($out);
        exit;
    } else {
        http_response_code(400);
        echo 'Unknown export format';
        exit;
    }
}

// --- normal page behavior below ---

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$errors = [];
$id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
$action = $_REQUEST['action'] ?? '';

// Default form values
$item = [
    'pattern' => '',
    'note' => '',
    'enabled' => 1,
    'created_at' => null,
];

// Handle POST actions: save (create/update) or delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic CSRF suggestion: you can add token check here
    $postAction = $_POST['post_action'] ?? 'save';
    if ($postAction === 'delete') {
        $delId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($delId <= 0) {
            $errors[] = 'Ungültige ID zum Löschen.';
        } else {
            try {
                $stmt = $pdo->prepare('DELETE FROM blocked_sites WHERE id = :id');
                $ok = $stmt->execute([':id' => $delId]);
                if ($ok) {
                    $_SESSION['flash'] = 'Eintrag gelöscht.';
                    redirect('blocked.php');
                } else {
                    $errors[] = 'Löschen fehlgeschlagen.';
                }
            } catch (PDOException $e) {
                error_log('blocked.php delete error: ' . $e->getMessage());
                $errors[] = 'Interner Fehler beim Löschen.';
            }
        }
    } else {
        // save (insert/update)
        $saveId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $pattern = trim((string)($_POST['pattern'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        $enabled = isset($_POST['enabled']) && ($_POST['enabled'] === '1' || $_POST['enabled'] === 'on') ? 1 : 0;

        if ($pattern === '') {
            $errors[] = 'Pattern (URL) ist erforderlich.';
        } else {
            if (mb_strlen($pattern) > 1000) {
                $errors[] = 'Pattern zu lang.';
            }
        }

        if (empty($errors)) {
            try {
                if ($saveId <= 0) {
                    $stmt = $pdo->prepare('INSERT INTO blocked_sites (pattern, note, enabled, created_at) VALUES (:pattern, :note, :enabled, NOW())');
                    $ok = $stmt->execute([
                        ':pattern' => $pattern,
                        ':note' => $note,
                        ':enabled' => $enabled,
                    ]);
                    if ($ok) {
                        $_SESSION['flash'] = 'Eintrag angelegt.';
                        redirect('blocked.php');
                    } else {
                        $errors[] = 'Fehler beim Anlegen.';
                    }
                } else {
                    $stmt = $pdo->prepare('UPDATE blocked_sites SET pattern = :pattern, note = :note, enabled = :enabled WHERE id = :id');
                    $ok = $stmt->execute([
                        ':pattern' => $pattern,
                        ':note' => $note,
                        ':enabled' => $enabled,
                        ':id' => $saveId,
                    ]);
                    if ($ok) {
                        $_SESSION['flash'] = 'Eintrag gespeichert.';
                        redirect('blocked.php');
                    } else {
                        $errors[] = 'Fehler beim Speichern.';
                    }
                }
            } catch (PDOException $e) {
                error_log('blocked.php save error: ' . $e->getMessage());
                $errors[] = 'Interner Fehler beim Speichern.';
            }
        } else {
            // keep submitted values for redisplay
            $item['pattern'] = $pattern;
            $item['note'] = $note;
            $item['enabled'] = $enabled;
        }
    }
}

// If GET and editing existing item, load it
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $id > 0) {
    try {
        $stmt = $pdo->prepare('SELECT id, pattern, note, enabled, created_at FROM blocked_sites WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $item['pattern'] = $row['pattern'] ?? '';
            $item['note'] = $row['note'] ?? '';
            $item['enabled'] = isset($row['enabled']) ? (int)$row['enabled'] : 0;
            $item['created_at'] = $row['created_at'] ?? null;
        } else {
            $_SESSION['flash'] = 'Eintrag nicht gefunden.';
            redirect('blocked.php');
        }
    } catch (PDOException $e) {
        error_log('blocked.php load error: ' . $e->getMessage());
        $_SESSION['flash'] = 'Fehler beim Laden des Eintrags.';
        redirect('blocked.php');
    }
}

// Load list of blocked sites
try {
    $stmt = $pdo->query('SELECT id, pattern, note, enabled, created_at FROM blocked_sites ORDER BY created_at DESC, id DESC');
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('blocked.php list error: ' . $e->getMessage());
    $list = [];
    $errors[] = 'Fehler beim Laden der Liste.';
}

// prepare title and include header
$page_title = 'Gesperrte Websites';
$no_refresh = true; // prevents meta refresh on this page

$headerFile = __DIR__ . '/../header.php';
if (is_file($headerFile)) {
    require_once $headerFile;
} else {
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>' . h($page_title) . '</title></head><body><main class="container">';
}
?>
<style>
    table.blocked { border-collapse:collapse; width:100%; margin-bottom:12px; }
    table.blocked th, table.blocked td { border:1px solid #ddd; padding:8px; text-align:left; }
    table.blocked th { background:#f4f4f4; }
    .form-grid { display:grid; grid-template-columns:160px 1fr; gap:10px 12px; max-width:800px; }
    .form-actions { margin-top:12px; }
    .muted { color:#666; font-size:0.95rem; }
    .export-links { margin-bottom:12px; }
</style>

<main>
    <h1><?php echo h($page_title); ?></h1>

    <div class="export-links">
        <strong>Export:</strong>
        <a href="blocked.php?export=csv">CSV</a> |
        <a href="blocked.php?export=txt">Text (pattern \t note)</a> |
        <a href="blocked.php?export=list">Nur Pattern (one per line)</a>
    </div>

    <?php if (!empty($flash)): ?>
        <div style="background:#dfd;padding:8px;margin-bottom:12px;"><?php echo h($flash); ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div style="background:#fdd;padding:8px;margin-bottom:12px;border:1px solid #f99;">
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?php echo h($e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section style="margin-bottom:20px;">
        <h2>Eintrag bearbeiten / neu anlegen</h2>
        <form method="post" action="blocked.php<?php echo $id > 0 ? '?id=' . urlencode((string)$id) : ''; ?>">
            <input type="hidden" name="id" value="<?php echo h($id); ?>">
            <input type="hidden" name="post_action" value="save">
            <div class="form-grid">
                <label for="pattern">Pattern (URL)</label>
                <input id="pattern" name="pattern" type="text" value="<?php echo h($item['pattern']); ?>" placeholder="z. B. example.com oder *.example.com">

                <label for="note">Notiz</label>
                <input id="note" name="note" type="text" value="<?php echo h($item['note']); ?>">

                <label for="enabled">Aktiv</label>
                <input id="enabled" name="enabled" type="checkbox" value="1" <?php echo ($item['enabled'] ? 'checked' : ''); ?>>

                <div></div>
                <div class="muted">Pattern kann Platzhalter enthalten; genaue Matching-Logik liegt beim Blocker.</div>

                <?php if (!empty($item['created_at'])): ?>
                    <label>Erstellt</label>
                    <div class="muted"><?php echo h((string)$item['created_at']); ?></div>
                <?php endif; ?>
            </div>

            <div class="form-actions">
                <button type="submit">Speichern</button>
                <a href="blocked.php">Neu</a>
                <?php if ($id > 0): ?>
                    <form method="post" action="blocked.php" style="display:inline" onsubmit="return confirm('Eintrag wirklich löschen?');">
                        <input type="hidden" name="id" value="<?php echo h($id); ?>">
                        <input type="hidden" name="post_action" value="delete">
                        <button type="submit" style="margin-left:8px">Löschen</button>
                    </form>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section>
        <h2>Liste gesperrter Websites</h2>
        <?php if (empty($list)): ?>
            <div class="muted">Keine Einträge gefunden.</div>
        <?php else: ?>
            <table class="blocked" role="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Pattern</th>
                        <th>Notiz</th>
                        <th>Aktiv</th>
                        <th>Erstellt</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($list as $row): ?>
                        <tr>
                            <td><?php echo h((string)($row['id'] ?? '')); ?></td>
                            <td><?php echo h((string)($row['pattern'] ?? '')); ?></td>
                            <td><?php echo h((string)($row['note'] ?? '')); ?></td>
                            <td><?php echo (!empty($row['enabled']) ? 'ja' : 'nein'); ?></td>
                            <td><?php echo h((string)($row['created_at'] ?? '')); ?></td>
                            <td>
                                <a href="blocked.php?id=<?php echo urlencode((string)($row['id'])); ?>">Bearbeiten</a>
                                <form method="post" action="blocked.php" style="display:inline" onsubmit="return confirm('Eintrag wirklich löschen?');">
                                    <input type="hidden" name="id" value="<?php echo h((string)($row['id'])); ?>">
                                    <input type="hidden" name="post_action" value="delete">
                                    <button type="submit" style="margin-left:8px">Löschen</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>

<?php
// include shared footer (schließt </main></body></html>)
$footerFile = __DIR__ . '/../footer.php';
if (is_file($footerFile)) {
    require_once $footerFile;
} else {
    echo '</body></html>';
}
?>
