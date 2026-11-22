<?php
/**
 * A limited, standalone regression for page-scoped interlanguage de-duplication.
 *
 * This is NOT a substitute for FR/EN multiwiki MediaWiki 1.43 integration.
 * Run: php extensions/Interlanguage/tests/InterlanguageMultiTitleSmokeTest.php
 */

class ApiQuery {
}

class ApiResult {
	public static function setContentValue( array &$target, string $key, string $value ): void {
		$target[$key] = $value;
	}
}

class InterlanguageTestPageSet {
	public function getGoodTitleCount(): int {
		return 2;
	}
	public function getGoodTitles(): array {
		return [ 101 => new stdClass(), 202 => new stdClass() ];
	}
}

class InterlanguageTestQueryDB {
	public function addQuotes( string $text ): string {
		return "'" . str_replace( "'", "''", $text ) . "'";
	}
	public function makeList( array $items ): string {
		return implode( ',', array_map( [ $this, 'addQuotes' ], $items ) );
	}
}

class ApiQueryBase {
	protected string $table = '';
	public array $rowsByPage = [];
	public array $queryWhere = [];
	public array $continuation = [];
	protected InterlanguageTestQueryDB $db;
	protected InterlanguageTestPageSet $pageSet;
	public function __construct( ApiQuery $query, string $name, string $prefix ) {
		$this->db = new InterlanguageTestQueryDB();
		$this->pageSet = new InterlanguageTestPageSet();
	}
	protected function getPageSet(): InterlanguageTestPageSet {
		return $this->pageSet;
	}
	protected function extractRequestParams(): array {
		return [
			'prop' => [], 'url' => false, 'lang' => null, 'title' => null,
			'limit' => 10, 'dir' => 'ascending', 'continue' => null,
			'inlanguagecode' => 'en',
		];
	}
	protected function requireMaxOneParameter( ...$args ): void {
	}
	protected function addFields( array $fields ): void {
	}
	protected function addTables( string $table ): void {
		$this->table = $table;
	}
	protected function addWhereFld( string $field, $values ): void {
	}
	protected function addWhere( string $where ): void {
	}
	protected function addOption( string $name, $value ): void {
	}
	protected function resetQueryParams(): void {
		$this->table = '';
	}
	protected function getDB(): InterlanguageTestQueryDB {
		return $this->db;
	}
	protected function select( string $caller, array $extra = [] ): iterable {
		$where = $extra['where'] ?? '';
		$this->queryWhere[$this->table] = $where;
		$exclude = [];
		if ( preg_match( "/\bNOT IN \(([^)]+)\)/", $where, $match ) ) {
			foreach ( explode( ',', $match[1] ) as $item ) {
				$exclude[] = trim( $item, "' \t" );
			}
		} elseif ( preg_match( "/!=\s*'([^']+)'/", $where, $match ) ) {
			$exclude[] = $match[1];
		}
		$rows = $GLOBALS['interlanguageTestRows'][$this->table] ?? [];
		return array_values( array_filter(
			array_map( static fn( $values ) => (object)$values, $rows ),
			static function ( $row ) use ( $exclude ): bool {
				$lang = $row->ll_lang ?? $row->ill_lang;
				return !in_array( $lang, $exclude, true );
			}
		) );
	}
	protected function addPageSubItem( int $pageId, array $entry ): bool {
		$this->rowsByPage[$pageId][] = $entry;
		return true;
	}
	protected function setContinueEnumParameter( string $name, string $token ): void {
		$this->continuation[$name] = $token;
	}
	protected function dieContinueUsageIf( bool $condition ): void {
		if ( $condition ) {
			throw new RuntimeException( 'Malformed continuation token' );
		}
	}
	protected function dieWithError( $message, $code ): never {
		throw new RuntimeException( $code );
	}
}

require_once dirname( __DIR__ ) . '/api/ApiQueryLangLinks.php';

$wgLanguageCode = 'fr';
$wgInterlanguageExtensionDB = false;
$wgInterlanguageExtensionInterwiki = false;
$GLOBALS['interlanguageTestRows'] = [
	'langlinks' => [
		[ 'll_from' => 101, 'll_lang' => 'en', 'll_title' => 'One (EN)' ],
		[ 'll_from' => 202, 'll_lang' => 'es', 'll_title' => 'Two (ES)' ],
	],
	'interlanguage_links' => [
		[ 'ill_from' => 101, 'ill_lang' => 'en', 'ill_title' => 'Duplicated (EN)' ],
		[ 'ill_from' => 101, 'ill_lang' => 'es', 'ill_title' => 'One (ES)' ],
		[ 'ill_from' => 202, 'ill_lang' => 'en', 'ill_title' => 'Two (EN)' ],
		[ 'ill_from' => 202, 'ill_lang' => 'es', 'ill_title' => 'Duplicated (ES)' ],
		[ 'ill_from' => 202, 'ill_lang' => 'fr', 'ill_title' => 'Native (FR)' ],
	],
];

$module = new ApiQueryLangLinks( new ApiQuery(), 'langlinks' );
$module->execute();
$summary = [];
foreach ( $module->rowsByPage as $pageId => $entries ) {
	foreach ( $entries as $entry ) {
		$summary[] = "$pageId:{$entry['lang']}:{$entry['title']}";
	}
}
sort( $summary );
$expected = [
	'101:en:One (EN)',
	'101:es:One (ES)',
	'202:en:Two (EN)',
	'202:es:Two (ES)',
];
sort( $expected );
if ( $summary !== $expected ) {
	throw new RuntimeException( 'Page-scoped interlanguage links incorrect: ' . json_encode( $summary ) );
}
if ( count( $module->rowsByPage[101] ) !== 2 || count( $module->rowsByPage[202] ) !== 2 ) {
	throw new RuntimeException( 'Links were associated with an incorrect page or duplicated' );
}
if ( str_contains( $module->queryWhere['interlanguage_links'], "'en','es'" ) ) {
	throw new RuntimeException( 'SQL still excludes languages across every selected page' );
}
if ( $module->continuation ) {
	throw new RuntimeException( 'Unexpected continuation for a small result' );
}
echo "Interlanguage page-scoped de-duplication smoke test: OK\n";
