<?php

namespace Flow\Data\Pager;

use Flow\Exception\InvalidInputException;
use Flow\Model\UUID;

/**
 * Cursor for topic lists ordered by last activity. The UUID disambiguates
 * topics updated during the same second, without changing the API parameter.
 */
class TopicListCursor {
	public const SORT = [ 'workflow_last_update_timestamp', 'topic_id' ];

	/**
	 * @param string $offset Existing timestamp, or a timestamp and topic UUID separated by |.
	 * @return array{timestamp:string,topicId:?UUID}
	 */
	public static function parse( string $offset ): array {
		$timestamp = '(?:[0-9]{14}|[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})';
		if ( preg_match( '/^(' . $timestamp . ')\|([a-z0-9]{16,19})$/D', $offset, $matches ) ) {
			return [
				'timestamp' => $matches[1],
				'topicId' => UUID::create( $matches[2] ),
			];
		}
		if ( preg_match( '/^' . $timestamp . '$/D', $offset ) ) {
			// Links generated before composite pagination contained only a timestamp.
			return [ 'timestamp' => $offset, 'topicId' => null ];
		}
		throw new InvalidInputException( 'Invalid updated topic-list cursor', 'invalid-input' );
	}
}
