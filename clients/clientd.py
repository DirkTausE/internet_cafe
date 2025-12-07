#!/usr/bin/env python3
# clientd.py - verbesserter Client-Daemon mit CUPS Job-Polling
# Verwendet python3-cups (python3-cups Paket) und requests
# Konfiguriere SERVER_URL & API_KEY unten.

import time
import socket
import requests
import cups
import os
import sys
from datetime import datetime

def load_config_file(path):
    """Load configuration from a file in KEY=VALUE format."""
    config = {}
    if not os.path.exists(path):
        return config
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
                    config[key.strip()] = value
    except Exception as e:
        print(f"Warning: Failed to load config from {path}: {e}", file=sys.stderr)
    return config

# Load configuration from file or environment
config = load_config_file('/etc/internetcafe-clientd.conf')
SERVER_URL = os.getenv('SERVER_URL', config.get('SERVER_URL', "http://server.local/api.php?q="))
API_KEY = os.getenv('API_KEY', config.get('API_KEY', ''))

if not API_KEY:
    print("Warning: API_KEY not set. Please set API_KEY environment variable or add it to /etc/internetcafe-clientd.conf", file=sys.stderr)

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
                # Normalize state to lowercase
                state = (state or '').lower()
                # optional: if state instructs logout/lock, do it
                self.apply_state(state)
            # post back last_checkin
            headers = {'X-API-KEY': API_KEY}
            requests.post(SERVER_URL + "pc/set_state", json={'host': HOSTNAME, 'state': state}, headers=headers, timeout=5)
        except Exception as e:
            print("checkin error", e)

    def apply_state(self, state):
        # Normalize state to lowercase for comparison
        state = (state or '').lower()
        
        if state in ('stop', 'off'):
            # log out all users - get current user safely
            try:
                current_user = os.getlogin()
            except OSError:
                # Fallback if os.getlogin() fails
                current_user = os.getenv('USER') or os.getenv('LOGNAME')
            
            if current_user:
                ret = os.system(f"loginctl terminate-user {current_user} || true")
                if ret != 0:
                    print(f"Warning: Failed to terminate user {current_user}", file=sys.stderr)
        elif state == 'pause':
            ret = os.system("loginctl lock-session || true")
            if ret != 0:
                print("Warning: Failed to lock session", file=sys.stderr)
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
