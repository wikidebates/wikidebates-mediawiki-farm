#!/usr/bin/env bash
# Use the existing extension test suites, against the farm's actual gitlinks.
# Never execute this laboratory on a live wiki.
set -Eeuo pipefail
[[ "${CI:-}" == true && "${GITHUB_ACTIONS:-}" == true && -n "${RUNNER_TEMP:-}" ]] ||
	{ echo "Disposable GitHub Actions runner required" >&2; exit 2; }
test ! -e LocalSettings.php
export MW_INSTALL_PATH="$PWD"
export WD_TEST_EXTENSIONS=WikiSEO
wd="$PWD/extensions/Wikidebates"
seo="$PWD/extensions/WikiSEO"
export WD_SEO_LAB_BOOTSTRAP="$wd/tests/mediawikiEnvironment.php"
for path in extensions/Wikidebates extensions/WikiSEO extensions/ShortDescription; do
	expected=$(git ls-files --stage -- "$path" | awk '$1 == "160000" {print $2}')
	test -n "$expected"
	test "$(git -C "$path" rev-parse HEAD)" = "$expected"
	test -z "$(git -C "$path" status --porcelain)"
	printf 'SEO_PIN %s %s\n' "$path" "$expected"
done
php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 ? 0 : 1);'

# Fail on semantic/compatibility errors before measuring performance.
for test in seoCoexistence seoCacheInvalidation seoPublicationDate seoStructuredData mediawiki seoBenchmark; do
	php -d opcache.enable_cli=0 -d display_errors=1 -d error_reporting=E_ALL "$wd/tests/$test.php"
done
for test in canonical parser website robots; do
	php -d opcache.enable_cli=0 -d display_errors=1 -d error_reporting=E_ALL "$seo/tests/integration/$test.php"
done
for lang in fr en; do
	WD_TEST_LANGUAGE="$lang" WD_TEST_EXTENSIONS=WikiSEO,ShortDescription \
		php -d opcache.enable_cli=0 -d display_errors=1 -d error_reporting=E_ALL "$seo/tests/integration/short_description.php"
done
for lang in fr en de es it pt; do
	WD_TEST_LANGUAGE="$lang" \
		php -d opcache.enable_cli=0 -d display_errors=1 -d error_reporting=E_ALL "$seo/tests/integration/hreflang.php"
done

# Historical reference: the two SEO gitlinks of farm f0e2bf8a7fd3
# (MediaWiki 1.43.12), not an assertion about the current live server.
reference_wd=21d0c0d27ac72b9392d859d695c56230a868d772
reference_seo=ca976844f6a8c038080684cf566aaaa4093cef9c
candidate_seo=$(git -C "$seo" rev-parse HEAD)
candidate_wd=$(git -C "$wd" rev-parse HEAD)
lab=$(mktemp -d "$RUNNER_TEMP/seo-pair.XXXXXXXX")
before="$lab/reference-wd"
cleanup() {
	git -C "$seo" checkout -q --detach "$candidate_seo"
	if [[ -d "$before" ]]; then
		git -C "$wd" worktree remove "$before"
	fi
}
trap cleanup EXIT
git -C "$wd" -c 'url.https://github.com/.insteadOf=git@github.com:' fetch --no-tags --depth=1 origin "$reference_wd"
git -C "$wd" worktree add -q --detach "$before" "$reference_wd"
git -C "$seo" fetch --no-tags --depth=1 origin "$reference_seo"
# Identical benchmark source for both pairs; the historical bootstrap loads
# its own Wikidebates extension, the current bootstrap loads the candidate.
cp "$seo/tests/integration/combinedBenchmark.php" "$lab/combinedBenchmark.php"
results="$lab/results.jsonl"
printf 'SEO_PAIR reference %s %s\n' "$reference_wd" "$reference_seo"
printf 'SEO_PAIR candidate %s %s\n' "$candidate_wd" "$candidate_seo"
for round in 1 2; do
	if [[ "$round" == 1 ]]; then
		versions=(baseline optimized)
	else
		versions=(optimized baseline)
	fi
	for version in "${versions[@]}"; do
		target="$candidate_seo"
		bootstrap="$wd/tests/mediawikiEnvironment.php"
		if [[ "$version" == baseline ]]; then
			target="$reference_seo"
			bootstrap="$before/tests/mediawikiEnvironment.php"
		fi
		git -C "$seo" checkout -q --detach "$target"
		test "$(git -C "$seo" rev-parse HEAD)" = "$target"
		for case in ordinary-empty ordinary-persisted ordinary-text ordinary-local-file ordinary-separate-x native-debate native-argument native-hreflang parser; do
			WD_SEO_LAB_BOOTSTRAP="$bootstrap" \
				php -d opcache.enable_cli=0 -d display_errors=1 -d error_reporting=E_ALL \
				"$lab/combinedBenchmark.php" "$case" "$version" "$round" | tee -a "$results"
		done
	done
done
python3 tests/ci/summarizeSeoReleaseBenchmark.py "$results" | tee -a "$GITHUB_STEP_SUMMARY"
