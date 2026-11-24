<?php

declare( strict_types=1 );

require_once __DIR__ . '/../overrides/includes/MetaValues.php';

use MediaWiki\Extension\WikiSEO\MetaValues;

$checks = [
	[ 'og default', MetaValues::openGraphType( [] ), 'website' ],
	[ 'og explicit', MetaValues::openGraphType( [ 'type' => 'Article' ] ), 'article' ],
	[ 'og override', MetaValues::openGraphType( [ 'type' => 'Article', 'og_type' => 'website' ] ), 'website' ],
	[ 'schema default', MetaValues::schemaType( [] ), 'WebPage' ],
	[ 'schema legacy', MetaValues::schemaType( [ 'type' => 'website' ] ), 'WebPage' ],
	[ 'schema article', MetaValues::schemaType( [ 'type' => 'article' ] ), 'Article' ],
	[ 'schema override', MetaValues::schemaType( [ 'type' => 'article', 'schema_type' => 'AboutPage' ] ), 'AboutPage' ],
	[ 'schema invalid', MetaValues::schemaType( [ 'schema_type' => 'Dangerous' ] ), 'WebPage' ],
	[ 'date valid', MetaValues::date( '2025-02-18' ), '2025-02-18' ],
	[ 'date invalid', MetaValues::date( '2025-02-29' ), null ],
	[ 'date timestamp', MetaValues::date( '2025-02-18T12:04:53+00:00' ), '2025-02-18T12:04:53+00:00' ],
	[ 'date relative', MetaValues::date( 'now' ), null ],
	[ 'reject javascript', MetaValues::httpUrl( 'javascript:alert(1)' ), null ],
	[ 'accept https', MetaValues::secureUrl( 'https://example.org/a.png' ), 'https://example.org/a.png' ],
	[ 'reject http secure', MetaValues::secureUrl( 'http://example.org/a.png' ), null ],
];

foreach ( $checks as [ $label, $actual, $expected ] ) {
	if ( $actual !== $expected ) {
		throw new RuntimeException( "$label: " . var_export( $actual, true ) . ' instead of ' . var_export( $expected, true ) );
	}
}

$json = MetaValues::jsonLd( [ 'name' => '</script><script>alert(1)</script>', 'title' => 'Éthique' ] );
if ( $json === null || str_contains( $json, '</script>' ) || !str_contains( $json, 'Éthique' ) ) {
	throw new RuntimeException( 'JSON-LD escaping failed' );
}

echo count( $checks ) + 1 . " standalone WikiSEO checks passed\n";
