<?php

declare( strict_types=1 );

/** Parse actual candidate templates and all editorial calls in an isolated MediaWiki. */
require getenv( 'WD_SEO_LAB_BOOTSTRAP' );

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\WikiSEO\MetaValues;
use MediaWiki\Extension\WikiSEO\WikiSEO;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

$lang = getenv( 'WD_TEST_LANGUAGE' );
$dir = dirname( __DIR__, 2 ) . '/docs/editorial-seo';
$manifest = json_decode( file_get_contents( $dir . '/pages.json' ), true, 512, JSON_THROW_ON_ERROR );
$services = MediaWikiServices::getInstance();
$templates = [ 'fr' => 'Métabalises', 'en' => 'Meta tags', 'es' => 'Metaetiquetas' ];
$name = $templates[$lang] ?? null;
if ( $name !== null ) {
	$title = Title::makeTitle( NS_TEMPLATE, $name );
	$user = User::newSystemUser( 'Editorial SEO test', [ 'steal' => false ] );
	$page = $services->getWikiPageFactory()->newFromTitle( $title );
	$updater = $page->newPageUpdater( $user );
	$updater->setContent( SlotRecord::MAIN, ContentHandler::makeContent(
		file_get_contents( $dir . '/templates/' . $lang . '.wikitext' ), $title ) );
	$updater->saveRevision( CommentStoreComment::newUnsavedComment( 'Isolated SEO template fixture' ), EDIT_SUPPRESS_RC );
	check( $updater->wasSuccessful(), 'Template fixture saved' );
	DeferredUpdates::doUpdates();
	$services->getDBLoadBalancerFactory()->commitPrimaryChanges( 'editorialSeoTemplates' );
}

function parseSEO( string $wikitext ): array {
	global $services;
	$title = Title::newFromText( 'Editorial SEO fixture' );
	$parsed = $services->getParserFactory()->create()->parse( $wikitext, $title, ParserOptions::newFromAnon() );
	$context = new RequestContext();
	$context->setTitle( $title );
	$out = $context->getOutput();
	$out->setArticleFlag( true );
	$out->addParserOutputMetadata( $parsed );
	// Mirror WikiSEO's existing integration/parser.php fixture: ordinary parser
	// properties are not automatically copied by OutputPage in this harness.
	foreach ( $parsed->getPageProperties() as $key => $value ) {
		$out->setProperty( $key, $value );
	}
	$seo = new WikiSEO();
	$seo->setMetadataFromPageProps( $out );
	$seo->addMetadataToPage( $out );
	$head = implode( "\n", $out->getHeadLinksArray() ) . "\n" . implode( "\n", $out->getHeadItemsArray() );
	$json = $out->getHeadItemsArray()['jsonld-metadata'] ?? '';
	$prefix = '<script type="application/ld+json">';
	check( str_starts_with( $json, $prefix ), 'JSON-LD produced' );
	$schema = json_decode( substr( $json, strlen( $prefix ), -strlen( '</script>' ) ), true, 512, JSON_THROW_ON_ERROR );
	return [ $parsed->getPageProperties(), $seo, $out, $head, $schema ];
}

function normalized( string $value ): string {
	return trim( preg_replace( '/[\s\x{00A0}\x{202F}]+/u', ' ', $value ) );
}

function field( string $text, string $name ): ?string {
	return preg_match( '/^\s*\|' . preg_quote( $name, '/' ) . '=(.*)$/mu', $text, $match )
		? trim( $match[1] ) : null;
}

$count = 0;
foreach ( $manifest['pages'] as $entry ) {
	if ( $entry['wiki'] !== $lang ) {
		continue;
	}
	$call = $entry['operations'][0]['after'];
	[ $props, $seo, $out, $head, $schema ] = parseSEO( $call );
	$title = field( $call, 'titre' ) ?? field( $call, 'título' ) ?? field( $call, 'title' );
	$description = field( $call, 'descripción' ) ?? field( $call, 'description' );
	$label = $entry['title'];
	check( $title !== null && $description !== null, 'Complete fixture: ' . $label );
	same( normalized( $title ), normalized( $out->getHTMLTitle() ), 'HTML title: ' . $label );
	same( normalized( $description ), normalized( $seo->getMetadataValue( 'description' ) ?? '' ), 'Description: ' . $label );
	same( $entry['schema'], $schema['@type'], 'Schema type: ' . $label );
	same( normalized( $description ), normalized( $schema['description'] ?? '' ), 'Schema description: ' . $label );
	$ogType = $entry['schema'] === 'Article' ? 'article' : 'website';
	check( str_contains( $head, 'property="og:type" content="' . $ogType . '"' ), 'Open Graph type: ' . $label );
	check( isset( $schema['image']['url'] ), 'Social image: ' . $label );
	check( !str_contains( $head, '{{#if:' ), 'No literal #if: ' . $label );
	if ( field( $call, 'schema_author' ) !== null ) {
		same( field( $call, 'schema_author' ), $schema['author']['name'] ?? null, 'Article author: ' . $label );
	} else {
		check( !isset( $schema['author'] ), 'No invented author: ' . $label );
	}
	check( !isset( $schema['datePublished'] ), 'No invented publication date: ' . $label );
	$count++;
}
same( [ 'fr' => 34, 'en' => 17, 'es' => 7, 'de' => 1, 'it' => 1, 'pt' => 1 ][$lang], $count,
	'All editorial calls tested in ' . $lang );

if ( $name !== null ) {
	$descriptionKey = $lang === 'es' ? 'descripción' : 'description';
	$legacyKey = $lang === 'es' ? 'tipo' : 'type';
	[ $props, $seo, $out, $head, $schema ] = parseSEO( '{{' . $name . '}}' );
	same( 'WebPage', $schema['@type'], 'Empty call defaults to WebPage' );
	same( null, $seo->getMetadataValue( 'description' ), 'No generic description' );
	[ $props, $seo ] = parseSEO( '{{' . $name . '|' . $descriptionKey . '=}}{{SHORTDESC:Specific fallback}}' );
	same( 'Specific fallback', $seo->getMetadataValue( 'description' ), 'ShortDescription fallback' );
	[ $props, $seo, $out, $head, $schema ] = parseSEO( '{{' . $name . '|' . $descriptionKey .
		'=Explicit editorial text|' . $legacyKey . '=article|og_type=website|schema_type=AboutPage' .
		'|image=https://example.test/other.png|image_alt=Custom alt}}{{SHORTDESC:Other text}}' );
	same( 'Explicit editorial text', $seo->getMetadataValue( 'description' ), 'Explicit description wins' );
	same( 'AboutPage', $schema['@type'], 'Explicit Schema type wins over legacy type' );
	same( 'website', MetaValues::openGraphType( $props ), 'Explicit Open Graph type wins' );
	same( 'https://example.test/other.png', $schema['image']['url'], 'Custom image' );
	same( 'Custom alt', $seo->getMetadataValue( 'image_alt' ), 'Custom alt' );
	[ $props, $seo, $out, $head, $schema ] = parseSEO( '{{' . $name . '|' . $legacyKey . '=article}}' );
	same( 'Article', $schema['@type'], 'Legacy article type remains supported' );
	if ( $lang === 'fr' ) {
		foreach ( [
			[ '|mots-clés=argument', 'argument' ],
			[ '|rubriques=philosophie', 'philosophie' ],
			[ '|mots-clés=argument|rubriques=philosophie', 'argument, philosophie' ],
		] as [ $params, $expected ] ) {
			[ $props, $seo ] = parseSEO( '{{Métabalises' . $params . '}}' );
			same( $expected, normalized( $seo->getMetadataValue( 'keywords' ) ?? '' ), 'Legacy keywords' );
		}
	}
}
echo "OK: $count editorial calls and template compatibility ($lang)\n";
