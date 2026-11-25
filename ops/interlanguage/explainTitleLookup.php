<?php

/**
 * Read-only SQL plan and timing diagnostic on a dependent wiki.
 * Capture the actual generated statements, not a
 * separately written approximation of the runtime query.
 */
if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
require_once dirname( __DIR__, 2 ) . '/maintenance/Maintenance.php';

use MediaWiki\Title\Title;
use Psr\Log\AbstractLogger;

class WikidebatesExplainInterlanguageLookup extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'EXPLAIN the actual Interlanguage normalized reverse lookup statements.' );
		$this->addOption( 'title', 'Local page title, including its real namespace.', true, true );
		$this->addOption( 'strict', 'Fail on a central full table or full index scan in the generated plan.' );
		$this->addOption( 'repeat', 'Measure 1 to 100 lookups per branch; default 1.', false, true );
	}

	public function execute() {
		$domain = $GLOBALS['wgInterlanguageExtensionDB'] ?? false;
		$language = $GLOBALS['wgLanguageCode'] ?? '';
		if ( !$domain || !class_exists( 'InterlanguageTitleLookup' ) ) {
			throw new RuntimeException( 'Run on a dependent wiki with the schema-free lookup deployed.' );
		}
		$repeat = (int)$this->getOption( 'repeat', 1 );
		if ( $repeat < 1 || $repeat > 100 ) {
			throw new RuntimeException( 'Repeat must be between 1 and 100.' );
		}
		$title = Title::newFromText( $this->getOption( 'title' ) );
		if ( !$title || !$title->canExist() || $title->isExternal() || $title->getFragment() !== '' ) {
			throw new RuntimeException( 'Expected a valid local whole-page title.' );
		}
		$provider = $this->getServiceContainer()->getConnectionProvider();
		$local = $provider->getReplicaDatabase();
		$central = $provider->getPrimaryDatabase( $domain );
		if ( $central->getType() !== 'mysql' ) {
			throw new RuntimeException( 'The SQL plan diagnostic requires MySQL/MariaDB.' );
		}

		$logger = new class extends AbstractLogger {
			public array $statements = [];
			public bool $capture = true;
			public function log( $level, $message, array $context = [] ): void {
				$method = $context['method'] ?? '';
				$sql = $context['sql'] ?? null;
				if ( $this->capture && is_string( $sql ) && preg_match( '/^\s*SELECT\b/i', $sql ) &&
					in_array( $method, [
						'InterlanguageExtension::getLocalRedirectTitles',
						'InterlanguageTitleLookup::matchingSpellings',
						'InterlanguageTitleLookup::findCandidates',
					], true )
				) {
					$this->statements[] = [
						'domain' => $context['domain'] ?? '',
						'method' => $method,
						'sql' => $sql,
						'runtime_ms' => $context['runtime_ms'] ?? null,
					];
				}
			}
		};
		foreach ( [ $local, $central ] as $db ) {
			$db->setLogger( $logger );
			$db->setFlag( DBO_DEBUG );
		}
		$extension = new InterlanguageExtension();
		$targets = $extension->getLocalRedirectTitles( $local, $title );
		if ( $targets === null ) {
			throw new RuntimeException( 'Redirect alias discovery exceeded its bounds.' );
		}
		$start = hrtime( true );
		$native = InterlanguageTitleLookup::findCandidates( $central, $targets, $language, true );
		$nativeMs = ( hrtime( true ) - $start ) / 1e6;
		$start = hrtime( true );
		$supplemental = InterlanguageTitleLookup::findCandidates( $central, $targets, $language, false );
		$supplementalMs = ( hrtime( true ) - $start ) / 1e6;

		$statements = $logger->statements;
		$logger->capture = false;
		$rangeQueries = 0;
		$pointQueries = 0;
		$localQueries = 0;
		$fullScans = [];
		foreach ( $statements as $statement ) {
			$isRange = $statement['method'] === 'InterlanguageTitleLookup::matchingSpellings';
			$isPoint = $statement['method'] === 'InterlanguageTitleLookup::findCandidates';
			$isReverse = $isRange || $isPoint;
			$db = $isReverse ? $central : $local;
			if ( $isRange ) {
				$rangeQueries++;
			} elseif ( $isPoint ) {
				$pointQueries++;
			} else {
				$localQueries++;
			}
			$this->output( ( $isReverse ? 'CENTRAL' : 'LOCAL' ) . ' ' . $statement['method'] .
				' (' . $statement['runtime_ms'] . " ms)\n" );
			foreach ( $db->query( 'EXPLAIN ' . $statement['sql'], __METHOD__ ) as $row ) {
				$plan = [
					'table' => $row->table,
					'access' => $row->type,
					'key' => $row->key,
					'rows' => $row->rows,
					'extra' => $row->Extra,
				];
				$this->output( "\t" . json_encode( $plan, JSON_THROW_ON_ERROR ) . "\n" );
				if ( $isReverse && in_array( $row->type, [ 'ALL', 'index' ], true ) ) {
					$fullScans[] = $row->table . ':' . $row->type;
				}
			}
		}
		if ( $rangeQueries !== 2 || $localQueries < 1 ) {
			throw new RuntimeException( 'Expected both central language range reads and the local redirect lookup.' );
		}
		$this->output( 'Local indexed alias queries: ' . $localQueries . "\n" );
		$this->output( 'Central language range queries: ' . $rangeQueries . "\n" );
		$this->output( 'Central candidate point queries: ' . $pointQueries . "\n" );
		$this->output( 'Verified native candidates: ' . ( $native === null ? 'incomplete' : count( $native ) ) . "\n" );
		$this->output( 'Verified supplemental candidates: ' . ( $supplemental === null ? 'incomplete' : count( $supplemental ) ) . "\n" );
		$this->output( 'Central full scans: ' . ( $fullScans ? implode( ', ', $fullScans ) : 'none' ) . "\n" );
		$config = $this->getServiceContainer()->getMainConfig();
		$cache = $this->getServiceContainer()->getObjectCacheFactory()->getLocalServerInstance( $config->get( 'MainCacheType' ) );
		$this->output( 'Normalization cache: ' . get_class( $cache ) . "\n" );
		$this->output( sprintf( "First native lookup: %.3f ms; first supplemental lookup: %.3f ms\n", $nativeMs, $supplementalMs ) );

		// Compare complete lookup wall time with the historical exact native
		// SQL query. These have different coverage, so the latter is a cost
		// reference, not a correctness or result-equivalence oracle.
		$measure = function ( callable $lookup ) use ( $repeat ): string {
			$times = [];
			for ( $i = 0; $i < $repeat; $i++ ) {
				$start = hrtime( true );
				$lookup();
				$times[] = ( hrtime( true ) - $start ) / 1e6;
			}
			sort( $times, SORT_NUMERIC );
			$middle = intdiv( $repeat, 2 );
			$median = $repeat % 2 ? $times[$middle] : ( $times[$middle - 1] + $times[$middle] ) / 2;
			return sprintf( 'median=%.3f ms max=%.3f ms n=%d', $median, max( $times ), $repeat );
		};
		$this->output( 'BENCH native normalized: ' . $measure( static fn () =>
			InterlanguageTitleLookup::findCandidates( $central, $targets, $language, true ) ) . "\n" );
		$this->output( 'BENCH supplemental normalized: ' . $measure( static fn () =>
			InterlanguageTitleLookup::findCandidates( $central, $targets, $language, false ) ) . "\n" );
		$this->output( 'BENCH historical native exact: ' . $measure( static function () use ( $central, $targets, $language ) {
			foreach ( $central->select( [ 'langlinks', 'page' ],
				[ 'page_id', 'page_namespace', 'page_title', 'page_is_redirect' ],
				[ 'll_lang' => $language, 'll_title' => $targets, 'page_id = ll_from' ],
				__METHOD__, [ 'LIMIT' => 2 ]
			) as $row ) {
				// Consume the result just as the real lookup does.
			}
		} ) . "\n" );
		if ( $this->hasOption( 'strict' ) && ( $fullScans || $native === null || $supplemental === null ) ) {
			throw new RuntimeException( 'A central query plan scanned a full table/index or candidate discovery was incomplete.' );
		}
	}
}

$maintClass = WikidebatesExplainInterlanguageLookup::class;
require_once RUN_MAINTENANCE_IF_MAIN;
