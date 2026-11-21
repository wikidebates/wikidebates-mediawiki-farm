<?php

/** Unicode-aware ranking shared by API responses and bounded category searches. */
class PFAutocompleteSearch {

	public static function normalize( string $value ): string {
		$value = str_replace( '_', ' ', $value );
		if ( class_exists( Normalizer::class ) ) {
			$value = Normalizer::normalize( $value, Normalizer::FORM_C ) ?: $value;
		}
		return mb_strtolower( trim( $value ), 'UTF-8' );
	}

	/** @return string[] */
	public static function words( string $query ): array {
		$words = preg_split( '/[\s_]+/u', self::normalize( $query ), -1, PREG_SPLIT_NO_EMPTY );
		return array_slice( array_values( array_unique( $words ?: [] ) ), 0, 6 );
	}

	private static function score( string $value, string $query, array $words ): int {
		$value = self::normalize( $value );
		if ( $value === $query ) {
			return 0;
		}
		if ( str_starts_with( $value, $query ) ) {
			return 1;
		}
		foreach ( $words as $word ) {
			if ( !preg_match( '/(?:^|[^\p{L}\p{N}])' . preg_quote( $word, '/' ) . '/u', $value ) ) {
				return 3;
			}
		}
		return $words ? 2 : 3;
	}

	/** Preserve page keys (including numeric titles) and stable ties. */
	public static function rank( array $values, string $query, bool $pageKeys = false ): array {
		$query = self::normalize( $query );
		if ( $query === '' ) {
			return $values;
		}
		$words = self::words( $query );
		$items = [];
		foreach ( $values as $key => $value ) {
			$label = html_entity_decode( strip_tags( (string)$value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$score = self::score( $label, $query, $words );
			if ( $pageKeys ) {
				$score = min( $score, self::score( (string)$key, $query, $words ) );
			}
			$items[] = [ $key, $value, $score, mb_strlen( $label, 'UTF-8' ), count( $items ) ];
		}
		usort( $items, static function ( array $a, array $b ): int {
			return [ $a[2], $a[3], $a[4] ] <=> [ $b[2], $b[3], $b[4] ];
		} );
		$result = [];
		foreach ( $items as $item ) {
			$result[$item[0]] = $item[1];
		}
		return $result;
	}
}
