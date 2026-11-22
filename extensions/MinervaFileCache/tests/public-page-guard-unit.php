<?php

namespace MediaWiki\Title {
	final class Title {
		public function __construct(
			private int $namespace = 0, private string $dbKey = 'Cache_study_argument'
		) {
		}
		public function canExist(): bool { return true; }
		public function getNamespace(): int { return $this->namespace; }
		public function getDBkey(): string { return $this->dbKey; }
	}
}

namespace MediaWiki {
	final class MediaWikiServices {
		public static $instance;
		public static function getInstance() { return self::$instance; }
	}
}

namespace {
	use MediaWiki\Extension\MinervaFileCache\PublicPageGuard;
	use MediaWiki\MediaWikiServices;
	use MediaWiki\Title\Title;

	require __DIR__ . '/../src/PublicPageGuard.php';

	$db = new class {
		public $page;
		public $revision;
		public int $reads = 0;
		public function selectRow($tables, array $fields, array $where, string $caller,
			array $options = [], array $joins = []) {
			$this->reads++;
			if ($tables !== ['page', 'revision'] ||
				($joins['revision'] ?? null) !== ['JOIN', 'rev_id = page_latest AND rev_page = page_id']) {
				throw new \RuntimeException('Expected a single joined PRIMARY read');
			}
			if (!$this->page || !$this->revision ||
				$where['page_namespace'] !== 0 || $where['page_title'] !== 'Cache_study_argument') {
				return false;
			}
			return (object)array_merge((array)$this->page, (array)$this->revision);
		}
	};
	$db->page = (object)[
		'page_id' => 13, 'page_latest' => 25,
		'page_touched' => '20261122120000', 'page_is_redirect' => 0
	];
	$db->revision = (object)['rev_deleted' => 0];
	$permission = new class {
		public bool $allow = true;
		public function userCan($action, $user, $title): bool {
			return $action === 'read' && $this->allow;
		}
	};
	MediaWikiServices::$instance = new class($db, $permission) {
		public function __construct(private $db, private $permission) {
		}
		public function getConnectionProvider() {
			return new class($this->db) {
				public function __construct(private $db) {
				}
				public function getPrimaryDatabase() { return $this->db; }
			};
		}
		public function getPermissionManager() { return $this->permission; }
		public function getUserFactory() {
			return new class {
				public function newAnonymous() { return new \stdClass(); }
			};
		}
	};
	$title = new Title();
	$assertions = 0;
	$check = static function (bool $value, string $message) use (&$assertions): void {
		if (!$value) {
			throw new \RuntimeException($message);
		}
		$assertions++;
	};
	$allowed = static fn(): bool => PublicPageGuard::matches($title, 25, '20261122120000');
	$check($allowed(), 'Current public revision can reuse anonymous mobile file');
	$check($db->reads === 1, 'A public cache HIT performs exactly one PRIMARY select');
	$check(!PublicPageGuard::matches($title, 26, '20261122120000'),
		'Stale revision ID is rejected');
	$check(!PublicPageGuard::matches($title, 25, '20261122120001'),
		'Stale page_touched is rejected');
	foreach ([1, 2, 4, 8, 15] as $mask) {
		$db->revision->rev_deleted = $mask;
		$check(!$allowed(), 'RevisionDelete bit ' . $mask . ' vetoes existing HIT');
	}
	$db->revision->rev_deleted = 0;
	$permission->allow = false;
	$check(!$allowed(), 'Anonymous read revocation vetoes existing HIT');
	$permission->allow = true;
	$check($allowed(), 'Read permission restoration allows same visible revision');
	$db->page->page_is_redirect = 1;
	$check(!$allowed(), 'Redirect conversion vetoes cached source page');
	$db->page->page_is_redirect = 0;
	$db->page = false;
	$check(!$allowed(), 'Deletion vetoes existing mobile file');
	$db->page = (object)[
		'page_id' => 13, 'page_latest' => 25,
		'page_touched' => '20261122120000', 'page_is_redirect' => 0
	];
	$db->revision = false;
	$check(!$allowed(), 'Missing revision row never certifies an HTML file');
	$db->revision = (object)['rev_deleted' => 0];
	$check($allowed(), 'Explicit restoration permits current public content');

	echo json_encode(['result' => 'PASS', 'assertions' => $assertions],
		JSON_THROW_ON_ERROR) . "\n";
}
