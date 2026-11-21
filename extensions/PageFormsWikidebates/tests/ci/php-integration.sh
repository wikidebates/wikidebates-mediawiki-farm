#!/bin/bash
# Disposable test wiki only. Never run this script in a production checkout.
set -eu
if [ "${CI:-}" != "true" ]; then
	echo "This bootstrap is restricted to a disposable CI checkout."
	exit 1
fi
root="$(pwd)"
config="$(mktemp -d)"
mkdir -p "$config/cache"
composer install --no-interaction --prefer-dist --no-progress
php maintenance/run.php install \
	--confpath "$config" \
	--dbtype sqlite \
	--dbpath "$config" \
	--dbname pf_ci \
	--server http://example.test \
	--scriptpath /w \
	--lang en \
	--pass 'Disposable-CI-Password-2026' \
	'PageForms CI' Admin
cat >> "$config/LocalSettings.php" <<'PHP'
$wgMainCacheType = CACHE_NONE;
$wgParserCacheType = CACHE_NONE;
$wgMessageCacheType = CACHE_NONE;
$wgJobRunRate = 0;
wfLoadExtension( 'PageFormsWikidebates' );
PHP
if [ "${PF_CI_ENABLE_SMW:-}" = "1" ]; then
	cat >> "$config/LocalSettings.php" <<'PHP'
wfLoadExtension( 'SemanticMediaWiki' );
enableSemantics( 'example.test' );
PHP
fi
export MW_CONFIG_FILE="$config/LocalSettings.php"
php maintenance/run.php update --quick
vendor/bin/phpunit --filter 'PF(FluidEditing|ValuesUtilsRegression|MappingUtilsRegression|AutoeditAPICompatibility|ResourceLoaderDependency)Test' \
	extensions/PageFormsWikidebates/tests/phpunit

# Exercise the actual single-line eval bridge, not just PHP syntax.
# The benchmark is read-only, and the test wiki/category contain no real data.
echo "=== PageForms benchmark CLI regression ==="
benchmark_output="$(
	PF_BENCH_WIKI=pf_ci \
	PF_BENCH_CATEGORY='PFBenchmarkNonexistentCategory' \
	PF_BENCH_QUERY='UnlikelyBenchmarkTerm' \
	PF_BENCH_DEPTH=1 \
	PF_BENCH_ROUNDS=1 \
	sh extensions/PageFormsWikidebates/tests/benchmarks/autocomplete.sh
)"
printf '%s\n' "$benchmark_output"
printf '%s\n' "$benchmark_output" | grep -q 'runs=1'
printf '%s\n' "$benchmark_output" | grep -q 'median='
