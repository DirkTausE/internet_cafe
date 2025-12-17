<?php
// web/admin/blocked_import.php
// Importer zum automatischen Einpflegen einer Liste von Hostnamen in die Tabelle blocked_sites
// - akzeptiert textarea oder Datei-Upload (Text/CSV)
// - fehlertolerant gegenüber http://, https://, Pfaden etc.
// - erlaubt pro Zeile eine optionale Notiz (CSV, Semikolon, Tab oder erstes whitespace getrennt)
// - erkennt und überspringt Duplikate (case-insensitive Vergleich, optional "www."-Stripping)
// - schreibt inserted rows mit created_at = NOW()
declare(strict_types=1);

// session sicher starten
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// DB helper
$dbFile = __DIR__ . '/../db.php';
if (!file_exists($dbFile)) {
    error_log('blocked_import.php: db.php not found: ' . $dbFile);
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
    error_log('blocked_import.php: keine gültige PDO-Verbindung');
    http_response_code(500);
    echo 'Interner Serverfehler (keine DB-Verbindung).';
    exit;
}

// Hilfsfunktionen (nur definieren, wenn noch nicht vorhanden)
if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5); }
}

// setze Titel & disable auto-refresh für dieses Formular
$page_title = 'Blocked Sites - Import';
$no_refresh = true;

// Ergebnisse / Status
$summary = [
    'lines_total' => 0,
    'parsed' => 0,
    'inserted' => 0,
    'skipped_duplicate' => 0,
    'invalid' => 0,
];
$errors = [];
$inserted_rows = [];
$skipped = [];
$invalid_lines = [];

/**
 * Extrahiere Host/Pattern aus beliebigem Input (hostname, url, url/path)
 * Rückgabe: string|null (host ohne scheme und ohne Pfad) oder null wenn nicht extrahierbar
 */
function extract_host(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;

    // If it's a plain IP or host with port, capture host part
    // Regex: optional scheme, capture host (no slash, no colon at end unless port)
    if (preg_match('@^(?:https?://)?([^/:\s]+)@i', $raw, $m)) {
        $host = $m[1];
        // strip optional port
        $host = preg_replace('/:\d+$/', '', $host);
        // normalize
        $host = mb_strtolower($host, 'UTF-8');
        // remove trailing dots
        $host = rtrim($host, '.');
        return $host === '' ? null : $host;
    }
    // fallback: if no match, try parse_url with default scheme
    $try = 'http://' . $raw;
    $p = parse_url($try);
    if ($p !== false && !empty($p['host'])) {
        $host = mb_strtolower($p['host'], 'UTF-8');
        $host = rtrim($host, '.');
        return $host === '' ? null : $host;
    }
    return null;
}

/**
 * Prüft ob ein Pattern bereits in blocked_sites existiert (case-insensitive)
 */
function exists_pattern(PDO $pdo, string $pattern, bool $strip_www = true): bool {
    // optional normalize by stripping leading www.
    $cmp = $strip_www && stripos($pattern, 'www.') === 0 ? substr($pattern, 4) : $pattern;
    $sql = 'SELECT 1 FROM blocked_sites WHERE LOWER(pattern) = LOWER(:pat) LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':pat' => $cmp]);
    if ($stmt->fetchColumn()) return true;
    // also check the other form (with/without www) to reduce duplicates:
    if ($strip_www) {
        // check with www.
        $with_www = 'www.' . $cmp;
        $stmt2 = $pdo->prepare($sql);
        $stmt2->execute([':pat' => $with_www]);
        return (bool)$stmt2->fetchColumn();
    }
    return false;
}

/**
 * Insert pattern into DB
 */
function insert_pattern(PDO $pdo, string $pattern, string $note, int $enabled = 1): ?int {
    $sql = 'INSERT INTO blocked_sites (pattern, note, enabled, created_at) VALUES (:pattern, :note, :enabled, NOW())';
    $stmt = $pdo->prepare($sql);
    $ok = $stmt->execute([
        ':pattern' => $pattern,
        ':note' => $note,
        ':enabled' => $enabled,
    ]);
    if ($ok) {
        return (int)$pdo->lastInsertId();
    }
    return null;
}

// POST-Verarbeitung: Datei oder Textarea einlesen und parsen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // basic options
    $strip_www = isset($_POST['strip_www']) ? (bool)$_POST['strip_www'] : true;
    $default_enabled = isset($_POST['enabled']) ? (int)$_POST['enabled'] : 1;

    // accept uploaded file if present
    $content = '';
    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $tmp = $_FILES['file']['tmp_name'];
        $content = @file_get_contents($tmp) ?: '';
    } else {
        $content = (string)($_POST['data'] ?? '');
    }

    // split into lines
    $lines = preg_split('/\r\n|\r|\n/', $content);
    $summary['lines_total'] = count($lines);
    $line_no = 0;

    foreach ($lines as $line) {
        $line_no++;
        $line = trim($line);
        if ($line === '') continue;

        // Allow comments starting with # or //
        if (strpos($line, '#') === 0 || strpos($line, '//') === 0) {
            continue;
        }

        // Parse CSV-like: try comma first (handles quoted fields), then semicolon
        $fields = str_getcsv($line, ',');
        if (count($fields) === 1 && strpos($line, ';') !== false) {
            $fields = str_getcsv($line, ';');
        }

        $raw = '';
        $note = '';
        if (count($fields) >= 1) {
            $raw = trim((string)$fields[0]);
            if (count($fields) > 1) {
                // join rest as note (commas within quoted fields preserved)
                $note = trim(implode(',', array_slice($fields, 1)));
            }
        }

        // If after CSV parsing only one field and contains whitespace, split into two parts (hostname note)
        if ($raw === '' && trim($line) !== '') {
            $parts = preg_split('/\s+/', $line, 2);
            $raw = trim($parts[0] ?? '');
            $note = trim($parts[1] ?? '');
        }

        if ($raw === '') {
            $summary['invalid']++;
            $invalid_lines[] = ['line' => $line_no, 'text' => $line, 'reason' => 'empty after parsing'];
            continue;
        }

        $host = extract_host($raw);
        if ($host === null) {
            $summary['invalid']++;
            $invalid_lines[] = ['line' => $line_no, 'text' => $line, 'reason' => 'could not extract host'];
            continue;
        }

        // optionally strip leading www.
        if ($strip_www && stripos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        $summary['parsed']++;

        // check duplicates
        // Compare case-insensitive; also consider with/without www (handled in exists_pattern)
        if (exists_pattern($pdo, $host, $strip_www)) {
            $summary['skipped_duplicate']++;
            $skipped[] = ['line' => $line_no, 'pattern' => $host, 'note' => $note];
            continue;
        }

        // insert
        $inserted_id = null;
        try {
            $inserted_id = insert_pattern($pdo, $host, $note, $default_enabled ? 1 : 0);
        } catch (PDOException $e) {
            $errors[] = 'DB error on line ' . $line_no . ': ' . $e->getMessage();
            $invalid_lines[] = ['line' => $line_no, 'text' => $line, 'reason' => 'db error'];
            continue;
        }

        if ($inserted_id !== null) {
            $summary['inserted']++;
            $inserted_rows[] = ['id' => $inserted_id, 'pattern' => $host, 'note' => $note];
        } else {
            $errors[] = 'Insert failed at line ' . $line_no;
            $invalid_lines[] = ['line' => $line_no, 'text' => $line, 'reason' => 'insert returned null'];
        }
    } // foreach lines
} // POST

// include header (no refresh)
$headerFile = __DIR__ . '/../header.php';
if (is_file($headerFile)) {
    require_once $headerFile;
} else {
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>' . h($page_title) . '</title></head><body><main class="container">';
}
?>
<style>
  .muted { color:#666; }
  textarea.import-area { width:100%; min-height:200px; font-family:monospace; }
  table.summary { border-collapse:collapse; width:100%; }
  table.summary th, table.summary td { border:1px solid #ddd; padding:8px; }
  table.summary th { background:#f4f4f4; text-align:left; }
  .small { font-size:0.9rem; color:#444; }
</style>

<main>
  <h1><?php echo h($page_title); ?></h1>

  <p class="muted">Importiere eine Liste von Hostnamen (je Zeile: Host oder vollständige URL; optional: erste Spalte Host/URL, zweite Spalte Notiz). Kommentare mit <code>#</code> oder <code>//</code> ignoriert.</p>

  <section style="margin-bottom:18px;">
    <h2>Import</h2>
    <form method="post" enctype="multipart/form-data" action="blocked_import.php">
      <div style="margin-bottom:8px;">
        <label><strong>Text einfügen</strong></label>
        <textarea name="data" class="import-area" placeholder="Beispiel: example.com, Grund oder https://example.org/path Notiz"><?php echo isset($_POST['data']) ? h((string)$_POST['data']) : ''; ?></textarea>
      </div>

      <div style="margin-bottom:8px;">
        <label><strong>oder Datei hochladen</strong> (Text/CSV)</label><br>
        <input type="file" name="file" accept=".txt,.csv,text/plain">
      </div>

      <div style="display:flex;gap:12px;align-items:center;margin-bottom:8px;">
        <label><input type="checkbox" name="strip_www" value="1" <?php echo (isset($_POST['strip_www']) && $_POST['strip_www']) ? 'checked' : 'checked'; ?>> Strip leading <code>www.</code> (empfohlen)</label>
        <label style="margin-left:8px;"><input type="checkbox" name="enabled" value="1" <?php echo (isset($_POST['enabled']) && $_POST['enabled']) ? 'checked' : 'checked'; ?>> Aktiv (setzen alle Einträge auf aktiv)</label>
      </div>

      <div><button type="submit">Importieren</button> <a href="blocked.php" style="margin-left:12px">Zur Blocklist</a></div>
    </form>
  </section>

  <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
    <section style="margin-bottom:18px;">
      <h2>Ergebnis</h2>

      <table class="summary" style="margin-bottom:12px;">
        <tr><th>Zeilen gesamt</th><td><?php echo (int)$summary['lines_total']; ?></td></tr>
        <tr><th>Geparst</th><td><?php echo (int)$summary['parsed']; ?></td></tr>
        <tr><th>Eingefügt</th><td><?php echo (int)$summary['inserted']; ?></td></tr>
        <tr><th>Duplikate übersprungen</th><td><?php echo (int)$summary['skipped_duplicate']; ?></td></tr>
        <tr><th>Ungültig / Fehler</th><td><?php echo (int)$summary['invalid']; ?></td></tr>
      </table>

      <?php if (!empty($inserted_rows)): ?>
        <h3>Neu eingefügte Einträge</h3>
        <ul>
          <?php foreach ($inserted_rows as $r): ?>
            <li><?php echo h($r['pattern']); ?> <?php if ($r['note'] !== '') echo '— ' . h($r['note']); ?> (id=<?php echo (int)$r['id']; ?>)</li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (!empty($skipped)): ?>
        <h3>Übersprungene Duplikate</h3>
        <ul>
          <?php foreach ($skipped as $s): ?>
            <li>Zeile <?php echo (int)$s['line']; ?>: <?php echo h($s['pattern']); ?> <?php if ($s['note'] !== '') echo '— ' . h($s['note']); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (!empty($invalid_lines)): ?>
        <h3>Ungültige Zeilen / Fehler</h3>
        <ul>
          <?php foreach ($invalid_lines as $il): ?>
            <li>Zeile <?php echo (int)$il['line']; ?>: <?php echo h($il['text']); ?> — <?php echo h($il['reason']); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (!empty($errors)): ?>
        <h3>Interne Fehler</h3>
        <ul>
          <?php foreach ($errors as $err): ?>
            <li><?php echo h($err); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

    </section>
  <?php endif; ?>

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
