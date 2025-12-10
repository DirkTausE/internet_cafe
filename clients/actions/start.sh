#!/usr/bin/env bash
# Example start action - adapt to your environment.
# Receives JSON on stdin (payload). You can parse it if needed.
read -r PAYLOAD
echo "Payload: $PAYLOAD" >&2
# Example: touch a file to indicate 'started'
mkdir -p /tmp/clientd-demo
echo "started at $(date -Iseconds)" > /tmp/clientd-demo/started.txt
exit 0