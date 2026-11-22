#!/usr/bin/env python3
"""Read-only public API inspection for the FR and EN Wikidebates sites.

Only two HTTPS GET requests for public action=query&meta=siteinfo are made.
No authentication, edits, purges, POST requests, cache manipulation or secrets.
"""
import argparse
import json
import os
from pathlib import Path
import sys
import urllib.error
import urllib.parse
import urllib.request

SITES = {
    "fr": "https://fr.wikidebates.org/w/api.php",
    "en": "https://en.wikidebates.org/w/api.php",
}
PARAMS = {
    "action": "query", "meta": "siteinfo", "siprop": "general|extensions",
    "format": "json",
}
USER_AGENT = "Wikidebates-readonly-deployment-verifier/1.0 (GitHub Actions; 2 GETs)"


def inspect(language, endpoint):
    url = endpoint + "?" + urllib.parse.urlencode(PARAMS)
    request = urllib.request.Request(
        url, headers={"User-Agent": USER_AGENT, "Accept": "application/json"},
        method="GET",
    )
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            if response.geturl().split("?")[0] != endpoint:
                raise ValueError("API redirected to another endpoint")
            raw = response.read(2_000_000)
        payload = json.loads(raw)
        if not isinstance(payload, dict) or not isinstance(payload.get("query"), dict):
            raise ValueError("No query section in public siteinfo response")
        data = payload["query"]
        general = data.get("general", {})
        if not isinstance(general, dict):
            raise ValueError("Unexpected general metadata")
        extensions = data.get("extensions", [])
        if not isinstance(extensions, list):
            raise ValueError("Unexpected extension list")
        names = sorted({
            item["name"] for item in extensions
            if isinstance(item, dict) and isinstance(item.get("name"), str)
        })
        return {
            "site": language, "status": "ok",
            "generator": general.get("generator", "not published"),
            "sitename": general.get("sitename", "not published"),
            "extension_names_public": names,
            "extension_count_public": len(names),
            "note": "Public siteinfo is NOT a proof of on-disk Git commit or PHP-FPM config.",
        }
    except (urllib.error.HTTPError, urllib.error.URLError, ValueError, json.JSONDecodeError) as exc:
        if isinstance(exc, urllib.error.HTTPError):
            message = "HTTP " + str(exc.code)
        else:
            message = type(exc).__name__
        return {"site": language, "status": "unverified", "reason": message}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--strict", action="store_true",
                        help="fail if any public API cannot be verified")
    args = parser.parse_args()
    results = [inspect(lang, url) for lang, url in SITES.items()]
    out = {"read_only": True, "method": "GET", "query": PARAMS,
           "sites": results, "production_commit_verified": False}
    Path("wikidebates-public-site-audit.json").write_text(
        json.dumps(out, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    lines = ["# Wikidebates FR/EN — public API verification", "",
             "Only read-only public siteinfo requests were made; no production files inspected.",
             "", "| Site | Status | Generator | Published extensions |",
             "|---|---|---|---:|"]
    for result in results:
        generator = result.get("generator", "unknown")
        count = result.get("extension_count_public", "-")
        lines.append(f"| {result['site']} | {result['status']} | {generator} | {count} |")
        if result["status"] == "ok":
            names = ", ".join(result["extension_names_public"])
            lines.append(f"\nPublicly advertised extensions ({result['site']}): {names}\n")
        else:
            lines.append(f"\n{result['site']} could not be verified: {result['reason']}\n")
    lines.append("\n**Not checked:** Git SHA, LocalSettings.php, private components, "
                 "installed submodule heads, workers, cron or semantic cache freshness.")
    summary = os.getenv("GITHUB_STEP_SUMMARY")
    if summary:
        with open(summary, "a", encoding="utf-8") as stream:
            stream.write("\n".join(lines) + "\n")
    print("\n".join(lines))
    return int(args.strict and any(result["status"] != "ok" for result in results))


if __name__ == "__main__":
    sys.exit(main())
