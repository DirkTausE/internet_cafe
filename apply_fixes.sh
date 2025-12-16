#!/usr/bin/env bash
# apply_fixes.sh
# Safe apply of fixes for clientd / greeter / API behavior.
#
# Usage: run from repository root: ./apply_fixes.sh
set -euo pipefail

BRANCH="fix/api-clientd-greeter"
FILES=(
  "clients/clientd.py"
  "clients/greeter_neu.py"
  "web/api.php"
  "web/api/computers.php"
)

echo "This script will backup files and write corrected versions."
read -r -p "Continue? [y/N] " ans
if [[ "${ans,,}" != "y" ]]; then
  echo "Aborted."
  exit 1
fi

# Ensure clean working tree
if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "Working tree not clean. Commit or stash changes first."
  git status --porcelain
  exit 1
fi

# create branch
git checkout -b "$BRANCH"

# backup originals
for f in "${FILES[@]}"; do
  if [[ -f "$f" ]]; then
    cp -a "$f" "${f}.bak.$(date +%Y%m%d%H%M%S)"
    echo "Backed up $f -> ${f}.bak.*"
  fi
done

# write new clients/clientd.py
cat > clients/clientd.py <<'PYCLIENT'
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
        "CLIENTD_SECRET not set in %s; client requests will be unauthenticated.",
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
        - when state == 'wartung' => show maintenance prompt (and disable guest)
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
                        "greeter.show_maintenance_prompt failed: %s",
                        e,
                    )
        else:
            # leaving maintenance
            if prev == "wartung" and greeter is not None:
                try:
                    greeter.clear_maintenance_prompt()  # type: ignore
                except Exception as e:
                    LOG.warning(
                        "greeter.clear_maintenance_prompt failed: %s",
                        e,
                    )

        # perform local system actions for certain states
        if state in ("stop", "off"):
            try:
                subprocess.run(
                    ["loginctl", "terminate-user", user],
                    check=False,
                    timeout=10,
                )
                LOG.info(
                    "Requested termination of sessions for user %s",
                    user,
                )
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
PYCLIENT

# write new clients/greeter_neu.py
cat > clients/greeter_neu.py <<'PYGREETER'
#!/usr/bin/env python3
# clients/greeter_neu.py
# Greeter control helper for InternetCafe.
#
# Implements:
#  - enable_guest_access(): allow guest login (LightDM)
#  - disable_guest_access(): disallow guest login
#  - show_maintenance_prompt(): force greeter to show login prompt
#  - clear_maintenance_prompt(): undo maintenance hint
#
# Notes:
# - Current implementation targets LightDM. Other DMs are not supported.
# - Many operations require root privileges (writes /etc and restarts DM).
# - Behavior is conservative: writes one small file in
#   /etc/lightdm/lightdm.conf.d/ and restarts LightDM only when needed.

from pathlib import Path
import subprocess
import logging
import os
from typing import Tuple

LOG = logging.getLogger("greeter_neu")
LOG.addHandler(logging.NullHandler())

LIGHTDM_CONF_DIR = Path("/etc/lightdm/lightdm.conf.d")
CONF_FILENAME = "50-internetcafe.conf"
CONF_PATH = LIGHTDM_CONF_DIR / CONF_FILENAME
MAINT_FLAG = Path("/var/run/internetcafe_maintenance")


def _is_root() -> bool:
    try:
        return os.geteuid() == 0  # type: ignore[attr-defined]
    except Exception:
        return False


def _restart_lightdm() -> Tuple[bool, str]:
    """
    Restart lightdm service (systemd). Returns (success, message).
    """
    try:
        p = subprocess.run(
            ["systemctl", "restart", "lightdm.service"],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=10,
            check=False,
        )
        if p.returncode == 0:
            return True, "restarted lightdm"
        msg = p.stderr.decode().strip()
        return False, "restart failed: " + msg
    except Exception as e:
        return False, f"exception: {e}"


def _write_lightdm_conf(allow_guest: bool) -> Tuple[bool, str]:
    """
    Ensure the conf directory and file exist and contain the option.
    """
    try:
        LIGHTDM_CONF_DIR.mkdir(parents=True, exist_ok=True)
        content = "[Seat:*]\nallow-guest={}\n".format(
            "true" if allow_guest else "false"
        )
        # Only rewrite file if changed
        if CONF_PATH.exists():
            existing = CONF_PATH.read_text(encoding="utf-8")
            if existing == content:
                return True, "unchanged"
        CONF_PATH.write_text(content, encoding="utf-8")
        return True, "written"
    except Exception as e:
        return False, str(e)


def enable_guest_access() -> bool:
    """
    Attempt to enable guest login on supported greeter (LightDM).
    Returns True on success or if already enabled.
    """
    LOG.info("Enabling guest access (best-effort).")
    if not _is_root():
        LOG.warning("enable_guest_access requires root privileges.")
        return False

    lightdm_sbin = Path("/usr/sbin/lightdm")
    lightdm_bin = Path("/usr/bin/lightdm")
    if not lightdm_sbin.exists() and not lightdm_bin.exists():
        LOG.warning(
            "LightDM not detected; enable_guest_access unsupported."
        )
        return False

    ok, msg = _write_lightdm_conf(True)
    if not ok:
        LOG.error("Failed to write LightDM config: %s", msg)
        return False

    ok2, msg2 = _restart_lightdm()
    if not ok2:
        LOG.error("Failed to restart LightDM: %s", msg2)
        return False

    LOG.info("Guest access enabled.")
    return True


def disable_guest_access() -> bool:
    """
    Attempt to disable guest login on supported greeter.
    Returns True on success or if already disabled.
    """
    LOG.info("Disabling guest access (best-effort).")
    if not _is_root():
        LOG.warning("disable_guest_access requires root privileges.")
        return False

    lightdm_sbin = Path("/usr/sbin/lightdm")
    lightdm_bin = Path("/usr/bin/lightdm")
    if not lightdm_sbin.exists() and not lightdm_bin.exists():
        LOG.warning(
            "LightDM not detected; disable_guest_access unsupported."
        )
        return False

    ok, msg = _write_lightdm_conf(False)
    if not ok:
        LOG.error("Failed to write LightDM config: %s", msg)
        return False

    ok2, msg2 = _restart_lightdm()
    if not ok2:
        LOG.error("Failed to restart LightDM: %s", msg2)
        return False

    LOG.info("Guest access disabled.")
    return True


def show_maintenance_prompt(
    message: str = "Wartung. Anmeldung erforderlich."
) -> bool:
    """
    Indicate a maintenance situation. Creates a marker file and attempts
    to disable guest access so greeter shows the login prompt.
    """
    LOG.info("Activating maintenance mode.")
    if not _is_root():
        LOG.warning("show_maintenance_prompt requires root privileges.")
        return False

    # ensure guest disabled so greeter shows login prompt
    if not disable_guest_access():
        LOG.warning("Failed to ensure guest access disabled.")

    try:
        MAINT_FLAG.write_text(message, encoding="utf-8")
    except Exception as e:
        LOG.warning("Could not write maintenance flag: %s", e)

    ok, msg = _restart_lightdm()
    if not ok:
        LOG.warning(
            "Failed to restart LightDM after maintenance: %s",
            msg,
        )

    LOG.info("Maintenance prompt activated.")
    return True


def clear_maintenance_prompt() -> bool:
    """
    Clear maintenance marker and optionally re-enable guest access.
    """
    LOG.info("Clearing maintenance mode.")
    if not _is_root():
        LOG.warning("clear_maintenance_prompt requires root privileges.")
        return False
    try:
        if MAINT_FLAG.exists():
            MAINT_FLAG.unlink()
    except Exception as e:
        LOG.warning("Could not remove maintenance flag: %s", e)

    ok, msg = _restart_lightdm()
    if not ok:
        LOG.warning(
            "Failed to restart LightDM after clearing maintenance: %s",
            msg,
        )

    LOG.info("Maintenance prompt cleared.")
    return True
PYGREETER

# write new web/api.php
cat > web/api.php <<'PHPAPI'
<?php
// web/api.php - minimal API for internet_cafe
// Endpoints: ?q=ping, ?q=status, ?q=version
// Also supports action=... for client.d requests (POST or GET)
// API key support: X-API-KEY header or ?api_key=... if configured in config.php
//
// Security: client agents using the API_KEY may only READ admin 'state' and may only
// write 'current_state'. Any attempt by a client (API_KEY) to set the admin 'state'
// is rejected. Admin operations that change the admin 'state' must use the
// authenticated admin endpoints (e.g. web/api/computers.php with Bearer token).

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Load optional config (do not raise errors if not present)
$expectedApiKey = null;
$apiKeyRequired = false;
$appVersion = '0.1.0';
$appName = 'internetcafe';
$configPath = __DIR__ . '/../config.php';
if (file_exists($configPath)) {
    @include $configPath;
    if (defined('API_KEY')) {
        $expectedApiKey = API_KEY;
        $apiKeyRequired = true;
    } elseif (isset($config) && is_array($config) && isset($config['api_key'])) {
        $expectedApiKey = $config['api_key'];
        $apiKeyRequired = true;
    }
    if (defined('APP_VERSION')) {
        $appVersion = APP_VERSION;
    } elseif (isset($config['version'])) {
        $appVersion = (string)$config['version'];
    }
    if (defined('APP_NAME')) {
        $appName = APP_NAME;
    } elseif (isset($config['name'])) {
        $appName = (string)$config['name'];
    }
}

// Helper: read git short sha if available (optional)
$gitSha = null;
$gitHeadFile = __DIR__ . '/../.git/HEAD';
if (is_readable($gitHeadFile)) {
    $head = trim(@file_get_contents($gitHeadFile));
    if (preg_match('/^ref: (.+)$/', $head, $m)) {
        $ref = __DIR__ . '/../.git/' . $m[1];
        if (is_readable($ref)) {
            $gitSha = substr(trim(@file_get_contents($ref)), 0, 12);
        }
    }
}

// API key check (if configured, require it for requests)
$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
if ($apiKeyRequired && !$providedKey) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'api_key_required'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($apiKeyRequired && $expectedApiKey !== null && $providedKey !== $expectedApiKey) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_api_key'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Read parameters (GET/POST), also accept JSON body for POST
$q = $_GET['q'] ?? $_POST['q'] ?? null;
$action = $_GET['action'] ?? $_POST['action'] ?? null;
$inputJson = null;
if (empty($q) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $inputJson = $decoded;
            if (isset($decoded['q'])) {
                $q = $decoded['q'];
            }
            if (isset($decoded['action'])) {
                $action = $decoded['action'];
            }
        }
    }
}

// Simple Action handler for client.d requests
class ActionHandler
{
    // Handle action and return an array to be json-encoded
    public function handle(string $action, $params = null): array
    {
        // Make the expectedApiKey available if present to decide client vs admin
        $expectedApiKey = $GLOBALS['expectedApiKey'] ?? null;

        switch ($action) {
            case 'echo':
                // returns whatever was sent
                return ['ok' => true, 'action' => 'echo', 'data' => $params];

            case 'status_check':
                // lightweight status check for clients
                return ['ok' => true, 'action' => 'status_check', 'time' => date('c')];

            // pc/get_state: used by clientd to read administrative 'state' and current_state
            case 'pc/get_state':
                // params expected: ['host' => '<hostname>'] or ['id' => <id>]
                $host = $params['host'] ?? $_GET['host'] ?? $_POST['host'] ?? null;
                $id = $params['id'] ?? $_GET['id'] ?? $_POST['id'] ?? null;
                if (empty($host) && empty($id)) {
                    return ['ok' => false, 'error' => 'missing host or id'];
                }
                if (!function_exists('db_get_pdo')) {
                    return ['ok' => false, 'error' => 'db helper not available'];
                }
                $pdo = db_get_pdo();
                if (!$pdo) return ['ok' => false, 'error' => 'db unavailable'];
                try {
                    if (!empty($id) && ctype_digit((string)$id)) {
                        $stmt = $pdo->prepare('SELECT * FROM computers WHERE id = ? LIMIT 1');
                        $stmt->execute([(int)$id]);
                    } else {
                        $stmt = $pdo->prepare('SELECT * FROM computers WHERE hostname = ? OR name = ? LIMIT 1');
                        $stmt->execute([$host, $host]);
                    }
                    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    if (!$row) return ['ok' => false, 'error' => 'computer not found'];
                    // return both admin state and current_state (if present)
                    $resp = [
                        'ok' => true,
                        'computer' => [
                            'id' => $row['id'] ?? null,
                            'name' => $row['name'] ?? ($row['hostname'] ?? null),
                            'state' => $row['state'] ?? $row['status'] ?? null,      // admin state
                            'current_state' => $row['current_state'] ?? null,       // actual state
                            'raw' => $row,
                        ],
                    ];
                    return $resp;
                } catch (Throwable $e) {
                    return ['ok' => false, 'error' => 'db error: '.$e->getMessage()];
                }

            // pc/set_state: used by clientd to report measured current_state.
            // Clients authenticated with API_KEY are allowed to write only 'current_state'.
            // Admin changes to the admin 'state' must go through admin endpoints (e.g. web/api/computers.php).
            case 'pc/set_state':
                // params may include: host, id, current_state (client report)
                $host = $params['host'] ?? $_GET['host'] ?? $_POST['host'] ?? null;
                $id = $params['id'] ?? $_GET['id'] ?? $_POST['id'] ?? null;
                $incoming_state = isset($params['state']) ? $params['state'] : (isset($_POST['state']) ? $_POST['state'] : null);
                $incoming_current = isset($params['current_state']) ? $params['current_state'] : (isset($_POST['current_state']) ? $_POST['current_state'] : null);

                if (empty($host) && empty($id)) {
                    return ['ok' => false, 'error' => 'missing host or id'];
                }
                if (!function_exists('db_get_pdo')) {
                    return ['ok' => false, 'error' => 'db helper not available'];
                }

                // Determine whether request is client-authenticated by API_KEY (valid)
                $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
                $clientAuth = false;
                if ($providedKey !== null && $expectedApiKey !== null) {
                    // use hash_equals if available
                    $clientAuth = function_exists('hash_equals') ? hash_equals((string)$expectedApiKey, (string)$providedKey) : ($expectedApiKey === $providedKey);
                }

                // If client-authenticated, reject any attempt to set admin 'state'
                if ($clientAuth && $incoming_state !== null) {
                    return ['ok' => false, 'error' => 'clients are not allowed to set admin state', 'code' => 'forbidden_client_write'];
                }

                // Only allow 'current_state' writes here (client reports)
                if ($incoming_current === null) {
                    // nothing for client to write; instruct to use admin endpoint if incoming_state provided but not allowed
                    return ['ok' => false, 'error' => 'missing current_state', 'note' => 'clients may only write current_state'];
                }

                // Proceed to update DB: prefer current_state column, fallback to state/status if needed
                $pdo = db_get_pdo();
                if (!$pdo) return ['ok' => false, 'error' => 'db unavailable'];
                try {
                    if (!empty($id) && ctype_digit((string)$id)) {
                        $stmt = $pdo->prepare('SELECT * FROM computers WHERE id = ? LIMIT 1');
                        $stmt->execute([(int)$id]);
                    } else {
                        $stmt = $pdo->prepare('SELECT * FROM computers WHERE hostname = ? OR name = ? LIMIT 1');
                        $stmt->execute([$host, $host]);
                    }
                    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    if (!$row) return ['ok' => false, 'error' => 'computer not found'];

                    $cols = array_map('strtolower', array_keys($row));
                    if (in_array('current_state', $cols, true)) {
                        $target = 'current_state';
                    } elseif (in_array('state', $cols, true)) {
                        $target = 'state';
                    } elseif (in_array('status', $cols, true)) {
                        $target = 'status';
                    } else {
                        return ['ok' => false, 'error' => 'no suitable column to write current_state'];
                    }

                    $u = $pdo->prepare("UPDATE `" . str_replace('`','', 'computers') . "` SET `$target` = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? LIMIT 1");
                    $u->execute([(string)$incoming_current, $row['id']]);
                    return ['ok' => true, 'updated_column' => $target, 'value' => $incoming_current];
                } catch (Throwable $e) {
                    return ['ok' => false, 'error' => 'db error: '.$e->getMessage()];
                }

            default:
                return ['ok' => false, 'error' => 'unknown_action', 'action' => $action];
        }
    }
}

// Handler for q endpoints
if ($q === 'ping') {
    echo json_encode(['status' => 'ok', 'time' => date('c')], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($q === 'status') {
    // Try to get system uptime (Linux) as an extra field, optional
    $uptime = null;
    if (is_readable('/proc/uptime')) {
        $parts = preg_split('/\s+/', trim(@file_get_contents('/proc/uptime')));
        if (isset($parts[0])) {
            $uptime = (float)$parts[0];
        }
    }
    $payload = [
        'status' => 'ok',
        'time' => date('c'),
        'name' => $appName,
        'version' => $appVersion,
        'git' => $gitSha,
        'uptime_seconds' => $uptime,
    ];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($q === 'version') {
    $payload = [
        'name' => $appName,
        'version' => $appVersion,
        'git' => $gitSha,
        'time' => date('c'),
    ];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle action=... (client.d)
if (!empty($action)) {
    $handler = new ActionHandler();
    // Prefer JSON body params if available, else GET/POST params
    $params = $inputJson['params'] ?? $_POST['params'] ?? $_GET['params'] ?? null;
    // If params is JSON string, attempt decode
    if (is_string($params)) {
        $decoded = json_decode($params, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $params = $decoded;
        }
    }
    $result = $handler->handle((string)$action, $params);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// Default: invalid request
http_response_code(400);
echo json_encode(['error' => 'invalid_request', 'usage' => '?q=ping|status|version or action=...'], JSON_UNESCAPED_UNICODE);
PHPAPI

# write new web/api/computers.php
cat > web/api/computers.php <<'PHPCOMPS'
<?php
// web/api/computers.php
//
// Minimal, secure API endpoint for controlling computers.
// Supports URLs like:
//   POST /api/computers/123/action
//   POST /api/computers/PC-01/action
//
// Expects JSON body with:
//   { "action": "set_state", "state": "frei", "occupied": "101" }
// or other actions (start/stop/restart) which will be forwarded to clientd as action scripts.
//
// Auth:
// - Incoming requests must provide Authorization: Bearer <API_SECRET>
//   API_SECRET is read from environment var API_SECRET or /etc/internetcafe-api.conf (key API_SECRET).
//
// Forwarding to client:
// - If the computers row has ip_address (or hostname resolvable) the server will attempt
//   to POST to client agent at http://<ip>:9999/action with Authorization: Bearer <CLIENTD_SECRET>
//   CLIENTD_SECRET is read from env CLIENTD_SECRET or /etc/internetcafe-clientd.conf.
//
// Security notes:
// - This endpoint normalizes and whitelists states to the canonical 7 values.
// - All DB operations use prepared statements.
// - Forwarding to clients times out quickly and failures are reported but do not break DB update.

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

function send_json(int $code, array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function load_conf_file(string $path): array {
    $cfg = [];
    if (!file_exists($path)) return $cfg;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] === '#') continue;
        if (strpos($ln, '=') !== false) {
            [$k,$v] = array_map('trim', explode('=', $ln, 2));
            $v = trim($v, "\"'");
            $cfg[$k] = $v;
        }
    }
    return $cfg;
}

function get_secret(string $envKey, string $confPath, string $confKey) {
    if (!empty(getenv($envKey))) return getenv($envKey);
    $cfg = load_conf_file($confPath);
    return $cfg[$confKey] ?? null;
}

function get_bearer_token_from_header(): ?string {
    $h = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $h = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (function_exists('apache_request_headers')) {
        $req = apache_request_headers();
        if (!empty($req['Authorization'])) $h = $req['Authorization'];
    }
    if (!$h) return null;
    if (preg_match('/^\s*Bearer\s+(.+)$/i', $h, $m)) return trim($m[1]);
    return null;
}

$allowed_states = ['starting','frei','gast','pause','wartung','stop','off'];

$api_secret = get_secret('API_SECRET', '/etc/internetcafe-api.conf', 'API_SECRET');
if (!$api_secret) {
    // fallback to environment
    $api_secret = getenv('API_SECRET') ?: null;
}
if (!$api_secret) {
    // misconfiguration
    send_json(500, ['ok'=>false,'error'=>'server misconfigured: API secret not set']);
}

// authenticate incoming request (Bearer token)
$token = get_bearer_token_from_header();
if (!$token) send_json(401, ['ok'=>false,'error'=>'missing Authorization Bearer token']);
if (!hash_equals((string)$api_secret, (string)$token)) send_json(403, ['ok'=>false,'error'=>'forbidden']);

// parse ID/hostname from request URI
$uri = $_SERVER['REQUEST_URI'] ?? '';
// strip query
$uri = explode('?', $uri, 2)[0];
// match /api/computers/{id_or_name}/action
if (!preg_match('#/api/computers/([^/]+)/action$#', $uri, $m)) {
    // also accept /api/computers.php?id=...
    if (!empty($_GET['id'])) {
        $target_raw = (string)$_GET['id'];
    } else {
        send_json(404, ['ok'=>false,'error'=>'invalid endpoint']);
    }
} else {
    $target_raw = $m[1];
}
$target_raw = urldecode($target_raw);

// read JSON body
$body = file_get_contents('php://input');
$data = [];
if ($body) {
    $data = json_decode($body, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        send_json(400, ['ok'=>false,'error'=>'invalid json']);
    }
}

// require action field
$action = isset($data['action']) ? (string)$data['action'] : '';
if ($action === '') {
    send_json(400, ['ok'=>false,'error'=>'missing action']);
}

// allowed generic actions: set_state OR forward action scripts (start/stop/restart)
$action = strtolower(trim($action));

// connect DB
$pdo = function_exists('db_get_pdo') ? db_get_pdo() : null;
if (!$pdo) send_json(500, ['ok'=>false,'error'=>'db unavailable']);

// find computer by id (numeric) or by hostname
$computer = null;
if (ctype_digit($target_raw)) {
    $stmt = $pdo->prepare('SELECT * FROM computers WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$target_raw]);
    $computer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} else {
    $stmt = $pdo->prepare('SELECT * FROM computers WHERE hostname = ? LIMIT 1');
    $stmt->execute([$target_raw]);
    $computer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$computer) {
    send_json(404, ['ok'=>false,'error'=>'computer not found']);
}

// prepare result structure
$result = [
    'ok' => false,
    'updated_db' => false,
    'forwarded' => false,
    'client_response' => null,
];

// handle set_state
if ($action === 'set_state') {
    $state_raw = isset($data['state']) ? (string)$data['state'] : '';
    $current_raw = isset($data['current_state']) ? (string)$data['current_state'] : null;

    if ($state_raw === '' && $current_raw === null) {
        send_json(400, ['ok'=>false,'error'=>'missing state or current_state']);
    }

    // normalize provided admin state if given
    $state = null;
    if ($state_raw !== '') {
        $state = strtolower(trim($state_raw));
        if ($state === 'stop' || strtoupper($state_raw) === 'STOP') $state = 'stop';
        if ($state === 'off' || strtoupper($state_raw) === 'OFF') $state = 'off';
        if (!in_array($state, $allowed_states, true)) {
            send_json(400, ['ok'=>false,'error'=>'invalid state', 'allowed'=>$allowed_states]);
        }
    }

    // optional occupied
    $occupied = isset($data['occupied']) ? $data['occupied'] : null;

    // update DB: prefer writing current_state if provided (client report),
    // otherwise write admin 'state' as before.
    try {
        if ($current_raw !== null) {
            // write to current_state if available, else fallback to state/status
            $cols = array_map('strtolower', array_keys($computer));
            if (in_array('current_state', $cols, true)) {
                $sql = 'UPDATE computers SET current_state = ?, updated_at = CURRENT_TIMESTAMP';
                $params = [$current_raw];
            } elseif (in_array('state', $cols, true)) {
                $sql = 'UPDATE computers SET state = ?, updated_at = CURRENT_TIMESTAMP';
                $params = [$current_raw];
            } elseif (in_array('status', $cols, true)) {
                $sql = 'UPDATE computers SET status = ?, updated_at = CURRENT_TIMESTAMP';
                $params = [$current_raw];
            } else {
                send_json(500, ['ok'=>false,'error'=>'no suitable column to store current_state']);
            }
            if ($occupied !== null && $occupied !== '') {
                $sql .= ', occupied_by = ?';
                $params[] = $occupied;
            }
            $sql .= ' WHERE id = ?';
            $params[] = $computer['id'];
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $result['updated_db'] = ($stmt->rowCount() >= 0);
        } else {
            // admin write path (existing behavior)
            $sql = 'UPDATE computers SET state = ?, updated_at = CURRENT_TIMESTAMP';
            $params = [$state];
            if ($occupied !== null && $occupied !== '') {
                $sql .= ', occupied_by = ?';
                $params[] = $occupied;
            }
            $sql .= ' WHERE id = ?';
            $params[] = $computer['id'];
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $result['updated_db'] = ($stmt->rowCount() >= 0);
        }
    } catch (Throwable $e) {
        send_json(500, ['ok'=>false,'error'=>'db update failed','detail'=>$e->getMessage()]);
    }

    // attempt to forward to client agent if ip_address present (unchanged)
    $client_ip = $computer['ip_address'] ?? $computer['hostname'] ?? null;
    if ($client_ip) {
        // build payload to client; clientd expects action script names (we use set_state)
        $payload = ['action' => 'set_state'];
        if ($current_raw !== null) {
            // forward current_state report to client only if desired (usually clients report)
            $payload['current_state'] = $current_raw;
        } elseif ($state !== null) {
            $payload['state'] = $state;
        }
        if ($occupied !== null && $occupied !== '') $payload['occupied'] = (string)$occupied;
        // read client secret
        $clientd_secret = get_secret('CLIENTD_SECRET', '/etc/internetcafe-clientd.conf', 'CLIENTD_SECRET') ?: getenv('CLIENTD_SECRET') ?: null;

        // try numeric ip or hostname; default port 9999 (clientd default)
        $client_port = 9999;
        $url = (strpos($client_ip, ':') !== false && substr_count($client_ip, ':') === 1) ? "http://{$client_ip}/action" : "http://{$client_ip}:{$client_port}/action";

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, true);
        $json = json_encode($payload);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 5);
        $headers = ['Content-Type: application/json'];
        if ($clientd_secret) $headers[] = 'Authorization: Bearer ' . $clientd_secret;
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $resp = curl_exec($curl);
        $err = curl_error($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($resp !== false && $http_code >= 200 && $http_code < 300) {
            $result['forwarded'] = true;
            // try decode JSON
            $dec = json_decode($resp, true);
            $result['client_response'] = $dec !== null ? $dec : $resp;
        } else {
            $result['forwarded'] = false;
            $result['client_response'] = ['error' => $err ?: ('HTTP ' . $http_code), 'raw' => $resp];
        }
    }

    $result['ok'] = true;
    send_json(200, $result);
}

// handle direct actions forwarded to clients (start/stop/restart)
$forward_actions = ['start','stop','restart'];
if (in_array($action, $forward_actions, true)) {
    // attempt to forward to client (no DB change)
    $client_ip = $computer['ip_address'] ?? $computer['hostname'] ?? null;
    if (!$client_ip) send_json(400, ['ok'=>false,'error'=>'no client ip/hostname available']);

    $payload = ['action' => $action];
    if (isset($data['reason'])) $payload['reason'] = $data['reason'];

    $clientd_secret = get_secret('CLIENTD_SECRET', '/etc/internetcafe-clientd.conf', 'CLIENTD_SECRET') ?: getenv('CLIENTD_SECRET') ?: null;

    $client_port = 9999;
    $url = (strpos($client_ip, ':') !== false && substr_count($client_ip, ':') === 1) ? "http://{$client_ip}/action" : "http://{$client_ip}:{$client_port}/action";

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POST, true);
    $json = json_encode($payload);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 5);
    $headers = ['Content-Type: application/json'];
    if ($clientd_secret) $headers[] = 'Authorization: Bearer ' . $clientd_secret;
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

    $resp = curl_exec($curl);
    $err = curl_error($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($resp !== false && $http_code >= 200 && $http_code < 300) {
        $result['forwarded'] = true;
        $dec = json_decode($resp, true);
        $result['client_response'] = $dec !== null ? $dec : $resp;
    } else {
        $result['forwarded'] = false;
        $result['client_response'] = ['error' => $err ?: ('HTTP ' . $http_code), 'raw' => $resp];
    }

    $result['ok'] = true;
    send_json(200, $result);
}

// fallback
send_json(400, ['ok'=>false,'error'=>'unsupported action']);
PHPCOMPS

echo "Files written. Now running checks..."

# run syntax checks
echo "Running PHP syntax checks..."
find web -name '*.php' -print0 | xargs -0 -n1 php -l || true

echo "Running Python compile checks..."
find . -name '*.py' -print0 | xargs -0 -n1 python3 -m py_compile || true

if command -v flake8 >/dev/null 2>&1; then
  echo "Running flake8..."
  flake8 --max-line-length=79 clients || true
else
  echo "flake8 not installed — skipping flake8 check."
fi

# show git diff
echo "---- git diff ----"
git add -A
git --no-pager diff --staged
read -r -p "Commit these changes? [y/N] " ans2
if [[ "${ans2,,}" != "y" ]]; then
  echo "Aborting commit. Reverting staged changes."
  git reset --hard
  exit 1
fi

git commit -m "Fix: clientd/greeter + API: enforce client current_state writes, lint fixes"
git push -u origin "$BRANCH"

echo "Done. Changes pushed to branch $BRANCH."
echo "Please open a PR on GitHub from this branch and review the changes."
