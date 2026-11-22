#!/usr/bin/env python3
"""Bounded, anonymous, read-only audit of public HTML response cache headers.

At most three GET requests per site: siteinfo plus its main page with desktop
and mobile User-Agents. Never inspect non-public pages, bypass cookies, send
write requests, purge caches or interpret HTTP observations as CDN rules.
"""
import json
import os
from pathlib import Path
import sys
import urllib.error
import urllib.parse
import urllib.request

SITES = {"fr": "fr.wikidebates.org", "en": "en.wikidebates.org"}
HEADERS = (
	"cf-cache-status", "age", "cache-control", "cdn-cache-control",
	"cloudflare-cdn-cache-control", "content-type", "vary", "x-minerva-file-cache",
)
AGENTS = {
	"desktop": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36",
	"mobile": "Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36",
}
REUSED = {"HIT", "STALE", "UPDATING", "REVALIDATED"}
NOT_STORED_OBSERVED = {"DYNAMIC", "BYPASS"}
ELIGIBLE = {"MISS", "EXPIRED"}


def safe_headers(headers):
	"""Never export cookies, identifiers, arbitrary origin headers or HTML."""
	result = {}
	for key in HEADERS:
		value = headers.get(key)
		if value is not None:
			result[key] = "".join(c for c in value if c.isprintable())[:240]
	return result


def classify(status, headers):
	if status != 200 or "text/html" not in headers.get("content-type", "").lower():
		return "unverified"
	cf_status = headers.get("cf-cache-status", "").upper()
	if cf_status in REUSED:
		return "edge-reuse-observed"
	if cf_status in NOT_STORED_OBSERVED:
		return "not-reused-observed"
	if cf_status in ELIGIBLE:
		return "eligible-or-revalidated-later"
	return "unverified"


def get(url, agent, max_bytes):
	req = urllib.request.Request(url, headers={
		"User-Agent": agent, "Accept": "application/json" if max_bytes else "text/html",
		"Accept-Encoding": "identity", "Cache-Control": "no-transform",
	}, method="GET")
	try:
		with urllib.request.urlopen(req, timeout=15) as response:
			final = urllib.parse.urlsplit(response.geturl())
			expected = urllib.parse.urlsplit(url)
			if final.scheme != "https" or final.hostname != expected.hostname:
				raise ValueError("Cross-host or non-HTTPS redirect")
			body = response.read(max_bytes + 1 if max_bytes else 1)
			if max_bytes and len(body) > max_bytes:
				raise ValueError("Public siteinfo exceeded bounded response size")
			return response.status, safe_headers(response.headers), body
	except urllib.error.HTTPError as error:
		return error.code, safe_headers(error.headers), b""


def main_page_url(host, general):
	mainpage = general.get("mainpage")
	articlepath = general.get("articlepath")
	if not isinstance(mainpage, str) or not mainpage or not isinstance(articlepath, str):
		raise ValueError("Missing public main page and article path")
	if not articlepath.startswith("/") or not articlepath.count("$1") == 1:
		raise ValueError("Unexpected article path format")
	if any(c in articlepath for c in ("?", "#", "\\", "\r", "\n")):
		raise ValueError("Unsafe article path in public siteinfo")
	title = urllib.parse.quote(mainpage.replace(" ", "_"), safe=":/()")
	path = articlepath.replace("$1", title)
	return "https://" + host + path


def audit_site(language, host, opener=get):
	result = {"site": language, "api": "unverified", "html": []}
	api_url = "https://" + host + "/w/api.php?" + urllib.parse.urlencode({
		"action": "query", "meta": "siteinfo", "siprop": "general", "format": "json"
	})
	try:
		status, headers, body = opener(api_url, AGENTS["desktop"], 131072)
		result["api_status"] = status
		result["api_headers"] = headers
		if status != 200:
			return result
		payload = json.loads(body)
		general = payload["query"]["general"]
		if not isinstance(general, dict):
			raise ValueError("Invalid public siteinfo")
		url = main_page_url(host, general)
		result["api"] = "ok"
		for skin, agent in AGENTS.items():
			try:
				page_status, page_headers, _ = opener(url, agent, 0)
				result["html"].append({
					"variant": skin, "http_status": page_status, "headers": page_headers,
					"observation": classify(page_status, page_headers)
				})
			except (urllib.error.URLError, TimeoutError, ValueError, OSError) as error:
				result["html"].append({
					"variant": skin, "observation": "unverified",
					"reason": type(error).__name__
				})
	except (urllib.error.URLError, TimeoutError, ValueError, KeyError, TypeError, OSError,
		json.JSONDecodeError) as error:
		result["reason"] = type(error).__name__
	return result


def run():
	results = [audit_site(name, host) for name, host in SITES.items()]
	html = [item for site in results for item in site["html"]]
	report = {
		"method": "GET", "read_only": True, "requests_maximum": 6,
		"cdn_rules_inspected": False, "origin_server_inspected": False,
		"site_count": len(results), "results": results,
		"edge_reuse_observed": any(x["observation"] == "edge-reuse-observed" for x in html),
		"incomplete": any(site["api"] != "ok" or len(site["html"]) != 2 for site in results) or
			any(x["observation"] == "unverified" for x in html),
	}
	Path("wikidebates-public-cdn-headers.json").write_text(
		json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
	)
	lines = [
		"# Wikidébats — CDN HTML, observation en lecture seule", "",
		"Au maximum 6 GET anonymes sur des pages publiquement identifiées.",
		"Ces résultats n'inventorient **ni les règles Cloudflare ni la configuration du serveur**.",
		"", "| Wiki | Vue | HTTP | CF-Cache-Status | Cache-Control | Conclusion locale |",
		"|---|---|---:|---|---|---|"
	]
	for site in results:
		for item in site["html"]:
			h = item.get("headers", {})
			lines.append("| " + " | ".join([
				site["site"], item["variant"], str(item.get("http_status", "?")),
				h.get("cf-cache-status", "?").replace("|", "/"),
				h.get("cache-control", "?").replace("|", "/"),
				item["observation"],
			]) + " |")
		if site["api"] != "ok":
			lines.append("| " + site["site"] + " | API siteinfo | " +
				str(site.get("api_status", "?")) + " | ? | ? | unverified |")
	lines.append("")
	if report["edge_reuse_observed"]:
		lines.append("**ALERTE : une réponse HTML a été réutilisée au CDN.** "
			"Vérifier les règles de cache avant toute nouvelle restriction d'accès.")
	elif report["incomplete"]:
		lines.append("**Audit incomplet :** une absence de preuve ne valide pas la sécurité du CDN.")
	else:
		lines.append("Aucun HIT Edge observé sur ces échantillons ; cela ne valide pas les règles privées.")
	summary = os.getenv("GITHUB_STEP_SUMMARY")
	if summary:
		with open(summary, "a", encoding="utf-8") as file:
			file.write("\n".join(lines) + "\n")
	print("\n".join(lines))
	return 0


if __name__ == "__main__":
	sys.exit(run())
