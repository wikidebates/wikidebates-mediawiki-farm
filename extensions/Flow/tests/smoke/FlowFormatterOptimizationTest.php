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

	// The workflow edit permission must be checked for each message, even
	// during a topic formatting batch, so rights changes are not cached.
	class CountedEditWorkflow extends \Flow\Model\Workflow {
		public int $checks = 0;
		public function getArticleTitle() {
			return \MediaWiki\Title\Title::newFromText( 'Wikidébats:Bistro' );
		}
		public function userCan( $permission, $user ) {
			check( $permission === 'edit', 'Only workflow edit checks are counted' );
			$this->checks++;
			return false;
		}
	}
	$checkWorkflow = new CountedEditWorkflow;
	$checkRow = new FormatterRow;
	$checkRow->workflow = $checkWorkflow;
	$permissions->setUser( $reader );
	$renderer->primePostRevisions( [] );
	check( $renderer->buildActions( $checkRow ) === [], 'Reader edit denial is preserved' );
	check( $renderer->buildActions( $checkRow ) === [], 'Repeated reader edit denial is preserved' );
	check( $checkWorkflow->checks === 2, 'A workflow edit check is performed for each message' );
	$renderer->clearPrimedPostRevisions();
	check( $renderer->buildActions( $checkRow ) === [], 'After the batch, edit checks still run' );
	check( $checkWorkflow->checks === 3, 'Edit checks are not retained outside the batch' );

	// The opt-in action profiler records the workflow gate without changing
	// permissions or retaining a decision. Normal rendering records nothing.
	$timings = [];
	$renderer->setBenchmarkTimer(
		static function ( string $phase, float $milliseconds ) use ( &$timings ): void {
			$timings[$phase][] = $milliseconds;
		}
	);
	check( $renderer->buildActions( $checkRow ) === [], 'Profiling retains the edit denial' );
	check( $checkWorkflow->checks === 4, 'Profiling reevaluates the workflow gate' );
	check( isset( $timings['format_detail_action_workflow_edit_permission'][0] ) &&
		$timings['format_detail_action_workflow_edit_permission'][0] >= 0,
		'Profiling records a nonnegative workflow gate duration' );
	$renderer->setBenchmarkTimer( null );
	check( $renderer->buildActions( $checkRow ) === [], 'Nonprofiled denial stays unchanged' );
	check( $checkWorkflow->checks === 5, 'Disabling profiling never caches an edit decision' );
	check( count( $timings['format_detail_action_workflow_edit_permission'] ) === 1,
		'Disabled action profiler records no further measurements' );

	// The same profiler must preserve the per-action permission check path.
	class TestActionPost extends IndexPost {
		public function getRevisionId() {
			return UUID::create( '0000000000000066' );
		}
		public function getChangeType() {
			return 'test-action';
		}
	}
	class AllowedActionWorkflow extends CountedEditWorkflow {
		public function userCan( $permission, $user ) {
			$this->checks++;
			return true;
		}
		public function getId() {
			return UUID::create( '0000000000000033' );
		}
	}
	$allowedWorkflow = new AllowedActionWorkflow;
	$actionRow = new FormatterRow;
	$actionRow->workflow = $allowedWorkflow;
	$actionRow->revision = new TestActionPost( '0000000000000066' );
	$actionPermissions = new class( new FlowActions( [
		'test-action' => [ 'actions' => [ 'reply' ] ],
	] ), $reader ) extends RevisionActionPermissions {
		public int $checks = 0;
		public function isAllowed( \Flow\Model\AbstractRevision $revision, $action ) {
			check( $action === 'reply', 'The original action permission is evaluated' );
			$this->checks++;
			return false;
		}
	};
	( new \ReflectionProperty( RevisionFormatter::class, 'permissions' ) )
		->setValue( $renderer, $actionPermissions );
	$actionTimings = [];
	$renderer->setBenchmarkTimer(
		static function ( string $phase, float $milliseconds ) use ( &$actionTimings ): void {
			$actionTimings[$phase][] = $milliseconds;
		}
	);
	check( $renderer->buildActions( $actionRow ) === [],
		'Profiled action permission denial produces the same empty links' );
	check( $actionPermissions->checks === 1,
		'Profiled per-action permission is checked exactly once' );
	check( isset( $actionTimings['format_detail_action_specific_permission'][0] ) &&
		$actionTimings['format_detail_action_specific_permission'][0] >= 0,
		'Profiled per-action permission produces a nonnegative duration' );
	$renderer->setBenchmarkTimer( null );
	check( $renderer->buildActions( $actionRow ) === [],
		'Nonprofiled action permission denial remains unchanged' );
	check( $actionPermissions->checks === 2,
		'Nonprofiled action permission is checked again' );
	check( count( $actionTimings['format_detail_action_specific_permission'] ) === 1,
		'Nonprofiled action permission does not emit new metrics' );
	( new \ReflectionProperty( RevisionFormatter::class, 'permissions' ) )
		->setValue( $renderer, $permissions );

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
