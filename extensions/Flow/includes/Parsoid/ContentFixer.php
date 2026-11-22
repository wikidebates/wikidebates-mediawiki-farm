<?php

namespace Flow\Parsoid;

use DOMDocument;
use DOMXPath;
use Flow\Conversion\Utils;
use Flow\Exception\FlowException;
use Flow\Model\AbstractRevision;
use Flow\Parsoid\Fixer\WikiLinkFixer;
use MediaWiki\Title\Title;

class ContentFixer {
	/**
	 * @var Fixer[]
	 */
	protected $contentFixers = [];

	/** @var callable|null Profiling observer, set only by the read-only benchmark. */
	private $benchmarkTimer;

	/** @var bool Site-level renderer setting (the extension defaults to enabled). */
	private bool $reuseDecodedDom = false;

	/** @var bool|null Maintenance-only override; null follows the site-level setting. */
	private ?bool $benchmarkReuseDecodedDom = null;

	public function setReuseDecodedDom( bool $reuse ): void {
		$this->reuseDecodedDom = $reuse;
	}

	public function setBenchmarkReuseDecodedDom( ?bool $reuse ): void {
		$this->benchmarkReuseDecodedDom = $reuse;
	}

	public function setBenchmarkTimer( ?callable $observer ): void {
		$this->benchmarkTimer = $observer;
		// Forward profiling to WikiLinkFixer only, without changing the
		// normal application path or other HTML fixers.
		foreach ( $this->contentFixers as $fixer ) {
			if ( $fixer instanceof WikiLinkFixer ) {
				$fixer->setBenchmarkTimer( $observer );
			}
		}
	}

	private function recordBenchmarkStep( string $phase, int $started ): void {
		( $this->benchmarkTimer )(
			'format_detail_content_' . $phase,
			round( ( hrtime( true ) - $started ) / 1000000, 3 )
		);
	}

	/**
	 * Accepts multiple content fixers.
	 *
	 * @param Fixer $contentFixer,...
	 * @throws FlowException When provided arguments are not an instance of Fixer
	 */
	public function __construct( Fixer $contentFixer /* [, Fixer $contentFixer2 [, ...]] */ ) {
		$this->contentFixers = func_get_args();

		// validate data
		foreach ( $this->contentFixers as $contentFixer ) {
			if ( !$contentFixer instanceof Fixer ) {
				throw new FlowException( 'Invalid content fixer', 'default' );
			}
		}
	}

	/**
	 * @param AbstractRevision $revision
	 * @return string
	 */
	public function getContent( AbstractRevision $revision ) {
		if ( $this->benchmarkReuseDecodedDom ?? $this->reuseDecodedDom ) {
			// First request the same HTML through AbstractRevision::getContent,
			// including the existing XSS hook, legacy conversions and stubs.
			// The maintenance override and configured path share identical logic.
			$dom = null;
			$started = $this->benchmarkTimer !== null ? hrtime( true ) : null;
			$html = $revision->getHtmlWithDecodedDom( $dom );
			if ( $started !== null ) {
				$this->recordBenchmarkStep( 'source_html', $started );
			}
			$title = $revision->getCollection()->getTitle();
			if ( $dom instanceof DOMDocument ) {
				if ( $this->benchmarkTimer !== null ) {
					( $this->benchmarkTimer )( 'format_detail_content_reused_dom', 0.0 );
				}
				return $this->renderFromPreparedDom( $dom, $title );
			}
			// A cached, converted, custom or inaccessible revision does not
			// expose a decoded source DOM and retains the original path.
			return $this->apply( $html, $title );
		}

		if ( $this->benchmarkTimer === null ) {
			return $this->apply(
				$revision->getContent( 'html' ),
				$revision->getCollection()->getTitle()
			);
		}

		$started = hrtime( true );
		$html = $revision->getContent( 'html' );
		$this->recordBenchmarkStep( 'source_html', $started );
		return $this->apply( $html, $revision->getCollection()->getTitle() );
	}

	/**
	 * Applies all contained content fixers to the provided HTML content.
	 * The resulting content is then suitable for display to the end user.
	 *
	 * @param string $content Html
	 * @param Title $title
	 * @return string Html
	 */
	public function apply( $content, Title $title ) {
		// Plain text without HTML syntax or entities cannot contain any node
		// matched by the fixers, and needs no DOM parsing or reserialization.
		if ( strpbrk( $content, '<>&' ) === false ) {
			return $content;
		}
		$started = $this->benchmarkTimer !== null ? hrtime( true ) : null;
		$dom = self::createDOM( $content );
		if ( $started !== null ) {
			$this->recordBenchmarkStep( 'dom_parse', $started );
		}

		return $this->renderFromPreparedDom( $dom, $title );
	}

	/**
	 * Complete the original fixers and XML serialization on a DOM prepared by
	 * either path. The maintenance experiment merely skips the second parse.
	 * The result must be compared byte-for-byte before enabling this in traffic.
	 */
	private function renderFromPreparedDom( DOMDocument $dom, Title $title ): string {
		$started = $this->benchmarkTimer !== null ? hrtime( true ) : null;
		$this->applyToDom( $dom, $title );
		if ( $started !== null ) {
			$this->recordBenchmarkStep( 'dom_fixers', $started );
		}

		$started = $this->benchmarkTimer !== null ? hrtime( true ) : null;
		$html = Utils::getInnerHtml( $dom->getElementsByTagName( 'body' )->item( 0 ) );
		if ( $started !== null ) {
			$this->recordBenchmarkStep( 'dom_serialize', $started );
		}
		return $html;
	}

	/**
	 * Applies all content fixers to the provided DOMDocument.
	 * Like apply(), but modifies a DOM in place rather than parsing and reserializing a string.
	 *
	 * @param DOMDocument $dom
	 * @param Title $title
	 */
	public function applyToDom( DOMDocument $dom, Title $title ) {
		$xpath = new DOMXPath( $dom );
		$profile = $this->benchmarkTimer !== null;
		foreach ( $this->contentFixers as $i => $contentFixer ) {
			if ( $profile ) {
				// These names are used only by the read-only maintenance profiler.
				$className = get_class( $contentFixer );
				$separator = strrpos( $className, '\\' );
				$name = strtolower( substr( $className, $separator === false ? 0 : $separator + 1 ) );
				$phase = 'fixer_' . $name . '_';
				$started = hrtime( true );
			}
			$found = $xpath->query( $contentFixer->getXPath() );
			if ( $profile ) {
				$this->recordBenchmarkStep( $phase . 'xpath', $started );
			}
			if ( !$found ) {
				wfDebugLog( 'Flow', __METHOD__ . ': Invalid XPath from ' . get_class( $contentFixer ) . ' of: ' .
					$contentFixer->getXPath() );
				unset( $this->contentFixers[$i] );
				continue;
			}

			if ( $contentFixer instanceof WikiLinkFixer ) {
				if ( $profile ) {
					$started = hrtime( true );
				}
				$contentFixer->preload( $found, $title );
				if ( $profile ) {
					$this->recordBenchmarkStep( $phase . 'preload', $started );
				}
			}
			if ( $profile ) {
				$started = hrtime( true );
			}
			foreach ( $found as $node ) {
				$contentFixer->apply( $node, $title );
			}
			if ( $profile ) {
				$this->recordBenchmarkStep( $phase . 'apply', $started );
			}
		}
	}

	/**
	 * Creates a DOM with extra considerations for BC with
	 * previous parsoid content
	 *
	 * @param string $content HTML from parsoid
	 * @return DOMDocument
	 * @throws \Flow\Exception\WikitextException
	 */
	public static function createDOM( $content ) {
		 // The body tag is required otherwise <meta> tags at the top are
		 // magic'd into <head> rather than kept with the content.
		if (
			!str_starts_with( $content, '<body' )
			&& !str_starts_with( $content, '<!DOCTYPE' )
			// We might have set the html/head/base href in AbstractRevision#getContent, so
			// make one more check before body wrapping the content.
			&& !str_starts_with( $content, '<html><head>' )
		) {
			// BC: content currently comes from parsoid and is stored
			// wrapped in <body> tags, but prior to I0d9659f we were
			// storing only the contents and not the body tag itself.
			$content = "<body>$content</body>";
		}
		return Utils::createDOM( $content );
	}
}
