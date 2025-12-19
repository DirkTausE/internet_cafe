<?php
$no_refresh=true;

require_once "../header.php";
require_once "../db.php";

$pdo = db_connect();
if (!$pdo) {
    die("Verbindung zur Datenbank fehlgeschlagen. Bitte überprüfe die Konfiguration.");
}

// Formularverarbeitung für neuen Artikel oder Aktualisierung
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $description = $_POST['description'] ?? '';
    $price_normal = floatval($_POST['price_normal'] ?? 0);
    $price_diako = floatval($_POST['price_diako'] ?? 0);
    $vat_percent = intval($_POST['vat_percent'] ?? 0);
    $id = $_POST['id'] ?? null;

    if ($id) {
        // Bearbeiten eines Artikels
        $stmt = $pdo->prepare("UPDATE products SET name = ?, description = ?, price_normal = ?, price_diako = ?, vat_percent = ? WHERE id = ?");
        $success = $stmt->execute([$name, $description, $price_normal, $price_diako, $vat_percent, $id]);
        $message = $success ? "Artikel erfolgreich aktualisiert!" : "Fehler beim Aktualisieren des Artikels.";
    } else {
        // Neuen Artikel hinzufügen
        $stmt = $pdo->prepare("INSERT INTO products (name, description, price_normal, price_diako, vat_percent, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $success = $stmt->execute([$name, $description, $price_normal, $price_diako, $vat_percent]);
        $message = $success ? "Artikel erfolgreich hinzugefügt!" : "Fehler beim Hinzufügen des Artikels.";
    }
    echo "<div class='message'>" . htmlspecialchars($message) . "</div>";
}

// Artikel aus der Tabelle abrufen
$articles = $pdo->query("SELECT id, name, description, price_normal, price_diako, vat_percent, created_at FROM products")->fetchAll();

?>

<div class="container">
    <h1>Artikelverwaltung</h1>

    <!-- Formular für neuen Artikel oder Bearbeitung -->
    <form method="POST" action="" class="form-inline">
        <input type="hidden" name="id" id="id" value="">
        <input type="text" name="name" id="name" placeholder="Artikelname" required>
        <input type="text" name="description" id="description" placeholder="Beschreibung" required>
        <input type="number" step="0.01" name="price_normal" id="price_normal" placeholder="Normalpreis" required>
        <input type="number" step="0.01" name="price_diako" id="price_diako" placeholder="Sonderpreis" required>
        <input type="number" step="0.01" name="vat_percent" id="vat_percent" placeholder="MwSt (%)" required>
        <button type="submit">Speichern</button>
    </form>

    <!-- Tabelle für vorhandene Artikel -->
    <table border="1" cellpadding="5" cellspacing="0" style="margin-top: 20px; width: 100%; text-align: left;">
        <thead>
            <tr>
                <th>ID</th>
                <th>Artikelname</th>
                <th>Beschreibung</th>
                <th>Normalpreis</th>
                <th>Sonderpreis</th>
                <th>Mehrwertsteuer</th>
                <th>Erstellt am</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($articles) > 0): ?>
                <?php foreach ($articles as $article): ?>
                    <tr>
                        <td><?= htmlspecialchars($article['id']) ?></td>
                        <td><?= htmlspecialchars($article['name']) ?></td>
                        <td><?= htmlspecialchars($article['description']) ?></td>
                        <td><?= number_format($article['price_normal'], 2) ?> €</td>
                        <td><?= number_format($article['price_diako'], 2) ?> €</td>
                        <td><?= htmlspecialchars($article['vat_percent']) ?>%</td>
                        <td><?= htmlspecialchars($article['created_at']) ?></td>
                        <td>
                            <button type="button" onclick="editArticle(<?= htmlspecialchars(json_encode($article)) ?>)">Bearbeiten</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8">Keine Artikel vorhanden.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function editArticle(article) {
    document.getElementById('id').value = article.id;
    document.getElementById('name').value = article.name;
    document.getElementById('description').value = article.description;
    document.getElementById('price_normal').value = article.price_normal;
    document.getElementById('price_diako').value = article.price_diako;
    document.getElementById('vat_percent').value = article.vat_percent;
}
</script>

<?php
require_once "../footer.php";
?>
