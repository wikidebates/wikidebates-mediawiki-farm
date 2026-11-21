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

$topicFormatter->setBenchmarkTimer( null );
check( $topicProperty->getValue( $topicFormatter ) === null,
	'The topic formatter releases the callback after rendering' );
check( $revisionProperty->getValue( $revisionFormatter ) === null,
	'The revision formatter releases the callback after rendering' );

echo 'OK: ' . $GLOBALS['flow_test_assertions'] . " benchmark timing assertions\n";
