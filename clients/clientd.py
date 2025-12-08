#!/usr/bin/env python3
# clients/clientd.py
# Client daemon: load secrets from environment or /etc/internetcafe-clientd.conf,
# normalize states to canonical lowercase values and use portable systemd paths.
#
# This file intentionally does NOT contain real secrets. Use /etc/internetcafe-clientd.conf
# or environment variables (CLIENTD_SECRET, API_KEY) on the host.

from __future__ import annotations
import os
import json
import time
import socket
import logging
import subprocess
import getpass
from datetime import datetime

# Optional imports; keep original behavior where available
try:
    import cups  # may be absent on some test hosts
except Exception:
    cups = None

LOG = logging.getLogger("clientd")
LOG.setLevel(logging.INFO)
ch = logging.StreamHandler()
ch.setFormatter(logging.Formatter('%(asctime)s %(levelname)s %(message)s'))
LOG.addHandler(ch)

CONFIG_FILE = "/etc/internetcafe-clientd.conf"
CLIENTD_SECRET = None
API_KEY = None

def load_conf(path: str) -> dict:
    cfg = {}
    if not os.path.isfile(path):
        return cfg
    try:
        with open(path, "r", encoding="utf-8") as fh:
            for ln in fh:
                ln = ln.strip()
                if not ln or ln.startswith("#"):
                    continue
                if "=" not in ln:
                    continue
                k, v = ln.split("=", 1)
                k = k.strip()
                v = v.strip().strip('"').strip("'")
                cfg[k] = v
    except Exception as e:
        LOG.warning("Failed to read config %s: %s", path, e)
    return cfg

# Load from environment first, then from config file
env_clientd = os.getenv("CLIENTD_SECRET")
env_api = os.getenv("API_KEY")

conf = load_conf(CONFIG_FILE)
CLIENTD_SECRET = env_clientd or conf.get("CLIENTD_SECRET") or ""
API_KEY = env_api or conf.get("API_KEY") or ""

if not CLIENTD_SECRET:
    LOG.warning("CLIENTD_SECRET not set (env or /etc). Requests without auth will be rejected.")
if not API_KEY:
    LOG.warning("API_KEY not set (env or /etc). Sending to server may be unauthenticated.")

# Network / server defaults (keep from original file where applicable)
SERVER_URL = os.getenv("SERVER_URL") or "http://server.local/api.php?q="
HOSTNAME = socket.gethostname()
CHECKIN_INTERVAL = int(os.getenv("CHECKIN_INTERVAL", "15"))
PRINT_POLL_INTERVAL = int(os.getenv("PRINT_POLL_INTERVAL", "5"))

# canonical states mapping (all lowercase)
_CANON_STATES = {
    'starting': 'starting',
    'start': 'starting',
    'booting': 'starting',
    'frei': 'frei',
    'free': 'frei',
    'available': 'frei',
    'idle': 'frei',
    'gast': 'gast',
    'guest': 'gast',
    'pause': 'pause',
    'paused': 'pause',
    'break': 'pause',
    'wartung': 'wartung',
    'maintenance': 'wartung',
    'maint': 'wartung',
    'stop': 'stop',
    'stopped': 'stop',
    'off': 'off',
    'poweroff': 'off',
    'shutdown': 'off',
}

def normalize_state(raw) -> str|None:
    if raw is None:
        return None
    s = str(raw).strip().lower()
    if s == "":
        return None
    if s in _CANON_STATES:
        return _CANON_STATES[s]
    for k, v in _CANON_STATES.items():
        if k in s:
            return v
    # numeric heuristics
    if s.isdigit():
        if int(s) == 1:
            return 'starting'
        if int(s) == 0:
            return 'off'
    return None

def send_api(endpoint: str, payload: dict) -> dict|None:
    # prefer requests when available, else simple fallback using curl if present
    headers = {}
    if API_KEY:
        headers['X-API-KEY'] = API_KEY
    try:
        # lazy import to avoid failing if requests not installed
        import requests
        url = SERVER_URL + endpoint
        r = requests.post(url, json=payload, headers=headers, timeout=5)
        try:
            return r.json()
        except Exception:
            return {"http_status": r.status_code, "text": r.text}
    except Exception:
        # fallback to curl subprocess (best-effort)
        cmd = ["curl", "-sS", "-X", "POST", "-H", "Content-Type: application/json"]
        for k, v in headers.items():
            cmd.extend(["-H", f"{k}: {v}"])
        cmd.append(SERVER_URL + endpoint)
        try:
            p = subprocess.run(cmd, input=json.dumps(payload).encode('utf-8'), stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=8)
            out = p.stdout.decode('utf-8', errors='replace')
            try:
                return json.loads(out)
            except Exception:
                return {"raw": out, "rc": p.returncode}
        except Exception as e:
            LOG.debug("send_api fallback failed: %s", e)
            return None

class ClientDaemon:
    def __init__(self):
        self.last_job_ids = set()
        if cups:
            try:
                self.cups_conn = cups.Connection()
                self.scan_known_jobs()
            except Exception as e:
                LOG.warning("CUPS connection error: %s", e)
                self.cups_conn = None
        else:
            self.cups_conn = None

    def scan_known_jobs(self):
        if not self.cups_conn:
            return
        try:
            jobs = self.cups_conn.getJobs(which_jobs='all')
            self.last_job_ids = set(jobs.keys())
        except Exception as e:
            LOG.warning("scan_known_jobs error: %s", e)
            self.last_job_ids = set()

    def poll_print_jobs(self):
        if not self.cups_conn:
            return
        try:
            jobs = self.cups_conn.getJobs(which_jobs='all')
        except Exception as e:
            LOG.debug("CUPS poll error: %s", e)
            return
        current_ids = set(jobs.keys())
        new_ids = current_ids - self.last_job_ids
        for jid in new_ids:
            try:
                attrs = self.cups_conn.getJobAttributes(jid)
                pages = int(attrs.get('job-media-sheets', attrs.get('page-count', 0)) or 0)
                job_name = attrs.get('job-name', str(jid))
                user = attrs.get('job-originating-user-name', None)
                payload = {
                    'host': HOSTNAME,
                    'job_name': job_name,
                    'pages': pages,
                    'user': user,
                }
                LOG.info("Found print job -> sending to server: %s", payload)
                res = send_api('print/job', payload)
                LOG.info("Server response: %s", res)
            except Exception as e:
                LOG.warning("Error handling print job %s: %s", jid, e)
        self.last_job_ids = current_ids

    def checkin_state(self):
        try:
            r = send_api("pc/get_state", {"host": HOSTNAME})
            if isinstance(r, dict):
                state_raw = r.get('computer', {}).get('current_state')
                state = normalize_state(state_raw)
                if state is not None:
                    self.apply_state(state)
                # post back last checkin if needed
                send_api("pc/set_state", {"host": HOSTNAME, "state": state})
        except Exception as e:
            LOG.debug("checkin_state error: %s", e)

    def apply_state(self, state: str):
        state = (state or '').lower()
        LOG.info("Applying state: %s", state)
        # prefer subprocess.run with arg list; avoid shell interpolation
        try:
            user = getpass.getuser()
        except Exception:
            user = os.getenv("SUDO_USER") or os.getenv("USER") or "unknown"

        if state in ('stop', 'off'):
            # attempt to terminate sessions for the current user (best-effort)
            try:
                subprocess.run(["loginctl", "terminate-user", user], check=False, timeout=10)
                LOG.info("Requested termination of sessions for user %s", user)
            except Exception as e:
                LOG.warning("Could not terminate-user: %s", e)
        elif state == 'pause':
            try:
                subprocess.run(["loginctl", "lock-session"], check=False, timeout=10)
                LOG.info("Requested lock-session for user %s", user)
            except Exception as e:
                LOG.warning("Could not lock-session: %s", e)
        elif state == 'starting':
            LOG.info("Received starting state: no-op for agent.")
        elif state in ('frei', 'gast', 'wartung'):
            LOG.info("State %s applied (no direct system action configured).", state)
        else:
            LOG.debug("Unknown state received: %s", state)

    def run(self):
        last_print_poll = 0.0
        while True:
            self.checkin_state()
            now = time.time()
            if now - last_print_poll > PRINT_POLL_INTERVAL:
                self.poll_print_jobs()
                last_print_poll = now
            time.sleep(CHECKIN_INTERVAL)

if __name__ == '__main__':
    # basic runtime check; keeps process runnable for testing.
    LOG.info("Starting clientd (hostname=%s)", HOSTNAME)
    d = ClientDaemon()
    try:
        d.run()
    except KeyboardInterrupt:
        LOG.info("clientd stopped by user")