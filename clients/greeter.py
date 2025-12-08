#!/usr/bin/env python3
# clients/greeter.py - example greeter script (cleaned for lint)

import logging
import socket
import sys
from typing import Optional

LOG = logging.getLogger("greeter")
LOG.addHandler(logging.StreamHandler())
LOG.setLevel(logging.INFO)


def get_hostname() -> str:
    try:
        return socket.gethostname()
    except Exception:
        return "unknown"


def greet(name: Optional[str] = None) -> str:
    host = get_hostname()
    who = name or "guest"
    return f"Hello {who} from {host}"


def main(argv: list) -> int:
    name = argv[1] if len(argv) > 1 else None
    msg = greet(name)
    print(msg)
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))