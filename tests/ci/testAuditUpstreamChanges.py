#!/usr/bin/env python3
import unittest

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

	def test_versions(self):
		self.assertGreater(version("1.43.12"), version("1.43.9"))
		self.assertIsNone(version("1.43.13-rc.0"))


if __name__ == "__main__":
	unittest.main()
