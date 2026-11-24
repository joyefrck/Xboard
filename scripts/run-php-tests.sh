#!/bin/sh
# Runs every isolated PHP test. New tests/*.php files are picked up automatically.
set -u
cd "$(dirname "$0")/.."

# Not standalone: driven by a dedicated CI step (database or REALITY binaries) or only a fixture.
needs_own_step="account-session-security-concurrency online-status-locking-concurrency security-p0-concurrency node-automation-reality-runtime online-status-fixture"

failed=""
count=0
log=$(mktemp)
trap 'rm -f "$log"' EXIT
for file in tests/*.php; do
  name=$(basename "$file" .php)
  case " $needs_own_step " in *" $name "*) continue ;; esac
  count=$((count + 1))
  if php "$file" >"$log" 2>&1; then
    echo "ok   $name"
  else
    echo "FAIL $name"
    tail -n 25 "$log"
    failed="$failed $name"
  fi
done
echo "$count PHP test files executed"
[ -z "$failed" ] || { echo "Failed:$failed"; exit 1; }
