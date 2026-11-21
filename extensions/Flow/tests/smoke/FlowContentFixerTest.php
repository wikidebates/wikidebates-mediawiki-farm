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
	echo 'OK: ' . $GLOBALS['flow_test_assertions'] . " HTML/link-batching assertions\n";
}
