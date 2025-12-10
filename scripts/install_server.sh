#!/usr/bin/env bash
# Installationsskript für Server-Komponenten (Debian/Ubuntu - smoke-install)
# Anforderungen: root, Internet, PHP8+, MySQL8+
set -euo pipefail
IFS=$'\n\t'

# Konfigurierbare Variablen (können per ENV überschrieben werden)
DB_NAME="${DB_NAME:-internetcafe}"
DB_USER="${DB_USER:-internetcafe_user}"
DB_PASS="${DB_PASS:-internetcafe_pass}"   # In Produktion: sicher setzen
WEB_DEST="${WEB_DEST:-/var/www/internetcafe}"
SQL_CANDIDATES=("db/schema_ext.sql" "db/schema.sql")

# Export damit apt (und andere Werkzeuge) noninteractive lesen — vermeidet SC2034
export DEBIAN_FRONTEND=noninteractive

log() { echo -e "[INFO] $*"; }
err() { echo -e "[ERROR] $*" >&2; }

require_root() {
  if [ "$(id -u)" -ne 0 ]; then
    err "Dieses Skript muss als root ausgeführt werden."
    exit 1
  fi
}

# detect_distro: parse /etc/os-release instead of sourcing it to avoid ShellCheck SC1091
detect_distro() {
  if [ -r /etc/os-release ]; then
    DISTRO=$(grep -E '^ID=' /etc/os-release | head -n1 | cut -d= -f2- | tr -d '"' | tr '[:upper:]' '[:lower:]' || true)
    VERSION=$(grep -E '^VERSION_ID=' /etc/os-release | head -n1 | cut -d= -f2- | tr -d '"' || true)
    DISTRO=${DISTRO:-unknown}
    VERSION=${VERSION:-unknown}
  else
    DISTRO="unknown"
    VERSION="unknown"
  fi
}

apt_install_safe() {
  log "apt update..."
  apt-get update -y
  log "Installing packages: $*"
  apt-get install -y --no-install-recommends "$@"
}

install_mysql_debian() {
  log "Installiere MySQL (Debian/Ubuntu)..."
  apt_install_safe mysql-server
  systemctl enable --now mysql || true
  sleep 2
  if ! systemctl is-active --quiet mysql; then
    err "MySQL scheint nicht zu laufen. Prüfe 'systemctl status mysql'."
    return 1
  fi
  log "MySQL installiert und läuft."
}

find_sql_file() {
  for f in "${SQL_CANDIDATES[@]}"; do
    if [ -f "$f" ]; then
      echo "$f"
      return 0
    fi
  done
  return 1
}

create_db_and_user() {
  local sql_create
  sql_create=$(
cat <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
)
  log "Erstelle Datenbank und Nutzer (falls noch nicht vorhanden)..."
  if ! printf '%s\n' "$sql_create" | mysql; then
    err "DB-Erstellung als aktueller User fehlgeschlagen, versuche als root..."
    printf '%s\n' "$sql_create" | sudo mysql
  fi
  log "DB und Nutzer vorbereitet."
}

import_schema() {
  local sqlfile="$1"
  log "Importiere Schema aus $sqlfile ..."
  # Redirect mit sudo korrekt ausführen: sudo bash -c "mysql DB < file"
  if mysql "${DB_NAME}" < "$sqlfile"; then
    log "Schema in ${DB_NAME} importiert."
  else
    err "Import als aktueller User fehlgeschlagen. Versuche als root..."
    sudo bash -c "mysql '${DB_NAME}' < '${sqlfile}'"
  fi
}

install_website_files() {
  log "Installiere Website-Dateien nach ${WEB_DEST} ..."
  mkdir -p "$WEB_DEST"
  if [ -d "web" ]; then
    rsync -a --delete web/ "$WEB_DEST/"
  else
    err "Verzeichnis 'web' fehlt im Projekt. Abbruch."
    exit 1
  fi
  chown -R www-data:www-data "$WEB_DEST" || true
  find "$WEB_DEST" -type d -exec chmod 755 {} \;
  find "$WEB_DEST" -type f -exec chmod 644 {} \;
  log "Website-Dateien installiert."
}

restart_webserver_if_exists() {
  if command -v apache2ctl >/dev/null 2>&1; then
    log "Restart Apache2..."
    systemctl enable --now apache2 || true
    systemctl restart apache2 || true
  fi
  if command -v nginx >/dev/null 2>&1; then
    log "Restart Nginx..."
    systemctl enable --now nginx || true
    systemctl restart nginx || true
  fi
}

validate_install() {
  log "Validierung der Installation..."
  if ! mysql -e "SELECT 1;" >/dev/null 2>&1; then
    err "Keine Verbindung zu MySQL möglich."
    return 1
  fi

  local tbl_count
  tbl_count=$(mysql -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';" || echo "0")
  if [ -z "$tbl_count" ] || [ "$tbl_count" -eq 0 ]; then
    err "Keine Tabellen in ${DB_NAME} gefunden (Schema eventuell nicht importiert)."
    return 2
  fi
  log "DB '${DB_NAME}' enthält ${tbl_count} Tabellen."

  if [ -d "${WEB_DEST}" ]; then
    log "Webverzeichnis ${WEB_DEST} vorhanden."
  else
    err "Webverzeichnis ${WEB_DEST} fehlt."
    return 3
  fi

  if command -v php >/dev/null 2>&1; then
    php -v | head -n1
  else
    err "PHP nicht installiert oder in PATH."
    return 4
  fi

  log "Validierung erfolgreich."
  return 0
}

main() {
  require_root
  detect_distro
  log "Detected distro: ${DISTRO} ${VERSION}"

  if [[ "${DISTRO}" == "ubuntu" || "${DISTRO}" == "debian" ]]; then
    install_mysql_debian
    apt_install_safe php php-mysql unzip rsync curl
    apt_install_safe apache2 || true
  else
    err "Dieses Skript ist primär für Debian/Ubuntu. Für andere Distros bitte manuell anpassen."
  fi

  local sqlfile
  sqlfile="$(find_sql_file || true)"
  if [ -z "${sqlfile}" ]; then
    err "Keine SQL‑Schema Datei gefunden. Erwartet eine der: ${SQL_CANDIDATES[*]}"
    exit 1
  fi
  log "Gefundenes SQL: ${sqlfile}"

  create_db_and_user
  import_schema "$sqlfile"

  install_website_files
  restart_webserver_if_exists

  if validate_install; then
    log "Installation abgeschlossen: Website unter ${WEB_DEST} bereit, DB ${DB_NAME} importiert."
    echo
    log "Wichtige Hinweise:"
    log " - DB user: ${DB_USER}"
    log " - DB pass: ${DB_PASS}"
    log "Ändere DB‑Passwort und sichere Zugangsdaten in einer produktiven Umgebung!"
    exit 0
  else
    err "Validierung ergab Fehler. Bitte Logs prüfen."
    exit 1
  fi
}

main "$@"