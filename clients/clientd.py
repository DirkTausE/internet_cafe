#!/usr/bin/env python3
# clientd.py - verbesserter Client-Daemon mit CUPS Job-Polling
# Verwendet python3-cups (python3-cups Paket) und requests
# Konfiguriere SERVER_URL & API_KEY unten.

import time
import socket
import requests
import cups
import os

SERVER_URL = "http://server.local/api.php?q="
API_KEY = "CHANGE_ME_API_KEY"
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
        headers = {
            'X-API-KEY': API_KEY,
            'Content-Type': 'application/json'
        }
        try:
            r = requests.post(
                SERVER_URL + endpoint,
                json=payload,
                headers=headers,
                timeout=5
            )
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
            # job fields example: 'pages', 'job-name',
            # 'job-originating-user-name', 'copies'
            # A safer approach: query job attributes
            attrs = self.cups_conn.getJobAttributes(jid)
            pages = int(
                attrs.get(
                    'job-media-sheets',
                    attrs.get('page-count', 0)
                ) or 0
            )
            job_name = attrs.get('job-name', str(jid))
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
            r = requests.get(
                SERVER_URL + "pc/get_state",
                params={'host': HOSTNAME},
                timeout=5
            )
            if r.status_code == 200:
                js = r.json()
                state = js.get('computer', {}).get('current_state')
                # optional: if state instructs logout/lock, do it
                self.apply_state(state)
            # post back last_checkin
            headers = {'X-API-KEY': API_KEY}
            requests.post(
                SERVER_URL + "pc/set_state",
                json={'host': HOSTNAME, 'state': state},
                headers=headers,
                timeout=5
            )
        except Exception as e:
            print("checkin error", e)

    def apply_state(self, state):
        if state in ('STOP', 'OFF'):
            # log out all users
            os.system("loginctl terminate-user $(whoami) || true")
        elif state == 'pause':
            os.system("loginctl lock-session || true")
        # further logic: starting->prepare kiosk,
        # frei->allow login, gast->allow guest login

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
