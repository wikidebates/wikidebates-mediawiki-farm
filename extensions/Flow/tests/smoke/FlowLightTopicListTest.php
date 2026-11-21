<?php
/** Run with php extensions/Flow/tests/smoke/FlowLightTopicListTest.php. */
require __DIR__ . '/bootstrap.php';

use Flow\Block\TopicListBlock;
use Flow\Container;
use Flow\Data\Pager\PagerPage;
use Flow\Formatter\TocTopicListFormatter;
use Flow\Formatter\TopicListFormatter;
use Flow\Model\AbstractRevision;
use Flow\Model\PostRevision;
use Flow\Model\UUID;
use Flow\Model\Workflow;
use Flow\RevisionActionPermissions;
use Flow\Templating;

class LightTitle {
	public function __construct( private string $path ) {
	}
	public function getLinkURL() {
		return $this->path;
	}
}
class_alias( LightTitle::class, 'MediaWiki\\Title\\Title' );
class LightRawMessage {
	public function __construct( private string $message ) {
	}
	public function plaintextParams( $value ) {
		$this->message = (string)$value;
		return $this;
	}
	public function text() {
		return $this->message;
	}
}
class_alias( LightRawMessage::class, 'MediaWiki\\Message\\Message' );
class_alias( LightRawMessage::class, 'MediaWiki\\Language\\RawMessage' );
class LightWorkflow extends Workflow {
	public function __construct( string $id, string $type ) {
		$this->id = UUID::create( $id );
		$this->type = $type;
	}
	public function getArticleTitle() {
		return new LightTitle( '/wiki/Topic:' . $this->getId()->getAlphadecimal() );
	}
	public function getLastUpdatedObj() {
		return new DateTimeImmutable( '@1700000000' );
	}
}
class LightPost extends PostRevision {
	public function __construct( string $id, string $revisionId ) {
		$this->postId = UUID::create( $id );
		$this->revId = UUID::create( $revisionId );
	}
}
class LightPermissions extends RevisionActionPermissions {
	public array $visible = [];
	public function __construct() {
	}
	public function isAllowed( AbstractRevision $revision, $action ) {
		check( $action === 'view', 'Only view permissions are used for previews' );
		return isset( $this->visible[$revision->getPostId()->getAlphadecimal()] );
	}
}
class LightTemplating extends Templating {
	public function __construct() {
	}
	public function getModeratedRevision( AbstractRevision $revision ) {
		return $revision;
	}
	public function getContent( AbstractRevision $revision, $format = 'fixed-html' ) {
		return $format === 'topic-title-wikitext' ? 'Heading' : 'Heading';
	}
}
class LightFullFormatter extends TopicListFormatter {
	public function __construct() {
	}
	public function buildEmptyResult( Workflow $workflow ) {
		return [
			'workflowId' => 'board',
			'title' => 'Talk:Board',
			'actions' => [ 'newtopic' => '/wiki/Talk:Board?newtopic=1' ],
			'links' => [
				'newtopic' => '/wiki/Talk:Board?newtopic=1',
				'board-sort' => [ 'updated' => '/wiki/Talk:Board?sortby=updated' ],
			],
		];
	}
}
class LightPagerPage extends PagerPage {
	public function __construct() {
	}
	public function getPagingLinksOptions() {
		return [ 'fwd' => [ 'offset-id' => '123' ] ];
	}
}

$board = new LightWorkflow( '0000000000000001', 'discussion' );
$allowed = new LightWorkflow( '0000000000000002', 'topic' );
$hidden = new LightWorkflow( '0000000000000003', 'topic' );
$revAllowed = new LightPost( '0000000000000002', '0000000000000004' );
$revHidden = new LightPost( '0000000000000003', '0000000000000005' );
$permissions = new LightPermissions();
$permissions->visible = [ '0000000000000002' => true ];
Container::$services['formatter.topiclist'] = new LightFullFormatter();
Container::$services['formatter.topiclist.toc'] = new TocTopicListFormatter( new LightTemplating() );

$block = ( new ReflectionClass( TopicListBlock::class ) )->newInstanceWithoutConstructor();
$reflection = new ReflectionObject( $block );
foreach ( [
	'workflow' => $board,
	'permissions' => $permissions,
	'topicRootRevisionCache' => [
		'0000000000000002' => $revAllowed,
		'0000000000000003' => $revHidden,
	],
] as $propertyName => $value ) {
	$property = $reflection->getProperty( $propertyName );
	$property->setValue( $block, $value );
}
$method = $reflection->getMethod( 'renderLightweightApi' );
$result = $method->invoke( $block, [ $allowed, $hidden ], new LightPagerPage() );
check( $result['lightweight'] === true, 'Explicit lightweight mode' );
check( $result['roots'] === [ '0000000000000002' ], 'Never expose an unauthorized topic title' );
check( count( $result['posts'] ) === 1, 'No other posts/replies are fetched' );
check( count( $result['revisions'] ) === 1, 'No other revisions are embedded in the page' );
check( $result['revisions']['0000000000000004']['postId'] === '0000000000000002', 'Preview has its root id' );
check( $result['revisions']['0000000000000004']['content']['content'] === 'Heading', 'Preview title present' );
check( $result['revisions']['0000000000000004']['links']['topic']['url'] ===
	'/wiki/Topic:0000000000000002', 'Native topic page link retained' );
check( $result['links']['newtopic'] !== '', 'Board new topic link retained' );
check( isset( $result['links']['pagination']['fwd'] ), 'Existing pagination retained' );
check( isset( $result['links']['board-sort']['updated'] ), 'Sort link retained' );

$permissions->visible = [];
$result = $method->invoke( $block, [ $allowed, $hidden ], new LightPagerPage() );
check( $result['roots'] === [], 'All hidden topics are excluded' );
check( $result['revisions'] === [], 'No data is leaked when all topics are hidden' );

echo 'OK: ' . $GLOBALS['flow_test_assertions'] . " Flow lightweight assertions\n";
