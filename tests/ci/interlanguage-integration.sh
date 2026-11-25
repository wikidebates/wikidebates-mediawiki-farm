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
$wgMainCacheType = getenv( 'ILL_CI_USE_HASH_CACHE' ) === '1' ? 'hash' : CACHE_NONE;
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

# Provision only the historical supplemental mapping table. No auxiliary schema.
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


# Mirror the production database boundary: FR cannot read dependent wikis.
# Each dependent has its own DB and narrowly scoped central mapping rights.
# These disposable credentials never leave the isolated CI service.
for lang in "${langs[@]}"; do
	sql -e "CREATE USER 'ill_client_$lang'@'%' IDENTIFIED BY 'ci-only-ill-client'; GRANT ALL ON ill_ci_$lang.* TO 'ill_client_$lang'@'%';"
	if [[ "$lang" != fr ]]; then
		sql -e "GRANT SELECT ON ill_ci_fr.* TO 'ill_client_$lang'@'%'; GRANT INSERT, DELETE ON ill_ci_fr.interlanguage_links TO 'ill_client_$lang'@'%';"
	fi
	printf '\n$wgDBuser = "ill_client_%s";\n$wgDBpassword = "ci-only-ill-client";\n' "$lang" >> "$config/$lang/LocalSettings.php"
done
if MYSQL_PWD=ci-only-ill-client mysql --protocol=TCP --host=127.0.0.1 --user=ill_client_fr --batch ill_ci_en -e 'SELECT COUNT(*) FROM page' >/dev/null 2>&1; then
	echo 'Central database account unexpectedly has dependent read access' >&2
	exit 1
fi
echo 'PASS separate database permissions, central account cannot access English DB'
cat >> "$config/en/LocalSettings.php" <<'PHP'
$wgNamespaceAliases['ILLalias'] = NS_TEMPLATE;
$wgNamespaceAliases['ILLproject'] = NS_PROJECT;
PHP
# Derive remote title rules once, on each receiving wiki, and convert them
# into a shared static PHP array. Runtime hreflang does no remote lookup.
for lang in "${langs[@]}"; do
	MW_CONFIG_FILE="$config/$lang/LocalSettings.php" php maintenance/run.php ./ops/interlanguage/exportHreflangTitleRules.php > "$config/$lang/title-rules.json"
done
php -r '
	$rules = [];
	foreach ( array_slice( $argv, 1 ) as $file ) {
		$data = json_decode( file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
		$rules[$data["language"]] = $data["titleRules"];
	}
	if ( count( $rules ) !== 6 || $rules["en"]["namespaceAliases"]["ILLalias"] !== "Template" ||
		$rules["en"]["namespaceAliases"]["ILLproject"] !== "Wikidebates" ||
		$rules["en"]["namespaceCase"][""] !== "first-letter" ) {
		throw new RuntimeException( "Unexpected exported title rules" );
	}
	echo "\n\$illTitleRules = " . var_export( $rules, true ) . ";\n";
' "$config"/*/title-rules.json > "$config/title-rules.php"
cat >> "$config/title-rules.php" <<'PHP'
foreach ( $illTitleRules as $illLang => $illRules ) {
	$wgWikiSeoInterlanguageArticleUrls[$illLang] = array_replace( $wgWikiSeoInterlanguageArticleUrls[$illLang], $illRules );
}
unset( $illTitleRules, $illLang, $illRules );
PHP
for lang in "${langs[@]}"; do
	cat "$config/title-rules.php" >> "$config/$lang/LocalSettings.php"
done
echo 'PASS exported six receiver-local namespace and case rules'

edit_page() {
	local lang=$1 title=$2 content=$3
	printf '%s\n' "$content" | MW_CONFIG_FILE="$config/$lang/LocalSettings.php" \
		php maintenance/run.php edit --user Admin --summary 'Disposable integration fixture' "$title"
}
# Simulate a historical raw mapping without any migration or backfill.
# Direct SQL here is confined to the disposable fixture and forces a raw
# spelling that would have been missed by the legacy exact lookup.
edit_page fr 'Legacy spelling central' 'Legacy link [[en:Template:Legacy spelling]]'
sql ill_ci_fr -e "UPDATE langlinks JOIN page ON page_id=ll_from SET ll_title='  ILLalias : legacy__spelling  ' WHERE page_title='Legacy_spelling_central' AND ll_lang='en'"
edit_page en 'Template:Legacy spelling' 'Target in the English template namespace'
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_namespace=10 AND page_title='Legacy_spelling' AND pp_propname='wikirouge-langlinks' AND pp_value LIKE '%Legacy_spelling_central%'")" = 1
test "$(sql ill_ci_fr -e "SELECT COUNT(*) FROM langlinks JOIN page ON page_id=ll_from WHERE page_title='Legacy_spelling_central' AND ll_lang='en' AND ll_title='  ILLalias : legacy__spelling  '")" = 1
echo 'PASS schema-free normalized lookup preserves the original raw mapping'

edit_page fr 'Normalized project central' '[[en: ILLproject: normalized__project fixture]]'
edit_page en 'Project:Normalized project fixture' 'Native peer using a receiving-only alias'
edit_page fr 'Normalized colon central' '[[en: topic: A__colon fixture]]'
edit_page en 'Topic: A colon fixture' 'Ordinary colon peer'
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
	('Project:Normalized project fixture', 'Normalized project central'),
	('Topic: A colon fixture', 'Normalized colon central'),
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
# Exercise plain core purge directly, before the queued targeted job runs.
python3 - <<'PY'
import json
import urllib.parse
import urllib.request
params = dict(action="purge", titles="Property old peer|Property new peer", format="json")
request = urllib.request.Request("http://127.0.0.1:18781/api.php", data=urllib.parse.urlencode(params).encode())
with urllib.request.urlopen(request, timeout=30) as response:
	data = json.load(response)
assert len(data["purge"]) == 2 and all("purged" in row and "linkupdate" not in row for row in data["purge"]), data
PY
test "$(property_count Property_old_peer)" = 1
test "$(property_count Property_new_peer)" = 0
echo 'PASS ordinary remote purges do not refresh the persisted property'

# No opt-in, permission change or manually triggered job. The actual
# queued central edit requests targeted secondary-data refresh by default.
run_purges
test "$(property_count Property_old_peer)" = 0
test "$(property_count Property_new_peer)" = 1
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_title='Property_new_peer' AND pp_propname='wikirouge-langlinks' AND pp_value LIKE '%Property_refresh_cluster%'")" = 1
echo 'PASS automatic non-recursive remote link updates remove old and persist new peer properties'

# A request addressed to the stored redirect alias should update the real
# translated page, not merely the redirect page's cache.
edit_page fr 'Redirect cluster' 'Expanded translation group [[en:English redirect alias]] [[de:Property redirect peer]]'
run_purges
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_namespace=10 AND page_title='English_redirect_target' AND pp_propname='wikirouge-langlinks' AND pp_value LIKE '%Property redirect peer%'")" = 1
echo 'PASS automatic targeted refresh reaches the final redirect destination'

# A receiving wiki can deliberately deny linkpurge without denying purge.
# Respect the restriction; no alternate identity, rights grant or retry.
edit_page fr 'Property restricted cluster' 'Initial mapping [[en:Restricted old peer]]'
edit_page en 'Restricted old peer' 'Old translation'
edit_page en 'Restricted new peer' 'New translation'
run_purges
cp "$config/en/LocalSettings.php" "$config/en/LocalSettings.before-restriction.php"
cat >> "$config/en/LocalSettings.php" <<'PHP'
$wgAvailableRights[] = 'linkpurge';
$wgGroupPermissions['*']['linkpurge'] = false;
$wgRevokePermissions['*']['linkpurge'] = true;
PHP
# Check the actual implicit-rights classification in this disposable wiki.
printf '%s\n' '$pm = \MediaWiki\MediaWikiServices::getInstance()->getPermissionManager(); echo json_encode(["implicit"=>in_array("linkpurge",$pm->getImplicitRights(),true),"explicit"=>in_array("linkpurge",$pm->getAllPermissions(),true)]),"\n";' |
	MW_CONFIG_FILE="$config/en/LocalSettings.php" php maintenance/run.php eval
edit_page fr 'Property restricted cluster' 'Changed mapping [[en:Restricted new peer]]'
sql ill_ci_en -e "UPDATE page SET page_touched='20000101000000' WHERE page_namespace=0 AND page_title IN ('Restricted_old_peer','Restricted_new_peer')"
run_purges
sql ill_ci_en -e "SELECT page_title,page_touched,pp_value FROM page LEFT JOIN page_props ON pp_page=page_id AND pp_propname='wikirouge-langlinks' WHERE page_namespace=0 AND page_title IN ('Restricted_old_peer','Restricted_new_peer')"
python3 - <<'PY'
import json
import urllib.parse
import urllib.request
params = dict(action="purge", titles="Restricted old peer", forcelinkupdate=1, redirects=1, format="json")
request = urllib.request.Request("http://127.0.0.1:18781/api.php", data=urllib.parse.urlencode(params).encode())
with urllib.request.urlopen(request, timeout=30) as response:
	data = json.load(response)
print("Restricted API response:", json.dumps(data))
row, = data["purge"]
assert "purged" in row and "linkupdate" not in row and "warnings" in data, data
PY
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page WHERE page_namespace=0 AND page_title IN ('Restricted_old_peer','Restricted_new_peer') AND page_touched<>'20000101000000'")" = 2
test "$(property_count Restricted_old_peer)" = 1
test "$(property_count Restricted_new_peer)" = 0
mv "$config/en/LocalSettings.before-restriction.php" "$config/en/LocalSettings.php"
echo 'PASS receiving linkpurge restriction is respected while ordinary purge remains authorized'

# Native updates need no dictionary maintenance; the three user-visible wikicode
# forms and a receiving-only namespace alias all resolve on the English wiki.
edit_page fr 'Syntax one central' '[[en:Template:Syntax one]]'
edit_page fr 'Syntax two central' '[[en: Template:Syntax two]]'
edit_page fr 'Syntax three central' '[[en: Template: Syntax three]]'
edit_page fr 'Syntax alias central' '[[en:ILLalias: syntax__alias]]'
edit_page en 'Template:Syntax one' 'First equivalent form'
edit_page en 'Template:Syntax two' 'Second equivalent form'
edit_page en 'Template:Syntax three' 'Third equivalent form'
edit_page en 'Template:Syntax alias' 'Receiving-only namespace alias'
for variant in one two three alias; do
	test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_namespace=10 AND page_title='Syntax_$variant' AND pp_propname='wikirouge-langlinks' AND pp_value LIKE '%Syntax_${variant}_central%'")" = 1
done
echo 'PASS all three native link spellings, underscores, capitalization and a local namespace alias'
edit_page fr 'Syntax one central' '[[en: ILLalias: Syntax__one]]'
edit_page en 'Template:Syntax one' 'Same translation after changing raw spelling'
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_namespace=10 AND page_title='Syntax_one' AND pp_propname='wikirouge-langlinks' AND pp_value LIKE '%Syntax_one_central%'")" = 1

# Unknown colons belong to the main namespace. They must not conflate
# the two actual DB keys or a template.
edit_page fr 'Colon tight central' '[[en:Topic:Same tail]]'
edit_page fr 'Colon spaced central' '[[en:Topic: Same tail]]'
edit_page fr 'Colon template central' '[[en:Template:Same tail]]'
edit_page en 'Topic:Same tail' 'Tight colon'
edit_page en 'Topic: Same tail' 'Spaced colon'
edit_page en 'Template:Same tail' 'Template namespace'
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_namespace=0 AND page_title='Topic:Same_tail' AND pp_propname='wikirouge-langlinks' AND pp_value LIKE '%Colon_tight_central%'")" = 1
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_namespace=0 AND page_title='Topic:_Same_tail' AND pp_propname='wikirouge-langlinks' AND pp_value LIKE '%Colon_spaced_central%'")" = 1
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_namespace=10 AND page_title='Same_tail' AND pp_propname='wikirouge-langlinks' AND pp_value LIKE '%Colon_template_central%'")" = 1
echo 'PASS ordinary colons and namespaces remain distinct after candidate discovery'

# Two native sources claim the same identity through different spellings.
edit_page fr 'Ambiguous syntax first' '[[en:ILLalias: Ambiguous syntax]]'
edit_page fr 'Ambiguous syntax second' '[[en:Template:Ambiguous_syntax]]'
edit_page en 'Template:Ambiguous syntax' 'No arbitrary central source'
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_namespace=10 AND page_title='Ambiguous_syntax' AND pp_propname='wikirouge-langlinks'")" = 0
echo 'PASS normalized native ambiguity suppresses automatic inference'

# Historical supplemental rows may point to a differently named local page.
# Fixture SQL bypasses normal writers. Live discovery needs no backfill.
edit_page fr 'Supplemental reverse central' 'No native English link'
edit_page fr 'Native preferred central' '[[en:ILLalias: Native preferred fixture]]'
edit_page fr 'Supplemental competitor central' 'Competing fallback'
edit_page fr 'Shadowed central' '[[en:Native shadow target]]'
sql ill_ci_fr -e "INSERT INTO interlanguage_links (ill_from,ill_lang,ill_title) SELECT page_id,'en','ILLalias: Supplemental fixture' FROM page WHERE page_title='Supplemental_reverse_central'"
sql ill_ci_fr -e "INSERT INTO interlanguage_links (ill_from,ill_lang,ill_title) SELECT page_id,'en','Template:Native_preferred_fixture' FROM page WHERE page_title='Supplemental_competitor_central'"
sql ill_ci_fr -e "INSERT INTO interlanguage_links (ill_from,ill_lang,ill_title) SELECT page_id,'en','Shadowed supplemental' FROM page WHERE page_title='Shadowed_central'"
edit_page en 'Template:Supplemental fixture' 'Supplemental peer with another title'
edit_page en 'Template:Native preferred fixture' 'Native priority'
edit_page en 'Shadowed supplemental' 'Native link on central source overrides this fallback'
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_namespace=10 AND page_title='Supplemental_fixture' AND pp_propname='wikirouge-langlinks' AND pp_value LIKE '%Supplemental_reverse_central%'")" = 1
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_namespace=10 AND page_title='Native_preferred_fixture' AND pp_propname='wikirouge-langlinks' AND pp_value LIKE '%Native_preferred_central%'")" = 1
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_title='Shadowed_supplemental' AND pp_propname='wikirouge-langlinks'")" = 0
echo 'PASS differently named supplemental peers and native priority'

# A cached normalization must never resurrect a removed mapping.
edit_page fr 'Syntax one central' 'Removed native translation'
edit_page en 'Template:Syntax one' 'Local page after removal'
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_namespace=10 AND page_title='Syntax_one' AND pp_propname='wikirouge-langlinks'")" = 0
echo 'PASS deleted mappings cannot be resurrected by cached normalization'

# A live mutation in the same process must be visible even with an object
# cache. The dependent SQL account has only the historical mapping grants.
edit_page fr 'Memo first central' 'First source'
edit_page fr 'Memo second central' 'Second source'
cat > "$config/memo-mutation.php" <<'PHP'
<?php
$db = \MediaWiki\MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase( 'ill_ci_fr' );
$a = (int)$db->selectField( 'page', 'page_id', [ 'page_namespace' => 0, 'page_title' => 'Memo_first_central' ], __FILE__ );
$b = (int)$db->selectField( 'page', 'page_id', [ 'page_namespace' => 0, 'page_title' => 'Memo_second_central' ], __FILE__ );
$lookup = static fn () => InterlanguageTitleLookup::findCandidates( $db, [ 'Memo peer' ], 'en', false, true );
$check = static function ( bool $ok, string $message ) {
	if ( !$ok ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
};
$check( $a && $b && $lookup() === [], 'Expected no initial claim' );
$db->insert( 'interlanguage_links', [ 'ill_from' => $a, 'ill_lang' => 'en', 'ill_title' => '  Memo__peer  ' ], __FILE__ );
$check( array_keys( $lookup() ?? [] ) === [ $a ] && array_keys( $lookup() ?? [] ) === [ $a ], 'Expected first live claim and warm lookup' );
$db->insert( 'interlanguage_links', [ 'ill_from' => $b, 'ill_lang' => 'en', 'ill_title' => 'memo peer' ], __FILE__ );
$check( count( $lookup() ?? [] ) === 2, 'Expected immediate ambiguity' );
$db->delete( 'interlanguage_links', [ 'ill_from' => $a, 'ill_lang' => 'en' ], __FILE__ );
$check( array_keys( $lookup() ?? [] ) === [ $b ], 'Expected only the surviving claim' );
$db->delete( 'interlanguage_links', [ 'ill_from' => $b, 'ill_lang' => 'en' ], __FILE__ );
$check( $lookup() === [], 'Expected no remaining claims' );
echo "PASS live mapping changes with a warm native object cache\n";
PHP
printf '%s\n' 'require getenv( "ILL_CI_MEMO_SCRIPT" );' |
	ILL_CI_MEMO_SCRIPT="$config/memo-mutation.php" ILL_CI_USE_HASH_CACHE=1 MW_CONFIG_FILE="$config/en/LocalSettings.php" php maintenance/run.php eval
test "$(sql ill_ci_fr -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='ill_ci_fr' AND table_name='interlanguage_title_keys'")" = 0
echo 'PASS no auxiliary table, flags or grants required'

# EXPLAIN actual runtime SQL with more realistic central mapping cardinality.
# These synthetic rows are confined to the disposable MariaDB service.
sql ill_ci_fr <<'SQL'
INSERT INTO page (page_id,page_namespace,page_title,page_random,page_touched,page_latest,page_len)
SELECT 1000000+seq,0,CONCAT('ILL_plan_native_',seq),0.5,'20000101000000',0,0 FROM seq_1_to_5000;
INSERT INTO page (page_id,page_namespace,page_title,page_random,page_touched,page_latest,page_len)
SELECT 2000000+seq,0,CONCAT('ILL_plan_supplemental_',seq),0.5,'20000101000000',0,0 FROM seq_1_to_5000;
INSERT INTO langlinks (ll_from,ll_lang,ll_title)
SELECT 1000000+seq,'en',CONCAT('Plan native ',seq) FROM seq_1_to_5000;
INSERT INTO interlanguage_links (ill_from,ill_lang,ill_title)
SELECT 2000000+seq,'en',CONCAT('Plan supplemental ',seq) FROM seq_1_to_5000;
SQL
for title in 'Template:Legacy spelling' 'Plan native 2500' 'Plan supplemental 2500' 'Absent plan fixture'; do
	MW_CONFIG_FILE="$config/en/LocalSettings.php" php maintenance/run.php ./ops/interlanguage/explainTitleLookup.php --title="$title" --strict --repeat=5
	ILL_CI_USE_HASH_CACHE=1 MW_CONFIG_FILE="$config/en/LocalSettings.php" php maintenance/run.php ./ops/interlanguage/explainTitleLookup.php --title="$title" --strict --repeat=20
done
sql ill_ci_fr <<'SQL'
DELETE FROM langlinks WHERE ll_from BETWEEN 1000001 AND 1005000;
DELETE FROM interlanguage_links WHERE ill_from BETWEEN 2000001 AND 2005000;
DELETE FROM page WHERE page_id BETWEEN 1000001 AND 1005000 OR page_id BETWEEN 2000001 AND 2005000;
SQL
echo 'PASS actual central normalized reverse queries use indexes with 10000 synthetic distractors'

# Page lifecycle checks use the same restricted accounts as normalized reads.
# Targeted refresh is automatic for dependent peers, with the original receiving rights.
move_page() {
	local lang=$1 old=$2 new=$3
	shift 3
	printf '%s|%s\n' "$old" "$new" |
		MW_CONFIG_FILE="$config/$lang/LocalSettings.php" php maintenance/run.php moveBatch --u Admin "$@"
}
delete_page() {
	local lang=$1 title=$2
	printf '%s\n' "$title" |
		MW_CONFIG_FILE="$config/$lang/LocalSettings.php" php maintenance/run.php deleteBatch --u Admin
}
run_dependent_purges() {
	MW_CONFIG_FILE="$config/$1/LocalSettings.php" php maintenance/run.php runJobs --type=purgeDependentWikis --maxjobs=100
}

# Central move: page ID is stable, including a supplemental DE peer.
edit_page fr 'Lifecycle central old' '[[en:Lifecycle English peer]]'
edit_page en 'Lifecycle English peer' 'Native peer'
edit_page de 'Lifecycle central old' 'Automatic same-title peer'
run_purges
run_dependent_purges de
central_id=$(sql ill_ci_fr -e "SELECT page_id FROM page WHERE page_namespace=0 AND page_title='Lifecycle_central_old'")
test -n "$central_id"
test "$(sql ill_ci_fr -e "SELECT COUNT(*) FROM interlanguage_links WHERE ill_from=$central_id AND ill_lang='de'")" = 1
move_page fr 'Lifecycle central old' 'Template:Lifecycle central new'
test "$(sql ill_ci_fr -e "SELECT page_id FROM page WHERE page_namespace=10 AND page_title='Lifecycle_central_new'")" = "$central_id"
run_purges
for lang in en de; do
	test "$(sql "ill_ci_$lang" -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_title IN ('Lifecycle_English_peer','Lifecycle_central_old') AND pp_propname='wikirouge-langlinks' AND pp_value LIKE '%Template:Lifecycle_central_new%'")" = 1
done
echo 'PASS central move preserves mapping ID and refreshes native and supplemental peers'

# Central deletion: core's link-deletion update removes native data and the
# extension deletes only the supplemental rows for the actual deleted ID.
delete_page fr 'Template:Lifecycle central new'
test "$(sql ill_ci_fr -e "SELECT COUNT(*) FROM page WHERE page_id=$central_id")" = 0
test "$(sql ill_ci_fr -e "SELECT COUNT(*) FROM interlanguage_links WHERE ill_from=$central_id")" = 0
test "$(sql ill_ci_fr -e "SELECT COUNT(*) FROM langlinks WHERE ll_from=$central_id")" = 0
run_purges
for lang in en de; do
	test "$(sql "ill_ci_$lang" -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_title IN ('Lifecycle_English_peer','Lifecycle_central_old') AND pp_propname='wikirouge-langlinks'")" = 0
done
echo 'PASS central deletion cleans the deleted ID and refreshes former peers'

# Dependent move without redirect: only a unique effective supplemental
# row may follow the same local page ID. Its noncanonical raw spelling
# requires normalized candidate verification, not direct SQL equality.
edit_page fr 'Lifecycle supplemental central' '[[de:Lifecycle German peer]]'
sql ill_ci_fr -e "INSERT INTO interlanguage_links (ill_from,ill_lang,ill_title) SELECT page_id,'en','ILLalias: lifecycle__automatic_old' FROM page WHERE page_title='Lifecycle_supplemental_central'"
edit_page en 'Template:Lifecycle automatic old' 'Automatically linked template'
edit_page de 'Lifecycle German peer' 'Another native peer'
run_purges
run_dependent_purges en
old_id=$(sql ill_ci_en -e "SELECT page_id FROM page WHERE page_namespace=10 AND page_title='Lifecycle_automatic_old'")
move_page en 'Template:Lifecycle automatic old' 'Template:Lifecycle automatic new' --noredirects
test "$(sql ill_ci_en -e "SELECT page_id FROM page WHERE page_namespace=10 AND page_title='Lifecycle_automatic_new'")" = "$old_id"
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page WHERE page_namespace=10 AND page_title='Lifecycle_automatic_old'")" = 0
test "$(sql ill_ci_fr -e "SELECT ill_title FROM interlanguage_links JOIN page ON page_id=ill_from WHERE page_title='Lifecycle_supplemental_central' AND ill_lang='en'")" = 'Template:Lifecycle automatic new'
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_id=$old_id AND pp_propname='wikirouge-langlinks' AND pp_value LIKE '%Lifecycle_supplemental_central%'")" = 1
run_dependent_purges en
test "$(sql ill_ci_de -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_title='Lifecycle_German_peer' AND pp_propname='wikirouge-langlinks' AND pp_value LIKE '%Template:Lifecycle automatic new%'")" = 1
echo 'PASS unique supplemental mapping follows dependent page ID without UPDATE permission'

# Delete a non-content-namespace peer and ensure native DE is preserved.
delete_page en 'Template:Lifecycle automatic new'
test "$(sql ill_ci_fr -e "SELECT COUNT(*) FROM interlanguage_links JOIN page ON page_id=ill_from WHERE page_title='Lifecycle_supplemental_central' AND ill_lang='en'")" = 0
run_dependent_purges en
test "$(sql ill_ci_de -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_title='Lifecycle_German_peer' AND pp_propname='wikirouge-langlinks' AND pp_value LIKE '%Lifecycle_supplemental_central%' AND pp_value NOT LIKE '%Lifecycle automatic new%'")" = 1
test "$(sql ill_ci_fr -e "SELECT ll_title FROM langlinks JOIN page ON page_id=ll_from WHERE page_title='Lifecycle_supplemental_central' AND ll_lang='de'")" = 'Lifecycle German peer'
echo 'PASS dependent template deletion cleans only supplemental data and notifies peers'

# A native mapping remains editorial data even when the dependent page
# is moved with a redirect, or subsequently deleted.
edit_page fr 'Lifecycle native central' '[[en:ILLalias: Lifecycle native old]]'
edit_page en 'Template:Lifecycle native old' 'Native mapped template'
native_raw=$(sql ill_ci_fr -e "SELECT ll_title FROM langlinks JOIN page ON page_id=ll_from WHERE page_title='Lifecycle_native_central' AND ll_lang='en'")
move_page en 'Template:Lifecycle native old' 'Template:Lifecycle native new'
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_namespace=10 AND page_title='Lifecycle_native_new' AND pp_propname='wikirouge-langlinks' AND pp_value LIKE '%Lifecycle_native_central%'")" = 1
delete_page en 'Template:Lifecycle native old'
# Emit the disposable fixture's actual property state on failure.
sql ill_ci_en -e "SELECT page_title,pp_value FROM page LEFT JOIN page_props ON pp_page=page_id AND pp_propname='wikirouge-langlinks' WHERE page_namespace=10 AND page_title IN ('Lifecycle_native_old','Lifecycle_native_new')"
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM page_props JOIN page ON pp_page=page_id WHERE page_namespace=10 AND page_title='Lifecycle_native_new' AND pp_propname='wikirouge-langlinks'")" = 0
test "$(sql ill_ci_fr -e "SELECT ll_title FROM langlinks JOIN page ON page_id=ll_from WHERE page_title='Lifecycle_native_central' AND ll_lang='en'")" = "$native_raw"
echo 'PASS native links unchanged across dependent move and deletion of its redirect'

# Two conflicting automatic claims cannot be arbitrarily retargeted.
edit_page fr 'Lifecycle conflict first' 'First source'
edit_page fr 'Lifecycle conflict second' 'Second source'
sql ill_ci_fr -e "INSERT INTO interlanguage_links (ill_from,ill_lang,ill_title) SELECT page_id,'en','ILLalias: Lifecycle conflict old' FROM page WHERE page_title IN ('Lifecycle_conflict_first','Lifecycle_conflict_second')"
edit_page en 'Template:Lifecycle conflict old' 'Ambiguous automatic peer'
move_page en 'Template:Lifecycle conflict old' 'Template:Lifecycle conflict new' --noredirects
test "$(sql ill_ci_fr -e "SELECT COUNT(*) FROM interlanguage_links JOIN page ON page_id=ill_from WHERE page_title IN ('Lifecycle_conflict_first','Lifecycle_conflict_second') AND ill_lang='en' AND ill_title='ILLalias: Lifecycle conflict old'")" = 2
echo 'PASS ambiguous automatic mappings are not retargeted'

# Direct normalized deletion with a competing literal main-namespace
# title in the same coarse index bucket must not remove that other row.
edit_page fr 'Lifecycle normalized deletion' 'Derived mapping'
edit_page fr 'Lifecycle colon survives' 'Unrelated mapping'
sql ill_ci_fr -e "INSERT INTO interlanguage_links (ill_from,ill_lang,ill_title) SELECT page_id,'en',' ILLalias: lifecycle__delete ' FROM page WHERE page_title='Lifecycle_normalized_deletion'"
sql ill_ci_fr -e "INSERT INTO interlanguage_links (ill_from,ill_lang,ill_title) SELECT page_id,'en','Topic: Lifecycle delete' FROM page WHERE page_title='Lifecycle_colon_survives'"
edit_page en 'Template:Lifecycle delete' 'To be removed'
delete_page en 'Template:Lifecycle delete'
test "$(sql ill_ci_fr -e "SELECT COUNT(*) FROM interlanguage_links JOIN page ON page_id=ill_from WHERE page_title='Lifecycle_normalized_deletion' AND ill_lang='en'")" = 0
test "$(sql ill_ci_fr -e "SELECT COUNT(*) FROM interlanguage_links JOIN page ON page_id=ill_from WHERE page_title='Lifecycle_colon_survives' AND ill_lang='en'")" = 1
echo 'PASS normalized deletion verifies namespace and preserves an unrelated colon title'

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
