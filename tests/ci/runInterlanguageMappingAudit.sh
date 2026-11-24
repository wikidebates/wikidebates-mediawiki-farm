#!/usr/bin/env bash
# Read-only database audit; intended to run as the wiki's ordinary Unix user.
set -euo pipefail
umask 077

root=$(cd -- "$(dirname -- "$0")/../.." && pwd -P)
state="$HOME/interlanguage-audits"
mkdir -p -- "$state"
chmod 700 -- "$state"

exec 9>"$state/.lock"
if ! flock -n 9; then
	echo "Interlanguage audit already running; skipped." >&2
	exit 0
fi

stamp=$(date -u +%Y%m%dT%H%M%SZ)
report=$(mktemp "$state/.report.XXXXXX")
log=$(mktemp "$state/.log.XXXXXX")
trap 'rm -f -- "$report" "$log"' EXIT

# Use the same PHP CLI and configuration as the regular maintenance commands.
# No public API requests, parsing, cache purges, edits or cleanup.
cd "$root"
if python3 tests/ci/interlanguageMappingAudit.py --examples 5 --report "$report" >"$log" 2>&1 &&
	test -s "$report" &&
	python3 -c 'import json, sys; data = json.load(open(sys.argv[1])); assert isinstance(data["counts"], dict) and isinstance(data["issues"], dict)' "$report"
then
	mv -- "$report" "$state/audit-$stamp.json"
	mv -- "$log" "$state/audit-$stamp.log"
	printf 'Interlanguage audit complete: %s\n' "$state/audit-$stamp.json"
else
	mv -- "$log" "$state/failed-$stamp.log"
	printf 'Interlanguage audit failed: %s\n' "$state/failed-$stamp.log" >&2
	exit 1
fi
