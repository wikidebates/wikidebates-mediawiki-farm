<?php
/** Isolated Flow updated-topic cursor integration with the real Pager and ObjectManager. */
namespace Wikimedia {
	function base_convert( $number, $from, $to, $padding = 0 ) {
		// Fixtures intentionally stay within PHP's native base_convert precision.
		return str_pad( \base_convert( $number, $from, $to ), $padding, '0', STR_PAD_LEFT );
	}
}
namespace Wikimedia\Rdbms {
	class Blob {
		public function __construct( private string $value ) {
		}
		public function fetch() {
			return $this->value;
		}
	}
}
namespace {
	class ErrorPageError extends \Exception {
		public function __construct( $title, $message ) {
			parent::__construct( $message );
		}
	}
	require __DIR__ . '/bootstrap.php';

	use Flow\Data\ObjectManager;
	use Flow\Data\Pager\Pager;
	use Flow\Data\Pager\TopicListCursor;
	use Flow\DbFactory;
	use Flow\Model\UUID;

	define( 'DB_REPLICA', 1 );

	class CursorFakeDatabase {
		public function timestamp( $value ) {
			return $value;
		}
		public function expr( $column, $comparison, $value ) {
			return [ 'compare', $column, $comparison, $value ];
		}
		public function andExpr( array $expressions ) {
			return [ 'and', $expressions ];
		}
		public function orExpr( array $expressions ) {
			return [ 'or', $expressions ];
		}
	}
	class CursorFakeDbFactory extends DbFactory {
		public function __construct() {
		}
		public function getDB( $db ) {
			return new CursorFakeDatabase;
		}
	}
	class CursorFakeStorage {
		/** @var array[] */
		public array $all = [];
		public int $databaseCalls = 0;
		public function evaluate( array $row, array $expr ): bool {
			if ( $expr[0] === 'and' ) {
				foreach ( $expr[1] as $part ) {
					if ( !$this->evaluate( $row, $part ) ) {
						return false;
					}
				}
				return true;
			}
			if ( $expr[0] === 'or' ) {
				foreach ( $expr[1] as $part ) {
					if ( $this->evaluate( $row, $part ) ) {
						return true;
					}
				}
				return false;
			}
			$value = $expr[3] instanceof \Wikimedia\Rdbms\Blob ? $expr[3]->fetch() : $expr[3];
			$comparison = strcmp( $row[$expr[1]], $value );
			switch ( $expr[2] ) {
				case '<': return $comparison < 0;
				case '<=': return $comparison <= 0;
				case '>': return $comparison > 0;
				case '>=': return $comparison >= 0;
				case '=': return $comparison === 0;
			}
			throw new \LogicException( 'Unknown SQL comparator' );
		}
		public function findMulti( array $queries, array $options ) {
			$this->databaseCalls++;
			$results = [];
			foreach ( $queries as $idx => $conditions ) {
				$rows = $this->all;
				foreach ( $conditions as $condition ) {
					if ( is_array( $condition ) ) {
						$rows = array_values( array_filter( $rows,
							fn ( $row ) => $this->evaluate( $row, $condition ) ) );
					}
				}
				$ascending = str_contains( $options['ORDER BY'][0], 'ASC' );
				usort( $rows, static function ( $a, $b ) use ( $ascending ) {
					$cmp = strcmp( $a['workflow_last_update_timestamp'], $b['workflow_last_update_timestamp'] );
					if ( !$cmp ) {
						$cmp = strcmp( $a['topic_id'], $b['topic_id'] );
					}
					return $ascending ? $cmp : -$cmp;
				} );
				$results[$idx] = array_slice( $rows, 0, $options['LIMIT'] ?? 500 );
			}
			return $results;
		}
	}
	class CursorFakeIndex {
		public function __construct( private CursorFakeStorage $storage ) {
		}
		public function getSort() {
			return TopicListCursor::SORT;
		}
		public function findMulti( array $queries, array $options ) {
			return $this->storage->findMulti( $queries, [
				'ORDER BY' => [ 'workflow_last_update_timestamp DESC', 'topic_id DESC' ],
				'LIMIT' => $options['limit'] ?? 500,
			] );
		}
	}
	class CursorFakeManager extends ObjectManager {
		public function __construct( public CursorFakeStorage $source ) {
			$this->storage = $source;
			$this->dbFactory = new CursorFakeDbFactory;
			$this->lifecycleHandlers = [];
		}
		public function getIndexFor( array $keys, array $options = [] ) {
			return new CursorFakeIndex( $this->source );
		}
		protected function load( array $row ) {
			return (object)[
				'id' => UUID::create( $row['topic_id'] )->getAlphadecimal(),
				'timestamp' => $row['workflow_last_update_timestamp'],
			];
		}
		public function serializeOffset( $object, array $sortFields ) {
			return $object->timestamp . '|' . $object->id;
		}
	}

	// Exercise the real ObjectLocator::getIndexFor() and TopKIndex::canAnswer()
	// before the pagination doubles: the previous test accepted an invalid nested sort.
	class CursorIndexSelectionManager extends ObjectManager {
		public function __construct( $index ) {
			$this->indexes = [ $index ];
		}
	}
	$checkIndexSelection = static function ( array $indexedSort, string $declaredOrder, $requestedSort ) {
		// Only the index metadata is needed for selection; no cache or storage is touched.
		$index = ( new \\ReflectionClass( \\Flow\\Data\\Index\\TopKIndex::class ) )->newInstanceWithoutConstructor();
		( new \\ReflectionProperty( \\Flow\\Data\\Index\\FeatureIndex::class, 'indexedOrdered' ) )
			->setValue( $index, [ 'topic_list_id' ] );
		( new \\ReflectionProperty( \\Flow\\Data\\Index\\TopKIndex::class, 'options' ) )
			->setValue( $index, [ 'sort' => $indexedSort, 'order' => $declaredOrder, 'limit' => 500 ] );
		$manager = new CursorIndexSelectionManager( $index );
		$pager = new Pager( $manager, [ 'topic_list_id' => 'board' ], [
			'sort' => $requestedSort,
			'order' => 'desc',
			'pager-limit' => 10,
		] );
		check( $pager instanceof Pager, 'Real topic-list index is selected for requested sort' );
		return $manager;
	};
	$checkIndexSelection( TopicListCursor::SORT, 'desc', TopicListCursor::SORT );
	$checkIndexSelection( [ 'topic_id' ], 'DESC', 'topic_id' );

	$storage = new CursorFakeStorage;
	// More than TopKIndex's 500 cached items. 560 topics share the same second.
	for ( $i = 1; $i <= 660; $i++ ) {
		$id = str_pad( base_convert( (string)$i, 10, 36 ), 16, '0', STR_PAD_LEFT );
		$storage->all[] = [
			'topic_list_id' => 'board',
			'topic_id' => UUID::create( $id )->getBinary()->fetch(),
			'workflow_last_update_timestamp' => $i <= 560 ? '20261121135800' : '20261121135759',
		];
	}
	$manager = new CursorFakeManager( $storage );
	$options = [
		'sort' => TopicListCursor::SORT,
		'order' => 'desc',
		'sortby' => 'updated',
		'pager-composite-cursor' => true,
		'pager-limit' => 37,
	];
	$foundIds = [];
	$pages = [];
	$cursor = null;
	do {
		$paging = $options;
		if ( $cursor !== null ) {
			$paging['pager-offset'] = $cursor;
		}
		$page = ( new Pager( $manager, [ 'topic_list_id' => 'board' ], $paging ) )->getPage();
		$ids = array_map( static fn ( $item ) => $item->id, $page->getResults() );
		check( count( $ids ) > 0, 'Paging must never return a spurious empty page' );
		$pages[] = $ids;
		array_push( $foundIds, ...$ids );
		$links = $page->getPagingLinksOptions();
		$cursor = $links['fwd']['offset'] ?? null;
		check( count( $pages ) <= 20, 'No cursor cycle or infinite pagination' );
	} while ( $cursor !== null );
	check( count( $foundIds ) === 660, 'All 660 topics are visited exactly once' );
	check( count( array_unique( $foundIds ) ) === 660, 'Same-second ties never duplicate an ID' );
	check( $pages[0][0] === str_pad( base_convert( '560', 10, 36 ), 16, '0', STR_PAD_LEFT ),
		'Newest timestamp takes precedence over ID' );
	check( count( $pages ) === 18, 'Pagination reaches beyond the previous 500-item boundary' );

	// Go backwards from page 12: the SQL query must use ASC to select the
	// closest preceding page and restore DESC before the pager sees it.
	$anchor = $pages[11][0];
	$stamp = '20261121135800';
	$options['pager-dir'] = 'rev';
	$options['pager-offset'] = $stamp . '|' . $anchor;
	$page = ( new Pager( $manager, [ 'topic_list_id' => 'board' ], $options ) )->getPage();
	$reverseIds = array_map( static fn ( $item ) => $item->id, $page->getResults() );
	check( $reverseIds === $pages[10], 'Reverse cursor loads the immediately preceding page' );

	// Deep link to a given topic, including the selected item.
	$options['pager-dir'] = 'fwd';
	$options['pager-include-offset'] = true;
	$page = ( new Pager( $manager, [ 'topic_list_id' => 'board' ], $options ) )->getPage();
	check( $page->getResults()[0]->id === $anchor, 'Inclusive cursor retains targeted topic' );

	// Old timestamp-only URLs retain their previous exclusive behavior.
	unset( $options['pager-include-offset'] );
	$options['pager-offset'] = '20261121135800';
	$page = ( new Pager( $manager, [ 'topic_list_id' => 'board' ], $options ) )->getPage();
	check( $page->getResults()[0]->timestamp === '20261121135759', 'Legacy offset remains valid' );

	$parsed = TopicListCursor::parse( '20261121135800|' . $anchor );
	check( $parsed['topicId']->getAlphadecimal() === $anchor, 'Composite cursor round-trips' );
	check( TopicListCursor::parse( '20261121135800' )['topicId'] === null,
		'Old timestamp-only API calls still work' );
	foreach ( [ 'xyz', '20261121135800|INVALID', '20261121135800|deadbeef', '2026-33-99' ] as $bad ) {
		try {
			TopicListCursor::parse( $bad );
			throw new \LogicException( 'Invalid cursor was accepted' );
		} catch ( \Flow\Exception\InvalidInputException $e ) {
			check( true, 'Invalid/malicious cursor rejected' );
		}
	}
	echo 'OK: ' . $GLOBALS['flow_test_assertions'] . " updated-pagination assertions (660 topics, reverse, deep-link, legacy)\n";
}
