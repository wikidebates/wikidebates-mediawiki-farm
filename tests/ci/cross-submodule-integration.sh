#!/usr/bin/env bash
# Run only in a disposable GitHub Actions checkout. NEVER in a deployed wiki.
set -euo pipefail
if [[ "${CI:-}" != "true" || "${GITHUB_ACTIONS:-}" != "true" ]]; then
    echo "Refusing to run outside disposable GitHub Actions." >&2
    exit 2
fi
if [[ ! -f ./includes/Defines.php || ! -d ./extensions/PageFormsWikidebates ]]; then
    echo "Expected MediaWiki farm checkout at repository root." >&2
    exit 2
fi
for path in \
    extensions/Wikidebates \
    extensions/VEForAll \
    extensions/VisualEditor \
    extensions/Echo \
	extensions/AbuseFilter; do
    expected=$(git ls-files --stage -- "$path" | awk '$1 == "160000" { print $2 }')
    actual=$(git -C "$path" rev-parse HEAD 2>/dev/null || true)
    if [[ -z "$expected" || "$actual" != "$expected" ]]; then
        echo "Pinned submodule checkout mismatch: $path expected=$expected actual=$actual" >&2
        exit 1
    fi
    echo "PINNED $path $actual"
done
# MinervaNeue is a private fork. The default Actions token for the farm
# cannot clone another private repository; verify only the pinned gitlink.
minerva_pin=$(git ls-files --stage -- skins/MinervaNeue | awk '$1 == "160000" { print $2 }')
if [[ ! "$minerva_pin" =~ ^[a-f0-9]{40}$ ]]; then
    echo "Missing or invalid pinned MinervaNeue gitlink." >&2
    exit 1
fi
echo "PINNED PRIVATE skins/MinervaNeue $minerva_pin (source not tested)"
test ! -d extensions/VEForAllMobileDeps

# No database, user data or cache from FR/EN is read or modified.
php -l extensions/Wikidebates/src/AutoParentLink.php
php extensions/Wikidebates/tests/autoParentLinkSyntax.php
php -l extensions/VEForAll/includes/VEForAllHooks.php
php -l extensions/Flow/includes/Hooks.php
php -l extensions/AbuseFilter/includes/SpecsFormatter.php
php -l extensions/AbuseFilter/includes/Parser/AbuseFilterTokenizer.php
php -l extensions/AbuseFilter/includes/View/AbuseFilterViewRevert.php
php -l extensions/AbuseFilter/includes/View/AbuseFilterViewTestBatch.php
node --check extensions/Wikidebates/modules/common/parse.js

config=$(mktemp -d)
trap 'rm -rf "$config"' EXIT
mkdir -p "$config/cache"
# Refuse a stale Composer lock or an accidentally retained execution dependency.
composer validate --no-check-all --no-check-publish
composer install --no-interaction --prefer-dist --no-progress
for path in extensions/Scribunto extensions/VariablesLua; do
	test -z "$(git ls-files --stage -- "$path")"
	test ! -e "$path/extension.json"
done
php -r 'require "vendor/autoload.php"; if (\Composer\InstalledVersions::isInstalled("mediawiki/semantic-scribunto") || is_file("extensions/SemanticScribunto/extension.json")) { fwrite(STDERR, "Unexpected Lua execution dependency\n"); exit(1); }'
# Exercise the three native hover modes against the exact Composer lock, without Lua.
php tests/ci/nativeArgumentMap.php
php tests/ci/nativeArgumentMap.php --archive
php maintenance/run.php install \
    --confpath "$config" \
    --dbtype sqlite \
    --dbpath "$config" \
    --dbname wikidebates_farm_test \
    --server http://example.test \
    --scriptpath /w \
    --lang en \
    --pass 'Disposable-Farm-Test-2026' \
    'Farm integration CI' Admin
cat >> "$config/LocalSettings.php" <<'PHP'
$wgMainCacheType = CACHE_NONE;
$wgParserCacheType = CACHE_NONE;
$wgMessageCacheType = CACHE_NONE;
$wgJobRunRate = 0;
wfLoadExtension( 'SemanticMediaWiki' );
enableSemantics( 'example.test' );
wfLoadExtension( 'VisualEditor' );
wfLoadExtension( 'VEForAll' );
wfLoadExtension( 'PageFormsWikidebates' );
wfLoadExtension( 'Flow' );
wfLoadExtension( 'Wikidebates' );
wfLoadExtension( 'AbuseFilter' );
PHP
export MW_CONFIG_FILE="$config/LocalSettings.php"
# Flow ships abstract schema JSON but no pre-generated SQLite SQL file.
# Generate the schema only in this disposable CI checkout before running update.php.
php maintenance/run.php generateSchemaSql \
    --json extensions/Flow/sql/tables.json \
    --sql extensions/Flow/sql \
    --type sqlite
test -s extensions/Flow/sql/sqlite/tables-generated.sql
php maintenance/run.php update --quick
vendor/bin/phpunit tests/phpunit/integration/WikidebatesFarmCombinedExtensionTest.php
vendor/bin/phpunit \
    --filter 'PF(FluidEditing|ValuesUtilsRegression|MappingUtilsRegression|AutoeditAPICompatibility|ResourceLoaderDependency)Test' \
    extensions/PageFormsWikidebates/tests/phpunit

# Run targeted upstream AbuseFilter tests against the pinned 1.43 code.
# These include HTML escaping, tokenizer cache keys and protected-filter views.
vendor/bin/phpunit extensions/AbuseFilter/tests/phpunit/unit/SpecsFormatterTest.php
vendor/bin/phpunit extensions/AbuseFilter/tests/phpunit/unit/Parser/TokenizerTest.php
vendor/bin/phpunit extensions/AbuseFilter/tests/phpunit/unit/Special/SpecialAbuseFilterTest.php
vendor/bin/phpunit extensions/AbuseFilter/tests/phpunit/integration/Special/SpecialAbuseFilterTest.php
