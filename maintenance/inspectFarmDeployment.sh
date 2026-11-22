#!/usr/bin/env bash
# Read-only inspection. No fetch, checkout, update, purge, or database operations.
set -euo pipefail
export GIT_OPTIONAL_LOCKS=0

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
root=$(CDPATH= cd -- "$script_dir/.." && pwd -P)

if ! git -C "$root" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    printf 'ERROR: %s is not a Git working tree.\n' "$root" >&2
    exit 2
fi
if [[ "$(git -C "$root" rev-parse --show-toplevel)" != "$root" ]]; then
    printf 'ERROR: inspector must be inside the checkout root.\n' >&2
    exit 2
fi

printf 'Wikidebates checkout (read-only inspection)\n'
printf 'Root: %s\n' "$root"
printf 'Git branch: %s\n' "$(git -C "$root" symbolic-ref --short -q HEAD || printf 'DETACHED')"
printf 'Git HEAD: %s\n' "$(git -C "$root" rev-parse HEAD)"
if [[ -f "$root/includes/Defines.php" ]]; then
    version=$(sed -n "s/.*define( 'MW_VERSION', '\([^']*\)' ).*/\1/p" "$root/includes/Defines.php" | head -n 1)
    if [[ -z "$version" ]]; then version=UNKNOWN; fi
    printf 'MediaWiki version in checkout: %s\n' "$version"
fi

modified=$(git -C "$root" status --porcelain --untracked-files=no | wc -l | tr -d '[:space:]')
printf 'Tracked changes in checkout: %s\n' "$modified"

count=0
ok=0
missing=0
optional_missing=0
drift=0

# docker-compose-ci is a build/test helper used by CrawlerProtection's Makefile.
# It is not required for MediaWiki runtime. Always report it but do not
# fail an otherwise healthy production checkout solely because it is absent.
report_uninitialized() {
    local path="$1" expected="$2"
    if [[ "$path" == 'extensions/CrawlerProtection/build' ]]; then
        printf 'OPTIONAL_UNINITIALIZED %s (build/test only; expected %s)\n' "$path" "$expected"
        optional_missing=$((optional_missing + 1))
    else
        printf 'UNINITIALIZED %s (expected %s)\n' "$path" "$expected"
        missing=$((missing + 1))
    fi
}

printf '\nSubmodule gitlinks versus installed checkout:\n'
while read -r mode expected stage path; do
    [[ "$mode" == '160000' ]] || continue
    count=$((count + 1))
    directory="$root/$path"
    if [[ ! -d "$directory" ]]; then
        report_uninitialized "$path" "$expected"
        continue
    fi
    actual_root=$(git -C "$directory" rev-parse --show-toplevel 2>/dev/null || true)
    if [[ "$actual_root" != "$directory" ]]; then
        report_uninitialized "$path" "$expected"
        continue
    fi
    actual=$(git -C "$directory" rev-parse HEAD 2>/dev/null || true)
    if [[ "$actual" != "$expected" ]]; then
        printf 'MISMATCH %s expected=%s installed=%s\n' "$path" "$expected" "$actual"
        drift=$((drift + 1))
    else
        ok=$((ok + 1))
    fi
done < <(git -C "$root" ls-files --stage)
printf 'Submodules: %d total, %d matching, %d required uninitialized, %d optional uninitialized, %d mismatched\n' "$count" "$ok" "$missing" "$optional_missing" "$drift"

required_missing=0
for path in extensions/Flow/extension.json extensions/Interlanguage/extension.json extensions/PageFormsWikidebates/extension.json extensions/Wikidebates/src/AutoParentLink.php; do
    if [[ -f "$root/$path" ]]; then
        printf 'PRESENT %s\n' "$path"
    else
        printf 'MISSING %s\n' "$path"
        required_missing=$((required_missing + 1))
    fi
done

printf '\nThis report does not inspect LocalSettings.php, production config, database, cron, jobs, or running PHP workers.\n'
printf 'It does not fetch Git history, install submodules, or execute MediaWiki maintenance.\n'
if (( modified > 0 || missing > 0 || drift > 0 || required_missing > 0 )); then
    printf 'REVIEW REQUIRED: checkout changes, missing components or submodule drift detected.\n'
    exit 1
fi
printf 'GIT CHECK OK: tracked files and required initialized submodules match this checkout HEAD.\n'
if (( optional_missing > 0 )); then
    printf 'NOTE: optional CrawlerProtection Docker build/test submodule is absent; MediaWiki runtime does not require it.\n'
fi
