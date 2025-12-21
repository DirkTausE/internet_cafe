<?php
// web/admin/kunde.php
// Formular & Verarbeitung für Anlegen (id=0) und Bearbeiten (id>0).
// Verwendet Spalten der Tabelle 'customers' (customer_type, name, is_diako, note, email, balance).
declare(strict_types=1);

$no_refresh = true;

// session sicher starten
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$dbFile = __DIR__ . '/../db.php';
if (!file_exists($dbFile)) {
    error_log('kunde.php: db.php not found: ' . $dbFile);
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
    error_log('kunde.php: keine gültige PDO-Verbindung (db_get_pdo/db_connect lieferten null).');
    http_response_code(500);
    echo 'Interner Serverfehler (keine DB-Verbindung).';
    exit;
}

function redirect_to_list(): void {
    header('Location: kunden.php');
    exit;
}

$id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
$errors = [];
$customer = [
    'customer_type' => 'guest',
    'name' => '',
    'email' => '',
    'note' => '',
    'balance' => '0.00',
    'is_diako' => 0,
];

// POST-Verarbeitung
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Optional: CSRF-Token-Check hier (empfohlen)
    $customer_type = trim((string)($_POST['customer_type'] ?? 'guest'));
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    $balance_raw = trim((string)($_POST['balance'] ?? '0.00'));
    $is_diako = isset($_POST['is_diako']) ? 1 : 0;

    if ($name === '') {
        $errors[] = 'Name ist erforderlich.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'E-Mail ist ungültig.';
    }

    $balance = 0.0;
    if ($balance_raw !== '') {
        $balance_normalized = str_replace(',', '.', $balance_raw);
        if (!is_numeric($balance_normalized)) {
            $errors[] = 'Guthaben (Balance) ist ungültig.';
        } else {
            $balance = (float)$balance_normalized;
        }
    }

    if (empty($errors)) {
        try {
            if ($id === 0) {
                $stmt = $pdo->prepare('INSERT INTO customers (customer_type, name, is_diako, note, email, balance) VALUES (:customer_type, :name, :is_diako, :note, :email, :balance)');
                $ok = $stmt->execute([
                    ':customer_type' => $customer_type,
                    ':name' => $name,
                    ':is_diako' => $is_diako,
                    ':note' => $note,
                    ':email' => $email,
                    ':balance' => number_format($balance, 2, '.', ''),
                ]);
                if ($ok) {
                    $_SESSION['flash'] = 'Kunde erfolgreich angelegt.';
                    redirect_to_list();
                } else {
                    error_log('kunde.php: Insert execute returned false for new customer.');
                    $errors[] = 'Fehler beim Anlegen des Kunden.';
                }
            } else {
                $stmt = $pdo->prepare('UPDATE customers SET customer_type = :customer_type, name = :name, is_diako = :is_diako, note = :note, email = :email, balance = :balance WHERE id = :id');
                $ok = $stmt->execute([
                    ':customer_type' => $customer_type,
                    ':name' => $name,
                    ':is_diako' => $is_diako,
                    ':note' => $note,
                    ':email' => $email,
                    ':balance' => number_format($balance, 2, '.', ''),
                    ':id' => $id,
                ]);
                if ($ok) {
                    $_SESSION['flash'] = 'Kunde gespeichert.';
                    redirect_to_list();
                } else {
                    error_log('kunde.php: Update execute returned false for id ' . $id);
                    $errors[] = 'Fehler beim Speichern des Kunden.';
                }
            }
        } catch (PDOException $e) {
            error_log('kunde.php: DB-Fehler: ' . $e->getMessage());
            $errors[] = 'Interner Fehler beim Speichern des Kunden.';
        }
    }

    $customer = [
        'customer_type' => $customer_type,
        'name' => $name,
        'email' => $email,
        'note' => $note,
        'balance' => number_format($balance, 2, '.', ''),
        'is_diako' => $is_diako,
    ];
} else {
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare('SELECT id, customer_type, name, is_diako, note, email, balance FROM customers WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $customer = [
                    'customer_type' => $row['customer_type'] ?? 'guest',
                    'name' => $row['name'] ?? '',
                    'email' => $row['email'] ?? '',
                    'note' => $row['note'] ?? '',
                    'balance' => isset($row['balance']) ? number_format((float)$row['balance'], 2, '.', '') : '0.00',
                    'is_diako' => isset($row['is_diako']) ? (int)$row['is_diako'] : 0,
                ];
            } else {
                $_SESSION['flash'] = 'Kunde nicht gefunden.';
                redirect_to_list();
            }
        } catch (PDOException $e) {
            error_log('kunde.php: Fehler beim Laden des Kunden: ' . $e->getMessage());
            $_SESSION['flash'] = 'Fehler beim Laden des Kunden.';
            redirect_to_list();
        }
    }
}

// prepare page title for header.php
$page_title = ($id === 0) ? 'Neuen Kunden anlegen' : 'Kunde bearbeiten';
$title = $page_title;

// include shared header (with fallback)
$headerFile = __DIR__ . '/../header.php';
if (!file_exists($headerFile)) {
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>' . htmlspecialchars((string)$page_title, ENT_QUOTES | ENT_HTML5) . '</title></head><body>';
} else {
    require_once $headerFile;
}
?>
<main class="container" style="padding:18px;">
    <h1><?php echo htmlspecialchars((string)$page_title, ENT_QUOTES | ENT_HTML5); ?></h1>

    <?php if (!empty($errors)): ?>
        <div style="background:#fdd;padding:10px;margin-bottom:12px;border:1px solid #f99;">
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?php echo htmlspecialchars((string)$e, ENT_QUOTES | ENT_HTML5); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="kunde.php?id=<?php echo urlencode((string)$id); ?>">
        <div style="max-width:720px;display:grid;grid-template-columns:160px 1fr;column-gap:12px;row-gap:10px;align-items:start;">
            <label for="customer_type">Typ</label>
            <select id="customer_type" name="customer_type">
                <option value="guest" <?php echo ($customer['customer_type'] === 'guest') ? 'selected' : ''; ?>>guest</option>
                <option value="registered" <?php echo ($customer['customer_type'] === 'registered') ? 'selected' : ''; ?>>registered</option>
            </select>

            <label for="name">Name</label>
            <input id="name" type="text" name="name" value="<?php echo htmlspecialchars((string)$customer['name'], ENT_QUOTES | ENT_HTML5); ?>">

            <label for="email">E-Mail</label>
            <input id="email" type="text" name="email" value="<?php echo htmlspecialchars((string)$customer['email'], ENT_QUOTES | ENT_HTML5); ?>">

            <label for="note">Hinweis / Notiz</label>
            <textarea id="note" name="note" rows="4"><?php echo htmlspecialchars((string)$customer['note'], ENT_QUOTES | ENT_HTML5); ?></textarea>

            <label for="balance">Guthaben (Balance)</label>
            <input id="balance" type="text" name="balance" value="<?php echo htmlspecialchars((string)$customer['balance'], ENT_QUOTES | ENT_HTML5); ?>" style="text-align:right;font-variant-numeric:tabular-nums;">

            <div></div>
            <label style="font-weight:normal;"><input id="is_diako" type="checkbox" name="is_diako" value="1" <?php echo (!empty($customer['is_diako'])) ? 'checked' : ''; ?>> is_diako</label>

            <div style="grid-column:1/3;color:#666;font-size:13px;">Hinweis: Balance mit Punkt oder Komma eingeben (z. B. <em>12.50</em> oder <em>12,50</em>).</div>
        </div>

        <div style="margin-top:16px;display:flex;gap:12px;align-items:center;">
            <button type="submit">Speichern</button>
            <a href="kunden.php">Abbrechen</a>
        </div>
    </form>
</main>
<?php
// include shared footer (mit fallback)
$footerFile = __DIR__ . '/../footer.php';
if (!file_exists($footerFile)) {
    echo '</body></html>';
} else {
    require_once $footerFile;
}
?>
