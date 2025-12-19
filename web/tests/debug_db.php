<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Datenbank-Debugging</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            line-height: 1.5;
        }
        h1, h2, h3 {
            color: #333;
        }
        p {
            margin: 10px 0;
        }
        .success {
            color: green;
        }
        .error {
            color: red;
        }
        .warning {
            color: orange;
        }
        pre {
            background: #f4f4f4;
            padding: 10px;
            border: 1px solid #ddd;
        }
        ul {
            list-style: disc;
            margin-left: 20px;
        }
    </style>
</head>
<body>
    <?php
    require_once "../db.php"; // Einbinden der Datenbankfunktionalität

    echo "<h1>Datenbank-Debugging</h1>";

    $pdo = db_connect();
    if (!$pdo) {
        // Wenn keine Verbindung hergestellt werden konnte
        echo "<p class='error'>Fehler: Verbindung zur Datenbank konnte nicht hergestellt werden. Bitte überprüfe die Konfiguration.</p>";
        echo "</body></html>";
        exit;
    }

    // Datenbankkonfiguration laden und anzeigen
    $config = load_db_config();
    echo "<h2>Verwendete Konfiguration</h2>";
    echo "<pre>" . htmlspecialchars(print_r($config, true)) . "</pre>";

    // Test auf Tabelle `computers`
    echo "<h2>Prüfung der Tabelle 'computers'</h2>";
    if (table_exists($pdo, 'computers')) {
        echo "<p class='success'>Die Tabelle 'computers' existiert.</p>";

        // Spalten der Tabelle abrufen und anzeigen
        echo "<h3>Spalten von 'computers'</h3>";
        $columns = table_columns($pdo, 'computers');
        if (!empty($columns)) {
            echo "<ul>";
            foreach ($columns as $column) {
                echo "<li>" . htmlspecialchars($column) . "</li>";
            }
            echo "</ul>";
        } else {
            echo "<p class='warning'>Warnung: Es konnten keine Spalten für die Tabelle 'computers' abgerufen werden.</p>";
        }
    } else {
        echo "<p class='error'>Fehler: Die Tabelle 'computers' existiert nicht in der Datenbank.</p>";
    }

    // Abschluss der Tests
    echo "<h2>Status</h2>";
    echo "<p class='success'>Die Tests wurden abgeschlossen.</p>";
    ?>
</body>
</html>
