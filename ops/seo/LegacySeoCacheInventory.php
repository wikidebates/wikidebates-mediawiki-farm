<?php

/** Offline observations only. No parser, HTTP request, cache write or purge. */
final class LegacySeoCacheInventory {
	public const OLD_EN_IMAGE = 'https://en.wikidebates.org/w/resources/assets/logo-Wikidebates-Twitter.png';

	/** Bounded private-file read; discard changing, linked or oversized files. */
	public static function read(string $path, string $root, int $limit, bool $gzip = false): ?string {
		clearstatcache(true, $path);
		$real = realpath($path);
		if ($real === false || is_link($path) || !str_starts_with($real, $root . '/') || !is_file($real)) {
			return null;
		}
		$before = @stat($real);
		if (!$before || $before['size'] > $limit) {
			return null;
		}
		$handle = $gzip ? @gzopen($real, 'rb') : @fopen($real, 'rb');
		if (!$handle) {
			return null;
		}
		try {
			$data = @stream_get_contents($handle, $limit + 1);
		} finally {
			fclose($handle);
		}
		clearstatcache(true, $real);
		$after = @stat($real);
		if (!$after || !is_string($data) || strlen($data) > $limit) {
			return null;
		}
		foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $key) {
			if ($before[$key] !== $after[$key]) {
				return null;
			}
		}
		return $data;
	}

	private static function types(array $node, array &$types): void {
		foreach ((array)($node['@type'] ?? []) as $type) {
			if (is_string($type)) {
				$types[$type] = true;
			}
		}
		foreach ($node as $key => $value) {
			if ($key !== '@type' && is_array($value)) {
				self::types($value, $types);
			}
		}
	}

	/** Positive historical markers, not an exhaustive Schema.org validator. */
	public static function inspect(string $html, string $lang): array {
		$reasons = [];
		$unknown = false;
		$types = [];
		if ($lang === 'en' && str_contains($html, self::OLD_EN_IMAGE)) {
			$headEnd = stripos($html, '</head>');
			if ($headEnd === false) {
				$unknown = true;
			} else {
				$head = substr($html, 0, $headEnd);
				if (preg_match_all('~<meta\b[^>]*>~i', $head, $tags) === false) {
					$unknown = true;
				} else {
					foreach ($tags[0] as $tag) {
						preg_match_all('~\b(name|property|content)\s*=\s*(["\x27])(.*?)\2~is', $tag, $attrs, PREG_SET_ORDER);
						$values = [];
						foreach ($attrs as $attr) {
							$values[strtolower($attr[1])] = html_entity_decode($attr[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
						}
						if (in_array(strtolower($values['property'] ?? $values['name'] ?? ''),
							['og:image', 'og:image:secure_url', 'twitter:image'], true) &&
							($values['content'] ?? null) === self::OLD_EN_IMAGE) {
							$reasons['broken-en-social-image'] = true;
						}
					}
				}
			}
		}
		if (stripos($html, 'application/ld+json') !== false) {
			$count = preg_match_all(
				'~<script\b[^>]*\btype\s*=\s*(["\x27])application/ld\+json\1[^>]*>(.*?)</script\s*>~is',
				$html, $scripts, PREG_SET_ORDER);
			if (!$count) {
				$unknown = true;
			} else {
				foreach ($scripts as $script) {
					try {
						$graph = json_decode($script[2], true, 64, JSON_THROW_ON_ERROR);
						if (!is_array($graph)) {
							$unknown = true;
							continue;
						}
						self::types($graph, $types);
					} catch (JsonException $e) {
						$unknown = true;
					}
				}
			}
		}
		if (isset($types['QAPage'])) {
			$reasons['historical-qa-graph'] = true;
		}
		if (isset($types['Claim']) && !isset($types['LearningResource'])) {
			$reasons['claim-without-learning-resource'] = true;
		}
		return [
			'status' => $reasons ? 'candidate' : ($unknown ? 'unverified' :
				(isset($types['WebPage'], $types['LearningResource']) ? 'current-marker' : 'other')),
			'reasons' => array_keys($reasons),
		];
	}

	/** Read the same on-disk identity and generation used by MinervaFileCache. */
	public static function mobile(string $root, string $wiki, string $title, array $identity,
		int $ttl, int $maxBytes, string $lang, int $maxVariants = 64): array {
		$key = hash('sha256', $title);
		$dir = $root . '/' . hash('sha256', $wiki) . '/' . substr($key, 0, 2) . '/' . $key;
		$result = ['counts' => [], 'reasons' => [], 'incomplete' => false];
		$count = static function (string $status) use (&$result): void {
			$result['counts'][$status] = ($result['counts'][$status] ?? 0) + 1;
		};
		if (!is_dir($dir)) {
			return $result;
		}
		$generation = self::read($dir . '/generation', $root, 128);
		if (!is_string($generation) || !preg_match('/\A[a-f0-9]{32}\z/D', $generation)) {
			$count('unverified');
			return $result;
		}
		$files = glob($dir . '/*.cache', GLOB_NOSORT);
		if ($files === false) {
			$count('unverified');
			return $result;
		}
		$result['incomplete'] = count($files) > $maxVariants;
		$limit = $maxBytes * 2;
		foreach (array_slice($files, 0, $maxVariants) as $file) {
			$raw = self::read($file, $root, $limit);
			$decoded = $raw === null ? false : @gzdecode($raw, $limit);
			$entry = $decoded === false ? null : json_decode($decoded, true);
			if (!is_array($entry) || !is_int($entry['created'] ?? null) ||
				!is_int($entry['expires'] ?? null) || !is_string($entry['body'] ?? null) ||
				!is_array($entry['headers'] ?? null)) {
				$count('unverified');
				continue;
			}
			if (($entry['generation'] ?? null) !== $generation ||
				($entry['identity'] ?? null) !== $identity ||
				($ttl > 0 && $entry['created'] + $ttl <= time())) {
				$count('not-current');
				continue;
			}
			$observation = self::inspect($entry['body'], $lang);
			$count($observation['status']);
			$result['reasons'] = array_values(array_unique(array_merge($result['reasons'], $observation['reasons'])));
		}
		if (self::read($dir . '/generation', $root, 128) !== $generation) {
			return ['counts' => ['raced' => 1], 'reasons' => [], 'incomplete' => true];
		}
		return $result;
	}
}
