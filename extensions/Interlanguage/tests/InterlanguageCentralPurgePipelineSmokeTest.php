<?php
/**
 * Exercise central LinksUpdate -> queue -> remote purge without network or SQL.
 *
 * Run with: php extensions/Interlanguage/tests/InterlanguageCentralPurgePipelineSmokeTest.php
 */

namespace MediaWiki\Parser {
	class ParserOutput {
		public function __construct( private array $links ) {}

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

namespace MediaWiki\Logger {
	class PipelineLogger {
		public array $warnings = [];

		public function warning( string $message, array $context = [] ): void {
			$this->warnings[] = [ $message, $context ];
		}
	}

	class LoggerFactory {
		private static ?PipelineLogger $logger = null;

		public static function getInstance( string $channel ): PipelineLogger {
			if ( $channel !== 'Interlanguage' ) {
				throw new \RuntimeException( 'Unexpected logger channel' );
			}
			return self::$logger ??= new PipelineLogger();
		}
	}
}

namespace MediaWiki {
	class PipelineDB {
		public array $existingLinks = [];

		public function select( $table, $fields, $conditions, $caller ): array {
			if ( $table !== 'langlinks' || ( $conditions['ll_from'] ?? null ) !== 42 ) {
				throw new \RuntimeException( 'Unexpected database query' );
			}
			$result = [];
			foreach ( $this->existingLinks as $lang => $target ) {
				$result[] = (object)[ 'll_lang' => $lang, 'll_title' => $target ];
			}
			return $result;
		}
	}

	class PipelineConnectionProvider {
		public PipelineDB $db;

		public function __construct() {
			$this->db = new PipelineDB();
		}

		public function getReplicaDatabase(): PipelineDB {
			return $this->db;
		}
	}

	class PipelineJobQueue {
		public array $jobs = [];

		public function push( $job ): void {
			$this->jobs[] = $job;
		}
	}

	class PipelineInterwiki {
		public function getAPI(): string {
			return 'https://en.wikidebates.org/w/api.php';
		}
	}

	class PipelineInterwikiLookup {
		public function fetch( string $language ) {
			return $language === 'en' ? new PipelineInterwiki() : false;
		}
	}

	class PipelineHttp {
		public array $requests = [];
		public string $response = '{"purge":[]}';

		public function post( string $url, array $options = [], string $caller = '' ): string {
			$this->requests[] = [ $url, $options, $caller ];
			return $this->response;
		}
	}

	class MediaWikiServices {
		private static ?self $instance = null;
		public PipelineConnectionProvider $connections;
		public PipelineJobQueue $queue;
		public PipelineInterwikiLookup $interwiki;
		public PipelineHttp $http;

		private function __construct() {
			$this->connections = new PipelineConnectionProvider();
			$this->queue = new PipelineJobQueue();
			$this->interwiki = new PipelineInterwikiLookup();
			$this->http = new PipelineHttp();
		}

		public static function getInstance(): self {
			return self::$instance ??= new self();
		}

		public function getConnectionProvider(): PipelineConnectionProvider {
			return $this->connections;
		}

		public function getJobQueueGroup(): PipelineJobQueue {
			return $this->queue;
		}

		public function getInterwikiLookup(): PipelineInterwikiLookup {
			return $this->interwiki;
		}

		public function getHttpRequestFactory(): PipelineHttp {
			return $this->http;
		}
	}
}

namespace {
	const DB_PRIMARY = 0;
	const DB_REPLICA = 1;

	class Title extends \MediaWiki\Title\Title {}

	class Job {
		protected array $params;

		public function __construct( string $type, Title $title, array $params ) {
			if ( $type !== 'purgeDependentWikis' ) {
				throw new \RuntimeException( 'Unexpected job type' );
			}
			$this->params = $params;
		}
	}

	class PipelineLinksUpdate {
		public function __construct(
			private Title $title,
			private \MediaWiki\Parser\ParserOutput $output
		) {}

		public function getTitle(): Title {
			return $this->title;
		}

		public function getParserOutput(): \MediaWiki\Parser\ParserOutput {
			return $this->output;
		}
	}

	function assertPipeline( bool $ok, string $message ): void {
		if ( !$ok ) {
			throw new \RuntimeException( $message );
		}
	}

	function wfArrayToCGI( array $values ): string {
		return http_build_query( $values );
	}

	require_once dirname( __DIR__ ) . '/InterlanguageCentralExtension.php';
	require_once dirname( __DIR__ ) . '/InterlanguageCentralExtensionPurgeJob.php';

	$services = \MediaWiki\MediaWikiServices::getInstance();
	$services->connections->db->existingLinks = [ 'en' => 'Old:Page' ];

	$unchanged = new PipelineLinksUpdate(
		new Title(),
		new \MediaWiki\Parser\ParserOutput( [ 'en:Old:Page' ] )
	);
	\InterlanguageCentralExtension::onLinksUpdate( $unchanged );
	assertPipeline( count( $services->queue->jobs ) === 0,
		'An unchanged translation must not enqueue any purge job' );
	assertPipeline( $services->http->requests === [],
		'The comparison must not generate HTTP requests directly' );

	$changed = new PipelineLinksUpdate(
		new Title(),
		new \MediaWiki\Parser\ParserOutput( [ 'en:New:Page' ] )
	);
	\InterlanguageCentralExtension::onLinksUpdate( $changed );
	assertPipeline( count( $services->queue->jobs ) === 1,
		'Changing one translation must enqueue exactly one central job' );

	$services->http->response =
		'{"purge":[{"title":"Old:Page","purged":true},{"title":"New:Page","purged":true}]}';
	$job = $services->queue->jobs[0];
	assertPipeline( $job instanceof \InterlanguageCentralExtensionPurgeJob,
		'The queued job must be the production purge job class' );
	assertPipeline( $job->run() === true, 'The simulated purge job must complete' );
	assertPipeline( count( $services->http->requests ) === 1,
		'Exactly one outbound POST must be made to EN' );

	[ $url, $options ] = $services->http->requests[0];
	assertPipeline( parse_url( $url, PHP_URL_HOST ) === 'en.wikidebates.org',
		'The purge destination must be the English wiki' );
	assertPipeline( parse_url( $url, PHP_URL_PATH ) === '/w/api.php',
		'The purge must reach the API endpoint' );
	parse_str( (string)parse_url( $url, PHP_URL_QUERY ), $parameters );
	assertPipeline(
		( $parameters['action'] ?? null ) === 'purge' &&
		( $parameters['format'] ?? null ) === 'json' &&
		( $parameters['titles'] ?? null ) === 'Old:Page|New:Page',
		'Both former and current EN targets must be purged without altering their colons'
	);
	assertPipeline( $options === [], 'The job must retain default HTTP options' );
	assertPipeline(
		\MediaWiki\Logger\LoggerFactory::getInstance( 'Interlanguage' )->warnings === [],
		'Simulated successful purge must not produce warnings'
	);

	echo "Interlanguage central purge pipeline: OK\n";
}
