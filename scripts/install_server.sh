#!/usr/bin/env bash
# install_server.sh
# Interactive installer for the Internetcafe Server (test/dev setup).
# - installs required packages (PHP, MySQL client/server, jq, curl)
# - creates installation directories
# - creates database + DB user (optionally imports init SQL)
# - creates API and clientd secret files in /etc
# - optionally installs sample API files (if selected)
# - creates a systemd service that runs the PHP built-in server for testing
#
# Usage:
#   sudo ./install_server.sh
#
# Notes:
# - This installer is intended for quick test setups (Debian/Ubuntu).
# - For production use, adjust webserver (nginx/php-fpm), TLS, and user permissions.

set -euo pipefail

# Helpers
echoinfo(){ printf '\e[1;34m[INFO]\e[0m %s\n' "$*"; }
echowarn(){ printf '\e[1;33m[WARN]\e[0m %s\n' "$*"; }
echoerr(){ printf '\e[1;31m[ERR]\e[0m %s\n' "$*"; }

# ensure running as root (for installing packages & systemd)
if [ "$EUID" -ne 0 ]; then
  echoerr "Please run this script with sudo/root:"
  echoerr "  sudo $0"
  exit 1
fi

# Defaults & prompts
read -rp "Install directory (web root) [/opt/internetcafe]: " INSTALL_DIR
INSTALL_DIR=${INSTALL_DIR:-/opt/internetcafe}
read -rp "Web subdirectory relative to install dir [web]: " WEB_SUBDIR
WEB_SUBDIR=${WEB_SUBDIR:-web}
WEB_DIR="${INSTALL_DIR%/}/${WEB_SUBDIR}"

read -rp "Database name [internetcafe]: " DB_NAME
DB_NAME=${DB_NAME:-internetcafe}
read -rp "Database app user [internetcafe]: " DB_USER
DB_USER=${DB_USER:-internetcafe}

read -rp "Create a new DB user '${DB_USER}'? [Y/n]: " CREATE_DBUSER_ANS
CREATE_DBUSER_ANS=${CREATE_DBUSER_ANS:-Y}

# Decide how to run mysql commands: prefer socket auth via sudo mysql if available
MYSQL_CMD="mysql"
if command -v mysql >/dev/null 2>&1; then
  # test sudo mysql (socket-based) first
  if sudo mysql -e "SELECT 1;" >/dev/null 2>&1; then
    MYSQL_CMD="sudo mysql"
  else
    # fallback to interactive mysql -u root -p
    MYSQL_CMD="mysql -u root -p"
  fi
else
  echoerr "mysql client not found. Aborting."
  exit 1
fi

echoinfo "Using MYSQL_CMD: ${MYSQL_CMD}"

# Update & install packages
echoinfo "Updating APT and installing required packages..."
apt-get update -y
apt-get install -y php8.3 php8.3-cli php8.3-mbstring php8.3-curl php8.3-mysql \
  mysql-client mysql-server curl git jq unzip

# Create directories
echoinfo "Creating directories: ${WEB_DIR}"
mkdir -p "${WEB_DIR}"
chown -R root:root "${INSTALL_DIR}"
chmod -R 0755 "${INSTALL_DIR}"

# Optionally copy sample API files into WEB_DIR
read -rp "Install sample API files into ${WEB_DIR}? [Y/n]: " INSTALL_SAMPLES
INSTALL_SAMPLES=${INSTALL_SAMPLES:-Y}
if [[ "${INSTALL_SAMPLES^^}" == "Y" || "${INSTALL_SAMPLES^^}" == "YES" ]]; then
  echoinfo "Installing sample API (web/api/computers.php) and a minimal index.php"
  mkdir -p "${WEB_DIR}/api"
  cat > "${WEB_DIR}/api/computers.php" <<'PHP'
<?php
// Minimal placeholder for your API. Replace with full implementation.
// Return a simple JSON hello for health checks.
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'msg'=>'internetcafe API placeholder']);
PHP

  cat > "${WEB_DIR}/index.php" <<'PHP'
<?php
echo "<h1>Internetcafe Test Server</h1>\n";
echo "<p>API: /api/computers.php</p>\n";
PHP

  chmod 644 "${WEB_DIR}/api/computers.php" "${WEB_DIR}/index.php"
fi

# Database creation
echoinfo "Creating database '${DB_NAME}' if it does not exist..."
$MYSQL_CMD -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Create DB user & password (if requested)
DB_PASS=""
if [[ "${CREATE_DBUSER_ANS^^}" == "Y" || "${CREATE_DBUSER_ANS^^}" == "YES" ]]; then
  # generate a password
  DB_PASS=$(openssl rand -base64 18 || head -c 24 /dev/urandom | base64)
  echoinfo "Creating DB user '${DB_USER}'@'127.0.0.1' with a generated password."
  # Use 127.0.0.1 host to avoid socket auth confusion; also create for localhost for convenience
  SQL="CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;"
  echo "${SQL}" | $MYSQL_CMD
  echoinfo "DB user created. Username: ${DB_USER}"
fi

# Optionally import init SQL if present
DEFAULT_INIT_SQL="${INSTALL_DIR%/}/init_internetcafe.sql"
read -rp "Import initial schema from ${DEFAULT_INIT_SQL} if present? [Y/n]: " IMPORT_SQL_ANS
IMPORT_SQL_ANS=${IMPORT_SQL_ANS:-Y}
if [[ "${IMPORT_SQL_ANS^^}" == "Y" || "${IMPORT_SQL_ANS^^}" == "YES" ]] && [ -f "${DEFAULT_INIT_SQL}" ]; then
  echoinfo "Importing ${DEFAULT_INIT_SQL} into ${DB_NAME}..."
  if [[ "${MYSQL_CMD}" == "sudo mysql" ]]; then
    sudo sh -c "mysql '${DB_NAME}' < '${DEFAULT_INIT_SQL}'"
  else
    mysql -u root -p "${DB_NAME}" < "${DEFAULT_INIT_SQL}"
  fi
  echoinfo "Import finished."
else
  echowarn "Skipping SQL import (file not found or user chose not to import)."
fi

# Create API and CLIENTD secret files in /etc
read -rp "Create /etc/internetcafe-api.conf and /etc/internetcafe-clientd.conf with generated secrets? [Y/n]: " CREATE_SECRETS
CREATE_SECRETS=${CREATE_SECRETS:-Y}
API_SECRET=""
CLIENTD_SECRET=""
if [[ "${CREATE_SECRETS^^}" == "Y" || "${CREATE_SECRETS^^}" == "YES" ]]; then
  API_SECRET=$(openssl rand -base64 24 || head -c 32 /dev/urandom | base64)
  CLIENTD_SECRET=$(openssl rand -base64 24 || head -c 32 /dev/urandom | base64)
  cat > /etc/internetcafe-api.conf <<EOF
API_SECRET="${API_SECRET}"
EOF
  chmod 600 /etc/internetcafe-api.conf

  cat > /etc/internetcafe-clientd.conf <<EOF
CLIENTD_SECRET="${CLIENTD_SECRET}"
EOF
  chmod 600 /etc/internetcafe-clientd.conf

  echoinfo "Secrets written to /etc/internetcafe-api.conf and /etc/internetcafe-clientd.conf (mode 600)."
else
  echowarn "Skipping secrets file creation. You'll need to set API_SECRET and CLIENTD_SECRET later."
fi

# Create systemd service for test PHP built-in server
read -rp "Create systemd service to run php -S as test server on port 8080? [Y/n]: " CREATE_SVC
CREATE_SVC=${CREATE_SVC:-Y}
SERVICE_NAME="internetcafe-web.service"
if [[ "${CREATE_SVC^^}" == "Y" || "${CREATE_SVC^^}" == "YES" ]]; then
  # pick a user for the service (www-data is common). Ensure web dir accessible.
  read -rp "Service user [www-data]: " SERVICE_USER
  SERVICE_USER=${SERVICE_USER:-www-data}
  # create a systemd service
  cat > /etc/systemd/system/${SERVICE_NAME} <<EOF
[Unit]
Description=Internetcafe PHP Test Server
After=network.target

[Service]
Type=simple
WorkingDirectory=${WEB_DIR}
ExecStart=/usr/bin/php -S 0.0.0.0:8080 -t ${WEB_DIR}
Restart=on-failure
# read secrets from /etc files if present
EnvironmentFile=-/etc/internetcafe-api.conf
EnvironmentFile=-/etc/internetcafe-clientd.conf
User=${SERVICE_USER}
Group=${SERVICE_USER}

[Install]
WantedBy=multi-user.target
EOF

  chmod 644 /etc/systemd/system/${SERVICE_NAME}
  systemctl daemon-reload
  systemctl enable --now ${SERVICE_NAME}
  echoinfo "Started and enabled ${SERVICE_NAME} (php -S serving ${WEB_DIR} on port 8080)."
  echoinfo "Check logs with: sudo journalctl -u ${SERVICE_NAME} -f"
else
  echowarn "Skipping systemd service creation. You can run a dev server manually with:\n  php -S 0.0.0.0:8080 -t ${WEB_DIR}"
fi

# Write a small .my.cnf for the app user if DB user was created and a non-root interactive user exists
APP_MYCNF_PATH=""
if [ -n "${DB_PASS}" ]; then
  # determine non-root user to own files (SUDO_USER or prompt)
  if [ -n "${SUDO_USER:-}" ]; then
    APP_USER="${SUDO_USER}"
  else
    read -rp "System user that will run the app (for .my.cnf owner) [www-data]: " APP_USER
    APP_USER=${APP_USER:-www-data}
  fi

  APP_MYCNF_PATH="/home/${APP_USER}/.my.cnf"
  # if home doesn't exist (e.g. www-data), use /etc/internetcafe-db.conf
  if [ ! -d "/home/${APP_USER}" ]; then
    APP_MYCNF_PATH="/etc/internetcafe-db.conf"
  fi

  echoinfo "Writing DB client defaults to ${APP_MYCNF_PATH} (mode 600)"
  cat > "${APP_MYCNF_PATH}" <<EOF
[client]
user=${DB_USER}
password="${DB_PASS}"
host=127.0.0.1
database=${DB_NAME}
EOF
  chmod 600 "${APP_MYCNF_PATH}"
  # set owner if file is in a user's home
  if [ -d "/home/${APP_USER}" ]; then
    chown "${APP_USER}:${APP_USER}" "${APP_MYCNF_PATH}"
  fi
fi

# Final summary
echo
echoinfo "Installation finished."
echo "Web directory: ${WEB_DIR}"
echo "Database: ${DB_NAME}"
if [ -n "${DB_PASS}" ]; then
  echo "DB user: ${DB_USER}"
  echo "DB password: ${DB_PASS}"
  echo "DB client defaults file: ${APP_MYCNF_PATH}"
else
  echowarn "No DB user created. Use mysql client to create users or import schema manually."
fi
if [ -n "${API_SECRET}" ]; then
  echo "API secret file: /etc/internetcafe-api.conf"
fi
if [ -n "${CLIENTD_SECRET}" ]; then
  echo "CLIENTD secret file: /etc/internetcafe-clientd.conf"
fi
echo
echoinfo "Test the API (example):"
echo "curl -H \"Authorization: Bearer ${API_SECRET:-<API_SECRET>}\" -H \"Content-Type: application/json\" -X POST http://127.0.0.1:8080/api/computers/1/action -d '{\"action\":\"set_state\",\"state\":\"wartung\"}'"
echoinfo "If you installed sample files, open http://<server-ip>:8080/ to see the index page."
echoinfo "Keep the secrets safe. /etc files are mode 600."