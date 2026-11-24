#!/usr/bin/env python3
"""Read only: validate complete public sources against the reviewed migration."""
import json
from pathlib import Path
import subprocess
import sys
from urllib.parse import urlencode

from publicSeoAudit import editorial_query, get, transclusion_query, LEGACY_TEMPLATES

ROOT = Path(__file__).resolve().parents[2]


def request(lang, query):
	host = lang + ".wikidebates.org"
	url = "https://" + host + "/w/api.php?" + urlencode(query)
	status, _, _, body = get(url, host)
	if status != 200:
		raise ValueError(f"{lang}: HTTP {status}")
	data = json.loads(body)
	if "error" in data or "continue" in data:
		raise ValueError(f"{lang}: API error or incomplete result")
	return data["query"]


def main():
	manifest = json.loads((ROOT / "docs/editorial-seo/pages.json").read_text())
	for lang in ("fr", "en", "es", "de", "it", "pt"):
		pages = request(lang, editorial_query(lang))["pages"]
		callers = (request(lang, transclusion_query(lang))["embeddedin"]
			if lang in LEGACY_TEMPLATES else [])
		# PHP uses the same transformation helper as the real migration.
		result = subprocess.run(
			["php", str(ROOT / "tests/ci/checkEditorialSeoSources.php"), lang],
			input=json.dumps({"pages": pages, "callers": callers}, ensure_ascii=False),
			text=True, check=True, stdout=subprocess.PIPE,
		)
		print(result.stdout.strip(), flush=True)


if __name__ == "__main__":
	main()
