<?php

namespace Flow\Maintenance;

/**
 * Order-aware and order-independent digests for maintenance-only Flow A/B diagnostics.
 * No raw revision content is returned. HMACs require a private, stable site key.
 */
final class FlowBenchmarkFingerprints {
	private static function digest( $value ): string {
		return hash( 'sha256', json_encode( $value, JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * @param array $topicList Flow topic-list API response
	 * @param string $hmacKey Private site key, never included in the returned result
	 * @return array Diagnostic hashes and revision identifiers, without message text
	 */
	public static function inspect( array $topicList, string $hmacKey ): array {
		if ( strlen( $hmacKey ) < 32 ) {
			throw new \InvalidArgumentException( 'Flow diagnostics require a strong private HMAC key' );
		}

		$roots = $topicList['roots'] ?? [];
		$posts = $topicList['posts'] ?? [];
		$revisions = $topicList['revisions'] ?? [];

		$sortedRoots = $roots;
		sort( $sortedRoots, SORT_STRING );
		$sortedPosts = $posts;
		ksort( $sortedPosts, SORT_STRING );
		$revisionIds = array_keys( $revisions );
		$sortedRevisionIds = $revisionIds;
		sort( $sortedRevisionIds, SORT_STRING );

		$contentByRevision = [];
		foreach ( $revisions as $revisionId => $revision ) {
			$contentByRevision[$revisionId] = $revision['content'] ?? null;
		}
		ksort( $contentByRevision, SORT_STRING );

		// Hash every revision property, including actions, permissions and links.
		// HMAC protects private or unguessable response fields in diagnostics.
		$revisionMetadata = [];
		foreach ( $revisions as $revisionId => $revision ) {
			$metadata = $revision;
			unset( $metadata['content'] );
			$revisionMetadata[$revisionId] = $metadata;
		}
		ksort( $revisionMetadata, SORT_STRING );

		$contentHmacByRevision = [];
		foreach ( $contentByRevision as $revisionId => $content ) {
			$contentHmacByRevision[$revisionId] = hash_hmac(
				'sha256',
				(string)$revisionId . "\0" . json_encode( $content, JSON_UNESCAPED_SLASHES ),
				$hmacKey
			);
		}

		return [
			'roots_in_order_sha256' => self::digest( $roots ),
			'roots_sorted_sha256' => self::digest( $sortedRoots ),
			'posts_in_order_sha256' => self::digest( $posts ),
			'posts_sorted_by_id_sha256' => self::digest( $sortedPosts ),
			'revisions_in_order_sha256' => self::digest( $revisionIds ),
			'revisions_sorted_sha256' => self::digest( $sortedRevisionIds ),
			'content_sorted_by_revision_id_sha256' => self::digest( $contentByRevision ),
			// Exact full-response comparison; volatile per-request tokens may differ.
			'full_api_hmac_sha256' => hash_hmac(
				'sha256', json_encode( $topicList, JSON_UNESCAPED_SLASHES ), $hmacKey
			),
			'revision_metadata_hmac_sha256' => hash_hmac(
				'sha256', json_encode( $revisionMetadata, JSON_UNESCAPED_SLASHES ), $hmacKey
			),
			'root_ids' => $roots,
			'post_revisions_by_id' => $sortedPosts,
			'content_hmac_by_revision_id' => $contentHmacByRevision,
		];
	}
}
