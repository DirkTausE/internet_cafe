<?php
require_once "../header.php";
require_once "../db.php";

$pdo = db_connect();
if (!$pdo) {
    die("Verbindung zur Datenbank konnte nicht hergestellt werden.");
}

// Funktion: Unberechnete Transaktionen eines Kunden abrufen
function getUnbilledTransactions($pdo, $customer_id) {
    $stmt = $pdo->prepare("
        SELECT t.id, t.total_amount, t.vat_amount 
        FROM transactions t
        LEFT JOIN invoice_transactions it ON t.id = it.transaction_id
        WHERE t.customer_id = ? AND it.transaction_id IS NULL
    ");
    $stmt->execute([$customer_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Funktion: Eine Rechnung erstellen und Transaktionen zuordnen
function createInvoice($pdo, $customer_id, $transactions) {
    if (count($transactions) === 0) {
        return false; // Keine Transaktionen zum Verarbeiten
    }

    // Gesamtsumme berechnen
    $total_amount = array_sum(array_column($transactions, 'total_amount'));

    // Neue Rechnung erstellen
    $stmt = $pdo->prepare("
        INSERT INTO invoices (customer_id, total_amount, created_at) 
        VALUES (?, ?, NOW())
    ");
    $stmt->execute([$customer_id, $total_amount]);
    $invoice_id = $pdo->lastInsertId();

    // Transaktionen mit der Rechnung verknüpfen
    $stmt = $pdo->prepare("
        INSERT INTO invoice_transactions (invoice_id, transaction_id, created_at) 
        VALUES (?, ?, NOW())
    ");
    foreach ($transactions as $transaction) {
        $stmt->execute([$invoice_id, $transaction['id']]);
    }

    return $invoice_id;
}

// Den optionalen `customer_id`-Parameter aus der URL abrufen
$preselected_customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : null;

// Automatische Verarbeitung von Kunden
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = intval($_POST['customer_id']);

    // Unberechnete Transaktionen abrufen
    $transactions = getUnbilledTransactions($pdo, $customer_id);

    if (count($transactions) > 0) {
        // Rechnung erstellen
        $invoice_id = createInvoice($pdo, $customer_id, $transactions);

        if ($invoice_id) {
            $message = "Rechnung #{$invoice_id} wurde erfolgreich erstellt.";
        } else {
            $message = "Fehler beim Erstellen der Rechnung.";
        }
    } else {
        $message = "Keine unberechneten Transaktionen für diesen Kunden.";
    }
}

// Kunden abrufen
$customers = $pdo->query("
    SELECT id, name, email 
    FROM customers 
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container">
    <h1>Abrechnung</h1>

    <?php if (!empty($message)): ?>
        <p style="color: green;"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <!-- Formular: Kunde auswählen und unberechnete Transaktionen in Rechnung zusammenfassen -->
    <h2>Neue Rechnung erstellen</h2>
    <form method="POST" action="">
        <label for="customer_id">Kunde:</label>
        <select name="customer_id" id="customer_id" required>
            <?php foreach ($customers as $customer): ?>
                <option value="<?= htmlspecialchars($customer['id']) ?>"
                    <?= $preselected_customer_id === $customer['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($customer['name']) ?> (<?= htmlspecialchars($customer['email']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Rechnung erstellen</button>
    </form>

    <!-- Tabelle: Übersicht der bestehenden Rechnungen -->
    <h2>Bestehende Rechnungen</h2>
    <table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-top: 20px;">
        <thead>
            <tr>
                <th>Rechnungs-ID</th>
                <th>Kunde</th>
                <th>Gesamtbetrag</th>
                <th>Datum</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $invoices = $pdo->query("
                SELECT i.id AS invoice_id, c.name AS customer_name, i.total_amount, i.created_at
                FROM invoices i
                JOIN customers c ON i.customer_id = c.id
                ORDER BY i.created_at DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <?php if (count($invoices) > 0): ?>
                <?php foreach ($invoices as $invoice): ?>
                    <tr>
                        <td><?= htmlspecialchars($invoice['invoice_id']) ?></td>
                        <td><?= htmlspecialchars($invoice['customer_name']) ?></td>
                        <td><?= number_format($invoice['total_amount'], 2) ?> €</td>
                        <td><?= htmlspecialchars($invoice['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4">Keine Rechnungen vorhanden.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
require_once "../footer.php";
?>
