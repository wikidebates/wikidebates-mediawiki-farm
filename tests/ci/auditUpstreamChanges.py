#!/usr/bin/env python3
"""Read-only upstream triage; optional deduplicated notification on issue #78."""
import argparse
import concurrent.futures
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
REPORT = ROOT / "upstream-audit.json"
API = "https://api.github.com"
SECURITY = re.compile(r"\b(?:CVE-\d{4}-\d+|GHSA-[\w-]+|SECURITY\s*:|security (?:fix|vulnerability|patch|backport))", re.I)
STABLE = re.compile(r"v?(\d+)\.(\d+)\.(\d+)$")
PACKAGES = {
	"mediawiki/maps",
	"mediawiki/semantic-media-wiki",
	"mediawiki/semantic-result-formats",
	"mediawiki/semantic-extra-special-properties",
	"mediawiki/semantic-scribunto",
}


def get_json(url, github=True):
	headers = {"User-Agent": "wikidebates-upstream-audit", "Accept": "application/json"}
	if github:
		if not url.startswith(API + "/"):
			raise ValueError("unexpected GitHub API URL")
		headers["X-GitHub-Api-Version"] = "2022-11-28"
		token = os.environ.get("GITHUB_TOKEN")
		if token:
			headers["Authorization"] = "Bearer " + token
	with urllib.request.urlopen(urllib.request.Request(url, headers=headers), timeout=20) as response:
		return json.load(response)


def gh(path):
	return get_json(API + "/" + path)


def version(value):
	match = STABLE.fullmatch(value)
	return tuple(map(int, match.groups())) if match else None


def non_runtime(path):
	parts = path.split("/")
	name = parts[-1]
	return (
		("i18n" in parts and name.endswith(".json"))
		or parts[0] in {".github", "tests", "test", "docs", "doc", ".phan"}
		or name in {"package-lock.json", "README", "README.md", "CHANGELOG", "CHANGELOG.md",
			"CONTRIBUTING.md", "Gruntfile.js", ".eslintrc.json", ".stylelintrc.json",
			".gitignore", ".gitattributes", "AGENTS.md"}
	)


def classify(data):
	"""Conservative classification, never equate absence of markers with safety."""
	status = data["status"]
	if status == "identical":
		return {"category": "current", "ahead": 0}
	if status != "ahead" or data.get("behind_by") != 0:
		return {"category": "review", "reason": "not a fast-forward comparison"}
	files = data.get("files")
	commits = data.get("commits")
	total = data.get("total_commits")
	if (not isinstance(files, list) or not isinstance(commits, list)
		or not isinstance(total, int) or total != len(commits) or not files or len(files) >= 300):
		return {"category": "review", "reason": "incomplete or empty comparison"}
	security = [
		c["sha"] for c in commits if SECURITY.search(c["commit"]["message"])
	]
	# Both paths of a rename must be low-risk to classify it as maintenance.
	runtime = [
		f for f in files if not non_runtime(f["filename"])
		or (f.get("previous_filename") and not non_runtime(f["previous_filename"]))
	]
	category = "maintenance" if not runtime else ("security" if security else "runtime")
	return {
		"category": category, "ahead": total, "security_commits": security,
		"files": [
			{"path": f["filename"], "sha": f["sha"], "status": f["status"],
				"previous_path": f.get("previous_filename")}
			for f in runtime
		],
	}


def unavailable(row, exc):
	reason = f"HTTP {exc.code}" if isinstance(exc, urllib.error.HTTPError) else type(exc).__name__
	return {**row, "category": "unavailable", "reason": reason}


def inspect(row):
	try:
		if not row.get("base") or not row.get("repo"):
			return {**row, "category": "unavailable", "reason": "missing comparison baseline"}
		repo = urllib.parse.quote(row["repo"], safe="/")
		head = row.get("head")
		if not head:
			branch = urllib.parse.quote(row["branch"], safe="")
			head = gh(f"repos/{repo}/branches/{branch}")["commit"]["sha"]
		row = {**row, "head": head}
		if row["base"] == head:
			return {**row, "category": "current", "ahead": 0}
		base = urllib.parse.quote(row["base"], safe="")
		target = urllib.parse.quote(head, safe="")
		data = gh(f"repos/{repo}/compare/{base}...{target}?per_page=100")
		return {**row, **classify(data)}
	except (OSError, ValueError, KeyError, TypeError) as exc:
		return unavailable(row, exc)


def module_rows(inventory, forks):
	rows = []
	for m in inventory["submodules"]:
		row = {"name": m["path"], "kind": "pin", "repo": m["repo"],
			"branch": m["branch"], "base": m["pinned"], "head": m.get("tracked_head")}
		if m["status"] == "unavailable":
			rows.append({**row, "category": "unavailable", "reason": m.get("reason", "unavailable")})
		else:
			rows.append(row)
		if m["path"] in forks:
			f = forks[m["path"]]
			fork_row = {"name": m["path"] + " (amont)", "kind": "fork-upstream",
				"repo": f["repo"], "branch": f["branch"], "base": f["upstream_base"]}
			# Do not silently treat an old upstream anchor as the deployed fork's base.
			if m["pinned"] != f["fork_pin"]:
				fork_row.update(category="review", reason="fork pin changed; verify upstream baseline",
					base=m["pinned"], expected_pin=f["fork_pin"])
			rows.append(fork_row)
	return rows


def inspect_core():
	row = {"name": "MediaWiki 1.43 LTS", "kind": "release", "repo": "wikimedia/mediawiki"}
	try:
		text = (ROOT / "includes/Defines.php").read_text(encoding="utf-8")
		current = re.search(r"define\(\s*['\"]MW_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]", text)[1]
		installed = version(current)
		if not installed or installed[:2] != (1, 43):
			return {**row, "category": "review", "reason": "review supported MediaWiki series", "base": current}
		refs = gh("repos/wikimedia/mediawiki/git/matching-refs/tags/1.43.")
		versions = [version(r["ref"].removeprefix("refs/tags/")) for r in refs]
		latest = max(v for v in versions if v and v[:2] == (1, 43))
		return {**row, "base": current, "head": ".".join(map(str, latest)),
			"category": "release" if latest > installed else "current"}
	except (OSError, ValueError, KeyError, TypeError) as exc:
		return unavailable(row, exc)


def inspect_package(package):
	row = {"name": package["name"], "kind": "composer-release", "base": package["version"]}
	try:
		current = version(package["version"])
		if not current:
			return {**row, "category": "review", "reason": "non-stable locked version"}
		data = get_json("https://repo.packagist.org/p2/" + package["name"] + ".json", github=False)
		versions = [version(p["version"]) for p in data["packages"][package["name"]]]
		latest = max(v for v in versions if v and v[0] == current[0])
		return {**row, "head": ".".join(map(str, latest)),
			"category": "release" if latest > current else "current"}
	except (OSError, ValueError, KeyError, TypeError) as exc:
		return unavailable(row, exc)


def fingerprint(row):
	# Ignore newer translation-only head commits when runtime changes are unchanged.
	value = {k: row.get(k) for k in ("name", "kind", "base", "category", "reason", "expected_pin")}
	if row["category"] in ("security", "runtime"):
		value["files"] = sorted(row["files"], key=lambda f: f["path"])
		value["security_commits"] = sorted(row["security_commits"])
	elif row["category"] == "release":
		value["head"] = row["head"]
	return hashlib.sha256(json.dumps(value, sort_keys=True).encode()).hexdigest()


def actionable(row):
	return row["category"] in {"security", "runtime", "release", "review"}


def scan():
	inventory = json.loads((ROOT / "submodule-audit.json").read_text())
	forks = json.loads((ROOT / "tests/ci/upstreamForkBaselines.json").read_text())
	rows = module_rows(inventory, forks)
	packages = [p for p in json.loads((ROOT / "composer.lock").read_text())["packages"]
		if p["name"] in PACKAGES]
	with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
		result = list(pool.map(lambda r: r if "category" in r else inspect(r), rows))
		result += list(pool.map(inspect_package, packages))
	result.append(inspect_core())
	result.sort(key=lambda r: (r["name"], r["kind"]))
	alerts = [r for r in result if actionable(r)]
	for row in alerts:
		row["fingerprint"] = fingerprint(row)
	REPORT.write_text(json.dumps({"rows": result, "alerts": alerts}, indent=2) + "\n")
	lines = ["# Upstream triage (advisory only)", "",
		"| Component | Category | Base | Head | Details |", "|---|---|---|---|---|"]
	for row in result:
		detail = row.get("reason", str(row.get("ahead", "")) + " commits" if "ahead" in row else "")
		lines.append(f"| {row['name']} | {row['category']} | {(row.get('base') or '-')[:12]} | "
			f"{(row.get('head') or '-')[:12]} | {detail} |")
	lines += ["", "Maintenance includes translations and development-only files by heuristic. "
		"Unmarked runtime changes are not certified safe. Composer releases are same-major candidates, not compatibility decisions."]
	summary = os.environ.get("GITHUB_STEP_SUMMARY")
	if summary:
		with open(summary, "a", encoding="utf-8") as out:
			out.write("\n".join(lines) + "\n")
	output = os.environ.get("GITHUB_OUTPUT")
	if output:
		with open(output, "a", encoding="utf-8") as out:
			out.write(f"alerts={len(alerts)}\n")
	print(f"{len(result)} comparisons; {len(alerts)} actionable candidates; "
		f"{sum(r['category'] == 'unavailable' for r in result)} unavailable.")
	for row in result:
		if actionable(row) or row["category"] == "unavailable":
			print(f"  {row['category']}: {row['name']} ({row.get('reason', '')})")


def unseen_candidates(alerts, pages):
	seen = set()
	for page in pages:
		for comment in page:
			if comment["user"]["login"] == "github-actions[bot]":
				seen.update(re.findall(r"<!-- upstream-audit:([0-9a-f]{64}) -->", comment["body"] or ""))
	return [row for row in alerts if row["fingerprint"] not in seen]


def notify():
	repo = os.environ["GITHUB_REPOSITORY"]
	if repo != "wikidebates/wikidebates-mediawiki-farm":
		raise SystemExit("Notifications are restricted to the canonical farm")
	alerts = json.loads(REPORT.read_text())["alerts"]
	if not alerts:
		return
	pages = json.loads(subprocess.check_output(
		["gh", "api", f"repos/{repo}/issues/78/comments", "--paginate", "--slurp"], text=True))
	new = unseen_candidates(alerts, pages)
	if not new:
		print("No new actionable candidates; no issue comment.")
		return
	lines = ["### Veille amont — nouveaux changements à examiner", "",
		"Rapport automatique indicatif. Aucune mise à jour ni aucun déploiement exécuté.", ""]
	for row in new:
		base = row.get("base") or "-"
		head = row.get("head") or "-"
		label = {"security": "Sécurité signalée", "runtime": "Code/configuration",
			"release": "Version candidate", "review": "Vérification manuelle"}[row["category"]]
		lines.append(f"- **{label} — {row['name']}** : {base[:12]} → {head[:12]}"
			+ (f" ({row['reason']})" if row.get("reason") else ""))
	lines += ["", f"[Rapport Actions](https://github.com/{repo}/actions/runs/{os.environ['GITHUB_RUN_ID']})", ""]
	lines += [f"<!-- upstream-audit:{row['fingerprint']} -->" for row in new]
	subprocess.run(["gh", "api", f"repos/{repo}/issues/78/comments", "--method", "POST",
		"-f", "body=" + "\n".join(lines)], check=True, stdout=subprocess.DEVNULL)


if __name__ == "__main__":
	parser = argparse.ArgumentParser()
	parser.add_argument("--notify", action="store_true")
	args = parser.parse_args()
	if args.notify:
		notify()
	else:
		scan()
