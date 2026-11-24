<?php

declare( strict_types=1 );

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\StubObject\StubGlobalUser;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

require_once dirname( __DIR__, 2 ) . '/maintenance/Maintenance.php';
require_once __DIR__ . '/EditorialSeoMigration.php';

/**
 * Run once per edition with the real farm's --wiki option.
 * No edit without explicit --apply, exact revision checks and a private backup.
 */
final class ApplyEditorialSeo extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Prepare or apply the reviewed editorial SEO migration' );
		$this->addOption( 'user', 'Existing authorised local MediaWiki account', true, true );
		$this->addOption( 'backup-dir', 'Existing private directory outside web roots', true, true );
		$this->addOption( 'apply', 'Write revisions after matching the saved preparation' );
	}

	public function execute() {
		umask( 0077 );
		$services = $this->getServiceContainer();
		$config = $services->getMainConfig();
		$lang = $config->get( 'LanguageCode' );
		if ( !isset( EditorialSeoMigration::COUNTS[$lang] )
			|| $config->get( 'DBname' ) !== $lang . 'wikidebates' ) {
			$this->fatalError( 'Not an expected Wikidebates edition' );
		}
		$dir = realpath( $this->getOption( 'backup-dir' ) );
		if ( $dir === false || !is_dir( $dir ) || !is_writable( $dir )
			|| ( fileperms( $dir ) & 0077 ) !== 0
			|| $dir === '/home/users/webmaster/www'
			|| str_starts_with( $dir . '/', '/home/users/webmaster/www/' )
			|| str_starts_with( $dir . '/', realpath( dirname( __DIR__, 2 ) ) . '/' ) ) {
			$this->fatalError( 'Backup directory must be private (0700) and outside web roots' );
		}
		$user = User::newFromName( $this->getOption( 'user' ) );
		if ( !$user || !$user->isRegistered() ) {
			$this->fatalError( 'An existing local account is required; no account will be created' );
		}
		StubGlobalUser::setUser( $user );

		try {
			$sourceDir = dirname( __DIR__, 2 ) . '/docs/editorial-seo';
			$entries = EditorialSeoMigration::load( $lang, $sourceDir );
			$this->checkCallers( $lang, $entries );
			$plan = [
				'wiki' => $config->get( 'DBname' ), 'user' => $user->getName(), 'pages' => [],
			];
			foreach ( $entries as $entry ) {
				$title = Title::newFromText( $entry['title'] );
				if ( !$title || $title->getPrefixedText() !== $entry['title'] ) {
					throw new RuntimeException( 'Unexpected title normalization: ' . $entry['title'] );
				}
				if ( !$services->getPermissionManager()->userCan( 'edit', $user, $title ) ) {
					throw new RuntimeException( 'Edit permission denied: ' . $entry['title'] );
				}
				$page = $services->getWikiPageFactory()->newFromTitle( $title );
				$updater = $page->newPageUpdater( $user );
				$revision = $updater->grabParentRevision();
				if ( !$revision || $revision->getId() !== $entry['revid'] ) {
					throw new RuntimeException( 'Revision changed: ' . $entry['title'] .
						' (expected ' . $entry['revid'] . ', actual ' . ( $revision?->getId() ?? 0 ) . ')' );
				}
				$content = $revision->getContent( SlotRecord::MAIN );
				if ( !$content instanceof WikitextContent || $content->isRedirect() ) {
					throw new RuntimeException( 'Unexpected content model or redirect: ' . $entry['title'] );
				}
				if ( ( $entry['operations'][0]['kind'] ?? null ) === 'append' ) {
					$this->checkNoExistingMetadata( $revision->getPageId(), $entry['title'] );
				}
				$before = $content->getText();
				$after = EditorialSeoMigration::transform( $before, $entry );
				if ( $before === $after ) {
					throw new RuntimeException( 'No change: ' . $entry['title'] );
				}
				$plan['pages'][] = [
					'title' => $entry['title'], 'revid' => $entry['revid'],
					'before' => $before, 'after' => $after,
				];
			}
			$backup = $dir . '/' . $lang . '.json';
			if ( !$this->hasOption( 'apply' ) ) {
				$this->writeExclusive( $backup, json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE |
					JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );
				$this->output( 'PREPARED ' . $lang . ' ' . count( $plan['pages'] ) . " pages\n" );
				return;
			}

			// The normal dry run is allowed before the technical SEO deployment,
			// but removing static hreflang is not safe until all routes are set.
			$routes = $config->get( 'WikiSeoInterlanguageArticleUrls' );
			foreach ( array_keys( EditorialSeoMigration::COUNTS ) as $code ) {
				if ( ( $routes[$code] ?? null ) !== 'https://' . $code . '.wikidebates.org/wiki/$1' ) {
					throw new RuntimeException( 'Dynamic hreflang route missing or unexpected: ' . $code );
				}
			}
			if ( !is_file( $backup ) || is_link( $backup ) ||
				json_decode( file_get_contents( $backup ), true, 512, JSON_THROW_ON_ERROR ) !== $plan ) {
				throw new RuntimeException( 'Prepared backup missing or not identical to the current plan' );
			}
			$journalPath = $dir . '/' . $lang . '.applied.jsonl';
			$journal = fopen( $journalPath, 'x' );
			if ( $journal === false ) {
				throw new RuntimeException( 'Application journal already exists or cannot be opened; inspect previous run' );
			}
			try {
				foreach ( $plan['pages'] as $item ) {
					$title = Title::newFromText( $item['title'] );
					if ( !$services->getPermissionManager()->userCan( 'edit', $user, $title ) ) {
						throw new RuntimeException( 'Edit permission changed: ' . $item['title'] );
					}
					$updater = $services->getWikiPageFactory()->newFromTitle( $title )->newPageUpdater( $user );
					// Fix the expected parent for PageUpdater's compare-and-swap.
					if ( $updater->hasEditConflict( $item['revid'] ) ) {
						throw new RuntimeException( 'Concurrent edit: ' . $item['title'] );
					}
					$updater->setContent( SlotRecord::MAIN, ContentHandler::makeContent( $item['after'], $title ) );
					$new = $updater->saveRevision(
						CommentStoreComment::newUnsavedComment( 'Editorial SEO: specific metadata and structured data' ),
						EDIT_UPDATE
					);
					if ( !$updater->wasSuccessful() || !$updater->wasRevisionCreated() || !$new ) {
						throw new RuntimeException( 'Revision not saved: ' . $item['title'] );
					}
					$services->getDBLoadBalancerFactory()->commitPrimaryChanges( __METHOD__ );
					$line = json_encode( [
						'title' => $item['title'], 'old_revid' => $item['revid'], 'new_revid' => $new->getId(),
					], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n";
					if ( fwrite( $journal, $line ) !== strlen( $line ) || !fflush( $journal ) ) {
						throw new RuntimeException( 'Journal write failed after saving: ' . $item['title'] );
					}
					$this->output( $line );
					DeferredUpdates::doUpdates();
				}
			} finally {
				fclose( $journal );
			}
			$this->output( 'APPLIED ' . $lang . ' ' . count( $plan['pages'] ) . " pages\n" );
		} catch ( Throwable $e ) {
			$this->fatalError( $e->getMessage() . "\nStopped; inspect private backup and journal before any retry." );
		}
	}

	private function writeExclusive( string $path, string $data ): void {
		$file = fopen( $path, 'x' );
		if ( $file === false ) {
			throw new RuntimeException( 'Backup already exists or cannot be written: ' . $path );
		}
		try {
			if ( fwrite( $file, $data ) !== strlen( $data ) || !fflush( $file ) ) {
				throw new RuntimeException( 'Incomplete backup: ' . $path );
			}
		} finally {
			fclose( $file );
		}
	}

	private function checkCallers( string $lang, array $entries ): void {
		if ( !isset( EditorialSeoMigration::TEMPLATES[$lang] ) ) {
			return;
		}
		$title = Title::newFromText( EditorialSeoMigration::TEMPLATES[$lang][0] );
		$db = $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase();
		$rows = $db->newSelectQueryBuilder()
			->select( [ 'page_namespace', 'page_title' ] )
			->from( 'templatelinks' )
			->join( 'linktarget', null, 'lt_id = tl_target_id' )
			->join( 'page', null, 'page_id = tl_from' )
			->where( [ 'lt_namespace' => $title->getNamespace(), 'lt_title' => $title->getDBkey() ] )
			->caller( __METHOD__ )->fetchResultSet();
		$actual = [];
		foreach ( $rows as $row ) {
			$actual[] = Title::makeTitle( (int)$row->page_namespace, $row->page_title )->getPrefixedText();
		}
		sort( $actual, SORT_STRING );
		if ( $actual !== EditorialSeoMigration::expectedCallers( $lang, $entries ) ) {
			throw new RuntimeException( 'Template callers have changed; repeat the editorial inventory' );
		}
	}

	private function checkNoExistingMetadata( int $pageId, string $title ): void {
		$db = $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase();
		$rows = $db->newSelectQueryBuilder()->select( 'pp_propname' )->from( 'page_props' )
			->where( [ 'pp_page' => $pageId, 'pp_propname' => [
				'title', 'description', 'type', 'og_type', 'schema_type',
			] ] )->caller( __METHOD__ )->fetchResultSet();
		if ( $rows->numRows() > 0 ) {
			throw new RuntimeException( 'Existing SEO properties on append target: ' . $title );
		}
	}
}

$maintClass = ApplyEditorialSeo::class;
require_once RUN_MAINTENANCE_IF_MAIN;
