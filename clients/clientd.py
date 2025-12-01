#!/usr/bin/env python3
# clients/clientd.py - Minimaler Platzhalter für Client-Daemon
# Bitte anpassen: SERVER_URL, API_KEY, CUPS-Integration etc.

import time, socket, requests
SERVER_URL = "http://server.local/web/api.php?q="
API_KEY = "REPLACE_ME"
HOSTNAME = socket.gethostname()

def main():
    while True:
        try:
            r = requests.get(SERVER_URL + "ping", timeout=5)
            print("Server:", r.json())
        except Exception as e:
            print("Fehler beim API-Request:", e)
        time.sleep(30)

if __name__ == '__main__':
    main()
