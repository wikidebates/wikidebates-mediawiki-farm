"""Summarize the existing WikiSEO benchmark for two pinned extension pairs.

Semantic assertions are performed by the extension integration tests first.
Hashes are required to be stable within each version; cross-version
differences are reported for review rather than equated with regressions.
"""
import json
import statistics
import sys

CASES = (
	"ordinary-empty", "ordinary-persisted", "ordinary-text", "ordinary-local-file",
	"ordinary-separate-x", "native-debate", "native-argument", "native-hreflang", "parser",
)
VERSIONS = ("baseline", "optimized")
PREFIX = "WD_COMBINED_SEO "


def summarize(rows):
	expected = {(case, version, r) for case in CASES for version in VERSIONS for r in (1, 2)}
	actual = {(r["case"], r["version"], r["round"]) for r in rows}
	if actual != expected or len(rows) != len(expected):
		raise ValueError("Missing or repeated benchmark processes")
	if {r["mediawiki"] for r in rows} != {"1.43.12"} or any(
		r["database"] != "sqlite" or not r["php"].startswith("8.4.") for r in rows
	):
		raise ValueError("Expected MediaWiki 1.43.12, PHP 8.4 and disposable SQLite")
	if len({r["php"] for r in rows}) != 1:
		raise ValueError("Different PHP versions")
	report = []
	for case in CASES:
		groups = [[r for r in rows if r["case"] == case and r["version"] == v] for v in VERSIONS]
		metrics = []
		for group in groups:
			hashes = {r["seo_sha256"] for r in group}
			if len(hashes) != 1:
				raise ValueError("Unstable output: " + case)
			metrics.append({
				"cpu_us": statistics.median(v for r in group for v in r["cpu_us_samples"]),
				"wall_us": statistics.median(v for r in group for v in r["wall_us_samples"]),
				"peak_kib": statistics.median(v for r in group for v in r["peak_increment_bytes_samples"]) / 1024,
				"first_sql": statistics.median(r["first_sql"]["total"] for r in group),
				"warm_sql": statistics.median(r["warm_sql"]["total"] / r["warm_sql_iterations"] for r in group),
				"warm_page_props": statistics.median(r["warm_sql"]["page_props"] / r["warm_sql_iterations"] for r in group),
				"warm_file_sql": statistics.median(r["warm_sql"]["file_catalog"] / r["warm_sql_iterations"] for r in group),
				"sha256": next(iter(hashes)),
			})
		before, after = metrics
		report.append({
			"case": case, "reference": before, "candidate": after,
			"cpu_change_percent": 100 * (after["cpu_us"] / before["cpu_us"] - 1),
			"same_output": before["sha256"] == after["sha256"],
			"first_sql_increased": after["first_sql"] > before["first_sql"],
			"warm_sql_increased": after["warm_sql"] > before["warm_sql"],
		})
	return report


def main():
	with open(sys.argv[1], encoding="utf-8") as stream:
		rows = [json.loads(line[len(PREFIX):]) for line in stream if line.startswith(PREFIX)]
	report = summarize(rows)
	print("### SEO release: historical pair vs exact candidate farm gitlinks")
	print("| Case | CPU ref/new µs | CPU change | Wall ref/new µs | Peak ref/new KiB | SQL first ref/new | SQL warm ref/new | Same output |")
	print("|---|---:|---:|---:|---:|---:|---:|---|")
	for row in report:
		a, b = row["reference"], row["candidate"]
		print(f"| {row['case']} | {a['cpu_us']:.1f}/{b['cpu_us']:.1f} | {row['cpu_change_percent']:+.1f}% | "
			f"{a['wall_us']:.1f}/{b['wall_us']:.1f} | {a['peak_kib']:.1f}/{b['peak_kib']:.1f} | "
			f"{a['first_sql']:.1f}/{b['first_sql']:.1f} | {a['warm_sql']:.2f}/{b['warm_sql']:.2f} | "
			f"{'yes' if row['same_output'] else 'review'} |")
		print("WD_RELEASE_SEO " + json.dumps(row, separators=(",", ":")))
	print("CPU, memory and cross-version SQL/hash differences are informational. Review changed hashes and SQL before deployment.")
	print("Head/parser microbenchmark, not complete HTTP or cache-hit latency. The historical native-hreflang fixture uses the legacy path; dynamic hreflang has separate six-language real SQL assertions.")


if __name__ == "__main__":
	main()
