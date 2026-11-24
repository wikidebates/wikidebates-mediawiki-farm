"""Offline contracts for the paired release summary."""
import copy
import unittest

from summarizeSeoReleaseBenchmark import CASES, VERSIONS, summarize


def fixture():
	return [
		{"case": case, "version": version, "round": rnd, "mediawiki": "1.43.12",
			"database": "sqlite", "php": "8.4.28", "seo_sha256": version,
			"cpu_us_samples": [100 if version == "baseline" else 90],
			"wall_us_samples": [100], "peak_increment_bytes_samples": [1024],
			"first_sql": {"total": 2}, "warm_sql_iterations": 20,
			"warm_sql": {"total": 20, "page_props": 0, "file_catalog": 0}}
		for case in CASES for version in VERSIONS for rnd in (1, 2)
	]


class SummaryTest(unittest.TestCase):
	def test_expected_difference_is_reported(self):
		report = summarize(fixture())
		self.assertEqual(len(report), len(CASES))
		self.assertAlmostEqual(report[0]["cpu_change_percent"], -10)
		self.assertFalse(report[0]["same_output"])
		self.assertFalse(report[0]["warm_sql_increased"])

	def test_increased_sql_is_reported(self):
		rows = fixture()
		for row in rows:
			if row["case"] == CASES[0] and row["version"] == "optimized":
				row["warm_sql"]["total"] = 40
		self.assertTrue(summarize(rows)[0]["warm_sql_increased"])

	def test_incomplete_duplicate_wrong_version_and_unstable_rejected(self):
		rows = fixture()
		with self.assertRaises(ValueError):
			summarize(rows[:-1])
		with self.assertRaises(ValueError):
			summarize(rows[:-1] + [copy.deepcopy(rows[0])])
		for key, value in (("mediawiki", "1.43.11"), ("php", "8.3.0"), ("seo_sha256", "unstable")):
			mutated = copy.deepcopy(rows)
			mutated[0][key] = value
			with self.assertRaises(ValueError):
				summarize(mutated)


if __name__ == "__main__":
	unittest.main()
