<?php

/**
 * Read-only SQL plan diagnostic on a dependent wiki with the normalized
 * dictionary enabled. Capture the actual generated statements, not a
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
	}

	public function execute() {
		$domain = $GLOBALS['wgInterlanguageExtensionDB'] ?? false;
		$language = $GLOBALS['wgLanguageCode'] ?? '';
		if ( !$domain || !( $GLOBALS['wgInterlanguageUseTitleKeys'] ?? false ) ||
			!class_exists( 'InterlanguageTitleKeys' )
		) {
			throw new RuntimeException( 'Run on a dependent wiki with the normalized reader enabled.' );
		}
		$title = Title::newFromText( $this->getOption( 'title' ) );
		if ( !$title || !$title->canExist() || $title->isExternal() || $title->getFragment() !== '' ) {
			throw new RuntimeException( 'Expected a valid local whole-page title.' );
		}
		$provider = $this->getServiceContainer()->getConnectionProvider();
		$local = $provider->getReplicaDatabase();
		$central = $provider->getPrimaryDatabase( $domain );
		if ( $central->getType() !== 'mysql' || !$central->tableExists( 'interlanguage_title_keys', __METHOD__ ) ) {
			throw new RuntimeException( 'The MySQL/MariaDB central dictionary is not available.' );
		}

		$logger = new class extends AbstractLogger {
			public array $statements = [];
			public function log( $level, $message, array $context = [] ): void {
				$method = $context['method'] ?? '';
				$sql = $context['sql'] ?? null;
				if ( is_string( $sql ) && preg_match( '/^\s*SELECT\b/i', $sql ) &&
					in_array( $method, [
						'InterlanguageExtension::getLocalRedirectTitles',
						'InterlanguageTitleKeys::findCandidates',
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
		// Also works with the first normalized-index version, where this
		// internal alias collector was private.
		$method = new ReflectionMethod( InterlanguageExtension::class, 'getLocalRedirectTitles' );
		$targets = $method->invoke( $extension, $local, $title );
		if ( $targets === null ) {
			throw new RuntimeException( 'Redirect alias discovery exceeded its bounds.' );
		}
		$native = InterlanguageTitleKeys::findCandidates( $central, $targets, $language, true );
		$supplemental = InterlanguageTitleKeys::findCandidates( $central, $targets, $language, false );

		$statements = $logger->statements;
		$reverseQueries = 0;
		$localQueries = 0;
		$fullScans = [];
		foreach ( $statements as $statement ) {
			$isReverse = $statement['method'] === 'InterlanguageTitleKeys::findCandidates';
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
		$this->output( 'Local indexed alias queries: ' . $localQueries . "\n" );
		$this->output( 'Central candidate queries: ' . $reverseQueries . "\n" );
		$this->output( 'Verified native candidates: ' . ( $native === null ? 'incomplete' : count( $native ) ) . "\n" );
		$this->output( 'Verified supplemental candidates: ' . ( $supplemental === null ? 'incomplete' : count( $supplemental ) ) . "\n" );
		$this->output( 'Central full scans: ' . ( $fullScans ? implode( ', ', $fullScans ) : 'none' ) . "\n" );
		if ( $this->hasOption( 'strict' ) && ( $fullScans || $native === null || $supplemental === null ) ) {
			throw new RuntimeException( 'A central query plan scanned a full table/index or candidate discovery was incomplete.' );
		}
	}
}

$maintClass = WikidebatesExplainInterlanguageLookup::class;
require_once RUN_MAINTENANCE_IF_MAIN;
