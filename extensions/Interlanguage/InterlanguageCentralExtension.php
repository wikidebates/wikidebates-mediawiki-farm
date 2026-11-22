<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Title\Title;

class InterlanguageCentralExtension {

	/**
	 * Register parser function
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook(
			'languagelink',
			[ self::class, 'languagelink' ],
			Parser::SFH_NO_HASH
		);
		return true;
	}

	/**
	 * Parser function {{#languagelink:lang|title}}
	 */
	public static function languagelink( Parser $parser, $lang, $title = "" ) {
		if ( strlen( $lang ) && strlen( $title ) ) {
			return "[[$lang:$title]][[:$lang:$title]]";
		}
		return "";
	}

	/**
	 * Trigger purge job when interlanguage links change
	 */
	public static function onLinksUpdate( $linksUpdate ) {

		$title = $linksUpdate->getTitle();
		if ( !$title instanceof Title ) {
			return true;
		}

		$oldILL = self::getILL( DB_REPLICA, $title );

		$parserOutput = $linksUpdate->getParserOutput();
		if ( !$parserOutput instanceof ParserOutput ) {
			return true;
		}

		// MW 1.43 ParserOutput::getLanguageLinks() returns a numeric list of
		// "lang:title" strings, not a map keyed by language. Match core's
		// LangLinksTable conversion so jobs target actual interwiki prefixes.
		$newILL = [];
		foreach ( $parserOutput->getLanguageLinks() as $link ) {
			if ( !is_string( $link ) || !str_contains( $link, ':' ) ) {
				continue;
			}
			[ $lang, $target ] = explode( ':', $link, 2 );
			if ( $lang === '' || $target === '' ) {
				continue;
			}
			// Preserve the first target per language, like LangLinksTable.
			$newILL[$lang] ??= [ $target => true ];
		}

		if (
			count( array_udiff_assoc( $oldILL, $newILL, [ self::class, 'arrayCompareKeys' ] ) ) ||
			count( array_udiff_assoc( $newILL, $oldILL, [ self::class, 'arrayCompareKeys' ] ) )
		) {
			$ill = array_merge_recursive( $oldILL, $newILL );
			$job = new InterlanguageCentralExtensionPurgeJob( $title, [ 'ill' => $ill ] );
			MediaWikiServices::getInstance()
				->getJobQueueGroup()
				->push( $job );
		}

		return true;
	}

	/**
	 * Get language links stored in DB
	 */
	public static function getILL( int $db, Title $title ) {

		$dbr = $db === DB_PRIMARY
			? MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase()
			: MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();

		$res = $dbr->select(
			'langlinks',
			[ 'll_lang', 'll_title' ],
			[ 'll_from' => $title->getArticleID() ],
			__METHOD__
		);

		$a = [];

		foreach ( $res as $row ) {
			if ( !isset( $a[$row->ll_lang] ) ) {
				$a[$row->ll_lang] = [];
			}
			$a[$row->ll_lang][$row->ll_title] = true;
		}

		return $a;
	}

	/**
	 * Compare arrays
	 */
	public static function arrayCompareKeys( $a, $b ) {
		return count( array_diff_key( $a, $b ) )
			? 1
			: ( count( array_diff_key( $b, $a ) ) ? -1 : 0 );
	}

	/**
	 * Inject central interlanguage links
	 */
	public static function onLanguageLinks( Title $title, &$links, &$linkFlags ) {

		$pageId = $title->getArticleID();
		if ( !$pageId ) {
			return true;
		}

		$a = [];

		foreach ( $links as $l ) {
			[ $lang, $titleText ] = explode( ':', $l, 2 );
			$a[$lang] = $titleText;
		}

		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();

		$conds = [ 'ill_from' => $pageId ];

		if ( $a ) {
			$conds[] = 'ill_lang NOT IN (' . $dbr->makeList( array_keys( $a ) ) . ')';
		}

		$res = $dbr->select(
			'interlanguage_links',
			[ 'ill_lang', 'ill_title' ],
			$conds,
			__METHOD__
		);

		foreach ( $res as $row ) {
			if ( isset( $a[$row->ill_lang] ) ) {
				continue;
			}

			$a[$row->ill_lang] = true;
			$links[] = $row->ill_lang . ':' . $row->ill_title;
		}

		return true;
	}
}
