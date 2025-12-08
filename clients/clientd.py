#!/usr/bin/env python3
# clientd.py - verbesserter Client-Daemon mit CUPS Job-Polling
# Verwendet python3-cups (python3-cups Paket) und requests
# Konfiguriere SERVER_URL & API_KEY unten.

import time
import socket
import requests
import cups
import os
import subprocess
import getpass
from datetime import datetime

def load_conf(path):
    """Load configuration from a KEY=VALUE file, skipping comments."""
    conf = {}
    if not os.path.exists(path):
        return conf
    try:
        with open(path, 'r') as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith('#'):
                    continue
                if '=' in line:
                    key, value = line.split('=', 1)
                    # Remove quotes if present
                    value = value.strip().strip('"').strip("'")
                    conf[key.strip()] = value
    except Exception as e:
        print(f"Warning: Could not load config from {path}: {e}")
    return conf

def normalize_state(state):
    """Normalize state to lowercase canonical values."""
    if state is None:
        return None
    state_lower = str(state).lower()
    # Map synonyms to canonical values
    state_map = {
        'starting': 'starting',
        'frei': 'frei',
        'gast': 'gast',
        'pause': 'pause',
        'wartung': 'wartung',
        'stop': 'stop',
        'off': 'off'
    }
    return state_map.get(state_lower, state_lower)

# Load configuration
# Priority: 1) Environment variables, 2) /etc/internetcafe-clientd.conf
API_KEY = os.getenv('API_KEY')
CLIENTD_SECRET = os.getenv('CLIENTD_SECRET')

if not API_KEY or not CLIENTD_SECRET:
    conf = load_conf('/etc/internetcafe-clientd.conf')
    if not API_KEY:
        API_KEY = conf.get('API_KEY')
    if not CLIENTD_SECRET:
        CLIENTD_SECRET = conf.get('CLIENTD_SECRET')

if not API_KEY:
    print("Warning: API_KEY not set. API calls may fail.")
if not CLIENTD_SECRET:
    print("Warning: CLIENTD_SECRET not set.")

SERVER_URL = "http://server.local/api.php?q="
HOSTNAME = socket.gethostname()
CHECKIN_INTERVAL = 15
PRINT_POLL_INTERVAL = 5

class ClientDaemon:
    def __init__(self):
        self.last_job_ids = set()
        self.cups_conn = cups.Connection()
        # initial read
        self.scan_known_jobs()

    def scan_known_jobs(self):
        try:
            jobs = self.cups_conn.getJobs(which_jobs='all')  # dict jobid->{}
            self.last_job_ids = set(jobs.keys())
        except Exception as e:
            print("CUPS initial error:", e)
            self.last_job_ids = set()

    def send_api(self, endpoint, payload):
        headers = {'X-API-KEY': API_KEY, 'Content-Type': 'application/json'}
        try:
            r = requests.post(SERVER_URL + endpoint, json=payload, headers=headers, timeout=5)
            return r.json()
        except Exception as e:
            print("API error", e)
            return None

    def poll_print_jobs(self):
        try:
            jobs = self.cups_conn.getJobs(which_jobs='all')
        except Exception as e:
            print("CUPS poll error:", e)
            return
        current_ids = set(jobs.keys())
        new_ids = current_ids - self.last_job_ids
        for jid in new_ids:
            job = jobs[jid]
            # job fields example: 'pages', 'job-name', 'job-originating-user-name', 'copies'
            pages = int(job.get('job-media-sheets', job.get('job-k-octets', 0)) or 0)  # fallback; real field may differ
            # A safer approach: query job attributes
            attrs = self.cups_conn.getJobAttributes(jid)
            pages = int(attrs.get('job-media-sheets', attrs.get('page-count', 0)) or 0)
            job_name = attrs.get('job-name', str(jid))
            user = attrs.get('job-originating-user-name', None)
            # detect color via 'document-format' heuristic (not perfect)
            color = 0
            copies = int(attrs.get('copies', 1))
            payload = {
                'host': HOSTNAME,
                'job_name': job_name,
                'pages': pages,
                'color': color,
                'copies': copies,
            }
            print("Found print job -> sending to server:", payload)
            res = self.send_api('print/job', payload)
            print("Server response:", res)
        self.last_job_ids = current_ids

    def checkin_state(self):
        try:
            r = requests.get(SERVER_URL + "pc/get_state", params={'host': HOSTNAME}, timeout=5)
            if r.status_code == 200:
                js = r.json()
                state = js.get('computer', {}).get('current_state')
                # optional: if state instructs logout/lock, do it
                self.apply_state(state)
            # post back last_checkin
            headers = {'X-API-KEY': API_KEY}
            requests.post(SERVER_URL + "pc/set_state", json={'host': HOSTNAME, 'state': state}, headers=headers, timeout=5)
        except Exception as e:
            print("checkin error", e)

    def apply_state(self, state):
        state = normalize_state(state)
        if not state:
            return
        
        if state in ('stop', 'off'):
            # log out all users
            try:
                current_user = os.getlogin()
            except OSError:
                current_user = getpass.getuser()
            
            try:
                result = subprocess.run(
                    ['loginctl', 'terminate-user', current_user],
                    capture_output=True,
                    text=True,
                    timeout=10
                )
                if result.returncode != 0:
                    print(f"Warning: loginctl terminate-user failed: {result.stderr}")
            except Exception as e:
                print(f"Error terminating user: {e}")
        elif state == 'pause':
            try:
                result = subprocess.run(
                    ['loginctl', 'lock-session'],
                    capture_output=True,
                    text=True,
                    timeout=10
                )
                if result.returncode != 0:
                    print(f"Warning: loginctl lock-session failed: {result.stderr}")
            except Exception as e:
                print(f"Error locking session: {e}")
        # further logic: starting->prepare kiosk, frei->allow login, gast->allow guest login

    def run(self):
        last_print_poll = 0
        while True:
            self.checkin_state()
            now = time.time()
            if now - last_print_poll > PRINT_POLL_INTERVAL:
                self.poll_print_jobs()
                last_print_poll = now
            time.sleep(CHECKIN_INTERVAL)

if __name__ == '__main__':
    d = ClientDaemon()
    d.run()
