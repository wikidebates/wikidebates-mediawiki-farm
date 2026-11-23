<?php

/**
 * Test a single-page anonymous prewarm through a disposable local HTTP server.
 * This script refuses unmarked production directories and never uses live URLs.
 */
$root = getenv('MINERVA_TEST_ROOT');
if (!$root || !is_file($root . '/.minerva-file-cache-lab') ||
	!is_file($root . '/state/minerva_file_cache_test.sqlite')) {
	throw new RuntimeException('A marked, initialized disposable Minerva laboratory is required');
}
$state = $root . '/state';
if (is_file($state . '/extension-enabled')) {
	throw new RuntimeException('Targeted HTTP integration requires fresh lab state');
}
require dirname(__DIR__) . '/src/Prewarm.php';

use MediaWiki\Extension\MinervaFileCache\Prewarm;

$database = new PDO('sqlite:' . $state . '/minerva_file_cache_test.sqlite');
$targetId = (int)$database->query("SELECT page_id FROM page WHERE page_title = 'Cache_study_argument'")->fetchColumn();
if ($targetId < 1) { throw new RuntimeException('Missing prepared test page'); }

$server = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php',
	'-d', 'output_buffering=0', '-S', '127.0.0.1:8080', '-t', $root . '/mediawiki'],
	[['pipe', 'r'], ['file', $state . '/targeted-prewarm-server.log', 'a'],
		['file', $state . '/targeted-prewarm-server.log', 'a']], $pipes);
if (!is_resource($server)) { throw new RuntimeException('Cannot start isolated HTTP server'); }
fclose($pipes[0]);

try {
	$ready = false;
	for ($i = 0; $i < 50; $i++) {
		$socket = @fsockopen('127.0.0.1', 8080, $errno, $error, 0.1);
		if ($socket) { fclose($socket); $ready = true; break; }
		usleep(100000);
	}
	if (!$ready) { throw new RuntimeException('Loopback server was not ready'); }
	if (!touch($state . '/extension-enabled')) {
		throw new RuntimeException('Cannot enable disposable extension');
	}
	putenv('MW_INSTALL_PATH=' . $root . '/mediawiki');
	// CLI maintenance insists on a readable config file. The lab's marked
	// bootstrap callback supplies its actual isolated SQLite configuration.
	$localSettings = $state . '/LocalSettings.php';
	file_put_contents($localSettings, "<?php\n");
	chmod($localSettings, 0600);

	$command = [PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php',
		dirname(__DIR__) . '/maintenance/prewarm.php', '--conf=' . $localSettings, '--page-id=' . $targetId, '--delay-ms=250'];
	$process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
	if (!is_resource($process)) { throw new RuntimeException('Cannot start single-page prewarm'); }
	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	if (proc_close($process) !== 0) {
		throw new RuntimeException('Single-page prewarm failed: ' . substr($stdout . $stderr, -1500));
	}
	if (!str_contains($stdout, 'Completed 1 pages')) {
		throw new RuntimeException('Single-page prewarm did not prepare exactly one record: ' . $stdout);
	}

	$url = 'http://127.0.0.1:8080/index.php?title=Cache_study_argument';
	$context = stream_context_create(['http' => [
		'method' => 'GET', 'ignore_errors' => true, 'follow_location' => 0,
		'timeout' => 20,
		'header' => ['User-Agent: ' . Prewarm::userAgent('android'),
			'Accept-Language: en', 'Accept-Encoding: identity']
	]]);
	$html = file_get_contents($url, false, $context);
	$headers = [];
	foreach ($http_response_header ?? [] as $header) {
		if (!str_contains($header, ':')) { continue; }
		[$name, $value] = explode(':', $header, 2);
		$headers[strtolower($name)][] = trim($value);
	}
	if (!is_string($html) || !str_contains($html, 'ARGUMENT_VERSION_ONE') ||
		!str_contains($html, 'skin-minerva') ||
		($headers['x-minerva-file-cache'][0] ?? '') !== 'HIT') {
		throw new RuntimeException('Targeted prewarm did not produce a reusable anonymous Minerva file');
	}
	$files = glob($state . '/mobile-cache/*/*/*/*.cache') ?: [];
	if (count($files) !== 1) {
		throw new RuntimeException('Single-page prewarm unexpectedly created ' . count($files) . ' cache files');
	}
	$invokeSkip = static function (string $device) use ($localSettings, $targetId): array {
		$args = [PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php',
			dirname(__DIR__) . '/maintenance/prewarm.php', '--conf=' . $localSettings,
			'--page-id=' . $targetId, '--device=' . $device,
			'--skip-valid', '--delay-ms=250'];
		$child = proc_open($args, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $childPipes);
		if (!is_resource($child)) { throw new RuntimeException('Cannot start selective prewarm'); }
		fclose($childPipes[0]);
		$out = stream_get_contents($childPipes[1]);
		$err = stream_get_contents($childPipes[2]);
		fclose($childPipes[1]);
		fclose($childPipes[2]);
		return ['exit' => proc_close($child), 'output' => $out, 'error' => $err];
	};
	$beforeHash = hash_file('sha256', $files[0]);
	$local = $invokeSkip('android');
	if ($local['exit'] !== 0 || !str_contains($local['output'], '"LOCAL_HIT":1') ||
		hash_file('sha256', $files[0]) !== $beforeHash ||
		count(glob($state . '/mobile-cache/*/*/*/*.cache') ?: []) !== 1) {
		$entry = json_decode((string)gzdecode((string)file_get_contents($files[0])), true);
		$actualVariant = json_decode((string)($entry['variant'] ?? ''), true);
		$expectedVariant = json_decode(Prewarm::targetVariant(
			'http://127.0.0.1:8080/index.php?title=Cache_study_argument',
			'http://127.0.0.1:8080', 'en', Prewarm::userAgent('android')
		), true);
		$dbrow = $database->query('SELECT page_latest, page_touched FROM page WHERE page_id=' . $targetId)->fetch(PDO::FETCH_ASSOC);
		throw new RuntimeException('Selective Android prewarm rewrote a valid file or made an HTTP GET: ' .
			substr($local['output'] . $local['error'], -1000) .
			' variant.actual=' . json_encode($actualVariant) .
			' variant.expected=' . json_encode($expectedVariant) .
			' identity.actual=' . json_encode($entry['identity'] ?? null) .
			' page.primary=' . json_encode($dbrow));
	}
	$iphone = $invokeSkip('iphone');
	if ($iphone['exit'] !== 0 || !str_contains($iphone['output'], '"MISS":1') ||
		count(glob($state . '/mobile-cache/*/*/*/*.cache') ?: []) !== 2) {
		throw new RuntimeException('Missing iPhone variant must be generated independently: ' .
			substr($iphone['output'] . $iphone['error'], -1000));
	}
	echo json_encode(['result' => 'PASS', 'pageId' => $targetId,
		'cachedFiles' => 2, 'httpCache' => 'HIT', 'localAndroid' => 'LOCAL_HIT', 'iphone' => 'MISS'], JSON_THROW_ON_ERROR) . "\n";
} finally {
	if (is_file($state . '/extension-enabled')) { unlink($state . '/extension-enabled'); }
	proc_terminate($server);
	proc_close($server);
}
