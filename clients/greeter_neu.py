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
        LOG.warning("LightDM not detected; enable_guest_access unsupported.")
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
        LOG.warning("Failed to restart LightDM after maintenance: %s", msg)

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
        LOG.warning("Cant restart LightDM after clearing maintenance: %s", msg)

    LOG.info("Maintenance prompt cleared.")
    return True
