<?php
/**
 * Central-DB redirect lookup and DB priority; no real wiki or HTTP access.
 * Run: php extensions/Interlanguage/tests/InterlanguageForeignRedirectSmokeTest.php
 */
class Title {
	public function __construct( private string $text ) {}
	public static function newFromText( string $text ): self {
		return new self( $text );
	}
	public function getDBkey(): string {
		return str_replace( ' ', '_', $this->text );
	}
	public function getNamespace(): int {
		return 0;
	}
}
class InterlanguageRedirectTestResult implements IteratorAggregate {
	public function __construct( private $row = false, private array $rows = [] ) {}
	public function fetchObject() {
		return $this->row;
	}
	public function getIterator(): Traversable {
		return new ArrayIterator( $this->rows );
	}
}
class InterlanguageRedirectTestDatabase {
	public array $queries = [];
	public $original = false;
	public $target = false;
	public array $langlinks = [];

	public function select( $tables, $fields, $conditions, $caller ): InterlanguageRedirectTestResult {
		$this->queries[] = [ $tables, $fields, $conditions ];
		if ( $tables === 'page' ) {
			return new InterlanguageRedirectTestResult( $this->original );
		}
		if ( $tables === [ 'redirect', 'page' ] ) {
			if ( !in_array( 'rd_title = page_title', $conditions, true ) ||
				!in_array( 'rd_namespace = page_namespace', $conditions, true ) ) {
				throw new RuntimeException( 'Redirect join must match title and namespace' );
			}
			return new InterlanguageRedirectTestResult( $this->target );
		}
		if ( $tables === [ 'langlinks' ] ) {
			$id = $conditions['ll_from'] ?? null;
			return new InterlanguageRedirectTestResult( false, $this->langlinks[$id] ?? [] );
		}
		throw new RuntimeException( 'Unexpected central query' );
	}
}
require_once dirname( __DIR__ ) . '/InterlanguageExtension.php';

function checkRedirect( bool $ok, string $why ): void {
	if ( !$ok ) {
		throw new RuntimeException( $why );
	}
}
function extensionForRedirect( InterlanguageRedirectTestDatabase $db ): InterlanguageExtension {
	$ext = new InterlanguageExtension();
	$ext->foreignDbr = $db;
	return $ext;
}
$wgInterlanguageExtensionDB = 'centralWiki';
$wgInterlanguageExtensionApiUrl = 'https://example.invalid/w/api.php';

$db = new InterlanguageRedirectTestDatabase();
$result = extensionForRedirect( $db )->getLinksFromDB( 'Unknown' );
checkRedirect( isset( $result['query']['pages'][-1]['missing'] ), 'Missing central page' );

$db = new InterlanguageRedirectTestDatabase();
$db->original = (object)[ 'page_id' => 101, 'page_is_redirect' => 1 ];
$result = extensionForRedirect( $db )->getLinksFromDB( 'Broken redirect' );
checkRedirect( isset( $result['query']['pages'][-1]['missing'] ), 'Broken redirect must return missing' );
checkRedirect( count( $db->queries ) === 2, 'No links should be queried for broken redirect' );

$db = new InterlanguageRedirectTestDatabase();
$db->original = (object)[ 'page_id' => 101, 'page_is_redirect' => 1 ];
$db->target = (object)[ 'page_id' => 102 ];
$db->langlinks[102] = [
	(object)[ 'll_lang' => 'fr', 'll_title' => 'Sujet:Deux-points' ],
	(object)[ 'll_lang' => 'de', 'll_title' => 'Deutsch' ],
];
$result = extensionForRedirect( $db )->getLinksFromDB( 'Valid redirect' );
checkRedirect( $result['query']['pages'][0]['langlinks'] === [
	[ 'lang' => 'fr', '*' => 'Sujet:Deux-points' ],
	[ 'lang' => 'de', '*' => 'Deutsch' ],
], 'Valid redirect must preserve target translations' );
checkRedirect( count( $db->queries ) === 3 && $db->queries[2][2]['ll_from'] === 102,
	'Valid redirect must follow the resolved target ID' );

$db = new InterlanguageRedirectTestDatabase();
$db->original = (object)[ 'page_id' => 201, 'page_is_redirect' => 0 ];
$db->langlinks[201] = [ (object)[ 'll_lang' => 'en', 'll_title' => 'Topic:Subtopic' ] ];
$result = extensionForRedirect( $db )->getLinks( 'Ordinary page' );
checkRedirect( $result['query']['pages'][0]['langlinks'] === [
	[ 'lang' => 'en', '*' => 'Topic:Subtopic' ],
], 'Central DB must take priority when distant API URL is also configured' );
checkRedirect( count( $db->queries ) === 2, 'Unexpected request in DB priority mode' );

echo "Interlanguage central redirect and DB-priority checks: OK\n";
