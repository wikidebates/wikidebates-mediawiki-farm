<?php

if (PHP_SAPI !== 'cli') {
	exit(1);
}
require_once dirname(__DIR__, 2) . '/maintenance/Maintenance.php';
require_once __DIR__ . '/LegacySeoCacheInventory.php';

use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;

/**
 * Observe already stored, currently eligible cache identities for ns0.
 * This is deliberately not a purge/rebuild command.
 */
class WikidebatesAuditLegacySeoCaches extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Read existing Vector view and Minerva files for known historical SEO markers.');
		$this->addOption('after-id', 'Continue after this page_id (default 0).', false, true);
		$this->addOption('limit', 'Maximum current nonredirect main-space pages, default 2000, max 50000.', false, true);
		$this->addOption('max-seconds', 'Time budget in seconds, default 300, max 1200.', false, true);
		$this->addOption('max-load', 'Maximum 1-minute system load (default 6).', false, true);
	}

	private function number(string $name, int $default, int $min, int $max): int {
		$value = (string)$this->getOption($name, $default);
		if (!ctype_digit($value) || (int)$value < $min || (int)$value > $max) {
			throw new RuntimeException('Invalid --' . $name);
		}
		return (int)$value;
	}

	private function privateRoot(mixed $value): string {
		$root = is_string($value) && $value !== '' ? realpath($value) : false;
		$web = realpath(MW_INSTALL_PATH);
		if (!$root || !is_dir($root) || !$web || $root === '/' ||
			$root === $web || str_starts_with($root, $web . '/')) {
			throw new RuntimeException('Expected an existing private cache directory outside MediaWiki.');
		}
		return $root;
	}

	public function execute() {
		$services = $this->getServiceContainer();
		$services->getReadOnlyMode()->setReason('Offline SEO cache inventory');
		$config = $services->getMainConfig();
		$lang = $services->getContentLanguage()->getCode();
		if (!in_array($lang, ['fr', 'en'], true) || !$config->get('UseFileCache')) {
			throw new RuntimeException('Use only FR/EN with their normal file-cache configuration.');
		}
		$vectorRoot = $this->privateRoot($config->get('FileCacheDirectory'));
		$mobileRoot = $this->privateRoot($config->get('MinervaFileCacheDirectory'));
		$maxBytes = (int)$config->get('MinervaFileCacheMaxBytes');
		$ttl = (int)$config->get('MinervaFileCacheTTL');
		if ($maxBytes < 512 || $maxBytes > 32 * 1024 * 1024 || $ttl < 0) {
			throw new RuntimeException('Unsupported mobile cache limits.');
		}
		$after = $this->number('after-id', 0, 0, PHP_INT_MAX);
		$limit = $this->number('limit', 2000, 1, 50000);
		$budget = $this->number('max-seconds', 300, 10, 1200);
		$maxLoad = $this->number('max-load', 6, 1, 256);
		$wiki = WikiMap::getCurrentWikiId();
		$db = $services->getConnectionProvider()->getPrimaryDatabase();
		$rows = $db->newSelectQueryBuilder()
			->select(['page_id', 'page_title', 'page_latest', 'page_touched'])
			->from('page')
			->where(['page_namespace' => NS_MAIN, 'page_is_redirect' => 0, $db->expr('page_id', '>', $after)])
			->orderBy('page_id')->limit($limit + 1)->caller(__METHOD__)->fetchResultSet();
		$started = microtime(true);
		$report = [
			'wiki' => $wiki, 'language' => $lang, 'read_only' => true,
			'after_id' => $after, 'next_after' => $after, 'pages' => 0,
			'vector' => [], 'minerva' => [], 'incomplete_mobile_pages' => 0,
			'candidate_count' => 0, 'candidates' => [], 'stop_reason' => 'complete',
		];
		$add = static function (array &$counts, string $key, int $amount = 1): void {
			$counts[$key] = ($counts[$key] ?? 0) + $amount;
		};
		foreach ($rows as $row) {
			if ($report['pages'] >= $limit) {
				$report['stop_reason'] = 'limit';
				break;
			}
			$load = sys_getloadavg();
			if (!is_array($load) || !isset($load[0]) || $load[0] > $maxLoad) {
				$report['stop_reason'] = 'load';
				break;
			}
			if (microtime(true) - $started >= $budget) {
				$report['stop_reason'] = 'time';
				break;
			}
			$id = (int)$row->page_id;
			$title = Title::makeTitleSafe(NS_MAIN, $row->page_title);
			$report['pages']++;
			$report['next_after'] = $id;
			if (!$title) {
				$add($report['vector'], 'unverified');
				$add($report['minerva'], 'unverified');
				continue;
			}
			$view = new class($title, 'view') extends HTMLFileCache {
				public function auditPath(): string {
					return $this->cachePath();
				}
			};
			$desktop = ['status' => 'absent-or-expired', 'reasons' => []];
			if ($view->isCacheGood((string)$row->page_touched)) {
				$path = $view->auditPath();
				$html = LegacySeoCacheInventory::read($path, $vectorRoot, 16 * 1024 * 1024, str_ends_with($path, '.gz'));
				$desktop = $html === null ? ['status' => 'unverified', 'reasons' => []] :
					LegacySeoCacheInventory::inspect($html, $lang);
				unset($html);
			}
			$add($report['vector'], $desktop['status']);
			$identity = [
				'revision' => (int)$row->page_latest, 'touched' => (string)$row->page_touched,
				'epoch' => (string)$config->get('MinervaFileCacheEpoch'),
				'coreEpoch' => $config->get('CacheEpoch'), 'format' => 'MinervaFileCache-0.1.0',
			];
			$mobile = LegacySeoCacheInventory::mobile(
				$mobileRoot, $wiki, $title->getPrefixedDBkey(), $identity, $ttl, $maxBytes, $lang);
			foreach ($mobile['counts'] as $status => $n) {
				$add($report['minerva'], $status, $n);
			}
			if ($mobile['incomplete']) {
				$report['incomplete_mobile_pages']++;
			}
			if ($desktop['reasons'] || $mobile['reasons']) {
				$report['candidates'][] = [
					'page_id' => $id, 'title' => $title->getPrefixedText(),
					'revision' => (int)$row->page_latest, 'touched' => (string)$row->page_touched,
					'vector' => $desktop['reasons'], 'minerva' => $mobile['reasons'],
				];
			}
			if ($report['pages'] % 1000 === 0) {
				fwrite(STDERR, "SEO inventory $lang: " . $report['pages'] .
					" pages, cursor=$id, candidates=" . count($report['candidates']) . "\n");
			}
		}
		$report['candidate_count'] = count($report['candidates']);
		$report['scope'] = 'Positive markers in current ns0 file-cache identities; no parser-only audit, no full SEO certification, no automatic purge.';
		$this->output(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES |
			JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
	}
}

$maintClass = WikidebatesAuditLegacySeoCaches::class;
require_once RUN_MAINTENANCE_IF_MAIN;
