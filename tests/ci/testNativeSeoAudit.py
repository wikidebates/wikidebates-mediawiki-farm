"""Offline contracts for the bounded public native SEO audit."""
import json
import unittest

from nativeSeoAudit import inspect, observe, page_key, pair_results

URL = "https://fr.wikidebates.org/wiki/Exemple"
EN = "https://en.wikidebates.org/wiki/Example"
PUBLISHED = "2020-01-01T12:00:00Z"
MODIFIED = "2024-01-01T12:00:00Z"

def fixture(kind="argument"):
	owner = {"@type":"Claim","@id":URL+"#claim","name":"Claim","citation":[{"@type":"Book","name":"Source"}]}
	page = {"@type":["WebPage","LearningResource"],"url":URL,"@id":URL,"inLanguage":"fr",
		"description":"Summary","datePublished":PUBLISHED,"dateModified":MODIFIED}
	page["mainEntity"] = {"@id":owner["@id"]} if kind == "argument" else {"@type":"Question","name":"Question"}
	graph = [page, owner] if kind == "argument" else [page]
	tags = {"description":"Summary","og:description":"Summary","twitter:description":"Summary",
		"og:title":"Title","twitter:title":"Title","og:url":URL,"og:locale":"fr_FR",
		"og:type":"article" if kind == "argument" else "website"}
	if kind == "argument":
		tags.update({"article:published_time":PUBLISHED,"article:modified_time":MODIFIED})
	return tags, graph

def html(tags, graph, extra_head="", extra_body=""):
	meta = "".join('<meta name="'+k+'" content="'+v+'">' for k,v in tags.items())
	links = '<link rel="canonical" href="'+URL+'"><link rel="alternate" hreflang="fr" href="'+URL+'"><link rel="alternate" hreflang="en" href="'+EN+'">'
	return ("<html><head>"+links+meta+extra_head+"</head><body><script type=\"application/ld+json\">"+
		json.dumps({"@context":"https://schema.org","@graph":graph})+"</script>"+extra_body+"</body></html>").encode()

class NativeSeoAuditTest(unittest.TestCase):
	def test_fresh_native_both_kinds(self):
		for kind in ("debate","argument"):
			tags, graph = fixture(kind)
			self.assertEqual(inspect(html(tags,graph),URL,{},"fr",kind)["status"],"ok")

	def test_reports_actual_social_urls_without_fetching_them(self):
		tags, graph = fixture()
		url = "https://example.test/social.png"
		tags.update({"og:image": url, "twitter:image": url, "og:image:alt": "Balance"})
		result = inspect(html(tags, graph), URL, {}, "fr", "argument")
		self.assertEqual(result["status"], "ok")
		self.assertEqual(result["social_images"]["og:image"], [url])
		self.assertEqual(result["social_images"]["twitter:image"], [url])
		self.assertEqual(result["social_images"]["og:image:alt"], ["Balance"])

	def test_mismatches(self):
		def result(tags, graph, **kw):
			return inspect(html(tags,graph,**kw),URL,{},"fr","argument")
		for change in ("description","date","legacy","marker","subjectOf","count"):
			tags, graph = fixture()
			if change == "description":
				tags["description"] = "Wrong"
			elif change == "date":
				graph[0]["dateModified"] = "invalid"
			elif change == "legacy":
				graph[0]["@type"] = "QAPage"
			elif change == "marker":
				graph[1]["name"] = "UNIQ--ref-001-QINU"
			elif change == "subjectOf":
				graph[1]["subjectOf"] = []
			else:
				graph[0]["hasPart"] = [{"mainEntity":{"@type":"ItemList","numberOfItems":2,"itemListElement":[{"position":1}]}}]
			with self.subTest(change=change):
				self.assertEqual(result(tags,graph)["status"],"mismatch")
		tags, graph = fixture()
		self.assertEqual(result(tags,graph,extra_head='<meta name="description" content="Summary">')["status"],"mismatch")
		self.assertEqual(result(tags,graph,extra_head='<script type="application/ld+json">{}</script>')["status"],"mismatch")

	def test_body_navigation_not_hreflang(self):
		tags, graph = fixture()
		r = inspect(html(tags,graph,extra_body='<a rel="alternate" hreflang="de" href="https://other.test/">X</a>'),URL,{},"fr","argument")
		self.assertEqual(r["status"],"ok")
		self.assertNotIn("de",r["hreflang"])

	def test_url_normalization(self):
		self.assertEqual(page_key(URL+"_%3F"),page_key(URL+"_%3f"))
		self.assertIsNone(page_key(URL+"?action=purge"))
		self.assertIsNone(page_key("https://other.test/wiki/Test"))

	def test_blocked_http_is_unverified(self):
		calls = []
		def opener(url, host):
			calls.append(url)
			return 403,url,{},b""
		row = observe(("fr","argument","Exemple",None),opener)
		self.assertEqual(row["status"],"unverified")
		self.assertEqual(row["http"],403)
		self.assertEqual(calls,[URL])

	def test_reciprocity(self):
		a = {"lang":"fr","pair":"argument","canonical":URL,"hreflang":{"en":[EN]}}
		b = {"lang":"en","pair":"argument","canonical":EN,"hreflang":{"fr":[URL]}}
		self.assertEqual(pair_results([a,b])[0]["status"],"ok")
		b["hreflang"] = {}
		self.assertEqual(pair_results([a,b])[0]["status"],"mismatch")
		b["canonical"] = None
		self.assertEqual(pair_results([a,b])[0]["status"],"unverified")

if __name__ == "__main__":
	unittest.main()
