"""Offline public SEO observation tests, no network."""
import unittest
from urllib.request import Request
from publicSeoAudit import (
	NAMESPACE_QUERY, OneRedirect, allowed_url, audit, audit_namespaces,
	homepage, namespace_inventory, same_origin, sitemap_index,
)
from urllib.parse import urlencode


def index(*urls):
	return ('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
		+ "".join("<sitemap><loc>" + url + "</loc></sitemap>" for url in urls)
		+ "</sitemapindex>").encode()


class SeoAuditTest(unittest.TestCase):
	def test_index_namespace_and_duplicates(self):
		prefix = "https://fr.wikidebates.org/w/sitemaps/fr/sitemap-frwikidebates-NS_"
		result = sitemap_index(index(
			prefix + "0-0.xml.gz", prefix + "3100-0.xml.gz",
			prefix + "0-0.xml.gz", "https://en.wikidebates.org/other.xml",
			"https://fr.wikidebates.org/unknown.xml",
		), "fr")
		self.assertEqual(result, {
			"child_count": 5, "files_by_namespace": {"0": 2, "3100": 1},
			"duplicate_locations": 1, "wrong_origin": 1, "unclassified": 1,
		})

	def test_reject_non_index_and_dtd(self):
		for payload in (b"<html/>", b'<!DOCTYPE sitemapindex><sitemapindex/>'):
			with self.assertRaises(ValueError):
				sitemap_index(payload, "fr")

	def test_origin_rejects_credentials_and_non_https(self):
		for url in ("http://fr.wikidebates.org/", "https://other.test/",
			"https://user@fr.wikidebates.org/", "https://fr.wikidebates.org:81/",
			"https://fr.wikidebates.org/#fragment"):
			self.assertFalse(same_origin(url, "fr.wikidebates.org"))
		self.assertTrue(same_origin("https://fr.wikidebates.org/wiki/Accueil", "fr.wikidebates.org"))

	def test_restrict_requests_to_read_only_paths(self):
		host = "fr.wikidebates.org"
		self.assertFalse(allowed_url("https://" + host + "/wiki/Accueil?action=purge", host, "homepage"))
		self.assertFalse(allowed_url("https://" + host + "/wiki/Accueil?action=purge", host, "image"))
		self.assertFalse(allowed_url("https://" + host + "/logo.png?action=purge", host, "image"))
		self.assertTrue(allowed_url("https://" + host + "/logo.png?version=" + "a" * 32, host, "image"))

	def test_redirect_keeps_head_and_rejects_second_or_action(self):
		host = "fr.wikidebates.org"
		handler = OneRedirect(host, "image")
		req = Request("https://" + host + "/a.png", method="HEAD")
		newurl = "https://" + host + "/b.png"
		redirected = handler.redirect_request(req, None, 302, "", {}, newurl)
		self.assertEqual(redirected.get_method(), "HEAD")
		with self.assertRaises(ValueError):
			handler.redirect_request(req, None, 302, "", {}, newurl)
		with self.assertRaises(ValueError):
			OneRedirect(host, "image").redirect_request(
				req, None, 302, "", {}, "https://" + host + "/wiki/Accueil?action=purge"
			)

	def test_namespace_inventory_exact_query_and_content_flag(self):
		host = "fr.wikidebates.org"
		url = "https://" + host + "/w/api.php?" + urlencode(NAMESPACE_QUERY)
		self.assertTrue(allowed_url(url, host, "namespaces"))
		self.assertFalse(allowed_url(url + "&action=purge", host, "namespaces"))
		self.assertFalse(allowed_url(url.replace("siteinfo", "tokens"), host, "namespaces"))
		body = b'{"query":{"general":{"mainpage":"Project:Home"},"namespaces":{'
		body += b'"0":{"id":0,"*":"","content":""},"4":{"id":4,"*":"Project","canonical":"Project"},'
		body += b'"3000":{"id":3000,"*":"Portal","content":""}}}}'
		calls = []
		def opener(request_url, request_host):
			calls.append((request_url, request_host))
			return 200, request_url, {}, body
		result = audit_namespaces("fr", opener)
		self.assertEqual(calls, [(url, host)])
		self.assertEqual(result["mainpage"], "Project:Home")
		self.assertTrue(result["namespaces"]["0"]["content"])
		self.assertFalse(result["namespaces"]["4"]["content"])
		self.assertEqual(result["namespaces"]["3000"]["name"], "Portal")
		self.assertTrue(result["namespaces"]["3000"]["content"])
		with self.assertRaises(ValueError):
			namespace_inventory(b'{"query":{"general":{},"namespaces":{}}}')

	def test_namespace_unavailable_is_not_empty_success(self):
		def opener(url, host):
			return 403, url, {}, b""
		self.assertEqual(audit_namespaces("en", opener), {"http": 403})

	def test_homepage_head_only_and_graph(self):
		head, details = homepage(b'<html><head><link rel="canonical" href="https://example.test/">'
			b'<meta name="description" content="short"><meta property="og:image" content="/logo.png">'
			b'<script type="application/ld+json">{"@graph":[{"@type":"WebSite"},{"@type":"Organization"}]}</script>'
			b'<script type="application/ld+json">{bad}</script></head><body>'
			b'<meta name="description" content="not in head"></body></html>')
		self.assertEqual(details["descriptions"], 1)
		self.assertEqual(details["canonical_count"], 1)
		self.assertEqual(details["jsonld_types"], ["Organization", "WebSite"])
		self.assertEqual(details["jsonld_invalid"], 1)
		self.assertEqual(head.tags["og:image"], ["/logo.png"])

	def test_bounded_requests_and_image_head(self):
		calls = []
		def opener(url, host, method="GET"):
			calls.append((url, method))
			if "sitemap-index" in url:
				return 200, url, {}, index()
			if method == "HEAD":
				return 200, url, {"content-type": "image/png"}, b""
			return 200, url, {"content-type": "text/html"}, b'<meta property="og:image" content="/logo.png">'
		result = audit("fr", opener, include_home=True)
		self.assertEqual(len(calls), 3)
		self.assertEqual(calls[-1], ("https://fr.wikidebates.org/logo.png", "HEAD"))
		self.assertEqual(result["homepage"]["image_probe"]["http"], 200)

	def test_external_image_is_not_fetched(self):
		calls = []
		def opener(url, host, method="GET"):
			calls.append(url)
			if "sitemap-index" in url:
				return 200, url, {}, index()
			return 200, url, {"content-type": "text/html"}, b'<meta property="og:image" content="https://other.test/logo.png">'
		result = audit("en", opener, include_home=True)
		self.assertEqual(len(calls), 2)
		self.assertIn("unverified", result["homepage"]["image_probe"])

	def test_unavailable_index_does_not_hide_homepage(self):
		def opener(url, host, method="GET"):
			if "sitemap-index" in url:
				raise TimeoutError()
			return 403, url, {}, b""
		result = audit("fr", opener, include_home=True)
		self.assertEqual(result["sitemap"], {"unverified": "TimeoutError"})
		self.assertEqual(result["homepage"]["http"], 403)


if __name__ == "__main__":
	unittest.main()
