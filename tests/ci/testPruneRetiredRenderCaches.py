#!/usr/bin/env python3
"""Offline disposable-file regressions; no actual wiki or HTTP."""
import fcntl
import gzip
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location("prune", Path(__file__).resolve().parents[2]
	/ "ops/cache/pruneRetiredRenderCaches.py")
prune = importlib.util.module_from_spec(spec)
spec.loader.exec_module(prune)
EPOCH = "20261124224657"
OLD = "20261124122057"


class CleanupTest(unittest.TestCase):
	def test_vector(self):
		with tempfile.TemporaryDirectory() as directory:
			root = Path(directory)
			cutoff = prune.checked_epoch(EPOCH)
			old = root / "ns0%3AOld.html.gz"
			new = root / "ns0%3ANew.html"
			other = root / "different.html"
			busy = root / "ns0%3ABusy.html"
			for path in (old, new, other, busy):
				path.write_bytes(b"fixture")
				os.utime(path, (cutoff - 1, cutoff - 1))
			os.utime(new, (cutoff, cutoff))
			link = root / "ns0%3ALink.html"
			link.symlink_to(other)
			with busy.open("rb") as lock:
				fcntl.flock(lock, fcntl.LOCK_EX)
				preview = prune.vector_files(root, cutoff, False)
				self.assertEqual(preview["candidates"], 1)
				self.assertTrue(old.exists())
				result = prune.vector_files(root, cutoff, True)
				self.assertEqual(result["removed"], 1)
				self.assertEqual(result["busy"], 1)
			self.assertFalse(old.exists())
			self.assertTrue(new.exists() and busy.exists() and other.exists() and link.is_symlink())

	def test_private_payloads(self):
		with tempfile.TemporaryDirectory() as directory:
			root = Path(directory)
			wiki = "frwikidebates"
			key = prune.digest("Page")
			page = root / prune.digest(wiki) / key[:2] / key
			page.mkdir(parents=True)
			lockfile = page / "page.lock"
			lockfile.touch()
			generation = page / "generation"
			generation.write_text("generation")
			old, fresh, future, invalid, unknown = [
				page / (prune.digest(name) + ".cache")
				for name in ("old", "fresh", "future", "invalid", "unknown")
			]
			for path, epoch in ((old, OLD), (fresh, EPOCH), (future, "20261125000000")):
				path.write_bytes(gzip.compress(json.dumps({"identity": {"coreEpoch": epoch}, "body": "fixture"}).encode()))
			invalid.write_bytes(b"not compressed")
			unknown.write_bytes(gzip.compress(b'{"identity":{}}'))
			with lockfile.open("rb") as handle:
				fcntl.flock(handle, fcntl.LOCK_EX)
				self.assertEqual(prune.variant_files(root, wiki, EPOCH, 4096, True)["busy_pages"], 1)
			preview = prune.variant_files(root, wiki, EPOCH, 4096, False)
			self.assertEqual(preview["candidates"], 1)
			self.assertTrue(old.exists())
			report = prune.variant_files(root, wiki, EPOCH, 4096, True)
			self.assertEqual(report["removed"], 1)
			self.assertEqual(report["unverified"], 2)
			self.assertFalse(old.exists())
			self.assertTrue(all(path.exists() for path in (fresh, future, invalid, unknown, lockfile, generation)))
			self.assertEqual(generation.read_text(), "generation")

	def test_configured_root_and_epoch(self):
		with tempfile.TemporaryDirectory() as directory:
			root = Path(directory)
			real = root / "real"
			real.mkdir()
			alias = root / "alias"
			alias.symlink_to(real, target_is_directory=True)
			with self.assertRaises(ValueError):
				prune.checked_root(str(alias), alias)
			with self.assertRaises(ValueError):
				prune.checked_root(str(real), alias)
			with self.assertRaises(ValueError):
				prune.checked_epoch("2")


if __name__ == "__main__":
	unittest.main()
