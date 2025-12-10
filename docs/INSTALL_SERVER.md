# Installation des Internet Cafe Servers (Ubuntu MATE)

Dieses Dokument beschreibt die Schritte, um den Server für das Projekt "internet_cafe" auf Ubuntu MATE zu installieren und zu verifizieren. Es setzt voraus, dass du als Administrator (root oder sudo) arbeitest.

Wesentliche Punkte
- Zielsystem: Ubuntu MATE (Debian/Ubuntu Familie)
- Benötigt: root / sudo, Internetzugang
- Software: MySQL 8 (mysql-server), PHP 8.3.x, Apache2 (oder Nginx), rsync, curl

Vorbereitung
1. Repository auf dem Server auschecken (Pfad deiner Wahl, z. B. /opt/internetcafe):
   sudo apt update && sudo apt upgrade -y
   sudo apt install -y git
   sudo git clone <repo-url> /opt/internetcafe
   cd /opt/internetcafe

2. Prüfe Datei-Struktur:
   - web/ enthält die Website
   - db/schema_ext.sql oder db/schema.sql enthält das Datenbankschema
   - scripts/install_server.sh ist das Installationsskript
   - tests/install_server_test.sh ist der Smoke‑Test

Installation (automatisch)
1) Als root ausführen:
   sudo bash scripts/install_server.sh

Das Skript führt aus:
- Installation von mysql-server (MySQL 8) und grundlegenden PHP‑Packages
- Start/Enable von mysql
- Anlegen von DB + Benutzer (Konfigurierbar via ENV: DB_NAME, DB_USER, DB_PASS)
- Import des Schemas (sucht db/schema_ext.sql, fallback db/schema.sql)
- Kopie der Website (web/) nach /var/www/internetcafe
- Setzen der Zugriffsrechte (www-data)
- Optionale Neustarts für Apache/Nginx
- Validierungschecks (DB-Verbindung, Tabellenanzahl, Webverzeichnis, PHP verfügbar)

Wichtige Umgebungsvariablen (optional)
- DB_NAME (default: internetcafe)
- DB_USER (default: internetcafe_user)
- DB_PASS (default: internetcafe_pass)
- WEB_DEST (default: /var/www/internetcafe)

Empfohlen: Setze DB_PASS vor dem Ausführen:
export DB_PASS="ÄndereMichSicher!"

Manuelle Installation (falls du Komponenten lieber einzeln einrichtest)
1) MySQL installieren und starten:
   sudo apt update
   sudo apt install -y mysql-server
   sudo systemctl enable --now mysql

2) PHP + Webserver:
   sudo apt install -y php php-mysql apache2 rsync curl
   sudo systemctl enable --now apache2

3) DB + Benutzer anlegen (Beispiel):
   sudo mysql -e "CREATE DATABASE internetcafe CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   sudo mysql -e \"CREATE USER 'internetcafe_user'@'localhost' IDENTIFIED BY 'internetcafe_pass'; GRANT ALL ON internetcafe.* TO 'internetcafe_user'@'localhost'; FLUSH PRIVILEGES;\"

4) Schema importieren:
   sudo mysql internetcafe < db/schema_ext.sql

5) Website kopieren:
   sudo mkdir -p /var/www/internetcafe
   sudo rsync -a --delete web/ /var/www/internetcafe/
   sudo chown -R www-data:www-data /var/www/internetcafe

Validierung / Smoke Tests
- Starte die mitgelieferten Smoke Tests:
  sudo bash tests/install_server_test.sh

- Optionaler Browser‑Check:
  Kopiere web/test_db.php nach /var/www/internetcafe/test_db.php und rufe http://localhost/test_db.php auf.

Troubleshooting
- MySQL startet nicht
  - Prüfe Logs: sudo journalctl -u mysql -e
  - Prüfe freien Speicher/Ports

- Schema wird nicht importiert
  - Stelle sicher, dass db/schema_ext.sql oder db/schema.sql im Projekt vorhanden ist
  - Prüfe Dateienrechte (Lesen für root)

- Webserver zeigt Fehler
  - Prüfe Apache error.log: sudo tail -n 200 /var/log/apache2/error.log
  - Stelle sicher, dass PHP installiert und das php‑module aktiviert ist

Sicherheits‑Hinweise
- Ändere DB_PASS nach Installation auf ein sicheres Passwort
- In Produktionsumgebungen: benutze eine Secrets‑Management‑Lösung (Vault, environment files mit restriktiven Rechten)
- Entferne web/test_db.php nach erfolgreicher Prüfung

CI / Automatisierung (Empfehlung)
- Erzeuge einen GitHub Actions Job oder eine Docker‑Compose Testumgebung, die:
  - install_server.sh ausführt
  - tests/install_server_test.sh startet
  - Cleanup nach Tests durchführt
- So lassen sich zukünftige Änderungen automatisch validieren.

Änderungsprotokoll
- v1: Erstellt (Installer + Smoke Tests + Dokumentation) — Ziel: Ubuntu MATE (Debian/Ubuntu)

Wenn du möchtest, kann ich das Dokument noch anpassen (mehr Details zu Firewall/ufw, TLS/HTTPS, oder spezifische Apache‑VirtualHost‑Konfigurationen).