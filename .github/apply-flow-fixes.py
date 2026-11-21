from pathlib import Path
p = Path('extensions/Flow')
def edit(path, old, new, count=1):
	f=p/path
	s=f.read_text()
	assert s.count(old)==count, (path, s.count(old),count)
	f.write_text(s.replace(old,new))
edit('includes/Data/Storage/RevisionStorage.php', '"MAX( \'rev_id\' )"', "'MAX(rev_id)'")
edit('includes/Collection/CollectionCache.php', '\tprotected $lastRevCache;\n', '''\tprotected $lastRevCache;

	/** @var int In-process generation, advanced after writes or an explicit clear. */
	private $generation = 0;

	/**
	 * Consumers can discard derived request-local caches after a storage mutation.
	 * This is deliberately not a shared cache version or a database counter.
	 *
	 * @return int
	 */
	public function getGeneration(): int {
		return $this->generation;
	}
''')
edit('includes/Collection/CollectionCache.php', '\tpublic function onAfterClear() {\n', '\tpublic function onAfterClear() {\n\t\t$this->generation++;\n')
for signature in [
	'public function onAfterInsert( $object, array $new, array $metadata ) {',
	'public function onAfterUpdate( $object, array $old, array $new, array $metadata ) {',
	'public function onAfterRemove( $object, array $old, array $metadata ) {'
]:
	edit('includes/Collection/CollectionCache.php', '\t'+signature+'\n', '\t'+signature+'\n\t\t$this->generation++;\n')
edit('container.php', "\t\t'collection.cache' => $c['collection.cache'],\n", '')
edit('container.php', '\t$listeners = [\n', "\t$listeners = [\n\t\t// Invalidate derived request-local data before other listeners use it.\n\t\t'collection.cache' => $c['collection.cache'],\n",4)
edit('includes/RevisionActionPermissions.php', '\tprotected $boardAllowedCache = [];\n', '''\tprotected $boardAllowedCache = [];

	/** @var int|null Generation of the collection cache used by these decisions. */
	private $cacheGeneration;
''')
edit('includes/RevisionActionPermissions.php', '\tpublic function isAllowed( AbstractRevision $revision, $action ) {\n', '\tpublic function isAllowed( AbstractRevision $revision, $action ) {\n\t\t$this->ensureCacheIsFresh();\n')
edit('includes/RevisionActionPermissions.php', "$cacheKey = $revisionId . ':' . $action;", "$cacheKey = $revisionId . ':' . $revision->getModerationState() . ':' . $action;")
edit('includes/RevisionActionPermissions.php', '\tpublic function primeCacheForView( array $revisions ) {\n', '\tpublic function primeCacheForView( array $revisions ) {\n\t\t$this->ensureCacheIsFresh();\n')
edit('includes/RevisionActionPermissions.php', '\tprotected function getCachedRoot( AbstractRevision $revision ) {\n', '''\tprotected function getCachedRoot( AbstractRevision $revision ) {
		// A title (including a historical title) is its own root. Do not let it
		// populate the per-topic cache: replies must use the latest topic state.
		if ( !( $revision instanceof PostSummary ) &&
			!( $revision instanceof PostRevision && !$revision->isTopicTitle() )
		) {
			return $revision;
		}

''')
edit('includes/RevisionActionPermissions.php', '''		try {
			return $revision->getCollection()->getBoardWorkflow()->getId()->getAlphadecimal();
		} catch ( DataModelException $e ) {
			return $this->getTopicCacheKey( $revision );
		}
''', '''		// Finding the board just to build a key defeats the cache. A topic's
		// workflow identifies its board lookup; board decisions remain shared
		// by the actual board ID in isBoardAllowedCached().
		return $this->getTopicCacheKey( $revision );
''')
edit('includes/RevisionActionPermissions.php', '''	public function setUser( User $user ) {
		$this->user = $user;
	}
''', '''	public function setUser( User $user ) {
		// formatApi() calls this for every row. Preserve the cache when the
		// very same User object is reused, but not for another security context.
		if ( $this->user !== $user ) {
			$this->clearCache();
		}
		$this->user = $user;
	}

	/**
	 * Clear decisions explicitly, e.g. after changing a user's rights in-process.
	 */
	public function clearCache(): void {
		$this->isAllowedCache = [];
		$this->rootRevisionCache = [];
		$this->boardWorkflowCache = [];
		$this->rootAllowedCache = [];
		$this->boardAllowedCache = [];
	}

	/** Discard decisions and loaded roots after a Flow storage mutation. */
	private function ensureCacheIsFresh(): void {
		/** @var CollectionCache $cache */
		$cache = Container::get( 'collection.cache' );
		$generation = $cache->getGeneration();
		if ( $this->cacheGeneration !== $generation ) {
			$this->clearCache();
			$this->cacheGeneration = $generation;
		}
	}
''')
edit('includes/Formatter/TopicListQuery.php', 'use Flow\\Data\\ManagerGroup;\n', 'use Flow\\Collection\\CollectionCache;\nuse Flow\\Container;\nuse Flow\\Data\\ManagerGroup;\n')
edit('includes/Formatter/TopicListQuery.php', '/** @var PostSummary[] */\n\tprotected $summaryCache', '/** @var array<string,PostSummary|null> Null means a confirmed missing summary. */\n\tprotected $summaryCache')
edit('includes/Formatter/TopicListQuery.php', '\tprotected $watchStatusCache = [];\n', '''\tprotected $watchStatusCache = [];

	/** @var int|null Generation of the storage snapshot cached by this query. */
	private $cacheGeneration;
''')
edit('includes/Formatter/TopicListQuery.php', '\tpublic function getResults( array $topicIdsOrEntries ) {\n', '\tpublic function getResults( array $topicIdsOrEntries ) {\n\t\t$this->ensureCacheIsFresh();\n')
# Keep sibling replies, rather than overwriting the preceding sibling.
edit('includes/Formatter/TopicListQuery.php', "$replies[$replyToId ?? ''] = $postId;", "$replies[$replyToId ?? ''][] = $postId;")
f=p/'includes/Formatter/TopicListQuery.php'; s=f.read_text()
a=s.index('\tprotected function collectSummary( $topicIds ) {'); b=s.index('\n\t/**\n\t * @param UUID[] $postIds',a)
s=s[:a]+'''	protected function collectSummary( $topicIds ) {
		$this->ensureCacheIsFresh();
		if ( !$topicIds ) {
			return [];
		}
		$conds = [];
		foreach ( $topicIds as $topicId ) {
			$alpha = $topicId->getAlphadecimal();
			if ( !array_key_exists( $alpha, $this->summaryCache ) ) {
				$conds[$alpha] = [ 'rev_type_id' => $topicId ];
			}
		}
		if ( $conds ) {
			$found = $this->storage->findMulti( 'PostSummary', $conds, [
				'sort' => 'rev_id',
				'order' => 'DESC',
				'limit' => 1,
			] );
			// Only cache absence after a successful lookup, not after an error.
			foreach ( $conds as $alpha => $condition ) {
				$this->summaryCache[$alpha] = null;
			}
			foreach ( $found as $row ) {
				if ( !$row ) {
					continue;
				}
				$summary = reset( $row );
				$alpha = $summary->getSummaryTargetId()->getAlphadecimal();
				$this->summaryCache[$alpha] = $summary;
			}
		}

		$result = [];
		foreach ( $topicIds as $topicId ) {
			$alpha = $topicId->getAlphadecimal();
			if ( isset( $this->summaryCache[$alpha] ) ) {
				$result[$alpha] = $this->summaryCache[$alpha];
			}
		}
		return $result;
	}

	/** Invalidate positive and negative results when the storage changes. */
	private function ensureCacheIsFresh(): void {
		/** @var CollectionCache $cache */
		$cache = Container::get( 'collection.cache' );
		$generation = $cache->getGeneration();
		if ( $this->cacheGeneration === $generation ) {
			return;
		}
		$this->cacheGeneration = $generation;
		$this->postIdsCache = [];
		$this->summaryCache = [];
		$this->watchStatusCache = [];
		$this->rootPostIdCache = [];
		$this->postCache = [];
		$this->revisionCache = [];
		$this->workflowCache = [];
		$this->currentRevisionsCache = [];
		$this->identityMap = [];
	}
''' + s[b:]
f.write_text(s)
edit('modules/flow/ui/widgets/mw.flow.ui.CategoriesWidget.js', "mw.msg( 'colon-separator' )", "mw.message( 'colon-separator' ).escaped()")
edit('modules/tours/flowOptIn.js', "mw.msg( 'flow-guidedtour-optin-welcome-description' )", "mw.message( 'flow-guidedtour-optin-welcome-description' ).escaped()")
edit('modules/engine/components/board/features/flow-board-loadmore.js', '''		this.bindNodeHandlers( FlowBoardComponentLoadMoreFeatureMixin.UI.events );
''','''		this.bindNodeHandlers( FlowBoardComponentLoadMoreFeatureMixin.UI.events );
		// The base handler registers all topics first. Clean up once per batch,
		// not once per topic (which makes a batch quadratic in its size).
		this.bindComponentHandlers( {
			makeContentInteractive: this.pruneRenderedTopics
		} );
''')
edit('modules/engine/components/board/features/flow-board-loadmore.js', '''		var self = this,
			currentTopicId = $topic.data( 'flow-id' );

		// Store this topic by ID
		this.renderedTopics[ currentTopicId ] = $topic;

		// Remove any topics that are no longer on the page, just in case
''','''		var currentTopicId = $topic.data( 'flow-id' );

		// Store this topic by ID. Cleanup runs once after the whole batch.
		this.renderedTopics[ currentTopicId ] = $topic;
	}
	FlowBoardComponentLoadMoreFeatureMixin.UI.events.loadHandlers.topic = flowBoardComponentLoadMoreFeatureElementLoadTopic;

	/** Remove detached topic nodes after all load handlers in a batch have run. */
	FlowBoardComponentLoadMoreFeatureMixin.prototype.pruneRenderedTopics = function () {
		var self = this;

''')
edit('modules/engine/components/board/features/flow-board-loadmore.js', '''		} );
	}
	FlowBoardComponentLoadMoreFeatureMixin.UI.events.loadHandlers.topic = flowBoardComponentLoadMoreFeatureElementLoadTopic;

	//
	// Private functions
''','''		} );
	};

	//
	// Private functions
''')
print('Patched source files')

# Require the exact sixteen files validated locally, including the regression tests.
import hashlib
expected = {
	".github/workflows/flow-checks.yml": "46d5f6ba0fac3de83d4a69d191b77053143079755ca4d1605eae565931f6c438",
	"extensions/Flow/container.php": "48817be0d5aff119ed1d8a78941a6aab80ffb50cc41cbafb465bafadbf071d74",
	"extensions/Flow/docs/wikidebates-performance.md": "1f852c03468fcefea4d3d78b920d0f800cc44303c2bb4f57c297223204434f07",
	"extensions/Flow/includes/Collection/CollectionCache.php": "5395cb3eae5c3d9ca8b398a9c3bbbb10acf9ade4bd4fbc400ddeba4d5b88ae49",
	"extensions/Flow/includes/Data/Storage/RevisionStorage.php": "10388514db5dfb98d2b16768444b18478ab3e34048fcb5e85ba4d6d168d4a861",
	"extensions/Flow/includes/Formatter/TopicListQuery.php": "fe4d246059fb80519b95ec557520884afe5c5fcb1cdd20d72ace0e394bb9b9a6",
	"extensions/Flow/includes/RevisionActionPermissions.php": "776d25d82cab68f2de5ed58dbdefef9129c2d3a6b3f8f09a067f382db42720fd",
	"extensions/Flow/modules/engine/components/board/features/flow-board-loadmore.js": "ca12d68249edacad021e21095ddc0ee342c14c2fa5187a5a6d976a9c15b3f8da",
	"extensions/Flow/modules/flow/ui/widgets/mw.flow.ui.CategoriesWidget.js": "8524505fcad789e969f5ad273bc4274a34a3de5d24a271c40a65427006befbde",
	"extensions/Flow/modules/tours/flowOptIn.js": "53826858c4a6278710179a85d6641b6af0efd4fc83401627fe7dd762e1c3acc2",
	"extensions/Flow/scripts/benchmark-topiclist.py": "5cf1cb4514601db3d9de347758660823d9901fc2c46d21fe9d7ac8650f358486",
	"extensions/Flow/tests/smoke/FlowCacheTest.php": "4f4ac8dff42ec9e25155765ec86aee46f9e5e0baf4957a5feb29b10fb1baf5e7",
	"extensions/Flow/tests/smoke/bootstrap.php": "7941a8e55ddc32642b97cac4a42a770563b8bb06c4c79f73d4f1a30829c8745c",
	"extensions/Flow/tests/smoke/flow-loadmore.test.js": "b189b5ed61a962b709e97d0b82ebf02f922a568317051cce5cdccead235537f3",
	"extensions/Flow/tests/smoke/test_benchmark.py": "dc476a5145e22bfa1e09186980c7905abf6ec44165265986c073e7b9660ce7e0",
	"extensions/Flow/tests/smoke/test_revision_sql.py": "f8f8d84db1046a24af5038e5aa9f1903643abf0eb7dbaba83d5d47af5f210bf4"
}
for path, expected_hash in expected.items():
	actual = hashlib.sha256(Path(path).read_bytes()).hexdigest()
	assert actual == expected_hash, (path, actual, expected_hash)
print("All sixteen file checksums match the validated source")
