#!/bin/sh
# Safe staged overlay for a dedicated WikiSEO checkout. No production writes.
set -eu

usage() {
	echo "Usage: $0 [--check|--apply] /path/to/WikiSEO" >&2
	exit 2
}

mode="${1:-}"
target="${2:-}"
[ -n "$target" ] || usage
case "$mode" in
	--check|--apply) ;;
	*) usage ;;
esac

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
overlay="$script_dir/overrides"
[ -d "$target" ] && [ -f "$target/extension.json" ] || {
	echo "ERROR: destination must be an existing WikiSEO checkout" >&2
	exit 1
}

commit=$(git -C "$target" rev-parse HEAD 2>/dev/null) || {
	echo "ERROR: destination is not a Git checkout" >&2
	exit 1
}
case "$commit" in
	3b135415f9fe0eff4d23659b4143bd50009238ab|0496485749063be68fbe3a3aa1bd444979ec262a) ;;
	*)
		echo "ERROR: WikiSEO baseline SHA is not approved: $commit" >&2
		exit 1
		;;
esac

branch=$(git -C "$target" symbolic-ref --short HEAD 2>/dev/null || true)
case "$branch" in
	feature/*|fix/*|chore/*) ;;
	*)
		echo "ERROR: select a dedicated feature/fix/chore branch in WikiSEO first" >&2
		exit 1
		;;
esac

if [ -n "$(git -C "$target" status --porcelain)" ]; then
	echo "ERROR: the target WikiSEO checkout must be clean" >&2
	exit 1
fi

count=$(find "$overlay" -type f | wc -l | tr -d ' ')
echo "WikiSEO base: $commit"
echo "Target branch: $branch"
echo "Staged files: $count"
echo "Mode: $mode"

[ "$mode" = "--apply" ] || exit 0

find "$overlay" -type f | while IFS= read -r file; do
	relative=${file#"$overlay"/}
	mkdir -p "$target/$(dirname -- "$relative")"
	cp -- "$file" "$target/$relative"
	echo "APPLIED: $relative"
done

echo "Review the diff, run tests, commit on the feature branch, then open a PR."
