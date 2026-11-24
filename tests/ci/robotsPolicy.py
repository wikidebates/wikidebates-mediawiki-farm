#!/usr/bin/env python3
"""Render a reviewed robots policy or compare it with the six public files.

No installation, cache manipulation, server configuration or page crawl.
The audit reads only six fixed /robots.txt URLs, without redirects.
"""
import argparse
import json
from pathlib import Path
import urllib.error
import urllib.request

LANGS = ("de", "en", "es", "fr", "it", "pt")
POLICY = Path(__file__).resolve().parents[2] / "docs" / "seo" / "robots-policy.txt"
MAX_BYTES = 500 * 1024
AGENT = "Wikidebates-public-robots-audit/1.0"


def render(lang="shared"):
	if lang not in ("shared", *LANGS):
		raise ValueError("Unknown edition")
	langs = LANGS if lang == "shared" else (lang,)
	base = POLICY.read_text(encoding="utf-8").rstrip()
	return base + "\n\n" + "\n".join(
		f"Sitemap: https://{code}.wikidebates.org/w/sitemaps/{code}/sitemap-index-{code}wikidebates.xml"
		for code in langs
	) + "\n"


def parse(text):
	"""Structural inventory, not a complete search-engine robots evaluator."""
	groups = {}
	sitemaps = []
	other = []
	agents = []
	has_rules = False
	for line in text.splitlines():
		line = line.split("#", 1)[0].strip()
		if not line:
			continue
		key, sep, value = line.partition(":")
		key, value = key.strip().lower(), value.strip()
		if not sep:
			other.append(line[:200])
			continue
		if key == "user-agent":
			if has_rules:
				agents = []
				has_rules = False
			agent = value.lower()
			agents.append(agent)
			groups.setdefault(agent, set())
		elif key in ("allow", "disallow", "crawl-delay"):
			if not agents:
				other.append(key + ": " + value)
				continue
			has_rules = True
			for agent in agents:
				groups[agent].add((key, value))
		elif key == "sitemap":
			sitemaps.append(value)
		else:
			# Surface provider-injected directives rather than silently losing them.
			other.append(key + ": " + value[:200])
	return {"groups": groups, "sitemaps": sitemaps, "other": other}


def compare(current, proposed):
	old, new = parse(current), parse(proposed)
	old_groups, new_groups = old["groups"], new["groups"]
	removed = sorted(old_groups.keys() - new_groups.keys())
	changed = {
		agent: {
			"removed": sorted(old_groups[agent] - new_groups[agent]),
			"added": sorted(new_groups[agent] - old_groups[agent]),
		}
		for agent in sorted(old_groups.keys() & new_groups.keys())
		if old_groups[agent] != new_groups[agent]
	}
	fully_denied = {agent for agent, rules in old_groups.items()
		if rules == {("disallow", "/")}}
	lost_blocks = sorted(agent for agent in fully_denied
		if new_groups.get(agent) != {("disallow", "/")})
	return {
		"removed_agents": removed,
		"added_agents": sorted(new_groups.keys() - old_groups.keys()),
		"lost_full_blocks": lost_blocks,
		"changed_rules": changed,
		"current_sitemaps": old["sitemaps"],
		"proposed_sitemaps": new["sitemaps"],
		"unrecognized_current_directives": old["other"],
	}


class NoRedirect(urllib.request.HTTPRedirectHandler):
	def redirect_request(self, req, fp, code, msg, headers, newurl):
		return None


def get(lang):
	if lang not in LANGS:
		raise ValueError("Unknown edition")
	url = f"https://{lang}.wikidebates.org/robots.txt"
	request = urllib.request.Request(url, headers={
		"User-Agent": AGENT, "Accept": "text/plain", "Accept-Encoding": "identity",
	})
	try:
		with urllib.request.build_opener(NoRedirect()).open(request, timeout=12) as response:
			if response.geturl() != url:
				raise ValueError("Unexpected URL")
			body = response.read(MAX_BYTES + 1)
			if len(body) > MAX_BYTES:
				raise ValueError("Response exceeds 500 KiB")
			return response.status, response.headers.get("Content-Type", ""), body
	except urllib.error.HTTPError as error:
		return error.code, error.headers.get("Content-Type", ""), b""


def audit(lang, opener=get):
	try:
		status, content_type, body = opener(lang)
		result = {"language": lang, "http": status, "content_type": content_type}
		if status != 200 or content_type.split(";", 1)[0].strip().lower() != "text/plain":
			result["status"] = "unverified"
			return result
		current = body.decode("utf-8-sig")
		if "*" not in parse(current)["groups"]:
			result["status"] = "unverified"
			return result
		result["comparison"] = compare(current, render(lang))
		result["status"] = "observed"
		return result
	except (OSError, ValueError) as error:
		return {"language": lang, "status": "unverified", "reason": type(error).__name__}


def main():
	parser = argparse.ArgumentParser(description=__doc__)
	mode = parser.add_mutually_exclusive_group(required=True)
	mode.add_argument("--render", choices=("shared", *LANGS))
	mode.add_argument("--audit", action="store_true")
	args = parser.parse_args()
	if args.render:
		print(render(args.render), end="")
	else:
		print(json.dumps({
			"read_only": True, "scope": "six public robots.txt files; no redirects",
			"results": [audit(lang) for lang in LANGS],
		}, indent=2, ensure_ascii=False))


if __name__ == "__main__":
	main()
