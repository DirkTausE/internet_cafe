#!/usr/bin/env bash
read -r PAYLOAD
echo "Payload: $PAYLOAD" >&2
mkdir -p /tmp/clientd-demo
echo "restarted at $(date -Iseconds)" > /tmp/clientd-demo/restarted.txt
exit 0