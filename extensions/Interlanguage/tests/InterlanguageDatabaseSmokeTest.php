<?php
/**
 * Check that foreign DB reads and writes use distinct connections.
 *
 * Run with: php extensions/Interlanguage/tests/InterlanguageDatabaseSmokeTest.php
 */
namespace MediaWiki {
	class InterlanguageTestDbResult implements \IteratorAggregate {
		public function fetchObject() {
			return false;
		}

		public function getIterator(): \Traversable {
			return new \ArrayIterator( [] );
		}
	}

	class InterlanguageTestDatabase {
		public int $selectCalls = 0;

		public function select( $tables, $fields, $conditions, $caller ) {
			$this->selectCalls++;
			return new InterlanguageTestDbResult();
		}

		public function selectRow( $tables, $fields, $conditions, $caller ) {
			$this->selectCalls++;
			return false;
		}
	}

	class InterlanguageTestConnectionProvider {
		public InterlanguageTestDatabase $foreignPrimary;
		public InterlanguageTestDatabase $foreignReplica;
		public InterlanguageTestDatabase $localReplica;
		public int $primaryRequests = 0;
		public int $foreignReplicaRequests = 0;

		public function __construct() {
			$this->foreignPrimary = new InterlanguageTestDatabase();
			$this->foreignReplica = new InterlanguageTestDatabase();
			$this->localReplica = new InterlanguageTestDatabase();
		}

		public function getPrimaryDatabase( $domain = false ): InterlanguageTestDatabase {
			if ( $domain !== 'centralWiki' ) {
				throw new \RuntimeException( 'Unexpected primary database domain' );
			}
			$this->primaryRequests++;
			return $this->foreignPrimary;
		}

		public function getReplicaDatabase( $domain = false ): InterlanguageTestDatabase {
			if ( $domain === 'centralWiki' ) {
				$this->foreignReplicaRequests++;
				return $this->foreignReplica;
			}
			return $this->localReplica;
		}
	}

	class InterlanguageTestNamespaceInfo {
		public function getCanonicalNamespaces(): array {
			return [ 0 => '' ];
		}
	}

	class MediaWikiServices {
		private static ?self $instance = null;
		public InterlanguageTestConnectionProvider $provider;

		private function __construct() {
			$this->provider = new InterlanguageTestConnectionProvider();
		}

		public static function getInstance(): self {
			return self::$instance ??= new self();
		}

		public function getConnectionProvider(): InterlanguageTestConnectionProvider {
			return $this->provider;
		}

		public function getNamespaceInfo(): InterlanguageTestNamespaceInfo {
			return new InterlanguageTestNamespaceInfo();
		}
	}
}

namespace {
	class Title {
		private string $text;

		public function __construct( string $text ) {
			$this->text = $text;
		}

		public static function newFromText( string $text ): self {
			return new self( $text );
		}

		public function canExist(): bool {
			return true;
		}

		public function getNamespace(): int {
			return 0;
		}

		public function getText(): string {
			return $this->text;
		}

		public function getDBkey(): string {
			return str_replace( ' ', '_', $this->text );
		}
	}

	require_once dirname( __DIR__ ) . '/InterlanguageExtension.php';

	function assertInterlanguageDatabase( bool $condition, string $message ): void {
		if ( !$condition ) {
			throw new \RuntimeException( $message );
		}
	}

	$wgInterlanguageExtensionDB = 'centralWiki';
	$wgLanguageCode = 'fr';
	$wgInterlanguageExtensionInterwiki = 'en:';
	$provider = \MediaWiki\MediaWikiServices::getInstance()->getConnectionProvider();

	// First parse (primary), then read (replica).
	$extension = new \InterlanguageExtension();
	$title = Title::newFromText( 'Sample page' );
	$extension->onContentAlterParserOutput( null, $title, new \stdClass() );
	assertInterlanguageDatabase(
		$provider->primaryRequests === 1,
		'Parsing must acquire a primary database connection'
	);
	$links = $extension->getLinksFromDB( 'Sample page' );
	assertInterlanguageDatabase(
		$provider->foreignReplicaRequests === 1 &&
			$extension->foreignDbr === $provider->foreignReplica,
		'Reads after parsing should use the foreign replica, not the primary'
	);
	assertInterlanguageDatabase(
		isset( $links['query']['pages'][-1]['missing'] ),
		'Missing page result should be retained'
	);

	// First read (replica), then parse (primary).
	$extension = new \InterlanguageExtension();
	$extension->getLinksFromDB( 'Sample page' );
	$primaryBefore = $provider->primaryRequests;
	$extension->onContentAlterParserOutput( null, $title, new \stdClass() );
	assertInterlanguageDatabase(
		$provider->primaryRequests === $primaryBefore + 1,
		'Parsing after a read must not reuse the replica for writes'
	);
	assertInterlanguageDatabase(
		$provider->foreignPrimary->selectCalls > 0 &&
			$provider->foreignReplica->selectCalls > 0,
		'Both foreign database connections must be used independently'
	);

	echo "Interlanguage database smoke tests: OK\n";
}
