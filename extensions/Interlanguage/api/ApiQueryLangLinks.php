<?php
/**
 * Copyright © 2006 Yuri Astrakhan "<Firstname><Lastname>@gmail.com"
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

use MediaWiki\MediaWikiServices;

/**
 * A query module to list all langlinks (links to corresponding foreign language pages).
 *
 * @ingroup API
 */
class ApiQueryLangLinks extends ApiQueryBase {

	protected $wikirougeForeignDb;
	private array $localPageIdsByCentralId = [];
	private array $inferredPageLinks = [];

	public function __construct( ApiQuery $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'll' );
	}


	/**
	 * Translate central-wiki page IDs to the local ApiPageSet page IDs.
	 * Query the central table once per phase; never send a central page ID
	 * to addPageSubItem(), which addresses the local wiki's result pages.
	 *
	 * @param array<int,Title> $goodTitles
	 * @return int[] Central page IDs used solely for querying/continuation.
	 */
	private function centralPageIdsForTitles(
		string $table, string $prefix, array $goodTitles
	): array {
		global $wgLanguageCode;

		$localIdsByCanonicalTitle = [];
		foreach ( $goodTitles as $localPageId => $title ) {
			$canonical = InterlanguageExtension::getCanonicalTitleText( $title );
			$localIdsByCanonicalTitle[$canonical][] = (int)$localPageId;
		}
		if ( $localIdsByCanonicalTitle === [] ) {
			return [];
		}

		$from = "{$prefix}_from";
		$titleField = "{$prefix}_title";
		$rows = $this->getDB()->select(
			$table,
			[ $from, $titleField ],
			[
				$titleField => array_keys( $localIdsByCanonicalTitle ),
				"{$prefix}_lang" => $wgLanguageCode,
			],
			__METHOD__
		);
		$centralIds = [];
		foreach ( $rows as $row ) {
			$centralId = (int)$row->$from;
			foreach ( $localIdsByCanonicalTitle[$row->$titleField] ?? [] as $localId ) {
				$this->localPageIdsByCentralId[$centralId][$localId] = true;
			}
			$centralIds[$centralId] = $centralId;
		}
		return array_values( $centralIds );
	}

	/**
	 * @param array $pageId
	 * @param array $params
	 * @param array $prop
	 * @param Title[] $goodTitles
	 */
	private function addResultByPageId( $pageId, $params, $prop, $goodTitles ) {
		global $wgInterlanguageExtensionInterwiki;

		if ( !$pageId || !$wgInterlanguageExtensionInterwiki ) {
			return;
		}

		$llLang = substr( $wgInterlanguageExtensionInterwiki, 0, -1 );

		$db = $this->getDB();
		$res = $db->select(
			'page',
			[ 'page_id', 'page_namespace', 'page_title' ],
			[ 'page_id' => $pageId ],
			__METHOD__
		);
		foreach ( $res as $row ) {
			$title = Title::makeTitle( $row->page_namespace, $row->page_title );
			$obj = [
				'll_lang' => $llLang,
				'll_title' => InterlanguageExtension::getCanonicalTitleText( $title ),
				'll_from' => $row->page_id,
			];
			foreach ( array_keys( $this->localPageIdsByCentralId[(int)$row->page_id] ?? [] ) as $localId ) {
				if ( isset( $this->inferredPageLinks[$localId] ) ) {
					continue;
				}
				$this->inferredPageLinks[$localId] = true;
				$this->addResult( (object)$obj, $params, $prop, [ $localId ] );
			}
		}
	}

	private function addResult( $row, $params, $prop, ?array $pageIds = null ) {
		$entry = [ 'lang' => $row->ll_lang ];
		if ( isset( $prop['url'] ) ) {
			$title = Title::newFromText( "{$row->ll_lang}:{$row->ll_title}" );
			if ( $title ) {
				$entry['url'] = wfExpandUrl( $title->getFullURL(), PROTO_CURRENT );
			}
		}
		if ( isset( $prop['langname'] ) ) {
			$entry['langname'] = Language::fetchLanguageName( $row->ll_lang, $params['inlanguagecode'] );
		}
		if ( isset( $prop['autonym'] ) ) {
			$entry['autonym'] = Language::fetchLanguageName( $row->ll_lang );
		}
		ApiResult::setContentValue( $entry, 'title', $row->ll_title );
		$localIds = $pageIds ?? ( self::getInterlanguageExtensionDB()
			? array_keys( $this->localPageIdsByCentralId[(int)$row->ll_from] ?? [] )
			: [ (int)$row->ll_from ] );
		foreach ( $localIds as $localId ) {
			if ( !$this->addPageSubItem( $localId, $entry ) ) {
				return false;
			}
		}
		return true;
	}

	public function execute() {
		global $wgLanguageCode;

		if ( $this->getPageSet()->getGoodTitleCount() == 0 ) {
			return;
		}

		$params = $this->extractRequestParams();
		$prop = array_flip( (array)$params['prop'] );

		if ( isset( $params['title'] ) && !isset( $params['lang'] ) ) {
			$this->dieWithError(
				[
					'apierror-invalidparammix-mustusewith',
					$this->encodeParamName( 'title' ),
					$this->encodeParamName( 'lang' ),
				],
				'invalidparammix'
			);
		}

		// Handle deprecated param
		$this->requireMaxOneParameter( $params, 'url', 'prop' );
		if ( $params['url'] ) {
			$prop = [ 'url' => 1 ];
		}

		// Keep the legacy two-field continuation for native langlinks, while
		// marking cursors that have already advanced to interlanguage_links.
		if ( $params['continue'] !== null && str_starts_with( $params['continue'], 'w|' ) ) {
			$cont = explode( '|', $params['continue'], 3 );
			$this->dieContinueUsageIf(
				count( $cont ) !== 3 || !ctype_digit( $cont[1] ) ||
				(int)$cont[1] < 1 || $cont[2] === '' || str_contains( $cont[2], '|' )
			);
			$this->execute_wikirouge( $params, $prop, $params['limit'], $cont );
			return;
		}

		$this->addFields( [
			'll_from',
			'll_lang',
			'll_title'
		] );

		$this->addTables( 'langlinks' );

		$goodTitles = $this->getPageSet()->getGoodTitles();
		if ( self::getInterlanguageExtensionDB() ) {
			$llFrom = $this->centralPageIdsForTitles( 'langlinks', 'll', $goodTitles );
			if ( $params['continue'] === null ) {
				$this->addResultByPageId( $llFrom, $params, $prop, $goodTitles );
			}
		} else {
			$llFrom = array_keys( $goodTitles );
		}
		if ( !$llFrom ) {
			$this->execute_wikirouge( $params, $prop, $params['limit'] );
			return;
		}
		$this->addWhereFld( 'll_from', $llFrom );

		if ( !is_null( $params['continue'] ) ) {
			$cont = explode( '|', $params['continue'] );
			$this->dieContinueUsageIf( count( $cont ) != 2 );
			$op = $params['dir'] == 'descending' ? '<' : '>';
			$cursorFrom = (int)$cont[0];
			$lllang = $this->getDB()->addQuotes( $cont[1] );
			$this->addWhere(
				"ll_from $op $cursorFrom OR " .
				"(ll_from = $cursorFrom AND " .
				"ll_lang $op= $lllang)"
			);
		}

		// FIXME: (follow-up) To allow extensions to add to the language links, we need
		//       to load them all, add the extra links, then apply paging.
		//       Should not be terrible, it's not going to be more than a few hundred links.

		// Note that, since (ll_from, ll_lang) is a unique key, we don't need
		// to sort by ll_title to ensure deterministic ordering.
		$sort = ( $params['dir'] == 'descending' ? ' DESC' : '' );
		if ( isset( $params['lang'] ) ) {
			$this->addWhereFld( 'll_lang', $params['lang'] );
			if ( isset( $params['title'] ) ) {
				$this->addWhereFld( 'll_title', $params['title'] );
			}
			$this->addOption( 'ORDER BY', 'll_from' . $sort );
		} else {
			// Don't order by ll_from if it's constant in the WHERE clause
			if ( count( $llFrom ) === 1 ) {
				$this->addOption( 'ORDER BY', 'll_lang' . $sort );
			} else {
				$this->addOption( 'ORDER BY', [
					'll_from' . $sort,
					'll_lang' . $sort
				] );
			}
		}

		$this->addOption( 'LIMIT', $params['limit'] + 1 );

		$db = $this->getDB();
		$extraQuery = [
			'where' =>  'll_lang != ' . $db->addQuotes( $wgLanguageCode ),
		];
		$res = $this->select( __METHOD__, $extraQuery );

		$count = 0;
		$fit = true;
		foreach ( $res as $row ) {
			if ( ++$count > $params['limit'] ) {
				// We've reached the one extra which shows that
				// there are additional pages to be had. Stop here...
				$this->setContinueEnumParameter( 'continue', "{$row->ll_from}|{$row->ll_lang}" );
				break;
			}
			$fit = $this->addResult( $row, $params, $prop );
			if ( !$fit ) {
				$this->setContinueEnumParameter( 'continue', "{$row->ll_from}|{$row->ll_lang}" );
				break;
			}
		}

		if ( $count <= $params['limit'] && $fit ) {
			$this->execute_wikirouge( $params, $prop, $params['limit'] - $count );
		}
	}

	public function execute_wikirouge( $params, $prop, $remaining = null, $continuation = null ) {
		global $wgLanguageCode;

		$remaining ??= $params['limit'];
		$this->resetQueryParams();
		$this->addFields( [ 'ill_from', 'ill_lang', 'ill_title' ] );
		$this->addTables( 'interlanguage_links' );

		$goodTitles = $this->getPageSet()->getGoodTitles();
		if ( self::getInterlanguageExtensionDB() ) {
			// The local title may be represented in either central table.
			// A native link can identify a central page that has additional
			// translations but no local-language interlanguage_links row.
			$llFrom = array_values( array_unique( array_merge(
				$this->centralPageIdsForTitles( 'langlinks', 'll', $goodTitles ),
				$this->centralPageIdsForTitles( 'interlanguage_links', 'ill', $goodTitles )
			) ) );
			// Inferred links belong only to the first response, not continuations.
			if ( $params['continue'] === null && $continuation === null ) {
				$this->addResultByPageId( $llFrom, $params, $prop, $goodTitles );
			}
		} else {
			$llFrom = array_keys( $goodTitles );
		}
		if ( !$llFrom ) {
			return;
		}
		$this->addWhereFld( 'ill_from', $llFrom );

		if ( $continuation !== null ) {
			$op = $params['dir'] === 'descending' ? '<' : '>';
			$from = (int)$continuation[1];
			$lang = $this->getDB()->addQuotes( $continuation[2] );
			$this->addWhere(
				"(ill_from $op $from OR (ill_from = $from AND ill_lang $op= $lang))"
			);
		}

		$sort = $params['dir'] === 'descending' ? ' DESC' : '';
		if ( isset( $params['lang'] ) ) {
			$this->addWhereFld( 'ill_lang', $params['lang'] );
			if ( isset( $params['title'] ) ) {
				$this->addWhereFld( 'ill_title', $params['title'] );
			}
			$this->addOption( 'ORDER BY', 'ill_from' . $sort );
		} elseif ( count( $llFrom ) === 1 ) {
			$this->addOption( 'ORDER BY', 'ill_lang' . $sort );
		} else {
			$this->addOption( 'ORDER BY', [ 'ill_from' . $sort, 'ill_lang' . $sort ] );
		}

		$this->addOption( 'LIMIT', $remaining + 1 );

		$db = $this->getDB();
		$nativeTable = $db->tableName( 'langlinks' );
		$extraQuery = [
			'where' => [
				'ill_lang != ' . $db->addQuotes( $wgLanguageCode ),
				// Exclude native languages per page, including on later requests
				// when the native langlinks phase is no longer queried.
				'NOT EXISTS (SELECT 1 FROM ' . $nativeTable .
					' native_ll WHERE native_ll.ll_from = ill_from' .
					' AND native_ll.ll_lang = ill_lang)'
			],
		];
		$res = $this->select( __METHOD__, $extraQuery );

		$count = 0;
		foreach ( $res as $row ) {
			if ( ++$count > $remaining ) {
				$this->setContinueEnumParameter(
					'continue', "w|{$row->ill_from}|{$row->ill_lang}"
				);
				break;
			}
			$obj = (object)[
				'll_lang' => $row->ill_lang,
				'll_title' => $row->ill_title,
				'll_from' => $row->ill_from,
			];
			if ( !$this->addResult( $obj, $params, $prop ) ) {
				$this->setContinueEnumParameter(
					'continue', "w|{$row->ill_from}|{$row->ill_lang}"
				);
				break;
			}
		}
	}

	public static function getInterlanguageExtensionDB() {
		global $wgInterlanguageExtensionDB;

		if ( !isset( $wgInterlanguageExtensionDB ) || !$wgInterlanguageExtensionDB ) {
			return false;
		}
		return $wgInterlanguageExtensionDB;
	}

	/**
	 * Get the Query database connection (read-only)
	 * @return IDatabase
	 */
	public function getDB() {
		$interlanguageExtensionDB = self::getInterlanguageExtensionDB();

		if ( !$interlanguageExtensionDB ) {
			return parent::getDB();
		}

		if ( is_null( $this->wikirougeForeignDb ) ) {
			$this->wikirougeForeignDb = MediaWikiServices::getInstance()->getConnectionProvider()
				->getReplicaDatabase( $interlanguageExtensionDB );
		}

		return $this->wikirougeForeignDb;
	}

	public function getCacheMode( $params ) {
		return 'public';
	}

	public function getAllowedParams() {
		return [
			'prop' => [
				ApiBase::PARAM_ISMULTI => true,
				ApiBase::PARAM_TYPE => [
					'url',
					'langname',
					'autonym',
				],
				ApiBase::PARAM_HELP_MSG_PER_VALUE => [],
			],
			'lang' => null,
			'title' => null,
			'dir' => [
				ApiBase::PARAM_DFLT => 'ascending',
				ApiBase::PARAM_TYPE => [
					'ascending',
					'descending'
				]
			],
			'inlanguagecode' => MediaWikiServices::getInstance()->getContentLanguage()->getCode(),
			'limit' => [
				ApiBase::PARAM_DFLT => 10,
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => ApiBase::LIMIT_BIG1,
				ApiBase::PARAM_MAX2 => ApiBase::LIMIT_BIG2
			],
			'continue' => [
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
			],
			'url' => [
				ApiBase::PARAM_DFLT => false,
				ApiBase::PARAM_DEPRECATED => true,
			],
		];
	}

	protected function getExamplesMessages() {
		return [
			'action=query&prop=langlinks&titles=Main%20Page&redirects='
				=> 'apihelp-query+langlinks-example-simple',
		];
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/API:Langlinks';
	}
}
