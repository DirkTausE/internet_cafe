#!/usr/bin/env bash
# install_client.sh
# Interactive installer for the Internetcafe Test Client (clientd).
# - installs python3, venv, jq
# - creates /opt/clientd, python virtualenv and installs Flask
# - installs sample HTTP agent and set_state action
# - creates systemd service to run the agent on port 9999
#
# Usage:
#   sudo ./install_client.sh
#
# Notes:
# - This is a simple test agent. For production, run the app under a dedicated user,
#   secure the filesystem, and use TLS/reverse-proxy.

set -euo pipefail

# Helpers
echoinfo(){ printf '\e[1;34m[INFO]\e[0m %s\n' "$*"; }
echoerr(){ printf '\e[1;31m[ERR]\e[0m %s\n' "$*"; }

if [ "$EUID" -ne 0 ]; then
  echoerr "Please run as root (sudo)."
  exit 1
fi

# Defaults & prompts
read -rp "Install directory [/opt/clientd]: " INSTALL_DIR
INSTALL_DIR=${INSTALL_DIR:-/opt/clientd}
read -rp "Agent port [9999]: " AGENT_PORT
AGENT_PORT=${AGENT_PORT:-9999}
read -rp "Service user [clientd] (will be created if missing): " SERVICE_USER
SERVICE_USER=${SERVICE_USER:-clientd}

# Create service user if missing
if ! id -u "${SERVICE_USER}" >/dev/null 2>&1; then
  echoinfo "Creating system user ${SERVICE_USER}..."
  useradd --system --home-dir "${INSTALL_DIR}" --shell /usr/sbin/nologin "${SERVICE_USER}" || true
fi

# Install packages (Debian/Ubuntu)
apt-get update -y
apt-get install -y python3 python3-venv python3-pip jq curl git

# Create directories and set ownership
mkdir -p "${INSTALL_DIR}"
mkdir -p "${INSTALL_DIR}/actions"
chown -R "${SERVICE_USER}":"${SERVICE_USER}" "${INSTALL_DIR}"
chmod 0755 "${INSTALL_DIR}"

# Create python virtualenv and install Flask
echoinfo "Creating python virtualenv and installing Flask..."
python3 -m venv "${INSTALL_DIR}/venv"
# Ensure pip is up to date
"${INSTALL_DIR}/venv/bin/pip" install --upgrade pip setuptools
"${INSTALL_DIR}/venv/bin/pip" install flask

# Create agent app (clientd.py)
cat > "${INSTALL_DIR}/clientd.py" <<PY
from flask import Flask, request, jsonify
import os, subprocess, json, logging

app = Flask(__name__)
logging.basicConfig(level=logging.INFO)

CLIENTD_SECRET = os.getenv('CLIENTD_SECRET') or ''
ACTIONS_DIR = os.path.join(os.path.dirname(__file__), 'actions')
os.makedirs(ACTIONS_DIR, exist_ok=True)

def check_auth(req):
    h = req.headers.get('Authorization','')
    if h.lower().startswith('bearer '):
        token = h.split(None,1)[1]
        return token == CLIENTD_SECRET
    return False

@app.route('/action', methods=['POST'])
def action():
    if not check_auth(request):
        return jsonify(ok=False, error='forbidden'), 403
    try:
        payload = request.get_json(force=True)
    except Exception as e:
        return jsonify(ok=False, error='invalid json', detail=str(e)), 400

    action = payload.get('action')
    if not action:
        return jsonify(ok=False, error='missing action'), 400

    script_path = os.path.join(ACTIONS_DIR, action + '.sh')
    if not os.path.isfile(script_path) or not os.access(script_path, os.X_OK):
        return jsonify(ok=False, error='action not found'), 404

    try:
        proc = subprocess.run([script_path], input=json.dumps(payload).encode('utf-8'),
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
        return jsonify(ok=True, rc=proc.returncode, stdout=proc.stdout.decode('utf-8'), stderr=proc.stderr.decode('utf-8')), 200
    except Exception as e:
        return jsonify(ok=False, error='exec failed', detail=str(e)), 500

if __name__ == "__main__":
    app.run(host='0.0.0.0', port=${AGENT_PORT})
PY

chown "${SERVICE_USER}":"${SERVICE_USER}" "${INSTALL_DIR}/clientd.py"
chmod 750 "${INSTALL_DIR}/clientd.py"

# Create sample action set_state.sh (safe, logs payload)
cat > "${INSTALL_DIR}/actions/set_state.sh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
TMPDIR="/tmp/clientd-demo"
mkdir -p "$TMPDIR"
PAYLOAD_FILE="$TMPDIR/last-set-state.json"
payload="$(cat -)"
echo "$payload" > "$PAYLOAD_FILE"
chmod 600 "$PAYLOAD_FILE"
# also persist a simple state file
STATE_FILE="/var/lib/clientd/state.json"
mkdir -p "$(dirname "$STATE_FILE")"
# attempt to use jq if available for pretty JSON, else write raw
if command -v jq >/dev/null 2>&1; then
  echo "$payload" | jq '.' > "${STATE_FILE}.tmp"
else
  echo "$payload" > "${STATE_FILE}.tmp"
fi
mv -f "${STATE_FILE}.tmp" "${STATE_FILE}" || true
chmod 600 "${STATE_FILE}" || true
echo "OK"
exit 0
SH

chown -R "${SERVICE_USER}":"${SERVICE_USER}" "${INSTALL_DIR}/actions"
chmod 750 "${INSTALL_DIR}/actions/set_state.sh"

# Create systemd service file
read -rp "Create CLIENTD_SECRET automatically? [Y/n]: " CREATE_CLIENTD_SECRET
CREATE_CLIENTD_SECRET=${CREATE_CLIENTD_SECRET:-Y}
CLIENTD_SECRET=""
if [[ "${CREATE_CLIENTD_SECRET^^}" == "Y" || "${CREATE_CLIENTD_SECRET^^}" == "YES" ]]; then
  CLIENTD_SECRET=$(openssl rand -base64 24 || head -c 32 /dev/urandom | base64)
  echoinfo "Generated CLIENTD_SECRET."
  # write to /etc/internetcafe-clientd.conf for server to read (if not already)
  if [ ! -f /etc/internetcafe-clientd.conf ]; then
    cat > /etc/internetcafe-clientd.conf <<EOF
CLIENTD_SECRET="${CLIENTD_SECRET}"
# Add API_KEY for client daemon to authenticate with server
# API_KEY="your-server-api-key-here"
EOF
    chmod 600 /etc/internetcafe-clientd.conf
    echoinfo "Wrote /etc/internetcafe-clientd.conf (mode 600)."
  else
    echoinfo "/etc/internetcafe-clientd.conf already exists; leaving it unchanged."
  fi
else
  read -rp "Enter desired CLIENTD_SECRET: " CLIENTD_SECRET
fi

SERVICE_FILE="/etc/systemd/system/clientd.service"
cat > "${SERVICE_FILE}" <<EOF
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
Environment=CLIENTD_SECRET=${CLIENTD_SECRET}
# Load additional environment variables from config file
EnvironmentFile=-/etc/internetcafe-clientd.conf

[Install]
WantedBy=multi-user.target
EOF

chmod 644 "${SERVICE_FILE}"
systemctl daemon-reload
systemctl enable --now clientd.service

echoinfo "Clientd service started (systemd unit: clientd.service)."
echoinfo "Agent listening on port ${AGENT_PORT} (http)."
echoinfo "Actions directory: ${INSTALL_DIR}/actions"
echoinfo "Sample action: set_state writes to /tmp/clientd-demo/last-set-state.json and /var/lib/clientd/state.json"
echoinfo "Client secret (store safely):"
echo "${CLIENTD_SECRET}"
echoinfo "Test with curl from server:"
echoinfo "curl -v -H \"Authorization: Bearer ${CLIENTD_SECRET}\" -H \"Content-Type: application/json\" -d '{\"action\":\"set_state\",\"state\":\"wartung\"}' http://<client-ip>:${AGENT_PORT}/action"

# fix permissions for /var/lib/clientd (ensure service user can write)
mkdir -p /var/lib/clientd
chown -R "${SERVICE_USER}":"${SERVICE_USER}" /var/lib/clientd
chmod 700 /var/lib/clientd

echoinfo "Installation complete."