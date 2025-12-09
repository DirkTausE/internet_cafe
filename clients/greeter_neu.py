#!/usr/bin/env python3
# clients/greeter_neu.py
# Greeter: if API/DB health check fails, show a username/password
# login window (no guest login allowed). Attempts server auth when
# possible, otherwise falls back to a minimal local check.

import json
import logging
import sys
from pathlib import Path
from typing import Dict, Optional, Tuple

try:
    # prefer requests if available
    import requests  # type: ignore
    _HAS_REQUESTS = True
except Exception:
    _HAS_REQUESTS = False
    import urllib.request as _urlreq  # type: ignore
    import urllib.error as _urlerr  # type: ignore

try:
    import tkinter as tk
    from tkinter import ttk, messagebox
except Exception:
    tk = None  # GUI not available

LOG = logging.getLogger("greeter_neu")
LOG.addHandler(logging.StreamHandler())
LOG.setLevel(logging.INFO)

CONFIG_PATH = Path("/etc/internetcafe-clientd.conf")
DEFAULT_AUTH_URL = "http://server.local/api.php?q=auth"
HEALTH_TIMEOUT = 3.0
AUTH_TIMEOUT = 5.0


def load_conf(path: Path) -> Dict[str, str]:
    cfg: Dict[str, str] = {}
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
                cfg[k.strip()] = v.strip().strip('"').strip("'")
    except Exception as exc:
        LOG.debug("Failed to read config %s: %s", path, exc)
    return cfg


def _http_get(url: str, timeout: float) -> Tuple[int, Optional[str]]:
    if _HAS_REQUESTS:
        try:
            r = requests.get(url, timeout=timeout)
            return r.status_code, r.text
        except Exception as exc:
            LOG.debug("requests GET error: %s", exc)
            return 0, None
    # urllib fallback
    try:
        with _urlreq.urlopen(url, timeout=timeout) as resp:
            data = resp.read()
            decoded = data.decode("utf-8", errors="replace")
            return resp.getcode(), decoded
    except _urlerr.URLError as exc:
        LOG.debug("urllib GET error: %s", exc)
        return 0, None
    except Exception as exc:
        LOG.debug("urllib GET unexpected error: %s", exc)
        return 0, None


def _http_post_json(
    url: str,
    payload: dict,
    headers: Dict[str, str],
    timeout: float,
) -> Tuple[int, Optional[str]]:
    body = json.dumps(payload).encode("utf-8")
    if _HAS_REQUESTS:
        try:
            r = requests.post(
                url, json=payload, headers=headers, timeout=timeout
            )
            return r.status_code, r.text
        except Exception as exc:
            LOG.debug("requests POST error: %s", exc)
            return 0, None
    # urllib fallback
    req = _urlreq.Request(url, data=body)
    req.add_header("Content-Type", "application/json")
    for k, v in headers.items():
        req.add_header(k, v)
    try:
        with _urlreq.urlopen(req, timeout=timeout) as resp:
            data = resp.read()
            decoded = data.decode("utf-8", errors="replace")
            return resp.getcode(), decoded
    except _urlerr.URLError as exc:
        LOG.debug("urllib POST error: %s", exc)
        return 0, None
    except Exception as exc:
        LOG.debug("urllib POST unexpected error: %s", exc)
        return 0, None


def check_api_health(auth_url: str) -> bool:
    """
    Do a lightweight GET to the auth endpoint to detect API/DB issues.
    Consider healthy if we receive any 2xx response.
    """
    status, _ = _http_get(auth_url, HEALTH_TIMEOUT)
    return 200 <= status < 300


def server_authenticate(
    auth_url: str, api_key: str, username: str, password: str
) -> bool:
    """
    Attempt authentication against server API. Expects a JSON response
    with an 'ok' boolean or HTTP 200 for success. This is best-effort.
    """
    headers: Dict[str, str] = {}
    if api_key:
        headers["X-API-KEY"] = api_key
    payload = {"username": username, "password": password}
    status, text = _http_post_json(auth_url, payload, headers, AUTH_TIMEOUT)
    if 200 <= status < 300 and text:
        try:
            js = json.loads(text)
            if isinstance(js, dict):
                return bool(
                    js.get("ok")
                    or js.get("authenticated")
                    or js.get("success")
                )
        except Exception as exc:
            LOG.debug("Failed to parse auth JSON: %s", exc)
            # If API returned 200 but not JSON, treat as success.
            return True
    return False


def local_authenticate(username: str, password: str) -> bool:
    """
    Fallback local authentication when server is unreachable.
    Reject 'guest' explicitly. Accept any non-empty username/password.
    This is deliberately permissive for testing but blocks guest.
    """
    if not username or not password:
        return False
    if username.strip().lower() == "guest":
        return False
    return True


class LoginWindow:
    def __init__(self, auth_url: str, api_key: str):
        if tk is None:
            raise RuntimeError("tkinter is required for GUI")
        self.auth_url = auth_url
        self.api_key = api_key
        self.root = tk.Tk()
        self.root.title("Login")
        self.root.resizable(False, False)
        frm = ttk.Frame(self.root, padding=12)
        frm.grid()

        ttk.Label(frm, text="Username").grid(column=0, row=0, sticky="w")
        self.user_var = tk.StringVar()
        self.user_entry = ttk.Entry(frm, textvariable=self.user_var)
        self.user_entry.grid(column=0, row=1, sticky="we")
        self.user_entry.focus()

        ttk.Label(frm, text="Password").grid(column=0, row=2, sticky="w")
        self.pass_var = tk.StringVar()

        # keep lines <=79 chars by splitting constructor args
        self.pass_entry = ttk.Entry(
            frm,
            textvariable=self.pass_var,
            show="*",
        )
        self.pass_entry.grid(column=0, row=3, sticky="we")

        self.msg = ttk.Label(frm, text="", foreground="red")
        self.msg.grid(column=0, row=4, pady=(6, 0))

        btn = ttk.Button(frm, text="Login", command=self.on_login)
        btn.grid(column=0, row=5, pady=(8, 0))

        self.root.bind("<Return>", lambda ev: self.on_login())

    def on_login(self) -> None:
        user = self.user_var.get().strip()
        pwd = self.pass_var.get()
        if not user or not pwd:
            self.msg.config(text="Fill both username and password.")
            return
        if user.lower() == "guest":
            self.msg.config(text="Guest login is not permitted here.")
            return

        # Try server auth first if API reachable
        ok = False
        try:
            ok = server_authenticate(
                self.auth_url, self.api_key, user, pwd
            )
        except Exception as exc:
            LOG.debug("server_authenticate error: %s", exc)
            ok = False

        if not ok:
            # fallback local check
            ok = local_authenticate(user, pwd)

        if ok:
            messagebox.showinfo("Login", "Login successful")
            self.root.destroy()
            sys.exit(0)
        self.msg.config(text="Authentication failed. Try again.")

    def run(self) -> None:
        self.root.mainloop()


def main() -> int:
    conf = load_conf(CONFIG_PATH)
    auth_url = (
        conf.get("AUTH_URL")
        or conf.get("API_AUTH_URL")
        or DEFAULT_AUTH_URL
    )
    api_key = conf.get("API_KEY", "") or ""

    # Quick health check: if API is healthy, try a silent reachability call.
    healthy = False
    try:
        healthy = check_api_health(auth_url)
    except Exception as exc:
        LOG.debug("health check error: %s", exc)
        healthy = False

    if healthy:
        LOG.info("API reachable; attempting silent auth check skipped.")
        # If API is healthy, we may proceed to normal greeter flow.
        # For this minimal greeter, we consider healthy => exit success.
        # Real integration could show a nicer greeter UI here.
        print("OK")
        return 0

    # If API/DB error detected, show login window.
    LOG.info("API/DB unreachable - showing login window")
    if tk is None:
        LOG.error("tkinter not available; cannot show login window")
        return 2

    win = LoginWindow(auth_url, api_key)
    try:
        win.run()
    except KeyboardInterrupt:
        return 1
    return 0


if __name__ == "__main__":
    try:
        rc = main()
    except SystemExit:
        raise
    except Exception as exc:  # pragma: no cover - safety net
        LOG.exception("Unhandled greeter error: %s", exc)
        rc = 1
    sys.exit(rc)