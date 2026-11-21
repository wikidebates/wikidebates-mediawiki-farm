<?php
/** Run with php extensions/Flow/tests/smoke/FlowFormatterOptimizationTest.php. */
namespace MediaWiki\Title {
	class Title {
		public function __construct( private string $text ) {
		}
		public static function newFromText( $text, $namespace = null ) {
			return new self( $text );
		}
		public function getTalkPage() {
			return new self( 'User talk:' . $this->text );
		}
		public function getText() {
			return $this->text;
		}
		public function getPrefixedText() {
			return $this->text;
		}
		public function getLinkURL() {
			return '/wiki/' . rawurlencode( $this->text );
		}
		public function isKnown() {
			return true;
		}
	}
}
namespace MediaWiki\SpecialPage {
	class SpecialPage {
		public static function getTitleFor( $name, $user = null ) {
			return new \MediaWiki\Title\Title( 'Special:' . $name . '/' . $user );
		}
	}
}
namespace MediaWiki\Context {
	interface IContextSource {
	}
}
namespace {
	require __DIR__ . '/bootstrap.php';

	use Flow\Collection\PostCollection;
	use Flow\FlowActions;
	use Flow\Formatter\FormatterRow;
	use Flow\Formatter\RevisionFormatter;
	use Flow\Formatter\TopicListFormatter;
	use Flow\Model\PostRevision;
	use Flow\Model\UUID;
	use Flow\RevisionActionPermissions;
	use Flow\UrlGenerator;
	use MediaWiki\MediaWikiServices;
	use MediaWiki\User\User;

	define( 'NS_USER', 2 );
	function wfMessage( $key ) {
		return $key;
	}

	$reader = new User( [ 'read' ] );
	$moderator = new User( [ 'read', 'block' ] );
	$permissions = new RevisionActionPermissions( new FlowActions( [] ), $reader );
	/** @var RevisionFormatter $renderer */
	$renderer = ( new \ReflectionClass( RevisionFormatter::class ) )->newInstanceWithoutConstructor();
	( new \ReflectionProperty( RevisionFormatter::class, 'permissions' ) )->setValue( $renderer, $permissions );

	$links = $renderer->serializeUserLinks( [ 'name' => 'Alice' ] );
	check( !isset( $links['block'] ), 'Reader does not get a blocking link' );
	$oldChecks = MediaWikiServices::$checks;
	$renderer->serializeUserLinks( [ 'name' => 'Alice' ] );
	check( MediaWikiServices::$checks === $oldChecks, 'Same viewer reuses cached user links' );
	$permissions->setUser( $moderator );
	$links = $renderer->serializeUserLinks( [ 'name' => 'Alice' ] );
	check( isset( $links['block'] ), 'Moderator gets their blocking link' );
	$permissions->setUser( $reader );
	$links = $renderer->serializeUserLinks( [ 'name' => 'Alice' ] );
	check( !isset( $links['block'] ), 'Moderator-only actions never leak to another viewer' );

	class IndexPost extends PostRevision {
		public function __construct( string $id ) {
			$this->postId = UUID::create( $id );
		}
	}
	$parent = new IndexPost( '0000000000000001' );
	$row = new FormatterRow;
	$row->revision = $parent;
	$row->currentRevision = $parent;
	$renderer->primePostRevisions( [ $row ] );
	$method = new \ReflectionMethod( RevisionFormatter::class, 'getReplyParentRevision' );
	check( $method->invoke( $renderer, $parent->getPostId() ) === $parent,
		'Preloaded parent is reused without fetching its collection' );

	$storageHits = 0;
	PostCollection::$collections[$parent->getPostId()->getAlphadecimal()] = new class( $parent, $storageHits ) {
		public function __construct( private PostRevision $parent, private int &$hits ) {
		}
		public function getLastRevision() {
			$this->hits++;
			return $this->parent;
		}
	};
	$renderer->clearPrimedPostRevisions();
	check( $method->invoke( $renderer, $parent->getPostId() ) === $parent,
		'Unprimed history or other formatter calls still use the original fallback' );
	check( $storageHits === 1, 'Clearing the batch removes the in-process revision shortcut' );

	// A topic workflow's edit check is the same for all posts in one batch.
	// The decision must never persist across a batch or a change of viewer.
	class CountedEditWorkflow extends \Flow\Model\Workflow {
		public int $checks = 0;
		public function getArticleTitle() {
			return \MediaWiki\Title\Title::newFromText( 'Wikidébats:Bistro' );
		}
		public function userCan( $permission, $user ) {
			check( $permission === 'edit', 'Only workflow edit checks are cached' );
			$this->checks++;
			return in_array( 'block', $user->rights, true );
		}
	}
	$workflowOne = new CountedEditWorkflow;
	$workflowTwo = new CountedEditWorkflow;
	$editRow = new FormatterRow;
	$editRow->workflow = $workflowOne;
	$permissions->setUser( $reader );
	$renderer->primePostRevisions( [] );
	check( $renderer->buildActions( $editRow ) === [], 'Reader cannot edit the workflow' );
	check( $renderer->buildActions( $editRow ) === [], 'Repeated reader edit denial is preserved' );
	check( $workflowOne->checks === 1, 'Duplicate posts share one workflow edit decision' );
	$otherRow = new FormatterRow;
	$otherRow->workflow = $workflowTwo;
	check( $renderer->buildActions( $otherRow ) === [], 'Another topic still denies editing' );
	check( $workflowTwo->checks === 1, 'Distinct workflows have separate decisions' );

	$editCheck = new \ReflectionMethod( RevisionFormatter::class, 'canEditWorkflow' );
	check( $editCheck->invoke( $renderer, $workflowOne, $moderator ),
		'Changing viewer recomputes and allows a moderator' );
	check( $workflowOne->checks === 2, 'New viewer does not reuse reader denial' );
	check( $editCheck->invoke( $renderer, $workflowOne, $moderator ),
		'Moderator permission can be reused in the same batch' );
	check( $workflowOne->checks === 2, 'Same moderator checks only once per workflow' );
	check( !$editCheck->invoke( $renderer, $workflowOne, $reader ),
		'Moderator permission is never reused for a reader' );
	check( $workflowOne->checks === 3, 'Switching back to reader clears moderator decisions' );

	$renderer->clearPrimedPostRevisions();
	check( !$editCheck->invoke( $renderer, $workflowOne, $reader ),
		'Outside a formatting batch, edits are checked each time' );
	check( !$editCheck->invoke( $renderer, $workflowOne, $reader ),
		'Outside a formatting batch, no implicit cache is retained' );
	check( $workflowOne->checks === 5, 'No edit decision survives batch cleanup' );

	$renderer->primePostRevisions( [] );
	check( !$editCheck->invoke( $renderer, $workflowOne, $reader ),
		'New batch rechecks the workflow for the same viewer' );
	check( $workflowOne->checks === 6, 'Fresh batch does not reuse an old decision' );
	$renderer->clearPrimedPostRevisions();

	// A new formatting batch must reflect changed rights on the very same
	// User object (never keep edit decisions beyond the old batch).
	$reader->rights = [ 'read', 'block' ];
	$renderer->primePostRevisions( [] );
	check( $editCheck->invoke( $renderer, $workflowOne, $reader ),
		'A later batch reflects updated rights on the same User object' );
	check( $workflowOne->checks === 7, 'Rights are checked again in the new batch' );
	$renderer->clearPrimedPostRevisions();
	$reader->rights = [ 'read' ];

	class SpyFormatter extends RevisionFormatter {
		public int $primeCount = 0;
		public int $clearCount = 0;
		public bool $throw = false;
		public function __construct() {
		}
		public function primePostRevisions( array $rows ): void {
			$this->primeCount++;
		}
		public function clearPrimedPostRevisions(): void {
			$this->clearCount++;
		}
		public function formatApi( FormatterRow $row, \MediaWiki\Context\IContextSource $ctx, $action = 'view' ) {
			if ( $this->throw ) {
				throw new \RuntimeException( 'Formatting stopped' );
			}
			return [
				'revisionId' => 'revision',
				'postId' => 'post',
				'replyToId' => null,
			];
		}
	}
	$spy = new SpyFormatter;
	$urlGenerator = ( new \ReflectionClass( UrlGenerator::class ) )->newInstanceWithoutConstructor();
	$topicFormatter = new TopicListFormatter( $urlGenerator, $spy );
	$ctx = new class implements \MediaWiki\Context\IContextSource {
	};
	$out = $topicFormatter->buildResult( [], [ $row ], $ctx );
	check( $out['posts'] === [ 'post' => [ 'revision' ] ], 'Batch formatting preserves the post mapping' );
	check( $spy->primeCount === 1 && $spy->clearCount === 1, 'Temporary parent index is always cleared' );
	$spy->throw = true;
	try {
		$topicFormatter->buildResult( [], [ $row ], $ctx );
		throw new \LogicException( 'Expected formatting to fail' );
	} catch ( \RuntimeException $e ) {
		check( $e->getMessage() === 'Formatting stopped', 'Formatter exception propagates' );
	}
	check( $spy->clearCount === 2, 'Temporary parent index is also cleared on failure' );

	// Only reuse an updated-sort page for the ToC when the server already
	// knows that it has no continuation. Never guess around timestamp ties.
	class TocOptimizationBlock extends \Flow\Block\TopicListBlock {
		public int $fallbacks = 0;
		protected function getFindOptions( array $options ) {
			$find = [ 'sortby' => $options['sortby'] ?? 'updated' ];
			if ( isset( $options['offset'] ) ) {
				$find['pager-offset'] = $options['offset'];
			}
			return $find;
		}
		public function renderApi( array $options ) {
			$this->fallbacks++;
			return [ 'fallback' => true ];
		}
	}
	$workflow = ( new \ReflectionClass( \Flow\Model\Workflow::class ) )->newInstanceWithoutConstructor();
	$storage = ( new \ReflectionClass( \Flow\Data\ManagerGroup::class ) )->newInstanceWithoutConstructor();
	$block = new TocOptimizationBlock( $workflow, $storage );
	$pager = ( new \ReflectionClass( \Flow\Data\Pager\Pager::class ) )->newInstanceWithoutConstructor();
	$completePage = new \Flow\Data\Pager\PagerPage( [], [], $pager );
	$formatter = new class {
		public int $calls = 0;
		public function formatApi( $workflow, $roots, $workflows, $page ) {
			$this->calls++;
			return [ 'roots' => array_keys( $roots ), 'links' => [ 'pagination' => [] ] ];
		}
	};
	\Flow\Container::$services['formatter.topiclist.toc'] = $formatter;
	foreach ( [ 'fullPageForToc' => $completePage, 'fullPageSortForToc' => 'updated',
		'fullWorkflowsForToc' => [] ] as $property => $value
	) {
		( new \ReflectionProperty( \Flow\Block\TopicListBlock::class, $property ) )
			->setValue( $block, $value );
	}
	$result = $block->renderTocApi( [], [ 'sortby' => 'updated' ] );
	check( $result['roots'] === [] && $block->fallbacks === 0 && $formatter->calls === 1,
		'Complete updated-sort pages reuse cached ToC data without an extra query' );
	$hasNextPage = new \Flow\Data\Pager\PagerPage( [], [ 'fwd' => [ 'offset' => 'x' ] ], $pager );
	( new \ReflectionProperty( \Flow\Block\TopicListBlock::class, 'fullPageForToc' ) )
		->setValue( $block, $hasNextPage );
	check( $block->renderTocApi( [], [ 'sortby' => 'updated' ] ) === [ 'fallback' => true ],
		'Partial updated-sort pages retain the original safe pagination' );
	check( $block->renderTocApi( [], [ 'sortby' => 'updated', 'offset' => 123 ] ) === [ 'fallback' => true ],
		'Explicit offset requests continue to use the ordinary query path' );

	echo 'OK: ' . $GLOBALS['flow_test_assertions'] . " formatter and viewer-cache assertions\n";
}
