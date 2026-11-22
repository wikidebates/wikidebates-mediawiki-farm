<?php

/**
 * Read-only HTTP comparison against a marked, disposable Minerva lab.
 * Never point this at a production wiki. Nothing is published or deleted.
 */
$root = getenv('MINERVA_TEST_ROOT');
if (!$root || !is_file($root . '/.minerva-file-cache-lab') ||
	!is_file($root . '/state/minerva_file_cache_test.sqlite')) {
	throw new RuntimeException('A previously initialized disposable Minerva laboratory is required');
}

$state = $root . '/state';
$flag = $state . '/extension-enabled';
$createdFlag = !is_file($flag);
$cases = [
	'chrome-android-130' => [
		'ua' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
		'acceptLanguage' => 'en-US,en;q=0.9',
	],
	'chrome-android-131' => [
		'ua' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
		'acceptLanguage' => 'en-US,en;q=0.9',
	],
	'firefox-android' => [
		'ua' => 'Mozilla/5.0 (Android 14; Mobile; rv:132.0) Gecko/132.0 Firefox/132.0',
		'acceptLanguage' => 'en-US,en;q=0.9',
	],
	'safari-iphone' => [
		'ua' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
		'acceptLanguage' => 'en-US,en;q=0.9',
	],
	'safari-ipad' => [
		'ua' => 'Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
		'acceptLanguage' => 'en-US,en;q=0.9',
	],
	'chrome-android-tablet' => [
		'ua' => 'Mozilla/5.0 (Linux; Android 14; Pixel Tablet) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
		'acceptLanguage' => 'en-US,en;q=0.9',
	],
	'chrome-android-fr-language' => [
		'ua' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
		'acceptLanguage' => 'fr-FR,fr;q=0.9',
	],
];

$normalizeHtml = static function (string $html): ?string {
	$requestIds = 0;
	$timings = 0;
	$normalized = preg_replace_callback('~<script\b[^>]*>.*?</script\s*>~is',
		static function (array $match) use (&$requestIds, &$timings): string {
			$script = preg_replace_callback('~("wgRequestId"\s*:\s*")[^"]*(")~',
				static function (array $value) use (&$requestIds): string {
					$requestIds++;
					return $value[1] . '__REQUEST_ID__' . $value[2];
				}, $match[0]);
			if ($script === null) { return $match[0]; }
			return preg_replace_callback('~("wgBackendResponseTime"\s*:\s*)[0-9]+(?:\.[0-9]+)?(?=\s*[,}])~',
				static function (array $value) use (&$timings): string {
					$timings++;
					return $value[1] . '0';
				}, $script) ?? $match[0];
		}, $html);
	return $normalized !== null && $requestIds === 1 && $timings === 1
		? hash('sha256', $normalized) : null;
};

$headersDigest = static function (array $headers): string {
	$relevant = ['content-type', 'content-language', 'vary', 'link', 'x-frame-options',
		'content-security-policy', 'content-security-policy-report-only', 'referrer-policy',
		'x-content-type-options', 'permissions-policy', 'cross-origin-opener-policy',
		'cross-origin-resource-policy', 'cross-origin-embedder-policy'];
	$selected = array_intersect_key($headers, array_flip($relevant));
	ksort($selected);
	return hash('sha256', json_encode($selected, JSON_THROW_ON_ERROR));
};

$fetch = static function (string $ua, string $language): array {
	$url = 'http://127.0.0.1:8080/index.php?title=Cache_study_argument';
	$headers = ['User-Agent: ' . $ua, 'Accept-Language: ' . $language, 'Accept-Encoding: identity'];
	$context = stream_context_create(['http' => [
		'method' => 'GET', 'ignore_errors' => true, 'follow_location' => 0,
		'timeout' => 15, 'header' => $headers,
	]]);
	$start = hrtime(true);
	$html = @file_get_contents($url, false, $context);
	if (!is_string($html)) { throw new RuntimeException('Loopback request failed'); }
	$received = [];
	foreach ($http_response_header ?? [] as $line) {
		if (!str_contains($line, ':')) { continue; }
		[$name, $value] = explode(':', $line, 2);
		$received[strtolower($name)][] = trim($value);
	}
	return [
		'status' => (int)(explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1] ?? 0),
		'headers' => $received,
		'html' => $html,
		'elapsedMs' => round((hrtime(true) - $start) / 1000000, 2),
	];
};

$process = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'zlib.output_compression=0',
	'-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php', '-S', '127.0.0.1:8080',
	'-t', $root . '/mediawiki'],
	[['pipe', 'r'], ['file', $state . '/ua-matrix-server.log', 'a'],
		['file', $state . '/ua-matrix-server.log', 'a']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot launch disposable loopback server'); }
fclose($pipes[0]);

try {
	$ready = false;
	for ($i = 0; $i < 50; $i++) {
		$socket = @fsockopen('127.0.0.1', 8080, $errno, $error, 0.1);
		if ($socket) { fclose($socket); $ready = true; break; }
		usleep(100000);
	}
	if (!$ready) { throw new RuntimeException('Disposable HTTP server did not start'); }
	if ($createdFlag && !touch($flag)) { throw new RuntimeException('Cannot enable disposable cache'); }
	$report = ['environment' => 'disposable-Minerva-laboratory', 'samples' => [],
		'candidates' => [], 'note' => 'Matching hashes are observations, never authorization to collapse User-Agent cache keys.'];
	foreach ($cases as $name => $case) {
		$first = $fetch($case['ua'], $case['acceptLanguage']);
		$second = $fetch($case['ua'], $case['acceptLanguage']);
		$bodyClass = '';
		preg_match('/<body[^>]*class="([^"]+)"/', $second['html'], $matches);
		$bodyClass = $matches[1] ?? '';
		$report['samples'][$name] = [
			'firstHttpStatus' => $first['status'],
			'secondHttpStatus' => $second['status'],
			'firstCacheState' => $first['headers']['x-minerva-file-cache'][0] ?? null,
			'secondCacheState' => $second['headers']['x-minerva-file-cache'][0] ?? null,
			'minerva' => str_contains($bodyClass, 'skin-minerva'),
			'firstRequestMs' => $first['elapsedMs'],
			'secondRequestMs' => $second['elapsedMs'],
			'stableReplay' => $first['html'] === $second['html'],
			'htmlSha256' => hash('sha256', $second['html']),
			'normalizedHtmlSha256' => $normalizeHtml($second['html']),
			'replayedHeadersSha256' => $headersDigest($second['headers']),
			'vary' => $second['headers']['vary'] ?? [],
		];
	}
	$names = array_keys($report['samples']);
	foreach ($names as $i => $name) {
		for ($j = $i + 1; $j < count($names); $j++) {
			$other = $names[$j];
			$a = $report['samples'][$name];
			$b = $report['samples'][$other];
			if (!$a['minerva'] || !$b['minerva'] ||
				$a['secondHttpStatus'] !== 200 || $b['secondHttpStatus'] !== 200) {
				continue;
			}
			$report['candidates'][] = [
				'pair' => [$name, $other],
				'normalizedHtmlComparable' => $a['normalizedHtmlSha256'] !== null &&
					$b['normalizedHtmlSha256'] !== null,
				'normalizedHtmlMatches' => $a['normalizedHtmlSha256'] !== null &&
					$a['normalizedHtmlSha256'] === $b['normalizedHtmlSha256'],
				'headersMatch' => $a['replayedHeadersSha256'] === $b['replayedHeadersSha256'],
			];
		}
	}
	file_put_contents($state . '/ua-matrix.json',
		json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
	echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} finally {
	if ($createdFlag && is_file($flag)) { unlink($flag); }
	proc_terminate($process);
	proc_close($process);
}
