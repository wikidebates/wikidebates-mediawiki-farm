<?php
/**
 * Execute pagination on actual SQLite rows without starting MediaWiki.
 * No network access, persistent files or live wiki databases.
 */
class ApiQuery {}
class ApiResult {
	public static function setContentValue( array &$entry, string $key, $value ): void {
		$entry[$key] = $value;
	}
}
class InterlanguageTestSqlDb {
	public PDO $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		foreach ( [ 'langlinks' => 'll', 'interlanguage_links' => 'ill' ] as $table => $prefix ) {
			$this->pdo->exec( 'CREATE TABLE mw_' . $table .
				" ({$prefix}_from INTEGER, {$prefix}_lang TEXT, {$prefix}_title TEXT," .
				" PRIMARY KEY ({$prefix}_from, {$prefix}_lang))" );
		}
	}

	public function tableName( $name ): string {
		return 'mw_' . $name;
	}

	public function addQuotes( $value ): string {
		return $this->pdo->quote( (string)$value );
	}

	public function insert( $table, $id, $lang, $title ): void {
		$prefix = $table === 'langlinks' ? 'll' : 'ill';
		$sql = 'INSERT INTO ' . $this->tableName( $table ) .
			" ({$prefix}_from, {$prefix}_lang, {$prefix}_title) VALUES (?, ?, ?)";
		$this->pdo->prepare( $sql )->execute( [ $id, $lang, $title ] );
	}
}
class InterlanguageTestPageSet {
	public array $ids = [ 17, 19 ];
	public function getGoodTitleCount(): int { return count( $this->ids ); }
	public function getGoodTitles(): array { return array_fill_keys( $this->ids, (object)[] ); }
}
class ApiQueryBase {
	public static InterlanguageTestSqlDb $db;
	public array $params = [];
	public array $entries = [];
	public array $continued = [];
	public InterlanguageTestPageSet $pageSet;
	private string $table = '';
	private array $where = [];
	private array $options = [];

	public function __construct( $query, $name, $prefix ) {
		$this->pageSet = new InterlanguageTestPageSet();
	}

	public function getDB() { return self::$db; }
	public function getPageSet(): InterlanguageTestPageSet { return $this->pageSet; }
	protected function resetQueryParams(): void {
		$this->table = '';
		$this->where = [];
		$this->options = [];
	}
	protected function addFields( $columns ): void {}
	protected function addTables( $table ): void { $this->table = $table; }
	protected function addWhere( $sql ): void { $this->where[] = $sql; }
	protected function addWhereFld( $column, $value ): void {
		$quoted = array_map(
			static fn( $v ) => self::$db->addQuotes( $v ),
			(array)$value
		);
		$this->where[] = $column . ' IN (' . implode( ', ', $quoted ) . ')';
	}
	protected function addOption( $name, $value ): void { $this->options[$name] = $value; }
	protected function select( $caller, $extra = [] ): array {
		$where = array_merge( $this->where, (array)( $extra['where'] ?? [] ) );
		$sql = 'SELECT * FROM ' . self::$db->tableName( $this->table );
		if ( $where ) {
			$sql .= ' WHERE ' . implode(
				' AND ',
				array_map( static fn( $s ) => '(' . $s . ')', $where )
			);
		}
		if ( isset( $this->options['ORDER BY'] ) ) {
			$sql .= ' ORDER BY ' . implode( ', ', (array)$this->options['ORDER BY'] );
		}
		$sql .= ' LIMIT ' . (int)( $this->options['LIMIT'] ?? 100000 );
		return self::$db->pdo->query( $sql )->fetchAll( PDO::FETCH_OBJ );
	}
	protected function addPageSubItem( $page, $entry ): bool {
		$this->entries[] = [ (int)$page, $entry['lang'], $entry['title'] ];
		return true;
	}
	protected function setContinueEnumParameter( $name, $value ): void {
		$this->continued[$name] = $value;
	}
	protected function extractRequestParams(): array { return $this->params; }
	protected function requireMaxOneParameter( $params, $one, $two ): void {}
	protected function dieContinueUsageIf( $invalid ): void {
		if ( $invalid ) {
			throw new RuntimeException( 'Invalid continuation' );
		}
	}
}
require_once dirname( __DIR__ ) . '/api/ApiQueryLangLinks.php';

function checkSqlPages( bool $correct, string $message ): void {
	if ( !$correct ) {
		throw new RuntimeException( $message );
	}
}
function readAllLinks( array $params, array $ids = [ 17, 19 ] ): array {
	$cursor = null;
	$collected = [];
	$cursors = [];
	for ( $step = 0; $step < 15; $step++ ) {
		$api = new ApiQueryLangLinks( new ApiQuery(), 'langlinks' );
		$api->pageSet->ids = $ids;
		$api->params = [ ...$params, 'continue' => $cursor ];
		$api->execute();
		checkSqlPages( count( $api->entries ) <= $params['limit'], 'Overfull response page' );
		array_push( $collected, ...$api->entries );
		$next = $api->continued['continue'] ?? null;
		$cursors[] = $next;
		if ( $next === null ) {
			return [ $collected, $cursors ];
		}
		checkSqlPages( $next !== $cursor, 'Cursor did not advance' );
		$cursor = $next;
	}
	throw new RuntimeException( 'Pagination did not finish' );
}

$wgLanguageCode = 'fr';
$wgInterlanguageExtensionDB = false;
ApiQueryBase::$db = new InterlanguageTestSqlDb();
foreach ( [
	[ 'langlinks', 17, 'de', 'Deutsch' ],
	[ 'langlinks', 17, 'it', 'Italiano' ],
	[ 'langlinks', 17, 'fr', 'Local' ],
	[ 'langlinks', 19, 'de', 'German' ],
	[ 'langlinks', 19, 'ja', 'Japanese' ],
	[ 'interlanguage_links', 17, 'de', 'Duplicate native' ],
	[ 'interlanguage_links', 17, 'en', 'Topic:With colon' ],
	[ 'interlanguage_links', 17, 'es', 'Spanish' ],
	[ 'interlanguage_links', 19, 'de', 'Duplicate native' ],
	[ 'interlanguage_links', 19, 'it', 'Another Italian' ],
	[ 'interlanguage_links', 19, 'ru', 'Russian' ],
	[ 'interlanguage_links', 19, 'fr', 'Local' ],
] as $row ) {
	ApiQueryBase::$db->insert( ...$row );
}

$params = [ 'prop' => [], 'url' => false, 'dir' => 'ascending', 'limit' => 2 ];
[ $ascending, $cursors ] = readAllLinks( $params );
checkSqlPages( $ascending === [
	[ 17, 'de', 'Deutsch' ], [ 17, 'it', 'Italiano' ],
	[ 19, 'de', 'German' ], [ 19, 'ja', 'Japanese' ],
	[ 17, 'en', 'Topic:With colon' ], [ 17, 'es', 'Spanish' ],
	[ 19, 'it', 'Another Italian' ], [ 19, 'ru', 'Russian' ],
], 'Ascending result skipped a link, repeated one or lost its title' );
checkSqlPages( $cursors === [ '19|de', 'w|17|en', 'w|19|it', null ],
	'Unexpected cross-source pagination cursors' );

[ $descending ] = readAllLinks( [ ...$params, 'dir' => 'descending' ] );
checkSqlPages( $descending === [
	[ 19, 'ja', 'Japanese' ], [ 19, 'de', 'German' ],
	[ 17, 'it', 'Italiano' ], [ 17, 'de', 'Deutsch' ],
	[ 19, 'ru', 'Russian' ], [ 19, 'it', 'Another Italian' ],
	[ 17, 'es', 'Spanish' ], [ 17, 'en', 'Topic:With colon' ],
], 'Descending pagination skipped or duplicated rows' );

[ $filtered ] = readAllLinks( [ ...$params, 'lang' => 'es', 'limit' => 1 ] );
checkSqlPages( $filtered === [ [ 17, 'es', 'Spanish' ] ],
	'Language filter incorrectly excluded the auxiliary table' );

[ $single ] = readAllLinks( [ ...$params, 'limit' => 1 ], [ 19 ] );
checkSqlPages( $single === [
	[ 19, 'de', 'German' ], [ 19, 'ja', 'Japanese' ],
	[ 19, 'it', 'Another Italian' ], [ 19, 'ru', 'Russian' ],
], 'Single-page sequence is not complete' );

echo "Interlanguage pagination SQL smoke tests: OK\n";
