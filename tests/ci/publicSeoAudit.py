#!/usr/bin/env python3
"""Small read-only SEO observation: six sitemap indexes, FR/EN home pages.

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


class Head(HTMLParser):
	def __init__(self):
		super().__init__(convert_charrefs=True)
		self.done = False
		self.tags = {}
		self.canonicals = []
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
				"og:image", "twitter:image", "twitter:card"):
				self.tags.setdefault(name, []).append(a.get("content") or "")
		elif tag == "link" and "canonical" in (a.get("rel") or "").lower().split():
			self.canonicals.append(a.get("href") or "")
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
	return head, {
		"canonical_count": len(head.canonicals), "canonicals": head.canonicals,
		"descriptions": len([v for v in head.tags.get("description", []) if v.strip()]),
		"robots": head.tags.get("robots", []), "googlebot": head.tags.get("googlebot", []),
		"og_urls": head.tags.get("og:url", []),
		"site_names": head.tags.get("og:site_name", []),
		"jsonld_scripts": len(head.scripts), "jsonld_invalid": invalid_json,
		"jsonld_types": sorted(set(types)),
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
	parser.add_argument("--namespace-inventory", action="store_true",
		help="also request the public namespace inventory once per selected wiki")
	parser.add_argument("--expect-sitemap-policy", action="store_true",
		help="fail if a selected live index does not match the documented policy")
	args = parser.parse_args()
	langs = list(dict.fromkeys(args.lang or LANGS))
	results = []
	for lang in langs:
		entry = audit(lang, include_home=lang in ("fr", "en"))
		if args.namespace_inventory:
			entry["namespace_inventory"] = audit_namespaces(lang)
		entry["sitemap_policy"] = sitemap_policy(lang, entry["sitemap"], entry.get("namespace_inventory"))
		results.append(entry)
	print(json.dumps({
		"read_only": True, "scope": "indexes only; FR/EN homepage and one same-host OG image HEAD",
		"namespace_inventory": args.namespace_inventory,
		"results": results,
	}, indent=2, ensure_ascii=False))
	return int(args.expect_sitemap_policy and any(
		entry["sitemap_policy"]["status"] != "ok" for entry in results
	))


if __name__ == "__main__":
	raise SystemExit(main())
