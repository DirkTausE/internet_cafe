#!/usr/bin/env bash
# init_repo.sh
# Erzeugt die Verzeichnisstruktur und Beispiel-Dateien für das Internetcafe-Projekt.
# Usage:
#   ./init_repo.sh           # nur Dateien/Ordner anlegen
#   ./init_repo.sh --git     # zusätzlich git init + branch + initial commit
#   ./init_repo.sh --git --push  # zusätzlich optional remote setzen und pushen (fragt nach URL)
#
# WARNUNG: Dieses Script legt Beispiel-Konfigurationsdateien an. Trage KEINE echten Passwörter in
# config.php.example ein. Erzeuge stattdessen lokal config.php aus config.php.example und setze
# sichere Dateirechte.
set -euo pipefail

BRANCH="examples-internetcafe"
DO_GIT=0
DO_PUSH=0

for arg in "$@"; do
  case "$arg" in
    --git) DO_GIT=1 ;;
    --push) DO_PUSH=1 ;;
    *) ;;
  esac
done

# Basisverzeichnisse
dirs=(
  web
  web/assets/css
  web/assets/js
  clients
  db
  docs
  scripts
  storage/invoices
  storage/scans
)

echo "Erstelle Projektverzeichnisstruktur..."
for d in "${dirs[@]}"; do
  if [ ! -d "$d" ]; then
    mkdir -p "$d"
    echo "  + $d"
  else
    echo "  = $d (existiert)"
  fi
done

# .gitignore
cat > .gitignore <<'EOF'
# Projekt-spezifische Ignorierliste
/storage/
config.php
.env
*.log
*.sqlite
*.key
*.pem
/.idea/
/.vscode/
/node_modules/
/vendor/
.DS_Store
Thumbs.db
EOF

echo "Erstellt .gitignore"

# config.php.example (root)
cat > config.php.example <<'PHP_CONF'
<?php
// config.php.example - NICHT produktive Zugangsdaten einchecken.
// Kopiere diese Datei zu config.php und fülle die echten Werte ein.
// Setze die Dateirechte: chmod 640 config.php  && chown www-data:www-data config.php

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'internetcafe');
define('DB_USER', 'cafe_user');
define('DB_PASS', 'REPLACE_WITH_DB_PASSWORD');

define('BASE_URL', 'http://server.local'); // oder https://domain.tld
define('API_KEY', 'REPLACE_WITH_API_KEY'); // per-client keys separat verwalten

define('INVOICE_PDF_PATH', '/var/www/internetcafe/storage/invoices');
define('SCAN_STORAGE_PATH', '/var/www/internetcafe/storage/scans');
define('WKHTMLTOPDF_BIN', '/usr/bin/wkhtmltopdf');

// Logging / debug
define('APP_DEBUG', false);
PHP_CONF

echo "Erstellt config.php.example"

# README.md
cat > README.md <<'MD'
# Internetcafe - Beispielprojekt

Dieses Repository enthält Beispiel-Dateien und eine Struktur für ein Internetcafé-Verwaltungssystem
(Backend: PHP + MariaDB, Clients: Python3).

Wichtige Hinweise:
- Keine echten Zugangsdaten in `config.php.example` eintragen.
- Vor dem Einsatz: `cp config.php.example config.php` und passwort/keys lokal ausfüllen.
- `storage/` enthält Laufzeitdaten (invoices, scans) und ist in `.gitignore` aufgeführt.

Verzeichnisstruktur (Kurz):
- web/        -> PHP Webanwendung (Dashboard, API, Templates)
- clients/    -> Client-Skripte (clientd, greeter)
- db/         -> SQL Schema & Seed
- docs/       -> Installationsanleitungen
- scripts/    -> Hilfsskripte
- storage/    -> Laufzeitdaten (nicht versioniert)

Siehe auch REPO_STRUCTURE.md für Details.
MD

echo "Erstellt README.md"

# REPO_STRUCTURE.md
cat > REPO_STRUCTURE.md <<'MD'
# Repository-Struktur: internet_cafe

Empfohlene Hauptverzeichnisse und Zweck:

web/         -> PHP Webanwendung (public + app Dateien)
clients/     -> Client-Skripte (clientd, greeter, systemd unit)
db/          -> SQL-Schema und Seed-Daten
assets/      -> Gemeinsame statische Dateien (CSS, JS)
docs/        -> Installationsanleitungen, README, Greeter-Hinweise
scripts/     -> Hilfs-Skripte
storage/     -> Laufzeitdaten (in .gitignore, nicht versionieren): invoices, scans, uploads

Wichtig: config.php aus config.php.example lokal erzeugen (nicht ins Repo committen).
MD

echo "Erstellt REPO_STRUCTURE.md"

# Minimaler Web-App Satz (web/)
cat > web/index.php <<'PHP_WEB'
<?php
// web/index.php - Minimaler Einstieg (Dashboard placeholder)
require_once __DIR__ . '/../config.php';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Internetcafé Verwaltung - Dashboard</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
  <header class="topbar">
    <div class="brand">Internetcafé Verwaltung</div>
  </header>
  <main class="container">
    <h1>Dashboard (Beispiel)</h1>
    <p>Hier kommt die Dashboard-Oberfläche (Clients & offene Rechnungen).</p>
    <p>Bearbeite <code>web/index.php</code> und die Dateien in <code>web/</code>, um die Funktionalität zu implementieren.</p>
  </main>
</body>
</html>
PHP_WEB

cat > web/api.php <<'PHP_API'
<?php
// web/api.php - Platzhalter API (erweitern mit echten Endpoints)
// Beispiel: GET /web/api.php?q=ping
header('Content-Type: application/json; charset=utf-8');
$q = $_GET['q'] ?? '';
if ($q === 'ping') {
    echo json_encode(['ok' => true, 'time' => date('c')]);
    exit;
}
echo json_encode(['error'=>'unknown endpoint', 'requested'=>$q]);
PHP_API

cat > web/db.php <<'PHP_DB'
<?php
// web/db.php - PDO-Verbindung (verwenden config.php lokal)
require_once __DIR__ . '/../config.php';
function getDb(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        \$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        \$opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, \$opts);
    }
    return \$pdo;
}
PHP_DB

cat > web/header.php <<'PHP_HDR'
<?php
// web/header.php - einfache Header include
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Internetcafé Verwaltung</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <script src="assets/js/dashboard.js" defer></script>
</head>
<body>
<header class="topbar">
  <div class="brand">Internetcafé Verwaltung</div>
</header>
<main class="container">
PHP_HDR

cat > web/footer.php <<'PHP_FTR'
<?php
// web/footer.php - Footer include
?>
</main>
<footer class="footer">
  <div>Internetcafé &middot; Verwaltungssystem</div>
</footer>
</body>
</html>
PHP_FTR

# assets CSS and JS
cat > web/assets/css/style.css <<'CSS'
/* web/assets/css/style.css - Beispiel-Styles */
body {
  font-family: Arial, Helvetica, sans-serif;
  margin: 0;
  background: #f5f7fa;
  color: #222;
}
.topbar {
  background: #1f6feb;
  color: #fff;
  padding: 12px 16px;
}
.container { padding: 18px; }
CSS

cat > web/assets/js/dashboard.js <<'JS'
// web/assets/js/dashboard.js - Platzhalter
document.addEventListener('DOMContentLoaded', function(){
  console.log('Dashboard JS geladen');
});
JS

echo "Erstellt minimalen Web-App Satz in web/"

# clients: einfache Platzhalter für clientd und greeter
cat > clients/clientd.py <<'PY_CLIENT'
#!/usr/bin/env python3
# clients/clientd.py - Minimaler Platzhalter für Client-Daemon
# Bitte anpassen: SERVER_URL, API_KEY, CUPS-Integration etc.

import time, socket, requests
SERVER_URL = "http://server.local/web/api.php?q="
API_KEY = "REPLACE_ME"
HOSTNAME = socket.gethostname()

def main():
    while True:
        try:
            r = requests.get(SERVER_URL + "ping", timeout=5)
            print("Server:", r.json())
        except Exception as e:
            print("Fehler beim API-Request:", e)
        time.sleep(30)

if __name__ == '__main__':
    main()
PY_CLIENT
chmod +x clients/clientd.py

cat > clients/greeter_minimal.py <<'PY_GREETER'
#!/usr/bin/env python3
# clients/greeter_minimal.py - Zeigt nur Hintergrund (GTK3)
import gi, sys, os
gi.require_version('Gtk','3.0')
from gi.repository import Gtk, GdkPixbuf

DEFAULT_BG = '/usr/share/backgrounds/xfce/xfce-blue.jpg'
img = sys.argv[1] if len(sys.argv) > 1 else DEFAULT_BG

win = Gtk.Window()
win.set_decorated(False)
win.fullscreen()
if os.path.exists(img):
    pixbuf = GdkPixbuf.Pixbuf.new_from_file_at_scale(img, 1920, 1080, False)
    win.add(Gtk.Image.new_from_pixbuf(pixbuf))
else:
    win.add(Gtk.Label(label="Hintergrundbild nicht gefunden"))
win.connect("destroy", Gtk.main_quit)
win.show_all()
Gtk.main()
PY_GREETER
chmod +x clients/greeter_minimal.py

cat > clients/clientd.service <<'UNIT'
[Unit]
Description=Internetcafe Client Daemon (Beispiel)
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/python3 /opt/internetcafe/clientd.py
Restart=on-failure
User=root

[Install]
WantedBy=multi-user.target
UNIT

echo "Erstellt Beispiel-Clients in clients/"

# db/schema.sql - kleine Platzhalter/Start
cat > db/schema.sql <<'SQL'
-- db/schema.sql - Beispiel-DB-Struktur (kürzer gehalten).
-- Ergänze hier die vollständige Struktur nach Bedarf (siehe Conversation).
CREATE DATABASE IF NOT EXISTS internetcafe DEFAULT CHARACTER SET = 'utf8mb4' DEFAULT COLLATE = 'utf8mb4_general_ci';
USE internetcafe;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(150),
  role ENUM('admin','operator') NOT NULL DEFAULT 'operator',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS computers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hostname VARCHAR(100) NOT NULL UNIQUE,
  mac_address VARCHAR(17),
  ip_address VARCHAR(45),
  description VARCHAR(255),
  current_state ENUM('starting','frei','gast','pause','wartung','STOP','OFF') NOT NULL DEFAULT 'OFF',
  last_checkin TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
-- Ergänze weitere Tabellen (sessions, invoices, invoice_items, tariffs, products, print_jobs, scan_jobs, blocked_sites usw.)
SQL

echo "Erstellt db/schema.sql (Kurzversion)"

# docs/installation.md (kurz)
cat > docs/installation.md <<'MD'
# Installation (Kurz)

1. Server (Ubuntu-MATE): Pakete installieren (Apache2, MariaDB, PHP, wkhtmltopdf, squid, cups, python3)
2. Datenbank anlegen:
   mysql_secure_installation
   Erstelle DB und Benutzer und importiere db/schema.sql
3. Webapp: Kopiere web/ nach /var/www/internetcafe, setzte Permissions (www-data)
4. Clients: Kopiere clients/ nach /opt/internetcafe, passe API_KEY und SERVER_URL an
5. Erstelle config.php lokal aus config.php.example und setze Dateirechte
MD

echo "Erstellt docs/installation.md"

# scripts/organize.sh - kleiner helper (optional)
cat > scripts/organize.sh <<'SH'
#!/usr/bin/env bash
# Kleines Hilfs-Skript: zeigt Struktur
echo "Projektstruktur:"
tree -a -I '.git|node_modules|vendor'
SH
chmod +x scripts/organize.sh

echo "Erstellt scripts/organize.sh"

# Optional: Git initialisieren + initial commit
if [ "$DO_GIT" -eq 1 ]; then
  if [ -d .git ]; then
    echo "Git-Repository existiert bereits (.)"
  else
    echo "Initialisiere Git-Repository..."
    git init
  fi

  # Wechsel auf Branch
  echo "Erstelle und wechsle zu Branch $BRANCH"
  git checkout -b "$BRANCH"

  # Stelle sicher, config.php nicht versehentlich gestaged ist
  git rm --cached -f config.php >/dev/null 2>&1 || true

  echo "Füge Dateien hinzu und commite..."
  git add .
  git commit -m "Initial: project skeleton and examples"

  echo "Git initial commit fertig auf Branch $BRANCH."

  if [ "$DO_PUSH" -eq 1 ]; then
    read -p "Remote URL für origin (z.B. git@github.com:DirkTausE/internet_cafe.git) : " REMOTE_URL
    if [ -n "$REMOTE_URL" ]; then
      if git remote | grep -q origin; then
        git remote set-url origin "$REMOTE_URL"
      else
        git remote add origin "$REMOTE_URL"
      fi
      echo "Pushe Branch $BRANCH zu origin..."
      git push -u origin "$BRANCH"
      echo "Push fertig."
    else
      echo "Keine Remote-URL angegeben. Überspringe push."
    fi
  else
    echo "Push nicht ausgeführt (verwende --push, um zu pushen)."
  fi
else
  echo "Git-Init übersprungen. Falls gewünscht, führe das Script mit --git aus."
fi

echo
echo "Fertig. Nächste Schritte:"
echo " - Prüfe 'config.php.example' und erstelle lokal 'config.php'."
echo " - Passe web/ und clients/ Dateien an (DB-Zugang, API_KEY, Server-URL)."
echo " - Wenn gewünscht git initialisieren: ./init_repo.sh --git --push"
echo " - Setze Dateirechte für storage/: sudo chown -R www-data:www-data storage/ && sudo chmod -R 750 storage/"

# Self-deletion: Remove this initialization script from the repository
echo
echo "Entferne init_repo.sh aus dem Repository..."
SCRIPT_PATH="${BASH_SOURCE[0]}"

# Remove from git if git was initialized
if [ "$DO_GIT" -eq 1 ]; then
  if git rm -f "$SCRIPT_PATH" >/dev/null 2>&1; then
    if git commit -m "Remove init_repo.sh after initialization" >/dev/null 2>&1; then
      echo "  - init_repo.sh aus Git entfernt und committed"
    else
      echo "  - Warnung: git commit fehlgeschlagen (Datei ist für Löschung vorgemerkt)"
    fi
  else
    echo "  - Hinweis: git rm übersprungen (Datei möglicherweise nicht im Repository)"
  fi
fi

# Delete the physical file
# Note: The script can safely delete itself while running because it's already loaded into memory
rm -f "$SCRIPT_PATH"
echo "  - init_repo.sh gelöscht"
echo "Repository-Initialisierung abgeschlossen."