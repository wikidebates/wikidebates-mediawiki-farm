<?php
/** Run with php extensions/Flow/tests/smoke/FlowPrefetchedRootsTest.php. */
require __DIR__ . '/bootstrap.php';

use Flow\Collection\CollectionCache;
use Flow\Container;
use Flow\Data\ManagerGroup;
use Flow\Formatter\FormatterRow;
use Flow\Formatter\TopicListQuery;
use Flow\Model\AbstractRevision;
use Flow\Model\PostRevision;
use Flow\Model\UUID;
use Flow\Repository\TreeRepository;
use Flow\RevisionActionPermissions;
use Flow\WatchedTopicItems;

class PrefetchedTestPost extends PostRevision {
	public function __construct( string $id, string $revisionId, ?UUID $parent = null ) {
		$this->postId = UUID::create( $id );
		$this->revId = UUID::create( $revisionId );
		$this->replyToId = $parent;
	}
}
class PrefetchedTestStorage extends ManagerGroup {
	public array $items = [];
	public array $requests = [];
	public function __construct() {
	}
	public function findMulti( ...$args ) {
		check( $args[0] === 'PostRevision', 'Only unresolved post revisions are fetched' );
		$this->requests[] = $args[1];
		$found = [];
		foreach ( $args[1] as $query ) {
			$id = $query['rev_type_id']->getAlphadecimal();
			if ( isset( $this->items[$id] ) ) {
				$found[] = [ $this->items[$id] ];
			}
		}
		return $found;
	}
}
class PrefetchedTestPermissions extends RevisionActionPermissions {
	public array $hidden = [];
	public int $primeCalls = 0;
	public function __construct() {
	}
	public function primeCacheForView( array $revisions ) {
		$this->primeCalls++;
	}
	public function isAllowed( AbstractRevision $revision, $action ) {
		return !isset( $this->hidden[$revision->getRevisionId()->getAlphadecimal()] );
	}
}
class PrefetchedTestQuery extends TopicListQuery {
	public array $ids = [];
	protected function collectPostIds( array $topicIds ) {
		return $this->ids;
	}
	protected function loadMetadataBatch( $results ) {
	}
	protected function collectSummary( $topicIds ) {
		return [];
	}
	protected function collectWatchStatus( $topicIds ) {
		return [];
	}
	protected function buildResult( AbstractRevision $revision, $indexField, ?FormatterRow $row = null ) {
		$row ??= new FormatterRow;
		$row->revision = $revision;
		$row->currentRevision = $revision;
		return $row;
	}
}

$cache = new CollectionCache;
Container::$services['collection.cache'] = $cache;
$rootId = UUID::create( '0000000000000001' );
$replyId = UUID::create( '0000000000000002' );
$otherId = UUID::create( '0000000000000003' );
$root = new PrefetchedTestPost( '0000000000000001', '0000000000000011' );
$reply = new PrefetchedTestPost( '0000000000000002', '0000000000000012', $rootId );
$other = new PrefetchedTestPost( '0000000000000003', '0000000000000013' );
$storage = new PrefetchedTestStorage;
$storage->items = [
	$rootId->getAlphadecimal() => $root,
	$replyId->getAlphadecimal() => $reply,
	$otherId->getAlphadecimal() => $other,
];
$perms = new PrefetchedTestPermissions;
$query = new PrefetchedTestQuery(
	$storage,
	( new ReflectionClass( TreeRepository::class ) )->newInstanceWithoutConstructor(),
	$perms,
	( new ReflectionClass( WatchedTopicItems::class ) )->newInstanceWithoutConstructor()
);
$query->ids = [ $rootId->getAlphadecimal() => $rootId, $replyId->getAlphadecimal() => $replyId ];
$queryTiming = [];
$query->setBenchmarkTimer(
	static function ( string $phase, float $milliseconds ) use ( &$queryTiming ): void {
		$queryTiming[$phase][] = $milliseconds;
	}
);
$primeObserver = new ReflectionProperty( RevisionActionPermissions::class, 'primeBenchmarkTimer' );
check( $primeObserver->getValue( $perms ) !== null,
	'Query observer propagates to permission priming without changing formatting metrics' );
$rows = $query->getResults( [ $rootId ], [ $rootId->getAlphadecimal() => $root ] );
check( count( $storage->requests ) === 1, 'One latest-post batch is issued' );
check( count( $storage->requests[0] ) === 1, 'Already loaded root is absent from latest-revision SQL' );
check( $storage->requests[0][0]['rev_type_id'] === $replyId, 'Only reply is fetched' );
check( $rows[0]->revision === $root && $rows[1]->revision === $reply, 'Same root and reply objects rendered' );
check( $rows[0]->replies === [ $replyId->getAlphadecimal() ], 'Replies remain attached to root' );
check( $perms->primeCalls === 1, 'Normal query eagerly warms the permissions cache' );
$visibleIds = static function ( array $rows ): array {
	return array_map(
		static function ( FormatterRow $row ): array {
			return [
				$row->revision->getPostId()->getAlphadecimal(),
				$row->revision->getRevisionId()->getAlphadecimal(),
				$row->replies,
			];
		},
		$rows
	);
};
$originalIds = $visibleIds( $rows );
foreach ( [
	'prepare_roots', 'collect_post_ids', 'collect_revisions', 'load_metadata',
	'prime_topic_keys', 'prime_permissions', 'filter_visible_topics',
	'collect_summaries', 'collect_watch_status', 'build_visible_rows',
	'visible_row_permissions', 'attach_replies',
] as $name ) {
	check(
		count( $queryTiming['query_detail_' . $name] ?? [] ) === 1 &&
		$queryTiming['query_detail_' . $name][0] >= 0,
		'Query profiling records the nonnegative ' . $name . ' duration exactly once'
	);
}
$query->setBenchmarkTimer( null );
check( $primeObserver->getValue( $perms ) === null,
	'Query observer removal also clears the permission priming callback' );
$queryTimingCount = array_sum( array_map( 'count', $queryTiming ) );

// A/B mode must perform exactly the same view checks and emit the same
// structural rows, but skip *only* the optional cache warming pass.
$query->setBenchmarkSkipPermissionPrewarm( true );
$lazyRows = $query->getResults( [ $rootId ], [ $rootId->getAlphadecimal() => $root ] );
check( $visibleIds( $lazyRows ) === $originalIds,
	'Benchmark lazy prewarm mode preserves the same visible root, post and replies' );
check( $perms->primeCalls === 1, 'Benchmark lazy mode does not invoke eager priming' );
$query->setBenchmarkSkipPermissionPrewarm( false );
$normalRows = $query->getResults( [ $rootId ], [ $rootId->getAlphadecimal() => $root ] );
check( $visibleIds( $normalRows ) === $originalIds,
	'Restoring eager mode preserves the original structural output' );
check( $perms->primeCalls === 2, 'Eager prewarm is restored after the benchmark switch is cleared' );

// Hidden content must not become visible when priming is disabled.
$perms->hidden[$root->getRevisionId()->getAlphadecimal()] = true;
$query->setBenchmarkSkipPermissionPrewarm( true );
$lazyHiddenRows = $query->getResults( [ $rootId ], [ $rootId->getAlphadecimal() => $root ] );
check( count( $lazyHiddenRows ) === 1 && $lazyHiddenRows[0]->revision === $reply,
	'Lazy prewarm mode still excludes a hidden topic root' );
$query->setBenchmarkSkipPermissionPrewarm( false );
$eagerHiddenRows = $query->getResults( [ $rootId ], [ $rootId->getAlphadecimal() => $root ] );
check( $visibleIds( $eagerHiddenRows ) === $visibleIds( $lazyHiddenRows ),
	'Hidden-root behavior remains identical in both prewarm modes' );
$perms->hidden = [];

// A root from another page (or keyed to the wrong topic) must never be
// trusted merely because it was passed into this method.
$cache->onAfterClear();
$storage->requests = [];
$rows = $query->getResults( [ $rootId ], [ $rootId->getAlphadecimal() => $other ] );
check( count( $storage->requests[0] ) === 2, 'Mismatched prefetched root is ignored' );
check( $rows[0]->revision === $root, 'Wrong topic cannot contaminate another result' );
check(
	array_sum( array_map( 'count', $queryTiming ) ) === $queryTimingCount,
	'Disabling the query profiler stops all measurements without altering rendering'
);

// The query is a shared service. Prefer a freshly retrieved revision over an
// older in-process cache hit, then invalidate on moderation/storage updates.
$rootEdited = new PrefetchedTestPost( '0000000000000001', '0000000000000021' );
$storage->items[$rootId->getAlphadecimal()] = $rootEdited;
$storage->requests = [];
$rows = $query->getResults( [ $rootId ], [ $rootId->getAlphadecimal() => $rootEdited ] );
check( $rows[0]->revision === $rootEdited, 'Latest prefetched root replaces stale cached revision' );
check( $storage->requests === [], 'Replies remain cached within the same storage generation' );
$perms->hidden[$rootEdited->getRevisionId()->getAlphadecimal()] = true;
$rows = $query->getResults( [ $rootId ], [ $rootId->getAlphadecimal() => $rootEdited ] );
check( count( $rows ) === 1 && $rows[0]->revision === $reply,
	'Original view permission checks are still applied to prefetched roots' );

// No optional parameter: historical callers continue to fetch the root.
$cache->onAfterUpdate( $rootEdited, [], [], [] );
$storage->requests = [];
$perms->hidden = [];
$rows = $query->getResults( [ $rootId ] );
check( count( $storage->requests[0] ) === 2, 'Search and older callers retain their original fetch behavior' );
check( $rows[0]->revision === $rootEdited, 'No-prefetch calls see the newest stored revision' );

// Reuse actual subtree membership for topic keys, without changing view
// permission checks. The query still fetches the same revisions and roots.
class TopicKeyTree extends TreeRepository {
	public array $nodeList = [];
	public int $calls = 0;
	public function __construct() {
	}
	public function fetchSubtreeNodeList( array $roots ) {
		$this->calls++;
		return $this->nodeList;
	}
}
class RecordingTopicPermissions extends PrefetchedTestPermissions {
	public array $maps = [];
	public function primeTopicCacheKeysForView( array $revisions, array $topicIdsByPost ): void {
		$this->maps[] = $topicIdsByPost;
	}
}
class TopicKeyQuery extends TopicListQuery {
	protected function loadMetadataBatch( $results ) {
	}
	protected function collectSummary( $topicIds ) {
		return [];
	}
	protected function collectWatchStatus( $topicIds ) {
		return [];
	}
	protected function buildResult( AbstractRevision $revision, $indexField, ?FormatterRow $row = null ) {
		$row ??= new FormatterRow;
		$row->revision = $revision;
		$row->currentRevision = $revision;
		return $row;
	}
}
$tree = new TopicKeyTree;
$primedPermissions = new RecordingTopicPermissions;
$primedStorage = new PrefetchedTestStorage;
$secondReplyId = UUID::create( '0000000000000004' );
$secondReply = new PrefetchedTestPost( '0000000000000004', '0000000000000014', $otherId );
$primedStorage->items = [
	$rootId->getAlphadecimal() => $rootEdited,
	$replyId->getAlphadecimal() => $reply,
	$otherId->getAlphadecimal() => $other,
	$secondReplyId->getAlphadecimal() => $secondReply,
];
$tree->nodeList = [
	$rootId->getAlphadecimal() => [ $rootId, $replyId ],
	$otherId->getAlphadecimal() => [ $otherId, $secondReplyId ],
];
$primingQuery = new TopicKeyQuery(
	$primedStorage,
	$tree,
	$primedPermissions,
	( new ReflectionClass( WatchedTopicItems::class ) )->newInstanceWithoutConstructor()
);
$primingQuery->getResults( [ $rootId, $otherId ] );
$expectedMap = [
	$replyId->getAlphadecimal() => $rootId->getAlphadecimal(),
	$secondReplyId->getAlphadecimal() => $otherId->getAlphadecimal(),
];
check( $primedPermissions->maps[0] === $expectedMap,
	'Topic-list query primes only reply-to-topic relationships from the selected subtrees' );
check( !isset( $primedPermissions->maps[0][$rootId->getAlphadecimal()] ),
	'Topic title keys are not primed from subtree membership' );
check( $tree->calls === 1, 'Only one subtree query builds post IDs and topic keys' );
$primingQuery->getResults( [ $rootId, $otherId ] );
check( $tree->calls === 1 && $primedPermissions->maps[1] === $expectedMap,
	'Repeated query reuses both the post set and its topic map' );

// Moving a reply to another subtree must refresh its topic identity.
$tree->nodeList = [
	$rootId->getAlphadecimal() => [ $rootId ],
	$otherId->getAlphadecimal() => [ $otherId, $replyId, $secondReplyId ],
];
$cache->onAfterUpdate( $reply, [], [], [] );
$primingQuery->getResults( [ $rootId, $otherId ] );
check( $tree->calls === 2, 'A collection mutation refetches the subtree map' );
check( $primedPermissions->maps[2][$replyId->getAlphadecimal()] === $otherId->getAlphadecimal(),
	'Mutation refreshes the primed topic ID for a moved reply' );

// Duplicate membership is ambiguous; leave it to the original tree
// lookup and never allow one topic to override another in the memo.
$tree->nodeList = [
	$rootId->getAlphadecimal() => [ $rootId, $replyId ],
	$otherId->getAlphadecimal() => [ $otherId, $replyId, $secondReplyId ],
];
$cache->onAfterClear();
$primingQuery->getResults( [ $rootId, $otherId ] );
check( !isset( $primedPermissions->maps[3][$replyId->getAlphadecimal()] ),
	'A reply found under multiple roots cannot be primed' );
check( $primedPermissions->maps[3][$secondReplyId->getAlphadecimal()] === $otherId->getAlphadecimal(),
	'Unambiguous replies retain the subtree fast path' );

echo 'OK: ' . $GLOBALS['flow_test_assertions'] . " root-reuse assertions\n";
