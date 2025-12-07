# Installation — Server (Internetcafe API)

Ziel: schnelle, reproduzierbare Anleitung zum Aufsetzen des Servers für Tests (lokal / Test‑VM).  
Diese Anleitung ist für Testzwecke ausgelegt (schnell startbar). Für Produktion siehe Hinweise am Ende.

Vorbedingungen (Test-Umgebung)
- Debian/Ubuntu (20.04/22.04/24.04) oder ähnliche Linux‑Distribution
- root / sudo‑Zugriff
- MySQL / MariaDB installiert
- PHP 8.3 CLI + mbstring (wir haben php8.3 verwendet)
- Git (optional)

Kurzübersicht der Schritte
1. Systempakete installieren
2. Repository/Code platzieren
3. Datenbank anlegen & Schema importieren
4. API‑Secret & Clientd‑Secret konfigurieren
5. Webserver (Testmodus) starten
6. Smoke‑Tests und Prüflisten

1) Systempakete (Debian/Ubuntu)
sudo apt update
sudo apt install -y php8.3 php8.3-cli php8.3-mbstring php8.3-pdo-mysql mysql-client mysql-server curl git unzip

Anmerkung:
- Für produktiven Betrieb empfehle nginx + php8.3-fpm und apt install php8.3-fpm.

2) Code‑Layout (angenommen: /opt/internetcafe)
sudo mkdir -p /opt/internetcafe
sudo chown $(whoami):$(whoami) /opt/internetcafe
cd /opt/internetcafe

Kopiere/lege dort die Web‑Dateien ab (z. B. aus deinem Git‑Repo). Wichtige Pfade, die in dieser Doku vorkommen:
- API: web/api/computers.php
- Web‑Root (Testserver): web/
- SQL: init_internetcafe.sql, add_state_to_computers.sql
- DB client‑defaults: /home/surfer/.my.cnf oder /etc/internetcafe-db.conf (optional)

3) Datenbank initialisieren
- MySQL root (interaktiv):
sudo mysql -e "CREATE DATABASE IF NOT EXISTS internetcafe CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

- Schema + Demo‑Daten (wenn noch leer):
# als root/mySQL‑Admin
mysql -u root -p internetcafe < /opt/internetcafe/init_internetcafe.sql

- Falls du nur die state‑Spalte brauchst (falls nicht schon vorhanden), siehe:
mysql -u root -p < /opt/internetcafe/add_state_to_computers.sql

Prüfen:
mysql -u root -p -D internetcafe -e "SELECT id,hostname,name,current_state,state FROM computers LIMIT 20\G"

4) Secrets & Konfiguration
- API secret (Server auth):
Erzeuge eine Datei /etc/internetcafe-api.conf (root) mit Inhalt:
API_SECRET="REPLACE_WITH_STRONG_SECRET"

sudo tee /etc/internetcafe-api.conf >/dev/null <<'EOF'
API_SECRET="mein-sehr-starkes-secret-ersetzt-denn"
EOF
sudo chmod 600 /etc/internetcafe-api.conf

- Clientd secret (für Weiterleitung an Clients):
sudo tee /etc/internetcafe-clientd.conf >/dev/null <<'EOF'
CLIENTD_SECRET="client-secret-ändert-werden"
EOF
sudo chmod 600 /etc/internetcafe-clientd.conf

Hinweis: Alternativ exportiere `API_SECRET` und `CLIENTD_SECRET` in der Webserver‑Umgebung (z. B. systemd unit, PHP-FPM pool env).

5) Starten des Test‑Webservers (schnell)
Für Tests empfiehlt sich der PHP Built‑in Server (nur Test/Dev).

cd /opt/internetcafe/web
# Starten auf Port 8080 im Hintergrund
nohup php -S 0.0.0.0:8080 -t . >/var/log/internetcafe-php.log 2>&1 & disown

Prüfen:
# API jetzt testen (lokal)
curl -i -H "Authorization: Bearer mein-sehr-starkes-secret-ersetzt-denn" -H "Content-Type: application/json" \
  -X POST http://127.0.0.1:8080/api/computers/1/action \
  -d '{"action":"set_state","state":"wartung","occupied":"0"}'

Erwartetes JSON: { "ok": true, "updated_db": true, ... }

6) Systemd (optional, stabiler Testbetrieb)
Wenn du möchtest, erstelle einen systemd Service (beispiel für Testserver mit php -S):
/etc/systemd/system/internetcafe-web.service
[Unit]
Description=Internetcafe PHP Test Server
After=network.target

[Service]
Type=simple
WorkingDirectory=/opt/internetcafe/web
ExecStart=/usr/bin/php -S 0.0.0.0:8080 -t .
Restart=on-failure
Environment=API_SECRET=mein-sehr-starkes-secret-ersetzt-denn
Environment=CLIENTD_SECRET=client-secret-ändert-werden
User=www-data
Group=www-data

# dann:
sudo systemctl daemon-reload
sudo systemctl enable --now internetcafe-web.service
sudo journalctl -u internetcafe-web.service -f

7) Test‑Checkliste für morgen (Schnelltest)
- DB: SELECT COUNT(*) FROM computers;
- API Auth: curl mit korrektem Bearer token → 200
- API set_state: POST action set_state → DB spalte `state` ändert sich
- Forwarding: Falls Clients laufen, prüfen /var/lib/clientd/state.json auf Client
- Logs: tail -n 200 /var/log/internetcafe-php.log oder systemd journal

Troubleshooting (häufig)
- "Access denied" beim mysql: root per socket? Versuche sudo mysql -e "..."
- PHP: mbstring fehlt → sudo apt install php8.3-mbstring; restart fpm if used
- API 500 mit "server misconfigured: API secret not set" → set /etc/internetcafe-api.conf oder env var

Security & Production Hinweise (kurz)
- Verwende nginx + php‑fpm hinter TLS (HTTPS).
- Lagere secrets nicht in repo; verwende vault/oder environment in systemd/php-fpm.
- Setze DB‑User mit minimalen Rechten für die App (nicht root) und verwende /home/surfer/.my.cnf mit chmod 600.
- Absicherung der Client‑Forwarding‑Schnittstelle (CLIENTD_SECRET) notwendig.

Zusätzliche Dateien / SQL
- init_internetcafe.sql — Erstinitialisierung mit Tabellen + Demo
- add_state_to_computers.sh — Script zum Hinzufügen/Befüllen der state Spalte
- make_name_unique_and_notnull.sh — Script zur Bereinigung und Hinzufügen von UNIQUE/NOT NULL

Wenn du willst, bereite ich jetzt ein kurzes "One‑page Testskript" vor, das für morgen die wichtigsten Prüfungen automatisiert durchführt (DB checks, curl API, sample clientd request). Antworte: "Testskript" — ich lege es an.