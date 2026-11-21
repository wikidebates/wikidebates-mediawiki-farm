<?php

namespace Flow;

use Closure;
use Flow\Collection\CollectionCache;
use Flow\Collection\PostCollection;
use Flow\Exception\DataModelException;
use Flow\Model\AbstractRevision;
use Flow\Model\PostRevision;
use Flow\Model\PostSummary;
use Flow\Model\Workflow;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;

/**
 * Role based security for revisions based on moderation state
 */
class RevisionActionPermissions {
	/**
	 * @var FlowActions
	 */
	protected $actions;

	/**
	 * @var User
	 */
	protected $user;

	/**
	 * Per-request memoization for permission checks.
	 *
	 * @var bool[]
	 */
	protected $isAllowedCache = [];

	/**
	 * Per-request cache of topic root revisions keyed by topic id.
	 *
	 * @var AbstractRevision[]
	 */
	protected $rootRevisionCache = [];

	/**
	 * Per-request cache of board workflows keyed by workflow id.
	 *
	 * @var Workflow[]
	 */
	protected $boardWorkflowCache = [];

	/**
	 * Per-request cache of root permission checks.
	 *
	 * @var bool[]
	 */
	protected $rootAllowedCache = [];

	/**
	 * Per-request cache of board permission checks.
	 *
	 * @var bool[]
	 */
	protected $boardAllowedCache = [];

	/**
	 * Resolve a revision collection's topic ID only once per viewer/storage
	 * generation. PostRevision::getCollection() creates a new collection each
	 * time, so its local cached root ID cannot be reused across permission
	 * checks for the same post. Only successful lookups are cached: the
	 * historical-revision fallback must remain specific to its revision ID.
	 *
	 * @var array<string,string>
	 */
	private $topicCacheKeys = [];

	/** @var int|null Generation of the collection cache used by these decisions. */
	private $cacheGeneration;

	/** @var callable|null Opt-in read-only benchmark timing observer. */
	private $benchmarkTimer;

	/** @var callable|null Separate query-only observer: do not mix query and formatting counters. */
	private $primeBenchmarkTimer;

	public function setBenchmarkTimer( ?callable $observer ): void {
		$this->benchmarkTimer = $observer;
	}

	public function setPrimeBenchmarkTimer( ?callable $observer ): void {
		$this->primeBenchmarkTimer = $observer;
	}

	/**
	 * Measure only during the explicit maintenance benchmark.
	 * Timings are nested and must never be added to their parent stages.
	 *
	 * @return mixed
	 */
	private function timePermissionOperation( string $phase, callable $operation ) {
		$started = hrtime( true );
		try {
			return $operation();
		} finally {
			( $this->benchmarkTimer )(
				'format_detail_permission_' . $phase,
				round( ( hrtime( true ) - $started ) / 1000000, 3 )
			);
		}
	}

	/**
	 * @param FlowActions $actions
	 * @param User $user
	 */
	public function __construct( FlowActions $actions, User $user ) {
		$this->user = $user;
		$this->actions = $actions;
	}

	/**
	 * Get the name of all the actions the user is allowed to perform.
	 *
	 * @param AbstractRevision|null $revision The revision to check permissions against
	 * @return array Array of action names that are allowed
	 */
	public function getAllowedActions( ?AbstractRevision $revision = null ) {
		$allowed = [];
		foreach ( array_keys( $this->actions->getActions() ) as $action ) {
			if ( $this->isAllowedAny( $revision, $action ) ) {
				$allowed[] = $action;
			}
		}
		return $allowed;
	}

	/**
	 * Check if a user is allowed to perform a certain action.
	 *
	 * @param AbstractRevision $revision
	 * @param string $action
	 * @return bool
	 */
	public function isAllowed( AbstractRevision $revision, $action ) {
		$this->ensureCacheIsFresh();
		$revisionId = $revision->getRevisionId()->getAlphadecimal();
		$cacheKey = $revisionId . ':' . $revision->getModerationState() . ':' . $action;
		if ( array_key_exists( $cacheKey, $this->isAllowedCache ) ) {
			if ( $this->benchmarkTimer !== null ) {
				// A zero duration is a counter event; the underlying cache lookup
				// and key construction remain included in their parent phase.
				( $this->benchmarkTimer )( 'format_detail_permission_cache_hit', 0.0 );
			}
			return $this->isAllowedCache[$cacheKey];
		}

		$result = $this->benchmarkTimer === null
			? $this->computeIsAllowed( $revision, $action )
			: $this->timePermissionOperation(
				'cache_miss', fn () => $this->computeIsAllowed( $revision, $action )
			);
		$this->isAllowedCache[$cacheKey] = $result;

		return $result;
	}

	/**
	 * @param AbstractRevision $revision
	 * @param string $action
	 * @return bool
	 */
	protected function computeIsAllowed( AbstractRevision $revision, $action ) {
		// Check the requested revision before consulting its topic or board.
		$revisionAllowed = $this->benchmarkTimer === null
			? $this->isRevisionAllowed( $revision, $action )
			: $this->timePermissionOperation(
				'revision_rights', fn () => $this->isRevisionAllowed( $revision, $action )
			);
		if ( !$revisionAllowed ) {
			return false;
		}

		$root = $this->benchmarkTimer === null
			? $this->getCachedRoot( $revision )
			: $this->timePermissionOperation(
				'root_lookup', fn () => $this->getCachedRoot( $revision )
			);
		// Replies must still be governed by the current root moderation state.
		if ( !$revision->getRevisionId()->equals( $root->getRevisionId() ) ) {
			$rootAllowed = $this->benchmarkTimer === null
				? $this->isRootAllowedCached( $root, $action )
				: $this->timePermissionOperation(
					'root_rights', fn () => $this->isRootAllowedCached( $root, $action )
				);
			if ( !$rootAllowed ) {
				return false;
			}
		}

		$workflow = $this->benchmarkTimer === null
			? $this->getCachedBoardWorkflow( $revision )
			: $this->timePermissionOperation(
				'board_lookup', fn () => $this->getCachedBoardWorkflow( $revision )
			);
		$boardAllowed = $this->benchmarkTimer === null
			? $this->isBoardAllowedCached( $workflow, $action )
			: $this->timePermissionOperation(
				'board_rights', fn () => $this->isBoardAllowedCached( $workflow, $action )
			);
		if ( !$boardAllowed ) {
			return false;
		}

		/** @var CollectionCache $cache */
		$cache = Container::get( 'collection.cache' );
		$last = $this->benchmarkTimer === null
			? $cache->getLastRevisionFor( $revision )
			: $this->timePermissionOperation(
				'latest_revision', fn () => $cache->getLastRevisionFor( $revision )
			);
		// Historical entries must still be checked against the current state.
		$isLastRevision = $last->getRevisionId()->equals( $revision->getRevisionId() );
		if ( !$isLastRevision ) {
			$lastAllowed = $this->benchmarkTimer === null
				? $this->isRevisionAllowed( $last, $action )
				: $this->timePermissionOperation(
					'latest_revision_rights', fn () => $this->isRevisionAllowed( $last, $action )
				);
			if ( !$lastAllowed ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if a user is allowed to perform certain actions.
	 *
	 * @param AbstractRevision|null $revision
	 * @param string $action Multiple parameters to check if either of the provided actions are allowed
	 * @return bool
	 */
	public function isAllowedAny( ?AbstractRevision $revision, $action /* [, $action2 [, ... ]] */ ) {
		$actions = func_get_args();
		// Pull $revision out of the actions list
		array_shift( $actions );

		foreach ( $actions as $action ) {
			if ( $this->isAllowed( $revision, $action ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a user is allowed to perform a certain action, against the latest
	 * root(topic) post related to the provided revision.  This is required for
	 * things like preventing replies to locked topics.
	 *
	 * @param AbstractRevision $root
	 * @param string $action
	 * @return bool
	 */
	public function isRootAllowed( AbstractRevision $root, $action ) {
		// If the `root-permissions` key is not set then it is allowed
		if ( !$this->actions->hasValue( $action, 'root-permissions' ) ) {
			return true;
		}

		$permission = $this->getPermission( $root, $action, 'root-permissions' );

		// If `root-permissions` is defined but not for the current state
		// then action is denied
		if ( $permission === null ) {
			return false;
		}

		return MediaWikiServices::getInstance()->getPermissionManager()
			->userHasAnyRight( $this->user, ...(array)$permission );
	}

	/**
	 * Check if a user is allowed to perform a certain action, depending on the
	 * status (deleted?) of the board.
	 *
	 * @param Workflow $workflow
	 * @param string $action
	 * @return bool
	 */
	public function isBoardAllowed( Workflow $workflow, $action ) {
		$permissions = $this->actions->getValue( $action, 'core-delete-permissions' );

		// If user is allowed to see deleted page content, there's no need to
		// even check if it's been deleted (additional storage lookup)
		$allowed = MediaWikiServices::getInstance()->getPermissionManager()
			->userHasAnyRight( $this->user, ...(array)$permissions );
		if ( $allowed ) {
			return true;
		}

		return !$workflow->isDeleted();
	}

	/**
	 * Check if a user is allowed to perform a certain action, only against 1
	 * specific revision (whereas the default isAllowed() will check if the
	 * given $action is allowed for both given and the most current revision)
	 *
	 * @param AbstractRevision|null $revision
	 * @param string $action
	 * @return bool
	 */
	public function isRevisionAllowed( ?AbstractRevision $revision, $action ) {
		// Users must have the core 'edit' permission to perform any write action in flow
		$performsWrites = $this->benchmarkTimer === null
			? $this->actions->getValue( $action, 'performs-writes' )
			: $this->timePermissionOperation(
				'write_flag_lookup', fn () => $this->actions->getValue( $action, 'performs-writes' )
			);
		$pm = MediaWikiServices::getInstance()->getPermissionManager();
		if ( $performsWrites && !$pm->userHasRight( $this->user, 'edit' ) ) {
			return false;
		}

		$permission = $this->getPermission( $revision, $action );

		// If no permission is defined for this state, then the action is not allowed
		// check if permission is set for this action
		if ( $permission === null ) {
			return false;
		}

		// Check if user is allowed to perform action against this revision
		return $pm->userHasAnyRight( $this->user, ...(array)$permission );
	}

	/**
	 * Returns the permission specified in FlowActions for the given action
	 * against the given revision's moderation state.
	 *
	 * @param AbstractRevision|null $revision
	 * @param string $action
	 * @param string $type
	 * @return Closure|string
	 */
	public function getPermission( ?AbstractRevision $revision, $action, $type = 'permissions' ) {
		// $revision may be null if the revision has yet to be created
		$moderationState = AbstractRevision::MODERATED_NONE;
		if ( $revision !== null ) {
			$moderationState = $revision->getModerationState();
		}
		$permission = $this->benchmarkTimer === null
			? $this->actions->getValue( $action, $type, $moderationState )
			: $this->timePermissionOperation(
				'rule_lookup',
				fn () => $this->actions->getValue( $action, $type, $moderationState )
			);

		// Some permissions may be more complex to be defined as simple array
		// values, in which case they're a Closure (which will accept
		// AbstractRevision & FlowActionPermissions as arguments)
		if ( $permission instanceof Closure ) {
			$permission = $this->benchmarkTimer === null
				? $permission( $revision, $this )
				: $this->timePermissionOperation(
					'rule_closure', fn () => $permission( $revision, $this )
				);
		}

		return $permission;
	}


	/**
	 * Prime only resolved post-to-topic identities that the topic-list tree
	 * lookup already returned. This method does not make or cache any access
	 * decisions; every view, root, board and moderation check still runs.
	 *
	 * Historical callers and other query types do not provide these keys.
	 * Do not override previously resolved conflicting keys, and never prime
	 * topic-title revisions: historical titles retain their original keys.
	 *
	 * @param AbstractRevision[] $revisions Current revisions selected by the topic list
	 * @param array<string,string> $topicIdsByPost Post ID to topic ID (alphadecimal)
	 */
	public function primeTopicCacheKeysForView( array $revisions, array $topicIdsByPost ): void {
		if ( !$topicIdsByPost ) {
			return;
		}

		$this->ensureCacheIsFresh();
		foreach ( $revisions as $revision ) {
			if ( !( $revision instanceof PostRevision ) || $revision->isTopicTitle() ) {
				continue;
			}
			$postAlpha = $revision->getPostId()->getAlphadecimal();
			$topicAlpha = $topicIdsByPost[$postAlpha] ?? null;
			if ( !is_string( $topicAlpha ) || $topicAlpha === '' || $topicAlpha === $postAlpha ) {
				continue;
			}
			$cacheKey = $revision->getRevisionType() . ':' . $postAlpha;
			if ( isset( $this->topicCacheKeys[$cacheKey] ) &&
				$this->topicCacheKeys[$cacheKey] !== $topicAlpha
			) {
				continue;
			}
			$this->topicCacheKeys[$cacheKey] = $topicAlpha;
		}
	}

	/**
	 * Warm per-request caches used by topic list rendering.
	 *
	 * @param AbstractRevision[] $revisions
	 * @return void
	 */
	public function primeCacheForView( array $revisions ) {
		$this->ensureCacheIsFresh();
		$rootNanoseconds = 0;
		$boardNanoseconds = 0;
		$observer = $this->primeBenchmarkTimer;
		foreach ( $revisions as $revision ) {
			if ( !( $revision instanceof AbstractRevision ) ) {
				continue;
			}

			$started = $observer !== null ? hrtime( true ) : null;
			$this->getCachedRoot( $revision );
			if ( $started !== null ) {
				$rootNanoseconds += hrtime( true ) - $started;
			}

			$started = $observer !== null ? hrtime( true ) : null;
			$this->getCachedBoardWorkflow( $revision );
			if ( $started !== null ) {
				$boardNanoseconds += hrtime( true ) - $started;
			}
		}
		if ( $observer !== null ) {
			$observer( 'query_detail_prime_root_revisions', round( $rootNanoseconds / 1000000, 3 ) );
			$observer( 'query_detail_prime_board_workflows', round( $boardNanoseconds / 1000000, 3 ) );
		}
	}

	/**
	 * @param AbstractRevision $revision
	 * @return AbstractRevision
	 */
	protected function getCachedRoot( AbstractRevision $revision ) {
		// A title (including a historical title) is its own root. Do not let it
		// populate the per-topic cache: replies must use the latest topic state.
		if ( !( $revision instanceof PostSummary ) &&
			!( $revision instanceof PostRevision && !$revision->isTopicTitle() )
		) {
			return $revision;
		}

		$topicKey = $this->getTopicCacheKey( $revision );
		if ( isset( $this->rootRevisionCache[$topicKey] ) ) {
			return $this->rootRevisionCache[$topicKey];
		}

		$root = $this->getRoot( $revision );
		$this->rootRevisionCache[$topicKey] = $root;

		return $root;
	}

	/**
	 * @param AbstractRevision $revision
	 * @return Workflow
	 */
	protected function getCachedBoardWorkflow( AbstractRevision $revision ) {
		$workflowKey = $this->getBoardWorkflowCacheKey( $revision );
		if ( isset( $this->boardWorkflowCache[$workflowKey] ) ) {
			return $this->boardWorkflowCache[$workflowKey];
		}

		$collection = $revision->getCollection();
		$workflow = $collection->getBoardWorkflow();
		$this->boardWorkflowCache[$workflowKey] = $workflow;

		return $workflow;
	}

	/**
	 * @param AbstractRevision $root
	 * @param string $action
	 * @return bool
	 */
	protected function isRootAllowedCached( AbstractRevision $root, $action ) {
		$cacheKey = $root->getRevisionId()->getAlphadecimal() . ':' . $action;
		if ( array_key_exists( $cacheKey, $this->rootAllowedCache ) ) {
			return $this->rootAllowedCache[$cacheKey];
		}

		$result = $this->isRootAllowed( $root, $action );
		$this->rootAllowedCache[$cacheKey] = $result;

		return $result;
	}

	/**
	 * @param Workflow $workflow
	 * @param string $action
	 * @return bool
	 */
	protected function isBoardAllowedCached( Workflow $workflow, $action ) {
		$cacheKey = $workflow->getId()->getAlphadecimal() . ':' . $action;
		if ( array_key_exists( $cacheKey, $this->boardAllowedCache ) ) {
			return $this->boardAllowedCache[$cacheKey];
		}

		$result = $this->isBoardAllowed( $workflow, $action );
		$this->boardAllowedCache[$cacheKey] = $result;

		return $result;
	}

	/**
	 * @param AbstractRevision $revision
	 * @return string
	 */
	protected function getTopicCacheKey( AbstractRevision $revision ) {
		if ( $revision instanceof PostSummary ) {
			return $revision->getSummaryTargetId()->getAlphadecimal();
		}

		// Avoid repeatedly traversing the Flow tree when the same post is
		// checked against many actions. The collection ID, unlike rev_id,
		// identifies the same post across historical revisions.
		try {
			$collectionKey = $revision->getRevisionType() . ':' .
				$revision->getCollectionId()->getAlphadecimal();
			if ( isset( $this->topicCacheKeys[$collectionKey] ) ) {
				return $this->topicCacheKeys[$collectionKey];
			}

			$topicKey = $revision->getCollection()->getWorkflowId()->getAlphadecimal();
			$this->topicCacheKeys[$collectionKey] = $topicKey;
			return $topicKey;
		} catch ( DataModelException $e ) {
			// No associated root (e.g. an isolated historical revision).
			// Do not memoize this fallback by collection: separate revisions
			// must still receive separate cache keys.
			return $revision->getRevisionId()->getAlphadecimal();
		}
	}

	/**
	 * @param AbstractRevision $revision
	 * @return string
	 */
	protected function getBoardWorkflowCacheKey( AbstractRevision $revision ) {
		// Finding the board just to build a key defeats the cache. A topic's
		// workflow identifies its board lookup; board decisions remain shared
		// by the actual board ID in isBoardAllowedCached().
		return $this->getTopicCacheKey( $revision );
	}
	/**
	 * @param AbstractRevision $revision
	 * @return AbstractRevision
	 */
	protected function getRoot( AbstractRevision $revision ) {
		if ( $revision instanceof PostSummary ) {
			$topicId = $revision->getSummaryTargetId();
		} elseif ( $revision instanceof PostRevision && !$revision->isTopicTitle() ) {
			try {
				$topicId = $revision->getCollection()->getWorkflowId();
			} catch ( DataModelException $e ) {
				// failed to locate root post (most likely in unit tests, where
				// we didn't store the tree)
				return $revision;
			}
		} else {
			// if we can't the revision it back to a root, this revision is root
			return $revision;
		}

		$collection = PostCollection::newFromId( $topicId );
		return $collection->getLastRevision();
	}

	/**
	 * @return User
	 */
	public function getUser() {
		return $this->user;
	}

	/**
	 * @return FlowActions
	 */
	public function getActions() {
		return $this->actions;
	}

	/**
	 * @param User $user
	 */
	public function setUser( User $user ) {
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
		$this->topicCacheKeys = [];
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
}
