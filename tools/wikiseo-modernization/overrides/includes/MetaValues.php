<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikiSEO;

/**
 * Value normalization shared by the Open Graph, X card and JSON-LD generators.
 * This class has no MediaWiki dependencies, so it can be tested independently.
 */
final class MetaValues {
	private const SCHEMA_TYPES = [
		'WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'ProfilePage',
		'ItemPage', 'Article', 'NewsArticle', 'BlogPosting', 'ScholarlyArticle',
	];

	public static function openGraphType( array $metadata ): string {
		$type = strtolower( trim( (string)( $metadata['og_type'] ?? $metadata['type'] ?? '' ) ) );
		if ( in_array( $type, [ 'article', 'newsarticle', 'blogposting', 'scholarlyarticle' ], true ) ) {
			return 'article';
		}
		if ( in_array( $type, [ 'website', 'profile', 'book', 'music.song',
			'video.movie', 'video.episode', 'video.tv_show', 'video.other' ], true ) ) {
			return $type;
		}
		return 'website';
	}

	public static function schemaType( array $metadata ): string {
		$source = trim( (string)( $metadata['schema_type'] ?? $metadata['type'] ?? '' ) );
		if ( $source === '' || strcasecmp( $source, 'website' ) === 0 ) {
			return 'WebPage';
		}
		foreach ( self::SCHEMA_TYPES as $allowed ) {
			if ( strcasecmp( $source, $allowed ) === 0 ) {
				return $allowed;
			}
		}
		return 'WebPage';
	}

	public static function isArticleSchemaType( string $type ): bool {
		return in_array( $type, [ 'Article', 'NewsArticle', 'BlogPosting', 'ScholarlyArticle' ], true );
	}

	public static function date( $value ): ?string {
		if ( !is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( !preg_match( '/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2}))?$/D', $value ) ) {
			return null;
		}
		try {
			$parsed = new \DateTimeImmutable( $value );
		} catch ( \Exception $e ) {
			return null;
		}
		$errors = \DateTimeImmutable::getLastErrors();
		if ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) {
			return null;
		}
		return $value;
	}

	public static function httpUrl( $value ): ?string {
		if ( !is_string( $value ) || !filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return null;
		}
		$scheme = strtolower( (string)parse_url( $value, PHP_URL_SCHEME ) );
		return in_array( $scheme, [ 'http', 'https' ], true ) ? $value : null;
	}

	public static function secureUrl( $value ): ?string {
		$url = self::httpUrl( $value );
		return $url !== null && strtolower( (string)parse_url( $url, PHP_URL_SCHEME ) ) === 'https'
			? $url : null;
	}

	public static function jsonLd( array $metadata ): ?string {
		$result = json_encode( $metadata,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT |
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		return $result === false ? null : $result;
	}
}
