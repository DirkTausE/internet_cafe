#!/usr/bin/env python3
# clients/greeter_neu.py
# Best-effort greeter control helper for InternetCafe.
#
# This module implements simple functions to:
#  - enable_guest_access(): allow guest login (LightDM)
#  - disable_guest_access(): disallow guest login
#  - show_maintenance_prompt(): try to force greeter to show login prompt
#  - clear_maintenance_prompt(): undo maintenance hint
#
# Notes / Safety:
# - The code currently supports LightDM (common on many distros). For other
#   display managers it logs a warning and returns False.
# - Most operations require root (writing /etc and restarting display manager).
# - Behavior is intentionally conservative: it writes one small config file
#   in /etc/lightdm/lightdm.conf.d/ and restarts LightDM only when needed.
# - If you prefer a different approach (dbus, gdm, sddm) tell me and I
#   adapt the implementation.

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
MAINT_FLAG = Path("/var/run/internetcafe_maintenance")  # marker file for maintenance mode


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
        return False, f"restart failed: {p.stderr.decode().strip()}"
    except Exception as e:
        return False, f"exception: {e}"


def _write_lightdm_conf(allow_guest: bool) -> Tuple[bool, str]:
    """
    Ensure the conf directory and file exist and contain the minimal option.
    """
    try:
        LIGHTDM_CONF_DIR.mkdir(parents=True, exist_ok=True)
        content = "[Seat:*]\nallow-guest={}\n".format("true" if allow_guest else "false")
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
    # Only support LightDM for now
    if not Path("/usr/sbin/lightdm").exists() and not Path("/usr/bin/lightdm").exists():
        LOG.warning("LightDM not detected; enable_guest_access unsupported.")
        return False
    ok, msg = _write_lightdm_conf(True)
    if not ok:
        LOG.error("Failed to write LightDM config: %s", msg)
        return False
    ok2, msg2 = _restart_lightdm()
    if not ok2:
        LOG.error("Failed to restart LightDM: %s", msg2)
        # even if restart failed, config was written; return depending on needs
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
    if not Path("/usr/sbin/lightdm").exists() and not Path("/usr/bin/lightdm").exists():
        LOG.warning("LightDM not detected; disable_guest_access unsupported.")
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


def show_maintenance_prompt(message: str = "Wartung. Anmeldung erforderlich.") -> bool:
    """
    Indicate a maintenance situation. Creates a marker file and attempts to
    disable guest access so greeter shows the login prompt.
    Optionally you can display a message (requires greeter support).
    """
    LOG.info("Activating maintenance mode.")
    if not _is_root():
        LOG.warning("show_maintenance_prompt requires root privileges.")
        return False
    # disable guest access so greeter shows login prompt
    if not disable_guest_access():
        LOG.warning("Failed to ensure guest access disabled.")
    try:
        MAINT_FLAG.write_text(message, encoding="utf-8")
    except Exception as e:
        LOG.warning("Could not write maintenance flag: %s", e)
    # restart lightdm to ensure greeter reloads (best-effort)
    ok, msg = _restart_lightdm()
    if not ok:
        LOG.warning("Failed to restart LightDM after maintenance flag: %s", msg)
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
    # restart greeter to reload state
    ok, msg = _restart_lightdm()
    if not ok:
        LOG.warning("Failed to restart LightDM after clearing maintenance: %s", msg)
    LOG.info("Maintenance prompt cleared.")
    return True
