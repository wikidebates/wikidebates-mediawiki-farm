#!/usr/bin/env python3
"""Bounded anonymous observation of native SEO; run again after cache rollout.

Only GET fixed FR/EN wiki pages using the existing bounded HTTP helper.
No crawl, cache-busting, purges, API writes, or requests to linked websites.
"""
import argparse
from datetime import datetime
from html.parser import HTMLParser
import json
import re
from urllib.parse import quote, unquote, urlsplit

from publicSeoAudit import allowed_url, get

# All five canonical routes observed on 2026-11-24. A missing or redirected
# sample is reported, never treated as a successful test.
SAMPLES = (
	("fr", "debate", "Un revenu de base doit-il être instauré ?", "debate"),
	("en", "debate", "Should a basic income be introduced?", "debate"),
	("fr", "argument", "Le revenu de base apporte de meilleures conditions de vie", "argument"),
	("en", "argument", "Basic income brings better living conditions", "argument"),
	("fr", "argument", "Le revenu de base permet de lutter contre la pauvreté", None),
)

class Document(HTMLParser):
	def __init__(self):
		super().__init__(convert_charrefs=True)
		self.in_head = False
		self.tags = {}
		self.canonicals = []
		self.alternates = {}
		self.scripts = []
		self.script = None

	def handle_starttag(self, tag, attrs):
		a = dict(attrs)
		if tag == "head":
			self.in_head = True
		elif tag == "body":
			self.in_head = False
		if tag == "script" and (a.get("type") or "").lower() == "application/ld+json":
			self.script = (self.in_head, [])
		if not self.in_head:
			return
		if tag == "meta":
			key = (a.get("name") or a.get("property") or "").lower()
			self.tags.setdefault(key, []).append(a.get("content") or "")
		elif tag == "link":
			rel = (a.get("rel") or "").lower().split()
			if "canonical" in rel:
				self.canonicals.append(a.get("href") or "")
			if "alternate" in rel and a.get("hreflang"):
				self.alternates.setdefault(a["hreflang"].lower(), []).append(a.get("href") or "")

	def handle_data(self, data):
		if self.script is not None:
			self.script[1].append(data)

	def handle_endtag(self, tag):
		if tag == "script" and self.script is not None:
			self.scripts.append((self.script[0], "".join(self.script[1])))
			self.script = None
		elif tag == "head":
			self.in_head = False

def page_key(url):
	"""Normalize percent encoding only in the path, not query/fragment/host."""
	if not isinstance(url, str):
		return None
	try:
		u = urlsplit(url)
		if u.hostname not in {lang + ".wikidebates.org" for lang in ("fr","en","de","es","it","pt")}:
			return None
		if not allowed_url(url, u.hostname, "homepage") or not u.path.startswith("/wiki/"):
			return None
		return (u.hostname, unquote(u.path))
	except ValueError:
		return None

def kinds(node):
	value = node.get("@type", []) if isinstance(node, dict) else []
	return [value] if isinstance(value, str) else value if isinstance(value, list) else []

def timestamp(value):
	if not isinstance(value, str) or not re.fullmatch(r"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ", value):
		return None
	try:
		return datetime.fromisoformat(value.replace("Z", "+00:00"))
	except ValueError:
		return None

def has_marker(value):
	if isinstance(value, dict):
		return any(has_marker(v) for v in value.values())
	if isinstance(value, list):
		return any(has_marker(v) for v in value)
	return isinstance(value, str) and bool(re.search(r"UNIQ-[^\r\n]{0,180}-QINU", value))

def inspect(body, final, headers, lang, kind):
	doc = Document()
	doc.feed(body.decode("utf-8"))
	doc.close()
	issues = []
	def require(condition, message):
		if not condition:
			issues.append(message)
	def tag(name, expected):
		require(doc.tags.get(name) == [expected] and isinstance(expected, str) and bool(expected.strip()),
			"missing, duplicate or inconsistent " + name)
	canonical = doc.canonicals[0] if len(doc.canonicals) == 1 else None
	require(page_key(canonical) is not None and page_key(canonical) == page_key(final),
		"canonical missing, duplicate or different from final page")
	require(len(doc.tags.get("og:url", [])) == 1 and
		page_key(doc.tags["og:url"][0]) == page_key(canonical) and page_key(canonical) is not None,
		"og:url differs from canonical")
	robots = doc.tags.get("robots", []) + doc.tags.get("googlebot", []) + [headers.get("x-robots-tag", "")]
	require(not any(re.search(r"\b(?:noindex|none)\b", x, re.I) for x in robots), "sample unexpectedly noindex")
	require(not has_marker(doc.tags), "unresolved marker in head")
	tag("og:type", "article" if kind == "argument" else "website")
	tag("og:locale", "fr_FR" if lang == "fr" else "en_US")
	titles = doc.tags.get("og:title", [])
	require(len(titles) == 1 and bool(titles[0].strip()) and doc.tags.get("twitter:title") == titles,
		"missing, duplicate or inconsistent social titles")
	require(doc.tags.get("og:image", []) == doc.tags.get("twitter:image", []) and
		len(doc.tags.get("og:image", [])) <= 1, "social images differ or are duplicated")
	# Native pages must emit a single body graph, not competing WikiSEO head graphs.
	require(len(doc.scripts) == 1 and not doc.scripts[0][0], "expected one native JSON-LD body script")
	graphs = []
	for _, raw in doc.scripts:
		try:
			value = json.loads(raw)
			require(not has_marker(value), "unresolved marker in JSON-LD")
			if isinstance(value, dict) and isinstance(value.get("@graph"), list):
				graphs.extend(n for n in value["@graph"] if isinstance(n, dict))
		except (ValueError, RecursionError):
			issues.append("invalid JSON-LD")
	pages = [n for n in graphs if {"WebPage","LearningResource"} <= set(kinds(n))]
	require(not any("QAPage" in kinds(n) for n in graphs), "historical QAPage remains")
	require(len(pages) == 1, "native WebPage/LearningResource missing or duplicated")
	stats = {}
	if len(pages) == 1:
		page = pages[0]
		require(page_key(page.get("url")) == page_key(canonical) and page_key(canonical) is not None,
			"graph URL differs from canonical")
		require(page.get("inLanguage") == lang, "graph language differs")
		for field in ("description", "og:description", "twitter:description"):
			tag(field, page.get("description"))
		published, modified = page.get("datePublished"), page.get("dateModified")
		p, m = timestamp(published), timestamp(modified)
		require(p is not None and m is not None and p <= m, "missing, invalid or reversed page dates")
		entity = page.get("mainEntity")
		if kind == "debate":
			require(isinstance(entity, dict) and "Question" in kinds(entity), "missing Question")
			owner = page
			for field in ("article:published_time","article:modified_time"):
				require(field not in doc.tags, "article date on debate: " + field)
			if isinstance(entity, dict):
				require(not any(k in entity for k in ("datePublished","suggestedAnswer","answerCount")), "legacy Question fields")
		else:
			claim = [n for n in graphs if "Claim" in kinds(n) and isinstance(entity, dict)
				and n.get("@id") and n["@id"] == entity.get("@id")]
			require(len(claim) == 1, "missing linked Claim")
			owner = claim[0] if len(claim) == 1 else {}
			require("datePublished" not in owner, "page date assigned to Claim")
			tag("article:published_time", published)
			tag("article:modified_time", modified)
		require("subjectOf" not in owner, "legacy documentary subjectOf")
		citations = owner.get("citation", [])
		require(isinstance(citations, list), "citations not a list")
		parts = page.get("hasPart", [])
		counts = []
		for part in parts if isinstance(parts, list) else []:
			items = part.get("mainEntity") if isinstance(part, dict) else None
			if not isinstance(items, dict) or "ItemList" not in kinds(items):
				continue
			entries = items.get("itemListElement")
			require(isinstance(entries, list) and bool(entries) and items.get("numberOfItems") == len(entries),
				"ItemList count differs")
			if isinstance(entries, list):
				counts.append(len(entries))
				for i, item in enumerate(entries, 1):
					require(isinstance(item, dict) and item.get("position") == i, "ItemList positions differ")
		stats = {"citation_count": len(citations) if isinstance(citations, list) else None,
			"item_list_sizes": counts, "datePublished": published, "dateModified": modified}
	for code, urls in doc.alternates.items():
		require(len(urls) == 1 and page_key(urls[0]) is not None and
			page_key(urls[0])[0] == code + ".wikidebates.org", "invalid or duplicate hreflang " + code)
	if doc.alternates:
		require(len(doc.alternates.get(lang, [])) == 1 and
			page_key(doc.alternates[lang][0]) == page_key(canonical), "self hreflang differs from canonical")
	return {"status": "mismatch" if issues else "ok", "issues": issues,
		"canonical": canonical, "hreflang": doc.alternates, "jsonld_scripts": len(doc.scripts),
		"social_images": {key: doc.tags.get(key, []) for key in (
			"og:image", "og:image:secure_url", "og:image:type", "og:image:width", "og:image:height",
			"og:image:alt", "twitter:image", "twitter:image:alt")}, **stats}

def observe(sample, opener=get):
	lang, kind, title, pair = sample
	host = lang + ".wikidebates.org"
	url = "https://" + host + "/wiki/" + quote(title.replace(" ", "_"), safe=":")
	result = {"lang": lang, "kind": kind, "title": title, "pair": pair, "requested": url, "status": "unverified"}
	try:
		status, final, headers, body = opener(url, host)
		result.update(http=status, final=final)
		if status == 200 and "text/html" in headers.get("content-type", "").lower():
			result.update(inspect(body, final, headers, lang, kind))
	except (ValueError, OSError, RecursionError, TypeError) as exc:
		result["error"] = type(exc).__name__
	return result

def pair_results(rows):
	results = []
	for pair in sorted({r["pair"] for r in rows if r["pair"] is not None}):
		members = [r for r in rows if r["pair"] == pair]
		if len(members) != 2 or any(not page_key(r.get("canonical")) for r in members):
			results.append({"pair": pair, "status": "unverified"})
			continue
		ok = True
		for a, b in (members, members[::-1]):
			urls = a.get("hreflang", {}).get(b["lang"], [])
			ok = ok and len(urls) == 1 and page_key(urls[0]) == page_key(b["canonical"])
		results.append({"pair": pair, "status": "ok" if ok else "mismatch"})
	return results

def main():
	parser = argparse.ArgumentParser(description=__doc__)
	parser.add_argument("--strict", action="store_true", help="require refreshed native pages; only after deployment/cache renewal")
	parser.add_argument("--expect-pairs", action="store_true", help="also require FR/EN reciprocity for the two known thematic pairs; confirm editorial mappings first")
	args = parser.parse_args()
	rows = [observe(s) for s in SAMPLES]
	pairs = pair_results(rows)
	print(json.dumps({"read_only": True, "samples": rows, "reciprocity": pairs,
		"scope": "Five fixed pages, at most ten HTTPS GETs including same-host redirects. No external targets fetched."},
		ensure_ascii=False, indent=2))
	return int((args.strict and any(r["status"] != "ok" for r in rows)) or
		(args.expect_pairs and any(r["status"] != "ok" for r in pairs)))

if __name__ == "__main__":
	raise SystemExit(main())
