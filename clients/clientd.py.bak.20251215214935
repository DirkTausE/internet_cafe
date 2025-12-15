#!/usr/bin/env python3
# clients/clientd.py
# Client daemon: read admin 'state' from server and report 'current_state'.
# Integrates with greeter_neu to enable guest login for 'gast' and to show
# a maintenance login prompt for 'wartung'.

import json
import logging
import socket
import subprocess
import time
import getpass
from typing import Optional
from pathlib import Path

# Optional CUPS import (may be unavailable on some hosts)
try:
    import cups  # type: ignore
except Exception:
    cups = None

# greeter helper
try:
    from clients import greeter_neu  # type: ignore
except Exception:
    try:
        import greeter_neu  # type: ignore
    except Exception:
        greeter_neu = None  # type: ignore

LOG = logging.getLogger("clientd")
LOG.setLevel(logging.INFO)
ch = logging.StreamHandler()
ch.setFormatter(
    logging.Formatter("%(asctime)s %(levelname)s %(message)s")
)
LOG.addHandler(ch)

CONFIG_FILE = Path("/etc/internetcafe-clientd.conf")


def load_conf(path: Path) -> dict:
    cfg: dict = {}
    if not path.is_file():
        return cfg
    try:
        with path.open("r", encoding="utf-8") as fh:
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


conf = load_conf(CONFIG_FILE)

# Secrets: only from config file
CLIENTD_SECRET = conf.get("CLIENTD_SECRET", "") or ""
API_KEY = conf.get("API_KEY", "") or ""

if not CLIENTD_SECRET:
    LOG.warning(
        "CLIENTD_SECRET unset in %s; client request will be unauthenticated.",
        CONFIG_FILE,
    )
if not API_KEY:
    LOG.warning(
        "API_KEY not set in %s; server requests will be unauthenticated.",
        CONFIG_FILE,
    )

# Server / runtime defaults (configurable via conf)
SERVER_URL = conf.get("SERVER_URL") or "http://server.local/api.php?action="
HOSTNAME = socket.gethostname()
CHECKIN_INTERVAL = int(conf.get("CHECKIN_INTERVAL") or 15)
PRINT_POLL_INTERVAL = int(conf.get("PRINT_POLL_INTERVAL") or 5)

# canonical states mapping (all lowercase)
_CANON_STATES = {
    "starting": "starting",
    "start": "starting",
    "booting": "starting",
    "frei": "frei",
    "free": "frei",
    "available": "frei",
    "idle": "frei",
    "gast": "gast",
    "guest": "gast",
    "pause": "pause",
    "paused": "pause",
    "break": "pause",
    "wartung": "wartung",
    "maintenance": "wartung",
    "maint": "wartung",
    "stop": "stop",
    "stopped": "stop",
    "off": "off",
    "poweroff": "off",
    "shutdown": "off",
}


def normalize_state(raw) -> Optional[str]:
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
    if s.isdigit():
        if int(s) == 1:
            return "starting"
        if int(s) == 0:
            return "off"
    return None


def send_api(endpoint: str, payload: dict) -> Optional[dict]:
    headers = {}
    if API_KEY:
        headers["X-API-KEY"] = API_KEY
    try:
        import requests  # type: ignore

        url = SERVER_URL + endpoint
        r = requests.post(
            url,
            json=payload,
            headers=headers,
            timeout=5,
        )
        try:
            return r.json()
        except Exception:
            return {"http_status": r.status_code, "text": r.text}
    except Exception:
        # fallback to curl if requests not available
        cmd = [
            "curl",
            "-sS",
            "-X",
            "POST",
            "-H",
            "Content-Type: application/json",
        ]
        for k, v in headers.items():
            cmd.extend(["-H", f"{k}: {v}"])
        cmd.append(SERVER_URL + endpoint)
        try:
            p = subprocess.run(
                cmd,
                input=json.dumps(payload).encode("utf-8"),
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                timeout=8,
            )
            out = p.stdout.decode("utf-8", errors="replace")
            try:
                return json.loads(out)
            except Exception:
                return {"raw": out, "rc": p.returncode}
        except Exception as e:
            LOG.debug("send_api fallback failed: %s", e)
            return None


class ClientDaemon:
    def __init__(self) -> None:
        self.last_job_ids = set()
        self._last_sent_current_state = None
        self._last_applied_state = None
        if cups:
            try:
                self.cups_conn = cups.Connection()
                self.scan_known_jobs()
            except Exception as e:
                LOG.warning("CUPS connection error: %s", e)
                self.cups_conn = None
        else:
            self.cups_conn = None

    def scan_known_jobs(self) -> None:
        if not self.cups_conn:
            return
        try:
            jobs = self.cups_conn.getJobs(which_jobs="all")
            self.last_job_ids = set(jobs.keys())
        except Exception as e:
            LOG.warning("scan_known_jobs error: %s", e)
            self.last_job_ids = set()

    def poll_print_jobs(self) -> None:
        if not self.cups_conn:
            return
        try:
            jobs = self.cups_conn.getJobs(which_jobs="all")
        except Exception as e:
            LOG.debug("CUPS poll error: %s", e)
            return
        current_ids = set(jobs.keys())
        new_ids = current_ids - self.last_job_ids
        for jid in new_ids:
            try:
                attrs = self.cups_conn.getJobAttributes(jid)
                pages = int(
                    attrs.get("job-media-sheets", attrs.get("page-count", 0))
                    or 0
                )
                job_name = attrs.get("job-name", str(jid))
                user = attrs.get("job-originating-user-name", None)
                payload = {
                    "host": HOSTNAME,
                    "job_name": job_name,
                    "pages": pages,
                    "user": user,
                }
                LOG.info("Found print job -> sending to server: %s", payload)
                res = send_api("print/job", payload)
                LOG.info("Server response: %s", res)
            except Exception as e:
                LOG.warning("Error handling print job %s: %s", jid, e)
        self.last_job_ids = current_ids

    def checkin_state(self) -> None:
        try:
            # request admin state from server (ActionHandler pc/get_state)
            r = send_api("pc/get_state", {"host": HOSTNAME})
            if isinstance(r, dict):
                state_raw = r.get("computer", {}).get("state")
                state = normalize_state(state_raw)
                if state is not None:
                    self.apply_state(state)

                # send back applied/measured current_state only when changed
                current_to_send = state
                if (
                    current_to_send is not None
                    and current_to_send != self._last_sent_current_state
                ):
                    res = send_api(
                        "pc/set_state",
                        {"host": HOSTNAME, "current_state": current_to_send},
                    )
                    LOG.debug("pc/set_state response: %s", res)
                    if isinstance(res, dict) and res.get("ok") is True:
                        self._last_sent_current_state = current_to_send
        except Exception as e:
            LOG.debug("checkin_state error: %s", e)

    def apply_state(self, state: str) -> None:
        """
        Apply canonical state. Additionally manage greeter:
        - when state == 'gast' => enable guest access via greeter_neu
        - when state == 'wartung' => show maintenance prompt (+ disable guest)
        - when transitioning away from 'gast'/'wartung' revert greeter changes
        """
        state = (state or "").lower()
        LOG.info("Applying state: %s", state)
        prev = self._last_applied_state
        try:
            user = getpass.getuser()
        except Exception:
            user = "unknown"

        greeter = greeter_neu if greeter_neu is not None else None

        # If entering 'gast', ensure guest access enabled
        if state == "gast":
            if greeter is not None:
                try:
                    greeter.enable_guest_access()  # type: ignore
                except Exception as e:
                    LOG.warning("greeter.enable_guest_access failed: %s", e)
        else:
            # leaving 'gast' state: consider disabling guest access
            if prev == "gast" and greeter is not None:
                try:
                    greeter.disable_guest_access()  # type: ignore
                except Exception as e:
                    LOG.warning("greeter.disable_guest_access failed: %s", e)

        # Maintenance handling
        if state == "wartung":
            # show maintenance prompt and disable guest
            if greeter is not None:
                try:
                    greeter.show_maintenance_prompt()  # type: ignore
                except Exception as e:
                    LOG.warning(
                        "greeter.show_maintenance_prompt failed: %s", e
                    )
        else:
            # leaving maintenance
            if prev == "wartung" and greeter is not None:
                try:
                    greeter.clear_maintenance_prompt()  # type: ignore
                except Exception as e:
                    LOG.warning(
                        "greeter.clear_maintenance_prompt failed: %s", e
                    )

        # perform local system actions for certain states
        if state in ("stop", "off"):
            try:
                subprocess.run(
                    ["loginctl", "terminate-user", user],
                    check=False,
                    timeout=10,
                )
                LOG.info("Requested termination of sessions for user %s", user)
            except Exception as e:
                LOG.warning("Could not terminate-user: %s", e)
        elif state == "pause":
            try:
                subprocess.run(
                    ["loginctl", "lock-session"], check=False, timeout=10
                )
                LOG.info("Requested lock-session for user %s", user)
            except Exception as e:
                LOG.warning("Could not lock-session: %s", e)
        elif state == "starting":
            LOG.info("Received starting state: no-op for agent.")
        elif state in ("frei", "gast", "wartung"):
            LOG.info(
                "State %s applied (no direct system action configured).",
                state,
            )
        else:
            LOG.debug("Unknown state received: %s", state)

        # remember applied state
        self._last_applied_state = state

    def run(self) -> None:
        last_print_poll = 0.0
        while True:
            self.checkin_state()
            now = time.time()
            if now - last_print_poll > PRINT_POLL_INTERVAL:
                self.poll_print_jobs()
                last_print_poll = now
            time.sleep(CHECKIN_INTERVAL)


if __name__ == "__main__":
    LOG.info("Starting clientd (hostname=%s)", HOSTNAME)
    d = ClientDaemon()
    try:
        d.run()
    except KeyboardInterrupt:
        LOG.info("clientd stopped by user")
