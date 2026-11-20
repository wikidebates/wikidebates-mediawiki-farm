<?php
/**
 * Lightweight regression tests for the legacy Interlanguage frontend hooks.
 *
 * Run without bootstrapping MediaWiki:
 * php extensions/Interlanguage/tests/InterlanguageSmokeTest.php
 */
require_once dirname( __DIR__ ) . '/InterlanguageExtension.php';

class InterlanguageTestParserOutput {
	private array $properties = [];

	public function setPageProperty( string $name, string $value ): void {
		$this->properties[$name] = $value;
	}

	public function getPageProperty( string $name ) {
		return $this->properties[$name] ?? null;
	}
}

class InterlanguageTestOutputPage {
	public array $languageLinks = [];
	private array $properties = [];

	public function addLanguageLinks( array $links ): void {
		array_push( $this->languageLinks, ...$links );
	}

	public function setProperty( string $key, $value ): void {
		$this->properties[$key] = $value;
	}

	public function getProperty( string $key ) {
		return $this->properties[$key] ?? null;
	}
}

function expectInterlanguage( bool $condition, string $message ): void {
	if ( !$condition ) {
		throw new RuntimeException( $message );
	}
}

$extension = new InterlanguageExtension();
$parserOutput = new InterlanguageTestParserOutput();
$outputPage = new InterlanguageTestOutputPage();

$parserOutput->setPageProperty(
	'wikirouge-langlinks',
	serialize( [ [ 'lang' => 'en', '*' => 'A:Page' ], [ 'lang' => 'fr', '*' => 'Une page' ] ] )
);
$extension->onWikirougeOutputPageParserOutput( $outputPage, $parserOutput );
expectInterlanguage(
	$outputPage->languageLinks === [ 'en:A:Page', 'fr:Une page' ],
	'Language links were not added to the output'
);

$parserOutput->setPageProperty(
	'interlanguage_pages',
	serialize( [ 'Other wiki page' => true ] )
);
$extension->onOutputPageParserOutput( $outputPage, $parserOutput );
expectInterlanguage(
	$outputPage->getProperty( 'interlanguage_pages' ) === [ 'Other wiki page' => true ],
	'Interlanguage page links were not stored with OutputPage::setProperty'
);

// Persisted PHP arrays remain readable, while serialized objects are not instantiated.
$parserOutput->setPageProperty( 'wikirouge-langlinks', serialize( new stdClass() ) );
$extension->onWikirougeOutputPageParserOutput( $outputPage, $parserOutput );
expectInterlanguage(
	count( $outputPage->languageLinks ) === 2,
	'Non-array serialized language links should be ignored'
);

echo "Interlanguage smoke tests: OK\n";
