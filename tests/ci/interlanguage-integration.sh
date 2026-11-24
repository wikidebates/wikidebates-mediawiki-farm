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
// Explicit opt-in to the new effective API, without overriding the core class alias.
$wgAPIPropModules['langlinks'] = [
	'class' => 'InterlanguageEffectiveLangLinks',
	'services' => [ 'LanguageNameUtils', 'ContentLanguage', 'UrlUtils' ],
];
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
$wgInterlanguageExtensionNotifyFallbackChanges = true;
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

# Persist the new peer list after the German fallback has been established.
# This is a fixture edit, not a claim about automatic cache invalidation.
edit_page en 'Explicit translation fixture' 'Explicit dependent fixture refreshed after fallback'

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

# Real local page IDs must survive multi-title requests and both directions
# of continuation. The former customized API assigned central IDs instead.
def all_links(port, titles, direction):
	found = {}
	continuation = {}
	for _ in range(12):
		result = api(port, action='query', prop='langlinks', titles=titles,
			lllimit=1, lldir=direction, **continuation)
		for page in result['query']['pages'].values():
			links = found.setdefault(page['title'], [])
			links.extend((link['lang'], link['*']) for link in page.get('langlinks', []))
		continuation = result.get('continue', {})
		if not continuation:
			return {title: sorted(links) for title, links in found.items()}
	raise AssertionError('langlinks continuation did not terminate')

for direction in ('ascending', 'descending'):
	assert all_links(18782, 'French ordinary|Debat:Exemple', direction) == {
		'French ordinary': [('en', 'English ordinary')],
		'Debat:Exemple': [('en', 'Debate:Example')],
	}
	assert all_links(18781, 'English ordinary|Debate:Example', direction) == {
		'English ordinary': [('fr', 'French ordinary')],
		'Debate:Example': [('fr', 'Debat:Exemple')],
	}
print('PASS effective API local page IDs and both continuation directions')

expect_link(18782, 'Mixed fallback fixture', 'en', 'Explicit translation fixture')
expect_link(18782, 'Mixed fallback fixture', 'de', 'Mixed fallback fixture')
expect_link(18781, 'Explicit translation fixture', 'de', 'Mixed fallback fixture')
expect_link(18781, 'Explicit translation fixture', 'fr', 'Mixed fallback fixture')
expect_link(18783, 'Mixed fallback fixture', 'en', 'Explicit translation fixture')
filtered = api(18782, action='query', prop='langlinks', titles='Mixed fallback fixture',
	lllang='de', lltitle='Mixed fallback fixture')
page, = filtered['query']['pages'].values()
assert [(link['lang'], link['*']) for link in page['langlinks']] == [('de', 'Mixed fallback fixture')]
print('PASS effective API merged cluster and language/title filtering')

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
# The direct job test above cannot detect malformed payloads from LinksUpdate.
# Exercise a real central edit, with an unchanged supplemental DE peer.
run_purges() {
	MW_CONFIG_FILE="$config/fr/LocalSettings.php" php maintenance/run.php runJobs --type=purgeDependentWikis --maxjobs=100
}
run_purges
edit_page fr 'Purge lifecycle' 'Initial mapping [[en:Purge old target]]'
edit_page en 'Purge old target' 'Old peer'
edit_page en 'Purge new target' 'New peer'
edit_page de 'Purge lifecycle' 'Supplemental peer'
run_purges
sql ill_ci_en -e "UPDATE page SET page_touched='20000101000000' WHERE page_namespace=0 AND page_title IN ('Purge_old_target','Purge_new_target')"
sql ill_ci_de -e "UPDATE page SET page_touched='20000101000000' WHERE page_namespace=0 AND page_title='Purge_lifecycle'"
edit_page fr 'Purge lifecycle' 'Changed mapping [[en:Purge new target]]'
test "$(sql ill_ci_fr -e "SELECT ll_title FROM langlinks JOIN page ON page_id=ll_from WHERE page_title='Purge_lifecycle' AND ll_lang='en'")" = 'Purge new target'
run_purges
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page WHERE page_namespace=0 AND page_title IN ('Purge_old_target','Purge_new_target') AND page_touched<>'20000101000000'")" = 2
test "$(sql ill_ci_de -e "SELECT COUNT(*) FROM page WHERE page_namespace=0 AND page_title='Purge_lifecycle' AND page_touched<>'20000101000000'")" = 1
echo 'PASS actual central edit purges old, new and supplemental peers'

sql ill_ci_en -e "UPDATE page SET page_touched='20000101000000' WHERE page_namespace=0 AND page_title='Purge_new_target'"
edit_page fr 'Purge lifecycle' 'Unchanged mapping, different text [[en:Purge new target]]'
run_purges
test "$(sql ill_ci_en -e "SELECT page_touched FROM page WHERE page_namespace=0 AND page_title='Purge_new_target'")" = '20000101000000'
echo 'PASS unchanged central language links do not trigger a remote purge'

# The dependent queue notifies existing peers after the first fallback write.
run_de_purges() {
	MW_CONFIG_FILE="$config/de/LocalSettings.php" php maintenance/run.php runJobs --type=purgeDependentWikis --maxjobs=100
}
run_de_purges
edit_page fr 'Fallback lifecycle' 'Central fixture [[en:Fallback explicit peer]]'
edit_page en 'Fallback explicit peer' 'Existing translation'
run_purges
sql ill_ci_fr -e "UPDATE page SET page_touched='20000101000000' WHERE page_namespace=0 AND page_title='Fallback_lifecycle'"
sql ill_ci_en -e "UPDATE page SET page_touched='20000101000000' WHERE page_namespace=0 AND page_title='Fallback_explicit_peer'"
edit_page de 'Fallback lifecycle' 'First same-title translation'
test "$(sql ill_ci_fr -e "SELECT COUNT(*) FROM interlanguage_links JOIN page ON page_id=ill_from WHERE page_title='Fallback_lifecycle' AND ill_lang='de' AND ill_title='Fallback lifecycle'")" = 1
test "$(sql ill_ci_fr -e "SELECT page_touched FROM page WHERE page_namespace=0 AND page_title='Fallback_lifecycle'")" = '20000101000000'
test "$(sql ill_ci_en -e "SELECT page_touched FROM page WHERE page_namespace=0 AND page_title='Fallback_explicit_peer'")" = '20000101000000'
run_de_purges
test "$(sql ill_ci_fr -e "SELECT page_touched FROM page WHERE page_namespace=0 AND page_title='Fallback_lifecycle'")" != '20000101000000'
test "$(sql ill_ci_en -e "SELECT page_touched FROM page WHERE page_namespace=0 AND page_title='Fallback_explicit_peer'")" != '20000101000000'
echo 'PASS new fallback mapping asynchronously purges central page and existing peer'

sql ill_ci_fr -e "UPDATE page SET page_touched='20000101000000' WHERE page_namespace=0 AND page_title='Fallback_lifecycle'"
sql ill_ci_en -e "UPDATE page SET page_touched='20000101000000' WHERE page_namespace=0 AND page_title='Fallback_explicit_peer'"
edit_page de 'Fallback lifecycle' 'Unchanged mapping on subsequent edit'
run_de_purges
test "$(sql ill_ci_fr -e "SELECT page_touched FROM page WHERE page_namespace=0 AND page_title='Fallback_lifecycle'")" = '20000101000000'
test "$(sql ill_ci_en -e "SELECT page_touched FROM page WHERE page_namespace=0 AND page_title='Fallback_explicit_peer'")" = '20000101000000'
echo 'PASS unchanged fallback mapping does not repeat purges'

# A page with the same name must not displace an explicitly mapped peer.
edit_page fr 'Competing title' 'Native priority [[en:Actual translation]]'
edit_page en 'Actual translation' 'Real English peer'
edit_page en 'Competing title' 'Unrelated English page with the same name'
test "$(sql ill_ci_fr -e "SELECT COUNT(*) FROM interlanguage_links JOIN page ON page_id=ill_from WHERE page_title='Competing_title' AND ill_lang='en'")" = 0
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_title='Competing_title' AND pp_propname='wikirouge-langlinks'")" = 0
echo 'PASS same-title fallback cannot override a native translation'

edit_page fr 'Reserved fallback' 'Central fixture'
sql ill_ci_fr -e "INSERT INTO interlanguage_links (ill_from,ill_lang,ill_title) SELECT page_id,'en','Previously stored peer' FROM page WHERE page_namespace=0 AND page_title='Reserved_fallback'"
edit_page en 'Reserved fallback' 'Unrelated English page'
test "$(sql ill_ci_fr -e "SELECT ill_title FROM interlanguage_links JOIN page ON page_id=ill_from WHERE page_title='Reserved_fallback' AND ill_lang='en'")" = 'Previously stored peer'
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_title='Reserved_fallback' AND pp_propname='wikirouge-langlinks'")" = 0
echo 'PASS same-title fallback cannot overwrite an existing supplemental mapping'

# Reverse discovery through actual local redirects, including a namespace
# change. The other page with the same title text must not gain a group.
edit_page fr 'Redirect cluster' 'Translation through a redirect [[en:English redirect alias]]'
edit_page en 'English redirect alias' '#REDIRECT [[Template:English redirect middle]]'
edit_page en 'Template:English redirect middle' '#REDIRECT [[Template:English redirect target]]'
edit_page en 'English redirect target' 'Unrelated main-namespace page'
edit_page en 'Template:English redirect target' 'Actual English translation'
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM redirect JOIN page ON page_id=rd_from WHERE page_title='English_redirect_middle' AND rd_namespace=10 AND rd_title='English_redirect_target'")" = 1
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_namespace=10 AND page_title='English_redirect_target' AND pp_propname='wikirouge-langlinks'")" = 1
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_namespace=0 AND page_title='English_redirect_target' AND pp_propname='wikirouge-langlinks'")" = 0
python3 - <<'PY'
import json
import urllib.parse
import urllib.request
params = dict(action='query', prop='langlinks', titles='Template:English redirect target', format='json')
url = 'http://127.0.0.1:18781/api.php?' + urllib.parse.urlencode(params)
with urllib.request.urlopen(url, timeout=30) as response:
	data = json.load(response)
page, = data['query']['pages'].values()
assert any(link['lang'] == 'fr' and link['*'].replace('_', ' ') == 'Redirect cluster'
	for link in page.get('langlinks', [])), data
PY
echo 'PASS native reverse discovery through real redirect aliases, with namespace isolation'

# Direct and indirect matches must not compete for the same local page.
edit_page fr 'Redirect conflict direct' 'Direct claim [[en:Conflict redirect target]]'
edit_page fr 'Redirect conflict indirect' 'Indirect claim [[en:Conflict redirect alias]]'
edit_page en 'Conflict redirect alias' '#REDIRECT [[Conflict redirect target]]'
edit_page en 'Conflict redirect target' 'Ambiguous translation'
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_title='Conflict_redirect_target' AND pp_propname='wikirouge-langlinks'")" = 0
test "$(sql ill_ci_fr -e "SELECT COUNT(*) FROM interlanguage_links JOIN page ON page_id=ill_from WHERE page_title IN ('Redirect_conflict_direct','Redirect_conflict_indirect') AND ill_lang='en'")" = 0
echo 'PASS competing native redirect claims do not establish an arbitrary mapping'

# Exercise the explicit central DB lookup using actual MediaWiki page and
# redirect rows. The final title exists in two namespaces with distinct links.
edit_page fr 'Central redirect final' 'Unrelated main page [[en:Wrong forward peer]]'
edit_page fr 'Template:Central redirect final' 'Actual destination [[en:Correct forward peer]]'
edit_page fr 'Template:Central redirect middle' '#REDIRECT [[Template:Central redirect final]]'
edit_page fr 'Central redirect entry' '#REDIRECT [[Template:Central redirect middle]]'
edit_page fr 'Central redirect broken' '#REDIRECT [[Template:Central redirect absent]]'
printf '%s\n' '$extension = new InterlanguageExtension(); $data = $extension->getLinksFromDB( "Central redirect entry" ); $links = $data["query"]["pages"][0]["langlinks"] ?? []; if ( $links !== [ [ "lang" => "en", "*" => "Correct forward peer" ] ] ) { throw new RuntimeException( json_encode( $data ) ); } $data = $extension->getLinksFromDB( "Central redirect broken" ); if ( !isset( $data["query"]["pages"][-1]["missing"] ) ) { throw new RuntimeException( "Broken central redirect did not return missing" ); }' |
	MW_CONFIG_FILE="$config/en/LocalSettings.php" php maintenance/run.php eval
echo 'PASS explicit central lookup follows the real namespace and rejects a missing destination'

# A plain purge only invalidates rendering caches. Verify the actual
# persisted secondary data, without requesting a page view between checks.
edit_page fr 'Property refresh cluster' 'Initial mapping [[en:Property old peer]]'
edit_page en 'Property old peer' 'Old translation'
edit_page en 'Property new peer' 'New translation'
run_purges
property_count() {
	sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_namespace=0 AND page_title='$1' AND pp_propname='wikirouge-langlinks'"
}
test "$(property_count Property_old_peer)" = 1
test "$(property_count Property_new_peer)" = 0
edit_page fr 'Property refresh cluster' 'Changed mapping [[en:Property new peer]]'
run_purges
test "$(property_count Property_old_peer)" = 1
test "$(property_count Property_new_peer)" = 0
echo 'PASS ordinary remote purges do not refresh the persisted property'

# The sender opts in for only EN. Receiving authorization is deliberately
# controlled in this disposable fixture, never in production configuration.
refresh_property_peers() {
	printf '%s\n' '$GLOBALS["wgInterlanguagePurgeLinkUpdateLanguages"] = [ "en" ]; $job = new InterlanguageCentralExtensionPurgeJob( Title::newFromText( "Property refresh cluster" ), [ "ill" => [ "en" => [ "Property old peer" => true, "Property new peer" => true ] ] ] ); if ( !$job->run() ) { exit( 1 ); }' |
		MW_CONFIG_FILE="$config/fr/LocalSettings.php" php maintenance/run.php eval
}
cat >> "$config/en/LocalSettings.php" <<'PHP'
$wgGroupPermissions['*']['linkpurge'] = false;
PHP
refresh_property_peers
test "$(property_count Property_old_peer)" = 1
test "$(property_count Property_new_peer)" = 0
echo 'PASS denied linkpurge does not masquerade as a property update'

# The anonymous grant is restricted to the ephemeral integration runner.
cat >> "$config/en/LocalSettings.php" <<'PHP'
$wgGroupPermissions['*']['linkpurge'] = true;
PHP
refresh_property_peers
test "$(property_count Property_old_peer)" = 0
test "$(property_count Property_new_peer)" = 1
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_title='Property_new_peer' AND pp_propname='wikirouge-langlinks' AND pp_value LIKE '%Property_refresh_cluster%'")" = 1
echo 'PASS authorized non-recursive remote link updates remove old and persist new peer properties'

# A request addressed to the stored redirect alias should update the real
# translated page, not merely the redirect page's cache.
edit_page fr 'Redirect cluster' 'Expanded translation group [[en:English redirect alias]] [[de:Property redirect peer]]'
run_purges
printf '%s\n' '$GLOBALS["wgInterlanguagePurgeLinkUpdateLanguages"] = [ "en" ]; $job = new InterlanguageCentralExtensionPurgeJob( Title::newFromText( "Redirect cluster" ), [ "ill" => [ "en" => [ "English redirect alias" => true ] ] ] ); if ( !$job->run() ) { exit( 1 ); }' |
	MW_CONFIG_FILE="$config/fr/LocalSettings.php" php maintenance/run.php eval
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_namespace=10 AND page_title='English_redirect_target' AND pp_propname='wikirouge-langlinks' AND pp_value LIKE '%Property redirect peer%'")" = 1
echo 'PASS authorized targeted refresh reaches the final redirect destination'
cat >> "$config/en/LocalSettings.php" <<'PHP'
$wgGroupPermissions['*']['linkpurge'] = false;
PHP

# Validate the actual cleanup utility on the disposable central database.
# Preserve all valid supplemental rows and every native langlink.
sql ill_ci_fr -e "INSERT INTO interlanguage_links (ill_from,ill_lang,ill_title) VALUES (4294967294,'pt','Orphan cleanup fixture')"
backup="$config/cleanup-backup.json"
printf '%s\n' '$db = \MediaWiki\MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase(); $rows = []; foreach ($db->select(["interlanguage_links","page"],["ill_from","ill_lang","ill_title"],["(ill_lang = '\''fr'\'' OR page_id IS NULL)"],__METHOD__,[],["page"=>["LEFT JOIN","page_id=ill_from"]]) as $row) { $rows[] = ["ill_from"=>(int)$row->ill_from,"ill_lang"=>$row->ill_lang,"ill_title"=>$row->ill_title]; } file_put_contents(getenv("ILL_CLEANUP_TEST_BACKUP"),json_encode(["database"=>$db->getDomainID(),"entries"=>$rows],JSON_THROW_ON_ERROR));' |
	ILL_CLEANUP_TEST_BACKUP="$backup" MW_CONFIG_FILE="$config/fr/LocalSettings.php" php maintenance/run.php eval
hash=$(sha256sum "$backup" | cut -d' ' -f1)
valid_before=$(sql ill_ci_fr -e "SELECT ill_from,ill_lang,ill_title FROM interlanguage_links JOIN page ON page_id=ill_from WHERE ill_lang<>'fr' ORDER BY ill_from,ill_lang")
native_before=$(sql ill_ci_fr -e "SELECT ll_from,ll_lang,ll_title FROM langlinks ORDER BY ll_from,ll_lang")
all_before=$(sql ill_ci_fr -e "SELECT ill_from,ill_lang,ill_title FROM interlanguage_links ORDER BY ill_from,ill_lang")

cleanup_fixture() {
	MW_CONFIG_FILE="$config/fr/LocalSettings.php" php maintenance/run.php ./ops/interlanguage/cleanupMappings.php "$@"
}
cleanup_fixture --backup="$backup" --sha256="$hash" | tee "$config/cleanup-preview.log"
grep -Fx 'PREVIEW ONLY' "$config/cleanup-preview.log"
grep -Fx 'Total: 2' "$config/cleanup-preview.log"
test "$(sql ill_ci_fr -e "SELECT ill_from,ill_lang,ill_title FROM interlanguage_links ORDER BY ill_from,ill_lang")" = "$all_before"

if cleanup_fixture --backup="$backup" --sha256="$(printf '%064d' 0)" --apply; then
	echo 'Cleanup unexpectedly accepted an invalid hash' >&2
	exit 1
fi
python3 - "$backup" "$config/changed-backup.json" <<'PY'
import json
import sys
with open(sys.argv[1]) as f:
	data = json.load(f)
data["entries"][0]["ill_title"] += " changed"
with open(sys.argv[2], "w") as f:
	json.dump(data, f)
PY
changed_hash=$(sha256sum "$config/changed-backup.json" | cut -d' ' -f1)
if cleanup_fixture --backup="$config/changed-backup.json" --sha256="$changed_hash" --apply; then
	echo 'Cleanup unexpectedly accepted a changed candidate' >&2
	exit 1
fi
test "$(sql ill_ci_fr -e "SELECT ill_from,ill_lang,ill_title FROM interlanguage_links ORDER BY ill_from,ill_lang")" = "$all_before"
echo 'PASS cleanup rejects invalid hashes and changed snapshots without deleting rows'

cleanup_fixture --backup="$backup" --sha256="$hash" --apply
test "$(sql ill_ci_fr -e "SELECT COUNT(*) FROM interlanguage_links LEFT JOIN page ON page_id=ill_from WHERE ill_lang='fr' OR page_id IS NULL")" = 0
test "$(sql ill_ci_fr -e "SELECT ill_from,ill_lang,ill_title FROM interlanguage_links JOIN page ON page_id=ill_from WHERE ill_lang<>'fr' ORDER BY ill_from,ill_lang")" = "$valid_before"
test "$(sql ill_ci_fr -e "SELECT ll_from,ll_lang,ll_title FROM langlinks ORDER BY ll_from,ll_lang")" = "$native_before"
echo 'PASS transactional cleanup removes only backed-up self and orphan mappings'

echo 'PASS disposable Interlanguage and WikiSEO six-edition integration (FR central)'
