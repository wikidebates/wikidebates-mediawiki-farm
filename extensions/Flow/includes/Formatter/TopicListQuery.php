<?php

namespace Flow\Formatter;

use Flow\Collection\CollectionCache;
use Flow\Container;
use Flow\Data\ManagerGroup;
use Flow\Exception\FlowException;
use Flow\Exception\InvalidDataException;
use Flow\Model\AbstractRevision;
use Flow\Model\PostRevision;
use Flow\Model\PostSummary;
use Flow\Model\TopicListEntry;
use Flow\Model\UUID;
use Flow\Repository\TreeRepository;
use Flow\RevisionActionPermissions;
use Flow\WatchedTopicItems;
use MediaWiki\Json\FormatJson;

class TopicListQuery extends AbstractQuery {

	/** @var RevisionActionPermissions */
	protected $permissions;
	/** @var WatchedTopicItems */
	protected $watchedTopicItems;

	/** @var UUID[][] */
	protected $postIdsCache = [];

	/** @var array<string,array<string,string>> Topic ID per post, keyed by topic-list cache key. */
	private $postTopicKeysCache = [];

	/** @var array<string,string> Only the most recently collected page's post-to-topic keys. */
	private $lastPostTopicKeys = [];

	/** @var array<string,PostSummary|null> Null means a confirmed missing summary. */
	protected $summaryCache = [];

	/** @var bool[] */
	protected $watchStatusCache = [];

	/** @var int|null Generation of the storage snapshot cached by this query. */
	private $cacheGeneration;

	/** @var callable|null Read-only profiling, enabled only for the maintenance benchmark. */
	private $benchmarkTimer;

	public function setBenchmarkTimer( ?callable $observer ): void {
		$this->benchmarkTimer = $observer;
	}

	private function beginBenchmarkStep(): ?int {
		return $this->benchmarkTimer !== null ? hrtime( true ) : null;
	}

	private function endBenchmarkStep( string $phase, ?int $started ): void {
		if ( $started !== null && $this->benchmarkTimer !== null ) {
			( $this->benchmarkTimer )(
				'query_detail_' . $phase,
				round( ( hrtime( true ) - $started ) / 1000000, 3 )
			);
		}
	}

	/**
	 * @param ManagerGroup $storage
	 * @param TreeRepository $treeRepository
	 * @param RevisionActionPermissions $permissions
	 * @param WatchedTopicItems $watchedTopicItems
	 */
	public function __construct(
		ManagerGroup $storage,
		TreeRepository $treeRepository,
		RevisionActionPermissions $permissions,
		WatchedTopicItems $watchedTopicItems
	) {
		parent::__construct( $storage, $treeRepository );
		$this->permissions = $permissions;
		$this->watchedTopicItems = $watchedTopicItems;
	}

	/**
	 * @param UUID[]|TopicListEntry[] $topicIdsOrEntries
	 * @param PostRevision[] $prefetchedRoots Latest, already moderated root revisions indexed by topic ID.
	 *  They must have been fetched for this page, not copied from a previous request.
	 * @return FormatterRow[]
	 * @suppress PhanUndeclaredMethod Types not inferred from instanceof
	 */
	public function getResults( array $topicIdsOrEntries, array $prefetchedRoots = [] ) {
		$started = $this->beginBenchmarkStep();
		$this->ensureCacheIsFresh();
		$topicIds = $this->getTopicIds( $topicIdsOrEntries );

		// TopicListBlock has already fetched the current root revisions to filter
		// hidden/deleted topics. Reuse only roots belonging to this page. Other
		// callers (e.g. search) continue to use the ordinary revision lookup.
		$requested = [];
		foreach ( $topicIds as $id ) {
			$requested[$id->getAlphadecimal()] = true;
		}
		foreach ( $prefetchedRoots as $alpha => $revision ) {
			if (
				$revision instanceof PostRevision && isset( $requested[$alpha] ) &&
				$revision->getPostId()->getAlphadecimal() === $alpha
			) {
				$this->postCache[$alpha] = $revision;
			}
		}
		$this->endBenchmarkStep( 'prepare_roots', $started );

		// Subclasses can override collectPostIds; never reuse a previous
		// page's topic mapping if an override does not provide one.
		$started = $this->beginBenchmarkStep();
		$this->lastPostTopicKeys = [];
		$allPostIds = $this->collectPostIds( $topicIds );
		$this->endBenchmarkStep( 'collect_post_ids', $started );

		$started = $this->beginBenchmarkStep();
		$posts = $this->collectRevisions( $allPostIds );
		$this->endBenchmarkStep( 'collect_revisions', $started );

		$missing = array_diff(
			array_keys( $allPostIds ),
			array_keys( $posts )
		);
		if ( $missing ) {
			throw new InvalidDataException(
				'Failed to load latest revision for post IDs: ' . FormatJson::encode( $missing ),
				'fail-load-data'
			);
		}

		$started = $this->beginBenchmarkStep();
		$this->loadMetadataBatch( $posts );
		$this->endBenchmarkStep( 'load_metadata', $started );

		// These associations came from the very subtree query used to select
		// the posts. Reuse them for building permission cache keys, without
		// pre-approving a single permission or bypassing moderation checks.
		$started = $this->beginBenchmarkStep();
		if ( $this->lastPostTopicKeys ) {
			$this->permissions->primeTopicCacheKeysForView( $posts, $this->lastPostTopicKeys );
		}
		$this->endBenchmarkStep( 'prime_topic_keys', $started );

		$started = $this->beginBenchmarkStep();
		$this->permissions->primeCacheForView( $posts );
		$this->endBenchmarkStep( 'prime_permissions', $started );

		$started = $this->beginBenchmarkStep();
		$visibleTopicIds = [];
		foreach ( $posts as $post ) {
			if ( $post->isTopicTitle() && $this->permissions->isAllowed( $post, 'view' ) ) {
				$visibleTopicIds[] = $post->getPostId();
			}
		}
		$this->endBenchmarkStep( 'filter_visible_topics', $started );

		$started = $this->beginBenchmarkStep();
		$topicSummary = $this->collectSummary( $visibleTopicIds );
		$this->endBenchmarkStep( 'collect_summaries', $started );

		$started = $this->beginBenchmarkStep();
		$watchStatus = $this->collectWatchStatus( $visibleTopicIds );
		$this->endBenchmarkStep( 'collect_watch_status', $started );

		$started = $this->beginBenchmarkStep();
		$results = [];
		$replies = [];
		foreach ( $posts as $post ) {
			try {
				if ( !$this->permissions->isAllowed( $post, 'view' ) ) {
					continue;
				}
				$row = new TopicRow;
				$this->buildResult( $post, null, $row );
				/** @var PostRevision $revision */
				$revision = $row->revision;
				$replyToId = $revision->getReplyToId();
				$replyToId = $replyToId ? $replyToId->getAlphadecimal() : null;
				$postId = $revision->getPostId()->getAlphadecimal();
				$replies[$replyToId ?? ''][] = $postId;
				if ( $post->isTopicTitle() ) {
					// Attach the summary
					if ( isset( $topicSummary[$postId] ) ) {
						$row->summary = $this->buildResult( $topicSummary[$postId], 'rev_id' );
					}
					// Attach the watch status
					if ( isset( $watchStatus[$postId] ) && $watchStatus[$postId] ) {
						$row->isWatched = true;
					}
				}
				$results[] = $row;
			} catch ( FlowException $e ) {
				\MWExceptionHandler::logException( $e );
			}
		}
		$this->endBenchmarkStep( 'build_visible_rows', $started );

		$started = $this->beginBenchmarkStep();
		foreach ( $results as $result ) {
			$alpha = $result->revision->getPostId()->getAlphadecimal();
			$result->replies = $replies[$alpha] ?? [];
		}
		$this->endBenchmarkStep( 'attach_replies', $started );

		return $results;
	}

	/**
	 * @param TopicListEntry[]|UUID[] $topicsIdsOrEntries Topic IDs as UUID entries or
	 *  TopicListEntry objects
	 * @return UUID[]
	 */
	protected function getTopicIds( array $topicsIdsOrEntries ) {
		$topicIds = [];
		foreach ( $topicsIdsOrEntries as $entry ) {
			if ( $entry instanceof UUID ) {
				$topicIds[] = $entry;
			} elseif ( $entry instanceof TopicListEntry ) {
				$topicIds[] = $entry->getId();
			}
		}
		return $topicIds;
	}

	/**
	 * @param UUID[] $topicIds
	 * @return UUID[] Indexed by alphadecimal representation
	 */
	protected function collectPostIds( array $topicIds ) {
		if ( !$topicIds ) {
			return [];
		}

		$cacheKeyParts = array_map(
			static function ( UUID $topicId ) {
				return $topicId->getAlphadecimal();
			},
			$topicIds
		);
		sort( $cacheKeyParts );
		$cacheKey = implode( ':', $cacheKeyParts );
		if ( isset( $this->postIdsCache[$cacheKey] ) ) {
			$this->lastPostTopicKeys = $this->postTopicKeysCache[$cacheKey] ?? [];
			return $this->postIdsCache[$cacheKey];
		}

		// Get the full list of postId's necessary
		$nodeList = $this->treeRepository->fetchSubtreeNodeList( $topicIds );

		// The subtree result already identifies each reply's topic. Do not
		// prime ambiguous posts that appear under more than one root or roots
		// that were not requested; those retain the original lookup path.
		$requested = array_fill_keys( $cacheKeyParts, true );
		$topicKeys = [];
		$ambiguous = [];
		foreach ( $nodeList as $rootAlpha => $descendants ) {
			if ( !isset( $requested[$rootAlpha] ) ) {
				continue;
			}
			foreach ( $descendants as $postId ) {
				if ( !( $postId instanceof UUID ) ) {
					continue;
				}
				$postAlpha = $postId->getAlphadecimal();
				if ( $postAlpha === $rootAlpha || isset( $ambiguous[$postAlpha] ) ) {
					continue;
				}
				if ( isset( $topicKeys[$postAlpha] ) && $topicKeys[$postAlpha] !== $rootAlpha ) {
					unset( $topicKeys[$postAlpha] );
					$ambiguous[$postAlpha] = true;
				} else {
					$topicKeys[$postAlpha] = $rootAlpha;
				}
			}
		}
		$this->postTopicKeysCache[$cacheKey] = $topicKeys;
		$this->lastPostTopicKeys = $topicKeys;

		// Merge all the children from the various posts into one array
		if ( !$nodeList ) {
			// It should have returned at least $topicIds
			wfDebugLog( 'Flow', __METHOD__ .
				': No result received from TreeRepository::fetchSubtreeNodeList' );
			$postIds = $topicIds;
		} elseif ( count( $nodeList ) === 1 ) {
			$postIds = reset( $nodeList );
		} else {
			$postIds = array_merge( ...array_values( $nodeList ) );
		}

		// re-index by alphadecimal id
		$this->postIdsCache[$cacheKey] = array_combine(
			array_map(
				static function ( UUID $x ) {
					return $x->getAlphadecimal();
				},
				$postIds
			),
			$postIds
		);

		return $this->postIdsCache[$cacheKey];
	}

	/**
	 * @param UUID[] $topicIds
	 * @return array
	 */
	protected function collectWatchStatus( $topicIds ) {
		$ids = [];
		$missingIds = [];
		foreach ( $topicIds as $topicId ) {
			$alpha = $topicId->getAlphadecimal();
			$ids[] = $alpha;
			if ( !array_key_exists( $alpha, $this->watchStatusCache ) ) {
				$missingIds[] = $alpha;
			}
		}

		if ( $missingIds ) {
			$fetched = $this->watchedTopicItems->getWatchStatus( $missingIds );
			foreach ( $missingIds as $alpha ) {
				$this->watchStatusCache[$alpha] = !empty( $fetched[$alpha] );
			}
		}

		$result = [];
		foreach ( $ids as $alpha ) {
			$result[$alpha] = !empty( $this->watchStatusCache[$alpha] );
		}

		return $result;
	}

	/**
	 * @param UUID[] $topicIds
	 * @return PostSummary[]
	 */
	protected function collectSummary( $topicIds ) {
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
		$this->postTopicKeysCache = [];
		$this->lastPostTopicKeys = [];
		$this->summaryCache = [];
		$this->watchStatusCache = [];
		$this->rootPostIdCache = [];
		$this->postCache = [];
		$this->revisionCache = [];
		$this->workflowCache = [];
		$this->currentRevisionsCache = [];
		$this->identityMap = [];
	}

	/**
	 * @param UUID[] $postIds
	 * @return PostRevision[] Indexed by alphadecimal post id
	 */
	protected function collectRevisions( array $postIds ) {
		$queries = [];
		$result = [];
		foreach ( $postIds as $postId ) {
			$alpha = $postId->getAlphadecimal();
			if ( isset( $this->postCache[$alpha] ) ) {
				$result[$alpha] = $this->postCache[$alpha];
				continue;
			}
			$queries[] = [ 'rev_type_id' => $postId ];
		}
		if ( $queries ) {
			$found = $this->storage->findMulti( 'PostRevision', $queries, [
				'sort' => 'rev_id',
				'order' => 'DESC',
				'limit' => 1,
			] );

			// index results by post id for later filtering
			foreach ( $found as $row ) {
				$revision = reset( $row );
				$alpha = $revision->getPostId()->getAlphadecimal();
				$this->postCache[$alpha] = $revision;
				$result[$alpha] = $revision;
			}
		}

		return $result;
	}

	/**
	 * Override parent, we only load the most recent version, so just
	 * return self.
	 * @param AbstractRevision $revision
	 * @return AbstractRevision
	 */
	protected function getCurrentRevision( AbstractRevision $revision ) {
		return $revision;
	}
}
