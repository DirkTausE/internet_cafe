# Installation — Clients (clientd) für Tests

Ziel: Einrichtung eines einfachen Client‑Agenten (HTTP Endpoint) auf Test‑PCs, der serverseitige Aktionen (z. B. set_state) entgegennimmt und lokal anwendet.

Design‑Entscheidung für Tests
- Leichter Python‑Flask Agent auf Port 9999 (einfach zu installieren, cross‑platform).
- Agent akzeptiert POST /action mit Bearer Token (CLIENTD_SECRET) und ruft Aktionsskripte aus `clients/actions/`.
- Beispiel‑Action: set_state (legt /var/lib/clientd/state.json an und /tmp/clientd-demo/last-set-state.json).

1) Voraussetzungen (Client)
- Python 3.8+ (Debian/Ubuntu: python3)
- virtualenv (optional), systemd (für Service)
- jq (optional, für Script Logging)

2) Agent‑Installation (Beispiel: /opt/clientd)
sudo mkdir -p /opt/clientd
sudo chown $(whoami):$(whoami) /opt/clientd
cd /opt/clientd

3) Python virtualenv & Flask
python3 -m venv venv
source venv/bin/activate
pip install --upgrade pip
pip install flask

4) Agent‑App — save as /opt/clientd/clientd.py
```python
from flask import Flask, request, jsonify
import os, subprocess, json, logging, shlex

app = Flask(__name__)
logging.basicConfig(level=logging.INFO)

CLIENTD_SECRET = os.getenv('CLIENTD_SECRET') or ''
ACTIONS_DIR = '/opt/clientd/actions'  # place your action scripts here
os.makedirs(ACTIONS_DIR, exist_ok=True)

def check_auth(req):
    h = req.headers.get('Authorization','')
    if h.lower().startswith('bearer '):
        token = h.split(None,1)[1]
        if token == CLIENTD_SECRET:
            return True
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

    # execute the script with payload on stdin
    try:
        proc = subprocess.run([script_path], input=json.dumps(payload).encode('utf-8'),
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10)
        return jsonify(ok=True, rc=proc.returncode, stdout=proc.stdout.decode('utf-8'), stderr=proc.stderr.decode('utf-8')), 200
    except Exception as e:
        return jsonify(ok=False, error='exec failed', detail=str(e)), 500

if __name__ == "__main__":
    app.run(host='0.0.0.0', port=9999)
```

5) Action‑Script: set_state.sh (save as /opt/clientd/actions/set_state.sh)
```bash
#!/usr/bin/env bash
# /opt/clientd/actions/set_state.sh
# read JSON from stdin and persist state
set -euo pipefail
payload="$(cat -)"
mkdir -p /tmp/clientd-demo
echo "$payload" > /tmp/clientd-demo/last-set-state.json
chmod 600 /tmp/clientd-demo/last-set-state.json

# also write a machine-local state
STATE_FILE="/var/lib/clientd/state.json"
mkdir -p "$(dirname "$STATE_FILE")"
cat > "${STATE_FILE}.tmp" <<JSON
$(jq -n --arg p "$payload" '{payload:$p, updated: (now|todate)}')
JSON
mv -f "${STATE_FILE}.tmp" "$STATE_FILE" || true
chmod 600 "$STATE_FILE" || true
echo "OK"
exit 0
```
Hinweis: Das Script benutzt `jq` zum Erzeugen JSON. Installiere jq: `sudo apt install -y jq`. Wenn jq fehlt, passe das Script an oder verwende einfache file writes.

6) Systemd Service für Agent
Erstelle /etc/systemd/system/clientd.service:
[Unit]
Description=Clientd test agent
After=network.target

[Service]
Type=simple
User=root
Group=root
WorkingDirectory=/opt/clientd
ExecStart=/opt/clientd/venv/bin/python /opt/clientd/clientd.py
Restart=on-failure
Environment=CLIENTD_SECRET=client-secret-ändert-werden

[Install]
WantedBy=multi-user.target

Installieren / starten:
sudo systemctl daemon-reload
sudo systemctl enable --now clientd.service
sudo journalctl -u clientd.service -f

7) Firewall (falls relevant)
Erlaube Port 9999 lokal/private:
sudo ufw allow from 192.168.0.0/16 to any port 9999 proto tcp
# oder für Tests offen:
sudo ufw allow 9999/tcp

8) Testen (Server → Client)
Von Server (oder local) sende:
curl -v -H "Authorization: Bearer client-secret-ändert-werden" -H "Content-Type: application/json" \
  -d '{"action":"set_state","state":"wartung","occupied":"0"}' \
  http://<client-ip>:9999/action

Erwartet: JSON { "ok": true, "rc": 0, "stdout": "OK\n", ... }

Auf Client prüfen:
cat /tmp/clientd-demo/last-set-state.json
cat /var/lib/clientd/state.json

9) Sicherheit / Produktion (Kurz)
- Schütze CLIENTD_SECRET (keine Dateien in Repo).
- Begrenze Zugriffe mittels Firewall/VPN.
- Setze Nutzerrechte für /var/lib/clientd auf dedizierten Benutzer (nicht root).
- Verwende TLS (nginx reverse proxy) in produktiven Umgebungen.

10) Test‑Checkliste für morgen
- Auf jedem Test‑Client:
  - systemctl status clientd.service → active (running)
  - curl http://localhost:9999/action mit falschem Token → 403
  - curl mit gültigem Token → 200, stdout "OK"
  - /tmp/clientd-demo/last-set-state.json enthält korrekte Payload
- Server:
  - API call set_state aktualisiert DB
  - Server forwarded action → Client antwortet OK

Wenn du möchtest, baue ich dir noch:
- ein kleines Ansible Playbook für das automatische Aufsetzen (Server + Clients), oder
- eine Kurz‑PDF/Druckversion dieser Anleitungen (Markdown → PDF).
Antworte: "Ansible" oder "PDF" falls du das brauchst.