<?php
require_once __DIR__ . '/../../ops/seo/LegacySeoCacheInventory.php';

function same($expected, $actual): void {
	if ($expected !== $actual) {
		throw new RuntimeException(var_export([$expected, $actual], true));
	}
}
function graph(array $data, string $head = ''): string {
	return '<html><head>' . $head . '</head><body><script type="application/ld+json">' .
		json_encode($data) . '</script></body></html>';
}
$old = graph(['@type' => 'QAPage']);
same(['historical-qa-graph'], LegacySeoCacheInventory::inspect($old, 'fr')['reasons']);
same(['claim-without-learning-resource'],
	LegacySeoCacheInventory::inspect(graph(['@type' => 'Article', 'mainEntity' => ['@type' => 'Claim']]), 'fr')['reasons']);
$new = graph(['@graph' => [['@type' => ['WebPage', 'LearningResource']], ['@type' => 'Claim']]]);
same('current-marker', LegacySeoCacheInventory::inspect($new, 'en')['status']);
$broken = '<meta content="' . LegacySeoCacheInventory::OLD_EN_IMAGE . '" property="og:image">';
same(['broken-en-social-image'], LegacySeoCacheInventory::inspect(graph(['@type' => 'WebPage'], $broken), 'en')['reasons']);
same('other', LegacySeoCacheInventory::inspect('<html><head></head><body>' .
	LegacySeoCacheInventory::OLD_EN_IMAGE . ' QAPage</body></html>', 'en')['status']);
same('unverified', LegacySeoCacheInventory::inspect('<script type="application/ld+json">{</script>', 'fr')['status']);

$root = sys_get_temp_dir() . '/wd-seo-cache-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$key = hash('sha256', 'Page');
$dir = $root . '/' . hash('sha256', 'enwikidebates') . '/' . substr($key, 0, 2) . '/' . $key;
mkdir($dir, 0700, true);
$generation = str_repeat('a', 32);
$identity = ['revision' => 7, 'touched' => '20261124200000', 'epoch' => '2',
	'coreEpoch' => '20261124122057', 'format' => 'MinervaFileCache-0.1.0'];
file_put_contents($dir . '/generation', $generation);
$entry = ['generation' => $generation, 'identity' => $identity, 'created' => time(),
	'expires' => PHP_INT_MAX, 'body' => $old, 'headers' => []];
file_put_contents($dir . '/one.cache', gzencode(json_encode($entry)));
$entry['identity']['revision'] = 6;
file_put_contents($dir . '/expired.cache', gzencode(json_encode($entry)));
try {
	$result = LegacySeoCacheInventory::mobile($root, 'enwikidebates', 'Page', $identity, 0, 4194304, 'en');
	same(1, $result['counts']['candidate']);
	same(1, $result['counts']['not-current']);
	same(['historical-qa-graph'], $result['reasons']);
	same(null, LegacySeoCacheInventory::read($dir . '/one.cache', $root, 1));
	same(null, LegacySeoCacheInventory::read($dir . '/one.cache', $dir . '/other', 10000));
	$result = LegacySeoCacheInventory::mobile($root, 'enwikidebates', 'Page', $identity, 0, 4194304, 'en', 1);
	same(true, $result['incomplete']);
} finally {
	foreach (glob($dir . '/*') as $file) {
		unlink($file);
	}
	for ($path = $dir; $path !== $root; $path = dirname($path)) {
		rmdir($path);
	}
	rmdir($root);
}
echo "OK: offline legacy SEO markers, current cache identity, bounded reads and incomplete variants\n";
