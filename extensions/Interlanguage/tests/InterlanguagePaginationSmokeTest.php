<?php
/**
 * Isolated pagination smoke tests for the two langlinks database sources.
 * Run: php extensions/Interlanguage/tests/InterlanguagePaginationSmokeTest.php
 */

class ApiQuery {}
class ApiResult {
	public static function setContentValue( array &$entry, string $key, $value ): void {
		$entry[$key] = $value;
	}
}
class InterlanguagePaginationFakeDb {
	public function addQuotes( $value ): string {
		return "'" . str_replace( "'", "''", $value ) . "'";
	}

	public function tableName( $name ): string {
		return 'mw_' . $name;
	}
}
class InterlanguagePaginationPageSet {
	public function getGoodTitles(): array {
		return [ 17 => (object)[ 'name' => 'Some page' ] ];
	}

	public function getGoodTitleCount(): int {
		return 1;
	}
}
class ApiQueryBase {
	public array $params = [];
	public array $nativeRows = [];
	public array $wikirougeRows = [];
	public array $entries = [];
	public array $continued = [];
	public array $wheres = [];
	public array $options = [];
	public array $lastExtra = [];
	public array $tablesSeen = [];
	protected string $table = '';
	protected InterlanguagePaginationFakeDb $db;
	protected InterlanguagePaginationPageSet $pageSet;

	public function __construct( $query, $name, $prefix ) {
		$this->db = new InterlanguagePaginationFakeDb();
		$this->pageSet = new InterlanguagePaginationPageSet();
	}

	public function getPageSet(): InterlanguagePaginationPageSet {
		return $this->pageSet;
	}

	public function getDB(): InterlanguagePaginationFakeDb {
		return $this->db;
	}

	protected function resetQueryParams(): void {
		$this->table = '';
		$this->wheres = [];
		$this->options = [];
	}

	protected function addTables( $table ): void {
		$this->table = $table;
		$this->tablesSeen[] = $table;
	}

	protected function addFields( $fields ): void {}

	protected function addWhereFld( $field, $value ): void {
		$this->wheres[] = [ $field, $value ];
	}

	protected function addWhere( $condition ): void {
		$this->wheres[] = $condition;
	}

	protected function addOption( $name, $value ): void {
		$this->options[$name] = $value;
	}

	protected function select( $method, $extraQuery = [] ): array {
		$this->lastExtra = $extraQuery;
		return $this->table === 'langlinks' ? $this->nativeRows : $this->wikirougeRows;
	}

	protected function addPageSubItem( $from, $entry ): bool {
		$this->entries[] = [ $from, $entry ];
		return true;
	}

	protected function setContinueEnumParameter( $param, $value ): void {
		$this->continued[$param] = $value;
	}

	protected function extractRequestParams(): array {
		return $this->params;
	}

	protected function requireMaxOneParameter( $params, $name, $other ): void {}

	protected function dieContinueUsageIf( $invalid ): void {
		if ( $invalid ) {
			throw new RuntimeException( 'Invalid continuation cursor' );
		}
	}
}

require_once dirname( __DIR__ ) . '/api/ApiQueryLangLinks.php';

function assertPagination( bool $ok, string $message ): void {
	if ( !$ok ) {
		throw new RuntimeException( $message );
	}
}

$wgLanguageCode = 'fr';
$wgInterlanguageExtensionDB = false;
$params = [ 'prop' => [], 'url' => false, 'dir' => 'ascending', 'limit' => 2, 'continue' => null ];
$native = static fn( $lang ) => (object)[ 'll_from' => 17, 'll_lang' => $lang, 'll_title' => 'Topic:With colon' ];
$extra = static fn( $lang ) => (object)[ 'ill_from' => 17, 'ill_lang' => $lang, 'ill_title' => 'Topic:With colon' ];

// Native and additional links share the same response limit.
$api = new ApiQueryLangLinks( new ApiQuery(), 'langlinks' );
$api->params = $params;
$api->nativeRows = [ $native( 'de' ) ];
$api->wikirougeRows = [ $extra( 'en' ), $extra( 'es' ) ];
$api->execute();
assertPagination( count( $api->entries ) === 2, 'Combined response must respect limit' );
assertPagination( $api->continued['continue'] === 'w|17|es', 'Second source needs a distinct cursor' );
assertPagination( $api->options['LIMIT'] === 2, 'Secondary SQL must use remaining budget + 1' );
assertPagination(
	str_contains( implode( '|', $api->lastExtra['where'] ), 'NOT EXISTS' ) &&
	str_contains( implode( '|', $api->lastExtra['where'] ), 'mw_langlinks' ),
	'Secondary SQL must exclude duplicate native languages per page'
);

// An exact fit in the native phase must not leak another row.
$api = new ApiQueryLangLinks( new ApiQuery(), 'langlinks' );
$api->params = $params;
$api->nativeRows = [ $native( 'de' ), $native( 'it' ) ];
$api->wikirougeRows = [ $extra( 'es' ) ];
$api->execute();
assertPagination( count( $api->entries ) === 2, 'Native exact fit must stay bounded' );
assertPagination( $api->continued['continue'] === 'w|17|es', 'Zero-budget phase should only peek' );
assertPagination( $api->options['LIMIT'] === 1, 'Zero-budget phase must query one lookahead' );

// Resume directly in the secondary table without replaying native results.
$api = new ApiQueryLangLinks( new ApiQuery(), 'langlinks' );
$api->params = [ ...$params, 'continue' => 'w|17|es' ];
$api->nativeRows = [ $native( 'de' ) ];
$api->wikirougeRows = [ $extra( 'es' ) ];
$api->execute();
assertPagination( $api->tablesSeen === [ 'interlanguage_links' ], 'Secondary continuation replayed native links' );
assertPagination( count( $api->entries ) === 1, 'Secondary cursor lost a row' );
assertPagination(
	str_contains( implode( '|', array_filter( $api->wheres, 'is_string' ) ), "ill_lang >= 'es'" ),
	'Secondary cursor must use an inclusive language comparison'
);

// Descending order uses an inverse, inclusive comparison.
$api = new ApiQueryLangLinks( new ApiQuery(), 'langlinks' );
$api->execute_wikirouge( [ ...$params, 'dir' => 'descending' ], [], 1, [ 'w', '17', 'it' ] );
assertPagination(
	str_contains( implode( '|', array_filter( $api->wheres, 'is_string' ) ), "ill_lang <= 'it'" ),
	'Descending continuation comparison is wrong'
);

// Reject invalid secondary cursors instead of silently restarting.
$api = new ApiQueryLangLinks( new ApiQuery(), 'langlinks' );
$api->params = [ ...$params, 'continue' => 'w|bad|es' ];
try {
	$api->execute();
	throw new RuntimeException( 'Malformed cursor was accepted' );
} catch ( RuntimeException $e ) {
	assertPagination( $e->getMessage() === 'Invalid continuation cursor', 'Unexpected exception' );
}

echo "Interlanguage pagination smoke tests: OK\n";
