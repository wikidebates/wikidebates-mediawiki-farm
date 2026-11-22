<?php
/**
 * Test remote purge response diagnostics without sending HTTP requests.
 *
 * Run with: php extensions/Interlanguage/tests/InterlanguagePurgeSmokeTest.php
 */
namespace MediaWiki\Logger {
	class InterlanguagePurgeTestLogger {
		public array $warnings = [];

		public function warning( string $message, array $context = [] ): void {
			$this->warnings[] = [ $message, $context ];
		}
	}

	class LoggerFactory {
		private static ?InterlanguagePurgeTestLogger $logger = null;

		public static function getInstance( string $channel ): InterlanguagePurgeTestLogger {
			if ( $channel !== 'Interlanguage' ) {
				throw new \RuntimeException( 'Unexpected log channel' );
			}
			return self::$logger ??= new InterlanguagePurgeTestLogger();
		}
	}
}

namespace MediaWiki {
	class InterlanguagePurgeTestInterwiki {
		public function getAPI(): string {
			return 'https://example.invalid/w/api.php';
		}
	}

	class InterlanguagePurgeTestMissingApiInterwiki extends InterlanguagePurgeTestInterwiki {
		public function getAPI(): string {
			return '';
		}
	}

	class InterlanguagePurgeTestInterwikiLookup {
		public function fetch( string $lang ) {
			if ( $lang === 'en' ) {
				return new InterlanguagePurgeTestInterwiki();
			}
			if ( $lang === 'noapi' ) {
				return new InterlanguagePurgeTestMissingApiInterwiki();
			}
			return false;
		}
	}

	class InterlanguagePurgeTestHttp {
		public $response = null;
		public array $urls = [];

		public function post( string $url, array $options = [], string $caller = '' ) {
			$this->urls[] = $url;
			return $this->response;
		}
	}

	class MediaWikiServices {
		private static ?self $instance = null;
		public InterlanguagePurgeTestHttp $http;
		public InterlanguagePurgeTestInterwikiLookup $lookup;

		private function __construct() {
			$this->http = new InterlanguagePurgeTestHttp();
			$this->lookup = new InterlanguagePurgeTestInterwikiLookup();
		}

		public static function getInstance(): self {
			return self::$instance ??= new self();
		}

		public function getInterwikiLookup(): InterlanguagePurgeTestInterwikiLookup {
			return $this->lookup;
		}

		public function getHttpRequestFactory(): InterlanguagePurgeTestHttp {
			return $this->http;
		}
	}
}

namespace {
	class Title {}

	class Job {
		protected array $params;

		public function __construct( string $type, Title $title, array $params ) {
			$this->params = $params;
		}
	}

	function wfArrayToCGI( array $params ): string {
		return http_build_query( $params );
	}

	require_once dirname( __DIR__ ) . '/InterlanguageCentralExtensionPurgeJob.php';

	function assertInterlanguagePurge( bool $ok, string $message ): void {
		if ( !$ok ) {
			throw new \RuntimeException( $message );
		}
	}

	$services = \MediaWiki\MediaWikiServices::getInstance();
	$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'Interlanguage' );
	$job = new \InterlanguageCentralExtensionPurgeJob(
		new Title(),
		[ 'ill' => [ 'en' => [ 'Topic:Subtopic' => true ] ] ]
	);

	$services->http->response = '{"purge":[{"title":"Topic:Subtopic","purged":true}]}';
	assertInterlanguagePurge( $job->run() === true, 'Successful purge must complete' );
	assertInterlanguagePurge(
		count( $logger->warnings ) === 0,
		'Successful purge should not generate a warning'
	);
	assertInterlanguagePurge(
		count( $services->http->urls ) === 1 &&
			str_contains( $services->http->urls[0], 'action=purge' ) &&
			str_contains( $services->http->urls[0], 'Topic%3ASubtopic' ),
		'Purge must target the existing remote wiki and retain the full title'
	);

	$services->http->response = '{"error":{"code":"permissiondenied"}}';
	assertInterlanguagePurge( $job->run() === true, 'Purge diagnostics must preserve the current job semantics' );
	assertInterlanguagePurge( count( $logger->warnings ) === 1, 'API errors must be logged' );

	$services->http->response = null;
	assertInterlanguagePurge( $job->run() === true, 'Network failure must preserve the current job semantics' );
	assertInterlanguagePurge( count( $logger->warnings ) === 2, 'Missing HTTP responses must be logged' );

	// ApiPurge returns an array even when the anonymous request lacks
	// the purge right: no "purged":true flag is set, only warnings.
	$services->http->response = '{"purge":[{"title":"Topic:Subtopic"}],"warnings":{"main":{"*":"Permission denied"}}}';
	assertInterlanguagePurge( $job->run() === true, 'Permission denial must not trigger retries' );
	assertInterlanguagePurge( count( $logger->warnings ) === 3, 'Missing purged:true must be logged' );

	$services->http->response = '{"purge":[{"title":"Topic:Subtopic","purged":false}]}';
	assertInterlanguagePurge( $job->run() === true, 'Purge status false must not retry' );
	assertInterlanguagePurge( count( $logger->warnings ) === 4, 'Explicit false purge status must be logged' );

	$services->http->response = '{"purge":[]}';
	assertInterlanguagePurge( $job->run() === true, 'Empty response must not retry' );
	assertInterlanguagePurge( count( $logger->warnings ) === 5, 'Empty purge list must be logged' );

	// A partial batch is not a successful purge, even if its single
	// returned page is marked purged:true.
	$multiJob = new \InterlanguageCentralExtensionPurgeJob(
		new Title(),
		[ 'ill' => [ 'en' => [
			'Topic:Subtopic' => true,
			'Another title' => true,
		] ] ]
	);
	$services->http->response = '{"purge":[{"title":"Topic:Subtopic","purged":true}]}';
	assertInterlanguagePurge( $multiJob->run() === true, 'Partial batch must keep current job semantics' );
	assertInterlanguagePurge( count( $logger->warnings ) === 6, 'Partial purge batch must be logged' );

	$services->http->response = '{"purge":[{"title":"Topic:Subtopic","purged":true},{"title":"Another title","purged":true}]}';
	assertInterlanguagePurge( $multiJob->run() === true, 'Successful multiple purges must complete' );
	assertInterlanguagePurge( count( $logger->warnings ) === 6, 'Complete batch should not log warnings' );

	$services->http->response = '{"purge":[{"title":"Topic:Subtopic","purged":true},{"title":"Another title"}]}';
	assertInterlanguagePurge( $multiJob->run() === true, 'Mixed batch must not retry' );
	assertInterlanguagePurge( count( $logger->warnings ) === 7, 'One missing success flag must be logged' );

	$services->http->response = '{"purge":[{"title":"Topic:Subtopic","purged":true}],"error":{"code":"badrequest"}}';
	assertInterlanguagePurge( $job->run() === true, 'Global API error must not retry' );
	assertInterlanguagePurge( count( $logger->warnings ) === 8, 'Global API error must still be logged' );

	// Missing interwiki entries and absent API URLs must be observable,
	// without HTTP calls or any automatic retry.
	$missingEndpointsJob = new \InterlanguageCentralExtensionPurgeJob(
		new Title(),
		[ 'ill' => [
			'xx' => [ 'Missing entry' => true ],
			'noapi' => [ 'Missing endpoint' => true ],
		] ]
	);
	$urlsBefore = count( $services->http->urls );
	assertInterlanguagePurge( $missingEndpointsJob->run() === true,
		'Missing endpoints must preserve non-retrying job behavior' );
	assertInterlanguagePurge( count( $services->http->urls ) === $urlsBefore,
		'Skipped destinations must not generate HTTP traffic' );
	assertInterlanguagePurge( count( $logger->warnings ) === 9,
		'Missing endpoints must generate one aggregated warning per job' );
	$warning = $logger->warnings[8];
	assertInterlanguagePurge(
		$warning[1]['missing_prefixes'] === 1 &&
		$warning[1]['missing_apis'] === 1 &&
		str_contains( $warning[1]['languages'], 'xx' ) &&
		str_contains( $warning[1]['languages'], 'noapi' ),
		'Missing endpoint warning must distinguish missing prefixes and API URLs'
	);
	assertInterlanguagePurge(
		!str_contains( json_encode( $warning ), 'Missing entry' ) &&
		!str_contains( json_encode( $warning ), 'Missing endpoint' ),
		'Missing endpoint warning must not reveal page titles'
	);

	echo "Interlanguage purge smoke tests: OK\n";
}
