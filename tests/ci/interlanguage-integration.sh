#!/usr/bin/env bash
# Exercise both modes on real, disposable MediaWiki databases and local HTTP APIs.
set -euo pipefail
if [[ "${CI:-}" != true || "${GITHUB_ACTIONS:-}" != true ]]; then
	echo "Only run on a disposable GitHub Actions runner." >&2
	exit 2
fi
root=$(pwd)
config=$(mktemp -d)
en_pid=
fr_pid=
cleanup() {
	for pid in "$en_pid" "$fr_pid"; do
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
mkdir "$config/en" "$config/fr"
export MYSQL_PWD="${INTERLANGUAGE_CI_DB_PASSWORD:?Missing disposable database password}"
sql() {
	mysql --protocol=TCP --host=127.0.0.1 --user=root --batch --skip-column-names "$@"
}
sql -e 'CREATE DATABASE ill_ci_en; CREATE DATABASE ill_ci_fr;'
admin_password=$(openssl rand -hex 24)
for lang in en fr; do
	port=18781
	if [[ "$lang" = fr ]]; then port=18782; fi
	php maintenance/run.php install \
		--confpath "$config/$lang" --dbtype mysql \
		--dbserver 127.0.0.1 --dbname "ill_ci_$lang" \
		--dbuser root --dbpass "$MYSQL_PWD" \
		--server "http://127.0.0.1:$port" --scriptpath '' \
		--lang "$lang" --skins Vector --pass "$admin_password" \
		"Interlanguage CI $lang" Admin
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
// MediaWiki 1.43 uses a namespaced core API module; explicitly register the
// legacy extension module in these fixtures, rather than testing core by mistake.
$wgAutoloadLocalClasses['ApiQueryLangLinks'] = "$IP/extensions/Interlanguage/api/ApiQueryLangLinks.php";
$wgAPIPropModules['langlinks'] = 'ApiQueryLangLinks';
PHP
done

# The production table already exists; no schema updater is provided by this
# extension. Provision only its required columns and key in the fixture.
sql ill_ci_en <<'SQL'
CREATE TABLE interlanguage_links (
	ill_from INT UNSIGNED NOT NULL,
	ill_lang VARBINARY(20) NOT NULL,
	ill_title VARBINARY(255) NOT NULL,
	PRIMARY KEY (ill_from, ill_lang),
	INDEX ill_lang_title (ill_lang, ill_title)
) ENGINE=InnoDB;
SQL
for lang in en fr; do
	sql "ill_ci_$lang" <<'SQL'
INSERT INTO interwiki (iw_prefix, iw_url, iw_api, iw_wikiid, iw_local, iw_trans)
VALUES
	('en', 'http://127.0.0.1:18781/index.php?title=$1', 'http://127.0.0.1:18781/api.php', '', 1, 0),
	('fr', 'http://127.0.0.1:18782/index.php?title=$1', 'http://127.0.0.1:18782/api.php', '', 1, 0)
ON DUPLICATE KEY UPDATE iw_url=VALUES(iw_url), iw_api=VALUES(iw_api), iw_local=VALUES(iw_local);
SQL
done
cat >> "$config/en/LocalSettings.php" <<'PHP'
require_once "$IP/extensions/Interlanguage/InterlanguageCentral.php";
wfLoadExtension( 'Interlanguage' );
PHP
cat >> "$config/fr/LocalSettings.php" <<'PHP'
require_once "$IP/extensions/Interlanguage/Interlanguage.php";
$wgInterlanguageExtensionDB = 'ill_ci_en';
$wgInterlanguageExtensionInterwiki = 'en:';
PHP

edit_page() {
	local lang=$1 title=$2 content=$3
	printf '%s\n' "$content" | MW_CONFIG_FILE="$config/$lang/LocalSettings.php" \
		php maintenance/run.php edit --user Admin --summary 'Disposable integration fixture' "$title"
}
edit_page en 'English ordinary' 'English body [[fr:French ordinary]]'
edit_page en 'Debate:Example' 'Synthetic debate [[fr:Debat:Exemple]]'
edit_page en 'Argument:Example' 'Synthetic argument [[fr:Argument:Exemple]]'
edit_page en 'Institutional information' 'Synthetic institutional page [[fr:Informations institutionnelles]]'
edit_page en 'Shared page' 'Central same-title fixture'
edit_page fr 'French ordinary' 'French body'
edit_page fr 'Debat:Exemple' 'Synthetic debate'
edit_page fr 'Argument:Exemple' 'Synthetic argument'
edit_page fr 'Informations institutionnelles' 'Synthetic institutional page'
edit_page fr 'Shared page' 'Dependent same-title fixture'

# The extension must persist a real cross-database fallback mapping.
test "$(sql ill_ci_en -e "SELECT COUNT(*) FROM interlanguage_links JOIN page ON page_id=ill_from WHERE page_title='Shared_page' AND ill_lang='fr' AND ill_title='Shared page'")" = 1
echo 'PASS cross-database fallback write'

MW_CONFIG_FILE="$config/en/LocalSettings.php" php -S 127.0.0.1:18781 -t "$root" >"$config/en.log" 2>&1 &
en_pid=$!
MW_CONFIG_FILE="$config/fr/LocalSettings.php" php -S 127.0.0.1:18782 -t "$root" >"$config/fr.log" 2>&1 &
fr_pid=$!
for port in 18781 18782; do
	curl --fail --silent --show-error --retry 20 --retry-connrefused --retry-delay 1 \
		"http://127.0.0.1:$port/api.php?action=query&meta=siteinfo&format=json" > /dev/null
done

python3 - <<'PY'
import json
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

for port in (18781, 18782):
	site = api(port, action='query', meta='siteinfo')['query']['general']
	assert site['generator'] == 'MediaWiki 1.43.12', site['generator']
	print('PASS exact MediaWiki version', site['generator'])

for english, french in (
	('English ordinary', 'French ordinary'),
	('Debate:Example', 'Debat:Exemple'),
	('Argument:Example', 'Argument:Exemple'),
	('Institutional information', 'Informations institutionnelles'),
	('Shared page', 'Shared page'),
):
	expect_link(18781, english, 'fr', french)
	expect_link(18782, french, 'en', english)

parsed = api(18781, action='parse', text='{{languagelink:fr|French ordinary}}', title='Parser fixture', prop='langlinks')
assert any(link['lang'] == 'fr' for link in parsed['parse']['langlinks']), parsed
print('PASS central parser function')

parsed = api(18782, action='parse', text='{{interlangue:English ordinary}}', title='French ordinary', prop='properties')
assert any(p['name'] == 'interlanguage_pages' and 'English ordinary' in p['*'] for p in parsed['parse']['properties']), parsed
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
PY


# The direct job test uses a valid target payload. Verify an actual remote
# purge through the local MediaWiki API by observing page_touched in MariaDB.
sql ill_ci_fr -e "UPDATE page SET page_touched='20000101000000' WHERE page_namespace=0 AND page_title='French_ordinary'"
printf '%s\n' '$job = new InterlanguageCentralExtensionPurgeJob( Title::newFromText( "English ordinary" ), [ "ill" => [ "fr" => [ "French ordinary" => true ] ] ] ); if ( !$job->run() ) { exit( 1 ); }' | MW_CONFIG_FILE="$config/en/LocalSettings.php" php maintenance/run.php eval
test "$(sql ill_ci_fr -e "SELECT page_touched FROM page WHERE page_namespace=0 AND page_title='French_ordinary'")" != '20000101000000'
grep -F 'POST /api.php?action=purge' "$config/fr.log"
echo 'PASS remote purge job and database invalidation'
echo 'PASS disposable Interlanguage FR/EN integration'
