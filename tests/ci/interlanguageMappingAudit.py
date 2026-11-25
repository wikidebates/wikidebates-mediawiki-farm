#!/usr/bin/env python3
"""Read-only inventory of the six-edition farm's central language mappings.

Run from the MediaWiki installation root. Does not render, purge or edit
pages. Reads mapping tables, page identities, redirect rows and page_props.
Uses the local MediaWiki title parser for every compared title. Missing file
pages can be checked in DB-backed repositories; API-backed repositories
are explicitly unverified, with no public HTTP lookup. The persisted
dependent parser properties are evidence, not live HTML.
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
		'namespace' => $ns,
		'dbkey' => $title->getDBkey(),
		'key' => $title->getPrefixedDBkey(),
		'lookup' => $prefix . $title->getText(),
		'redirect' => (bool)$row->page_is_redirect,
	];
};
$fields = [ 'page_id', 'page_namespace', 'page_title', 'page_is_redirect' ];
$redirectCache = [];
$describeRedirect = static function ( $row ) use ( $db, $fields, $describe, &$redirectCache ) {
	$id = (int)$row->page_id;
	if ( isset( $redirectCache[$id] ) ) {
		return $redirectCache[$id];
	}
	$source = $describe( $row );
	if ( !$source['redirect'] ) {
		return $source;
	}
	$seen = [];
	$section = false;
	for ( $depth = 0; $depth <= 16; $depth++ ) {
		$currentId = (int)$row->page_id;
		if ( isset( $seen[$currentId] ) ) {
			return $redirectCache[$id] = $source + [ 'redirect_status' => 'loop' ];
		}
		if ( !$row->page_is_redirect ) {
			return $redirectCache[$id] = $source + [
				'redirect_status' => $section ? 'section' : 'valid',
				'destination' => $describe( $row ),
			];
		}
		$seen[$currentId] = true;
		if ( $depth === 16 ) {
			return $redirectCache[$id] = $source + [ 'redirect_status' => 'limit' ];
		}
		$redirect = $db->selectRow(
			'redirect', [ 'rd_namespace', 'rd_title', 'rd_interwiki', 'rd_fragment' ],
			[ 'rd_from' => $currentId ], __FILE__
		);
		if ( !$redirect ) {
			return $redirectCache[$id] = $source + [ 'redirect_status' => 'broken' ];
		}
		if ( (string)$redirect->rd_interwiki !== '' ) {
			return $redirectCache[$id] = $source + [ 'redirect_status' => 'interwiki' ];
		}
		$section = $section || (string)$redirect->rd_fragment !== '';
		$row = $db->selectRow( 'page', $fields, [
			'page_namespace' => (int)$redirect->rd_namespace, 'page_title' => $redirect->rd_title,
		], __FILE__ );
		if ( !$row ) {
			return $redirectCache[$id] = $source + [ 'redirect_status' => 'broken' ];
		}
	}
	throw new LogicException( 'Redirect resolution exceeded its bounds' );
};
$missingStatus = static function ( Title $title ) use ( $services ) {
	if ( $title->getNamespace() === NS_MEDIAWIKI &&
		$services->getMessageCache()->get( $title->getDBkey(), false ) !== false
	) {
		return 'message';
	}
	if ( $title->getNamespace() !== NS_FILE ) {
		return 'missing';
	}
	// Do not turn this read-only inventory into an external API crawl.
	// A repository requiring HTTP, or a failing repository, is unverified.
	try {
		$repos = $services->getRepoGroup();
		$options = [ 'ignoreRedirect' => true ];
		if ( $repos->getLocalRepo()->findFile( $title, $options ) ) {
			return 'file_without_page';
		}
		$unverified = false;
		$shared = $repos->forEachForeignRepo( static function ( $repo ) use ( $title, $options, &$unverified ) {
			if ( !$repo instanceof ForeignDBRepo ) {
				$unverified = true;
				return false;
			}
			try {
				return (bool)$repo->findFile( $title, $options );
			} catch ( Throwable $e ) {
				$unverified = true;
				return false;
			}
		} );
		return $shared ? 'shared_file' : ( $unverified ? 'file_unverified' : 'missing' );
	} catch ( Throwable $e ) {
		return 'file_unverified';
	}
};
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
			$pages[$row->page_id] = $describeRedirect( $row );
		}
	}
	$data = [ 'links' => $links, 'pages' => (object)$pages ];
} else {
	$targets = json_decode( file_get_contents( getenv( 'WD_ILL_TARGETS' ) ), true, 512, JSON_THROW_ON_ERROR );
	$parsed = [];
	$groups = [];
	foreach ( $targets as $raw ) {
		$title = Title::newFromText( $raw );
		if ( !$title ) {
			$parsed[$raw] = [ 'status' => 'invalid' ];
			continue;
		}
		if ( $title->isExternal() ) {
			$parsed[$raw] = [ 'status' => 'external' ];
			continue;
		}
		if ( !$title->canExist() ) {
			$parsed[$raw] = [ 'status' => 'invalid' ];
			continue;
		}
		$ns = $title->getNamespace();
		$key = $title->getDBkey();
		$parsed[$raw] = [
			'status' => $title->getFragment() !== '' ? 'section' : 'missing',
			'namespace' => $ns, 'dbkey' => $key, 'key' => $title->getPrefixedDBkey(),
		];
		if ( $parsed[$raw]['status'] === 'missing' ) {
			$groups[$ns][$key] = true;
		}
	}
	$found = [];
	foreach ( $groups as $ns => $keys ) {
		foreach ( array_chunk( array_map( 'strval', array_keys( $keys ) ), 500 ) as $chunk ) {
			foreach ( $db->select( 'page', $fields, [
				'page_namespace' => $ns, 'page_title' => $chunk,
			], __FILE__ ) as $row ) {
				$page = $describeRedirect( $row );
				$found[$ns][$row->page_title] = $page;
			}
		}
	}
	foreach ( $parsed as $raw => &$entry ) {
		if ( $entry['status'] !== 'missing' ) {
			continue;
		}
		$ns = $entry['namespace'];
		$key = $entry['dbkey'];
		if ( isset( $found[$ns][$key] ) ) {
			$entry = [ 'status' => 'exists' ] + $found[$ns][$key];
			if ( method_exists( 'InterlanguageExtension', 'getLookupTitleTexts' ) ) {
				$entry['lookup_spellings'] = InterlanguageExtension::getLookupTitleTexts( Title::makeTitle( $ns, $key ) );
			}
		} elseif ( $ns === NS_FILE || $ns === NS_MEDIAWIKI ) {
			$entry['status'] = $missingStatus( Title::makeTitle( $ns, $key ) );
		}
	}
	unset( $entry );
	$stored = [];
	$storedPages = [];
	if ( $lang !== 'fr' && getenv( 'WD_ILL_MODE' ) === 'resolve' ) {
		// Use the pp_propname_page index to inventory all properties, not only
		// currently mapped pages. Otherwise removed translations can never be
		// detected by the audit of stale persisted data.
		foreach ( $db->select(
			[ 'page_props', 'page' ],
			array_merge( [ 'pp_page', 'pp_value' ], $fields ),
			[ 'pp_propname' => 'wikirouge-langlinks' ],
			__FILE__, [], [ 'page' => [ 'LEFT JOIN', 'pp_page = page_id' ] ]
		) as $row ) {
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
			if ( $row->page_id !== null ) {
				$storedPages[$row->pp_page] = $describe( $row );
			}
		}
	}
	$data = [
		'targets' => (object)$parsed, 'stored' => (object)$stored, 'stored_pages' => (object)$storedPages,
		'bounded_exact_lookup' => method_exists( 'InterlanguageExtension', 'getLookupTitleTexts' ),
	];
}
if ( file_put_contents( getenv( 'WD_ILL_OUTPUT' ), json_encode( $data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE ) ) === false ) {
	throw new RuntimeException( 'Could not write audit snapshot' );
}
'''

def snapshot(root, directory, lang, mode, targets=(), config_dir=None):
	code = directory / "snapshot.php"
	code.write_text(PHP, encoding="utf-8")
	input_path = directory / "targets.json"
	input_path.write_text(json.dumps(sorted(set(targets)), ensure_ascii=False), encoding="utf-8")
	output = directory / "snapshot.json"
	output.unlink(missing_ok=True)
	env = dict(os.environ, WD_ILL_LANG=lang, WD_ILL_MODE=mode,
		WD_ILL_AUDIT_CODE=str(code), WD_ILL_TARGETS=str(input_path), WD_ILL_OUTPUT=str(output))
	command = ["php", "maintenance/run.php", "eval"]
	if config_dir is None:
		command.append("--wiki=" + lang)
	else:
		env["MW_CONFIG_FILE"] = str(config_dir / lang / "LocalSettings.php")
	result = subprocess.run(
		command,
		input="require getenv( 'WD_ILL_AUDIT_CODE' );\n",
		text=True, cwd=root, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
		check=False,
	)
	if result.returncode != 0 or not output.is_file():
		raise RuntimeError(f"{lang}/{mode}: {result.stderr[-2000:]} {result.stdout[-2000:]}")
	return json.loads(output.read_text(encoding="utf-8"))

def key(raw):
	return raw.replace(" ", "_")


def destination(page):
	"""Return an existing whole-page destination, never assume a redirect is valid."""
	if page.get("status", "exists") != "exists":
		return None
	if not page.get("redirect", False):
		return page
	if page.get("redirect_status") == "valid":
		return page["destination"]
	return None


def identity(page):
	"""Use MediaWiki's parsed namespace and DB key, not a guessed title string."""
	if page.get("status", "exists") in ("invalid", "external", "section", "unverified"):
		return None
	if page.get("redirect", False):
		page = destination(page)
	if page is None or "namespace" not in page or "dbkey" not in page:
		return None
	return page["namespace"], page["dbkey"]


def analyze(central, editions):
	issues = defaultdict(list)
	review = defaultdict(list)
	observations = defaultdict(list)
	counts = Counter()
	groups = defaultdict(lambda: defaultdict(list))
	target_sources = defaultdict(set)

	def issue(kind, **details):
		issues[kind].append(details)

	def check(kind, **details):
		review[kind].append(details)

	def observe(kind, **details):
		observations[kind].append(details)

	def resolve(lang, raw):
		return editions.get(lang, {}).get("targets", {}).get(raw, {"status": "unverified"})

	def equivalent(a, raw_a, b, raw_b):
		ai, bi = identity(a), identity(b)
		return (ai is not None and bi is not None and ai == bi) or raw_a == raw_b

	def classify_redirect(prefix, page, context):
		status = page.get("redirect_status", "unverified")
		details = dict(context, redirect_status=status)
		if "destination" in page:
			details["destination"] = page["destination"]["key"]
		kind = prefix + "_REDIRECT_" + status.upper()
		if status in ("broken", "loop"):
			issue(kind, **details)
		elif status == "valid" and prefix == "TARGET":
			observe(kind, **details)
		else:
			# Even a valid source redirect does not prove its destination owns
			# the same group. Section/interwiki/over-limit chains are not verified.
			check(kind, **details)

	counts["bounded_exact_lookup_languages"] = sum(
		bool(editions.get(lang, {}).get("bounded_exact_lookup")) for lang in LANGS[1:]
	)

	for table, source, lang, raw in central["links"]:
		counts[table] += 1
		counts[f"{table}:{lang}"] += 1
		page = central["pages"].get(str(source))
		context = dict(source=source, source_title=page["key"] if page else None,
			lang=lang, target=raw, table=table)
		if page is None:
			issue("ORPHAN_SOURCE", **context)
		elif page["redirect"]:
			classify_redirect("SOURCE", page, context)
		if lang not in LANGS or lang == "fr":
			issue("OUTSIDE_CLUSTER_OR_SELF_MAPPING", **context)
			continue
		groups[source][lang].append((table, raw))
		target = resolve(lang, raw)
		status = target["status"]
		if status != "exists":
			kind = "TARGET_" + status.upper()
			if status in ("message", "shared_file", "file_without_page"):
				observe(kind, **context)
			elif status in ("invalid", "external"):
				issue(kind, **context)
			else:
				check(kind, **context)
			continue
		if target["redirect"]:
			classify_redirect("TARGET", target, context)
		if key(raw) != target["key"] or raw != target["lookup"]:
			observe("SYNTAX_VARIANT", canonical_title=target["key"], **context)
		# The actual receiver's bounded lookup set is authoritative for
		# spelling coverage, not proof of a complete or rendered group.
		# Older snapshots lack this field; retain their exact-only warning.
		spellings = target.get("lookup_spellings", [target["lookup"]])
		if raw not in spellings:
			kind = "NATIVE_REVERSE_LOOKUP_RISK" if table == "langlinks" else "SUPPLEMENTAL_REVERSE_LOOKUP_RISK"
			check(kind, expected_lookup=target["lookup"], supported_spellings=spellings, **context)
		elif "lookup_spellings" in target:
			counts["bounded_exact_supported_mappings"] += 1
			if raw != target["lookup"]:
				observe("BOUNDED_VARIANT_SUPPORTED", expected_lookup=target["lookup"], **context)

	clusters = []
	for source, langs in groups.items():
		page = central["pages"].get(str(source))
		if not page or page["redirect"]:
			continue
		expected = {"fr": (page["lookup"], page)}
		chosen = {}
		for lang, entries in langs.items():
			native = [raw for table, raw in entries if table == "langlinks"]
			fallback = [raw for table, raw in entries if table == "interlanguage_links"]
			if len(native) > 1 or len(fallback) > 1:
				issue("DUPLICATE_SOURCE_LANGUAGE", source=source, lang=lang, entries=entries)
			if native and fallback:
				a, b = resolve(lang, native[0]), resolve(lang, fallback[0])
				if equivalent(a, native[0], b, fallback[0]):
					counts["redundant_fallback"] += 1
					observe("REDUNDANT_FALLBACK", source=source, lang=lang,
						native=native[0], fallback=fallback[0])
				else:
					issue("CONFLICT_NATIVE_FALLBACK", source=source, lang=lang,
						native=native[0], fallback=fallback[0])
			raw = (native or fallback)[0]
			target = resolve(lang, raw)
			# Keep all effective mappings in the expected property, even if
			# their target is missing or virtual. They are not unexpected peers.
			expected[lang] = raw, target
			dest = destination(target)
			if dest is not None:
				target_sources[lang, dest["id"]].add(source)
				chosen[lang] = dest
				if not target["redirect"]:
					counts["effective_existing_nonredirect_targets"] += 1
		counts["central_clusters"] += 1
		counts["effective_existing_resolved_targets"] += len(chosen)
		clusters.append((source, page, expected, chosen))

	for (lang, target_id), sources in target_sources.items():
		if len(sources) > 1:
			issue("TARGET_SHARED_BY_CENTRAL_PAGES", lang=lang, target_id=target_id, sources=sorted(sources))

	# A whole-page redirect can be a legitimate translation link, but its
	# URL is not the destination page's canonical URL. Report only effective
	# mappings here, not native-shadowed supplemental rows. This is a DB
	# observation, not proof of a live hreflang tag or target indexability.
	for source, page, expected, chosen in clusters:
		for code, (raw, mapped) in expected.items():
			if code == "fr" or not mapped.get("redirect") or mapped.get("redirect_status") != "valid":
				continue
			dest = mapped["destination"]
			check("HREFLANG_EFFECTIVE_REDIRECT", source=source, source_title=page["key"],
				lang=code, target=raw, redirect_title=mapped["key"], destination=dest["key"],
				destination_id=dest["id"], shared_target=len(target_sources[code, dest["id"]]) > 1)
			counts["hreflang_effective_redirect_targets"] += 1
		for lang, target in chosen.items():
			context = dict(source=source, source_title=page["key"], lang=lang, target=target["key"])
			if len(target_sources[lang, target["id"]]) > 1:
				counts["stored_targets_skipped_ambiguous"] += 1
				continue
			stored = editions[lang]["stored"]
			if str(target["id"]) not in stored:
				check("STORED_LINKS_ABSENT", **context)
				continue
			links = stored[str(target["id"])]
			if links is None:
				issue("STORED_LINKS_MALFORMED", **context)
				continue
			actual = defaultdict(set)
			for code, raw in links:
				actual[code].add(raw)
			counts["stored_targets_checked"] += 1
			if actual.get(lang):
				issue("STORED_SELF_PEER", actual=sorted(actual[lang]), **context)
			for code, (wanted_raw, wanted) in expected.items():
				if code == lang:
					continue
				wanted_identity = identity(wanted)
				if wanted_identity is None:
					counts["stored_peers_skipped_unverified_expected"] += 1
					continue
				display = wanted.get("key", wanted_raw)
				if not actual[code]:
					issue("STORED_PEER_MISSING", peer=code, expected=display, **context)
					continue
				resolved = [(raw, resolve(code, raw)) for raw in actual[code]]
				if any(p["status"] == "unverified" for _, p in resolved):
					check("STORED_PEER_UNVERIFIED", peer=code, expected=display,
						actual=sorted(actual[code]), **context)
				elif any(not equivalent(p, raw, wanted, wanted_raw) for raw, p in resolved):
					issue("STORED_PEER_MISMATCH", peer=code, expected=display,
						actual=sorted(actual[code]), **context)
				else:
					if actual[code] != {display}:
						counts["stored_peer_equivalent_namespace_forms"] += 1
						observe("STORED_PEER_EQUIVALENT", peer=code, expected=display,
							actual=sorted(actual[code]), **context)
					# The property is semantically correct, yet may still publish
					# a noncanonical alias. A normal purge alone cannot fix the
					# canonical target if the effective mapping still uses it.
					for raw, resolved_page in resolved:
						if resolved_page.get("redirect") and resolved_page.get("redirect_status") == "valid":
							check("HREFLANG_STORED_REDIRECT", peer=code, actual=raw,
								destination=resolved_page["destination"]["key"],
								destination_id=resolved_page["destination"]["id"], **context)
							counts["hreflang_stored_redirect_peers"] += 1
			for code in set(actual) - set(expected):
				if code in LANGS:
					issue("STORED_EXTRA_PEER", peer=code, actual=sorted(actual[code]), **context)
				else:
					check("STORED_OUTSIDE_CLUSTER", peer=code, actual=sorted(actual[code]), **context)
	# Inspect properties of former peers as well as current groups. The
	# snapshots are not one cross-wiki transaction, so a disconnected page
	# is a review item, not an instruction to delete anything.
	for lang in LANGS[1:]:
		edition = editions[lang]
		stored_pages = edition.get("stored_pages", {})
		for page_id, links in edition["stored"].items():
			counts["stored_properties_total"] += 1
			if (lang, int(page_id)) in target_sources:
				continue
			page = stored_pages.get(page_id)
			context = dict(lang=lang, page_id=int(page_id), target=page["key"] if page else None)
			if page is None:
				issue("STORED_ORPHAN_PAGE", **context)
			else:
				kind = "STORED_ON_REDIRECT" if page["redirect"] else "STORED_WITHOUT_EFFECTIVE_GROUP"
				check(kind, **context)
			if links is None:
				issue("STORED_LINKS_MALFORMED", **context)
			counts["stored_properties_without_effective_group"] += 1
	return dict(counts=dict(counts), issues=dict(issues), review=dict(review), observations=dict(observations))


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
	parser.add_argument("--config-dir", type=Path, help="Optional directory containing one LocalSettings.php per edition (disposable integration or separate installations)")
	parser.add_argument("--public", action="store_true", help="Only a bounded public API observation, no database access")
	parser.add_argument("--examples", type=int, default=5)
	parser.add_argument("--report", type=Path, help="Optional complete JSON report; not printed in full by default")
	args = parser.parse_args()
	if args.public:
		return public_observation()
	root = args.root.resolve()
	if not (root / "maintenance/run.php").is_file():
		parser.error("Run from the farm MediaWiki installation root, or set --root")
	config_dir = args.config_dir.resolve() if args.config_dir else None
	if config_dir is not None and not all((config_dir / lang / "LocalSettings.php").is_file() for lang in LANGS):
		parser.error("--config-dir must contain a LocalSettings.php for all six editions")
	with tempfile.TemporaryDirectory(prefix="ill-audit-") as tmp:
		directory = Path(tmp)
		central = snapshot(root, directory, "fr", "central", config_dir=config_dir)
		editions = {}
		for lang in LANGS[1:]:
			raws = [row[3] for row in central["links"] if row[2] == lang]
			editions[lang] = snapshot(root, directory, lang, "resolve", raws, config_dir=config_dir)
			print(f"SNAPSHOT {lang}: {len(set(raws))} distinct mapped titles", flush=True)
		# Resolve the actual persisted peer strings on their own editions as
		# well. This includes aliases not present in the central link tables.
		extra = defaultdict(set)
		for edition in editions.values():
			for links in edition["stored"].values():
				if links is not None:
					for code, raw in links:
						if code in LANGS:
							extra[code].add(raw)
		editions["fr"] = {"targets": {}, "stored": {}}
		for lang in LANGS:
			unresolved = extra[lang] - set(editions[lang]["targets"])
			if unresolved:
				identities = snapshot(root, directory, lang, "identity", unresolved, config_dir=config_dir)
				editions[lang]["targets"].update(identities["targets"])
				print(f"IDENTITIES {lang}: {len(unresolved)} additional persisted titles", flush=True)
		report = analyze(central, editions)
	print("\n=== COUNTS ===")
	for name, count in sorted(report["counts"].items()):
		print(f"{name}: {count}")
	for category, label in (("issues", "ERRORS"), ("review", "REVIEW"), ("observations", "INFORMATION")):
		print(f"\n=== {label} (stored data, not live HTML) ===")
		if not report[category]:
			print("No finding in this category")
		for name, rows in sorted(report[category].items()):
			print(f"{name}: {len(rows)}")
			for row in rows[:max(0, args.examples)]:
				print("\t" + json.dumps(row, ensure_ascii=False, sort_keys=True))
	if args.report:
		args.report.write_text(json.dumps(report, ensure_ascii=False, indent="\t") + "\n", encoding="utf-8")
		print(f"Full report: {args.report}")

if __name__ == "__main__":
	main()
