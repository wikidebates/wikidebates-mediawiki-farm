<?php

/**
 * Read-only SQL plan and comparable timing diagnostic on a dependent wiki.
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
		$this->addDescription( 'EXPLAIN and benchmark actual bounded exact Interlanguage lookup statements.' );
		$this->addOption( 'title', 'Local page title, including its real namespace.', true, true );
		$this->addOption( 'strict', 'Fail on a central full table or full index scan in the generated plan.' );
		$this->addOption( 'repeat', 'Number of measured repetitions per path (1 to 100).', false, true );
	}

	public function execute() {
		$domain = $GLOBALS['wgInterlanguageExtensionDB'] ?? false;
		$language = $GLOBALS['wgLanguageCode'] ?? '';
		if ( !$domain || !method_exists( 'InterlanguageExtension', 'findCentralCandidates' ) ) {
			throw new RuntimeException( 'Run on a dependent wiki with bounded exact lookup deployed.' );
		}
		$repeat = (int)$this->getOption( 'repeat', 20 );
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
			throw new RuntimeException( 'The plan diagnostic requires MySQL/MariaDB.' );
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
						'InterlanguageExtension::findCentralCandidates',
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
		$native = InterlanguageExtension::findCentralCandidates( $central, $targets, $language, true );
		$supplemental = InterlanguageExtension::findCentralCandidates( $central, $targets, $language, false );

		$statements = $logger->statements;
		$logger->capture = false;
		$reverseQueries = 0;
		$localQueries = 0;
		$fullScans = [];
		foreach ( $statements as $statement ) {
			$isReverse = $statement['method'] === 'InterlanguageExtension::findCentralCandidates';
			$db = $isReverse ? $central : $local;
			$isReverse ? $reverseQueries++ : $localQueries++;
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
		if ( $reverseQueries !== 2 || $localQueries < 1 ) {
			throw new RuntimeException( 'Expected both central candidate queries and the local redirect lookup.' );
		}
		$this->output( 'Bounded raw title spellings: ' . count( $targets ) . "\n" );
		$this->output( 'Local indexed alias queries: ' . $localQueries . "\n" );
		$this->output( 'Central candidate queries: ' . $reverseQueries . "\n" );
		$this->output( 'Verified native candidates: ' . ( $native === null ? 'incomplete' : count( $native ) ) . "\n" );
		$this->output( 'Verified supplemental candidates: ' . ( $supplemental === null ? 'incomplete' : count( $supplemental ) ) . "\n" );
		$this->output( 'Central full scans: ' . ( $fullScans ? implode( ', ', $fullScans ) : 'none' ) . "\n" );

		// Baseline uses the same set of local page/redirect identities but
		// only the historical canonical text, not the extra spelling forms.
		$historical = [];
		foreach ( $targets as $target ) {
			$historical[] = InterlanguageExtension::getCanonicalTitleText( Title::newFromText( $target ) );
		}
		$historical = array_values( array_unique( $historical ) );
		$measure = function ( callable $lookup ) use ( $repeat ): string {
			// One untimed call to warm ordinary MediaWiki/DB caches equally.
			$lookup();
			$times = [];
			for ( $i = 0; $i < $repeat; $i++ ) {
				$start = hrtime( true );
				$lookup();
				$times[] = ( hrtime( true ) - $start ) / 1e6;
			}
			sort( $times, SORT_NUMERIC );
			$mid = intdiv( $repeat, 2 );
			$median = $repeat % 2 ? $times[$mid] : ( $times[$mid - 1] + $times[$mid] ) / 2;
			return sprintf( 'median=%.3f ms max=%.3f ms n=%d', $median, max( $times ), $repeat );
		};
		$baseline = static function () use ( $central, $historical, $language ) {
			foreach ( $central->select( [ 'langlinks', 'page' ],
				[ 'page_id', 'page_namespace', 'page_title', 'page_is_redirect' ],
				[ 'll_lang' => $language, 'll_title' => $historical, 'page_id = ll_from' ],
				__METHOD__, [ 'LIMIT' => 2 ]
			) as $row ) {
				// Consume the result as the historical lookup did.
			}
		};
		$this->output( 'BENCH historical canonical native: ' . $measure( $baseline ) . "\n" );
		$this->output( 'BENCH bounded native: ' . $measure( static fn () =>
			InterlanguageExtension::findCentralCandidates( $central, $targets, $language, true ) ) . "\n" );
		$this->output( 'BENCH bounded supplemental: ' . $measure( static fn () =>
			InterlanguageExtension::findCentralCandidates( $central, $targets, $language, false ) ) . "\n" );
		$this->output( 'BENCH complete reverse discovery: ' . $measure( static function () use ( $extension, $local, $central, $title, $language ) {
			$targets = $extension->getLocalRedirectTitles( $local, $title );
			if ( $targets === null ) {
				return;
			}
			$native = InterlanguageExtension::findCentralCandidates( $central, $targets, $language, true );
			if ( $native === [] ) {
				InterlanguageExtension::findCentralCandidates( $central, $targets, $language, false );
			}
		} ) . "\n" );
		if ( $this->hasOption( 'strict' ) && ( $fullScans || $native === null || $supplemental === null ) ) {
			throw new RuntimeException( 'A central query plan scanned a full table/index or candidate discovery was incomplete.' );
		}
	}
}

$maintClass = WikidebatesExplainInterlanguageLookup::class;
require_once RUN_MAINTENANCE_IF_MAIN;
