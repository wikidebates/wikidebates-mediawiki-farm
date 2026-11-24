#!/usr/bin/env bash
# Exercise both modes on real, disposable MediaWiki databases and local HTTP APIs.
set -euo pipefail
if [[ "${CI:-}" != true || "${GITHUB_ACTIONS:-}" != true ]]; then
	echo "Only run on a disposable GitHub Actions runner." >&2
	exit 2
fi
root=$(pwd)
config=$(mktemp -d)
langs=(fr en de es it pt)
declare -A ports=([en]=18781 [fr]=18782 [de]=18783 [es]=18784 [it]=18785 [pt]=18786)
pids=()
cleanup() {
	for pid in "${pids[@]}"; do
		if [[ -n "$pid" ]]; then
			kill "$pid" 2>/dev/null || true
		fi
	done
	rm -rf "$config"
}
trap cleanup EXIT
trap 'for log in "$config"/*.log; do if [[ -f "$log" ]]; then tail -n 60 "$log"; fi; done' ERR
test ! -e LocalSettings.php
test -f extensions/Interlanguage/extension.json
test -f skins/Vector/skin.json
test -f extensions/WikiSEO/includes/InterlanguageHreflang.php
test -f skins/MinervaNeue/skin.json
for lang in "${langs[@]}"; do
	mkdir "$config/$lang"
done
export MYSQL_PWD="${INTERLANGUAGE_CI_DB_PASSWORD:?Missing disposable database password}"
sql() {
	mysql --protocol=TCP --host=127.0.0.1 --user=root --batch --skip-column-names "$@"
}
for lang in "${langs[@]}"; do
	sql -e "CREATE DATABASE ill_ci_$lang;"
done
admin_password=$(openssl rand -hex 24)
for lang in "${langs[@]}"; do
	port=${ports[$lang]}
	php maintenance/run.php install \
		--confpath "$config/$lang" --dbtype mysql \
		--dbserver 127.0.0.1 --dbname "ill_ci_$lang" \
		--dbuser root --dbpass "$MYSQL_PWD" \
		--server "http://127.0.0.1:$port" --scriptpath '' \
		--lang "$lang" --skins Vector --pass "$admin_password" \
		"Interlanguage CI $lang" Admin
	case "$lang" in
		fr) project='Wikidébats' ;;
		de) project='Wikidebatten' ;;
		it) project='Wikidibattiti' ;;
		*) project='Wikidebates' ;;
	esac
	printf '\n$wgMetaNamespace = "%s";\n' "$project" >> "$config/$lang/LocalSettings.php"
	cat >> "$config/$lang/LocalSettings.php" <<'PHP'
$wgScriptPath = '';
$wgArticlePath = '/index.php?title=$1';
$wgMainCacheType = CACHE_NONE;
$wgParserCacheType = CACHE_NONE;
$wgMessageCacheType = CACHE_NONE;
$wgJobRunRate = 0;
$wgEnableEmail = false;
$wgShowExceptionDetails = true;
$wgGroupPermissions['*']['purge'] = true;
$wgEnableCanonicalServerLink = true;
// Permit __NOINDEX__ in main-namespace disposable fixtures.
$wgExemptFromUserRobotsControl = [];
$wgWikiSeoDefaultLanguage = null;
$wgWikiSeoDisableLogoFallbackImage = true;
$wgHideInterlanguageLinks = isset( $_GET['illcihide'] );
$wgWikiSeoInterlanguageArticleUrls = [];
foreach ( [
	'en' => [18781, 'Wikidebates'],
	'fr' => [18782, 'Wikidébats'],
	'de' => [18783, 'Wikidebatten'],
	'es' => [18784, 'Wikidebates'],
	'it' => [18785, 'Wikidibattiti'],
	'pt' => [18786, 'Wikidebates'],
] as $illCode => [ $illPort, $illProject ] ) {
	$wgWikiSeoInterlanguageArticleUrls[$illCode] = [
		'article' => 'http://127.0.0.1:' . $illPort . '/index.php?title=$1',
		'namespaceAliases' => [ 'Project' => $illProject, $illProject => $illProject ],
	];
}
unset( $illCode, $illPort, $illProject );
wfLoadExtension( 'WikiSEO' );
wfLoadExtension( 'MobileFrontend' );
wfLoadSkin( 'MinervaNeue' );
// MediaWiki 1.43 uses a namespaced core API module; explicitly register the
// legacy extension module in these fixtures, rather than testing core by mistake.
$wgAutoloadLocalClasses['ApiQueryLangLinks'] = "$IP/extensions/Interlanguage/api/ApiQueryLangLinks.php";
$wgAPIPropModules['langlinks'] = 'ApiQueryLangLinks';
PHP
done

# The production table already exists; no schema updater is provided by this
# extension. Provision only its required columns and key in the fixture.
sql ill_ci_fr <<'SQL'
CREATE TABLE interlanguage_links (
	ill_from INT UNSIGNED NOT NULL,
	ill_lang VARBINARY(20) NOT NULL,
	ill_title VARBINARY(255) NOT NULL,
	PRIMARY KEY (ill_from, ill_lang),
	INDEX ill_lang_title (ill_lang, ill_title)
) ENGINE=InnoDB;
SQL
for lang in "${langs[@]}"; do
	sql "ill_ci_$lang" <<'SQL'
INSERT INTO interwiki (iw_prefix, iw_url, iw_api, iw_wikiid, iw_local, iw_trans)
VALUES
	('en', 'http://127.0.0.1:18781/index.php?title=$1', 'http://127.0.0.1:18781/api.php', '', 1, 0),
	('fr', 'http://127.0.0.1:18782/index.php?title=$1', 'http://127.0.0.1:18782/api.php', '', 1, 0),
	('de', 'http://127.0.0.1:18783/index.php?title=$1', 'http://127.0.0.1:18783/api.php', '', 1, 0),
	('es', 'http://127.0.0.1:18784/index.php?title=$1', 'http://127.0.0.1:18784/api.php', '', 1, 0),
	('it', 'http://127.0.0.1:18785/index.php?title=$1', 'http://127.0.0.1:18785/api.php', '', 1, 0),
	('pt', 'http://127.0.0.1:18786/index.php?title=$1', 'http://127.0.0.1:18786/api.php', '', 1, 0)
ON DUPLICATE KEY UPDATE iw_url=VALUES(iw_url), iw_api=VALUES(iw_api), iw_local=VALUES(iw_local);
SQL
done
cat >> "$config/fr/LocalSettings.php" <<'PHP'
require_once "$IP/extensions/Interlanguage/InterlanguageCentral.php";
wfLoadExtension( 'Interlanguage' );
PHP
for lang in en de es it pt; do
	cat >> "$config/$lang/LocalSettings.php" <<'PHP'
require_once "$IP/extensions/Interlanguage/Interlanguage.php";
$wgInterlanguageExtensionDB = 'ill_ci_fr';
$wgInterlanguageExtensionInterwiki = 'fr:';
$wgInterlanguageExtensionSort = 'alphabetic';
PHP
done

edit_page() {
	local lang=$1 title=$2 content=$3
	printf '%s\n' "$content" | MW_CONFIG_FILE="$config/$lang/LocalSettings.php" \
		php maintenance/run.php edit --user Admin --summary 'Disposable integration fixture' "$title"
}
edit_page fr 'French ordinary' 'French body [[en:English ordinary]]'
edit_page fr 'Debat:Exemple' 'Synthetic debate [[en:Debate:Example]]'
edit_page fr 'Argument:Exemple' 'Synthetic argument [[en:Argument:Example]]'
edit_page fr 'Informations institutionnelles' 'Synthetic institutional page [[en:Institutional information]]'
edit_page fr 'Shared page' 'Central same-title fixture'
edit_page en 'English ordinary' 'English body'
edit_page en 'Debate:Example' 'Synthetic debate'
edit_page en 'Argument:Example' 'Synthetic argument'
edit_page en 'Institutional information' 'Synthetic institutional page'
edit_page en 'Shared page' 'Dependent same-title fixture'

# A native translation and a same-title fallback must form one reciprocal cluster.
edit_page fr 'Mixed fallback fixture' 'Central mixed fixture [[en:Explicit translation fixture]]'
edit_page en 'Explicit translation fixture' 'Explicit dependent fixture'
edit_page de 'Mixed fallback fixture' 'Same-title fallback fixture'

# All five dependent editions reuse the same central translations.
edit_page fr 'Débat multilingue ?' 'Six-language fixture [[en:Multilingual debate]] [[de:Mehrsprachige Debatte]] [[es:Debate multilingüe]] [[it:Dibattito multilingue]] [[pt:Debate multilingue]]'
edit_page en 'Multilingual debate' 'Synthetic English debate'
edit_page de 'Mehrsprachige Debatte' 'Synthetic German debate'
edit_page es 'Debate multilingüe' 'Synthetic Spanish debate'
edit_page it 'Dibattito multilingue' 'Synthetic Italian debate'
edit_page pt 'Debate multilingue' 'Synthetic Portuguese debate'

# Actual localized project namespaces, with Project: stored centrally.
edit_page fr 'Wikidébats:Accueil de test' 'Project fixture [[en:Project:Test home]] [[de:Project:Teststartseite]] [[es:Project:Inicio de prueba]] [[it:Project:Pagina di prova]] [[pt:Project:Página de teste]]'
edit_page en 'Wikidebates:Test home' 'English project fixture'
edit_page de 'Wikidebatten:Teststartseite' 'German project fixture'
edit_page es 'Wikidebates:Inicio de prueba' 'Spanish project fixture'
edit_page it 'Wikidibattiti:Pagina di prova' 'Italian project fixture'
edit_page pt 'Wikidebates:Página de teste' 'Portuguese project fixture'
edit_page fr 'Page non indexable' '__NOINDEX__ [[en:Restricted fixture]]'
edit_page en 'Restricted fixture' '__NOINDEX__'
edit_page fr 'Untranslated fixture' 'No translation'
old_revision=$(sql ill_ci_fr -e "SELECT page_latest FROM page WHERE page_namespace=0 AND page_title='French_ordinary'")
export old_revision
edit_page fr 'French ordinary' 'French body, new revision [[en:English ordinary]]'

# Inject legacy central self-mapping and conflicting supplemental entry.
sql ill_ci_fr -e "INSERT INTO interlanguage_links (ill_from,ill_lang,ill_title) SELECT page_id,'fr','Wrong French title' FROM page WHERE page_namespace=0 AND page_title='Mixed_fallback_fixture' ON DUPLICATE KEY UPDATE ill_title=VALUES(ill_title)"
sql ill_ci_fr -e "INSERT INTO interlanguage_links (ill_from,ill_lang,ill_title) SELECT page_id,'en','Obsolete English title' FROM page WHERE page_namespace=0 AND page_title='Mixed_fallback_fixture' ON DUPLICATE KEY UPDATE ill_title=VALUES(ill_title)"

# The extension must persist a real cross-database fallback mapping.
test "$(sql ill_ci_fr -e "SELECT COUNT(*) FROM interlanguage_links JOIN page ON page_id=ill_from WHERE page_title='Shared_page' AND ill_lang='en' AND ill_title='Shared page'")" = 1
echo 'PASS cross-database fallback write'
test "$(sql ill_ci_fr -e "SELECT COUNT(*) FROM interlanguage_links JOIN page ON page_id=ill_from WHERE page_title='Mixed_fallback_fixture' AND ill_lang='de' AND ill_title='Mixed fallback fixture'")" = 1

for lang in "${langs[@]}"; do
	MW_CONFIG_FILE="$config/$lang/LocalSettings.php" php -S "127.0.0.1:${ports[$lang]}" -t "$root" >"$config/$lang.log" 2>&1 &
	pids+=("$!")
done
for lang in "${langs[@]}"; do
	port=${ports[$lang]}
	curl --fail --silent --show-error --retry 20 --retry-connrefused --retry-delay 1 \
		"http://127.0.0.1:$port/api.php?action=query&meta=siteinfo&format=json" > /dev/null
done

python3 - <<'PY'
import json
import os
from html.parser import HTMLParser
import urllib.parse
import urllib.request

def api(port, **params):
	params.update(format='json', formatversion=1)
	url = f'http://127.0.0.1:{port}/api.php?' + urllib.parse.urlencode(params)
	with urllib.request.urlopen(url, timeout=30) as response:
		data = json.load(response)
	assert 'error' not in data, data
	return data

def expect_link(port, title, language, other_title):
	data = api(port, action='query', prop='langlinks', titles=title, llprop='url', lllimit=50)
	page, = data['query']['pages'].values()
	links = [link for link in page.get('langlinks', []) if link['lang'] == language]
	assert len(links) == 1, (title, data)
	assert links[0].get('*', links[0].get('title')) == other_title, (title, data)
	assert links[0]['url'].startswith('http://127.0.0.1:'), (title, data)
	print('PASS API', title, language, other_title)

for port, lang in ((18782, 'fr'), (18781, 'en'), (18783, 'de'), (18784, 'es'), (18785, 'it'), (18786, 'pt')):
	site = api(port, action='query', meta='siteinfo')['query']['general']
	assert site['generator'] == 'MediaWiki 1.43.12', site['generator']
	assert site['lang'] == lang, site
	print('PASS exact MediaWiki version and language', site['generator'], lang)

for english, french in (
	('English ordinary', 'French ordinary'),
	('Debate:Example', 'Debat:Exemple'),
	('Argument:Example', 'Argument:Exemple'),
	('Institutional information', 'Informations institutionnelles'),
	('Shared page', 'Shared page'),
):
	expect_link(18781, english, 'fr', french)
	expect_link(18782, french, 'en', english)

parsed = api(18782, action='parse', text='{{languagelink:en|English ordinary}}', title='Parser fixture', prop='langlinks')
assert any(link['lang'] == 'en' for link in parsed['parse']['langlinks']), parsed
print('PASS central parser function')

parsed = api(18781, action='parse', text='{{interlanguage:French ordinary}}', title='English ordinary', prop='properties')
assert any(p['name'] == 'interlanguage_pages' and 'French ordinary' in p['*'] for p in parsed['parse']['properties']), parsed
print('PASS dependent parser function and persisted parser property')

class Links(HTMLParser):
	def __init__(self):
		super().__init__()
		self.urls = []

	def handle_starttag(self, tag, attrs):
		if tag == 'a':
			self.urls.append(dict(attrs).get('href', ''))

for port, title in ((18781, 'English ordinary'), (18782, 'French ordinary')):
	url = f'http://127.0.0.1:{port}/index.php?' + urllib.parse.urlencode({'title': title})
	with urllib.request.urlopen(url, timeout=30) as response:
		html = response.read().decode()
	assert 'errorbox' not in html and 'mw-parser-output' in html, title
	assert ('French body' if port == 18782 else 'English body') in html, title
	links = Links()
	links.feed(html)
	other_port, other_title = (18781, 'English ordinary') if port == 18782 else (18782, 'French ordinary')
	assert any(urllib.parse.urlparse(href).netloc == f'127.0.0.1:{other_port}' and urllib.parse.parse_qs(urllib.parse.urlparse(href).query).get('title', [''])[0].replace('_', ' ') == other_title for href in links.urls), (title, links.urls)
	print('PASS rendered page and language link', title)

# Collect only the HTML head. Body navigation hreflang is not SEO markup.
class SeoHead(HTMLParser):
	def __init__(self):
		super().__init__()
		self.in_head = False
		self.canonical = []
		self.alternates = {}
		self.robots = []

	def handle_starttag(self, tag, attrs):
		attrs = dict(attrs)
		if tag == 'head':
			self.in_head = True
		elif self.in_head and tag == 'link':
			if attrs.get('rel') == 'canonical':
				self.canonical.append(attrs['href'])
			if attrs.get('rel') == 'alternate' and 'hreflang' in attrs:
				code = attrs['hreflang']
				assert code not in self.alternates, ('duplicate hreflang', code)
				self.alternates[code] = attrs['href']
		elif self.in_head and tag == 'meta' and attrs.get('name') in ('robots', 'googlebot'):
			self.robots.append(attrs.get('content', ''))

	def handle_endtag(self, tag):
		if tag == 'head':
			self.in_head = False

def head(port, title, **params):
	url = f'http://127.0.0.1:{port}/index.php?' + urllib.parse.urlencode({'title': title, **params})
	with urllib.request.urlopen(url, timeout=30) as response:
		html = response.read().decode()
	assert 'mw-parser-output' in html or params.get('action') == 'history', (title, params, html[:500])
	result = SeoHead()
	result.feed(html)
	assert len(result.canonical) == 1, (title, result.canonical)
	return result

def verify_cluster(pages):
	# Derive expected URLs from the actual canonical tags of every edition.
	expected = {lang: head(port, title).canonical[0] for lang, (port, title) in pages.items()}
	for skin in ('vector', 'minerva'):
		for lang, (port, title) in pages.items():
			result = head(port, title, useskin=skin)
			assert result.canonical == [expected[lang]], (skin, lang, result.canonical)
			assert result.alternates == expected, (skin, lang, title, expected, result.alternates)
			hidden = head(port, title, useskin=skin, illcihide=1)
			assert hidden.alternates == expected, (skin, lang, 'hidden selector', hidden.alternates)
		print('PASS reciprocal canonical hreflang', skin, ','.join(sorted(pages)), next(iter(pages.values()))[1])

for en, fr in (
	('English ordinary', 'French ordinary'),
	('Debate:Example', 'Debat:Exemple'),
	('Argument:Example', 'Argument:Exemple'),
	('Institutional information', 'Informations institutionnelles'),
	('Shared page', 'Shared page'),
):
	verify_cluster({'en': (18781, en), 'fr': (18782, fr)})

verify_cluster({
	'fr': (18782, 'Mixed fallback fixture'),
	'en': (18781, 'Explicit translation fixture'),
	'de': (18783, 'Mixed fallback fixture'),
})

verify_cluster({
	'en': (18781, 'Multilingual debate'),
	'fr': (18782, 'Débat multilingue ?'),
	'de': (18783, 'Mehrsprachige Debatte'),
	'es': (18784, 'Debate multilingüe'),
	'it': (18785, 'Dibattito multilingue'),
	'pt': (18786, 'Debate multilingue'),
})

verify_cluster({
	'fr': (18782, 'Wikidébats:Accueil de test'),
	'en': (18781, 'Wikidebates:Test home'),
	'de': (18783, 'Wikidebatten:Teststartseite'),
	'es': (18784, 'Wikidebates:Inicio de prueba'),
	'it': (18785, 'Wikidibattiti:Pagina di prova'),
	'pt': (18786, 'Wikidebates:Página de teste'),
})

for skin in ('vector', 'minerva'):
	for port, title in ((18781, 'Restricted fixture'), (18782, 'Page non indexable')):
		result = head(port, title, useskin=skin)
		assert any('noindex' in value for value in result.robots), (skin, title, result.robots)
		assert result.alternates == {}, (skin, title, result.alternates)
	assert head(18782, 'Untranslated fixture', useskin=skin).alternates == {}
	assert head(18782, 'French ordinary', useskin=skin, oldid=os.environ['old_revision']).alternates == {}
	assert head(18782, 'French ordinary', useskin=skin, action='history').alternates == {}
	print('PASS noindex, untranslated, old revision and history', skin)
PY


# The direct job test uses a valid target payload. Verify an actual remote
# purge through the local MediaWiki API by observing page_touched in MariaDB.
sql ill_ci_en -e "UPDATE page SET page_touched='20000101000000' WHERE page_namespace=0 AND page_title='English_ordinary'"
printf '%s\n' '$job = new InterlanguageCentralExtensionPurgeJob( Title::newFromText( "French ordinary" ), [ "ill" => [ "en" => [ "English ordinary" => true ] ] ] ); if ( !$job->run() ) { exit( 1 ); }' | MW_CONFIG_FILE="$config/fr/LocalSettings.php" php maintenance/run.php eval
test "$(sql ill_ci_en -e "SELECT page_touched FROM page WHERE page_namespace=0 AND page_title='English_ordinary'")" != '20000101000000'
grep -F 'POST /api.php?action=purge' "$config/en.log"
echo 'PASS remote purge job and database invalidation'
echo 'PASS disposable Interlanguage and WikiSEO six-edition integration (FR central)'
