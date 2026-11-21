<?php
/**
 * Bounded-cache regression: 50 topic-list roots can contain substantially
 * more than 50 posts. The real MapCacheLRU is approximated by bootstrap.php.
 *
 * Run with:
 * php extensions/Flow/tests/smoke/FlowLastRevisionCacheCapacityTest.php
 */
require __DIR__ . '/bootstrap.php';

use Flow\Collection\CollectionCache;
use Flow\Model\PostRevision;
use Flow\Model\UUID;

class LruCollection {
	public int $reads = 0;
	public ?PostRevision $last = null;

	public function getLastRevision() {
		$this->reads++;
		return $this->last;
	}
}

class LruPost extends PostRevision {
	public function __construct( string $id, private LruCollection $collection ) {
		$this->postId = UUID::create( $id );
	}

	public function getCollection() {
		return $this->collection;
	}
}

$cache = new CollectionCache;
$revisions = [];
$collections = [];

// Representative 50-topic list: 242 different posts, one collection each.
for ( $i = 1; $i <= 242; $i++ ) {
	$collection = new LruCollection;
	$revision = new LruPost( sprintf( '%016x', $i ), $collection );
	$collection->last = $revision;
	$collections[] = $collection;
	$revisions[] = $revision;
}

foreach ( $revisions as $revision ) {
	check( $cache->getLastRevisionFor( $revision ) === $revision,
		'First read resolves each latest revision' );
}

foreach ( $revisions as $revision ) {
	check( $cache->getLastRevisionFor( $revision ) === $revision,
		'Second render yields identical latest revisions' );
}
check( array_sum( array_map( static fn ( LruCollection $collection ) => $collection->reads, $collections ) )
	=== count( $revisions ), 'All 242 last revisions are retained without an LRU eviction storm' );

// Mutating a revision still invalidates exactly its collection entry.
$changedCollection = $collections[41];
$changed = new LruPost( $revisions[41]->getPostId()->getAlphadecimal(), $changedCollection );
$changedCollection->last = $changed;
$oldGeneration = $cache->getGeneration();
$cache->onAfterInsert( $changed, [], [] );
check( $cache->getGeneration() === $oldGeneration + 1,
	'Insert advances the cache generation' );
check( $cache->getLastRevisionFor( $revisions[41] ) === $changed,
	'Insert invalidation loads a newer last revision' );
check( $changedCollection->reads === 2, 'Insert causes exactly one refreshed lookup' );

$oldGeneration = $cache->getGeneration();
$cache->onAfterUpdate( $changed, [], [], [] );
check( $cache->getGeneration() === $oldGeneration + 1,
	'Update advances the cache generation' );
check( $cache->getLastRevisionFor( $changed ) === $changed,
	'Update invalidates its cached last revision' );
check( $changedCollection->reads === 3, 'Update re-reads the last revision' );

$oldGeneration = $cache->getGeneration();
$cache->onAfterRemove( $changed, [], [] );
check( $cache->getGeneration() === $oldGeneration + 1,
	'Removal advances the cache generation' );
check( $cache->getLastRevisionFor( $changed ) === $changed,
	'Removal invalidates its cached last revision' );
check( $changedCollection->reads === 4, 'Removal re-reads the last revision' );

$oldGeneration = $cache->getGeneration();
$cache->onAfterClear();
check( $cache->getGeneration() === $oldGeneration + 1,
	'Clear advances the cache generation' );
check( $cache->getLastRevisionFor( $changed ) === $changed,
	'Clear invalidates cached last revisions' );
check( $changedCollection->reads === 5, 'Clear re-reads the last revision' );

// A larger LRU must remain bounded. Inserting 513 distinct items after clear
// should evict the oldest one, but preserve the most recent one.
$early = new LruCollection;
$first = new LruPost( sprintf( '%016x', 3000 ), $early );
$early->last = $first;
$cache->getLastRevisionFor( $first );
for ( $i = 3001; $i <= 3512; $i++ ) {
	$collection = new LruCollection;
	$revision = new LruPost( sprintf( '%016x', $i ), $collection );
	$collection->last = $revision;
	$cache->getLastRevisionFor( $revision );
	$last = $revision;
	$latest = $collection;
}
check( $cache->getLastRevisionFor( $last ) === $last && $latest->reads === 1,
	'Most recent revision remains cached after filling the LRU' );
check( $cache->getLastRevisionFor( $first ) === $first && $early->reads === 2,
	'Oldest revision is evicted once the 512-entry bound is exceeded' );

echo 'OK: ' . $GLOBALS['flow_test_assertions'] . " last revision LRU assertions\n";
