#!/usr/bin/env python3
"""Read-only geometry check for MediaWiki HTML cache storage and web roots.

Accept paths supplied by the operator, but never print those paths. Do not load
LocalSettings.php, scan cache contents, execute HTTP requests or alter files.
An outside result is NOT a certificate against web-server aliases or rewrites.
"""

import argparse
import json
import os
from pathlib import Path
import sys


CACHES = frozenset({"vector", "minerva", "lecture"})


def _path_info(path):
	"""Return lexical and resolved paths; fail closed on missing paths."""
	if not os.path.isabs(path):
		return None, "not-absolute"
	lexical = os.path.abspath(path)
	try:
		resolved = str(Path(path).resolve(strict=True))
		if not Path(resolved).is_dir():
			return None, "not-directory"
	except (OSError, RuntimeError):
		return None, "missing-or-inaccessible"
	return (lexical, resolved), None


def _inside(path, root):
	try:
		return os.path.commonpath((path, root)) == root
	except ValueError:
		return False


def inspect(document_roots, caches):
	"""Produce only labels and verdicts, never filesystem paths."""
	roots = [_path_info(item) for item in document_roots]
	root_ok = bool(roots) and all(error is None for _, error in roots)
	available_roots = [pair for pair, error in roots if pair is not None]
	storage = []
	for name, path in caches:
		pair, error = _path_info(path)
		if error:
			state = "unverified-" + error
		elif not root_ok:
			state = "unverified-document-root"
		elif any(_inside(candidate, ancestor)
			for candidate in pair
			for root in available_roots
			for ancestor in root):
			# Lexical comparison catches /web/images/cache -> /private/cache
			# symlinks that could still be served at the /web/images URL.
			state = "inside-declared-web-root"
		else:
			state = "outside-declared-web-roots"
		storage.append({"cache": name, "status": state})

	if not root_ok or not storage or any(x["status"].startswith("unverified") for x in storage):
		conclusion = "unverified"
	elif any(x["status"] == "inside-declared-web-root" for x in storage):
		conclusion = "review-required"
	else:
		conclusion = "outside-declared-web-roots-only"
	return {
		"read_only": True,
		"content_inspected": False,
		"server_config_inspected": False,
		"paths_disclosed": False,
		"document_roots_checked": len(roots),
		"document_roots_resolved": len(available_roots),
		"storage": storage,
		"conclusion": conclusion,
		"note": "Out-of-root location does not rule out aliases, proxies or HTTP caches.",
	}


def main(argv=None):
	parser = argparse.ArgumentParser(description=__doc__)
	parser.add_argument("--docroot", action="append", required=True, metavar="PATH",
		help="Actual public document root (repeat for all web roots)")
	parser.add_argument("--cache", action="append", required=True, metavar="NAME=PATH",
		help="Configured vector, minerva or lecture cache path (repeat)")
	parser.add_argument("--json", action="store_true", help="Machine-readable sanitized report")
	args = parser.parse_args(argv)
	caches = []
	seen = set()
	for item in args.cache:
		name, sep, path = item.partition("=")
		if not sep or name not in CACHES or name in seen or not path:
			print("Invalid or duplicate cache label; expected vector, minerva or lecture.",
				file=sys.stderr)
			return 2
		seen.add(name)
		caches.append((name, path))
	report = inspect(args.docroot, caches)
	if args.json:
		print(json.dumps(report, ensure_ascii=False, sort_keys=True))
	else:
		print("READ-ONLY HTML CACHE PATH REVIEW")
		for item in report["storage"]:
			print(item["cache"] + ": " + item["status"])
		print("RESULT: " + report["conclusion"])
		print("No server configuration, web aliases, CDN rules, or private files inspected.")
	return {
		"outside-declared-web-roots-only": 0,
		"review-required": 1,
		"unverified": 2,
	}[report["conclusion"]]


if __name__ == "__main__":
	sys.exit(main())
