#!/usr/bin/env python3
"""Read-only comparison of pinned gitlinks and tracked GitHub branches.

Gerrit branches are checked against GitHub mirrors, for advisory purposes.
This program never fetches, checks out, updates, or pushes Git repositories.
"""
import argparse
import concurrent.futures
import configparser
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
API = "https://api.github.com"
GITHUB_RE = re.compile(r"github\.com[:/]([^/\s]+/[^/\s]+?)(?:\.git)?$")
GERRIT = "https://gerrit.wikimedia.org/r/mediawiki/extensions/"


def gitlinks():
    data = subprocess.check_output(["git", "ls-files", "--stage", "-z"], cwd=ROOT)
    result = {}
    for entry in data.split(b"\0"):
        if not entry:
            continue
        metadata, path = entry.split(b"\t", 1)
        mode, sha, _stage = metadata.split()
        if mode == b"160000":
            result[os.fsdecode(path)] = sha.decode("ascii")
    return result


def parse_modules():
    config = configparser.RawConfigParser()
    config.read(ROOT / ".gitmodules", encoding="utf-8")
    pins = gitlinks()
    modules = []
    for section in config.sections():
        if not section.startswith('submodule "') or not section.endswith('"'):
            continue
        path = config.get(section, "path")
        url = config.get(section, "url")
        branch = config.get(section, "branch", fallback="main")
        match = GITHUB_RE.search(url)
        source = "github"
        if match:
            repo = match.group(1)
        elif url.startswith(GERRIT):
            repo = "wikimedia/mediawiki-extensions-" + url[len(GERRIT):]
            source = "github-mirror-of-gerrit"
        else:
            repo = None
            source = "unsupported"
        modules.append({
            "path": path, "url": url, "repo": repo, "branch": branch,
            "pinned": pins.get(path), "source": source,
        })
    return sorted(modules, key=lambda item: item["path"])


def remote_head(item):
    if not item["pinned"] or not item["repo"]:
        return {**item, "status": "unavailable", "reason": "missing pin or unsupported repository"}
    repo = urllib.parse.quote(item["repo"], safe="/")
    branch = urllib.parse.quote(item["branch"], safe="")
    address = f"{API}/repos/{repo}/branches/{branch}"
    headers = {
        "Accept": "application/vnd.github+json",
        "User-Agent": "wikidebates-pinned-dependencies-audit",
        "X-GitHub-Api-Version": "2022-11-28",
    }
    token = os.environ.get("GITHUB_TOKEN")
    if token:
        headers["Authorization"] = "Bearer " + token
    try:
        with urllib.request.urlopen(
            urllib.request.Request(address, headers=headers), timeout=15
        ) as response:
            payload = json.load(response)
        head = payload["commit"]["sha"]
        status = "match" if head == item["pinned"] else "differs"
        return {**item, "status": status, "tracked_head": head}
    except (urllib.error.URLError, KeyError, ValueError, TimeoutError) as exc:
        reason = f"HTTP {exc.code}" if isinstance(exc, urllib.error.HTTPError) else type(exc).__name__
        return {**item, "status": "unavailable", "reason": reason}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--offline", action="store_true", help="check local gitlinks only")
    args = parser.parse_args()
    inventory = parse_modules()
    if args.offline:
        result = [{**item, "status": "unchecked"} for item in inventory]
    else:
        with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
            result = list(pool.map(remote_head, inventory))
    stats = {key: sum(i["status"] == key for i in result)
             for key in ("match", "differs", "unavailable", "unchecked")}
    print(f"{len(result)} submodules: " + ", ".join(f"{k}={v}" for k, v in stats.items()))
    print("Read-only audit: no Git remote or checkout was changed.")
    sha = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip()
    payload = {
        "head": sha, "read_only": True,
        "gerrit_mirrors_are_advisory": True,
        "totals": stats, "submodules": result,
    }
    (ROOT / "submodule-audit.json").write_text(
        json.dumps(payload, indent=2) + "\n", encoding="utf-8"
    )
    tick = chr(96)
    summary = [
        "# Read-only pinned dependency audit",
        "",
        f"**{len(result)}** gitlinks inspected. "
        + ", ".join(f"{key}: **{val}**" for key, val in stats.items()),
        "",
        "An updated upstream commit is not necessarily a safe upgrade or vulnerability.",
        "Gerrit references are checked via GitHub mirrors. Private forks may be inaccessible.",
        "",
        "| Submodule | Pin | Branch | Head | Status |",
        "|---|---|---|---|---|",
    ]
    for item in result:
        pin = (item["pinned"] or "-")[:12]
        head = (item.get("tracked_head") or "-")[:12]
        summary.append(
            f"| {item['path']} | {tick}{pin}{tick} | {tick}{item['branch']}{tick} "
            f"| {tick}{head}{tick} | {item['status']} |"
        )
    destination = os.environ.get("GITHUB_STEP_SUMMARY")
    if destination:
        with open(destination, "a", encoding="utf-8") as out:
            out.write("\n".join(summary) + "\n")
    if not result or any(not item["pinned"] for item in result):
        print("Missing tracked gitlink(s).", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
