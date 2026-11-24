<?php

/**
 * CLI-only maintenance for the six known Wikidebates interwiki API routes.
 * Preview by default. Apply only after the coordinated Interlanguage deployment.
 */
if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
require_once dirname( __DIR__, 2 ) . '/maintenance/Maintenance.php';

use MediaWiki\MainConfigNames;
use Wikimedia\Rdbms\IDatabase;

class WikidebatesConfigureInterlanguageApiRoutes extends Maintenance {
	private const LANGUAGES = [ 'fr', 'en', 'de', 'es', 'it', 'pt' ];
	private const BACKUP_DIRECTORY = '/home/users/webmaster/files/interlanguage-audits';

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Preview or fill empty Wikidebates interwiki API URLs only.' );
		$this->addOption( 'apply', 'Back up and fill empty API fields, then invalidate interwiki lookup caches.' );
	}

	public function execute() {
		$services = $this->getServiceContainer();
		$lang = $services->getContentLanguage()->getCode();
		if ( !in_array( $lang, self::LANGUAGES, true ) ) {
			throw new RuntimeException( 'Unexpected wiki language: ' . $lang );
		}
		if ( $this->getConfig()->get( MainConfigNames::InterwikiCache ) !== false ) {
			throw new RuntimeException( 'Static interwiki configuration is not supported.' );
		}
		$apply = $this->hasOption( 'apply' );
		if ( $apply && !class_exists( 'InterlanguageEffectiveLangLinks' ) ) {
			throw new RuntimeException( 'Deploy the validated Interlanguage version before applying.' );
		}

		$db = $services->getConnectionProvider()->getPrimaryDatabase();
		$targets = array_values( array_diff( self::LANGUAGES, [ $lang ] ) );
		$lookup = $services->getInterwikiLookup();

		if ( $apply ) {
			$schema = $db->query(
				'SHOW CREATE TABLE ' . $db->tableName( 'interwiki' ),
				__METHOD__
			)->fetchRow();
			if ( !$schema || !preg_match( '/\bENGINE=InnoDB\b/i', $schema[1] ?? '' ) ) {
				throw new RuntimeException( 'The interwiki table must be transactional (InnoDB).' );
			}
		}

		$operation = function () use ( $db, $lang, $targets, $lookup, $apply ) {
			$rows = [];
			foreach ( $db->select(
				'interwiki',
				[ 'iw_prefix', 'iw_url', 'iw_api' ],
				[ 'iw_prefix' => $targets ],
				__METHOD__,
				$apply ? [ 'FOR UPDATE' ] : []
			) as $row ) {
				$rows[$row->iw_prefix] = (array)$row;
			}
			$changes = [];
			foreach ( $targets as $target ) {
				$base = 'https://' . $target . '.wikidebates.org';
				$article = $base . '/wiki/$1';
				$api = $base . '/w/api.php';
				$row = $rows[$target] ?? null;
				$effective = $lookup->fetch( $target );
				if ( !$row || $row['iw_url'] !== $article ||
					!in_array( $row['iw_api'], [ '', $api ], true ) ||
					!$effective || $effective->getURL() !== $article ||
					!in_array( $effective->getAPI(), [ '', $api ], true )
				) {
					throw new RuntimeException( 'Unexpected route; no changes for ' . $lang . ': ' . $target );
				}
				$this->output( $lang . ' -> ' . $target . ': SQL=' .
					( $row['iw_api'] ?: 'EMPTY' ) . '; effective=' .
					( $effective->getAPI() ?: 'EMPTY' ) . "\n" );
				if ( $row['iw_api'] === '' ) {
					$changes[$target] = $api;
				}
			}
			$this->output( 'Missing SQL API fields: ' . count( $changes ) . "\n" );
			if ( !$apply ) {
				return;
			}

			if ( $changes ) {
				$this->backup( $lang, $db->getDomainID(), array_intersect_key( $rows, $changes ) );
				foreach ( $changes as $target => $api ) {
					$db->update(
						'interwiki',
						[ 'iw_api' => $api ],
						[
							'iw_prefix' => $target,
							'iw_url' => $rows[$target]['iw_url'],
							'iw_api' => '',
						],
						__METHOD__
					);
					if ( $db->affectedRows() !== 1 ) {
						throw new RuntimeException( 'Unexpected affected row count; rolling back.' );
					}
				}
			}
			$db->onTransactionCommitOrIdle( static function () use ( $lookup, $targets ) {
				foreach ( $targets as $target ) {
					$lookup->invalidateCache( $target );
				}
			}, __METHOD__ );
		};

		if ( !$apply ) {
			$operation();
			$this->output( "PREVIEW ONLY\n" );
			return;
		}
		$db->doAtomicSection( __METHOD__, $operation, IDatabase::ATOMIC_CANCELABLE );
		$this->output( "Changes staged; verify SQL and effective routes in a new maintenance process after successful exit.\n" );
	}

	private function backup( string $lang, string $domain, array $rows ): void {
		umask( 0077 );
		$directory = self::BACKUP_DIRECTORY;
		if ( is_link( $directory ) ||
			( !is_dir( $directory ) && !mkdir( $directory, 0700, true ) ) ||
			!chmod( $directory, 0700 )
		) {
			throw new RuntimeException( 'Cannot secure backup directory.' );
		}
		$path = $directory . '/api-routes-' . $lang . '-' . gmdate( 'Ymd\THis\Z' ) .
			'-' . bin2hex( random_bytes( 6 ) ) . '.json';
		$json = json_encode( [
			'language' => $lang,
			'database' => $domain,
			'created_at' => gmdate( DATE_ATOM ),
			'rows' => array_values( $rows ),
		], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
		$handle = fopen( $path, 'x' );
		if ( $handle === false ) {
			throw new RuntimeException( 'Cannot create backup.' );
		}
		try {
			if ( fwrite( $handle, $json ) !== strlen( $json ) || !fflush( $handle ) || !fsync( $handle ) ) {
				throw new RuntimeException( 'Incomplete backup; no database update.' );
			}
		} finally {
			fclose( $handle );
		}
		$this->output( 'Backup: ' . $path . "\n" );
	}
}

$maintClass = WikidebatesConfigureInterlanguageApiRoutes::class;
require_once RUN_MAINTENANCE_IF_MAIN;
