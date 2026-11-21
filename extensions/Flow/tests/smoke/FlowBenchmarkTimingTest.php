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

echo 'OK: ' . $GLOBALS['flow_test_assertions'] . " benchmark timing assertions\n";
