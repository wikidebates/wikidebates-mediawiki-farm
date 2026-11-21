<?php
/**
 * Standalone regression for optional timing observers. Rendering itself is
 * exercised by the normal MediaWiki workflow; this tests the opt-in boundary.
 */
require __DIR__ . '/bootstrap.php';

use Flow\Block\TopicListBlock;

$block = ( new ReflectionClass( TopicListBlock::class ) )->newInstanceWithoutConstructor();
$begin = new ReflectionMethod( TopicListBlock::class, 'beginBenchmarkPhase' );
$end = new ReflectionMethod( TopicListBlock::class, 'endBenchmarkPhase' );
$timings = [];

check( $begin->invoke( $block ) === null, 'Uninstrumented rendering has no timestamp' );
$end->invoke( $block, 'ignored', null );
check( $timings === [], 'Uninstrumented rendering has no observer side effects' );

$block->setBenchmarkTimer( static function ( string $phase, float $milliseconds ) use ( &$timings ): void {
	$timings[$phase] = $milliseconds;
} );
$started = $begin->invoke( $block );
check( is_int( $started ), 'Opt-in profiling uses a monotonic timestamp' );
$end->invoke( $block, 'page_and_root_revisions', $started );
check( array_key_exists( 'page_and_root_revisions', $timings ), 'Observer receives phase name' );
check( $timings['page_and_root_revisions'] >= 0, 'Observer receives nonnegative milliseconds' );

$block->setBenchmarkTimer( null );
check( $begin->invoke( $block ) === null, 'Observer can be disabled' );
$end->invoke( $block, 'disabled', $started );
check( !isset( $timings['disabled'] ), 'Disabled observer is never called' );

$revisionFormatter = ( new ReflectionClass( \Flow\Formatter\RevisionFormatter::class ) )
	->newInstanceWithoutConstructor();
$topicFormatter = ( new ReflectionClass( \Flow\Formatter\TopicListFormatter::class ) )
	->newInstanceWithoutConstructor();
( new ReflectionProperty( \Flow\Formatter\TopicListFormatter::class, 'serializer' ) )
	->setValue( $topicFormatter, $revisionFormatter );
$permissions = ( new ReflectionClass( \Flow\RevisionActionPermissions::class ) )
	->newInstanceWithoutConstructor();
( new ReflectionProperty( \Flow\Formatter\RevisionFormatter::class, 'permissions' ) )
	->setValue( $revisionFormatter, $permissions );
$permissionProperty = new ReflectionProperty( \Flow\RevisionActionPermissions::class, 'benchmarkTimer' );

$observer = static function ( string $phase, float $milliseconds ) use ( &$timings ): void {
	$timings[$phase] = $milliseconds;
};
$topicFormatter->setBenchmarkTimer( $observer );
$topicProperty = new ReflectionProperty( \Flow\Formatter\TopicListFormatter::class, 'benchmarkTimer' );
$revisionProperty = new ReflectionProperty( \Flow\Formatter\RevisionFormatter::class, 'benchmarkTimer' );
check( $topicProperty->getValue( $topicFormatter ) === $observer,
	'The topic formatter receives the opt-in callback' );
check( $revisionProperty->getValue( $revisionFormatter ) === $observer,
	'The revision formatter receives the same callback' );
check( $permissionProperty->getValue( $permissions ) === $observer,
	'The permissions service receives the same callback' );

$topicFormatter->setBenchmarkTimer( null );
check( $topicProperty->getValue( $topicFormatter ) === null,
	'The topic formatter releases the callback after rendering' );
check( $revisionProperty->getValue( $revisionFormatter ) === null,
	'The revision formatter releases the callback after rendering' );
check( $permissionProperty->getValue( $permissions ) === null,
	'The permissions service releases the callback after rendering' );

// The read-only benchmark's observer must reach the shared ContentFixer
// without persisting beyond the formatting batch, even on failures.
$contentFixer = ( new ReflectionClass( \Flow\Parsoid\ContentFixer::class ) )
	->newInstanceWithoutConstructor();
$templating = ( new ReflectionClass( \Flow\Templating::class ) )
	->newInstanceWithoutConstructor();
( new ReflectionProperty( \Flow\Templating::class, 'contentFixer' ) )
	->setValue( $templating, $contentFixer );
( new ReflectionProperty( \Flow\Formatter\RevisionFormatter::class, 'templating' ) )
	->setValue( $revisionFormatter, $templating );
$fixerProperty = new ReflectionProperty( \Flow\Parsoid\ContentFixer::class, 'benchmarkTimer' );
check( $fixerProperty->getValue( $contentFixer ) === null,
	'ContentFixer is never instrumented by default' );
$topicFormatter->setBenchmarkTimer( $observer );
check( $fixerProperty->getValue( $contentFixer ) === $observer,
	'Topic formatter propagates opt-in timings through Templating to ContentFixer' );
$topicFormatter->setBenchmarkTimer( null );
check( $fixerProperty->getValue( $contentFixer ) === null,
	'ContentFixer observer is cleared after the benchmark' );
check( $permissionProperty->getValue( $permissions ) === null,
	'Permission timing cannot leak out of the benchmark' );

// Time the exact call path used for links and actions; propagate values and
// failures without writing to the normal API response.
$revisionFormatter->setBenchmarkTimer( $observer );
$operation = new ReflectionMethod( \Flow\Formatter\RevisionFormatter::class, 'timeFormattingOperation' );
$calls = 0;
$value = $operation->invoke( $revisionFormatter, 'revision_links', static function () use ( &$calls ) {
	$calls++;
	return [ 'topic' => '/wiki/Sujet:Example' ];
} );
check( $calls === 1, 'Benchmark helper invokes the wrapped operation exactly once' );
check( $value === [ 'topic' => '/wiki/Sujet:Example' ],
	'Benchmark helper returns the wrapped operation result unchanged' );
check( isset( $timings['format_detail_revision_links'] ) &&
	$timings['format_detail_revision_links'] >= 0,
	'Benchmark helper records its named phase' );

try {
	$operation->invoke( $revisionFormatter, 'revision_actions', static function () {
		throw new RuntimeException( 'action construction failed' );
	} );
	throw new LogicException( 'Expected the original formatter failure' );
} catch ( RuntimeException $e ) {
	check( $e->getMessage() === 'action construction failed',
		'Benchmark helper propagates the original exception' );
}
check( isset( $timings['format_detail_revision_actions'] ),
	'Benchmark helper also records failures' );
$revisionFormatter->setBenchmarkTimer( null );
check( $revisionProperty->getValue( $revisionFormatter ) === null,
	'Instrumentation can be cleared after a failed operation' );


// Measure existing root visibility work inside Templating::getContent(),
// without eliding either safety check or changing unprofiled behavior.
class TimedContentPost extends \Flow\Model\PostRevision {
	public int $rootLookups = 0;
	public function __construct( private ?self $root = null ) {
	}
	public function isTopicTitle() {
		return $this->root === null;
	}
	public function getRootPost() {
		$this->rootLookups++;
		return $this->root;
	}
	public function getContent( $format = 'html' ) {
		check( $format === 'html', 'Unmodified HTML format passes through Templating' );
		return '<p>Readable reply</p>';
	}
}
class TimedContentPermissions extends \Flow\RevisionActionPermissions {
	public int $checks = 0;
	public bool $rootAllowed = true;
	public $root;
	public function __construct() {
	}
	public function isAllowed( \Flow\Model\AbstractRevision $revision, $action ) {
		check( $action === 'view' || $action === 'view-topic-title',
			'Templating checks only the original content viewing actions' );
		$this->checks++;
		return $revision !== $this->root || $this->rootAllowed;
	}
}
$rootPost = new TimedContentPost;
$replyPost = new TimedContentPost( $rootPost );
$timedPermissions = new TimedContentPermissions;
$timedPermissions->root = $rootPost;
( new ReflectionProperty( \Flow\Templating::class, 'permissions' ) )
	->setValue( $templating, $timedPermissions );
$timings = [];
$templating->setBenchmarkTimer( $observer );
check( $templating->getContent( $replyPost, 'html' ) === '<p>Readable reply</p>',
	'Profiled root checks preserve readable reply HTML' );
check( $replyPost->rootLookups === 1 && $timedPermissions->checks === 2,
	'Profiled content still checks reply and current root permissions exactly once' );
foreach ( [ 'content_root_lookup', 'content_root_view_permission' ] as $phase ) {
	$key = 'format_detail_' . $phase;
	check( isset( $timings[$key] ) && $timings[$key] >= 0,
		'Profiled content reports ' . $phase );
}
$timedPermissions->rootAllowed = false;
check( $templating->getContent( $replyPost, 'html' ) === '',
	'Denied root visibility remains enforced under profiling' );
check( $replyPost->rootLookups === 2 && $timedPermissions->checks === 4,
	'Profiler still performs both permission checks on denied content' );
$timedPermissions->rootAllowed = true;
$timings = [];
check( $templating->getContent( $rootPost, 'html' ) === '<p>Readable reply</p>',
	'Topic title rendering remains unchanged under profiling' );
check( !isset( $timings['format_detail_content_root_lookup'] ),
	'Topic titles do not trigger reply root lookups' );
$templating->setBenchmarkTimer( null );
$timings = [];
check( $templating->getContent( $replyPost, 'html' ) === '<p>Readable reply</p>',
	'Disabling profiling restores the original readable reply output' );
check( $replyPost->rootLookups === 3 && $timedPermissions->checks === 7,
	'The original two checks remain active when the observer is absent' );
check( $timings === [], 'Uninstrumented content rendering records no benchmark events' );

// The topic list has already fetched latest post revisions before
// loadMetadataBatch(). This experiment skips ONLY that extra metadata
// fetch, with the original path used by default and for other queries.
$topicQuery = ( new ReflectionClass( \Flow\Formatter\TopicListQuery::class ) )
	->newInstanceWithoutConstructor();
$shouldPreload = new ReflectionMethod(
	\Flow\Formatter\TopicListQuery::class, 'shouldPreloadCurrentRevisionsForMetadata'
);
check( $shouldPreload->invoke( $topicQuery ) === true,
	'Default metadata collection still preloads current revisions' );
$topicQuery->setBenchmarkSkipCurrentRevisionRefetch( true );
check( $shouldPreload->invoke( $topicQuery ) === false,
	'Maintenance-only variant can skip the redundant current revision lookup' );
$topicQuery->setBenchmarkSkipCurrentRevisionRefetch( false );
check( $shouldPreload->invoke( $topicQuery ) === true,
	'Clearing the variant restores the original metadata read' );

// Confirm the parent default still applies to other Flow query classes.
$genericPreload = new ReflectionMethod(
	\Flow\Formatter\AbstractQuery::class, 'shouldPreloadCurrentRevisionsForMetadata'
);
check( $genericPreload->invoke( $topicQuery ) === true,
	'The generic query implementation always preserves current revision fetching' );

// Spy on metadata loading itself, not just the A/B toggle.
// The input topic revision is already the latest one from collectRevisions().
class MetadataLatestTopicRevision extends \Flow\Model\PostRevision {
	public function __construct( private \Flow\Model\UUID $id ) {
	}
	public function isTopicTitle() {
		return true;
	}
	public function getPostId() {
		return $this->id;
	}
	public function getCollectionId() {
		return $this->id;
	}
	public function getRevisionId() {
		return $this->id;
	}
	public function getChangeType() {
		return 'new-post';
	}
	public function getCollection() {
		return new class( $this->id ) {
			public function __construct( private \Flow\Model\UUID $id ) {
			}
			public function getId() {
				return $this->id;
			}
		};
	}
}
class MetadataSpyStorage {
	public int $currentRevisionQueries = 0;
	public function __construct( private MetadataLatestTopicRevision $revision ) {
	}
	public function getMulti( $type, $ids ) {
		return [];
	}
	public function findMulti( $type, $queries, $options ) {
		check( $type === MetadataLatestTopicRevision::class,
			'Current revision lookup uses the expected concrete post revision type' );
		$this->currentRevisionQueries++;
		return [ [ $this->revision ] ];
	}
}
$latestTopic = new MetadataLatestTopicRevision(
	\Flow\Model\UUID::create( '0000000000000011' )
);
$spyStorage = new MetadataSpyStorage( $latestTopic );
$metadataMethod = new ReflectionMethod(
	\Flow\Formatter\TopicListQuery::class, 'loadMetadataBatch'
);
$queryStorage = new ReflectionProperty( \Flow\Formatter\AbstractQuery::class, 'storage' );
$queryStorage->setValue( $topicQuery, $spyStorage );
$metadataMethod->invoke( $topicQuery, [ $latestTopic ] );
check( $spyStorage->currentRevisionQueries === 1,
	'Legacy topic metadata redundantly fetches the already loaded current revision' );
$freshTopicQuery = ( new ReflectionClass( \Flow\Formatter\TopicListQuery::class ) )
	->newInstanceWithoutConstructor();
$queryStorage->setValue( $freshTopicQuery, $spyStorage );
$freshTopicQuery->setBenchmarkSkipCurrentRevisionRefetch( true );
$metadataMethod->invoke( $freshTopicQuery, [ $latestTopic ] );
check( $spyStorage->currentRevisionQueries === 1,
	'Benchmark-only variant skips the duplicated current revision query' );
$currentMethod = new ReflectionMethod(
	\Flow\Formatter\TopicListQuery::class, 'getCurrentRevision'
);
check( $currentMethod->invoke( $freshTopicQuery, $latestTopic ) === $latestTopic,
	'Topic list continues using the original latest revision object' );
$freshTopicQuery->setBenchmarkSkipCurrentRevisionRefetch( false );
$metadataMethod->invoke( $freshTopicQuery, [ $latestTopic ] );
check( $spyStorage->currentRevisionQueries === 2,
	'Disabling the variant restores the legacy current revision fetch' );
// An edited topic title still needs its previous revision for history
// metadata. The A/B variant must eliminate only the current-revision lookup.
class MetadataEditedTopicRevision extends MetadataLatestTopicRevision {
	public function __construct(
		\Flow\Model\UUID $postId,
		private \Flow\Model\UUID $revisionId,
		private ?\Flow\Model\UUID $previousId
	) {
		parent::__construct( $postId );
	}
	public function getRevisionId() {
		return $this->revisionId;
	}
	public function getPrevRevisionId() {
		return $this->previousId;
	}
	public function getChangeType() {
		return 'edit-title';
	}
}
class MetadataEditedTopicSpyStorage {
	public int $previousFetches = 0;
	public int $currentFetches = 0;
	public function __construct(
		private MetadataEditedTopicRevision $latest,
		private MetadataEditedTopicRevision $previous
	) {
	}
	public function getMulti( $type, $ids ) {
		if ( $type === 'Workflow' ) {
			return [];
		}
		check( $type === MetadataEditedTopicRevision::class,
			'Edited title previous revision uses the expected revision class' );
		check( count( $ids ) === 1 &&
			$ids[0]->getAlphadecimal() === $this->previous->getRevisionId()->getAlphadecimal(),
			'Edited title still requests the actual previous revision ID' );
		$this->previousFetches++;
		return [ $this->previous ];
	}
	public function findMulti( $type, $queries, $options ) {
		check( $type === MetadataEditedTopicRevision::class,
			'Current revision is re-fetched only through the original query path' );
		$this->currentFetches++;
		return [ [ $this->latest ] ];
	}
}
$editedPostId = \Flow\Model\UUID::create( '0000000000000020' );
$previousRevision = new MetadataEditedTopicRevision(
	$editedPostId,
	\Flow\Model\UUID::create( '0000000000000021' ),
	null
);
$latestRevision = new MetadataEditedTopicRevision(
	$editedPostId,
	\Flow\Model\UUID::create( '0000000000000022' ),
	$previousRevision->getRevisionId()
);
$previousMethod = new ReflectionMethod(
	\Flow\Formatter\TopicListQuery::class, 'getPreviousRevision'
);
$revisionCacheProperty = new ReflectionProperty(
	\Flow\Formatter\AbstractQuery::class, 'revisionCache'
);
$cacheKeys = [];
foreach ( [ false, true ] as $skip ) {
	$editedTopicQuery = ( new ReflectionClass( \Flow\Formatter\TopicListQuery::class ) )
		->newInstanceWithoutConstructor();
	$editedTopicStorage = new MetadataEditedTopicSpyStorage( $latestRevision, $previousRevision );
	$queryStorage->setValue( $editedTopicQuery, $editedTopicStorage );
	$editedTopicQuery->setBenchmarkSkipCurrentRevisionRefetch( $skip );
	$metadataMethod->invoke( $editedTopicQuery, [ $latestRevision ] );
	check( $editedTopicStorage->previousFetches === 1,
		'Edited title previous revision is preloaded in both modes' );
	check( $editedTopicStorage->currentFetches === ( $skip ? 0 : 1 ),
		'Only the redundant current revision read varies by A/B mode' );
	check( $previousMethod->invoke( $editedTopicQuery, $latestRevision ) === $previousRevision,
		'Edited title resolves its previous revision in both modes' );
	check( $currentMethod->invoke( $editedTopicQuery, $latestRevision ) === $latestRevision,
		'Edited title preserves identity of its current revision in both modes' );
	$keys = array_keys( $revisionCacheProperty->getValue( $editedTopicQuery ) );
	sort( $keys, SORT_STRING );
	$cacheKeys[] = $keys;
}
check( $cacheKeys[0] === $cacheKeys[1],
	'Original and skip paths cache the same previous/current revision IDs' );

echo 'OK: ' . $GLOBALS['flow_test_assertions'] . " benchmark timing assertions\n";
