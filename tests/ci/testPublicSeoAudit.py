"""Offline public SEO observation tests, no network."""
import unittest
from publicSeoAudit import audit, homepage, same_origin, sitemap_index


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
