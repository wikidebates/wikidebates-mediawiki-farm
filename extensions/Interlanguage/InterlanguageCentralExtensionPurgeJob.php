<?php

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

/**
 * MediaWiki InterlanguageCentral extension
 * InterlanguageCentralExtensionPurgeJob class
 *
 * Copyright © 2010-2011 Nikola Smolenski <smolensk@eunet.rs>
 * @version 1.2
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 *
 * For more information,
 * @see https://www.mediawiki.org/wiki/Extension:Interlanguage
 */

// Based on https://www.mediawiki.org/wiki/Manual:Job_queue/For_developers
class InterlanguageCentralExtensionPurgeJob extends Job {
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'purgeDependentWikis', $title, $params );
	}

	/**
	 * Execute the job
	 *
	 * @return bool
	 */
	public function run() {
		//sleep() could be added here to reduce unnecessary use
		$ill = $this->params['ill'];

		$mwServices = MediaWikiServices::getInstance();
		$missingInterwiki = [];
		$missingApi = [];

		foreach ( $ill as $lang => $pages ) {
			if ( !$pages ) {
				continue;
			}
			$iw = $mwServices->getInterwikiLookup()->fetch( $lang );
			if ( !$iw ) {
				$missingInterwiki[] = $lang;
				continue;
			}
			$apiUrl = $iw->getAPI();
			if ( !$apiUrl ) {
				$missingApi[] = $lang;
				continue;
			}
			$apiUrl .= '?' . wfArrayToCGI( array(
				'action'	=> 'purge',
				'format'	=> 'json',
				'formatversion' => 2, // Native JSON booleans, including purged:true
				'titles'	=> implode( '|', array_keys( $pages ) )
			) );
//			Http::post( $apiUrl );
			$response = $mwServices->getHttpRequestFactory()->post( $apiUrl, [], __METHOD__ );
			$result = is_string( $response ) ? json_decode( $response, true ) : null;
			// Request formatversion=2: in version 1 a successful purged flag
			// is an empty string, not the JSON boolean true. With version 2
			// strictly require purged:true for each item. Reject partial batches
			// and do not automatically retry.
			$items = is_array( $result ) ? ( $result['purge'] ?? null ) : null;
			$purged = is_array( $items ) && count( $items ) === count( $pages ) && $items !== [];
			if ( $purged ) {
				foreach ( $items as $item ) {
					if ( !is_array( $item ) || ( $item['purged'] ?? null ) !== true ) {
						$purged = false;
						break;
					}
				}
			}
			if ( !$purged || isset( $result['error'] ) ) {
				LoggerFactory::getInstance( 'Interlanguage' )->warning(
					'Remote purge failed or returned an invalid response for interwiki {language}',
					[ 'language' => $lang ]
				);
			}
			// Keep the existing non-retrying behavior until remote API permissions are verified.
		}

		if ( $missingInterwiki || $missingApi ) {
			// Aggregate once per job to avoid a warning for every missing
			// destination. Do not log page titles, URLs or credentials.
			LoggerFactory::getInstance( 'Interlanguage' )->warning(
				'Remote purge skipped: {missing_prefixes} missing interwiki prefixes and ' .
				'{missing_apis} destinations without API URLs; sample: {languages}',
				[
					'missing_prefixes' => count( $missingInterwiki ),
					'missing_apis' => count( $missingApi ),
					'languages' => implode( ', ', array_slice(
						array_merge( $missingInterwiki, $missingApi ), 0, 5
					) ),
				]
			);
		}

		return true;
	}

	//TODO: custom insert with duplicate merging
}
