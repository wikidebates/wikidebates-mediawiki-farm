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
export MW_CONFIG_FILE="$config/LocalSettings.php"
php maintenance/run.php update --quick
vendor/bin/phpunit --filter 'PF(FluidEditing|ValuesUtilsRegression|MappingUtilsRegression)Test' \
	extensions/PageFormsWikidebates/tests/phpunit
