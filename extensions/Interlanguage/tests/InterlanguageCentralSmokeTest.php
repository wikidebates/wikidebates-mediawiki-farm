<?php
/**
 * Test central language-link injection without a database connection.
 *
 * Run with: php extensions/Interlanguage/tests/InterlanguageCentralSmokeTest.php
 */
namespace MediaWiki\Title {
	class Title {
		public function getArticleID(): int {
			return 42;
		}
	}
}

namespace MediaWiki {
	class InterlanguageCentralTestDB {
		public array $conditions = [];

		public function makeList( array $values ): string {
			return "'" . implode( "','", $values ) . "'";
		}

		public function select( $table, $fields, $conditions, $caller ): array {
			$this->conditions = $conditions;
			return [
				(object)[ 'ill_lang' => 'en', 'ill_title' => 'A different target' ],
				(object)[ 'ill_lang' => 'fr', 'ill_title' => 'Projet:Essai' ],
				(object)[ 'ill_lang' => 'de', 'ill_title' => 'Projekt:Test:2' ],
			];
		}
	}

	class InterlanguageCentralTestConnections {
		public InterlanguageCentralTestDB $db;

		public function __construct() {
			$this->db = new InterlanguageCentralTestDB();
		}

		public function getReplicaDatabase(): InterlanguageCentralTestDB {
			return $this->db;
		}
	}

	class MediaWikiServices {
		private static ?self $instance = null;
		private InterlanguageCentralTestConnections $connections;

		private function __construct() {
			$this->connections = new InterlanguageCentralTestConnections();
		}

		public static function getInstance(): self {
			return self::$instance ??= new self();
		}

		public function getConnectionProvider(): InterlanguageCentralTestConnections {
			return $this->connections;
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/InterlanguageCentralExtension.php';

	function assertInterlanguageCentral( bool $ok, string $message ): void {
		if ( !$ok ) {
			throw new \RuntimeException( $message );
		}
	}

	$links = [ 'en:Topic:Subtopic' ];
	$linkFlags = [];
	$title = new \MediaWiki\Title\Title();
	\InterlanguageCentralExtension::onLanguageLinks( $title, $links, $linkFlags );

	assertInterlanguageCentral(
		$links === [
			'en:Topic:Subtopic',
			'fr:Projet:Essai',
			'de:Projekt:Test:2',
		],
		'Central links must preserve colons and never replace existing language links'
	);
	$db = \MediaWiki\MediaWikiServices::getInstance()
		->getConnectionProvider()->getReplicaDatabase();
	assertInterlanguageCentral(
		$db->conditions['ill_from'] === 42,
		'Central links were queried for the wrong page'
	);

	echo "Interlanguage central smoke tests: OK\n";
}
