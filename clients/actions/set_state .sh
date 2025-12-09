#!/usr/bin/env bash
# clients/actions/set_state.sh
# Example safe handler for clientd's "set_state" action.
# Reads JSON payload from stdin and writes a small local state file for persistence.
# Do NOT allow arbitrary command execution here — keep script simple and auditable.

set -euo pipefail

TMPDIR="/tmp/clientd-demo"
mkdir -p "$TMPDIR"
PAYLOAD_FILE="$TMPDIR/last-set-state.json"

# read all stdin
payload="$(cat -)"

# write payload for debugging
echo "$payload" > "$PAYLOAD_FILE"
chmod 600 "$PAYLOAD_FILE" || true

# Try to extract "state" and "occupied" with jq if present, else fallback to plain parsing.
state=''
occupied=''

if command -v jq >/dev/null 2>&1; then
  state=$(jq -r '.state // empty' 2>/dev/null <<<"$payload" || true)
  occupied=$(jq -r '.occupied // empty' 2>/dev/null <<<"$payload" || true)
else
  # simple grep heuristics
  state=$(printf '%s' "$payload" | sed -n 's/.*"state"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' || true)
  occupied=$(printf '%s' "$payload" | sed -n 's/.*"occupied"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' || true)
fi

# Normalize to expected canonical states (lowercase)
state=$(printf '%s' "$state" | tr '[:upper:]' '[:lower:]')

# Persist a simple state file (owner-root recommended)
STATE_FILE="/var/lib/clientd/state.json"
mkdir -p "$(dirname "$STATE_FILE")"
cat > "${STATE_FILE}.tmp" <<JSON
{
  "state": $(jq -R -s '.' <<<"${state:-}"),
  "occupied": $(jq -R -s '.' <<<"${occupied:-}"),
  "payload": $(jq -R -s '.' <<<"$payload"),
  "updated": "$(date -Iseconds)"
}
JSON

# move atomically
mv -f "${STATE_FILE}.tmp" "$STATE_FILE"
chmod 600 "$STATE_FILE" || true

# also log to syslog if logger exists
if command -v logger >/dev/null 2>&1; then
  logger -t clientd "set_state applied: state=${state:-'(none)'} occupied=${occupied:-'(none)'}"
fi

# exit 0 for success
exit 0