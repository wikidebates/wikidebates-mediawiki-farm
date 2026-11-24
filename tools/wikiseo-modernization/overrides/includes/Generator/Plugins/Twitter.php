<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikiSEO\Generator\Plugins;

use InvalidArgumentException;
use MediaWiki\Extension\WikiSEO\Generator\AbstractBaseGenerator;
use MediaWiki\Extension\WikiSEO\Generator\GeneratorInterface;
use MediaWiki\Extension\WikiSEO\MetaValues;
use OutputPage;

/**
 * X/Twitter Card metadata. Intentionally does not extend OpenGraph: the old
 * inheritance emitted Open Graph tags twice without emitting full X cards.
 */
class Twitter extends AbstractBaseGenerator implements GeneratorInterface {
	protected $tags = [
		'title', 'description', 'image', 'image_alt',
		'twitter_card', 'twitter_site', 'twitter_creator',
		'twitter_title', 'twitter_description', 'twitter_image', 'twitter_image_alt',
	];

	public function init( array $metadata, OutputPage $out ): void {
		$this->metadata = $metadata;
		$this->outputPage = $out;
		$this->setFallbackImageIfEnabled();
		$this->preprocessFileMetadata();
	}

	public function addMetadata(): void {
		$card = $this->metadata['twitter_card'] ?? $this->getConfigValue( 'TwitterCardType' );
		if ( !in_array( $card, [ 'summary', 'summary_large_image' ], true ) ) {
			$card = 'summary_large_image';
		}
		$this->outputPage->addMeta( 'twitter:card', $card );

		$title = $this->metadata['twitter_title'] ?? $this->metadata['title'] ?? null;
		if ( $title === null ) {
			$pageTitle = $this->outputPage->getTitle();
			$title = $pageTitle !== null ? $pageTitle->getPrefixedText() : strip_tags( $this->outputPage->getPageTitle() );
		}
		$this->addIfPresent( 'twitter:title', $title );
		$this->addIfPresent( 'twitter:description',
			$this->metadata['twitter_description'] ?? $this->metadata['description'] ?? null );

		$image = $this->metadata['twitter_image'] ?? $this->metadata['image'] ?? null;
		if ( isset( $this->metadata['twitter_image'] ) && is_string( $image ) ) {
			try {
				$image = $this->getFileInfo( $this->getFileObject( $image ) )['url'];
			} catch ( InvalidArgumentException $e ) {
				$image = MetaValues::httpUrl( $image );
			}
		}
		$this->addIfPresent( 'twitter:image', $image );
		$this->addIfPresent( 'twitter:image:alt',
			$this->metadata['twitter_image_alt'] ?? $this->metadata['image_alt'] ?? null );

		// A global site handle takes precedence for compatibility with WikiSEO 2.7.
		$this->addIfPresent( 'twitter:site',
			$this->getConfigValue( 'TwitterSiteHandle' ) ?? $this->metadata['twitter_site'] ?? null );
		$this->addIfPresent( 'twitter:creator', $this->metadata['twitter_creator'] ?? null );
	}

	public function getAllowedParameterNames(): array {
		return $this->tags;
	}

	private function addIfPresent( string $name, $value ): void {
		if ( is_string( $value ) && trim( $value ) !== '' ) {
			$this->outputPage->addMeta( $name, $value );
		}
	}
}
