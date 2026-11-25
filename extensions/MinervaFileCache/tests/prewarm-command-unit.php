<?php

/* Isolated smoke test of the maintenance loop: no MediaWiki, network or production DB. */

namespace MediaWiki\WikiMap {
	class WikiMap {
		public static function getCurrentWikiId(): string { return 'fr'; }
	}
}

namespace MediaWiki\Title {
	class Title {
		private string $title;
		public function __construct(string $title, private int $namespace) { $this->title = $title; }
		public static function makeTitle(int $namespace, string $title): self {
			return new self($title, $namespace);
		}
		public function getNamespace(): int { return $this->namespace; }
		public function getFullURL(): string { return 'https://fr.example.test/wiki/' . rawurlencode($this->title); }
	}
}

namespace MediaWiki\Extension\MinervaFileCache {
	class Hooks {
		public static function store(): void {}
	}
}

namespace {
	define('SMW_NS_PROPERTY', 102);
	define('SMW_NS_CONCEPT', 108);
	define('CONTENT_MODEL_FLOW_BOARD', 'flow-board');
	require dirname(__DIR__) . '/src/Prewarm.php';
	use MediaWiki\Extension\MinervaFileCache\Prewarm;

	class Maintenance {
		private array $options = [];
		public function __construct() {}
		public function addDescription(string $text): void {}
		public function requireExtension(string $name): void {}
		public function addOption(string $name, string $text, bool $required = false, bool $hasArgument = false): void {}
		public function setOptions(array $options): void { $this->options = $options; }
		public function getOption(string $key, $default = null) {
			if ($key === 'delay-ms') { $GLOBALS['prewarmDefaultDelayMs'] = $default; }
			return $this->options[$key] ?? $default;
		}
		public function hasOption(string $key): bool { return isset($this->options[$key]); }
		public function getServiceContainer(): TestPrewarmServices { return $GLOBALS['prewarmServices']; }
		public function output(string $text): void { $GLOBALS['prewarmOutput'][] = $text; }
		public function error(string $text): void {}
		public function fatalError(string $text): void { throw new RuntimeException($text); }
	}

	class TestPrewarmConfig {
		public function __construct(private string $directory) {}
		public function get(string $key) {
			return [
				'Server' => 'https://fr.example.test', 'LanguageCode' => 'fr',
				'MinervaFileCacheEnabled' => true, 'UseFileCache' => true,
				'MinervaFileCacheDebugHeaders' => true, 'MinervaFileCacheDirectory' => $this->directory
			][$key];
		}
	}

	class TestPrewarmDb {
		public array $pages = [];
		public function select($table, $fields, $conditions, $caller, $options): array {
			$target = $conditions['page_id'] ?? null;
			if ($table !== 'page' || ($target === null &&
				!preg_match('/page_id > ([0-9]+)/', $conditions[0] ?? '', $match))) {
				throw new RuntimeException('Incorrect SQL page filter or pagination');
			}
			$after = $target === null ? (int)$match[1] : 0;
			$pages = array_filter($this->pages, static function ($page) use ($conditions, $target, $after) {
				return ($target === null ? $page->page_id > $after : $page->page_id === $target) &&
					(!isset($conditions['page_is_redirect']) ||
						(int)$page->page_is_redirect === $conditions['page_is_redirect']) &&
					(!isset($conditions['page_namespace']) || $page->page_namespace === $conditions['page_namespace']);
			});
			return array_slice(array_values($pages), 0, (int)$options['LIMIT']);
		}
	}

	class TestPrewarmRequest {
		private array $headers = [];
		private int $status = 200;
		public function __construct(private string $url, private array $options) {}
		public function setHeader(string $key, string $value): void { $this->headers[$key] = $value; }
		public function execute() {
			$GLOBALS['prewarmRequests'][] = [$this->url, $this->options, $this->headers];
			if (($GLOBALS['prewarmFailUrl'] ?? null) === $this->url) { $this->status = 429; }
			if (($GLOBALS['prewarmGoneUrl'] ?? null) === $this->url) { $this->status = 404; }
			if (($GLOBALS['prewarmRetryOnceUrl'] ?? null) === $this->url) {
				$GLOBALS['prewarmRetryCalls'] = ($GLOBALS['prewarmRetryCalls'] ?? 0) + 1;
				if ($GLOBALS['prewarmRetryCalls'] === 1) { $this->status = 429; }
			}
			$GLOBALS['prewarmHttpStatus'] = $this->status;
			return new class {
				public function isOK(): bool { return ($GLOBALS['prewarmHttpStatus'] ?? 200) < 400; }
			};
		}
		public function getStatus(): int { return $this->status; }
		public function getResponseHeader(string $name): ?string {
			if ($name === 'Retry-After' && $this->status === 429) {
				if (($GLOBALS['prewarmRetryOnceUrl'] ?? null) === $this->url) { return '0'; }
				if (($GLOBALS['prewarmFailUrl'] ?? null) === $this->url) { return '718'; }
			}
			return $name === 'X-Minerva-File-Cache' && $this->status === 200 ? 'MISS' : null;
		}
	}

	class TestPrewarmHttp {
		public function canMakeRequests(): bool { return true; }
		public function create(string $url, array $options, string $caller): TestPrewarmRequest {
			return new TestPrewarmRequest($url, $options);
		}
	}

	class TestPrewarmServices {
		private TestPrewarmConfig $config;
		private TestPrewarmDb $db;
		private TestPrewarmDb $primaryDb;
		public function __construct(string $directory) {
			$this->config = new TestPrewarmConfig($directory);
			$this->db = new TestPrewarmDb();
			$this->db->pages = [
				(object)['page_id'=>1,'page_namespace'=>0,'page_title'=>'One','page_is_redirect'=>0],
				(object)['page_id'=>2,'page_namespace'=>0,'page_title'=>'Two','page_is_redirect'=>0],
				(object)['page_id'=>3,'page_namespace'=>6,'page_title'=>'Three','page_is_redirect'=>0],
				(object)['page_id'=>4,'page_namespace'=>0,'page_title'=>'Redirect','page_is_redirect'=>1],
				(object)['page_id'=>5,'page_namespace'=>0,'page_title'=>'Five','page_is_redirect'=>0],
			];
			$this->primaryDb = new TestPrewarmDb();
			$this->primaryDb->pages = $this->db->pages;
		}
		public function setPrimaryPageTitle(int $id, string $title): void {
			foreach ($this->primaryDb->pages as $index => $page) {
				if ($page->page_id === $id) {
					$this->primaryDb->pages[$index] = (object)(array_replace(
						(array)$page, ['page_title' => $title]
					));
				}
			}
		}
		public function appendPrimaryPage(int $id, int $namespace, string $title, ?string $model = null): void {
			$this->primaryDb->pages[] = (object)[
				'page_id' => $id, 'page_namespace' => $namespace,
				'page_title' => $title, 'page_is_redirect' => 0,
				'page_content_model' => $model
			];
		}
		public function getMainConfig(): TestPrewarmConfig { return $this->config; }
		public function getHttpRequestFactory(): TestPrewarmHttp { return new TestPrewarmHttp(); }
		public function getConnectionProvider() {
			$db = $this->db;
			$primary = $this->primaryDb;
			return new class($db, $primary) {
				public function __construct(
					private TestPrewarmDb $db, private TestPrewarmDb $primary
				) {}
				public function getReplicaDatabase(): TestPrewarmDb { return $this->db; }
				public function getPrimaryDatabase(): TestPrewarmDb { return $this->primary; }
			};
		}
	}

	function checkPrewarmCli(bool $ok, string $message): void {
		if (!$ok) { throw new RuntimeException($message); }
		$GLOBALS['assertions']++;
	}

	$root = sys_get_temp_dir() . '/prewarm-command-test-' . bin2hex(random_bytes(6));
	mkdir($root . '/maintenance', 0700, true);
	file_put_contents($root . '/maintenance/Maintenance.php', '<?php');
	file_put_contents($root . '/noop.php', '<?php');
	putenv('MW_INSTALL_PATH=' . $root);
	define('RUN_MAINTENANCE_IF_MAIN', $root . '/noop.php');
	$GLOBALS['prewarmServices'] = new TestPrewarmServices($root);
	$GLOBALS['prewarmRequests'] = [];
	$GLOBALS['assertions'] = 0;
	require dirname(__DIR__) . '/maintenance/prewarm.php';
	try {
		$checkpoint = Prewarm::checkpointPath($root, 'fr', 'https://fr.example.test', 'fr', 'android', null);
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['resume'=>true,'limit'=>'2','delay-ms'=>'250']);
		checkPrewarmCli($runner->execute() === true, 'Initial limited run');
		checkPrewarmCli(Prewarm::readCheckpoint($checkpoint) === 2, 'Checkpoint after limited run');
		checkPrewarmCli(count($GLOBALS['prewarmRequests']) === 2, 'Only first two pages');
		checkPrewarmCli(($GLOBALS['prewarmRequests'][0][2]['Accept-Language'] ?? null) === 'fr', 'Language sent');
		checkPrewarmCli(str_contains($GLOBALS['prewarmRequests'][0][1]['userAgent'] ?? '', 'Android'), 'Mobile UA sent');
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['resume'=>true,'delay-ms'=>'250']);
		checkPrewarmCli($runner->execute() === true, 'Resume to completion');
		checkPrewarmCli(count($GLOBALS['prewarmRequests']) === 4, 'Redirect skipped');
		checkPrewarmCli(!is_file($checkpoint), 'Completed checkpoint removed');
		$GLOBALS['prewarmRequests'] = [];
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['dry-run'=>true,'limit'=>'1']);
		checkPrewarmCli($runner->execute() === true && count($GLOBALS['prewarmRequests']) === 0, 'Dry-run sends no HTTP');
		checkPrewarmCli(($GLOBALS['prewarmDefaultDelayMs'] ?? null) === 6000,
			'Rate-safe default delay is six seconds without affecting explicit overrides');
		$GLOBALS['prewarmFailUrl'] = 'https://fr.example.test/wiki/Two';
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['resume'=>true,'reset'=>true,'delay-ms'=>'250']);
		checkPrewarmCli($runner->execute() === false, 'Stops after HTTP 429');
		checkPrewarmCli(Prewarm::readCheckpoint($checkpoint) === 1, 'Failure preserves last completed ID');
		checkPrewarmCli(str_contains(implode('', $GLOBALS['prewarmOutput']), 'HTTP_429 retry-after=718s'),
			'Rate-limit stop reports Retry-After without making a premature retry');
		unset($GLOBALS['prewarmFailUrl']);
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['resume'=>true,'delay-ms'=>'250']);
		checkPrewarmCli($runner->execute() === true, 'Retry after rate limit');
		checkPrewarmCli(!is_file($checkpoint), 'Recovered checkpoint removed');
		$GLOBALS['prewarmRequests'] = [];
		$GLOBALS['prewarmGoneUrl'] = 'https://fr.example.test/wiki/Two';
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['delay-ms'=>'250']);
		checkPrewarmCli($runner->execute() === true, '404 does not abort prewarm');
		checkPrewarmCli(count($GLOBALS['prewarmRequests']) === 4, '404 page is skipped and remaining pages are visited');
		unset($GLOBALS['prewarmGoneUrl']);
		$GLOBALS['prewarmRequests'] = [];
		$GLOBALS['prewarmRetryOnceUrl'] = 'https://fr.example.test/wiki/Two';
		$GLOBALS['prewarmRetryCalls'] = 0;
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['retries'=>'1','delay-ms'=>'250']);
		checkPrewarmCli($runner->execute() === true, 'Transient HTTP 429 may be retried once');
		checkPrewarmCli($GLOBALS['prewarmRetryCalls'] === 2 && count($GLOBALS['prewarmRequests']) === 5,
			'Retry sends exactly one extra request without skipping other pages');
		unset($GLOBALS['prewarmRetryOnceUrl'], $GLOBALS['prewarmRetryCalls']);
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['recursive'=>true]);
		try {
			$runner->execute();
			throw new RuntimeException('Recursive mode without category should fail');
		} catch (RuntimeException $e) {
			checkPrewarmCli(str_contains($e->getMessage(), '--recursive requires --category'),
				'Recursive mode refuses missing category');
		}
		$GLOBALS['prewarmRequests'] = [];
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['namespace'=>'6','delay-ms'=>'250']);
		checkPrewarmCli($runner->execute() === true, 'Namespace filter succeeds');
		checkPrewarmCli(count($GLOBALS['prewarmRequests']) === 1 &&
			str_contains($GLOBALS['prewarmRequests'][0][0], '/Three'), 'Namespace filter is respected');

		$GLOBALS['prewarmRequests'] = [];
		// A single targeted HTTP request must not pay the bulk-mode tail delay.
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['page-id' => '2', 'delay-ms' => '2000']);
		$targetStart = microtime(true);
		checkPrewarmCli($runner->execute() === true, 'Targeted page-id prewarming succeeds');
		checkPrewarmCli(microtime(true) - $targetStart < 1.5,
			'A single target avoids the two-second post-request sleep');
		checkPrewarmCli(count($GLOBALS['prewarmRequests']) === 1 &&
			str_contains($GLOBALS['prewarmRequests'][0][0], '/Two'),
			'Targeted prewarm fetches the exact page only');
		// SMW supplies its own Article subclass for Property and Concept pages.
		// These would produce NO_HEADER over HTTP; explicitly skip without GET.
		$GLOBALS['prewarmServices']->appendPrimaryPage(6, SMW_NS_PROPERTY, 'Debate');
		$GLOBALS['prewarmServices']->appendPrimaryPage(7, SMW_NS_CONCEPT, 'Concept_demo');
		foreach ([6, 7] as $smwPageId) {
			$GLOBALS['prewarmRequests'] = [];
			$GLOBALS['prewarmOutput'] = [];
			$runner = new PrewarmMinervaFileCache();
			$runner->setOptions(['page-id' => (string)$smwPageId,
				'device' => 'iphone', 'skip-valid' => true, 'delay-ms' => '250']);
			checkPrewarmCli($runner->execute() === true &&
				count($GLOBALS['prewarmRequests']) === 0 &&
				str_contains(implode('', $GLOBALS['prewarmOutput']), '"SKIPPED_SMW_ARTICLE":1'),
				'SMW-managed Article is explicitly skipped with zero anonymous HTTP requests');
		}
		// Flow board pages have dynamic parser output (expiry zero), even
		// when their Article object is our CachedArticle. Only the explicit
		// Flow model, not every talk page, can be skipped before HTTP.
		$GLOBALS['prewarmServices']->appendPrimaryPage(10, 1, 'Flow_talk', 'flow-board');
		$GLOBALS['prewarmServices']->appendPrimaryPage(11, 1, 'Ordinary_talk', 'wikitext');
		$GLOBALS['prewarmRequests'] = [];
		$GLOBALS['prewarmOutput'] = [];
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['page-id' => '10', 'device' => 'android',
			'skip-valid' => true, 'delay-ms' => '250']);
		checkPrewarmCli($runner->execute() === true &&
			count($GLOBALS['prewarmRequests']) === 0 &&
			str_contains(implode('', $GLOBALS['prewarmOutput']), '"SKIPPED_FLOW_BOARD":1'),
			'Flow board is explicitly skipped with zero HTTP and no mobile cache file');
		$GLOBALS['prewarmRequests'] = [];
		$GLOBALS['prewarmOutput'] = [];
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['page-id' => '11', 'device' => 'android', 'delay-ms' => '250']);
		checkPrewarmCli($runner->execute() === true &&
			count($GLOBALS['prewarmRequests']) === 1 &&
			str_contains(implode('', $GLOBALS['prewarmOutput']), '"MISS":1'),
			'Ordinary wikitext talk pages remain eligible for anonymous mobile prewarm');
		// The custom SMW restriction must never accidentally suppress other
		// non-main namespaces such as project or categories.
		foreach ([8 => 4, 9 => 14] as $regularId => $regularNamespace) {
			$GLOBALS['prewarmServices']->appendPrimaryPage($regularId,
				$regularNamespace, 'Other_namespace_page_' . $regularId);
			$GLOBALS['prewarmRequests'] = [];
			$GLOBALS['prewarmOutput'] = [];
			$runner = new PrewarmMinervaFileCache();
			$runner->setOptions(['page-id' => (string)$regularId, 'delay-ms' => '250']);
			checkPrewarmCli($runner->execute() === true &&
				count($GLOBALS['prewarmRequests']) === 1 &&
				str_contains(implode('', $GLOBALS['prewarmOutput']), '"MISS":1'),
				'Project and Category pages are still requested for Minerva prewarming');
		}
		// Simulate replica lag after a move: same page ID, stale old title
		// in replica, current new title on the primary. Only the targeted
		// command must select the current title, without warming the old URL.
		$GLOBALS['prewarmServices']->setPrimaryPageTitle(2, 'Two_moved');
		$GLOBALS['prewarmRequests'] = [];
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['page-id' => '2', 'delay-ms' => '250']);
		checkPrewarmCli($runner->execute() === true &&
			count($GLOBALS['prewarmRequests']) === 1 &&
			str_contains($GLOBALS['prewarmRequests'][0][0], '/Two_moved'),
			'Single-page Minerva prewarm resolves its target against PRIMARY after a move');
		$GLOBALS['prewarmRequests'] = [];
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['limit' => '2', 'delay-ms' => '250']);
		checkPrewarmCli($runner->execute() === true &&
			str_contains($GLOBALS['prewarmRequests'][1][0], '/Two') &&
			!str_contains($GLOBALS['prewarmRequests'][1][0], '/Two_moved'),
			'Bulk Minerva scans keep using the replica connection');

		foreach (['android', 'iphone'] as $device) {
			$GLOBALS['prewarmRequests'] = [];
			$GLOBALS['prewarmOutput'] = [];
			$runner = new PrewarmMinervaFileCache();
			$runner->setOptions(['page-id' => '4', 'device' => $device,
				'skip-valid' => true, 'delay-ms' => '250']);
			checkPrewarmCli($runner->execute() === true && count($GLOBALS['prewarmRequests']) === 0,
				'Redirect page IDs do not generate a mobile request');
			checkPrewarmCli(str_contains(implode('', $GLOBALS['prewarmOutput']),
				'Completed 1 pages; {"BYPASS":1,"bypassReasons":{"redirect":1}}'),
				'Explicit PRIMARY redirect uses the existing coordinator BYPASS-redirect contract');
		}
		$GLOBALS['prewarmOutput'] = [];
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['page-id' => '999999', 'skip-valid' => true]);
		checkPrewarmCli($runner->execute() === true && count($GLOBALS['prewarmRequests']) === 0 &&
			str_contains(implode('', $GLOBALS['prewarmOutput']), 'Completed 0 pages; {"bypassReasons":[]}'),
			'A missing target must never be falsely reported as a verified redirect or acknowledged');
		$GLOBALS['prewarmOutput'] = [];
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['page-id' => '4', 'dry-run' => true]);
		checkPrewarmCli($runner->execute() === true &&
			str_contains(implode('', $GLOBALS['prewarmOutput']), 'Completed 0 pages;'),
			'Targeted preview continues to exclude redirects');
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['page-id' => '0']);
		try {
			$runner->execute();
			throw new RuntimeException('Zero page ID was not refused');
		} catch (RuntimeException $e) {
			checkPrewarmCli(str_contains($e->getMessage(), '--page-id'),
				'Invalid page-id is rejected');
		}
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['page-id' => '2', 'resume' => true]);
		try {
			$runner->execute();
			throw new RuntimeException('Targeted mode combined with resume');
		} catch (RuntimeException $e) {
			checkPrewarmCli(str_contains($e->getMessage(), '--page-id cannot be combined'),
				'Per-page mode refuses incompatible global checkpoint');
		}

		echo json_encode(['assertions'=>$GLOBALS['assertions'],'result'=>'PASS']) . "\n";
	} finally {
		foreach (glob($root . '/*') as $path) {
			if (is_dir($path)) {
				foreach (glob($path . '/*') as $file) { unlink($file); }
				rmdir($path);
			} else { unlink($path); }
		}
		rmdir($root);
	}
}
