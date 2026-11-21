<?php

use MediaWiki\Title\Title;

/**
 * Breadth-first category discovery, separately from limited page suggestions.
 * Batches bound query size; a wide tree raises an explicit error rather than
 * silently dropping subcategories behind the page-result LIMIT.
 */
class PFCategoryAutocomplete {

	private const BATCH_SIZE = 50;
	private $db;
	private bool $hasCategoryTitle;
	private bool $displayTitles;
	private int $categoryLimit;
	private bool $wordFallback;

	public function __construct( $db, bool $displayTitles, int $categoryLimit = 2000, bool $wordFallback = true ) {
		$this->db = $db;
		$this->displayTitles = $displayTitles;
		$this->categoryLimit = max( 1, min( 10000, $categoryLimit ) );
		$this->wordFallback = $wordFallback;
		$this->hasCategoryTitle = $db->fieldExists( 'categorylinks', 'cl_to' );
	}

	private function categoryQuery( array $categories ): array {
		$tables = [ 'page', 'categorylinks' ];
		$joins = [ 'categorylinks' => [ 'JOIN', 'cl_from = page_id' ] ];
		if ( $this->hasCategoryTitle ) {
			$conditions = [ 'cl_to' => $categories ];
		} else {
			$tables[] = 'linktarget';
			$joins['linktarget'] = [ 'JOIN', 'cl_target_id = lt_id' ];
			$conditions = [ 'lt_namespace' => NS_CATEGORY, 'lt_title' => $categories ];
		}
		return [ $tables, $joins, $conditions ];
	}

	private function discover( string $root, int $levels ): array {
		$seen = [ $root => true ];
		$frontier = [ $root ];
		for ( $level = 1; $level < $levels && $frontier; $level++ ) {
			$next = [];
			foreach ( array_chunk( $frontier, self::BATCH_SIZE ) as $batch ) {
				[ $tables, $joins, $conditions ] = $this->categoryQuery( $batch );
				$conditions['page_namespace'] = NS_CATEGORY;
				$conditions['cl_type'] = 'subcat';
				$rows = $this->db->select( $tables, [ 'page_title' ], $conditions, __METHOD__, [
					'DISTINCT', 'ORDER BY' => 'page_title', 'LIMIT' => $this->categoryLimit + 1,
				], $joins );
				foreach ( $rows as $row ) {
					$name = $row->page_title;
					if ( isset( $seen[$name] ) ) {
						continue;
					}
					if ( count( $seen ) >= $this->categoryLimit ) {
						throw new LengthException( wfMessage( 'pf-category-search-limit', $this->categoryLimit )->text() );
					}
					$seen[$name] = true;
					$next[] = $name;
				}
				$rows->free();
			}
			$frontier = $next;
		}
		return array_keys( $seen );
	}

	private function lowerColumn( string $column ): string {
		return $this->db->getType() === 'mysql' ? "LOWER(CONVERT($column USING utf8mb4))" : "LOWER($column)";
	}

	private function match( string $term, bool $words ): string {
		$titleTerm = str_replace( ' ', '_', $term );
		$like = $words ? $this->db->buildLike( $this->db->anyString(), $titleTerm, $this->db->anyString() ) :
			$this->db->buildLike( $titleTerm, $this->db->anyString() );
		$condition = $this->lowerColumn( 'page_title' ) . $like;
		if ( $this->displayTitles ) {
			$like = $words ? $this->db->buildLike( $this->db->anyString(), $term, $this->db->anyString() ) :
				$this->db->buildLike( $term, $this->db->anyString() );
			$condition .= ' OR ' . $this->lowerColumn( 'pp_displaytitle.pp_value' ) . $like;
		}
		return '(' . $condition . ')';
	}

	private function exact( string $term ): string {
		$condition = $this->lowerColumn( 'page_title' ) . ' = ' . $this->db->addQuotes( str_replace( ' ', '_', $term ) );
		if ( $this->displayTitles ) {
			$condition .= ' OR ' . $this->lowerColumn( 'pp_displaytitle.pp_value' ) . ' = ' . $this->db->addQuotes( $term );
		}
		return '(' . $condition . ')';
	}

	private function pages( array $categories, ?string $query, bool $words, int $limit ): array {
		$pages = [];
		$sortKeys = [];
		$term = PFAutocompleteSearch::normalize( $query ?? '' );
		foreach ( array_chunk( $categories, self::BATCH_SIZE ) as $batch ) {
			[ $tables, $joins, $conditions ] = $this->categoryQuery( $batch );
			$conditions[] = 'page_namespace != ' . NS_CATEGORY;
			$columns = [ 'page_title', 'page_namespace' ];
			if ( $this->displayTitles ) {
				foreach ( [ 'displaytitle', 'defaultsort' ] as $prop ) {
					$alias = 'pp_' . $prop;
					$tables[$alias] = 'page_props';
					$columns[$alias . '_value'] = "$alias.pp_value";
					$joins[$alias] = [ 'LEFT JOIN', [ "$alias.pp_page = page_id", "$alias.pp_propname = " . $this->db->addQuotes( $prop ) ] ];
				}
			}
			$order = 'page_title, page_namespace';
			if ( $term !== '' ) {
				foreach ( $words ? PFAutocompleteSearch::words( $term ) : [ $term ] as $word ) {
					$conditions[] = $this->match( $word, $words );
				}
				$columns['pf_search_rank'] = 'CASE WHEN ' . $this->exact( $term ) . ' THEN 0 ELSE 1 END';
				$order = 'pf_search_rank, ' . $order;
			}
			$rows = $this->db->select( $tables, $columns, $conditions, __METHOD__, [
				'DISTINCT', 'ORDER BY' => $order, 'LIMIT' => $limit,
			], $joins );
			foreach ( $rows as $row ) {
				$title = Title::makeTitleSafe( (int)$row->page_namespace, $row->page_title );
				if ( !$title ) {
					continue;
				}
				$key = $title->getPrefixedText();
				$display = $row->pp_displaytitle_value ?? '';
				$pages[$key] = trim( str_replace( '&#160;', '', strip_tags( $display ) ) ) !== '' ? htmlspecialchars_decode( $display ) : $key;
				$sortKeys[$key] = $row->pp_defaultsort_value ?? $key;
			}
			$rows->free();
		}
		uksort( $pages, static function ( $a, $b ) use ( $sortKeys ): int {
			return strcmp( (string)$sortKeys[$a], (string)$sortKeys[$b] ) ?: strcmp( (string)$a, (string)$b );
		} );
		return $pages;
	}

	public function getPages( string $root, int $levels, ?string $query = null ): array {
		if ( $levels === 0 ) {
			return [ $root ];
		}
		if ( $levels < 0 ) {
			return [];
		}
		$categories = $this->discover( str_replace( ' ', '_', $root ), $levels );
		$limit = max( 1, PFValuesUtils::getMaxValuesToRetrieve( $query ) );
		$pages = $this->pages( $categories, $query, false, $limit );
		// Fallback is restricted to this already-bounded category family, not a
		// whole-wiki scan. Prefix/exact matches remain ahead of word matches.
		if ( $this->wordFallback && count( $pages ) < min( 10, $limit ) &&
			mb_strlen( trim( $query ?? '' ), 'UTF-8' ) >= 3 && mb_strlen( $query, 'UTF-8' ) <= 128 ) {
			$pages += $this->pages( $categories, $query, true, $limit );
		}
		if ( $query !== null && $query !== '' ) {
			$pages = PFAutocompleteSearch::rank( $pages, $query, true );
		}
		return array_slice( $pages, 0, $limit, true );
	}
}
