<?php
/**
 * Test remote API JSON decoding without making network requests.
 *
 * Run with: php extensions/Interlanguage/tests/InterlanguageApiSmokeTest.php
 */
namespace MediaWiki {
	class InterlanguageTestHttpClient {
		public ?string $url = null;
		public $response = null;

		public function get( string $url, array $options = [], string $caller = '' ) {
			$this->url = $url;
			return $this->response;
		}
	}

	class MediaWikiServices {
		private static ?self $instance = null;
		private InterlanguageTestHttpClient $http;

		private function __construct() {
			$this->http = new InterlanguageTestHttpClient();
		}

		public static function getInstance(): self {
			return self::$instance ??= new self();
		}

		public function getHttpRequestFactory(): InterlanguageTestHttpClient {
			return $this->http;
		}
	}
}

namespace {
	// Force the legacy getKeyNS() fallback; no MediaWiki bootstrap required.
	class Title {
		public static function newFromText( string $text ) {
			return false;
		}
	}

	require_once dirname( __DIR__ ) . '/InterlanguageExtension.php';

	function assertInterlanguageApi( bool $condition, string $message ): void {
		if ( !$condition ) {
			throw new \RuntimeException( $message );
		}
	}

	$wgInterlanguageExtensionApiUrl = 'https://example.invalid/w/api.php';
	$client = \MediaWiki\MediaWikiServices::getInstance()->getHttpRequestFactory();
	$extension = new \InterlanguageExtension();

	$client->response = json_encode( [
		'query' => [
			'pages' => [
				'12' => [
					'pageid' => 12,
					'langlinks' => [
						[ 'lang' => 'en', '*' => 'Topic:With colon' ],
						[ 'lang' => 'fr', '*' => 'Une page' ],
					],
				],
			],
		],
	] );

	$result = $extension->getLinksFromApi( 'Test page' );
	assertInterlanguageApi( is_array( $result ), 'API result is not an array' );
	assertInterlanguageApi(
		( $result['query']['pages']['12']['langlinks'][0]['*'] ?? null ) === 'Topic:With colon',
		'API formatversion=1 langlinks were not preserved'
	);
	assertInterlanguageApi(
		str_contains( $client->url ?? '', 'format=json&formatversion=1' ) &&
			str_contains( $client->url ?? '', 'titles=Test_page' ),
		'The API URL does not request version 1 JSON and the expected title'
	);

	[ $success, $links ] = $extension->processLinks( $result, 'Test page' );
	assertInterlanguageApi( $success === true, 'Valid API response not accepted' );
	assertInterlanguageApi(
		$links[0]['lang'] === 'en' && $links[0]['*'] === 'Topic:With colon',
		'Processing discarded an interlanguage link'
	);

	foreach ( [ null, '', 'invalid json', '"unexpected scalar"', '[]',
		'{"query":{"pages":[]}}', '{"query":{"pages":"broken"}}'
	] as $response ) {
		$client->response = $response;
		$bad = $extension->getLinksFromApi( 'Test page' );
		assertInterlanguageApi( $bad === [], 'Invalid API response should be ignored' );
	}

	echo "Interlanguage API smoke tests: OK\n";
}
