<?php
$no_refresh = true;

require_once "../header.php";
require_once "../db.php";

$pdo = db_connect();
if (!$pdo) {
    die("Verbindung zur Datenbank konnte nicht hergestellt werden.");
}

/**
 * Fetch products from the database.
 */
function getProducts($pdo) {
    return $pdo->query("
        SELECT id, name, price_normal, price_diako
        FROM products
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch customers and products
$customers = $pdo->query("SELECT id, name, is_diako FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$products = getProducts($pdo);

// Initialize a message for success or errors
$message = null;
$message_color = "green";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $customer_id = intval($_POST['customer_id']);
        $order_items = json_decode($_POST['order_items_json'] ?? '[]', true);

        if ($customer_id <= 0 || empty($order_items)) {
            throw new Exception("Bitte wählen Sie einen Kunden und fügen Sie mindestens einen Artikel hinzu.");
        }

        // Check the customer's Diako status
        $stmt = $pdo->prepare("SELECT is_diako FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $is_diako = (bool) $stmt->fetchColumn();

        // Save the transaction
        $transaction_id = createTransaction($pdo, $customer_id, $order_items, $is_diako);
        $message = "Bestellung erfolgreich erstellt! Transaktions-ID: {$transaction_id}";
    } catch (Exception $e) {
        $message = "Fehler bei der Bestellung: " . htmlspecialchars($e->getMessage());
        $message_color = "red";
    }
}

/**
 * Save the transaction and related items to the database.
 */
function createTransaction($pdo, $customer_id, $order_items, $is_diako) {
    try {
        $pdo->beginTransaction();

        $total_amount = 0.0;
        $vat_percent = 19.0;

        foreach ($order_items as $item) {
            $price = $is_diako ? $item['diako_price'] : $item['normal_price'];
            $total_amount += $price * $item['quantity'];
        }
        $vat_amount = $total_amount * ($vat_percent / 100);

        // Insert the transaction
        $stmt = $pdo->prepare("
            INSERT INTO transactions (customer_id, total_amount, vat_amount, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$customer_id, $total_amount, $vat_amount]);
        $transaction_id = $pdo->lastInsertId();

        // Insert transaction items
        $stmt = $pdo->prepare("
            INSERT INTO transaction_items (transaction_id, product_id, description, quantity, unit_price, total_price) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($order_items as $item) {
            $price = $is_diako ? $item['diako_price'] : $item['normal_price'];
            $total_price = $price * $item['quantity'];
            $stmt->execute([
                $transaction_id,
                $item['product_id'],
                $item['description'],
                $item['quantity'],
                $price,
                $total_price
            ]);
        }

        $pdo->commit();
        return $transaction_id;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
?>

<div class="container">
    <h1>Bestellung erfassen</h1>

    <?php if (!empty($message)): ?>
        <p style="color: <?= htmlspecialchars($message_color) ?>;">
            <?= htmlspecialchars($message) ?>
        </p>
    <?php endif; ?>

    <form method="POST" id="order-form">
        <h2>Kunde</h2>
        <label for="customer_id">Kunde auswählen:</label>
        <select name="customer_id" id="customer_id" required>
            <option value="">-- Kunde auswählen --</option>
            <?php foreach ($customers as $customer): ?>
                <option value="<?= htmlspecialchars($customer['id']) ?>" data-is-diako="<?= $customer['is_diako'] ?>">
                    <?= htmlspecialchars($customer['name']) ?><?= $customer['is_diako'] ? " (Diako)" : "" ?>
                </option>
            <?php endforeach; ?>
        </select>

        <h2>Produkte hinzufügen</h2>
        <table id="order_items_table" border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-top: 20px;">
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
        <button type="button" id="add-item" style="margin-top: 10px;">Produkt hinzufügen</button>

        <h3>Gesamtsumme</h3>
        <p>Summe: <span id="order-total">0,00</span> €</p>

        <input type="hidden" name="order_items_json" id="order_items_json">
        <button type="submit">Bestellung speichern</button>
    </form>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const customersDropdown = document.querySelector("#customer_id");
    const orderItemsTable = document.querySelector("#order_items_table tbody");
    const orderTotalSpan = document.querySelector("#order-total");
    const orderItemsJsonInput = document.querySelector("#order_items_json");

    const products = <?= json_encode($products) ?>;

    // Dynamically update product prices when customer changes
    const updateProductPrices = () => {
        const selectedCustomer = customersDropdown.options[customersDropdown.selectedIndex];
        const isDiako = selectedCustomer.dataset.isDiako === "1";

        return products.map(product => ({
            id: product.id,
            name: product.name,
            price: isDiako ? product.price_diako : product.price_normal
        }));
    };

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
            row.querySelector(".product-total").textContent = lineTotal.toFixed(2);

            total += lineTotal;

            if (productSelector.value && quantity > 0) {
                items.push({
                    product_id: productSelector.value,
                    description: selectedOption.textContent,
                    normal_price: products.find(p => p.id == productSelector.value).price_normal,
                    diako_price: products.find(p => p.id == productSelector.value).price_diako,
                    quantity: quantity
                });
            }
        });
        orderTotalSpan.textContent = total.toFixed(2);
        orderItemsJsonInput.value = JSON.stringify(items);
    };

    const addRow = () => {
        const row = document.createElement("tr");
        row.innerHTML = `
            <td>
                <select class="product-selector">
                    <option value="">-- Produkt auswählen --</option>
                    ${updateProductPrices().map(p => `
                        <option data-price="${p.price}" value="${p.id}">${p.name}</option>
                    `).join("")}
                </select>
            </td>
            <td><span class="product-price">0.00</span></td>
            <td><input type="number" class="product-quantity" value="1" min="1"></td>
            <td><span class="product-total">0.00</span></td>
            <td><button type="button" class="remove-item">Entfernen</button></td>
        `;
        row.querySelector(".product-selector").addEventListener("change", calculateTotal);
        row.querySelector(".product-quantity").addEventListener("input", calculateTotal);
        row.querySelector(".remove-item").addEventListener("click", () => {
            row.remove();
            calculateTotal();
        });
        orderItemsTable.appendChild(row);
        calculateTotal();
    };

    customersDropdown.addEventListener("change", () => {
        orderItemsTable.innerHTML = ""; // Clear table
        addRow();
    });

    document.querySelector("#add-item").addEventListener("click", addRow);
    addRow();
});
</script>
<?php require_once "../footer.php"; ?>
