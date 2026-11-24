<?php

/**
 * Delete only backed-up historical self mappings and orphaned supplemental rows.
 * No changes by default. Never write to MediaWiki's native langlinks table.
 */
if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
require_once dirname( __DIR__, 2 ) . '/maintenance/Maintenance.php';

use Wikimedia\Rdbms\IDatabase;

class WikidebatesCleanupInterlanguageMappings extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Verify an exact backup and optionally remove only FR/FR or orphan supplemental mappings.' );
		$this->addOption( 'backup', 'Path to the JSON snapshot of all current candidates.', true, true );
		$this->addOption( 'sha256', 'Expected SHA-256 of the unchanged backup file.', true, true );
		$this->addOption( 'apply', 'Delete the verified candidates in one cancelable transaction.' );
	}

	public function execute() {
		$services = $this->getServiceContainer();
		if ( $services->getContentLanguage()->getCode() !== 'fr' ||
			!class_exists( 'InterlanguageCentralExtension' ) ||
			class_exists( 'InterlanguageExtension' )
		) {
			throw new RuntimeException( 'Run on the French central wiki only.' );
		}
		$apply = $this->hasOption( 'apply' );
		if ( $apply && ( !class_exists( 'InterlanguageEffectiveLangLinks' ) ||
			!method_exists( 'InterlanguageCentralExtension', 'onLinksUpdateComplete' ) )
		) {
			throw new RuntimeException( 'Deploy the validated Interlanguage code before applying.' );
		}
		$path = $this->getOption( 'backup' );
		$hash = strtolower( $this->getOption( 'sha256' ) );
		if ( !preg_match( '/^[0-9a-f]{64}$/D', $hash ) ||
			!is_file( $path ) || !is_readable( $path )
		) {
			throw new RuntimeException( 'Invalid backup path or expected hash.' );
		}
		$bytes = file_get_contents( $path, false, null, 0, 16 * 1024 * 1024 + 1 );
		if ( $bytes === false || strlen( $bytes ) > 16 * 1024 * 1024 ||
			!hash_equals( $hash, hash( 'sha256', $bytes ) )
		) {
			throw new RuntimeException( 'Backup unreadable, oversized or SHA-256 mismatch.' );
		}
		$backup = json_decode( $bytes, true, 512, JSON_THROW_ON_ERROR );
		$db = $services->getConnectionProvider()->getPrimaryDatabase();
		if ( !is_array( $backup ) || ( $backup['database'] ?? null ) !== $db->getDomainID() ||
			!is_array( $backup['entries'] ?? null ) || !array_is_list( $backup['entries'] )
		) {
			throw new RuntimeException( 'Backup does not identify this database and a list of entries.' );
		}
		$expected = self::indexRows( $backup['entries'] );
		if ( $apply ) {
			foreach ( [ 'interlanguage_links', 'page' ] as $table ) {
				$schema = $db->query(
					'SHOW CREATE TABLE ' . $db->tableName( $table ),
					__METHOD__
				)->fetchRow();
				if ( !$schema || !preg_match( '/\bENGINE=InnoDB\b/i', $schema[1] ?? '' ) ) {
					throw new RuntimeException( 'Expected transactional InnoDB table: ' . $table );
				}
			}
		}

		$operation = function () use ( $db, $expected, $apply ) {
			$entries = [];
			$self = 0;
			$orphans = 0;
			foreach ( $db->select(
				[ 'interlanguage_links', 'page' ],
				[ 'ill_from', 'ill_lang', 'ill_title', 'page_id' ],
				[ "(ill_lang = 'fr' OR page_id IS NULL)" ],
				__METHOD__,
				$apply ? [ 'FOR UPDATE' ] : [],
				[ 'page' => [ 'LEFT JOIN', 'page_id = ill_from' ] ]
			) as $row ) {
				$entries[] = [
					'ill_from' => (int)$row->ill_from,
					'ill_lang' => $row->ill_lang,
					'ill_title' => $row->ill_title,
				];
				if ( $row->ill_lang === 'fr' ) {
					$self++;
				} else {
					$orphans++;
				}
			}
			$actual = self::indexRows( $entries );
			$this->output( "FR/FR: $self\nOther orphans: $orphans\nTotal: " . count( $actual ) . "\n" );
			if ( $actual !== $expected ) {
				$this->output( 'Backup candidates: ' . count( $expected ) . "\n" );
				throw new RuntimeException( 'Candidate rows differ from the backup; obtain and review a fresh snapshot. No deletion.' );
			}
			$this->output( "Exact backup match\n" );
			if ( !$apply ) {
				$this->output( "PREVIEW ONLY\n" );
				return;
			}

			// The verified primary-key rows are locked. Delete only those keys,
			// in small indexed groups. Recheck orphan status in the DELETE.
			$byLanguage = [];
			foreach ( $actual as $row ) {
				$byLanguage[$row['ill_lang']][] = $row['ill_from'];
			}
			$deleted = 0;
			foreach ( $byLanguage as $language => $ids ) {
				foreach ( array_chunk( $ids, 250 ) as $chunk ) {
					$conditions = [ 'ill_lang' => $language, 'ill_from' => $chunk ];
					if ( $language !== 'fr' ) {
						$conditions[] = 'NOT EXISTS (SELECT 1 FROM ' .
							$db->tableName( 'page' ) . ' WHERE page_id = ill_from)';
					}
					$db->delete( 'interlanguage_links', $conditions, __METHOD__ );
					$count = $db->affectedRows();
					if ( $count !== count( $chunk ) ) {
						throw new RuntimeException( 'Candidate changed during deletion; rolling back all changes.' );
					}
					$deleted += $count;
				}
			}
			if ( $deleted !== count( $expected ) ) {
				throw new RuntimeException( 'Unexpected total; rolling back.' );
			}
			$this->output( "Deleted rows in transaction: $deleted\n" );
		};

		if ( $apply ) {
			$db->doAtomicSection( __METHOD__, $operation, IDatabase::ATOMIC_CANCELABLE );
			$this->output( "Verify the committed result in a fresh process after successful exit. No page or cache rebuild requested.\n" );
		} else {
			$operation();
		}
	}

	/** Validate the snapshot, reject duplicate primary keys, normalize row order. */
	private static function indexRows( array $rows ): array {
		$indexed = [];
		foreach ( $rows as $row ) {
			if ( !is_array( $row ) || !is_int( $row['ill_from'] ?? null ) ||
				$row['ill_from'] < 0 || $row['ill_from'] > 4294967295 ||
				!is_string( $row['ill_lang'] ?? null ) || strlen( $row['ill_lang'] ) > 20 ||
				!is_string( $row['ill_title'] ?? null ) || strlen( $row['ill_title'] ) > 255
			) {
				throw new RuntimeException( 'Invalid snapshot row.' );
			}
			$key = $row['ill_from'] . ':' . $row['ill_lang'];
			if ( array_key_exists( $key, $indexed ) ) {
				throw new RuntimeException( 'Duplicate snapshot primary key.' );
			}
			$indexed[$key] = [
				'ill_from' => $row['ill_from'],
				'ill_lang' => $row['ill_lang'],
				'ill_title' => $row['ill_title'],
			];
		}
		ksort( $indexed, SORT_STRING );
		return $indexed;
	}
}

$maintClass = WikidebatesCleanupInterlanguageMappings::class;
require_once RUN_MAINTENANCE_IF_MAIN;
