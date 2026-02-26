#!/usr/bin/env bash
# Smoke tests für server installation (lokal)
set -euo pipefail

DB_NAME="${DB_NAME:-internetcafe}"
WEB_DEST="${WEB_DEST:-/var/www/internetcafe}"

die() { echo "[FAIL] $*" >&2; exit 2; }
ok() { echo "[OK] $*"; }

echo "1) Prüfe, dass MySQL läuft..."
systemctl is-active --quiet mysql || die "MySQL Service nicht aktiv"

echo "2) Prüfe DB‑Verbindung..."
mysql -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB_NAME}';" \
  | grep -q "${DB_NAME}" || die "Datenbank ${DB_NAME} fehlt"

echo "3) Prüfe Tabellenanzahl > 0..."
tbl_count=$(mysql -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';")
[ "${tbl_count}" -gt 0 ] || die "Keine Tabellen in ${DB_NAME} gefunden"

echo "4) Prüfe Website‑Dateien..."
[ -d "${WEB_DEST}" ] || die "Webverzeichnis ${WEB_DEST} nicht gefunden"
[ -f "${WEB_DEST}/index.php" ] || echo "[WARN] index.php nicht gefunden in ${WEB_DEST}"

echo "5) Prüfe Webserver (HTTP 200 auf localhost)..."
if command -v curl >/dev/null 2>&1; then
  http_status=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/ || true)
  if [ "${http_status}" != "200" ] && [ "${http_status}" != "302" ]; then
    echo "[WARN] HTTP Status ${http_status} (erwartet 200/302). Webserver möglicherweise nicht konfiguriert."
  else
    ok "HTTP ${http_status}"
  fi
else
  echo "[WARN] curl nicht installiert — überspringe HTTP Check"
fi

ok "Smoke tests bestanden (oder Warnungen ausgegeben)."