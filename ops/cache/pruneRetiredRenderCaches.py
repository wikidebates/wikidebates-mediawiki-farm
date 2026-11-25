#!/usr/bin/env python3
"""Offline, opt-in removal of demonstrably retired Wikidebates HTML payloads.

Never remove directories, locks, generation files, preparation state or DB data.
The configured core epoch must match the explicit expected epoch on all six wikis.
"""
import argparse
import collections
import datetime
import fcntl
import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys

LANGS = ("fr", "en", "de", "es", "it", "pt")
EPOCH = re.compile(r"[0-9]{14}\Z")
HASH = re.compile(r"[0-9a-f]{64}\Z")
VECTOR = re.compile(r"ns[0-9]+%3a.+\.html(?:\.gz)?\Z", re.IGNORECASE)
HEX_PREFIX = re.compile(r"[0-9a-f]{2}\Z")

CONFIG = r'$s = \MediaWiki\MediaWikiServices::getInstance(); $c = $s->getMainConfig(); $r = \ExtensionRegistry::getInstance(); return json_encode(["wiki" => \MediaWiki\WikiMap\WikiMap::getCurrentWikiId(), "epoch" => $c->get("CacheEpoch"), "vector" => $c->get("FileCacheDirectory"), "vector_enabled" => $c->get("UseFileCache"), "minerva" => $r->isLoaded("MinervaFileCache") ? $c->get("MinervaFileCacheDirectory") : null, "mobile_max" => $r->isLoaded("MinervaFileCache") ? $c->get("MinervaFileCacheMaxBytes") : null, "reading" => $r->isLoaded("Wikidebates") ? $c->get("WikidebatesReadingCacheDirectory") : null], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);'


def checked_epoch(value):
	if not isinstance(value, str) or not EPOCH.fullmatch(value):
		raise ValueError("Expected a fixed 14-digit UTC cache epoch")
	return datetime.datetime.strptime(value, "%Y%m%d%H%M%S").replace(
		tzinfo=datetime.timezone.utc).timestamp()


def digest(value):
	return hashlib.sha256(value.encode("utf-8")).hexdigest()


def checked_root(value, expected):
	if not isinstance(value, str) or not value.startswith("/") or Path(value) != expected:
		raise ValueError("Unexpected configured cache directory: " + str(expected))
	if expected.resolve() != expected:
		raise ValueError("Symlinked cache root or ancestor: " + str(expected))
	if expected.exists() and not expected.is_dir():
		raise ValueError("Cache root is not a directory: " + str(expected))
	return expected


def configs(install, files, php, epoch):
	# All configurations are validated before any file is removed.
	out = []
	for lang in LANGS:
		run = subprocess.run([php, str(install / "maintenance/run.php"), "eval",
			"--wiki=" + lang], input=CONFIG + "\n", text=True,
			capture_output=True, check=True, timeout=60, cwd=install)
		conf = json.loads(run.stdout)
		if conf["wiki"] != lang + "wikidebates" or conf["epoch"] != epoch:
			raise ValueError("Unexpected wiki or core epoch for " + lang)
		if conf["vector_enabled"] is not True:
			raise ValueError("Vector file cache unexpectedly disabled for " + lang)
		vector = checked_root(conf["vector"], files / "vector-cache/wikidebates" / lang)
		mobile = checked_root(conf["minerva"], files / "minerva-cache/wikidebates")
		max_bytes = conf["mobile_max"]
		if type(max_bytes) is not int or not 512 <= max_bytes <= 32 * 1024 * 1024:
			raise ValueError("Unexpected Minerva payload bound for " + lang)
		reading = conf["reading"]
		if lang in ("fr", "en"):
			reading = checked_root(reading, files / "reading-cache/wikidebates")
		elif reading is not None:
			raise ValueError("Unexpected Reading cache on " + lang)
		out.append((lang, vector, mobile, reading, max_bytes))
	return out


def open_regular(path):
	fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
	handle = os.fdopen(fd, "rb")
	if not stat.S_ISREG(os.fstat(handle.fileno()).st_mode):
		handle.close()
		raise OSError("Not a regular file")
	return handle


def lock(handle, apply):
	fcntl.flock(handle.fileno(), (fcntl.LOCK_EX if apply else fcntl.LOCK_SH) | fcntl.LOCK_NB)


def unchanged(path, before):
	try:
		now = path.stat(follow_symlinks=False)
	except FileNotFoundError:
		return False
	return stat.S_ISREG(now.st_mode) and all(
		getattr(now, key) == getattr(before, key)
		for key in ("st_dev", "st_ino", "st_size", "st_mtime_ns", "st_ctime_ns"))


def retire(path, before, result, apply):
	if not unchanged(path, before):
		result["changed"] += 1
		return
	result["candidates"] += 1
	result["candidate_bytes"] += before.st_size
	if apply:
		path.unlink()
		result["removed"] += 1
		result["removed_bytes"] += before.st_size


def vector_files(root, cutoff, apply):
	result = collections.Counter()
	if not root.is_dir():
		return result
	for parent, dirs, names in os.walk(root, followlinks=False):
		dirs[:] = [name for name in dirs if not (Path(parent) / name).is_symlink()]
		for name in names:
			if not VECTOR.fullmatch(name):
				continue
			path = Path(parent) / name
			try:
				before = path.stat(follow_symlinks=False)
				if not stat.S_ISREG(before.st_mode):
					continue
				result["examined"] += 1
				if before.st_mtime >= cutoff:
					continue
				# Native file_put_contents(LOCK_EX) serializes writes to this inode.
				# Atomic replacement can still race the final unlink; the only
				# possible consequence is a harmless extra cache miss, never lost wiki data.
				with open_regular(path) as handle:
					lock(handle, apply)
					before = os.fstat(handle.fileno())
					if before.st_mtime < cutoff:
						retire(path, before, result, apply)
			except BlockingIOError:
				result["busy"] += 1
			except OSError:
				result["unverified"] += 1
	return result


def real_dirs(root):
	with os.scandir(root) as entries:
		for entry in entries:
			if entry.is_dir(follow_symlinks=False):
				yield Path(entry.path)


def variant_files(root, wiki, epoch, max_bytes, apply):
	result = collections.Counter()
	root = root / digest(wiki)
	if not root.is_dir():
		return result
	if root.is_symlink():
		raise ValueError("Symlinked per-wiki payload root")
	bound = max_bytes * 2
	for prefix in real_dirs(root):
		if not HEX_PREFIX.fullmatch(prefix.name):
			continue
		for page in real_dirs(prefix):
			if not HASH.fullmatch(page.name) or not page.name.startswith(prefix.name):
				continue
			try:
				# The actual PHP stores use page.lock for every publication and
				# invalidation. Never create or unlink a missing/existing lock.
				with open_regular(page / "page.lock") as page_lock:
					lock(page_lock, apply)
					with os.scandir(page) as entries:
						for entry in entries:
							if not entry.name.endswith(".cache") or not HASH.fullmatch(entry.name[:-6]):
								continue
							if not entry.is_file(follow_symlinks=False):
								continue
							result["examined"] += 1
							path = Path(entry.path)
							try:
								with open_regular(path) as handle:
									before = os.fstat(handle.fileno())
									if before.st_size > bound:
										raise ValueError("oversized payload")
									with gzip.GzipFile(fileobj=handle, mode="rb") as compressed:
										raw = compressed.read(bound + 1)
									if len(raw) > bound:
										raise ValueError("oversized decoded payload")
									data = json.loads(raw)
									identity = data.get("identity") if isinstance(data, dict) else None
									old = identity.get("coreEpoch") if isinstance(identity, dict) else None
									checked_epoch(old)
									if old < epoch:
										retire(path, before, result, apply)
									else:
										result["retained"] += 1
							except (OSError, EOFError, ValueError, TypeError, RecursionError):
								result["unverified"] += 1
			except BlockingIOError:
				result["busy_pages"] += 1
			except OSError:
				result["unverified_pages"] += 1
	return result


def report(lang, kind, counts, apply):
	print(json.dumps({"wiki": lang, "cache": kind,
		"mode": "apply" if apply else "preview", **dict(counts)},
		sort_keys=True), flush=True)


def main():
	parser = argparse.ArgumentParser(description=__doc__)
	parser.add_argument("--epoch", required=True, help="Exact active UTC CacheEpoch, all six wikis")
	parser.add_argument("--apply", action="store_true", help="Actually unlink only proven retired payloads")
	parser.add_argument("--php", default="/usr/bin/php8.4")
	args = parser.parse_args()
	cutoff = checked_epoch(args.epoch)
	if cutoff > datetime.datetime.now(datetime.timezone.utc).timestamp():
		raise ValueError("Refusing a future cache epoch")
	install = Path(__file__).resolve().parents[2]
	files = Path.home() / "files"
	if files.resolve() != files or not files.is_dir() or not (install / "maintenance/run.php").is_file():
		raise ValueError("Expected a private files directory and the farm installation")
	all_configs = configs(install, files, args.php, args.epoch)
	for lang, vector, mobile, reading, max_bytes in all_configs:
		wiki = lang + "wikidebates"
		report(lang, "vector", vector_files(vector, cutoff, args.apply), args.apply)
		report(lang, "minerva", variant_files(mobile, wiki, args.epoch, max_bytes, args.apply), args.apply)
		if reading is not None:
			report(lang, "reading", variant_files(reading, wiki, args.epoch, 4194304, args.apply), args.apply)
	print("DONE: active roots, lock files, generations and preparation journals preserved", flush=True)


if __name__ == "__main__":
	try:
		main()
	except (OSError, ValueError, KeyError, subprocess.SubprocessError) as exc:
		sys.exit("Stopped: " + str(exc))
