#!/usr/bin/env python3
"""Read-only inventory of the six-edition farm's central language mappings.

Run from the MediaWiki installation root. Does not render, purge or edit
pages. Only reads page, langlinks, interlanguage_links and page_props.
The persisted dependent parser properties are evidence, not live HTML.
"""
import argparse
from collections import Counter, defaultdict
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

LANGS = ("fr", "en", "de", "es", "it", "pt")

PHP = r'''<?php
$services = \MediaWiki\MediaWikiServices::getInstance();
$db = $services->getConnectionProvider()->getReplicaDatabase();
$lang = $services->getContentLanguage()->getCode();
if ( $lang !== getenv( 'WD_ILL_LANG' ) ) {
	throw new RuntimeException( 'Unexpected wiki language: ' . $lang );
}
$canonicalNamespaces = $services->getNamespaceInfo()->getCanonicalNamespaces();
$describe = static function ( $row ) use ( $canonicalNamespaces ) {
	$title = Title::makeTitle( (int)$row->page_namespace, $row->page_title );
	$ns = $title->getNamespace();
	$prefix = $ns === NS_MAIN ? '' : ( $canonicalNamespaces[$ns] ?? '' ) . ':';
	return [
		'id' => (int)$row->page_id,
		'key' => $title->getPrefixedDBkey(),
		'lookup' => $prefix . $title->getText(),
		'redirect' => (bool)$row->page_is_redirect,
	];
};
$fields = [ 'page_id', 'page_namespace', 'page_title', 'page_is_redirect' ];
if ( getenv( 'WD_ILL_MODE' ) === 'central' ) {
	$links = [];
	$ids = [];
	foreach ( [ 'langlinks' => 'll', 'interlanguage_links' => 'ill' ] as $table => $prefix ) {
		$from = $prefix . '_from';
		$code = $prefix . '_lang';
		$target = $prefix . '_title';
		foreach ( $db->select( $table, [ $from, $code, $target ], [], __FILE__ ) as $row ) {
			$id = (int)$row->$from;
			$links[] = [ $table, $id, $row->$code, $row->$target ];
			$ids[$id] = true;
		}
	}
	$pages = [];
	foreach ( array_chunk( array_keys( $ids ), 500 ) as $chunk ) {
		foreach ( $db->select( 'page', $fields, [ 'page_id' => $chunk ], __FILE__ ) as $row ) {
			$pages[$row->page_id] = $describe( $row );
		}
	}
	$data = [ 'links' => $links, 'pages' => (object)$pages ];
} else {
	$targets = json_decode( file_get_contents( getenv( 'WD_ILL_TARGETS' ) ), true, 512, JSON_THROW_ON_ERROR );
	$parsed = [];
	$groups = [];
	foreach ( $targets as $raw ) {
		$title = Title::newFromText( $raw );
		if ( !$title || !$title->canExist() || $title->isExternal() || $title->getFragment() !== '' ) {
			$parsed[$raw] = [ 'status' => 'invalid' ];
			continue;
		}
		$ns = $title->getNamespace();
		$key = $title->getDBkey();
		$parsed[$raw] = [ 'status' => 'missing', 'ns' => $ns, 'dbkey' => $key ];
		$groups[$ns][$key] = true;
	}
	$found = [];
	$ids = [];
	foreach ( $groups as $ns => $keys ) {
		foreach ( array_chunk( array_map( 'strval', array_keys( $keys ) ), 500 ) as $chunk ) {
			foreach ( $db->select( 'page', $fields, [
				'page_namespace' => $ns, 'page_title' => $chunk,
			], __FILE__ ) as $row ) {
				$found[$ns][$row->page_title] = $describe( $row );
				$ids[(int)$row->page_id] = true;
			}
		}
	}
	foreach ( $parsed as $raw => &$entry ) {
		if ( $entry['status'] === 'missing' && isset( $found[$entry['ns']][$entry['dbkey']] ) ) {
			$entry = [ 'status' => 'exists' ] + $found[$entry['ns']][$entry['dbkey']];
		}
	}
	unset( $entry );
	$stored = [];
	if ( $lang !== 'fr' ) {
		foreach ( array_chunk( array_keys( $ids ), 500 ) as $chunk ) {
			foreach ( $db->select( 'page_props', [ 'pp_page', 'pp_value' ], [
				'pp_page' => $chunk, 'pp_propname' => 'wikirouge-langlinks',
			], __FILE__ ) as $row ) {
				$links = @unserialize( $row->pp_value, [ 'allowed_classes' => false ] );
				$clean = [];
				$valid = is_array( $links );
				if ( $valid ) {
					foreach ( $links as $item ) {
						if ( !is_array( $item ) || !is_string( $item['lang'] ?? null ) ||
							!is_string( $item['*'] ?? null )
						) {
							$valid = false;
							break;
						}
						$clean[] = [ $item['lang'], $item['*'] ];
					}
				}
				$stored[$row->pp_page] = $valid ? $clean : null;
			}
		}
	}
	$data = [ 'targets' => (object)$parsed, 'stored' => (object)$stored ];
}
if ( file_put_contents( getenv( 'WD_ILL_OUTPUT' ), json_encode( $data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE ) ) === false ) {
	throw new RuntimeException( 'Could not write audit snapshot' );
}
'''

def snapshot(root, directory, lang, mode, targets=()):
	code = directory / "snapshot.php"
	code.write_text(PHP, encoding="utf-8")
	input_path = directory / "targets.json"
	input_path.write_text(json.dumps(sorted(set(targets)), ensure_ascii=False), encoding="utf-8")
	output = directory / "snapshot.json"
	output.unlink(missing_ok=True)
	env = dict(os.environ, WD_ILL_LANG=lang, WD_ILL_MODE=mode,
		WD_ILL_AUDIT_CODE=str(code), WD_ILL_TARGETS=str(input_path), WD_ILL_OUTPUT=str(output))
	result = subprocess.run(
		["php", "maintenance/run.php", "eval", "--wiki=" + lang],
		input="require getenv( 'WD_ILL_AUDIT_CODE' );\n",
		text=True, cwd=root, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
		check=False,
	)
	if result.returncode != 0 or not output.is_file():
		raise RuntimeError(f"{lang}/{mode}: {result.stderr[-2000:]} {result.stdout[-2000:]}")
	return json.loads(output.read_text(encoding="utf-8"))

def key(raw):
	return raw.replace(" ", "_")

def analyze(central, editions):
	issues = defaultdict(list)
	counts = Counter()
	groups = defaultdict(lambda: defaultdict(list))
	target_sources = defaultdict(set)

	def issue(kind, **details):
		issues[kind].append(details)

	def resolve(lang, raw):
		return editions[lang]["targets"].get(raw, {"status": "invalid"})

	for table, source, lang, raw in central["links"]:
		counts[table] += 1
		counts[f"{table}:{lang}"] += 1
		page = central["pages"].get(str(source))
		context = dict(source=source, source_title=page["key"] if page else None,
			lang=lang, target=raw, table=table)
		if page is None:
			issue("ORPHAN_SOURCE", **context)
		elif page["redirect"]:
			issue("SOURCE_REDIRECT", **context)
		if lang not in LANGS or lang == "fr":
			issue("OUTSIDE_CLUSTER_OR_SELF_MAPPING", **context)
			continue
		groups[source][lang].append((table, raw))
		target = resolve(lang, raw)
		if target["status"] != "exists":
			issue("TARGET_" + target["status"].upper(), **context)
			continue
		if target["redirect"]:
			issue("TARGET_REDIRECT", **context)
		if key(raw) != target["key"]:
			issue("NONCANONICAL_TARGET_PATH", canonical_title=target["key"], **context)
		if table == "langlinks" and raw != target["lookup"]:
			issue("NATIVE_REVERSE_LOOKUP_RISK", expected_lookup=target["lookup"], **context)

	for source, langs in groups.items():
		page = central["pages"].get(str(source))
		if not page or page["redirect"]:
			continue
		expected = {"fr": page["key"]}
		chosen = {}
		for lang, entries in langs.items():
			native = [raw for table, raw in entries if table == "langlinks"]
			fallback = [raw for table, raw in entries if table == "interlanguage_links"]
			if len(native) > 1 or len(fallback) > 1:
				issue("DUPLICATE_SOURCE_LANGUAGE", source=source, lang=lang, entries=entries)
			if native and fallback:
				a, b = resolve(lang, native[0]), resolve(lang, fallback[0])
				if a.get("id") != b.get("id") or (a.get("id") is None and native[0] != fallback[0]):
					issue("CONFLICT_NATIVE_FALLBACK", source=source, lang=lang,
						native=native[0], fallback=fallback[0])
				else:
					counts["redundant_fallback"] += 1
			raw = (native or fallback)[0]
			target = resolve(lang, raw)
			if target["status"] == "exists":
				target_sources[lang, target["id"]].add(source)
				if not target["redirect"]:
					expected[lang] = target["key"]
					chosen[lang] = target
		counts["central_clusters"] += 1
		counts["effective_existing_nonredirect_targets"] += len(chosen)
		for lang, target in chosen.items():
			stored = editions[lang]["stored"]
			context = dict(source=source, source_title=page["key"], lang=lang, target=target["key"])
			if str(target["id"]) not in stored:
				issue("STORED_LINKS_ABSENT", **context)
				continue
			links = stored[str(target["id"])]
			if links is None:
				issue("STORED_LINKS_MALFORMED", **context)
				continue
			actual = defaultdict(set)
			for code, raw in links:
				actual[code].add(key(raw))
			counts["stored_targets_checked"] += 1
			for code, wanted in expected.items():
				if code == lang:
					continue
				if not actual[code]:
					issue("STORED_PEER_MISSING", peer=code, expected=wanted, **context)
				elif actual[code] != {wanted}:
					issue("STORED_PEER_MISMATCH", peer=code, expected=wanted,
						actual=sorted(actual[code]), **context)
			for code in set(actual) - set(expected):
				if code in LANGS:
					issue("STORED_EXTRA_PEER", peer=code, actual=sorted(actual[code]), **context)
	for (lang, target_id), sources in target_sources.items():
		if len(sources) > 1:
			issue("TARGET_SHARED_BY_CENTRAL_PAGES", lang=lang, target_id=target_id, sources=sorted(sources))
	return dict(counts=counts, issues=dict(issues))


# A separate, deliberately small public observation. Single-title API calls
# avoid the historical custom langlinks module's multi-title ambiguity.
PUBLIC_HOMES = {
	"fr": "Wikidébats:Accueil",
	"en": "Wikidebates:Main Page",
	"de": "Wikidebatten:Hauptseite",
	"es": "Wikidebates:Página principal",
	"it": "Wikidibattiti:Pagina principale",
	"pt": "Wikidebates:Página principal",
}

def public_api(lang, title):
	params = {
		"action": "query", "prop": "info|langlinks", "inprop": "url",
		"lllimit": "50", "titles": title, "format": "json",
	}
	url = f"https://{lang}.wikidebates.org/w/api.php?" + urllib.parse.urlencode(params)
	# Do not follow redirects: an API redirect is an access/configuration observation.
	class NoRedirect(urllib.request.HTTPRedirectHandler):
		def redirect_request(self, *args, **kwargs):
			return None
	request = urllib.request.Request(url, headers={
		"User-Agent": "Wikidebates-public-SEO-audit/1.0",
		"Accept-Encoding": "identity",
	})
	try:
		with urllib.request.build_opener(NoRedirect()).open(request, timeout=12) as response:
			data = response.read(1024 * 1024 + 1)
			if len(data) > 1024 * 1024:
				raise ValueError("Oversized API response")
			payload = json.loads(data)
			if "error" in payload:
				return {"status": "api_error", "error": payload["error"].get("code")}
			if "continue" in payload:
				return {"status": "incomplete", "reason": "API continuation"}
			pages = payload["query"]["pages"]
			if len(pages) != 1:
				return {"status": "incomplete", "reason": "Unexpected page count"}
			page = next(iter(pages.values()))
			if "missing" in page or "invalid" in page:
				return {"status": "missing", "title": page.get("title", title)}
			return {
				"status": "ok", "title": page.get("title", title),
				"canonicalurl": page.get("canonicalurl", page.get("fullurl")),
				"redirect": "redirect" in page,
				"links": [[x["lang"], x.get("*", x.get("title", ""))]
					for x in page.get("langlinks", [])],
			}
	except urllib.error.HTTPError as error:
		return {"status": "http_error", "http": error.code}
	except (OSError, ValueError, KeyError, TypeError) as error:
		return {"status": "unverified", "reason": str(error)}

def public_observation():
	# Exactly six home-page lookups and one FR debate, plus at most
	# five one-title lookups of that debate's reported translations.
	results = {}
	for lang in LANGS:
		results[lang] = public_api(lang, PUBLIC_HOMES[lang])
		time.sleep(0.4)
	debate = public_api("fr", "Un revenu de base doit-il être instauré ?")
	cluster = {"fr": debate}
	if debate["status"] == "ok":
		for lang, title in debate["links"]:
			if lang in LANGS[1:] and lang not in cluster and title:
				time.sleep(0.4)
				cluster[lang] = public_api(lang, title)
	print(json.dumps({"homepages": results, "debate": cluster}, ensure_ascii=False, indent="\t"))
	return 0


def main():
	parser = argparse.ArgumentParser(description=__doc__)
	parser.add_argument("--root", type=Path, default=Path.cwd())
	parser.add_argument("--public", action="store_true", help="Only a bounded public API observation, no database access")
	parser.add_argument("--examples", type=int, default=5)
	parser.add_argument("--report", type=Path, help="Optional complete JSON report; not printed in full by default")
	args = parser.parse_args()
	if args.public:
		return public_observation()
	root = args.root.resolve()
	if not (root / "maintenance/run.php").is_file():
		parser.error("Run from the farm MediaWiki installation root, or set --root")
	with tempfile.TemporaryDirectory(prefix="ill-audit-") as tmp:
		directory = Path(tmp)
		central = snapshot(root, directory, "fr", "central")
		editions = {}
		for lang in LANGS[1:]:
			raws = [row[3] for row in central["links"] if row[2] == lang]
			editions[lang] = snapshot(root, directory, lang, "resolve", raws)
			print(f"SNAPSHOT {lang}: {len(set(raws))} distinct mapped titles", flush=True)
		report = analyze(central, editions)
	print("\n=== COUNTS ===")
	for name, count in sorted(report["counts"].items()):
		print(f"{name}: {count}")
	print("\n=== FINDINGS (stored data, not live HTML) ===")
	if not report["issues"]:
		print("No anomaly detected in the inspected data")
	for name, rows in sorted(report["issues"].items()):
		print(f"{name}: {len(rows)}")
		for row in rows[:max(0, args.examples)]:
			print("\t" + json.dumps(row, ensure_ascii=False, sort_keys=True))
	if args.report:
		args.report.write_text(json.dumps(report, ensure_ascii=False, indent="\t") + "\n", encoding="utf-8")
		print(f"Full report: {args.report}")

if __name__ == "__main__":
	main()
