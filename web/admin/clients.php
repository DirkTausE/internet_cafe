<?php
declare(strict_types=1);

// optionalen Seitentitel setzen, den header.php ggf. nutzt
$pageTitle = 'Admin: Client‑PC Konfigurationen';

// Direktes Einbinden, wie gewünscht (fatal error, wenn die Dateien fehlen)
require_once __DIR__ . '/../header.php';
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* -----------------------
   DB-Prüfung / $pdo holen
   ----------------------- */
$pdo = $pdo ?? null;

if (!($pdo instanceof PDO)) {
    if (function_exists('get_db')) {
        try {
            $maybe = get_db();
            if ($maybe instanceof PDO) {
                $pdo = $maybe;
            }
        } catch (Throwable $e) {
            error_log('[ERROR] get_db() failed: ' . $e->getMessage());
        }
    } elseif (function_exists('db_connect')) {
        try {
            $maybe = db_connect();
            if ($maybe instanceof PDO) {
                $pdo = $maybe;
            }
        } catch (Throwable $e) {
            error_log('[ERROR] db_connect() failed: ' . $e->getMessage());
        }
    } elseif (defined('DB_DSN')) {
        try {
            $user = defined('DB_USER') ? DB_USER : null;
            $pass = defined('DB_PASS') ? DB_PASS : null;
            $opts = defined('DB_OPTIONS') ? DB_OPTIONS : [];
            $pdo = new PDO(DB_DSN, $user, $pass, $opts);
        } catch (Throwable $e) {
            error_log('[ERROR] PDO creation from constants failed: ' . $e->getMessage());
            $pdo = null;
        }
    }
}

if (!($pdo instanceof PDO)) {
    echo '<div style="padding:1rem;background:#fee;border:1px solid #f88;">';
    echo '<h1>Fehler: Datenbankverbindung nicht vorhanden</h1>';
    echo '<p>Bitte überprüfe <code>web/db.php</code>. Diese Datei muss entweder <code>$pdo</code> setzen oder eine <code>get_db()</code>/<code>db_connect()</code>-Funktion bereitstellen.</p>';
    echo '</div>';
    exit;
}

/* -----------------------
   CSRF-Token
   ----------------------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
function csrf_token(): string {
    return $_SESSION['csrf_token'];
}
function csrf_check(?string $token): bool {
    return is_string($token) && hash_equals((string)csrf_token(), (string)$token);
}

/* -----------------------
   Audit-Log (minimal)
   ----------------------- */
$remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$xf = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
$who = $xf ? sprintf('%s (%s)', $remote, $xf) : $remote;
error_log(sprintf('[AUDIT] %s accessed %s by %s', date('c'), '/web/admin/clients.php', $who));

/* -----------------------
   Hilfsfunktionen: Spalten-Map bauen und Rows normalisieren
   ----------------------- */

/**
 * Liefert die Spalten der Tabelle (nur Namen).
 */
function get_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    $cacheKey = $table;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    $cols = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $cols[] = $r['Field'];
        }
    } catch (Throwable $e) {
        error_log('[DB ERROR] get_table_columns: ' . $e->getMessage());
    }
    $cache[$cacheKey] = $cols;
    return $cols;
}

/**
 * Baut eine Map canonical_key => tatsächlicher_spaltenname in der DB.
 * Canonical keys entsprechen den erwarteten Formular-/Feldnamen:
 * id, name, hostname, mac_adress, ip_adress, description, current_state, last_checkin, createt_at, state
 */
function build_column_map(PDO $pdo): array
{
    $tableCols = get_table_columns($pdo, 'computers');
    $exist = array_flip($tableCols);

    $candidates = [
        'id' => ['id'],
        'name' => ['name', 'hostname_name'],
        'hostname' => ['hostname', 'host'],
        'mac_adress' => ['mac_adress', 'mac_address', 'mac'],
        'ip_adress' => ['ip_adress', 'ip_address', 'ip'],
        'description' => ['description', 'desc', 'info'],
        'current_state' => ['current_state', 'cur_state'],
        'last_checkin' => ['last_checkin', 'last_seen', 'lastcheckin'],
        'createt_at' => ['createt_at', 'created_at', 'created'],
        'state' => ['state'],
    ];

    $map = [];
    foreach ($candidates as $key => $alts) {
        $map[$key] = null;
        foreach ($alts as $a) {
            if (isset($exist[$a])) {
                $map[$key] = $a;
                break;
            }
        }
    }

    // id must exist to operate; if not, try to find any PK-like column
    if (empty($map['id'])) {
        foreach ($tableCols as $c) {
            if (stripos($c, 'id') !== false) {
                $map['id'] = $c;
                break;
            }
        }
    }

    error_log('[DEBUG] column_map: ' . json_encode($map, JSON_UNESCAPED_SLASHES));
    return $map;
}

/**
 * Normiert einen DB-Row (assoziativ) zu canonical keys.
 */
function normalize_row(array $row, array $map): array
{
    $out = [];
    foreach ($map as $canon => $col) {
        if ($col !== null && array_key_exists($col, $row)) {
            $out[$canon] = $row[$col];
        } else {
            $out[$canon] = null;
        }
    }
    return $out;
}

/* -----------------------
   DB-Operationen (robust gegen unterschiedliche Spaltennamen)
   ----------------------- */

function get_all_computers(PDO $pdo): array
{
    $map = build_column_map($pdo);
    try {
        $stmt = $pdo->prepare('SELECT * FROM computers ORDER BY ' . ($map['id'] ?? 'id') . ' ASC');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = normalize_row($r, $map);
        }
        return $out;
    } catch (Throwable $e) {
        error_log('[DB ERROR] get_all_computers: ' . $e->getMessage());
        return [];
    }
}

function get_computer(PDO $pdo, int $id): ?array
{
    $map = build_column_map($pdo);
    $idCol = $map['id'] ?? 'id';
    try {
        $stmt = $pdo->prepare("SELECT * FROM computers WHERE `$idCol` = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return normalize_row($row, $map);
    } catch (Throwable $e) {
        error_log('[DB ERROR] get_computer: ' . $e->getMessage());
        return null;
    }
}

/**
 * Speichert oder aktualisiert einen Computer-Eintrag.
 * Erwartet $data mit canonical keys: name, hostname, mac_adress, ip_adress, description, current_state, state, id(optional)
 * Baut SQL dynamisch nur aus vorhandenem Spalten-Set der Tabelle.
 */
function save_computer(PDO $pdo, array $data)
{
    $map = build_column_map($pdo);

    // Liste der erlaubten canonical keys für Schreib-Operationen (keine timestamps wie last_checkin/createt_at automatisch)
    $writable = ['name','hostname','mac_adress','ip_adress','description','current_state','state'];

    // Build column => value for columns that actually exist
    $cols = [];
    $params = [];
    foreach ($writable as $k) {
        if (!empty($map[$k]) && array_key_exists($k, $data)) {
            $cols[$map[$k]] = $data[$k];
        }
    }

    if (empty($cols)) {
        error_log('[WARN] save_computer: keine brauchbaren Spalten zum Schreiben gefunden.');
        return false;
    }

    try {
        if (!empty($data['id']) && !empty($map['id'])) {
            // UPDATE
            $sets = [];
            foreach ($cols as $col => $val) {
                $sets[] = "`$col` = :$col";
                $params[":$col"] = $val;
            }
            $params[':id'] = (int)$data['id'];
            $sql = 'UPDATE computers SET ' . implode(', ', $sets) . ' WHERE `' . $map['id'] . '` = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$data['id'];
        } else {
            // INSERT
            $colNames = array_map(function($c){ return "`$c`"; }, array_keys($cols));
            $placeholders = array_map(function($c){ return ':' . $c; }, array_keys($cols));
            foreach ($cols as $col => $val) {
                $params[':' . $col] = $val;
            }
            $sql = 'INSERT INTO computers (' . implode(', ', $colNames) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$pdo->lastInsertId();
        }
    } catch (Throwable $e) {
        error_log('[DB ERROR] save_computer: ' . $e->getMessage());
        return false;
    }
}

function delete_computer(PDO $pdo, int $id): bool
{
    $map = build_column_map($pdo);
    $idCol = $map['id'] ?? 'id';
    try {
        $stmt = $pdo->prepare("DELETE FROM computers WHERE `$idCol` = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('[DB ERROR] delete_computer: ' . $e->getMessage());
        return false;
    }
}

/* -----------------------
   Anfrageverarbeitung
   ----------------------- */
$flash = '';
$errors = [];
$action = $_REQUEST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? null;
    if (!csrf_check($postedToken)) {
        http_response_code(400);
        $errors[] = 'Ungültiges CSRF-Token.';
    } else {
        if ($action === 'save') {
            $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
            // canonical input keys (so wie Formular sie liefert)
            $name = trim((string)($_POST['name'] ?? ''));
            $hostname = trim((string)($_POST['hostname'] ?? ''));
            $mac = trim((string)($_POST['mac_adress'] ?? ''));
            $ip = trim((string)($_POST['ip_adress'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $current_state = trim((string)($_POST['current_state'] ?? ''));
            $state = trim((string)($_POST['state'] ?? ''));

            if ($name === '' && $hostname === '') {
                $errors[] = 'Name oder Hostname muss gesetzt sein.';
            }
            if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
                $errors[] = 'Ungültige IP-Adresse.';
            }

            if (empty($errors)) {
                $savedId = save_computer($pdo, [
                    'id' => $id,
                    'name' => $name,
                    'hostname' => $hostname,
                    'mac_adress' => $mac,
                    'ip_adress' => $ip,
                    'description' => $description,
                    'current_state' => $current_state,
                    'state' => $state,
                ]);
                if ($savedId === false) {
                    $errors[] = 'Speichern fehlgeschlagen. Siehe Server-Logs.';
                } else {
                    $flash = $id ? 'Konfiguration aktualisiert.' : 'Neuer Computer angelegt.';
                    error_log(sprintf('[AUDIT] Computer %s von %s (id=%s)', $flash, $who, (string)$savedId));
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                    exit;
                }
            }
        } elseif ($action === 'delete') {
            $compId = isset($_POST['computer_id']) ? (int)$_POST['computer_id'] : 0;
            if ($compId <= 0) {
                $errors[] = 'Ungültige Computer-ID.';
            } else {
                if (delete_computer($pdo, $compId)) {
                    $flash = 'Computer gelöscht.';
                    error_log(sprintf('[AUDIT] Computer id=%d gelöscht von %s', $compId, $who));
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                    exit;
                } else {
                    $errors[] = 'Löschen fehlgeschlagen oder Eintrag nicht gefunden.';
                }
            }
        }
    }
}

/* -----------------------
   Daten laden & Anzeige vorbereiten
   ----------------------- */
$computers = get_all_computers($pdo);

$editComputer = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $editComputer = get_computer($pdo, $id);
    if ($editComputer === null) {
        $errors[] = 'Computer nicht gefunden.';
    }
} elseif (isset($_GET['action']) && $_GET['action'] === 'new') {
    $editComputer = [
        'id' => '',
        'name' => '',
        'hostname' => '',
        'mac_adress' => '',
        'ip_adress' => '',
        'description' => '',
        'current_state' => '',
        'state' => '',
    ];
}

/* -----------------------
   Ausgabe (header.php wurde required)
   ----------------------- */
?>
<style>
    table { border-collapse: collapse; width: 100%; margin-bottom: 1rem; }
    th, td { border: 1px solid #ddd; padding: 8px; }
    th { background: #f4f4f4; text-align: left; }
    .flash { padding: 10px; margin-bottom: 15px; border: 1px solid #cfc; background: #f8fff8; }
    .errors { padding: 10px; margin-bottom: 15px; border: 1px solid #fcc; background: #fff8f8; color: #800; }
    form.inline { display: inline; margin: 0; }
    form.field { max-width: 800px; margin-top: 10px; }
    label { display:block; margin-top:8px; }
    input[type="text"], textarea, select { width:100%; padding:6px; box-sizing: border-box; }
    .muted { color: #666; font-size: 0.9em; }
</style>

<div class="admin-clients" style="margin:20px;">
    <h1>Client‑PC Konfigurationen</h1>

    <?php if ($flash): ?>
        <div class="flash"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="errors">
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <p>
        <a href="?action=new">Neuen Computer anlegen</a>
        &nbsp;|&nbsp;
        <a href="kunden.php">Zur Kundenverwaltung (kunden.php)</a>
    </p>

    <?php if (empty($computers)): ?>
        <p>Keine Computer-Einträge vorhanden.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Hostname</th>
                    <th>MAC</th>
                    <th>IP</th>
                    <th>Current State</th>
                    <th>State</th>
                    <th>Last Checkin</th>
                    <th>Erstellt</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($computers as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($c['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($c['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($c['hostname'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($c['mac_adress'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($c['ip_adress'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($c['current_state'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($c['state'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="muted"><?= htmlspecialchars((string)($c['last_checkin'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="muted"><?= htmlspecialchars((string)($c['createt_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="actions">
                            <a href="?action=edit&id=<?= urlencode((string)$c['id']) ?>">Bearbeiten</a>
                            <form method="post" class="inline" onsubmit="return confirm('Computer wirklich löschen?');" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="computer_id" value="<?= htmlspecialchars((string)($c['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit">Löschen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($editComputer !== null): ?>
        <h2><?= $editComputer['id'] ? 'Computer bearbeiten' : 'Neuen Computer' ?></h2>
        <form method="post" class="field">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= htmlspecialchars((string)($editComputer['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

            <label>
                Anzeigename (name)
                <input type="text" name="name" required value="<?= htmlspecialchars((string)($editComputer['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </label>

            <label>
                Hostname
                <input type="text" name="hostname" value="<?= htmlspecialchars((string)($editComputer['hostname'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </label>

            <label>
                MAC-Adresse (mac_adress)
                <input type="text" name="mac_adress" value="<?= htmlspecialchars((string)($editComputer['mac_adress'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </label>

            <label>
                IP-Adresse (ip_adress, optional)
                <input type="text" name="ip_adress" value="<?= htmlspecialchars((string)($editComputer['ip_adress'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </label>

            <label>
                Beschreibung
                <textarea name="description" rows="3"><?= htmlspecialchars((string)($editComputer['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
            </label>

            <label>
                Current State
                <input type="text" name="current_state" value="<?= htmlspecialchars((string)($editComputer['current_state'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </label>

            <label>
                State
                <input type="text" name="state" value="<?= htmlspecialchars((string)($editComputer['state'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </label>

            <p class="muted">
                Last Checkin: <?= htmlspecialchars((string)($editComputer['last_checkin'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                &nbsp;|&nbsp;
                Erstellt: <?= htmlspecialchars((string)($editComputer['createt_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
            </p>

            <p>
                <button type="submit">Speichern</button>
                <a href="<?= strtok($_SERVER['REQUEST_URI'], '?') ?>">Abbrechen</a>
            </p>
        </form>
    <?php endif; ?>

    <hr>
    <small>
        Hinweis: Diese Seite delegiert den Zugriffsschutz an den Webserver. Stelle sicher,
        dass die Server‑Konfiguration Schutzmechanismen (z. B. HTTP‑Basic, Client‑Zertifikate,
        IP‑Restriktionen oder ein Reverse‑Proxy mit Auth) vor PHP‑Auslieferung anwendet.
    </small>
</div>

<?php
// Footer einbinden, falls vorhanden
$footerPath = __DIR__ . '/../footer.php';
if (file_exists($footerPath)) {
    require_once $footerPath;
}
