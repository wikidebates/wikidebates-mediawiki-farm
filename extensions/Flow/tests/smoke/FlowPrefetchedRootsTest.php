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
	public function __construct() {
	}
	public function primeCacheForView( array $revisions ) {
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
$rows = $query->getResults( [ $rootId ], [ $rootId->getAlphadecimal() => $root ] );
check( count( $storage->requests ) === 1, 'One latest-post batch is issued' );
check( count( $storage->requests[0] ) === 1, 'Already loaded root is absent from latest-revision SQL' );
check( $storage->requests[0][0]['rev_type_id'] === $replyId, 'Only reply is fetched' );
check( $rows[0]->revision === $root && $rows[1]->revision === $reply, 'Same root and reply objects rendered' );
check( $rows[0]->replies === [ $replyId->getAlphadecimal() ], 'Replies remain attached to root' );

// A root from another page (or keyed to the wrong topic) must never be
// trusted merely because it was passed into this method.
$cache->onAfterClear();
$storage->requests = [];
$rows = $query->getResults( [ $rootId ], [ $rootId->getAlphadecimal() => $other ] );
check( count( $storage->requests[0] ) === 2, 'Mismatched prefetched root is ignored' );
check( $rows[0]->revision === $root, 'Wrong topic cannot contaminate another result' );

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

echo 'OK: ' . $GLOBALS['flow_test_assertions'] . " root-reuse assertions\n";
