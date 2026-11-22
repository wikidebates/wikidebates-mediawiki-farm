"""Network-free contract tests for the bounded public CDN header inspector."""
import importlib.util
from pathlib import Path
import unittest

SOURCE = Path(__file__).with_name("publicCdnHeaderAudit.py")
spec = importlib.util.spec_from_file_location("cdn_audit", SOURCE)
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)


class PublicCdnAuditTest(unittest.TestCase):
	def test_classifies_hit_as_edge_reuse(self):
		self.assertEqual(audit.classify(200, {
			"content-type": "text/html; charset=utf-8", "cf-cache-status": "HIT"
		}), "edge-reuse-observed")
		self.assertEqual(audit.classify(200, {
			"content-type": "text/html", "cf-cache-status": "BYPASS"
		}), "not-reused-observed")
		self.assertEqual(audit.classify(200, {
			"content-type": "text/html", "cf-cache-status": "MISS"
		}), "eligible-or-revalidated-later")
		self.assertEqual(audit.classify(403, {
			"content-type": "text/html", "cf-cache-status": "HIT"
		}), "unverified")

	def test_whitelist_excludes_cookies_and_identifiers(self):
		headers = {"set-cookie": "Sensitive=secret", "cf-ray": "private",
			"cf-cache-status": "DYNAMIC", "cache-control": "private, no-store"}
		self.assertEqual(audit.safe_headers(headers), {
			"cf-cache-status": "DYNAMIC", "cache-control": "private, no-store"
		})

	def test_mainpage_from_public_siteinfo(self):
		self.assertEqual(audit.main_page_url("fr.wikidebates.org", {
			"mainpage": "Accueil", "articlepath": "/wiki/$1"
		}), "https://fr.wikidebates.org/wiki/Accueil")
		with self.assertRaises(ValueError):
			audit.main_page_url("fr.wikidebates.org", {
				"mainpage": "Accueil", "articlepath": "http://bad.example/$1"
			})

	def test_exactly_siteinfo_and_two_public_gets(self):
		calls = []
		def opener(url, agent, limit):
			calls.append((url, limit))
			if limit:
				return 200, {"content-type": "application/json"}, (
					b'{"query":{"general":{"mainpage":"Accueil",'
					b'"articlepath":"/wiki/$1"}}}'
				)
			return 200, {"cf-cache-status": "DYNAMIC", "content-type": "text/html"}, b""
		result = audit.audit_site("fr", "fr.wikidebates.org", opener)
		self.assertEqual(len(calls), 3)
		self.assertEqual([x[1] for x in calls], [131072, 0, 0])
		self.assertEqual(result["api"], "ok")
		self.assertEqual(len(result["html"]), 2)

	def test_unavailable_site_does_not_fabricate_results(self):
		def opener(url, agent, limit):
			raise TimeoutError()
		result = audit.audit_site("en", "en.wikidebates.org", opener)
		self.assertEqual(result["api"], "unverified")
		self.assertEqual(result["html"], [])
		self.assertEqual(result["reason"], "TimeoutError")


if __name__ == "__main__":
	unittest.main()
