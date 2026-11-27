<?php

/** Reuse the pinned core's native hover cases on the farm's locked SMW version. */
if (PHP_SAPI !== 'cli' || getenv('CI') !== 'true' ||
	getenv('GITHUB_ACTIONS') !== 'true' || getenv('WD_TEST_MARIADB_SOCKET')) {
	throw new RuntimeException('Only run in disposable SQLite GitHub Actions CI');
}
$root = dirname(__DIR__, 2);
$lock = json_decode(file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$versions = array_column($lock['packages'], 'version', 'name');
$expectedSMW = $versions['mediawiki/semantic-media-wiki'] ?? null;
if (!is_string($expectedSMW)) {
	throw new RuntimeException('Missing locked SMW version');
}
$archive = ($argv[1] ?? '') === '--archive';
if ($archive) {
	define('WD_TEST_LUA_ARCHIVE', true);
}
define('WD_FULLSTACK_TEST', true);
putenv('MW_INSTALL_PATH=' . $root);
putenv('WD_TEST_LANGUAGE=en');
putenv('WD_TEST_EXTENSIONS=SemanticMediaWiki');
putenv('WD_FULLSTACK_AUTOLOAD');
require $root . '/extensions/Wikidebates/tests/mediawikiEnvironment.php';
require $root . '/extensions/Wikidebates/tests/fullstackSupport.php';
require $root . '/extensions/Wikidebates/tests/fullstackArgumentMapCases.php';

use MediaWiki\Registration\ExtensionRegistry;
use SMW\Services\ServicesFactory;

same($expectedSMW, SMW_VERSION, 'Exact locked farm SMW version');
$core = $root . '/extensions/Wikidebates';
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($core . '/debatephp', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
	check($file->getExtension() !== 'lua', 'No bundled executable Lua sources: ' . $file->getFilename());
}
foreach (['argument-contract', 'serialized-contract', 'i18n-contract', 'fullstack-contract', 'native-boundary-contract', 'native-render-contract'] as $contract) {
	check(is_file($core . '/debatephp/tests/fixtures/' . $contract . '.json'), 'Frozen contract present: ' . $contract);
}
check(!is_file($core . '/debatephp/tools/compileI18n.php'), 'Obsolete translation compiler retired');
foreach (['legacy/Lua.php', 'legacy/Table.php', 'legacy/LegacyJson.php', 'regenerate.py'] as $obsolete) {
	check(!is_file($core . '/debatephp/tests/' . $obsolete), 'Historical test implementation retired: ' . $obsolete);
}
check(!is_dir($core . '/tests/fullstack-dependencies'), 'No redundant SMW 6 laboratory lock');
foreach (['DebatePHPMode', 'WikidebatesArgumentMapApi', 'WikidebatesArgumentMapDirectRead'] as $obsolete) {
	check(!\MediaWiki\MediaWikiServices::getInstance()->getMainConfig()->has($obsolete), 'No obsolete renderer setting: ' . $obsolete);
}
check(!class_exists(\MediaWiki\Extension\DebatePHP\TrackedSemanticQuery::class), 'Obsolete query dispatcher retired');
check(class_exists(\MediaWiki\Extension\DebatePHP\ArgumentRenderer::class), 'Canonical Argument renderer available');
check(class_exists(\MediaWiki\Extension\DebatePHP\DebateRenderer::class), 'Canonical Debate renderer available');
foreach (['ArgumentReferenceRenderer', 'ReferenceRenderer', 'Renderer', 'Lua', 'Table', 'LegacyJson'] as $legacy) {
	check(!class_exists('MediaWiki\\Extension\\DebatePHP\\' . $legacy), 'Obsolete renderer not available: ' . $legacy);
}
same($archive, ExtensionRegistry::getInstance()->isLoaded('WikidebatesLuaArchive'), 'Standalone archive loaded only when explicitly requested');
$handler = \MediaWiki\MediaWikiServices::getInstance()->getContentHandlerFactory()->getContentHandler('Scribunto');
check($handler instanceof \MediaWiki\Extension\WikidebatesLuaArchive\ArchiveContentHandler, 'Automatic or standalone inert source handler coexists with locked SMW');
foreach (['Scribunto', 'SemanticScribunto', 'VariablesLua'] as $name) {
	check(!ExtensionRegistry::getInstance()->isLoaded($name), 'No Lua extension: ' . $name);
}
$store = ServicesFactory::getInstance()->getStore();
check($store instanceof \SMW\SQLStore\SQLStore && $store->setup(false), 'Disposable real SMW store');
$schema = $GLOBALS;
(new \SMW\SetupFile())->loadSchema($schema);
\SMW\Globals::replace($schema);
check(\SMW\Site::isReady(), 'SMW ready');
$fsUser = \MediaWiki\User\User::newSystemUser('Wikidebates farm native map test', ['steal' => false]);
check($fsUser !== null, 'Synthetic test author');
fsArgumentMapCases();
require $core . '/tests/fullstackParentQueryModern.php';
check(!class_exists(\MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaEngine::class, false), 'No Lua engine loaded');
echo "OK: native ArgumentMap on pinned farm SMW " . SMW_VERSION . " without Lua" . ($archive ? " (archive enabled)" : "") . "\n";
