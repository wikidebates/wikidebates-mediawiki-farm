<?php
/** Run with php extensions/Flow/tests/smoke/FlowCacheTest.php. */
require __DIR__ . '/bootstrap.php';

use Flow\Collection\CollectionCache;
use Flow\Collection\PostCollection;
use Flow\Container;
use Flow\Data\ManagerGroup;
use Flow\FlowActions;
use Flow\Formatter\TopicListQuery;
use Flow\Model\AbstractRevision;
use Flow\Model\PostRevision;
use Flow\Model\PostSummary;
use Flow\Model\UUID;
use Flow\Model\Workflow;
use Flow\Repository\TreeRepository;
use Flow\RevisionActionPermissions;
use Flow\WatchedTopicItems;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;

class TestWorkflow extends Workflow {
	public bool $deleted = false;
	public function __construct() {
		$this->id = UUID::create( '0000000000000099' );
	}
	public function isDeleted() {
		return $this->deleted;
	}
}
class TestCollection {
	public $last;
	public int $boardLookups = 0;
	public function __construct( public UUID $topicId, public Workflow $board ) {
	}
	public function getWorkflowId() {
		return $this->topicId;
	}
	public function getBoardWorkflow() {
		$this->boardLookups++;
		return $this->board;
	}
	public function getLastRevision() {
		return $this->last;
	}
}
class TestPost extends PostRevision {
	public function __construct(
		string $id, string $revisionId, private TestCollection $testCollection,
		?UUID $parent = null, string $state = AbstractRevision::MODERATED_NONE
	) {
		$this->postId = UUID::create( $id );
		$this->revId = UUID::create( $revisionId );
		$this->replyToId = $parent;
		$this->moderationState = $state;
	}
	public function getCollection() {
		return $this->testCollection;
	}
	public function changeState( string $state ): void {
		$this->moderationState = $state;
	}
}
class TestSummary extends PostSummary {
	public function __construct( private TestCollection $testCollection ) {
		$this->summaryTargetId = $testCollection->topicId;
	}
	public function getCollection() {
		return $this->testCollection;
	}
}
class SummaryStorage extends ManagerGroup {
	public int $calls = 0;
	public array $rows = [];
	public bool $fail = false;
	public array $queries = [];
	public function __construct() {
	}
	public function findMulti( ...$args ) {
		$this->calls++;
		$this->queries = $args[1];
		if ( $this->fail ) {
			throw new RuntimeException( 'Simulated lookup failure' );
		}
		return $this->rows;
	}
}
class SummaryQuery extends TopicListQuery {
	public function summaries( array $ids ) {
		return $this->collectSummary( $ids );
	}
}

$cache = new CollectionCache;
Container::$services['collection.cache'] = $cache;
$board = new TestWorkflow;
$topicId = UUID::create( '0000000000000001' );
$topicCollection = new TestCollection( $topicId, $board );
$topic = new TestPost( '0000000000000001', '0000000000000011', $topicCollection );
$topicCollection->last = $topic;
PostCollection::$collections[$topicId->getAlphadecimal()] = $topicCollection;
$postCollection = new TestCollection( $topicId, $board );
$post = new TestPost( '0000000000000002', '0000000000000012', $postCollection, $topicId );
$postCollection->last = $post;

$actions = new FlowActions( [
	'view' => [
		'permissions' => [ '' => 'read', 'hide' => 'read-hidden', 'delete' => 'deletedhistory',
			'suppress' => 'suppressrevision', 'lock' => 'read' ],
		'root-permissions' => [ '' => 'read', 'hide' => 'read-hidden', 'delete' => 'deletedhistory',
			'suppress' => 'suppressrevision', 'lock' => 'read' ],
		'core-delete-permissions' => 'deletedhistory',
	],
	'reply' => [
		'performs-writes' => true,
		'permissions' => [ '' => 'edit' ],
		'root-permissions' => [ '' => 'edit', 'lock' => 'flow-lock' ],
		'core-delete-permissions' => 'deletedhistory',
	],
] );
$reader = new User( [ 'read', 'edit' ] );
$admin = new User( [ 'read', 'edit', 'deletedhistory', 'suppressrevision', 'read-hidden', 'flow-lock' ] );
$permissions = new RevisionActionPermissions( $actions, $reader );
check( $permissions->isAllowed( $post, 'view' ), 'Reader can see an ordinary post' );
$checks = MediaWikiServices::$checks;
$permissions->setUser( $reader );
check( $permissions->isAllowed( $post, 'view' ), 'Same user remains allowed' );
check( MediaWikiServices::$checks === $checks, 'Same User object preserves memoization' );
check( $postCollection->boardLookups === 1, 'One board lookup, not a lookup to compute the key' );
 
// The optional read-only profiler reports a miss only when a permission
// decision is calculated. Reusing that decision never skips the original
// permission checks in later viewer or storage generations.
$trace = [];
$permissions->clearCache();
$permissions->setBenchmarkTimer(
	static function ( string $phase, float $milliseconds ) use ( &$trace ): void {
		$trace[$phase][] = $milliseconds;
	}
);
check( $permissions->isAllowed( $post, 'view' ), 'Profiling preserves an allowed read' );
check( count( $trace['format_detail_permission_cache_miss'] ?? [] ) === 1,
	'First read is a measured permission cache miss' );
check( count( $trace['format_detail_permission_revision_rights'] ?? [] ) === 1,
	'Cache miss includes initial revision rights' );
check( isset( $trace['format_detail_permission_rule_lookup'][0] ),
	'Rule lookup duration is measured during first rights check' );
check( $permissions->isAllowed( $post, 'view' ), 'Cached read decision stays allowed' );
check( count( $trace['format_detail_permission_cache_hit'] ?? [] ) === 1,
	'Second read is a cache hit, not a second computation' );
check( count( $trace['format_detail_permission_cache_miss'] ) === 1,
	'Cache hit did not trigger a second permission computation' );
$traceCount = array_sum( array_map( 'count', $trace ) );
$permissions->setBenchmarkTimer( null );
check( $permissions->isAllowed( $post, 'view' ), 'Disabling profiling preserves cached permission' );
check( array_sum( array_map( 'count', $trace ) ) === $traceCount,
	'Disabling profiling stops all permission metrics' );


$permissions->setUser( new User( [] ) );
check( !$permissions->isAllowed( $post, 'view' ), 'User change invalidates positive decisions' );
$permissions->setUser( $reader );
check( $permissions->isAllowed( $post, 'view' ), 'User change also invalidates negative decisions' );

foreach ( [ 'lock', 'hide', 'delete', 'suppress', '' ] as $n => $state ) {
	$topicCollection->last = new TestPost(
		'0000000000000001', '000000000000003' . $n, $topicCollection, null, $state
	);
	$cache->onAfterInsert( $topicCollection->last, [], [] );
	check( $permissions->isAllowed( $post, 'reply' ) === ( $state === '' ), 'Reply after state change: ' . $state );
	check( $permissions->isAllowed( $post, 'view' ) === in_array( $state, [ '', 'lock' ], true ), 'View after state change: ' . $state );
}

$board->deleted = true;
$cache->onAfterUpdate( $board, [], [], [] );
check( !$permissions->isAllowed( $post, 'view' ), 'Board deletion invalidates view' );
$permissions->setUser( $admin );
check( $permissions->isAllowed( $post, 'view' ), 'Authorized user can still see a deleted board' );
$permissions->setUser( $reader );
check( !$permissions->isAllowed( $post, 'view' ), 'Admin decision cannot leak to reader' );
$board->deleted = false;
$cache->onAfterUpdate( $board, [], [], [] );
check( $permissions->isAllowed( $post, 'view' ), 'Board restoration invalidates a denial' );

// Same revision ID, changed moderation state (e.g. in-place suppression).
$post->changeState( 'suppress' );
check( !$permissions->isAllowed( $post, 'view' ), 'In-place revision state is part of the key' );
$post->changeState( '' );
$cache->onAfterClear();
check( $permissions->isAllowed( $post, 'reply' ), 'Explicit clear makes the state fresh' );

// Looking at a historical title must not poison the latest root used for replies.
$topicCollection->last = new TestPost( '0000000000000001', '0000000000000060', $topicCollection, null, 'lock' );
$cache->onAfterInsert( $topicCollection->last, [], [] );
$permissions->isAllowed( $topic, 'view' );
check( !$permissions->isAllowed( $post, 'reply' ), 'Historical title cannot bypass current topic lock' );
$permissions->clearCache();
check( !$permissions->isAllowed( $post, 'reply' ), 'Reply-first ordering also respects current lock' );
$permissions->isAllowed( $topic, 'view' );
check( !$permissions->isAllowed( $post, 'reply' ), 'Historical title cannot replace a primed current root' );

$storage = new SummaryStorage;
$query = new SummaryQuery(
	$storage,
	( new ReflectionClass( TreeRepository::class ) )->newInstanceWithoutConstructor(),
	$permissions,
	( new ReflectionClass( WatchedTopicItems::class ) )->newInstanceWithoutConstructor()
);
$otherId = UUID::create( '0000000000000003' );
check( $query->summaries( [ $topicId, $otherId, $topicId ] ) === [], 'Missing summaries return no placeholder rows' );
check( count( $storage->queries ) === 2, 'Duplicate topic IDs are deduplicated' );
$query->summaries( [ $otherId, $topicId ] );
check( $storage->calls === 1, 'Missing summaries are cached regardless of request order' );

$summary = new TestSummary( $topicCollection );
$storage->rows = [ $topicId->getAlphadecimal() => [ $summary ], $otherId->getAlphadecimal() => [] ];
$cache->onAfterInsert( $summary, [], [] );
check( $query->summaries( [ $topicId, $otherId ] ) === [ $topicId->getAlphadecimal() => $summary ], 'Creation invalidates negative cache; empty rows are safe' );
$query->summaries( [ $topicId, $otherId ] );
check( $storage->calls === 2, 'Both positive and negative entries are reused' );

$storage->rows = [];
$cache->onAfterRemove( $summary, [], [] );
check( $query->summaries( [ $topicId ] ) === [], 'Removal invalidates a positive summary entry' );
$cache->onAfterClear();
$storage->fail = true;
try {
	$query->summaries( [ $topicId ] );
	throw new LogicException( 'Expected lookup to fail' );
} catch ( RuntimeException $e ) {
	check( $e->getMessage() === 'Simulated lookup failure', 'Storage exceptions propagate' );
}
$storage->fail = false;
$storage->rows = [ [ $summary ] ];
check( $query->summaries( [ $topicId ] ) === [ $topicId->getAlphadecimal() => $summary ], 'A failed lookup does not cache absence' );

// Cache invalidation must be wired before consumers for all four model managers.
$container = file_get_contents( __DIR__ . '/../../container.php' );
check( substr_count( $container, "'collection.cache' => \$c['collection.cache']" ) === 4, 'Post, summary, header and workflow listeners are wired' );
echo 'OK: ' . $GLOBALS['flow_test_assertions'] . " cache assertions\n";

class SiblingQuery extends SummaryQuery {
	public array $inputPosts = [];
	protected function collectPostIds( array $topicIds ) {
		return array_map( static fn ( $post ) => $post->getPostId(), $this->inputPosts );
	}
	protected function collectRevisions( array $postIds ) {
		return $this->inputPosts;
	}
	protected function loadMetadataBatch( $results ) {
	}
	protected function collectWatchStatus( $topicIds ) {
		return [];
	}
	protected function buildResult( AbstractRevision $revision, $indexField, ?\Flow\Formatter\FormatterRow $row = null ) {
		$row ??= new \Flow\Formatter\FormatterRow;
		$row->revision = $revision;
		return $row;
	}
}
$topicCollection->last = $topic;
$cache->onAfterClear();
$storage->rows = [];
$siblings = new SiblingQuery(
	$storage,
	( new ReflectionClass( TreeRepository::class ) )->newInstanceWithoutConstructor(),
	$permissions,
	( new ReflectionClass( WatchedTopicItems::class ) )->newInstanceWithoutConstructor()
);
$secondCollection = new TestCollection( $topicId, $board );
$second = new TestPost( '0000000000000004', '0000000000000014', $secondCollection, $topicId );
$secondCollection->last = $second;
foreach ( [ $topic, $post, $second ] as $revision ) {
	$siblings->inputPosts[$revision->getPostId()->getAlphadecimal()] = $revision;
}
$rows = $siblings->getResults( [ $topicId ] );
check( $rows[0]->replies === [ $post->getPostId()->getAlphadecimal(), $second->getPostId()->getAlphadecimal() ], 'Sibling replies are appended, not overwritten' );
check( $rows[1]->replies === [] && $rows[2]->replies === [], 'Leaf replies remain arrays' );
echo 'OK: ' . $GLOBALS['flow_test_assertions'] . " total cache and reply assertions\n";
