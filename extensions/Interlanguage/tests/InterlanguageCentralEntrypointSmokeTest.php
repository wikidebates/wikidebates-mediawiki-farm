<?php
/**
 * Ensure the historical central entry point loads all manifest registrations.
 *
 * Run with: php extensions/Interlanguage/tests/InterlanguageCentralEntrypointSmokeTest.php
 */
$loadedExtensions = [];

function wfLoadExtension( $name, $path = null ) {
	global $loadedExtensions;
	$loadedExtensions[] = [ $name, $path ];
}

function assertInterlanguageEntrypoint( bool $condition, string $message ): void {
	if ( !$condition ) {
		throw new RuntimeException( $message );
	}
}

require_once dirname( __DIR__ ) . '/InterlanguageCentral.php';

assertInterlanguageEntrypoint(
	count( $loadedExtensions ) === 1 && $loadedExtensions[0][0] === 'Interlanguage',
	'Legacy central include must queue the Interlanguage manifest exactly once'
);

$path = $loadedExtensions[0][1];
assertInterlanguageEntrypoint(
	$path === dirname( __DIR__ ) . '/extension.json' && is_file( $path ),
	'Legacy central include must point to the bundled manifest'
);

$manifest = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
assertInterlanguageEntrypoint(
	( $manifest['JobClasses']['purgeDependentWikis'] ?? null ) ===
		'InterlanguageCentralExtensionPurgeJob',
	'Manifest must register the exact job type used by the central purge job'
);

foreach ( [
	'ParserFirstCallInit' => 'InterlanguageCentralExtension::onParserFirstCallInit',
	'LinksUpdate' => 'InterlanguageCentralExtension::onLinksUpdate',
	'LanguageLinks' => 'InterlanguageCentralExtension::onLanguageLinks',
] as $hook => $handler ) {
	assertInterlanguageEntrypoint(
		( $manifest['Hooks'][$hook] ?? null ) === $handler,
		"Manifest must register the central $hook hook"
	);
}

foreach ( [ 'InterlanguageCentralExtension', 'InterlanguageCentralExtensionPurgeJob' ] as $class ) {
	assertInterlanguageEntrypoint(
		isset( $manifest['AutoloadClasses'][$class] ),
		"Manifest must autoload $class"
	);
}

echo "Interlanguage central manifest entrypoint: OK\n";
