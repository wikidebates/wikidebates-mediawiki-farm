<?php

declare( strict_types=1 );

require dirname( __DIR__, 2 ) . '/scripts/editorial-seo/EditorialSeoMigration.php';

$lang = $argv[1];
$input = json_decode( stream_get_contents( STDIN ), true, 512, JSON_THROW_ON_ERROR );
$entries = EditorialSeoMigration::load( $lang, dirname( __DIR__, 2 ) . '/docs/editorial-seo' );
$pages = array_column( $input['pages'], null, 'title' );
$callers = array_column( $input['callers'], 'title' );
sort( $callers, SORT_STRING );
if ( $callers !== EditorialSeoMigration::expectedCallers( $lang, $entries ) ) {
	throw new RuntimeException( 'Caller inventory changed: ' . $lang );
}
foreach ( $entries as $entry ) {
	$page = $pages[$entry['title']] ?? [];
	$rev = $page['revisions'][0] ?? [];
	$slot = $rev['slots']['main'] ?? [];
	if ( isset( $page['missing'] ) || isset( $page['redirect'] ) || ( $rev['revid'] ?? null ) !== $entry['revid']
		|| !is_string( $slot['content'] ?? null ) ) {
		throw new RuntimeException( 'Unverified or changed source: ' . $entry['title'] );
	}
	if ( ( $entry['operations'][0]['kind'] ?? null ) === 'append' &&
		array_intersect_key( $page['pageprops'] ?? [], array_flip( [ 'title', 'description', 'type', 'og_type', 'schema_type' ] ) ) ) {
		throw new RuntimeException( 'Existing metadata: ' . $entry['title'] );
	}
	$new = EditorialSeoMigration::transform( $slot['content'], $entry );
	if ( $new === $slot['content'] ) {
		throw new RuntimeException( 'Unchanged: ' . $entry['title'] );
	}
}
echo 'FULL_SOURCE_OK ' . $lang . ' ' . count( $entries ) . " pages\n";
