#!/usr/bin/env bash
read -r PAYLOAD
echo "Payload: $PAYLOAD" >&2
mkdir -p /tmp/clientd-demo
echo "stopped at $(date -Iseconds)" > /tmp/clientd-demo/stopped.txt
exit 0