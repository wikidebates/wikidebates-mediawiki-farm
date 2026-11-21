<?php

namespace Flow\Maintenance;

use Flow\Block\TopicListBlock;
use Flow\Container;
use MediaWiki\Context\RequestContext;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Read-only, bounded server-side timing for the complete Flow topic-list
 * renderer. No cache purges, writes, forced anonymous page views or log dumps.
 * Run on a real wiki using maintenance/run.php, with --profiler as needed.
 */
class FlowBenchmarkTopicList extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Benchmark Flow topic-list rendering (read-only)' );
		$this->addOption( 'page', 'An existing Flow discussion board title', true, true );
		$this->addOption( 'runs', 'Number of consecutive renders (1..3; default 3)', false, true );
		$this->addOption( 'limit', 'Topics per render (1..50; default 10)', false, true );
		$this->addOption( 'sortby', 'newest or updated (default updated)', false, true );
		$this->addOption( 'breakdown', 'Include read-only phase timings for the renderer' );
		$this->addOption(
			'reuse-html-dom',
			'Maintenance-only A/B experiment: reuse the parsed HTML source DOM for fixers'
		);
		$this->requireExtension( 'Flow' );
	}

	public function execute() {
		$runs = (int)$this->getOption( 'runs', 3 );
		$limit = (int)$this->getOption( 'limit', 10 );
		$sortby = $this->getOption( 'sortby', 'updated' );
		$breakdown = $this->hasOption( 'breakdown' );
		$reuseHtmlDom = $this->hasOption( 'reuse-html-dom' );
		if ( $runs < 1 || $runs > 3 || $limit < 1 || $limit > 50 ||
			!in_array( $sortby, [ 'newest', 'updated' ], true )
		) {
			$this->fatalError( 'Use --runs=1..3 --limit=1..50 --sortby=newest|updated' );
		}

		$title = Title::newFromText( $this->getOption( 'page' ) );
		if ( !$title ) {
			$this->fatalError( 'Invalid board page title' );
		}
		$loader = Container::get( 'factory.loader.workflow' )->createWorkflowLoader( $title );
		$workflow = $loader->getWorkflow();
		if ( !$workflow || $workflow->isNew() || $workflow->getType() !== 'discussion' ) {
			$this->fatalError( 'The supplied title is not an existing Flow discussion board' );
		}

		$context = RequestContext::getMain();
		$context->setTitle( $title );
		$samples = [];
		for ( $i = 0; $i < $runs; $i++ ) {
			$block = new TopicListBlock( $workflow, Container::get( 'storage' ) );
			$block->init( $context, 'view-topiclist' );
			$phases = [];
			$formatDetails = [];
			$formatCalls = [];
			$queryDetails = [];
			$queryCalls = [];
			if ( $breakdown ) {
				$block->setBenchmarkTimer(
					static function ( string $phase, float $milliseconds ) use (
						&$phases, &$formatDetails, &$formatCalls, &$queryDetails, &$queryCalls
					): void {
						$formatPrefix = 'format_detail_';
						$queryPrefix = 'query_detail_';
						if ( str_starts_with( $phase, $formatPrefix ) ) {
							$key = substr( $phase, strlen( $formatPrefix ) );
							$formatDetails[$key] = round( ( $formatDetails[$key] ?? 0 ) + $milliseconds, 3 );
							$formatCalls[$key] = ( $formatCalls[$key] ?? 0 ) + 1;
						} elseif ( str_starts_with( $phase, $queryPrefix ) ) {
							$key = substr( $phase, strlen( $queryPrefix ) );
							$queryDetails[$key] = round( ( $queryDetails[$key] ?? 0 ) + $milliseconds, 3 );
							$queryCalls[$key] = ( $queryCalls[$key] ?? 0 ) + 1;
						} else {
							$phases[$phase] = $milliseconds;
						}
					}
				);
			}
			// Never let the experimental renderer leak into subsequent web/API
			// calls in long-lived maintenance processes, even on exceptions.
			$templating = Container::get( 'templating' );
			$templating->setBenchmarkReuseDecodedDom( $reuseHtmlDom );
			$cpuBefore = getrusage();
			$started = microtime( true );
			try {
				$topicList = $block->renderApi( [
					'sortby' => $sortby,
					'limit' => $limit,
					'format' => 'fixed-html',
				] );
			} finally {
				$templating->setBenchmarkReuseDecodedDom( false );
			}
			$wallMs = round( ( microtime( true ) - $started ) * 1000, 2 );
			$cpuAfter = getrusage();
			$cpuMs = round( (
				( $cpuAfter['ru_utime.tv_sec'] - $cpuBefore['ru_utime.tv_sec'] ) * 1000000 +
				$cpuAfter['ru_utime.tv_usec'] - $cpuBefore['ru_utime.tv_usec'] +
				( $cpuAfter['ru_stime.tv_sec'] - $cpuBefore['ru_stime.tv_sec'] ) * 1000000 +
				$cpuAfter['ru_stime.tv_usec'] - $cpuBefore['ru_stime.tv_usec']
			) / 1000, 2 );
			// Exact serialized HTML + format per revision, in output order.
			// The identity digest alone cannot detect altered content.
			$revisionContent = [];
			foreach ( $topicList['revisions'] ?? [] as $revisionId => $revision ) {
				$revisionContent[$revisionId] = $revision['content'] ?? null;
			}
			$sample = [
				'run' => $i + 1,
				'wall_ms' => $wallMs,
				'cpu_ms' => $cpuMs,
				'roots' => count( $topicList['roots'] ?? [] ),
				'posts' => count( $topicList['posts'] ?? [] ),
				'revisions' => count( $topicList['revisions'] ?? [] ),
				// Stable identity fingerprint for regression comparisons; does
				// not include full HTML, actions or user-specific tokens.
				'result_structure_sha256' => hash( 'sha256', json_encode( [
					$topicList['roots'] ?? [],
					$topicList['posts'] ?? [],
					array_keys( $topicList['revisions'] ?? [] ),
				], JSON_UNESCAPED_SLASHES ) ),
				'result_content_sha256' => hash( 'sha256', json_encode(
					$revisionContent, JSON_UNESCAPED_SLASHES
				) ),
			];
			if ( $breakdown ) {
				$sample['phases_ms'] = $phases;
				// These timings nest inside 'formatting'; do not add them to phases_ms.
				$sample['format_detail_ms'] = $formatDetails;
				$sample['format_detail_calls'] = $formatCalls;
				// These timings nest inside messages_and_permissions, not formatting.
				$sample['query_detail_ms'] = $queryDetails;
				$sample['query_detail_calls'] = $queryCalls;
				// The remaining time includes assembly and timing overhead.
				$sample['other_ms'] = round( max( 0, $wallMs - array_sum( $phases ) ), 2 );
			}
			$samples[] = $sample;
		}

		$this->output( json_encode( [
			'page' => $title->getPrefixedText(),
			'sortby' => $sortby,
			'limit' => $limit,
			'html_dom_reuse' => $reuseHtmlDom ? 'enabled' : 'disabled',
			'peak_memory_bytes' => memory_get_peak_usage( true ),
			'note' => 'In-process timings include database and conversion work; '
				. 'later samples may benefit from warmed request caches. '
				. 'Phase timings, when requested, are wall-clock time, may include SQL, '
				. 'and exclude block init; detailed formatting and query phases are nested, not additive. '
				. 'query_detail_ms is nested inside messages_and_permissions. '
				. '*_detail_calls counts measured operations rather than messages. '
				. 'result_structure_sha256 fingerprints visible root/post/revision identifiers, '
				. 'not the full API payload. result_content_sha256 fingerprints exact '
				. 'per-revision content and formats (not action tokens). '
				. '--reuse-html-dom is maintenance-only; the normal renderer is unchanged. '
				. 'Compare independent PHP processes. '
				. 'Use MediaWiki database profiling for SQL counts.',
			'samples' => $samples,
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
}

$maintClass = FlowBenchmarkTopicList::class;
require_once RUN_MAINTENANCE_IF_MAIN;
