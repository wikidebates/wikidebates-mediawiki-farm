<?php

declare( strict_types=1 );

require dirname( __DIR__, 2 ) . '/scripts/editorial-seo/EditorialSeoMigration.php';

function same( $expected, $actual ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

function rejects( callable $callback ): void {
	try {
		$callback();
	} catch ( RuntimeException $e ) {
		return;
	}
	throw new RuntimeException( 'Expected rejection' );
}

$dir = dirname( __DIR__, 2 ) . '/docs/editorial-seo';
foreach ( EditorialSeoMigration::COUNTS as $lang => $count ) {
	$entries = EditorialSeoMigration::load( $lang, $dir );
	same( $count + ( isset( EditorialSeoMigration::TEMPLATES[$lang] ) ? 1 : 0 ), count( $entries ) );
	same( [ 'fr' => 29, 'en' => 16, 'es' => 7 ][$lang] ?? 0,
		count( EditorialSeoMigration::expectedCallers( $lang, $entries ) ) );
	foreach ( $entries as $entry ) {
		if ( isset( $entry['template'] ) ) {
			same( $entry['template'], EditorialSeoMigration::transform( 'old template', $entry ) );
			continue;
		}
		$source = "before\n";
		$expected = $source;
		foreach ( $entry['operations'] as $op ) {
			if ( $op['kind'] === 'replace' ) {
				$source .= $op['before'] . "\n";
				$expected .= $op['after'] . "\n";
			} else {
				$expected .= "\n" . $op['after'] . "\n";
			}
		}
		same( $expected, EditorialSeoMigration::transform( $source, $entry ) );
		rejects( static fn () => EditorialSeoMigration::transform( $source . "\n{{#seo:|title=Other}}", $entry ) );
		if ( $entry['operations'][0]['kind'] === 'replace' ) {
			rejects( static fn () => EditorialSeoMigration::transform( $source . $entry['operations'][0]['before'], $entry ) );
			rejects( static fn () => EditorialSeoMigration::transform( 'unrelated', $entry ) );
		}
	}
}
echo "OK: all manifest transformations, duplicate-call and stale-source guards\n";
