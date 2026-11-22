#!/bin/sh
# Read-only PageForms category and SMW mapping timings on a real wiki.
set -eu

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/../../../.." && pwd)"
WIKI="${PF_BENCH_WIKI:-fr}"
CATEGORY="${PF_BENCH_CATEGORY:-}"
QUERY="${PF_BENCH_QUERY:-}"
DEPTH="${PF_BENCH_DEPTH:-3}"
ROUNDS="${PF_BENCH_ROUNDS:-5}"

if [ -z "$CATEGORY" ] || [ -z "$QUERY" ]; then
	printf '%s\n' "Set PF_BENCH_CATEGORY and PF_BENCH_QUERY before running." >&2
	exit 2
fi

cd "$ROOT"
# MW 1.43 maintenance/eval.php evaluates each input line independently.
# Load the complete PHP benchmark through one simple require expression.
PF_BENCH_SCRIPT="$ROOT/extensions/PageFormsWikidebates/tests/benchmarks/autocomplete.php"
export PF_BENCH_SCRIPT PF_BENCH_CATEGORY PF_BENCH_QUERY PF_BENCH_DEPTH PF_BENCH_ROUNDS

printf 'require getenv( "PF_BENCH_SCRIPT" );\n' |
	php maintenance/run.php eval --wiki="$WIKI"
