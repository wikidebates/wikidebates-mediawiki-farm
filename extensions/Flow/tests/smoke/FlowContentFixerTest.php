<?php
/** Integration-style smoke checks with MediaWiki link boundary doubles; requires ext-dom. */
namespace MediaWiki\Title {
	class Title {
		public function __construct( private string $text ) {
		}
		public static function newFromText( $text, $namespace = null ) {
			return $text !== '' ? new self( $text ) : null;
		}
		public static function newMainPage() {
			return new self( 'Main Page' );
		}
		public function getDBkey() {
			return $this->text;
		}
		public function getNamespace() {
			return 0;
		}
	}
}
namespace MediaWiki\Html {
	class Html {
		public static function rawElement( $tag, $attributes, $content ) {
			return "<$tag>" . $content . "</$tag>";
		}
		public static function element( $tag, array $attributes ) {
			$serialized = '';
			foreach ( $attributes as $key => $value ) {
				$serialized .= ' ' . $key . '="' . htmlspecialchars( $value, ENT_QUOTES ) . '"';
			}
			return "<$tag$serialized />";
		}
	}
}
namespace MediaWiki\Utils {
	class UrlUtils {
		public function expand( $url, $protocol ) {
			return '/wiki/';
		}
		public function matchesDomainList( $url, $exceptions ) {
			return false;
		}
	}
}
namespace Flow\Hooks {
	class HookRunner {
		public static int $calls = 0;
		public function __construct( $container ) {
		}
		public function onFlowCheckHtmlContentXss( string $html ): bool {
			self::$calls++;
			return !str_contains( $html, '<script' );
		}
	}
}
namespace MediaWiki\Cache {
	class LinkBatch {
		public array $titles = [];
		public array $batches = [];
		public function addObj( $title ) {
			$this->titles[$title->getDBkey()] = $title;
		}
		public function isEmpty() {
			return !$this->titles;
		}
		public function setCaller( $caller ) {
		}
		public function setArray( $data ) {
			$this->titles = $data;
		}
		public function execute() {
			$this->batches[] = array_keys( $this->titles );
			foreach ( $this->titles as $title ) {
				$exists = $title->getDBkey() !== 'Missing';
				\MediaWiki\MediaWikiServices::$linkCache[$title->getDBkey()] = $exists;
			}
		}
	}
}
namespace MediaWiki {
	class MediaWikiServices {
		public static array $linkCache = [];
		public static function getInstance() {
			return new self;
		}
		public function getHookContainer() {
			return new \stdClass;
		}
		public function getLinkRenderer() {
			return new class {
				public function makeLink( $title, $label, $attributes ) {
					$key = $title->getDBkey();
					if ( !array_key_exists( $key, MediaWikiServices::$linkCache ) ) {
						throw new \LogicException( 'LinkRenderer was called before LinkBatch' );
					}
					$class = MediaWikiServices::$linkCache[$key] ? '' : ' class="new"';
					return '<a href="/wiki/' . rawurlencode( $key ) . '"' . $class . '>' . $label->html . '</a>';
				}
			};
		}
	}
}
namespace {
	if ( !defined( 'PROTO_RELATIVE' ) ) {
		define( 'PROTO_RELATIVE', 0 );
	}
	if ( !function_exists( 'wfDebugLog' ) ) {
		function wfDebugLog( $group, $message ) {
		}
	}
	class HtmlArmor {
		public function __construct( public string $html ) {
		}
	}
	if ( !class_exists( \DOMDocument::class ) ) {
		throw new \RuntimeException( 'Install PHP ext-dom to run this test' );
	}
	spl_autoload_register( static function ( $class ) {
		if ( str_starts_with( $class, 'Flow\\' ) ) {
			$file = __DIR__ . '/../../includes/' . str_replace( '\\', '/', substr( $class, 5 ) ) . '.php';
			if ( is_file( $file ) ) {
				require_once $file;
			}
		}
	} );
	function check( $condition, $message ) {
		if ( !$condition ) {
			throw new \RuntimeException( $message );
		}
		$GLOBALS['flow_test_assertions'] = ( $GLOBALS['flow_test_assertions'] ?? 0 ) + 1;
	}

	$batch = new \MediaWiki\Cache\LinkBatch;
	$fixer = new \Flow\Parsoid\ContentFixer(
		new \Flow\Parsoid\Fixer\WikiLinkFixer( $batch )
	);
	$title = \MediaWiki\Title\Title::newFromText( 'Talk:Board' );
	$html = $fixer->apply(
		'<body><p><a href="./Existing" rel="mw:WikiLink">Existing</a> ' .
		'<a href="./Missing" rel="mw:WikiLink">Missing</a></p></body>', $title
	);
	check( count( $batch->batches ) === 1, 'Two wiki links need exactly one batch query' );
	check( count( $batch->batches[0] ) === 2, 'All matching titles were included in the batch' );
	check( str_contains( $html, 'href="/wiki/Existing"' ), 'Existing wiki link was resolved' );
	check( str_contains( $html, 'href="/wiki/Missing" class="new"' ), 'Red link was resolved' );
	check( substr_count( $html, 'href="/wiki/' ) === 2, 'No wikilinks were lost while replacing nodes' );
	$fixer->apply( '<body><a href="./New" rel="mw:WikiLink">New</a></body>', $title );
	check( count( $batch->batches ) === 2 && $batch->batches[1] === [ 'New' ],
		'Later messages do not requery links from earlier messages' );
	check( $fixer->apply( 'Simple text 123', $title ) === 'Simple text 123',
		'Plain text preserves its exact bytes and skips the DOM' );
	check( $fixer->apply( 'a &amp; b', $title ) === 'a &amp; b',
		'HTML entities still require the normal DOM processing' );
	check( count( $batch->batches ) === 2, 'Messages without wikilinks do not trigger link batches' );

	// The benchmark's per-stage observer must preserve the generated HTML.
	$content = '<body><p>Bonjour <strong>Wikidébats</strong> &amp; Flow.</p></body>';
	$unprofiled = $fixer->apply( $content, $title );
	$durations = [];
	$fixer->setBenchmarkTimer(
		static function ( string $phase, float $ms ) use ( &$durations ): void {
			$durations[$phase][] = $ms;
		}
	);
	check( $fixer->apply( $content, $title ) === $unprofiled,
		'Profiled DOM correction produces identical HTML' );
	foreach ( [ 'dom_parse', 'dom_fixers', 'dom_serialize' ] as $stage ) {
		$key = 'format_detail_content_' . $stage;
		check( isset( $durations[$key][0] ) && $durations[$key][0] >= 0,
			'Profiling records a nonnegative ' . $stage . ' duration' );
	}
	$events = count( $durations );
	check( $fixer->apply( 'Simple text 123', $title ) === 'Simple text 123',
		'Profiled plaintext preserves the original fast path' );
	check( count( $durations ) === $events,
		'Plaintext does not register fictitious DOM work' );

	class ProfiledHtmlRevision extends \Flow\Model\PostRevision {
		public function __construct( private \MediaWiki\Title\Title $title ) {
		}
		public function getContent( $format = 'html' ) {
			check( $format === 'html', 'ContentFixer reads the existing HTML format' );
			return '<body><p>Content <em>from revision</em>.</p></body>';
		}
		public function getCollection() {
			return new class( $this->title ) {
				public function __construct( private \MediaWiki\Title\Title $title ) {
				}
				public function getTitle() {
					return $this->title;
				}
			};
		}
	}
	$revision = new ProfiledHtmlRevision( $title );
	$source = $fixer->getContent( $revision );
	check( $source === $fixer->apply( $revision->getContent( 'html' ), $title ),
		'The timed getContent path preserves content and source HTML' );
	check( isset( $durations['format_detail_content_source_html'][0] ),
		'Profiling measures HTML source retrieval as distinct from DOM processing' );
	$fixer->setBenchmarkTimer( null );
	check( $fixer->getContent( $revision ) === $source,
		'Disabling profiling restores the unchanged noninstrumented path' );

	// An inherited getContent('html') must still run the XSS hook, decoding
	// and legacy handling before the experimental single-pass DOM can be used.
	class SafetyCheckedHtmlRevision extends \Flow\Model\PostRevision {
		public function __construct( private \MediaWiki\Title\Title $title, private string $raw ) {
			$this->content = $raw;
			$this->revId = new class {
				public function getAlphadecimal() {
					return 'test-revision';
				}
			};
		}
		public function getContentRaw() {
			return $this->raw;
		}
		public function getContentFormat() {
			return 'html';
		}
		public function getCollection() {
			return new class( $this->title ) {
				public function __construct( private \MediaWiki\Title\Title $title ) {
				}
				public function getTitle() {
					return $this->title;
				}
			};
		}
	}
	$urlUtils = new \MediaWiki\Utils\UrlUtils;
	$fullFixer = new \Flow\Parsoid\ContentFixer(
		new \Flow\Parsoid\Fixer\WikiLinkFixer( new \MediaWiki\Cache\LinkBatch ),
		new \Flow\Parsoid\Fixer\BadImageRemover(
			static fn ( string $image, \MediaWiki\Title\Title $title ): bool => $image === 'Blocked'
		),
		new \Flow\Parsoid\Fixer\BaseHrefFixer( '/wiki/$1', $urlUtils ),
		new \Flow\Parsoid\Fixer\ExtLinkFixer( $urlUtils )
	);
	$fixtures = [
		'<body><p>Bonjour <strong>Wikidébats</strong> &amp; Flow.</p></body>',
		'<body base-url="//example.org/wiki/"><p><a href="./Existing" rel="mw:WikiLink">Lien</a></p></body>',
		'<body><figure typeof="mw:File"><img resource="./Blocked" src="./image.png"></figure></body>',
		'<body><p><a href="./External" rel="mw:ExtLink">External</a><a></a><br></p></body>',
		'<p>Legacy body-less content <em>with tags</em> &amp; text</p>',
		'<body><div><span></span><strong>Closing tags</strong><img src="./x"></div></body>',
	];
	foreach ( $fixtures as $fixture ) {
		$fullFixer->setBenchmarkReuseDecodedDom( false );
		$legacyRevision = new SafetyCheckedHtmlRevision( $title, $fixture );
		$legacy = $fullFixer->getContent( $legacyRevision );
		$fullFixer->setBenchmarkReuseDecodedDom( true );
		$optimizedRevision = new SafetyCheckedHtmlRevision( $title, $fixture );
		$optimized = $fullFixer->getContent( $optimizedRevision );
		check( $legacy === $optimized,
			'Source DOM reuse preserves exact serialized HTML for fixture: ' . $fixture );
	}
	check( \Flow\Hooks\HookRunner::$calls === count( $fixtures ) * 2,
		'Both HTML variants execute the original XSS hook exactly once per revision' );

	$denied = new SafetyCheckedHtmlRevision( $title, '<body><script>alert(1)</script></body>' );
	check( $fullFixer->getContent( $denied ) === '',
		'Source DOM experiment respects XSS-hook denial and produces no HTML' );
	check( \Flow\Hooks\HookRunner::$calls === count( $fixtures ) * 2 + 1,
		'XSS check is invoked before attempting to reuse the DOM' );

	$fullFixer->setBenchmarkReuseDecodedDom( false );
	$raw = '<body><p>A cached, unmodified revision</p></body>';
	$cachedRevision = new SafetyCheckedHtmlRevision( $title, $raw );
	$cachedRevision->getContent( 'html' );
	$fullFixer->setBenchmarkReuseDecodedDom( true );
	$cachedOutput = $fullFixer->getContent( $cachedRevision );
	$fullFixer->setBenchmarkReuseDecodedDom( false );
	check( $cachedOutput === $fullFixer->getContent( new SafetyCheckedHtmlRevision( $title, $raw ) ),
		'Cached original HTML safely falls back when no first-pass DOM is available' );

	$fullFixer->setBenchmarkReuseDecodedDom( true );
	check( $fullFixer->getContent( $revision ) === $source,
		'Custom revision getContent override retains the original fallback path' );
	$fullFixer->setBenchmarkReuseDecodedDom( false );


	// A site-level switch must default to off, be overridable for benchmark
	// A/B checks, and recover its configured value when the override is cleared.
	$manifest = json_decode( file_get_contents( __DIR__ . '/../../extension.json' ), true, 512, JSON_THROW_ON_ERROR );
	check( ( $manifest['config']['FlowReuseDecodedDom']['value'] ?? null ) === false,
		'Site-level DOM reuse must default to false' );
	$wiring = file_get_contents( __DIR__ . '/../../includes/ServiceWiring.php' );
	check( str_contains( $wiring, "get( 'FlowReuseDecodedDom' ) === true" ),
		'Service wiring explicitly reads the opt-in setting' );

	$flagHtml = '<body><p>Feature <strong>flag</strong> &amp; fallback.</p></body>';
	$flagEvents = [];
	$fullFixer->setBenchmarkTimer(
		static function ( string $phase, float $milliseconds ) use ( &$flagEvents ): void {
			$flagEvents[] = $phase;
		}
	);
	$fullFixer->setBenchmarkReuseDecodedDom( null );
	$fullFixer->setReuseDecodedDom( false );
	$flagBaseline = $fullFixer->getContent( new SafetyCheckedHtmlRevision( $title, $flagHtml ) );
	check( in_array( 'format_detail_content_dom_parse', $flagEvents, true ),
		'Default-off mode retains the original second DOM parse' );

	$flagEvents = [];
	$fullFixer->setReuseDecodedDom( true );
	$flagOptimized = $fullFixer->getContent( new SafetyCheckedHtmlRevision( $title, $flagHtml ) );
	check( $flagOptimized === $flagBaseline, 'Configured DOM reuse preserves exact HTML' );
	check( in_array( 'format_detail_content_reused_dom', $flagEvents, true ) &&
		!in_array( 'format_detail_content_dom_parse', $flagEvents, true ),
		'Opt-in mode reuses the prepared DOM without reparsing' );

	$flagEvents = [];
	$fullFixer->setBenchmarkReuseDecodedDom( false );
	$flagForcedLegacy = $fullFixer->getContent( new SafetyCheckedHtmlRevision( $title, $flagHtml ) );
	check( $flagForcedLegacy === $flagBaseline &&
		in_array( 'format_detail_content_dom_parse', $flagEvents, true ),
		'Maintenance can force the original path even when the site flag is enabled' );

	$flagEvents = [];
	$fullFixer->setBenchmarkReuseDecodedDom( true );
	$flagForcedReuse = $fullFixer->getContent( new SafetyCheckedHtmlRevision( $title, $flagHtml ) );
	check( $flagForcedReuse === $flagBaseline &&
		in_array( 'format_detail_content_reused_dom', $flagEvents, true ),
		'Maintenance can force DOM reuse independent of the site setting' );

	$flagEvents = [];
	$fullFixer->setBenchmarkReuseDecodedDom( null );
	$flagRestored = $fullFixer->getContent( new SafetyCheckedHtmlRevision( $title, $flagHtml ) );
	check( $flagRestored === $flagBaseline &&
		in_array( 'format_detail_content_reused_dom', $flagEvents, true ),
		'Clearing the benchmark override restores the configured optimization' );

	$flagEvents = [];
	$fullFixer->setReuseDecodedDom( false );
	$flagDisabled = $fullFixer->getContent( new SafetyCheckedHtmlRevision( $title, $flagHtml ) );
	check( $flagDisabled === $flagBaseline &&
		in_array( 'format_detail_content_dom_parse', $flagEvents, true ),
		'Disabling the site flag immediately restores the previous parsing path' );
	$fullFixer->setBenchmarkTimer( null );

	echo 'OK: ' . $GLOBALS['flow_test_assertions'] . " HTML/link-batching assertions\n";

}
