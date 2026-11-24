"""Offline tests for the reviewed policy and bounded structural audit."""
import re
import unittest
from urllib.parse import quote

from robotsPolicy import LANGS, audit, compare, parse, render


def fixture_allowed(rules, path):
	# Limited matcher for our ASCII/UTF-8 fixture paths, not an RFC implementation.
	# Match the most specific allow/disallow rule; Allow wins a tie.
	matches = []
	path = quote(path, safe="/:?&=%$*._-")
	for key, value in rules:
		if key not in ("allow", "disallow") or not value:
			continue
		value = quote(value, safe="/:?&=%$*._-")
		end = value.endswith("$")
		pattern = re.escape(value[:-1] if end else value).replace(r"\*", ".*")
		if re.match(pattern + ("$" if end else ""), path):
			matches.append((len(value.rstrip("$").replace("*", "")), key == "allow"))
	return max(matches)[1] if matches else True


class RobotsPolicyTest(unittest.TestCase):
	def test_all_existing_denials_remain(self):
		blocked = """Mediapartners-Google*|UbiCrawler|DOC|Zao|sitecheck.internetseer.com|Zealbot|MSIECrawler|SiteSnagger|WebStripper|WebCopier|Fetch|Offline Explorer|Teleport|TeleportPro|WebZIP|linko|HTTrack|Microsoft.URL.Control|Xenu|larbin|libwww|ZyBORG|Download Ninja|fast|wget|grub-client|k2spider|NPBot|WebReaper|GPTBot|ChatGPT-User|ClaudeBot|Claude-User|Claude-SearchBot|CCBot|Bytespider|PerplexityBot|Perplexity-User|YouBot|Meta-ExternalAgent|Meta-ExternalFetcher|Applebot-Extended|Omgilibot|Omgili|Diffbot|ImagesiftBot|Timpibot"""
		groups = parse(render())["groups"]
		for agent in blocked.lower().split("|"):
			self.assertEqual(groups[agent], {("disallow", "/")}, agent)
		self.assertEqual(set(groups), set(blocked.lower().split("|")) | {"*"})

	def test_default_policy(self):
		rules = parse(render())["groups"]["*"]
		self.assertIn(("crawl-delay", "10"), rules)
		for path in (
			"/", "/wiki/Wikidébats:Accueil", "/wiki/Le_revenu_de_base",
			"/w/load.php?lang=fr&modules=skins.vector.styles",
			"/w/skins/Vector/resources/style.css",
			"/w/skins/MinervaNeue/resources/icon.svg?version=abc",
			"/w/skins/Vector/resources/font.woff2",
			"/w/api.php?action=mobileview&page=Accueil",
			"/api/rest_v1/?doc",
			"/w/sitemaps/fr/sitemap-index-frwikidebates.xml",
			"/w/images/a/a1/example.jpg",
		):
			self.assertTrue(fixture_allowed(rules, path), path)
		for path in (
			"/w/skins/Vector/skin.json", "/w/skins/Vector/test.php",
			"/w/index.php?title=Page", "/wiki/Page?action=history",
			"/wiki/Page?foo=bar&oldid=42", "/wiki/Page?diff=42",
			"/w/api.php?action=query&meta=siteinfo",
			"/api/rest_v1/page/html/Page", "/trap/",
			"/wiki/Special:Search", "/wiki/Spécial:Recherche",
			"/wiki/Spezial:Suche", "/wiki/Especial:Buscar", "/wiki/Speciale:Ricerca",
		):
			self.assertFalse(fixture_allowed(rules, path), path)

	def test_one_or_six_sitemaps(self):
		shared = parse(render())
		self.assertEqual(len(shared["sitemaps"]), 6)
		for lang in LANGS:
			specific = parse(render(lang))
			self.assertEqual(specific["groups"], shared["groups"])
			self.assertEqual(specific["sitemaps"], [
				f"https://{lang}.wikidebates.org/w/sitemaps/{lang}/sitemap-index-{lang}wikidebates.xml"
			])
		with self.assertRaises(ValueError):
			render("unknown")

	def test_compare_exposes_removed_blocks_and_unknown_directives(self):
		old = "User-agent: CustomBot\nDisallow: /\nUser-agent: *\nDisallow: /private/\nContent-Signal: search=yes\n"
		result = compare(old, render("fr"))
		self.assertIn("custombot", result["lost_full_blocks"])
		self.assertIn(["disallow", "/private/"], [list(x) for x in result["changed_rules"]["*"]["removed"]])
		self.assertEqual(result["unrecognized_current_directives"], ["content-signal: search=yes"])

	def test_audit_single_read_and_non_plain_failure(self):
		calls = []
		def opener(lang):
			calls.append(lang)
			return 200, "text/plain; charset=utf-8", render(lang).encode()
		result = audit("fr", opener)
		self.assertEqual(calls, ["fr"])
		self.assertEqual(result["status"], "observed")
		self.assertEqual(result["comparison"]["changed_rules"], {})
		self.assertEqual(audit("fr", lambda _: (200, "text/html", b"hello"))["status"], "unverified")
		self.assertEqual(audit("fr", lambda _: (403, "text/plain", b""))["status"], "unverified")


if __name__ == "__main__":
	unittest.main()
