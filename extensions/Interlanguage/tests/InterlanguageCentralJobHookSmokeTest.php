<?php
/**
 * Regression tests for LinksUpdate jobs against MW 1.43 ParserOutput values.
 *
 * Run with: php extensions/Interlanguage/tests/InterlanguageCentralJobHookSmokeTest.php
 */

namespace MediaWiki\Parser {
	class ParserOutput {
		private array $links;

		public function __construct( array $links ) {
			$this->links = $links;
		}

		public function getLanguageLinks(): array {
			return $this->links;
		}
	}
}

namespace MediaWiki\Title {
	class Title {
		public function getArticleID(): int {
			return 42;
		}
	}
}

namespace MediaWiki {
	class CentralJobHookTestDb {
		public array $old = [];

		public function select( $table, $fields, $conditions, $caller ): array {
			if ( $table !== 'langlinks' || $conditions['ll_from'] !== 42 ) {
				throw new \RuntimeException( 'Unexpected query to central link table' );
			}
			return array_map(
				static fn ( $lang, $title ) => (object)[
					'll_lang' => $lang,
					'll_title' => $title,
				],
				array_keys( $this->old ),
				array_values( $this->old )
			);
		}
	}

	class CentralJobHookTestProvider {
		public CentralJobHookTestDb $db;

		public function __construct() {
			$this->db = new CentralJobHookTestDb();
		}

		public function getReplicaDatabase(): CentralJobHookTestDb {
			return $this->db;
		}
	}

	class CentralJobHookTestQueue {
		public array $jobs = [];

		public function push( $job ): void {
			$this->jobs[] = $job;
		}
	}

	class MediaWikiServices {
		private static ?self $instance = null;
		public CentralJobHookTestProvider $provider;
		public CentralJobHookTestQueue $queue;

		private function __construct() {
			$this->provider = new CentralJobHookTestProvider();
			$this->queue = new CentralJobHookTestQueue();
		}

		public static function getInstance(): self {
			return self::$instance ??= new self();
		}

		public function getConnectionProvider(): CentralJobHookTestProvider {
			return $this->provider;
		}

		public function getJobQueueGroup(): CentralJobHookTestQueue {
			return $this->queue;
		}
	}
}

namespace {
	const DB_PRIMARY = 0;
	const DB_REPLICA = 1;

	class InterlanguageCentralExtensionPurgeJob {
		public \MediaWiki\Title\Title $title;
		public array $params;

		public function __construct( \MediaWiki\Title\Title $title, array $params ) {
			$this->title = $title;
			$this->params = $params;
		}
	}

	class CentralJobHookTestLinksUpdate {
		private \MediaWiki\Title\Title $title;
		private ?\MediaWiki\Parser\ParserOutput $parserOutput;

		public function __construct( ?\MediaWiki\Parser\ParserOutput $output ) {
			$this->title = new \MediaWiki\Title\Title();
			$this->parserOutput = $output;
		}

		public function getTitle(): \MediaWiki\Title\Title {
			return $this->title;
		}

		public function getParserOutput(): ?\MediaWiki\Parser\ParserOutput {
			return $this->parserOutput;
		}
	}

	function assertCentralHook( bool $pass, string $message ): void {
		if ( !$pass ) {
			throw new \RuntimeException( $message );
		}
	}

	require_once dirname( __DIR__ ) . '/InterlanguageCentralExtension.php';

	$services = \MediaWiki\MediaWikiServices::getInstance();

	$run = static function ( array $old, ?array $new ) use ( $services ): array {
		$services->provider->db->old = $old;
		$services->queue->jobs = [];
		$output = $new === null ? null : new \MediaWiki\Parser\ParserOutput( $new );
		\InterlanguageCentralExtension::onLinksUpdate( new CentralJobHookTestLinksUpdate( $output ) );
		return $services->queue->jobs;
	};

	$jobs = $run( [ 'en' => 'Topic:Subtitle' ], [ 'en:Topic:Subtitle' ] );
	assertCentralHook( $jobs === [], 'Unchanged link with a colon must not queue a job' );

	$jobs = $run( [ 'en' => 'Old:Name' ], [ 'en:New:Name' ] );
	assertCentralHook( count( $jobs ) === 1, 'Changed target must queue one job' );
	assertCentralHook( $jobs[0]->params['ill'] === [
		'en' => [ 'Old:Name' => true, 'New:Name' => true ],
	], 'Purge job must target both old and new English titles' );

	$jobs = $run( [], [ 'en:First', 'fr:Projet:Second' ] );
	assertCentralHook( count( $jobs ) === 1, 'Added links must queue one job' );
	assertCentralHook( $jobs[0]->params['ill'] === [
		'en' => [ 'First' => true ],
		'fr' => [ 'Projet:Second' => true ],
	], 'Purge job must use actual language codes, never numeric array indices' );

	$jobs = $run( [ 'en' => 'Old' ], [] );
	assertCentralHook( count( $jobs ) === 1, 'Removed link must queue one job' );
	assertCentralHook( $jobs[0]->params['ill'] === [
		'en' => [ 'Old' => true ],
	], 'Removed link must purge its previous title' );

	$jobs = $run( [], [] );
	assertCentralHook( $jobs === [], 'Empty unchanged links must not queue jobs' );

	$jobs = $run( [ 'en' => 'Old' ], null );
	assertCentralHook( $jobs === [], 'Missing ParserOutput must not spuriously purge old links' );

	$jobs = $run( [], [ 'en:First', 'en:Second' ] );
	assertCentralHook( count( $jobs ) === 1, 'Duplicate language must queue one job' );
	assertCentralHook( $jobs[0]->params['ill'] === [
		'en' => [ 'First' => true ],
	], 'First target per language must take precedence, matching MediaWiki core' );

	echo "Interlanguage central LinksUpdate smoke tests: OK\n";
}
