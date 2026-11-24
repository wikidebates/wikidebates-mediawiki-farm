#!/usr/bin/env python3
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

import auditUpstreamChanges as audit

from auditUpstreamChanges import classify, fingerprint, module_rows, unseen_candidates, version, release_candidates, major_rows, security_patch


def comparison(paths, messages, **kwargs):
	return {
		"status": "ahead", "behind_by": 0, "total_commits": len(messages),
		"commits": [{"sha": str(i), "commit": {"message": message}} for i, message in enumerate(messages)],
		"files": [{"filename": path, "sha": "a", "status": "modified"} for path in paths],
		**kwargs,
	}


class TriageTest(unittest.TestCase):
	def test_maintenance_even_with_dev_advisory(self):
		self.assertEqual(classify(comparison(["i18n/fr.json", "package-lock.json"], ["GHSA-abcd-efgh-ijkl"]))["category"], "maintenance")

	def test_release_automation(self):
		data = comparison([".release-please-manifest.json", "release-please-config.json", ".github/workflows/release.yml"], ["Release automation"])
		self.assertEqual(classify(data)["category"], "maintenance")

	def test_security_and_runtime(self):
		data = comparison(["includes/AntiSpoof.php"], ["SECURITY: fix", "CVE-2026-132683"])
		self.assertEqual(classify(data)["category"], "security")
		self.assertEqual(classify(comparison(["extension.json"], ["Fix behavior"]))["category"], "runtime")

	def test_divergence_and_truncation(self):
		self.assertEqual(classify(comparison(["a.php"], ["Fix"], status="diverged"))["category"], "review")
		self.assertEqual(classify(comparison(["a.php"], ["Fix"], total_commits=101))["category"], "review")
		self.assertEqual(classify(comparison(["a.php"] * 300, ["Fix"]))["category"], "review")

	def test_rename_from_runtime(self):
		data = comparison(["docs/moved.php"], ["Move"])
		data["files"][0]["previous_filename"] = "includes/runtime.php"
		self.assertEqual(classify(data)["category"], "runtime")

	def test_fingerprint_stable_across_translation_updates(self):
		a = {"name": "Test", "kind": "pin", "base": "a", "head": "b",
			**classify(comparison(["a.php"], ["Fix"]))}
		b = {**a, "head": "c", "ahead": 2}
		self.assertEqual(fingerprint(a), fingerprint(b))

	def test_stale_fork_anchor(self):
		m = {"path": "x", "repo": "owner/fork", "branch": "main", "pinned": "new", "status": "match"}
		f = {"x": {"repo": "owner/upstream", "branch": "REL1_43", "upstream_base": "up", "fork_pin": "old"}}
		self.assertEqual(module_rows({"submodules": [m]}, f)[1]["category"], "review")

	def test_notification_deduplication(self):
		seen = "a" * 64
		unseen = "b" * 64
		pages = [[
			{"user": {"login": "github-actions[bot]"}, "body": f"<!-- upstream-audit:{seen} -->"},
			{"user": {"login": "another-user"}, "body": f"<!-- upstream-audit:{unseen} -->"},
		]]
		rows = [{"fingerprint": seen}, {"fingerprint": unseen}]
		self.assertEqual(unseen_candidates(rows, pages), [rows[1]])
		self.assertEqual(unseen_candidates([rows[0]], pages), [])

	def test_major_candidates_and_deduplication(self):
		r = release_candidates((12, 1, 9), [(12, 1, 9), (12, 1, 10), (15, 1, 0), None])
		self.assertEqual(r["head"], "12.1.10")
		self.assertEqual(r["major_candidate"], "15.1.0")
		rows = major_rows([{"name": "mediawiki/maps", "base": "12.1.9", **r}])
		self.assertEqual(rows[0]["category"], "major-release")
		self.assertEqual(fingerprint(rows[0]), fingerprint({**rows[0], "head": "15.2.0"}))
		self.assertNotEqual(fingerprint(rows[0]), fingerprint({**rows[0], "head": "16.0.0"}))

	def test_security_patch_only_official_release_branch(self):
		row = {"name": "extensions/AntiSpoof", "kind": "pin", "category": "security",
			"branch": "REL1_43", "repo": "wikimedia/mediawiki-extensions-AntiSpoof",
			"base": "a" * 40, "head": "b" * 40, "security_commits": ["c" * 40]}
		self.assertIn("160000", security_patch(row))
		for change in ({"branch": "master"}, {"kind": "fork-upstream"},
			{"repo": "wikidebates/custom"}, {"category": "review"}, {"head": "invalid"},
			{"name": "extensions/../AntiSpoof"}):
			self.assertIsNone(security_patch({**row, **change}))

	def test_gitlink_proposal_round_trip_and_stale_pin(self):
		row = {"name": "extensions/AntiSpoof", "kind": "pin", "category": "security",
			"branch": "REL1_43", "repo": "wikimedia/mediawiki-extensions-AntiSpoof",
			"base": "a" * 40, "head": "b" * 40, "security_commits": ["c" * 40]}
		with tempfile.TemporaryDirectory() as folder:
			root = Path(folder)
			def git(*args, **kwargs):
				return subprocess.run(["git", *args], cwd=root, check=True,
					text=True, capture_output=True, **kwargs)
			git("init", "-q")
			git("update-index", "--add", "--cacheinfo", "160000", row["base"], row["name"])
			before = git("ls-files", "--stage").stdout
			proposals = root / "proposals"
			with patch.object(audit, "ROOT", root), patch.object(audit, "PROPOSALS", proposals):
				manifest = audit.prepare_security_proposals([row])
				self.assertEqual(manifest[0]["head"], row["head"])
				self.assertEqual(git("ls-files", "--stage").stdout, before)
				self.assertEqual(json.loads((proposals / "manifest.json").read_text()), manifest)
				git("apply", "--cached", str(proposals / manifest[0]["patch"]))
				self.assertIn(row["head"], git("ls-files", "--stage").stdout)
				with self.assertRaises(subprocess.CalledProcessError):
					audit.prepare_security_proposals([row])
				self.assertIn(row["head"], git("ls-files", "--stage").stdout)

	def test_notification_posts_once_and_only_to_canonical_issue(self):
		row = {"name": "extensions/Example", "category": "security", "base": "a" * 40,
			"head": "b" * 40, "fingerprint": "c" * 64}
		with tempfile.TemporaryDirectory() as folder:
			report = Path(folder) / "upstream-audit.json"
			report.write_text(json.dumps({"alerts": [row]}))
			env = {"GITHUB_REPOSITORY": "wikidebates/wikidebates-mediawiki-farm", "GITHUB_RUN_ID": "123"}
			with patch.object(audit, "REPORT", report), patch.dict(os.environ, env):
				with patch.object(audit.subprocess, "check_output", return_value="[[]]") as read:
					with patch.object(audit.subprocess, "run") as post:
						audit.notify()
						read.assert_called_once()
						post.assert_called_once()
						args = post.call_args.args[0]
						self.assertEqual(args[:5], ["gh", "api",
							"repos/wikidebates/wikidebates-mediawiki-farm/issues/78/comments", "--method", "POST"])
						body = args[-1]
						self.assertIn("<!-- upstream-audit:" + row["fingerprint"] + " -->", body)
						self.assertIn("/actions/runs/123", body)
				pages = [[{"user": {"login": "github-actions[bot]"}, "body": body}]]
				with patch.object(audit.subprocess, "check_output", return_value=json.dumps(pages)):
					with patch.object(audit.subprocess, "run") as post:
						audit.notify()
						post.assert_not_called()
				with patch.dict(os.environ, {"GITHUB_REPOSITORY": "other/farm"}):
					with patch.object(audit.subprocess, "check_output") as read:
						with self.assertRaises(SystemExit):
							audit.notify()
						read.assert_not_called()

	def test_versions(self):
		self.assertGreater(version("1.43.12"), version("1.43.9"))
		self.assertIsNone(version("1.43.13-rc.0"))


if __name__ == "__main__":
	unittest.main()
