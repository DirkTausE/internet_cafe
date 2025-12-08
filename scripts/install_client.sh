#!/usr/bin/env bash
# scripts/install_client.sh
# Installer that uses /opt/clientd and creates system user 'clientd', writes /etc/internetcafe-clientd.conf
# and installs a systemd unit that references /opt/clientd paths.

set -euo pipefail

INSTALL_DIR="${INSTALL_DIR:-/opt/clientd}"
SERVICE_USER="${SERVICE_USER:-clientd}"
AGENT_PORT="${AGENT_PORT:-9999}"
SERVICE_NAME="clientd.service"
CONF_PATH="/etc/internetcafe-clientd.conf"
UNIT_PATH="/etc/systemd/system/${SERVICE_NAME}"

echo "[INFO] This installer will: create user ${SERVICE_USER}, create ${INSTALL_DIR}, install venv and files, optionally write ${CONF_PATH} and enable systemd unit."

if [ "$(id -u)" -ne 0 ]; then
  echo "Please run as root (sudo)." >&2
  exit 1
fi

# create system user if not exists
if ! id -u "${SERVICE_USER}" >/dev/null 2>&1; then
  useradd --system --home-dir "${INSTALL_DIR}" --shell /usr/sbin/nologin "${SERVICE_USER}" || true
fi

apt-get update -y
apt-get install -y python3 python3-venv python3-pip jq curl

mkdir -p "${INSTALL_DIR}"
mkdir -p "${INSTALL_DIR}/actions"
chown -R "${SERVICE_USER}:${SERVICE_USER}" "${INSTALL_DIR}"
chmod 0755 "${INSTALL_DIR}"

# create venv and install minimal deps
python3 -m venv "${INSTALL_DIR}/venv"
"${INSTALL_DIR}/venv/bin/pip" install --upgrade pip setuptools
"${INSTALL_DIR}/venv/bin/pip" install flask requests

# install example clientd.py if not present (do not overwrite existing)
if [ ! -f "${INSTALL_DIR}/clientd.py" ]; then
  cat > "${INSTALL_DIR}/clientd.py" <<'PY'
# Minimal placeholder; replace with repository clientd.py content.
print("Placeholder clientd - replace with repo clientd.py")
PY
  chown "${SERVICE_USER}:${SERVICE_USER}" "${INSTALL_DIR}/clientd.py"
  chmod 750 "${INSTALL_DIR}/clientd.py"
fi

# sample action
cat > "${INSTALL_DIR}/actions/set_state.sh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
TMPDIR="/tmp/clientd-demo"
mkdir -p "$TMPDIR"
payload="$(cat -)"
echo "$payload" > "$TMPDIR/last-set-state.json"
chmod 600 "$TMPDIR/last-set-state.json"
# persist a simple state
STATE_FILE="/var/lib/clientd/state.json"
mkdir -p "$(dirname "$STATE_FILE")"
echo "$payload" > "${STATE_FILE}.tmp" || true
mv -f "${STATE_FILE}.tmp" "${STATE_FILE}" || true
chmod 600 "${STATE_FILE}" || true
echo "OK"
exit 0
SH

chown -R "${SERVICE_USER}:${SERVICE_USER}" "${INSTALL_DIR}/actions"
chmod 750 "${INSTALL_DIR}/actions/set_state.sh"

# Offer to create /etc config with generated secret (not committed)
read -rp "Create /etc/internetcafe-clientd.conf with generated CLIENTD_SECRET and API_KEY? [Y/n]: " ans
ans=${ans:-Y}
if [[ "${ans^^}" == "Y" || "${ans^^}" == "YES" ]]; then
  CLIENTD_SECRET=$(openssl rand -base64 24 2>/dev/null || head -c 24 /dev/urandom | base64)
  API_KEY=$(openssl rand -base64 24 2>/dev/null || head -c 24 /dev/urandom | base64)
  cat > "${CONF_PATH}" <<EOF
# /etc/internetcafe-clientd.conf
CLIENTD_SECRET="${CLIENTD_SECRET}"
API_KEY="${API_KEY}"
EOF
  chmod 600 "${CONF_PATH}"
  echo "[INFO] Wrote ${CONF_PATH} (mode 600)."
else
  echo "[INFO] Skipping writing ${CONF_PATH}. Remember to supply CLIENTD_SECRET and API_KEY via env or /etc file."
fi

# create systemd unit
cat > "${UNIT_PATH}" <<EOF
[Unit]
Description=Internetcafe test client agent
After=network.target

[Service]
Type=simple
User=${SERVICE_USER}
Group=${SERVICE_USER}
WorkingDirectory=${INSTALL_DIR}
ExecStart=${INSTALL_DIR}/venv/bin/python ${INSTALL_DIR}/clientd.py
Restart=on-failure
EnvironmentFile=-${CONF_PATH}

[Install]
WantedBy=multi-user.target
EOF

chmod 644 "${UNIT_PATH}"
systemctl daemon-reload
systemctl enable --now "${SERVICE_NAME}"

# ensure /var/lib/clientd ownership
mkdir -p /var/lib/clientd
chown -R "${SERVICE_USER}:${SERVICE_USER}" /var/lib/clientd
chmod 700 /var/lib/clientd

echo "[INFO] install_client.sh finished. Service: ${SERVICE_NAME}. Config: ${CONF_PATH}. Install dir: ${INSTALL_DIR}."
