<?php

declare( strict_types=1 );

/** Deterministic transformations of the reviewed editorial migration manifest. */
final class EditorialSeoMigration {
	public const TEMPLATES = [
		'fr' => [ 'Modèle:Métabalises', 118860 ],
		'en' => [ 'Template:Meta tags', 1400 ],
		'es' => [ 'Plantilla:Metaetiquetas', 257 ],
	];

	public const COUNTS = [ 'fr' => 34, 'en' => 17, 'es' => 7, 'de' => 1, 'it' => 1, 'pt' => 1 ];

	public static function load( string $lang, string $dir ): array {
		if ( !isset( self::COUNTS[$lang] ) ) {
			throw new RuntimeException( 'Unexpected wiki language' );
		}
		$manifest = json_decode( file_get_contents( $dir . '/pages.json' ), true, 512, JSON_THROW_ON_ERROR );
		$entries = [];
		if ( isset( self::TEMPLATES[$lang] ) ) {
			[ $title, $revid ] = self::TEMPLATES[$lang];
			$entries[] = [
				'wiki' => $lang, 'title' => $title, 'revid' => $revid,
				'template' => file_get_contents( $dir . '/templates/' . $lang . '.wikitext' ),
			];
		}
		$seen = [];
		foreach ( $manifest['pages'] as $entry ) {
			if ( $entry['wiki'] !== $lang ) {
				continue;
			}
			if ( isset( $seen[$entry['title']] ) || !is_int( $entry['revid'] ) || $entry['revid'] <= 0
				|| !$entry['operations'] || isset( $entry['template'] ) ) {
				throw new RuntimeException( 'Invalid manifest entry: ' . $entry['title'] );
			}
			$seen[$entry['title']] = true;
			$entries[] = $entry;
		}
		if ( count( $seen ) !== self::COUNTS[$lang] ) {
			throw new RuntimeException( 'Unexpected number of manifest pages' );
		}
		return $entries;
	}

	private static function seoCall( string $source ): bool {
		// Conservative: examples, comments and subst calls also require review.
		return preg_match( '~\{\{\s*(?:(?:safe)?subst\s*:\s*)?(?:(?:template|modèle|plantilla)\s*:\s*)?(?:#seo\s*:|métabalises\s*(?:\||\}\})|meta[ _]+tags\s*(?:\||\}\})|metaetiquetas\s*(?:\||\}\})|seo[ _]+(?:projet|project)\s*(?:\||\}\}))|<seo(?:\s|/?>)~iu', $source ) !== 0;
	}

	public static function transform( string $source, array $entry ): string {
		if ( isset( $entry['template'] ) ) {
			if ( !is_string( $entry['template'] ) || $entry['template'] === '' ) {
				throw new RuntimeException( 'Empty candidate template' );
			}
			return $entry['template'];
		}
		foreach ( $entry['operations'] as $index => $op ) {
			$after = $op['after'];
			if ( !is_string( $after ) || $after === '' ) {
				throw new RuntimeException( 'Empty replacement' );
			}
			if ( $op['kind'] === 'append' && $index === 0 ) {
				if ( self::seoCall( $source ) || !self::seoCall( $after ) ) {
					throw new RuntimeException( 'Existing or invalid SEO call: ' . $entry['title'] );
				}
				// Preserve all original bytes, including categories and interwiki links.
				$source .= ( str_ends_with( $source, "\n" ) ? "\n" : "\n\n" ) . $after . "\n";
			} elseif ( $op['kind'] === 'replace' ) {
				$before = $op['before'];
				if ( !is_string( $before ) || $before === '' || substr_count( $source, $before ) !== 1
					|| $before === $after ) {
					throw new RuntimeException( 'Missing or ambiguous replacement: ' . $entry['title'] );
				}
				if ( $index === 0 && ( !self::seoCall( $before ) || !self::seoCall( $after )
					|| self::seoCall( str_replace( $before, '', $source ) ) ) ) {
					throw new RuntimeException( 'Unexpected additional SEO call: ' . $entry['title'] );
				}
				$source = str_replace( $before, $after, $source );
			} else {
				throw new RuntimeException( 'Unsupported operation: ' . $entry['title'] );
			}
		}
		return $source;
	}

	/** Expected direct/indirect callers before migration, from the reviewed inventory. */
	public static function expectedCallers( string $lang, array $entries ): array {
		$result = [];
		if ( !isset( self::TEMPLATES[$lang] ) ) {
			return $result;
		}
		$name = explode( ':', self::TEMPLATES[$lang][0], 2 )[1];
		foreach ( $entries as $entry ) {
			$before = $entry['operations'][0]['before'] ?? '';
			if ( preg_match( '~^\{\{' . preg_quote( $name, '~' ) . '\s*\|~u', $before ) === 1 ) {
				$result[] = $entry['title'];
			}
		}
		sort( $result, SORT_STRING );
		return $result;
	}
}
