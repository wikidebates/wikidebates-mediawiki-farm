<?php

require_once __DIR__ . '/../../maintenance/FlowBenchmarkFingerprints.php';

use Flow\Maintenance\FlowBenchmarkFingerprints;

function assertFlowFingerprint( bool $condition, string $description ): void {
	if ( !$condition ) {
		throw new RuntimeException( $description );
	}
}

$key = str_repeat( 'x', 32 );
$list = [
	'roots' => [ 'topic-z', 'topic-a' ],
	'posts' => [
		'post-z' => [ 'revision-z' ],
		'post-a' => [ 'revision-a' ],
	],
	'revisions' => [
		'revision-z' => [ 'content' => [ 'format' => 'fixed-html', 'content' => '<p>one</p>' ] ],
		'revision-a' => [ 'content' => [ 'format' => 'fixed-html', 'content' => '<p>two</p>' ] ],
	],
];

$original = FlowBenchmarkFingerprints::inspect( $list, $key );
assertFlowFingerprint( count( $original['content_hmac_by_revision_id'] ) === 2,
	'Diagnostics cover each revision' );
assertFlowFingerprint( !str_contains( json_encode( $original ), '<p>' ),
	'Diagnostics never leak raw HTML' );
assertFlowFingerprint( !str_contains( json_encode( $original ), $key ),
	'Diagnostics never leak the HMAC key' );

$reordered = $list;
$reordered['posts'] = array_reverse( $reordered['posts'], true );
$reordered['revisions'] = array_reverse( $reordered['revisions'], true );
$compare = FlowBenchmarkFingerprints::inspect( $reordered, $key );
assertFlowFingerprint( $original['posts_in_order_sha256'] !== $compare['posts_in_order_sha256'],
	'Post insertion-order differences are detected' );
assertFlowFingerprint( $original['posts_sorted_by_id_sha256'] === $compare['posts_sorted_by_id_sha256'],
	'Post canonical hash ignores associative order' );
assertFlowFingerprint( $original['revisions_in_order_sha256'] !== $compare['revisions_in_order_sha256'],
	'Revision insertion-order differences are detected' );
assertFlowFingerprint( $original['revisions_sorted_sha256'] === $compare['revisions_sorted_sha256'],
	'Revision canonical hash ignores associative order' );
assertFlowFingerprint( $original['content_sorted_by_revision_id_sha256'] === $compare['content_sorted_by_revision_id_sha256'],
	'Content canonical hash ignores associative order' );
assertFlowFingerprint( $original['content_hmac_by_revision_id'] === $compare['content_hmac_by_revision_id'],
	'Revision HMACs are stable across associative ordering changes' );

$rootsReordered = $list;
$rootsReordered['roots'] = array_reverse( $rootsReordered['roots'] );
$compare = FlowBenchmarkFingerprints::inspect( $rootsReordered, $key );
assertFlowFingerprint( $original['roots_in_order_sha256'] !== $compare['roots_in_order_sha256'],
	'Root order matters to the visible topic list' );
assertFlowFingerprint( $original['roots_sorted_sha256'] === $compare['roots_sorted_sha256'],
	'Root set is unchanged' );

$edited = $list;
$edited['revisions']['revision-a']['content']['content'] = '<p>changed</p>';
$compare = FlowBenchmarkFingerprints::inspect( $edited, $key );
assertFlowFingerprint( $original['content_sorted_by_revision_id_sha256'] !== $compare['content_sorted_by_revision_id_sha256'],
	'Content changes are detected independent of insertion order' );
assertFlowFingerprint( $original['content_hmac_by_revision_id']['revision-a'] !==
	$compare['content_hmac_by_revision_id']['revision-a'], 'The modified revision is identified' );
assertFlowFingerprint( $original['content_hmac_by_revision_id']['revision-z'] ===
	$compare['content_hmac_by_revision_id']['revision-z'], 'Unmodified revision remains stable' );

$postReassigned = $list;
$postReassigned['posts']['post-a'] = [ 'revision-z' ];
$compare = FlowBenchmarkFingerprints::inspect( $postReassigned, $key );
assertFlowFingerprint( $original['posts_sorted_by_id_sha256'] !== $compare['posts_sorted_by_id_sha256'],
	'Different post-revision mapping is detected' );

// Metadata-only differences must be detected even when HTML and IDs match.
$metadataChanged = $list;
$metadataChanged['revisions']['revision-a']['actions'] = [ 'reply' => false ];
$metadataCompare = FlowBenchmarkFingerprints::inspect( $metadataChanged, $key );
assertFlowFingerprint(
	$original['content_sorted_by_revision_id_sha256'] ===
		$metadataCompare['content_sorted_by_revision_id_sha256'],
	'Metadata regression fixture keeps the same HTML'
);
assertFlowFingerprint(
	$original['revision_metadata_hmac_sha256'] !==
		$metadataCompare['revision_metadata_hmac_sha256'],
	'Action and permission metadata changes are detected'
);
assertFlowFingerprint(
	$original['full_api_hmac_sha256'] !== $metadataCompare['full_api_hmac_sha256'],
	'Full API digest detects changes beyond revision HTML'
);

$outsideRevision = $list;
$outsideRevision['links'] = [ 'next' => '/wiki/next' ];
$outsideCompare = FlowBenchmarkFingerprints::inspect( $outsideRevision, $key );
assertFlowFingerprint(
	$original['full_api_hmac_sha256'] !== $outsideCompare['full_api_hmac_sha256'],
	'Full API digest detects top-level links changes'
);
assertFlowFingerprint(
	$original['revision_metadata_hmac_sha256'] ===
		$outsideCompare['revision_metadata_hmac_sha256'],
	'Top-level links do not alter revision metadata digest'
);
assertFlowFingerprint( !str_contains( json_encode( $original ), $key ),
	'Extended diagnostics never publish the HMAC key' );

$rejected = false;
try {
	FlowBenchmarkFingerprints::inspect( $list, 'weak' );
} catch ( InvalidArgumentException $e ) {
	$rejected = true;
}
assertFlowFingerprint( $rejected, 'Weak diagnostic key is rejected' );

echo "OK: Flow benchmark fingerprint diagnostics\n";
