"""Offline test suite for the read-only cache-path geometry diagnostic."""

import contextlib
import io
import os
from pathlib import Path
import tempfile
import unittest

from inspectCacheWebExposure import inspect, main


class CacheWebExposureTests(unittest.TestCase):
	def test_default_html_cache_under_document_root_is_flagged(self):
		with tempfile.TemporaryDirectory() as folder:
			root = Path(folder) / "www"
			cache = root / "images" / "cache"
			cache.mkdir(parents=True)
			result = inspect([str(root)], [("vector", str(cache))])
			self.assertEqual(result["conclusion"], "review-required")
			self.assertEqual(result["storage"][0]["status"], "inside-declared-web-root")
			self.assertNotIn(str(root), str(result))

	def test_symlink_in_web_root_still_requires_review(self):
		with tempfile.TemporaryDirectory() as folder:
			root = Path(folder) / "www"
			outside = Path(folder) / "private"
			root.mkdir()
			outside.mkdir()
			link = root / "images" / "cache"
			link.parent.mkdir()
			link.symlink_to(outside, target_is_directory=True)
			result = inspect([str(root)], [("vector", str(link))])
			self.assertEqual(result["conclusion"], "review-required")

	def test_separate_private_directory_has_only_provisional_result(self):
		with tempfile.TemporaryDirectory() as folder:
			root = Path(folder) / "www"
			cache = Path(folder) / "private"
			root.mkdir()
			cache.mkdir()
			result = inspect([str(root)], [("minerva", str(cache))])
			self.assertEqual(result["conclusion"], "outside-declared-web-roots-only")
			self.assertFalse(result["server_config_inspected"])
			self.assertFalse(result["content_inspected"])

	def test_missing_web_root_is_not_declared_safe(self):
		with tempfile.TemporaryDirectory() as folder:
			cache = Path(folder) / "private"
			cache.mkdir()
			result = inspect([str(Path(folder) / "missing")], [("vector", str(cache))])
			self.assertEqual(result["conclusion"], "unverified")

	def test_missing_or_relative_cache_is_unverified(self):
		with tempfile.TemporaryDirectory() as folder:
			root = Path(folder)
			for path in ("relative/cache", str(root / "missing")):
				result = inspect([str(root)], [("vector", path)])
				self.assertEqual(result["conclusion"], "unverified")

	def test_no_paths_are_emitted_and_exit_codes_are_distinct(self):
		with tempfile.TemporaryDirectory() as folder:
			root = Path(folder) / "www"
			private = Path(folder) / "private"
			root.mkdir()
			private.mkdir()
			stdout = io.StringIO()
			with contextlib.redirect_stdout(stdout):
				self.assertEqual(main(["--docroot", str(root), "--cache",
					"vector=" + str(private), "--json"]), 0)
			self.assertNotIn(str(root), stdout.getvalue())
			self.assertNotIn(str(private), stdout.getvalue())
			with contextlib.redirect_stdout(io.StringIO()):
				self.assertEqual(main(["--docroot", str(root), "--cache",
					"vector=" + str(root)]), 1)
				self.assertEqual(main(["--docroot", str(root), "--cache",
					"vector=relative/path"]), 2)


if __name__ == "__main__":
	unittest.main()
