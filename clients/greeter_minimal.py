#!/usr/bin/env python3
# Minimal greeter for testing

import socket
import sys


def main(argv):
    name = argv[1] if len(argv) > 1 else "guest"
    print(f"Hello {name} from {socket.gethostname()}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))