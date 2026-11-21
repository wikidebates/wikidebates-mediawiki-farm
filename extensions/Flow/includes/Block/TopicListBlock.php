<?php

namespace Flow\Block;

use Flow\Container;
use Flow\Data\Pager\Pager;
use Flow\Data\Pager\PagerPage;
use Flow\Data\Pager\TopicListCursor;
use Flow\Exception\FailCommitException;
use Flow\Formatter\TocTopicListFormatter;
use Flow\Formatter\TopicListFormatter;
use Flow\Formatter\TopicListQuery;
use Flow\Model\PostRevision;
use Flow\Model\TopicListEntry;
use Flow\Model\UUID;
use Flow\Model\Workflow;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use UserOptionsUpdateJob;

class TopicListBlock extends AbstractBlock {

	/**
	 * @var array
	 */
	protected $supportedPostActions = [ 'new-topic' ];

	/**
	 * @var array
	 */
	protected $supportedGetActions = [ 'view', 'view-topiclist' ];

	/**
	 * @var string[]
	 * @todo Fill in the template names
	 */
	protected $templates = [
		'view' => '',
		'new-topic' => 'newtopic',
	];

	/**
	 * @var Workflow|null
	 */
	protected $topicWorkflow;

	/**
	 * @var TopicListEntry|null
	 */
	protected $topicListEntry;

	/**
	 * @var PostRevision|null
	 */
	protected $topicTitle;

	/**
	 * @var PostRevision|null
	 */
	protected $firstPost;

	/**
	 * @var array
	 *
	 * Associative array mapping topic ID (in alphadecimal form) to PostRevision for the topic root.
	 */
	protected $topicRootRevisionCache = [];

	/**
	 * The limit of Table of Contents topics that are rendered per request
	 */
	private const TOCLIMIT = 50;

	/** @var PagerPage|null The full, unfiltered page already loaded for initial rendering. */
	private $fullPageForToc;

	/** @var Workflow[] Workflows already fetched for the full page. */
	private $fullWorkflowsForToc = [];

	/** @var string|null Sort mode of the saved initial page. */
	private $fullPageSortForToc;

	/** @var callable|null Optional phase timing observer for read-only maintenance benchmarking. */
	private $benchmarkTimer;

	/**
	 * Record timings only when explicitly requested by the maintenance benchmark.
	 * Standard API and page rendering do not install an observer.
	 */
	public function setBenchmarkTimer( ?callable $observer ): void {
		$this->benchmarkTimer = $observer;
	}

	private function beginBenchmarkPhase(): ?int {
		return $this->benchmarkTimer !== null ? hrtime( true ) : null;
	}

	private function endBenchmarkPhase( string $phase, ?int $started ): void {
		if ( $started !== null && $this->benchmarkTimer !== null ) {
			( $this->benchmarkTimer )( $phase, round( ( hrtime( true ) - $started ) / 1000000, 3 ) );
		}
	}

	protected function validate() {
		// for now, new topic is considered a new post; perhaps some day topic creation should get it's own permissions?
		if (
			!$this->permissions->isRevisionAllowed( null, 'new-post' ) ||
			!$this->permissions->isBoardAllowed( $this->workflow, 'new-post' )
		) {
			$this->addError( 'permissions', $this->context->msg( 'flow-error-not-allowed' ) );
			return;
		}
		if ( !isset( $this->submitted['topic'] ) || !is_string( $this->submitted['topic'] ) ) {
			unset( $this->submitted['topic'] );
			$this->addError( 'topic', $this->context->msg( 'flow-error-missing-title' ) );
			return;
		}
		$this->submitted['topic'] = trim( $this->submitted['topic'] );
		if ( strlen( $this->submitted['topic'] ) === 0 ) {
			$this->addError( 'topic', $this->context->msg( 'flow-error-missing-title' ) );
			return;
		}
		if ( mb_strlen( $this->submitted['topic'] ) > PostRevision::MAX_TOPIC_LENGTH ) {
			$this->addError( 'topic', $this->context->msg( 'flow-error-title-too-long', PostRevision::MAX_TOPIC_LENGTH ) );
			return;
		}

		if ( !isset( $this->submitted['content'] ) || trim( $this->submitted['content'] ) === '' ) {
			$this->addError( 'content', $this->context->msg( 'flow-error-missing-content' ) );
			return;
		}

		// creates Workflow, Revision & TopicListEntry objects to be inserted into storage
		[ $this->topicWorkflow, $this->topicListEntry, $this->topicTitle, $this->firstPost ] = $this->create();

		if ( !$this->checkSpamFilters( null, $this->topicTitle ) ) {
			return;
		}
		if ( $this->firstPost && !$this->checkSpamFilters( null, $this->firstPost ) ) {
			return;
		}
	}

	/**
	 * Creates the objects about to be inserted into storage:
	 * * $this->topicWorkflow
	 * * $this->topicListEntry
	 * * $this->topicTitle
	 * * $this->firstPost
	 *
	 * @return array Array of [$topicWorkflow, $topicListEntry, $topicTitle, $firstPost]
	 */
	protected function create() {
		$title = $this->workflow->getArticleTitle();
		$user = $this->context->getUser();
		$topicWorkflow = Workflow::create( 'topic', $title );
		$topicListEntry = TopicListEntry::create( $this->workflow, $topicWorkflow );
		$topicTitle = PostRevision::createTopicPost(
			$topicWorkflow,
			$user,
			// @phan-suppress-next-line PhanTypeArraySuspiciousNullable
			$this->submitted['topic']
		);

		$firstPost = null;
		if ( !empty( $this->submitted['content'] ) ) {
			$firstPost = $topicTitle->reply(
				$topicWorkflow,
				$user,
				// @phan-suppress-next-line PhanTypeArraySuspiciousNullable
				$this->submitted['content'],
				// default to wikitext when not specified, for old API requests
				$this->submitted['format'] ?? 'wikitext'
			);
			$topicTitle->setChildren( [ $firstPost ] );
		}

		return [ $topicWorkflow, $topicListEntry, $topicTitle, $firstPost ];
	}

	public function commit() {
		if ( $this->action !== 'new-topic' ) {
			throw new FailCommitException( 'Unknown commit action', 'fail-commit' );
		}

		$metadata = [
			'workflow' => $this->topicWorkflow,
			'board-workflow' => $this->workflow,
			'topic-title' => $this->topicTitle,
			'first-post' => $this->firstPost,
		];

		/*
		 * Order of storage is important! We've been changing when we stored
		 * workflow a couple of times. For now, it needs to be stored first:
		 * * TopicPageCreationListener.php (post listener) must first create the
		 *   Topic:Xyz page before NotificationListener.php (topic/post
		 *   listeners) creates notifications (& mails) that link to it
		 * * ReferenceExtractor.php (run from ReferenceRecorder.php, a post
		 *   listener) needs to parse content with Parsoid & for that it needs
		 *   the board title. AbstractRevision::getContent() will figure out
		 *   the title from the workflow: $this->getCollection()->getTitle()
		 * If you even feel the need to change the order, make sure you come
		 * up with a fix for the above things ;)
		 */
		$this->storage->put( $this->workflow, [] ); // 'discussion' workflow
		$this->storage->put( $this->topicWorkflow, $metadata ); // 'topic' workflow
		$this->storage->put( $this->topicListEntry, $metadata );
		$this->storage->put( $this->topicTitle, $metadata );
		if ( $this->firstPost !== null ) {
			$this->storage->put( $this->firstPost, $metadata + [
				'reply-to' => $this->topicTitle
			] );
		}

		$output = [
			'topic-page' => $this->topicWorkflow->getArticleTitle()->getPrefixedText(),
			'topic-id' => $this->topicTitle->getPostId(),
			'topic-revision-id' => $this->topicTitle->getRevisionId(),
			'post-id' => $this->firstPost ? $this->firstPost->getPostId() : null,
			'post-revision-id' => $this->firstPost ? $this->firstPost->getRevisionId() : null,
		];

		return $output;
	}

	public function renderTocApi( array $topicList, array $options ) {
		global $wgFlowDefaultLimit;

		$tocApiParams = array_merge(
			$options,
			[
				'toconly' => true,
				'limit' => self::TOCLIMIT
			]
		);

		$findOptions = $this->getFindOptions( $options );

		// include the current sortby option.  Note that when 'user' is either
		// submitted or defaulted to this is the resulting sort. ex: newest
		$tocApiParams['sortby'] = $findOptions['sortby'];

		// If the initial updated-sort page has no continuation, it contains
		// every visible topic. Reuse its root revisions and workflows instead of
		// running an identical first-page query. Never use a timestamp offset
		// for partial pages: several topics may share the same update time.
		if (
			$tocApiParams['sortby'] === 'updated' && $this->fullPageForToc !== null &&
			$this->fullPageSortForToc === 'updated' &&
			!isset( $findOptions['pager-offset'] ) &&
			count( $this->fullPageForToc->getResults() ) <= self::TOCLIMIT &&
			!isset( $this->fullPageForToc->getPagingLinksOptions()['fwd'] )
		) {
			$roots = [];
			$workflows = [];
			foreach ( $this->fullWorkflowsForToc as $workflow ) {
				$id = $workflow->getId()->getAlphadecimal();
				if ( isset( $this->topicRootRevisionCache[$id] ) ) {
					$roots[$id] = $this->topicRootRevisionCache[$id];
					$workflows[$id] = $workflow;
				}
			}
			/** @var TocTopicListFormatter $formatter */
			$formatter = Container::get( 'formatter.topiclist.toc' );
			return [
				'submitted' => $this->wasSubmitted() ? $this->submitted : $tocApiParams,
				'errors' => $this->errors,
				'sortby' => $findOptions['sortby'],
			] + $formatter->formatApi( $this->workflow, $roots, $workflows, $this->fullPageForToc );
		}

		// In the case of 'newest' sort, we could save ourselves trouble and only
		// produce the necessary 40 topics that are missing from the ToC, by taking
		// the latest UUID from the topic list.
		// This is a bit harder for the case of 'updated' which requires a timestamp,
		// so in that case, we can stick to having repeated topics and letting the
		// data model sort through which ones it needs to update and which ones it
		// may ignore.
		if ( $tocApiParams['sortby'] === 'newest' ) {
			// Make sure we found topiclist block
			// and that it actually has roots in it
			$existingRoots = isset( $topicList['roots'] ) && is_array( $topicList['roots'] ) ?
				$topicList['roots'] : [];

			if ( count( $existingRoots ) > 0 ) {
				// Add new offset-id and limit to the api parameters and change the limit
				$tocApiParams['offset-id'] = end( $existingRoots );
				$tocApiParams['limit'] = self::TOCLIMIT - $wgFlowDefaultLimit;
			}
		}

		return $this->renderApi( $tocApiParams );
	}

	public function renderApi( array $options ) {
		$phaseStarted = $this->beginBenchmarkPhase();
		$options = $this->preloadTexts( $options );

		$response = [
			'submitted' => $this->wasSubmitted() ? $this->submitted : $options,
			'errors' => $this->errors,
		];

		// Repeating the default until we use the API for everything (bug 72659)
		// Also, if this is removed other APIs (i.e. ApiFlowNewTopic) may need
		// to be adjusted if they trigger a rendering of this block.
		$isTocOnly = $options['toconly'] ?? false;

		if ( $isTocOnly ) {
			/** @var TocTopicListFormatter $serializer */
			$serializer = Container::get( 'formatter.topiclist.toc' );
		} else {
			/** @var TopicListFormatter $serializer */
			$serializer = Container::get( 'formatter.topiclist' );
			$format = $options['format'] ?? 'fixed-html';
			$serializer->setContentFormat( $format );
		}

		// @todo remove the 'api' => true, its always api
		$findOptions = $this->getFindOptions( $options + [ 'api' => true ] );

		// include the current sortby option.  Note that when 'user' is either
		// submitted or defaulted to this is the resulting sort. ex: newest
		$response['sortby'] = $findOptions['sortby'];
		$this->endBenchmarkPhase( 'prepare', $phaseStarted );

		if ( $this->workflow->isNew() ) {
			return $response + $serializer->buildEmptyResult( $this->workflow );
		}

		$phaseStarted = $this->beginBenchmarkPhase();
		$page = $this->getPage( $findOptions );
		$this->endBenchmarkPhase( 'page_and_root_revisions', $phaseStarted );
		$workflowIds = [];
		/** @var TopicListEntry $topicListEntry */
		foreach ( $page->getResults() as $topicListEntry ) {
			$workflowIds[] = $topicListEntry->getId();
		}

		$phaseStarted = $this->beginBenchmarkPhase();
		$workflows = $this->storage->getMulti( 'Workflow', $workflowIds );
		$this->endBenchmarkPhase( 'workflows', $phaseStarted );

		if ( !$isTocOnly ) {
			$this->fullPageForToc = $page;
			$this->fullWorkflowsForToc = $workflows;
			$this->fullPageSortForToc = $findOptions['sortby'];
		}

		if ( $isTocOnly ) {
			// We don't need any further data, so we skip the TopicListQuery.

			$topicRootRevisionsByWorkflowId = [];
			$workflowsByWorkflowId = [];

			foreach ( $workflows as $workflow ) {
				$alphaWorkflowId = $workflow->getId()->getAlphadecimal();
				$topicRootRevisionsByWorkflowId[$alphaWorkflowId] = $this->topicRootRevisionCache[$alphaWorkflowId];
				$workflowsByWorkflowId[$alphaWorkflowId] = $workflow;
			}

			return $response + $serializer->formatApi( $this->workflow, $topicRootRevisionsByWorkflowId, $workflowsByWorkflowId, $page );
		}

		$phaseStarted = $this->beginBenchmarkPhase();
		/** @var TopicListQuery $query */
		$query = Container::get( 'query.topiclist' );
		$prefetchedRoots = [];
		foreach ( $workflowIds as $id ) {
			$alpha = $id->getAlphadecimal();
			if ( isset( $this->topicRootRevisionCache[$alpha] ) ) {
				$prefetchedRoots[$alpha] = $this->topicRootRevisionCache[$alpha];
			}
		}
		if ( $this->benchmarkTimer !== null ) {
			$query->setBenchmarkTimer( $this->benchmarkTimer );
		}
		try {
			$found = $query->getResults( $page->getResults(), $prefetchedRoots );
		} finally {
			if ( $this->benchmarkTimer !== null ) {
				$query->setBenchmarkTimer( null );
			}
		}
		$this->endBenchmarkPhase( 'messages_and_permissions', $phaseStarted );
		wfDebugLog( 'FlowDebug', 'Rendering topiclist for ids: ' . implode( ', ', array_map( static function ( UUID $id ) {
			return $id->getAlphadecimal();
		}, $workflowIds ) ) );

		$phaseStarted = $this->beginBenchmarkPhase();
		if ( $this->benchmarkTimer !== null ) {
			$serializer->setBenchmarkTimer( $this->benchmarkTimer );
		}
		try {
			$formatted = $serializer->formatApi( $this->workflow, $workflows, $found, $page, $this->context );
		} finally {
			if ( $this->benchmarkTimer !== null ) {
				$serializer->setBenchmarkTimer( null );
			}
		}
		$this->endBenchmarkPhase( 'formatting', $phaseStarted );
		return $response + $formatted;
	}

	/**
	 * Transforms preload params into proper options we can assign to template.
	 *
	 * @param array $options
	 * @return array
	 */
	protected function preloadTexts( $options ) {
		if ( isset( $options['preload'] ) && !empty( $options['preload'] ) ) {
			$title = Title::newFromText( $options['preload'] );
			$wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
			$page = $wikiPageFactory->newFromTitle( $title );
			if ( $page->isRedirect() ) {
				$title = $page->getRedirectTarget();
				$page = $wikiPageFactory->newFromTitle( $title );
			}

			if ( $page->exists() ) {
				$content = $page->getContent( RevisionRecord::RAW );
				$options['content'] = $content->serialize();
				$options['format'] = 'wikitext';
			}
		}

		if ( isset( $options['preloadtitle'] ) ) {
			$options['topic'] = $options['preloadtitle'];
		}

		return $options;
	}

	public function getName() {
		return 'topiclist';
	}

	protected function getLimit( array $options ) {
		global $wgFlowDefaultLimit, $wgFlowMaxLimit;
		$limit = $wgFlowDefaultLimit;
		if ( isset( $options['limit'] ) ) {
			$requestedLimit = intval( $options['limit'] );
			$limit = min( $requestedLimit, $wgFlowMaxLimit );
			$limit = max( 0, $limit );
		}

		return $limit;
	}

	protected function getFindOptions( array $requestOptions ) {
		$findOptions = [];
		$services = MediaWikiServices::getInstance();
		$userOptionsLookup = $services->getUserOptionsLookup();

		// Compute offset/limit
		$limit = $this->getLimit( $requestOptions );

		// @todo Once we migrate View.php to use the API directly
		// all defaults will be handled by API and not here.
		$requestOptions += [
			'include-offset' => false,
			'offset-id' => false,
			'offset-dir' => 'fwd',
			'offset' => false,
			'api' => true,
			'sortby' => 'user',
			'savesortby' => false,
		];

		$user = $this->context->getUser();
		if ( strlen( $requestOptions['sortby'] ) === 0 ) {
			$requestOptions['sortby'] = 'user';
		}
		// the sortby option in $findOptions is not directly used for querying,
		// but is needed by the pager to generate appropriate pagination links.
		if ( $requestOptions['sortby'] === 'user' ) {
			$requestOptions['sortby'] = $userOptionsLookup->getOption( $user, 'flow-topiclist-sortby' );
		}
		switch ( $requestOptions['sortby'] ) {
			case 'updated':
				$findOptions = [
					// @phan-suppress-next-line PhanUselessBinaryAddRight
					'sortby' => 'updated',
					'sort' => TopicListCursor::SORT,
					'order' => 'desc',
					'pager-composite-cursor' => true,
				] + $findOptions;

				if ( $requestOptions['offset-id'] ) {
					$requestOptions['offset-id'] = null;
					$this->context->getOutput()->addHTML(
						Html::warningBox( $this->context->msg( 'flow-invalid-param-offset-id-for-updated' )->parse() )
					);
				}
				break;

			case 'newest':
			default:
				$findOptions = [
					// @phan-suppress-next-line PhanUselessBinaryAddRight
					'sortby' => 'newest',
					'sort' => 'topic_id',
					'order' => 'desc',
				] + $findOptions;

				if ( $requestOptions['offset'] ) {
					$requestOptions['offset'] = null;
					$this->context->getOutput()->addHTML(
						Html::warningBox( $this->context->msg( 'flow-invalid-param-offset-for-newest' )->parse() )
					);
				}
		}

		if ( $requestOptions['offset-id'] ) {
			$findOptions['pager-offset'] = UUID::create( $requestOptions['offset-id'] );
		} elseif ( $requestOptions['offset'] ) {
			if ( $findOptions['sortby'] === 'updated' ) {
				$cursor = (string)$requestOptions['offset'];
				TopicListCursor::parse( $cursor );
				$findOptions['pager-offset'] = $cursor;
			} else {
				$findOptions['pager-offset'] = intval( $requestOptions['offset'] );
			}
		}

		if ( $requestOptions['offset-dir'] ) {
			$findOptions['pager-dir'] = $requestOptions['offset-dir'];
		}

		if ( $requestOptions['include-offset'] ) {
			$findOptions['pager-include-offset'] = $requestOptions['include-offset'];
		}

		$findOptions['pager-limit'] = $limit;

		if (
			$requestOptions['savesortby']
			&& $user->isRegistered()
			&& $userOptionsLookup->getOption( $user, 'flow-topiclist-sortby' ) != $findOptions['sortby']
		) {
			// Save the new sortby preference.
			$job = new UserOptionsUpdateJob( [
				'userId' => $user->getId(),
				'options' => [ 'flow-topiclist-sortby' => $findOptions['sortby'] ]
			] );
			$services->getJobQueueGroupFactory()->makeJobQueueGroup()->lazyPush( $job );
		}

		return $findOptions;
	}

	/**
	 * Gets a set of workflow IDs
	 * This filters result to only include unmoderated and locked topics.
	 *
	 * Also populates topicRootRevisionCache with a mapping from topic ID to the
	 * PostRevision for the topic root.
	 *
	 * @param array $findOptions
	 * @return PagerPage
	 */
	protected function getPage( array $findOptions ) {
		$pager = new Pager(
			$this->storage->getStorage( 'TopicListEntry' ),
			[ 'topic_list_id' => $this->workflow->getId() ],
			$findOptions
		);

		$postStorage = $this->storage->getStorage( 'PostRevision' );

		// Work around lack of $this in closures until we can use PHP 5.4+ features.
		$topicRootRevisionCache =& $this->topicRootRevisionCache;

		return $pager->getPage( static function ( array $found ) use ( $postStorage, &$topicRootRevisionCache ) {
			$queries = [];
			/** @var TopicListEntry[] $found */
			foreach ( $found as $entry ) {
				$queries[] = [ 'rev_type_id' => $entry->getId() ];
			}
			$posts = $postStorage->findMulti( $queries, [
				'sort' => 'rev_id',
				'order' => 'DESC',
				'limit' => 1,
			] );
			$allowed = [];
			foreach ( $posts as $queryResult ) {
				$post = reset( $queryResult );
				if ( !$post->isModerated() || $post->isLocked() ) {
					$allowed[$post->getPostId()->getAlphadecimal()] = $post;
				}
			}
			foreach ( $found as $idx => $entry ) {
				if ( isset( $allowed[$entry->getId()->getAlphadecimal()] ) ) {
					$topicRootRevisionCache[$entry->getId()->getAlphadecimal()] = $allowed[$entry->getId()->getAlphadecimal()];
				} else {
					unset( $found[$idx] );
				}
			}

			return $found;
		} );
	}

	/**
	 * @param OutputPage $out
	 */
	public function setPageTitle( OutputPage $out ) {
		if ( $this->action !== 'new-topic' ) {
			// Only new-topic should override page title, rest should default
			parent::setPageTitle( $out );
			return;
		}

		$title = $this->workflow->getOwnerTitle();
		$message = $out->msg( 'flow-newtopic-first-heading', $title->getPrefixedText() );
		$out->setPageTitleMsg( $message );
		$out->setSubtitle( '&lt; ' . MediaWikiServices::getInstance()->getLinkRenderer()->makeLink( $title ) );
	}
}
