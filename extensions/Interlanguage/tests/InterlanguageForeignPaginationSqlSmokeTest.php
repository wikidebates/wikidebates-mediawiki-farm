<?php
/**
 * Verify read-only central-wiki database lookup and two-source pagination
 * against an in-memory SQLite database (no live FR/EN connection).
 */
namespace MediaWiki {
	class ForeignPaginationConnectionProvider {
		public function getReplicaDatabase( $domain ) {
			if ( $domain !== 'centralWiki' ) {
				throw new \RuntimeException( 'The API must read the central wiki replica' );
			}
			return \ForeignPaginationDb::$instance;
		}
	}
	class MediaWikiServices {
		private static ?self $instance = null;
		public static function getInstance(): self {
			return self::$instance ??= new self();
		}
		public function getConnectionProvider(): ForeignPaginationConnectionProvider {
			return new ForeignPaginationConnectionProvider();
		}
	}
}

namespace {
	class ApiQuery {}
	class ApiResult {
		public static function setContentValue( array &$entry, string $name, $value ): void {
			$entry[$name] = $value;
		}
	}
	class ForeignPaginationTitle {
		public function __construct( public string $text, public int $id ) {}
		public function getArticleID(): int {
			return $this->id;
		}
	}
	class InterlanguageExtension {
		public static function getCanonicalTitleText( ForeignPaginationTitle $title ): string {
			return $title->text;
		}
	}
	class ForeignPaginationDb {
		public static self $instance;
		public PDO $pdo;
		public function __construct() {
			self::$instance = $this;
			$this->pdo = new PDO( 'sqlite::memory:' );
			$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			foreach ( [ 'langlinks' => 'll', 'interlanguage_links' => 'ill' ] as $name => $prefix ) {
				$this->pdo->exec( "CREATE TABLE mw_$name (" .
					"{$prefix}_from INTEGER, {$prefix}_lang TEXT, {$prefix}_title TEXT, " .
					"PRIMARY KEY ({$prefix}_from, {$prefix}_lang))" );
			}
		}
		public function tableName( $table ): string {
			return 'mw_' . $table;
		}
		public function addQuotes( $value ): string {
			return $this->pdo->quote( (string)$value );
		}
		public function insert( $table, $id, $lang, $title ): void {
			$prefix = $table === 'langlinks' ? 'll' : 'ill';
			$this->pdo->prepare(
				'INSERT INTO ' . $this->tableName( $table ) .
				" ({$prefix}_from, {$prefix}_lang, {$prefix}_title) VALUES (?, ?, ?)"
			)->execute( [ $id, $lang, $title ] );
		}
		public function selectFieldValues( $table, $field, $conditions, $caller ): array {
			$prefix = $table === 'langlinks' ? 'll' : 'ill';
			if ( $field !== "{$prefix}_from" ) {
				throw new RuntimeException( 'Invalid mapping field' );
			}
			$titles = (array)$conditions["{$prefix}_title"];
			if ( !$titles ) {
				return [];
			}
			$query = "SELECT DISTINCT {$prefix}_from FROM " . $this->tableName( $table ) .
				" WHERE {$prefix}_lang = ? AND {$prefix}_title IN (" .
				implode( ',', array_fill( 0, count( $titles ), '?' ) ) . ')';
			$statement = $this->pdo->prepare( $query );
			$statement->execute( [ $conditions["{$prefix}_lang"], ...$titles ] );
			return array_map( 'intval', $statement->fetchAll( PDO::FETCH_COLUMN ) );
		}
	}
	class ForeignPaginationPageSet {
		public array $titles = [ 17 => 'Local topic' ];
		public function getGoodTitles(): array {
			$result = [];
			foreach ( $this->titles as $id => $text ) {
				$result[$id] = new ForeignPaginationTitle( $text, (int)$id );
			}
			return $result;
		}
		public function getGoodTitleCount(): int {
			return count( $this->titles );
		}
	}
	class ApiQueryBase {
		public array $params = [];
		public array $entries = [];
		public array $continued = [];
		public ForeignPaginationPageSet $pageSet;
		private string $table = '';
		private array $where = [];
		private array $options = [];
		public function __construct( $query, $name, $prefix ) {
			$this->pageSet = new ForeignPaginationPageSet();
		}
		public function getDB() {
			return ForeignPaginationDb::$instance;
		}
		public function getPageSet(): ForeignPaginationPageSet {
			return $this->pageSet;
		}
		protected function resetQueryParams(): void {
			$this->table = '';
			$this->where = [];
			$this->options = [];
		}
		protected function addFields( $fields ): void {}
		protected function addTables( $table ): void {
			$this->table = $table;
		}
		protected function addWhereFld( $field, $value ): void {
			$quoted = array_map(
				static fn( $v ) => ForeignPaginationDb::$instance->addQuotes( $v ),
				(array)$value
			);
			$this->where[] = $field . ' IN (' . implode( ',', $quoted ) . ')';
		}
		protected function addWhere( $condition ): void {
			$this->where[] = $condition;
		}
		protected function addOption( $name, $value ): void {
			$this->options[$name] = $value;
		}
		protected function select( $caller, $extra = [] ): array {
			$conditions = array_merge( $this->where, (array)( $extra['where'] ?? [] ) );
			$sql = 'SELECT * FROM ' . ForeignPaginationDb::$instance->tableName( $this->table );
			if ( $conditions ) {
				$sql .= ' WHERE ' . implode( ' AND ', array_map(
					static fn( $s ) => '(' . $s . ')', $conditions
				) );
			}
			if ( isset( $this->options['ORDER BY'] ) ) {
				$sql .= ' ORDER BY ' . implode( ', ', (array)$this->options['ORDER BY'] );
			}
			$sql .= ' LIMIT ' . (int)( $this->options['LIMIT'] ?? 100000 );
			return ForeignPaginationDb::$instance->pdo->query( $sql )->fetchAll( PDO::FETCH_OBJ );
		}
		protected function addPageSubItem( $pageId, $entry ): bool {
			$this->entries[] = [ (int)$pageId, $entry['lang'], $entry['title'] ];
			return true;
		}
		protected function setContinueEnumParameter( $name, $value ): void {
			$this->continued[$name] = $value;
		}
		protected function requireMaxOneParameter( $params, $key, $other ): void {}
		protected function extractRequestParams(): array {
			return $this->params;
		}
		protected function dieContinueUsageIf( $invalid ): void {
			if ( $invalid ) {
				throw new RuntimeException( 'Invalid continuation' );
			}
		}
	}
	require_once dirname( __DIR__ ) . '/api/ApiQueryLangLinks.php';

	function checkForeign( bool $correct, string $message ): void {
		if ( !$correct ) {
			throw new RuntimeException( $message );
		}
	}

	function collectCentralLinks( array $params, array $titles ): array {
		$result = [];
		$cursor = null;
		for ( $attempt = 0; $attempt < 12; $attempt++ ) {
			$api = new ApiQueryLangLinks( new ApiQuery(), 'langlinks' );
			$api->pageSet->titles = $titles;
			$api->params = [ ...$params, 'continue' => $cursor ];
			$api->execute();
			checkForeign( count( $api->entries ) <= $params['limit'], 'Too many central links' );
			array_push( $result, ...$api->entries );
			$next = $api->continued['continue'] ?? null;
			if ( $next === null ) {
				return $result;
			}
			checkForeign( $next !== $cursor, 'Central cursor did not advance' );
			$cursor = $next;
		}
		throw new RuntimeException( 'Central pagination did not terminate' );
	}

	$wgLanguageCode = 'fr';
	$wgInterlanguageExtensionDB = 'centralWiki';
	// Mapping is tested independently of the optional inferred foreign link.
	$wgInterlanguageExtensionInterwiki = '';
	$db = new ForeignPaginationDb();
	foreach ( [
		[ 'langlinks', 101, 'fr', 'Local topic' ],
		[ 'langlinks', 101, 'de', 'Deutsch' ],
		[ 'langlinks', 101, 'it', 'Italiano' ],
		[ 'interlanguage_links', 101, 'fr', 'Local topic' ],
		[ 'interlanguage_links', 101, 'de', 'Already native' ],
		[ 'interlanguage_links', 101, 'en', 'Topic:With colon' ],
		[ 'interlanguage_links', 101, 'es', 'Spanish' ],
		[ 'interlanguage_links', 102, 'fr', 'Only secondary' ],
		[ 'interlanguage_links', 102, 'ja', 'Japanese' ],
		[ 'interlanguage_links', 102, 'pt', 'Portuguese' ],
	] as $row ) {
		$db->insert( ...$row );
	}
	$params = [ 'prop' => [], 'url' => false, 'dir' => 'ascending', 'limit' => 1 ];
	checkForeign(
		collectCentralLinks( $params, [ 17 => 'Local topic' ] ) === [
			[ 101, 'de', 'Deutsch' ], [ 101, 'it', 'Italiano' ],
			[ 101, 'en', 'Topic:With colon' ], [ 101, 'es', 'Spanish' ],
		],
		'Central-wiki mapping lost, duplicated or truncated links'
	);
	checkForeign(
		collectCentralLinks( $params, [ 19 => 'Only secondary' ] ) === [
			[ 102, 'ja', 'Japanese' ], [ 102, 'pt', 'Portuguese' ],
		],
		'Fallback to interlanguage_links does not paginate'
	);
	echo "Interlanguage foreign-database pagination SQL tests: OK\n";
}
