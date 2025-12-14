<?php
declare(strict_types=1);
/**
 * web/admin/clients.php
 * (gekürzte Kopfzeile wie zuvor)
 */

/* bootstrap config & session */
session_start();

/* <-- Hier wurde der Pfad zur config.php korrigiert: eine Ebene höher liegen die globalen web-Dateien --> */
$configFile = __DIR__ . '/../../config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo "Serverkonfiguration fehlerhaft: Datei config.php nicht gefunden (erwartet: " . htmlspecialchars($configFile, ENT_QUOTES) . ").";
    exit;
}

$config = require_once $configFile;

/* restlicher Code unverändert... */
$createPdo = $config['create_pdo'] ?? null;
if (!is_callable($createPdo)) {
    http_response_code(500);
    echo "Server misconfiguration: DB factory not available.";
    exit;
}

/* Admin credentials (set via env or config.local.php):
   'ADMIN_USER' => 'admin',
   'ADMIN_PASS_HASH' => password_hash('secret', PASSWORD_DEFAULT)
*/
$ADMIN_USER = $config['ADMIN_USER'] ?? getenv('ADMIN_USER') ?: null;
$ADMIN_PASS_HASH = $config['ADMIN_PASS_HASH'] ?? getenv('ADMIN_PASS_HASH') ?: null;

/* ... der Rest der Datei bleibt wie zuvor (keine inhaltliche Änderung) ... */

function require_login(): void
{
    if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
        header('Location: ?action=login');
        exit;
    }
}

function gen_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function check_csrf(?string $token): bool
{
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/* Simple helpers */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* Actions: login, logout, add/edit/delete */
$action = $_REQUEST['action'] ?? 'list';

try {
    $pdo = $createPdo();
} catch (Throwable $e) {
    http_response_code(500);
    echo "DB connection failed: " . h($e->getMessage());
    exit;
}

/* DB helper functions */
function fetch_clients(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT id, client_id, host, state, last_seen FROM clients ORDER BY id DESC');
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_client(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, client_id, host, state, secret, last_seen FROM clients WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/* Handle login */
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['user'] ?? '';
    $pass = $_POST['pass'] ?? '';
    $csrf = $_POST['csrf'] ?? '';
    if (!check_csrf($csrf)) {
        $error = 'Ungültiges Formular (CSRF).';
    } elseif ($ADMIN_USER === null || $ADMIN_PASS_HASH === null) {
        $error = 'Admin nicht konfiguriert.';
    } elseif (!hash_equals($ADMIN_USER, $user)) {
        $error = 'Login fehlgeschlagen.';
    } elseif (!password_verify($pass, $ADMIN_PASS_HASH)) {
        $error = 'Login fehlgeschlagen.';
    } else {
        // success
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        header('Location: ./clients.php');
        exit;
    }
}

/* Handle logout */
if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', time() - 42000, '/');
    }
    session_destroy();
    header('Location: ?action=login');
    exit;
}

/* Protect actions below with login */
if (!in_array($action, ['login'], true)) {
    require_login();
}

/* Add / Edit / Delete */
if ($action === 'save_client' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!check_csrf($csrf)) {
        $error = 'Ungültiges Formular (CSRF).';
    } else {
        $id = isset($_POST['id']) && ctype_digit((string)$_POST['id']) ? (int)$_POST['id'] : null;
        $client_id = trim($_POST['client_id'] ?? '');
        $host = trim($_POST['host'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $secret = trim($_POST['secret'] ?? '');

        // Simple validation
        if ($client_id === '' || strlen($client_id) > 100) {
            $error = 'Ungültige client_id';
        } elseif ($host === '' || strlen($host) > 200) {
            $error = 'Ungültiger Host';
        } else {
            if ($id) {
                $stmt = $pdo->prepare('UPDATE clients SET client_id=:client_id, host=:host, state=:state, secret=:secret WHERE id=:id');
                $ok = $stmt->execute([
                    ':client_id' => $client_id,
                    ':host' => $host,
                    ':state' => $state,
                    ':secret' => $secret === '' ? null : $secret,
                    ':id' => $id,
                ]);
                $msg = $ok ? 'Client aktualisiert.' : 'Fehler beim Aktualisieren.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO clients (client_id, host, state, secret, last_seen) VALUES (:client_id, :host, :state, :secret, NULL)');
                $ok = $stmt->execute([
                    ':client_id' => $client_id,
                    ':host' => $host,
                    ':state' => $state,
                    ':secret' => $secret === '' ? null : $secret,
                ]);
                $msg = $ok ? 'Client angelegt.' : 'Fehler beim Anlegen.';
            }
            header('Location: ./clients.php?ok=' . urlencode($msg));
            exit;
        }
    }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!check_csrf($csrf)) {
        $error = 'Ungültiges Formular (CSRF).';
    } else {
        $id = isset($_POST['id']) && ctype_digit((string)$_POST['id']) ? (int)$_POST['id'] : null;
        if ($id) {
            $stmt = $pdo->prepare('DELETE FROM clients WHERE id=:id');
            $stmt->execute([':id' => $id]);
            header('Location: ./clients.php?ok=' . urlencode('Client gelöscht.'));
            exit;
        } else {
            $error = 'Ungültige ID.';
        }
    }
}

/* Output: either login form or admin UI */
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Admin — Clients</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{font-family:Segoe UI,Roboto,Arial;margin:20px;background:#f7f7f9;color:#222}
.container{max-width:900px;margin:0 auto;background:#fff;padding:18px;border-radius:6px;box-shadow:0 2px 6px rgba(0,0,0,.05)}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{padding:8px;border-bottom:1px solid #eee;text-align:left}
form.inline{display:inline}
.msg{padding:8px;background:#e9f7ef;color:#164a2d;border-radius:4px;margin-bottom:10px}
.err{padding:8px;background:#fdecea;color:#611; border-radius:4px;margin-bottom:10px}
.small{font-size:.9em;color:#666}
input[type=text],input[type=password],select{padding:6px;width:100%;box-sizing:border-box;margin-top:4px}
button{padding:6px 10px;border:0;background:#1976d2;color:#fff;border-radius:4px;cursor:pointer}
button.warn{background:#d9534f}
.header{display:flex;justify-content:space-between;align-items:center}
</style>
</head>
<body>
<div class="container">
<?php if (($action === 'login') && !isset($_SESSION['is_admin'])): ?>
    <div class="header"><h2>Admin Login</h2></div>
    <?php if (!empty($error)): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
    <form method="post" action="?action=login">
        <label>Benutzername
            <input type="text" name="user" required autofocus>
        </label>
        <label>Passwort
            <input type="password" name="pass" required>
        </label>
        <input type="hidden" name="csrf" value="<?= h(gen_csrf()) ?>">
        <p><button type="submit">Anmelden</button></p>
        <p class="small">Admin muss in web/config.local.php oder als Umgebungsvariable konfiguriert sein.</p>
    </form>

<?php else: ?>
    <div class="header">
        <h2>Client-Verwaltung</h2>
        <div>
            <a href="?action=logout">Abmelden</a>
        </div>
    </div>

    <?php if (!empty($_GET['ok'])): ?><div class="msg"><?= h($_GET['ok']) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="err"><?= h($error) ?></div><?php endif; ?>

    <?php
    $clients = fetch_clients($pdo);
    ?>
    <table>
        <thead><tr><th>ID</th><th>Client ID</th><th>Host</th><th>State</th><th>Last seen</th><th>Aktionen</th></tr></thead>
        <tbody>
        <?php foreach ($clients as $c): ?>
            <tr>
                <td><?= h((string)$c['id']) ?></td>
                <td><?= h((string)$c['client_id']) ?></td>
                <td><?= h((string)$c['host']) ?></td>
                <td><?= h((string)$c['state']) ?></td>
                <td class="small"><?= h((string)$c['last_seen']) ?></td>
                <td>
                    <form method="get" class="inline" action="./clients.php">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" value="<?= h((string)$c['id']) ?>">
                        <button type="submit">Bearbeiten</button>
                    </form>
                    <form method="post" class="inline" action="?action=delete" onsubmit="return confirm('Löschen?');">
                        <input type="hidden" name="id" value="<?= h((string)$c['id']) ?>">
                        <input type="hidden" name="csrf" value="<?= h(gen_csrf()) ?>">
                        <button type="submit" class="warn">Löschen</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <hr>
    <?php
    // Edit form: fetch if id provided
    $edit = null;
    if (($action === 'edit' || $action === 'new') && isset($_REQUEST['id'])) {
        $idreq = $_REQUEST['id'];
        if (ctype_digit((string)$idreq)) {
            $edit = fetch_client($pdo, (int)$idreq);
        }
    } elseif ($action === 'new') {
        $edit = null;
    }
    ?>
    <h3><?= $edit ? 'Bearbeite Client' : 'Neuen Client anlegen' ?></h3>
    <form method="post" action="?action=save_client">
        <input type="hidden" name="csrf" value="<?= h(gen_csrf()) ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= h((string)$edit['id']) ?>"><?php endif; ?>

        <label>Client ID
            <input type="text" name="client_id" required value="<?= h($edit['client_id'] ?? '') ?>">
        </label>
        <label>Host (z. B. 192.168.1.10)
            <input type="text" name="host" required value="<?= h($edit['host'] ?? '') ?>">
        </label>
        <label>State (optional)
            <input type="text" name="state" value="<?= h($edit['state'] ?? '') ?>">
        </label>
        <label>Secret (wird maskiert angezeigt)
            <input type="text" name="secret" value="">
            <div class="small">Wenn leer gelassen, bleibt das aktuelle Secret unverändert (bei Update).</div>
        </label>
        <p><button type="submit"><?= $edit ? 'Speichern' : 'Anlegen' ?></button></p>
    </form>

    <p class="small">Hinweis: Secrets werden in der Anzeige nicht offen gezeigt. Du kannst sie hier setzen oder leeren.</p>
<?php endif; ?>
</div>
</body>
</html>
