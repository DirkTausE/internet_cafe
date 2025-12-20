<?php
require_once "../header.php";
require_once "../db.php";

// Verbindung zur Datenbank herstellen
$pdo = db_connect();
if (!$pdo) {
    die("Verbindung zur Datenbank konnte nicht hergestellt werden.");
}

// Produkte abrufen
function getProducts($pdo) {
    return $pdo->query("
        SELECT id, name, price_normal 
        FROM products 
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Bestellung in Transaktionen, Artikel und Rechnungen speichern
function createTransactionWithInvoice($pdo, $customer_id, $order_items) {
    try {
        $pdo->beginTransaction(); // Transaktionsstart

        $total_amount = 0.00; // Summe berechnen
        $vat_percent = 19.00; // Mehrwertsteuer (%)

        foreach ($order_items as $item) {
            $total_amount += $item['quantity'] * $item['unit_price'];
        }
        $vat_amount = $total_amount * ($vat_percent / 100);

        // Bestellung in `transactions` speichern
        $stmt = $pdo->prepare("
            INSERT INTO transactions (customer_id, total_amount, vat_amount, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$customer_id, $total_amount, $vat_amount]);
        $transaction_id = $pdo->lastInsertId();

        if (!$transaction_id) {
            throw new Exception("Fehler: Die Bestellung konnte nicht erstellt werden (transactions).");
        }

        // Artikel in `transaction_items` speichern
        $stmt = $pdo->prepare("
            INSERT INTO transaction_items (transaction_id, product_id, description, quantity, unit_price, total_price)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($order_items as $item) {
            $stmt->execute([
                $transaction_id,
                $item['product_id'],
                $item['description'],
                $item['quantity'],
                $item['unit_price'],
                $item['quantity'] * $item['unit_price']
            ]);
        }

        $pdo->commit(); // Bestätigung
        return $transaction_id;
    } catch (Exception $e) {
        $pdo->rollBack(); // Rollback bei Fehlern
        error_log("Fehler bei der Bestellung: " . $e->getMessage());
        throw $e;
    }
}

// Kunden und Produkte abrufen
$customers = $pdo->query("SELECT id, name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$products = getProducts($pdo);

// Formularverarbeitung
$message = null;
$message_color = "green";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $customer_id = intval($_POST['customer_id']);
        $order_items = json_decode($_POST['order_items_json'] ?? '[]', true);

        if (empty($order_items) || $customer_id <= 0) {
            throw new Exception("Bitte wählen Sie einen Kunden aus und fügen Sie mindestens einen Artikel hinzu!");
        }

        $transaction_id = createTransactionWithInvoice($pdo, $customer_id, $order_items);
        $message = "Bestellung erfolgreich gespeichert. Transaktions-ID: {$transaction_id}";
    } catch (Exception $e) {
        $message_color = "red";
        $message = htmlspecialchars($e->getMessage());
    }
}
?>

<div class="container">
    <h1>Neue Bestellung</h1>

    <?php if (!empty($message)): ?>
        <p style="color: <?= htmlspecialchars($message_color) ?>;"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <form method="POST" id="order-form">
        <h2>Kunde</h2>
        <label for="customer_id">Kunde auswählen:</label>
        <select name="customer_id" id="customer_id" required>
            <option value="">-- Kunde auswählen --</option>
            <?php foreach ($customers as $customer): ?>
                <option value="<?= htmlspecialchars($customer['id']) ?>">
                    <?= htmlspecialchars($customer['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <h2>Artikel hinzufügen</h2>
        <table id="order_items_table" border="1" style="width: 100%; margin-top: 1rem;">
            <thead>
                <tr>
                    <th>Produkt</th>
                    <th>Preis</th>
                    <th>Menge</th>
                    <th>Gesamt</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
        <button type="button" id="add-item" style="margin-top: 10px;">Neues Produkt hinzufügen</button>

        <h3>Zusammenfassung</h3>
        <p>Gesamtsumme: <span id="order-total">0,00</span> €</p>
        <input type="hidden" name="order_items_json" id="order_items_json">
        <button type="submit">Bestellung speichern</button>
    </form>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const products = <?= json_encode($products) ?>;

    const orderItemsTable = document.querySelector("#order_items_table tbody");
    const orderTotalSpan = document.querySelector("#order-total");
    const orderItemsJsonInput = document.querySelector("#order_items_json");

    // Gesamtsumme und JSON-Daten aktualisieren
    const calculateTotal = () => {
        let total = 0;
        const items = [];
        orderItemsTable.querySelectorAll("tr").forEach(row => {
            const productSelector = row.querySelector(".product-selector");
            const selectedOption = productSelector.options[productSelector.selectedIndex];
            const price = parseFloat(selectedOption.dataset.price || 0);
            const quantity = parseInt(row.querySelector(".product-quantity").value || 0, 10);
            const lineTotal = price * quantity;

            row.querySelector(".product-price").textContent = price.toFixed(2);
            row.querySelector(".line-total").textContent = lineTotal.toFixed(2);
            total += lineTotal;

            if (selectedOption.value && quantity > 0) {
                items.push({
                    product_id: selectedOption.value,
                    description: selectedOption.textContent.trim(),
                    unit_price: price,
                    quantity: quantity
                });
            }
        });
        orderTotalSpan.textContent = total.toFixed(2);
        orderItemsJsonInput.value = JSON.stringify(items);
    };

    // Neue Zeile hinzufügen
    const addRow = () => {
        const row = document.createElement("tr");
        row.innerHTML = `
            <td>
                <select class="product-selector">
                    <option value="">-- Produkt wählen --</option>
                    ${products.map(p => `<option data-price="${p.price_normal}" value="${p.id}">${p.name}</option>`).join("")}
                </select>
            </td>
            <td><span class="product-price">0.00</span> €</td>
            <td><input class="product-quantity" type="number" min="1" value="1"></td>
            <td><span class="line-total">0.00</span> €</td>
            <td><button type="button" class="remove-item">Entfernen</button></td>
        `;
        // Events für Dropdown und Menge
        row.querySelector(".product-selector").addEventListener("change", calculateTotal);
        row.querySelector(".product-quantity").addEventListener("input", calculateTotal);
        row.querySelector(".remove-item").addEventListener("click", () => {
            row.remove();
            calculateTotal();
        });
        orderItemsTable.appendChild(row);
        calculateTotal();
    };

    document.querySelector("#add-item").addEventListener("click", addRow);
    addRow();
});
</script>
<?php require_once "../footer.php"; ?>
