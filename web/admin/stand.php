<?php
// web/admin/stand.php
// Seite zur Anzeige von gruppierten und ungruppierten Einträgen eines Kunden, mit Storno-Funktion.
declare(strict_types=1);

$page_title = 'Aufgelaufene Kosten eines Kunden anzeigen';
$no_refresh = true;

// Session sicher starten
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Datenbankverbindung einbinden
require_once __DIR__ . '/../db.php';

$pdo = function_exists('db_get_pdo') ? db_get_pdo() : (function_exists('db_connect') ? db_connect() : null);
if (!($pdo instanceof PDO)) {
    error_log('stand.php: Keine gültige PDO-Verbindung vorhanden.');
    http_response_code(500);
    echo 'Interner Serverfehler (keine DB-Verbindung).';
    exit;
}

// Kundenliste abrufen
$customers = [];
try {
    $stmt = $pdo->query('SELECT id, name FROM customers ORDER BY name');
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('stand.php: Fehler beim Laden der Kunden: ' . $e->getMessage());
    $customers = [];
}

// Variablen für Einträge
$customer_id = isset($_REQUEST['customer_id']) ? (int)$_REQUEST['customer_id'] : null;
$grouped_offers = [];
$ungrouped_offers = [];
$total_price_grouped = 0.0;
$total_price_ungrouped = 0.0;
$errors = [];

// Gruppierte Einträge abrufen
if ($customer_id) {
    try {
        $stmt = $pdo->prepare('
            SELECT 
                p.name AS product_name,
                t_items.description,
                SUM(t_items.quantity) AS total_quantity,
                t_items.unit_price,
                SUM(t_items.quantity * t_items.unit_price) AS total_price
            FROM transaction_items t_items
            JOIN transactions t ON t.id = t_items.transaction_id
            LEFT JOIN products p ON p.id = t_items.product_id
            WHERE t.customer_id = :customer_id
            GROUP BY p.name, t_items.description, t_items.unit_price
            ORDER BY p.name, t_items.description
        ');
        $stmt->execute([':customer_id' => $customer_id]);
        $grouped_offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Gesamtbetrag für gruppierte Einträge berechnen
        foreach ($grouped_offers as $offer) {
            $total_price_grouped += (float)$offer['total_price'];
        }
    } catch (PDOException $e) {
        $errors[] = 'Fehler beim Laden der gruppierten Einträge.';
        error_log('stand.php: Fehler bei der SQL-Abfrage (gruppiert): ' . $e->getMessage());
    }
}

// Ungruppierte Einträge abrufen
if ($customer_id) {
    try {
        $stmt = $pdo->prepare('
            SELECT 
                t_items.id AS item_id,
                p.name AS product_name,
                t_items.description,
                t_items.quantity,
                t_items.unit_price,
                (t_items.quantity * t_items.unit_price) AS total_price
            FROM transaction_items t_items
            JOIN transactions t ON t.id = t_items.transaction_id
            LEFT JOIN products p ON p.id = t_items.product_id
            WHERE t.customer_id = :customer_id
            ORDER BY p.name, t_items.description
        ');
        $stmt->execute([':customer_id' => $customer_id]);
        $ungrouped_offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Gesamtbetrag für ungruppierte Einträge berechnen
        foreach ($ungrouped_offers as $offer) {
            $total_price_ungrouped += (float)$offer['total_price'];
        }
    } catch (PDOException $e) {
        $errors[] = 'Fehler beim Laden der ungruppierten Einträge.';
        error_log('stand.php: Fehler bei der SQL-Abfrage (ungruppiert): ' . $e->getMessage());
    }
}

// Storno ausführen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['storno_item_id']) && $customer_id) {
    $item_id = (int)$_POST['storno_item_id'];

    try {
        $stmt = $pdo->prepare('
            UPDATE transaction_items
            SET quantity = 0
            WHERE id = :item_id
        ');
        $stmt->execute([':item_id' => $item_id]);
        $_SESSION['flash'] = 'Der Posten wurde erfolgreich auf 0 gesetzt.';
        header('Location: stand.php?customer_id=' . urlencode((string)$customer_id));
        exit;
    } catch (PDOException $e) {
        $errors[] = 'Fehler beim Stornieren des Postens.';
        error_log('stand.php: Fehler beim Stornieren der Einträge: ' . $e->getMessage());
    }
}

// Header einbinden
$headerFile = __DIR__ . '/../header.php';
if (is_file($headerFile)) {
    require_once $headerFile;
} else {
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>' . htmlspecialchars($page_title) . '</title></head><body>';
}
?>

<main class="container" style="padding: 18px;">
    <h1><?php echo htmlspecialchars($page_title); ?></h1>

    <?php if (!empty($errors)): ?>
        <div style="color: red; margin-bottom: 16px;">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash'])): ?>
        <div style="color: green; margin-bottom: 16px;">
            <?php echo htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?>
        </div>
    <?php endif; ?>

    <form method="get" action="stand.php" style="margin-bottom: 16px;">
        <label for="customer_id">Kunde:</label>
        <select id="customer_id" name="customer_id">
            <option value="">-- Kunden auswählen --</option>
            <?php foreach ($customers as $customer): ?>
                <option value="<?php echo $customer['id']; ?>" <?php echo $customer_id == $customer['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($customer['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Anzeigen</button>
    </form>

    <?php if (!empty($grouped_offers)): ?>
        <h2>Gruppierte Einträge</h2>
        <table border="1" cellpadding="8" cellspacing="0" width="100%" style="border-collapse: collapse;">
            <thead>
                <tr>
                    <th>Produkt</th>
                    <th>Beschreibung</th>
                    <th>Menge</th>
                    <th>Einzelpreis (€)</th>
                    <th>Gesamt (€)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grouped_offers as $offer): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$offer['product_name']); ?></td>
                        <td><?php echo htmlspecialchars((string)$offer['description']); ?></td>
                        <td style="text-align: right;"><?php echo (int)$offer['total_quantity']; ?></td>
                        <td style="text-align: right;"><?php echo number_format((float)$offer['unit_price'], 2, ',', '.'); ?></td>
                        <td style="text-align: right;"><?php echo number_format((float)$offer['total_price'], 2, ',', '.'); ?> €</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align: right;">Gesamt:</td>
                    <td style="text-align: right;"><?php echo number_format((float)$total_price_grouped, 2, ',', '.'); ?> €</td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <?php if (!empty($ungrouped_offers)): ?>
        <h2>Ungruppierte Einträge</h2>
        <table border="1" cellpadding="8" cellspacing="0" width="100%" style="border-collapse: collapse;">
            <thead>
                <tr>
                    <th>Produkt</th>
                    <th>Beschreibung</th>
                    <th>Menge</th>
                    <th>Einzelpreis (€)</th>
                    <th>Gesamt (€)</th>
                    <th>Aktion</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ungrouped_offers as $offer): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$offer['product_name']); ?></td>
                        <td><?php echo htmlspecialchars((string)$offer['description']); ?></td>
                        <td style="text-align: right;"><?php echo (int)$offer['quantity']; ?></td>
                        <td style="text-align: right;"><?php echo number_format((float)$offer['unit_price'], 2, ',', '.'); ?></td>
                        <td style="text-align: right;"><?php echo number_format((float)$offer['total_price'], 2, ',', '.'); ?> €</td>
                        <td style="text-align: center;">
                            <form method="post" action="stand.php?customer_id=<?php echo (int)$customer_id; ?>" style="display: inline;">
                                <input type="hidden" name="storno_item_id" value="<?php echo (int)$offer['item_id']; ?>">
                                <button type="submit" style="background-color: red; color: white; border: none; padding: 5px 10px;">Storno</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align: right;">Gesamt:</td>
                    <td style="text-align: right;"><?php echo number_format((float)$total_price_ungrouped, 2, ',', '.'); ?> €</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>
</main>

<?php
// Footer einbinden
$footerFile = __DIR__ . '/../footer.php';
if (is_file($footerFile)) {
    require_once $footerFile;
} else {
    echo '</body></html>';
}
?>
