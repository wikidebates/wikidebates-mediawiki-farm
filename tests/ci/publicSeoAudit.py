#!/usr/bin/env python3
"""Small read-only SEO observation: six sitemap indexes, optional six home pages.

No recursive crawl, authentication, database access, purge or cache-busting.
Public access errors are observations, not proof of indexing problems.
"""
import argparse
from collections import Counter
from html.parser import HTMLParser
import json
import re
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

LANGS = ("de", "en", "es", "fr", "it", "pt")
# Editorial namespace selection, checked against public siteinfo on 2026-11-24.
# This is an audit expectation, not a second sitemap generator or live config.
SITEMAP_NAMESPACES = {
	lang: frozenset((0, 4, 12, 3000) if lang in ("fr", "en") else (0, 4, 12))
	for lang in LANGS
}
AGENT = "Wikidebates-public-SEO-audit/1.0"
XML_NS = "{http://www.sitemaps.org/schemas/sitemap/0.9}"
MAX_BYTES = 2 * 1024 * 1024
NAMESPACE_QUERY = {
	"action": "query", "meta": "siteinfo", "siprop": "namespaces|general", "format": "json",
}

# Fixed editorial samples, not a site crawl. Template candidates may be absent.
EDITORIAL_TITLES = {
	"fr": (
		"Wikidébats:Accueil", "Wikidébats:À propos",
		"Wikidébats:Laboratoire du débat méthodique", "Wikidébats:Principes fondateurs",
		"Wikidébats:Contribuer", "Wikidébats:Foire aux questions",
		"Portail:Débats", "Portail:Contributions",
		"Aide:Créer un débat", "Aide:Utiliser les pages de discussion",
		"Wikidébats:Exploitation pédagogique",
		"Wikidébats:Comparatif des encyclopédies de débats existantes",
		"Portail:Testez-vous", "Aide:Sommaire", "Aide:Tour de présentation",
		"Modèle:SEO projet", "Modèle:Métabalises",
	),
	"en": (
		"Wikidebates:Main Page", "Wikidebates:About",
		"Wikidebates:Laboratory of Methodical Debate", "Wikidebates:Founding Principles",
		"Wikidebates:Contribute", "Wikidebates:Frequently Asked Questions",
		"Help:Creating a debate", "Template:SEO project", "Template:Meta tags",
	),
	"de": ("Wikidebatten:Hauptseite",),
	"es": ("Wikidebates:Página principal", "Plantilla:Metaetiquetas"),
	"it": ("Wikidibattiti:Pagina principale",),
	"pt": ("Wikidebates:Página principal",),
}
EDITORIAL_PROPERTIES = (
	"shortdesc", "description", "manualDescription", "schema_type", "og_type",
	"title", "image", "canonical", "noindex", "page_image", "page_image_free",
)
LEGACY_TEMPLATES = {
	"fr": "Modèle:Métabalises",
	"en": "Template:Meta tags",
	"es": "Plantilla:Metaetiquetas",
}
TEMPLATE_SOURCE_LIMIT = 16384
TRANSCLUSION_LIMIT = 100
EDITORIAL_PREVIEW = 2400
EDITORIAL_TAIL = 1600
# Explicit assets actually used in pageprops/heads, plus the FR candidate in Git.
# Never fetch a host chosen by a page or by a free-form command-line argument.
SHARED_ASSETS = (
	"https://farm.wikidebates.org/w/resources/assets/logo-wikidebates-twitter.png",
	"https://fr.wikidebates.org/w/resources/assets/logo-Wikidebats-Twitter.png",
	"https://fr.wikidebates.org/w/resources/assets/logo-wikidebats-twitter.png",
	# Exact EN native i18n URL and the lowercase file tracked by the farm.
	"https://en.wikidebates.org/w/resources/assets/logo-Wikidebates-Twitter.png",
	"https://en.wikidebates.org/w/resources/assets/logo-wikidebates-twitter.png",
)
ASSET_ROBOTS = (
	"https://farm.wikidebates.org/robots.txt",
	"https://fr.wikidebates.org/robots.txt",
	"https://en.wikidebates.org/robots.txt",
)


def editorial_query(lang):
	return {
		"action": "query", "format": "json", "formatversion": "2",
		"titles": "|".join(EDITORIAL_TITLES[lang]),
		"prop": "info|pageprops|revisions", "inprop": "url",
		"ppprop": "|".join(EDITORIAL_PROPERTIES),
		"rvprop": "ids|content", "rvslots": "main",
	}


def transclusion_query(lang):
	return {
		"action": "query", "format": "json", "formatversion": "2",
		"list": "embeddedin", "eititle": LEGACY_TEMPLATES[lang],
		"eilimit": str(TRANSCLUSION_LIMIT),
	}


def same_origin(url, host):
	try:
		parts = urllib.parse.urlsplit(url)
		return (
			parts.scheme == "https" and parts.hostname == host
			and parts.port in (None, 443) and parts.username is None
			and parts.password is None and not parts.fragment
		)
	except ValueError:
		return False


def allowed_url(url, host, scope):
	if not same_origin(url, host) or any(c in url for c in ("\r", "\n", "\\")):
		return False
	parts = urllib.parse.urlsplit(url)
	if scope == "homepage":
		return not parts.query and (parts.path == "/" or parts.path.startswith("/wiki/"))
	if scope == "index":
		return not parts.query and parts.path.startswith("/w/sitemaps/") and parts.path.endswith(".xml")
	if scope == "namespaces":
		return (
			parts.path == "/w/api.php"
			and sorted(urllib.parse.parse_qsl(parts.query, keep_blank_values=True))
			== sorted(NAMESPACE_QUERY.items())
		)
	if scope == "editorial":
		lang = host.removesuffix(".wikidebates.org")
		return (lang in EDITORIAL_TITLES and host == lang + ".wikidebates.org"
			and parts.path == "/w/api.php"
			and sorted(urllib.parse.parse_qsl(parts.query, keep_blank_values=True))
			== sorted(editorial_query(lang).items()))
	if scope == "transclusions":
		lang = host.removesuffix(".wikidebates.org")
		return (lang in LEGACY_TEMPLATES and host == lang + ".wikidebates.org"
			and parts.path == "/w/api.php"
			and sorted(urllib.parse.parse_qsl(parts.query, keep_blank_values=True))
			== sorted(transclusion_query(lang).items()))
	if scope == "asset-body":
		return url in SHARED_ASSETS
	if scope == "asset-robots":
		return url in ASSET_ROBOTS
	if scope == "image":
		return (
			bool(re.search(r"\.(?:png|jpe?g|webp|gif|svg|avif|ico)$", parts.path, re.I))
			and (not parts.query or bool(re.fullmatch(r"version=[a-fA-F0-9]{32}", parts.query)))
		)
	return False


class OneRedirect(urllib.request.HTTPRedirectHandler):
	def __init__(self, host, scope):
		self.host = host
		self.scope = scope
		self.used = False

	def redirect_request(self, req, fp, code, msg, headers, newurl):
		if self.used or not allowed_url(newurl, self.host, self.scope):
			raise ValueError("Unexpected redirect")
		self.used = True
		# urllib's normal redirect handler can change HEAD into GET.
		redirected = super().redirect_request(req, fp, code, msg, headers, newurl)
		if redirected is not None:
			redirected.method = req.get_method()
		return redirected


def get(url, host, method="GET"):
	path = urllib.parse.urlsplit(url).path
	scope = "image" if method == "HEAD" else (
		"namespaces" if path == "/w/api.php" else (
			"index" if path.startswith("/w/sitemaps/") else "homepage"
		)
	)
	if method == "GET" and path == "/w/api.php" and allowed_url(url, host, "editorial"):
		scope = "editorial"
	elif method == "GET" and path == "/w/api.php" and allowed_url(url, host, "transclusions"):
		scope = "transclusions"
	elif method == "GET" and url in SHARED_ASSETS:
		scope = "asset-body"
	elif method == "GET" and url in ASSET_ROBOTS:
		scope = "asset-robots"
	if not allowed_url(url, host, scope):
		raise ValueError("Unexpected URL")
	opener = urllib.request.build_opener(OneRedirect(host, scope))
	request = urllib.request.Request(url, method=method, headers={
		"User-Agent": AGENT, "Accept-Encoding": "identity",
	})
	try:
		with opener.open(request, timeout=12) as response:
			if not allowed_url(response.geturl(), host, scope):
				raise ValueError("Unexpected final URL")
			body = response.read(MAX_BYTES + 1) if method == "GET" else b""
			if len(body) > MAX_BYTES:
				raise ValueError("Response exceeds limit")
			return response.status, response.geturl(), {
				"content-type": response.headers.get("Content-Type", ""),
				"x-robots-tag": response.headers.get("X-Robots-Tag", ""),
			}, body
	except urllib.error.HTTPError as exc:
		return exc.code, url, {}, b""


def sitemap_index(body, lang):
	# The native generator uses this namespace and no DTD. Reject DTDs,
	# non-UTF-8 content and oversized input before parsing.
	text = body.decode("utf-8-sig")
	if "<!DOCTYPE" in text.upper() or "<!ENTITY" in text.upper():
		raise ValueError("Unexpected XML declaration")
	root = ET.fromstring(text)
	if root.tag != XML_NS + "sitemapindex":
		raise ValueError("Not a sitemap index")
	host = lang + ".wikidebates.org"
	pattern = re.compile(
		r"/w/sitemaps/" + lang + r"/sitemap-" + lang
		+ r"wikidebates-NS_(-?\d+)-\d+\.xml(?:\.gz)?"
	)
	locations = []
	namespaces = Counter()
	unclassified = 0
	wrong_origin = 0
	for child in root:
		if child.tag != XML_NS + "sitemap":
			continue
		loc = (child.findtext(XML_NS + "loc") or "").strip()
		locations.append(loc)
		if not same_origin(loc, host):
			wrong_origin += 1
			continue
		parts = urllib.parse.urlsplit(loc)
		match = pattern.fullmatch(parts.path)
		if match and not parts.query:
			namespaces[match.group(1)] += 1
		else:
			unclassified += 1
	return {
		"child_count": len(locations), "files_by_namespace": dict(sorted(namespaces.items())),
		"duplicate_locations": len(locations) - len(set(locations)),
		"wrong_origin": wrong_origin, "unclassified": unclassified,
	}


def sitemap_policy(lang, index, inventory=None):
	"""Compare index files to the explicit editorial namespace selection."""
	allowed = SITEMAP_NAMESPACES[lang]
	result = {"allowed_namespaces": sorted(allowed), "status": "unverified"}
	if index.get("http") != 200 or "files_by_namespace" not in index:
		return result
	unexpected = sorted(int(key) for key in index["files_by_namespace"] if int(key) not in allowed)
	result["unexpected_namespaces"] = unexpected
	result["expected_without_files"] = sorted(allowed - {int(key) for key in index["files_by_namespace"]})
	# An empty editorial namespace is legitimate. The project namespace is
	# required here because all six actual main pages belong to it.
	result["project_present"] = "4" in index["files_by_namespace"]
	if inventory is not None:
		if inventory.get("http") != 200 or "namespaces" not in inventory:
			return result
		missing = sorted(allowed - {int(key) for key in inventory["namespaces"]})
		result["unknown_allowed_namespaces"] = missing
		if missing:
			result["status"] = "mismatch"
			return result
	result["status"] = "mismatch" if (
		unexpected or not result["project_present"] or index.get("duplicate_locations")
		or index.get("wrong_origin") or index.get("unclassified")
	) else "ok"
	return result


def namespace_inventory(body):
	"""Keep only namespace identity and the advertised editorial-content flag."""
	payload = json.loads(body)
	query = payload["query"]
	namespaces = query["namespaces"]
	general = query["general"]
	if not isinstance(namespaces, dict) or not isinstance(general, dict):
		raise ValueError("Invalid siteinfo")
	result = {}
	for key, item in namespaces.items():
		if not isinstance(item, dict) or not isinstance(item.get("id"), int):
			raise ValueError("Invalid namespace")
		if str(item["id"]) != key:
			raise ValueError("Namespace ID mismatch")
		if not isinstance(item.get("*"), str):
			raise ValueError("Missing namespace name")
		result[key] = {
			"name": item["*"],
			"canonical": item.get("canonical", ""),
			"content": "content" in item,
		}
	if "0" not in result or "4" not in result:
		raise ValueError("Missing standard namespaces")
	return {
		"mainpage": general.get("mainpage", ""),
		"namespaces": result,
	}


def audit_namespaces(lang, opener=get):
	host = lang + ".wikidebates.org"
	url = "https://" + host + "/w/api.php?" + urllib.parse.urlencode(NAMESPACE_QUERY)
	try:
		status, _, _, body = opener(url, host)
		result = {"http": status}
		if status == 200:
			result.update(namespace_inventory(body))
		return result
	except (ValueError, TypeError, KeyError, OSError, RecursionError) as exc:
		return {"unverified": type(exc).__name__}

def editorial_inventory(body, lang):
	payload = json.loads(body)
	if "error" in payload:
		return {"status": "api_error", "error": payload["error"].get("code")}
	if "continue" in payload:
		return {"status": "incomplete", "reason": "API continuation"}
	query = payload["query"]
	pages = query["pages"]
	if not isinstance(pages, list):
		raise ValueError("Expected formatversion 2 page list")
	normalized = {
		item["from"]: item["to"] for item in query.get("normalized", [])
	}
	expected = {normalized.get(title, title) for title in EDITORIAL_TITLES[lang]}
	seen = set()
	rows = []
	for page in pages:
		title = page["title"]
		if title not in expected or title in seen:
			raise ValueError("Unexpected or duplicate editorial page")
		seen.add(title)
		row = {"title": title}
		if page.get("missing") or page.get("invalid"):
			row["status"] = "missing"
		else:
			row.update(status="redirect" if page.get("redirect") else "ok",
				pageid=page.get("pageid"), canonicalurl=page.get("canonicalurl"))
			props = page.get("pageprops", {})
			row["pageprops"] = {k: props[k] for k in EDITORIAL_PROPERTIES if k in props}
			revisions = page.get("revisions", [])
			if revisions:
				revision = revisions[0]
				row["revid"] = revision.get("revid")
				content = revision.get("slots", {}).get("main", {}).get("content")
				if isinstance(content, str):
					limit = (TEMPLATE_SOURCE_LIMIT if title == normalized.get(
						LEGACY_TEMPLATES.get(lang), LEGACY_TEMPLATES.get(lang)) else EDITORIAL_PREVIEW)
					row["wikitext_preview"] = content[:limit]
					row["wikitext_truncated"] = len(content) > limit
					if row["wikitext_truncated"]:
						row["wikitext_tail"] = content[max(limit, len(content) - EDITORIAL_TAIL):]
			if "wikitext_preview" not in row:
				row["source_unverified"] = True
		rows.append(row)
	return {"status": "ok" if seen == expected else "incomplete",
		"missing_results": sorted(expected - seen), "pages": rows}


def audit_editorial(lang, opener=get):
	host = lang + ".wikidebates.org"
	url = "https://" + host + "/w/api.php?" + urllib.parse.urlencode(editorial_query(lang))
	try:
		status, _, _, body = opener(url, host)
		result = {"http": status, "status": "unverified"}
		if status == 200:
			result.update(editorial_inventory(body, lang))
		return result
	except (ValueError, TypeError, KeyError, OSError, RecursionError) as exc:
		return {"status": "unverified", "reason": type(exc).__name__}


def transclusion_inventory(body):
	payload = json.loads(body)
	if "error" in payload:
		return {"status": "api_error", "error": payload["error"].get("code")}
	pages = payload["query"]["embeddedin"]
	if not isinstance(pages, list) or len(pages) > TRANSCLUSION_LIMIT:
		raise ValueError("Unexpected transclusion list")
	seen = set()
	rows = []
	for page in pages:
		pageid, ns, title = page["pageid"], page["ns"], page["title"]
		if (type(pageid) is not int or pageid <= 0 or pageid in seen
			or type(ns) is not int or not isinstance(title, str) or not title):
			raise ValueError("Unexpected or duplicate transclusion")
		seen.add(pageid)
		rows.append({"pageid": pageid, "ns": ns, "title": title})
	return {"status": "incomplete" if "continue" in payload else "ok",
		"count_observed": len(rows), "pages": rows}


def audit_transclusions(lang, opener=get):
	host = lang + ".wikidebates.org"
	url = "https://" + host + "/w/api.php?" + urllib.parse.urlencode(transclusion_query(lang))
	try:
		status, _, _, body = opener(url, host)
		result = {"template": LEGACY_TEMPLATES[lang], "http": status, "status": "unverified"}
		if status == 200:
			result.update(transclusion_inventory(body))
		return result
	except (ValueError, TypeError, KeyError, OSError, RecursionError) as exc:
		return {"template": LEGACY_TEMPLATES[lang], "status": "unverified",
			"reason": type(exc).__name__}


def png_dimensions(body):
	"""Check the PNG signature and IHDR, not full image decoding."""
	if (len(body) < 33 or body[:8] != b"\x89PNG\r\n\x1a\n"
		or body[8:16] != b"\x00\x00\x00\rIHDR"):
		raise ValueError("Invalid PNG header")
	width = int.from_bytes(body[16:20], "big")
	height = int.from_bytes(body[20:24], "big")
	if not width or not height or max(width, height) > 0x7fffffff:
		raise ValueError("Invalid PNG dimensions")
	return width, height


def audit_shared_assets(opener=get):
	# Reuse the existing robots policy parser for observations, not a
	# full simulation of a search engine's robots matching algorithm.
	from robotsPolicy import parse as parse_robots
	images = []
	robots = []
	for url in SHARED_ASSETS:
		row = {"url": url, "status": "unverified"}
		try:
			code, final, headers, body = opener(url, urllib.parse.urlsplit(url).hostname)
			row.update(http=code, final_url=final, content_type=headers.get("content-type", ""),
				x_robots_tag=headers.get("x-robots-tag", ""))
			if code == 200 and row["content_type"].split(";", 1)[0].strip().lower() == "image/png":
				width, height = png_dimensions(body)
				row.update(status="observed", width=width, height=height, bytes=len(body))
		except (ValueError, OSError) as exc:
			row["reason"] = type(exc).__name__
		images.append(row)
	for url in ASSET_ROBOTS:
		row = {"url": url, "status": "unverified"}
		try:
			code, final, headers, body = opener(url, urllib.parse.urlsplit(url).hostname)
			row.update(http=code, final_url=final, content_type=headers.get("content-type", ""))
			if code == 200 and row["content_type"].split(";", 1)[0].strip().lower() == "text/plain":
				if len(body) > 500 * 1024:
					raise ValueError("Robots response exceeds 500 KiB")
				parsed = parse_robots(body.decode("utf-8-sig"))
				row["relevant_groups"] = {agent: sorted(rules) for agent, rules in parsed["groups"].items()
					if agent == "*" or agent.startswith(("google", "bing", "twitter", "facebook", "meta"))}
				row["other_directives"] = parsed["other"]
				row["status"] = "observed"
		except (ValueError, OSError) as exc:
			row["reason"] = type(exc).__name__
		robots.append(row)
	return {"images": images, "robots": robots,
		"scope": "Five fixed PNG GETs and three robots.txt GETs; at most one permitted redirect each."}


class Head(HTMLParser):
	def __init__(self):
		super().__init__(convert_charrefs=True)
		self.done = False
		self.tags = {}
		self.canonicals = []
		self.alternates = []
		self.scripts = []
		self.script = None

	def handle_starttag(self, tag, attrs):
		if self.done:
			return
		a = dict(attrs)
		if tag == "body":
			self.done = True
		elif tag == "meta":
			name = (a.get("name") or a.get("property") or "").lower()
			if name in ("description", "robots", "googlebot", "og:url", "og:site_name",
				"og:title", "og:description", "og:type", "og:image", "og:image:secure_url",
				"og:image:width", "og:image:height", "og:image:type", "og:image:alt",
				"twitter:title", "twitter:description", "twitter:image", "twitter:image:alt", "twitter:card"):
				self.tags.setdefault(name, []).append(a.get("content") or "")
		elif tag == "link":
			rels = (a.get("rel") or "").lower().split()
			if "canonical" in rels:
				self.canonicals.append(a.get("href") or "")
			if "alternate" in rels and a.get("hreflang"):
				self.alternates.append({"lang": a["hreflang"], "url": a.get("href") or ""})
		elif tag == "script" and (a.get("type") or "").lower() == "application/ld+json":
			self.script = []

	def handle_data(self, data):
		if not self.done and self.script is not None:
			self.script.append(data)

	def handle_endtag(self, tag):
		if tag == "script" and self.script is not None:
			self.scripts.append("".join(self.script))
			self.script = None
		elif tag == "head":
			self.done = True


def homepage(body):
	head = Head()
	head.feed(body.decode("utf-8", errors="replace"))
	types = []
	identities = []
	jsonld_images = []
	invalid_json = 0
	for script in head.scripts:
		try:
			value = json.loads(script)
		except (ValueError, RecursionError):
			invalid_json += 1
			continue
		nodes = value if isinstance(value, list) else [value]
		for node in nodes:
			if not isinstance(node, dict):
				continue
			graph = node.get("@graph", [])
			for item in [node] + (graph if isinstance(graph, list) else []):
				if isinstance(item, dict):
					kind = item.get("@type", [])
					if isinstance(kind, str):
						kind = [kind]
					if isinstance(kind, list):
						types.extend(x for x in kind if isinstance(x, str))
						if any(x in ("WebSite", "WebPage", "AboutPage", "CollectionPage", "Organization") for x in kind):
							identity = {k: item[k] for k in ("@id", "name", "url") if isinstance(item.get(k), str)}
							identity["@type"] = [x for x in kind if isinstance(x, str)]
							publisher = item.get("publisher")
							if isinstance(publisher, dict) and isinstance(publisher.get("@id"), str):
								identity["publisher_id"] = publisher["@id"]
							identities.append(identity)
					images = item.get("image", [])
					for image in images if isinstance(images, list) else [images]:
						if isinstance(image, str):
							jsonld_images.append({"url": image})
						elif isinstance(image, dict):
							jsonld_images.append({k: image[k] for k in ("url", "contentUrl", "width", "height", "caption")
								if isinstance(image.get(k), (str, int, float))})
	return head, {
		"canonical_count": len(head.canonicals), "canonicals": head.canonicals,
		"descriptions": len([v for v in head.tags.get("description", []) if v.strip()]),
		"robots": head.tags.get("robots", []), "googlebot": head.tags.get("googlebot", []),
		"og_urls": head.tags.get("og:url", []),
		"site_names": head.tags.get("og:site_name", []),
		"jsonld_scripts": len(head.scripts), "jsonld_invalid": invalid_json,
		"jsonld_types": sorted(set(types)),
		"jsonld_identities": identities, "jsonld_images": jsonld_images,
		"hreflang": head.alternates,
		"metadata": {k: v for k, v in head.tags.items()
			if k == "description" or k.startswith(("og:", "twitter:"))},
	}


def audit(lang, opener=get, include_home=False):
	host = lang + ".wikidebates.org"
	root = "https://" + host
	result = {"language": lang}
	try:
		status, _, _, body = opener(
			root + "/w/sitemaps/" + lang + "/sitemap-index-" + lang + "wikidebates.xml",
			host,
		)
		index = {"http": status}
		if status == 200:
			index.update(sitemap_index(body, lang))
		result["sitemap"] = index
	except (ValueError, ET.ParseError, OSError, UnicodeError) as exc:
		result["sitemap"] = {"unverified": type(exc).__name__}
	if not include_home:
		return result
	try:
		status, final, headers, body = opener(root + "/", host)
		page = {"http": status, "final_url": final}
		if status == 200 and "text/html" in headers.get("content-type", "").lower():
			head, details = homepage(body)
			page.update(details)
			page["x_robots_tag"] = headers.get("x-robots-tag", "")
			images = head.tags.get("og:image", [])
			if images:
				image = urllib.parse.urljoin(final, images[0])
				if images[0] and allowed_url(image, host, "image"):
					try:
						code, _, image_headers, _ = opener(image, host, "HEAD")
						page["image_probe"] = {
							"http": code, "content_type": image_headers.get("content-type", ""),
							"x_robots_tag": image_headers.get("x-robots-tag", ""),
						}
					except (ValueError, OSError) as exc:
						page["image_probe"] = {"unverified": type(exc).__name__}
				else:
					page["image_probe"] = {"unverified": "not a same-host static image URL"}
		result["homepage"] = page
	except (ValueError, OSError, UnicodeError) as exc:
		result["homepage"] = {"unverified": type(exc).__name__}
	return result


def main():
	parser = argparse.ArgumentParser(description=__doc__)
	parser.add_argument("--lang", choices=LANGS, action="append",
		help="limit the audit to selected languages")
	parser.add_argument("--all-homepages", action="store_true",
		help="observe the homepage on every selected edition, not just FR/EN")
	parser.add_argument("--shared-assets", action="store_true",
		help="check five explicitly reviewed social PNGs and three robots.txt files")
	parser.add_argument("--editorial-inventory", action="store_true",
		help="read fixed institutional page properties and bounded latest wikitext excerpts")
	parser.add_argument("--template-inventory", action="store_true",
		help="list at most 100 public transclusions per fixed FR/EN/ES legacy template")
	parser.add_argument("--namespace-inventory", action="store_true",
		help="also request the public namespace inventory once per selected wiki")
	parser.add_argument("--expect-sitemap-policy", action="store_true",
		help="fail if a selected live index does not match the documented policy")
	args = parser.parse_args()
	langs = list(dict.fromkeys(args.lang or LANGS))
	results = []
	for lang in langs:
		entry = audit(lang, include_home=args.all_homepages or lang in ("fr", "en"))
		if args.namespace_inventory:
			entry["namespace_inventory"] = audit_namespaces(lang)
		if args.editorial_inventory:
			entry["editorial_inventory"] = audit_editorial(lang)
		if args.template_inventory and lang in LEGACY_TEMPLATES:
			entry["template_inventory"] = audit_transclusions(lang)
		entry["sitemap_policy"] = sitemap_policy(lang, entry["sitemap"], entry.get("namespace_inventory"))
		results.append(entry)
	print(json.dumps({
		"read_only": True,
		"scope": ("indexes; all selected homepages and at most one same-host OG image HEAD per homepage"
			if args.all_homepages else "indexes; FR/EN homepage and one same-host OG image HEAD per homepage"),
		"namespace_inventory": args.namespace_inventory,
		"editorial_inventory": args.editorial_inventory,
		"template_inventory": args.template_inventory,
		**({"shared_assets": audit_shared_assets()} if args.shared_assets else {}),
		"results": results,
	}, indent=2, ensure_ascii=False))
	return int(args.expect_sitemap_policy and any(
		entry["sitemap_policy"]["status"] != "ok" for entry in results
	))


if __name__ == "__main__":
	raise SystemExit(main())
