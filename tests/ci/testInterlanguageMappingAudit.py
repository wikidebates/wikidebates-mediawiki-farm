import unittest

from interlanguageMappingAudit import analyze, LANGS


def page(page_id, key, lookup=None, redirect=False, namespace=0):
	return dict(status="exists", id=page_id, key=key, lookup=lookup or key.replace("_", " "), redirect=redirect, namespace=namespace)


def fixture():
	central = {
		"pages": {"1": page(1, "Débat"), "2": page(2, "Autre")},
		"links": [
			["langlinks", 1, "en", "Debate"],
			["interlanguage_links", 1, "de", "Debatte"],
		],
	}
	editions = {lang: {"targets": {}, "stored": {}} for lang in LANGS[1:]}
	editions["en"] = {"targets": {"Debate": page(3, "Debate")},
		"stored": {"3": [["fr", "Débat"], ["de", "Debatte"]]}}
	editions["de"] = {"targets": {"Debatte": page(4, "Debatte")},
		"stored": {"4": [["fr", "Débat"], ["en", "Debate"]]}}
	return central, editions


class MappingAuditTests(unittest.TestCase):
	def test_reciprocal_mixed_cluster(self):
		central, editions = fixture()
		report = analyze(central, editions)
		self.assertEqual(report["issues"], {})
		self.assertEqual(report["counts"]["stored_targets_checked"], 2)

	def test_missing_stored_fallback_peer(self):
		central, editions = fixture()
		editions["en"]["stored"]["3"] = [["fr", "Débat"]]
		row, = analyze(central, editions)["issues"]["STORED_PEER_MISSING"]
		self.assertEqual((row["lang"], row["peer"]), ("en", "de"))

	def test_noncanonical_alias_and_reverse_key(self):
		central, editions = fixture()
		central["links"][0][3] = "Project:Debate"
		editions["en"]["targets"] = {
			"Project:Debate": page(3, "Wikidebates:Debate", "Project:Debate"),
		}
		issues = analyze(central, editions)["issues"]
		self.assertIn("NONCANONICAL_TARGET_PATH", issues)
		self.assertNotIn("NATIVE_REVERSE_LOOKUP_RISK", issues)
		self.assertIn("STORED_PEER_MISMATCH", issues)

	def test_equivalent_namespace_aliases_are_not_mapping_errors(self):
		central, editions = fixture()
		central["pages"]["1"] = page(1, "Wikidébats:Débat", "Project:Débat", namespace=4)
		central["links"][0][3] = "Project:Debate"
		editions["en"]["targets"] = {
			"Project:Debate": page(3, "Wikidebates:Debate", "Project:Debate", namespace=4),
		}
		editions["en"]["stored"]["3"] = [["fr", "Project: Débat"], ["de", "Debatte"]]
		editions["de"]["stored"]["4"] = [["fr", "Project:Débat"], ["en", "Project:Debate"]]
		report = analyze(central, editions)
		self.assertNotIn("STORED_PEER_MISMATCH", report["issues"])
		self.assertIn("NONCANONICAL_TARGET_PATH", report["issues"])
		self.assertEqual(report["counts"]["stored_peer_equivalent_namespace_forms"], 3)

	def test_arbitrary_main_namespace_colon_is_not_an_alias(self):
		central, editions = fixture()
		central["pages"]["1"] = page(1, "Topic:Debate")
		editions["en"]["stored"]["3"][0][1] = "Topic: Debate"
		self.assertIn("STORED_PEER_MISMATCH", analyze(central, editions)["issues"])

	def test_orphan_missing_redirect_and_conflict(self):
		central, editions = fixture()
		central["links"] += [
			["interlanguage_links", 1, "en", "Other"],
			["langlinks", 2, "it", "Missing"],
			["langlinks", 99, "de", "Debatte"],
			["langlinks", 2, "de", "Debatte"],
		]
		editions["en"]["targets"]["Other"] = page(5, "Other", redirect=True)
		editions["it"]["targets"]["Missing"] = {"status": "missing"}
		issues = analyze(central, editions)["issues"]
		for kind in ("ORPHAN_SOURCE", "TARGET_MISSING", "TARGET_REDIRECT",
			"CONFLICT_NATIVE_FALLBACK", "TARGET_SHARED_BY_CENTRAL_PAGES"):
			self.assertIn(kind, issues)

	def test_absent_and_malformed_stored_properties(self):
		central, editions = fixture()
		editions["en"]["stored"].clear()
		editions["de"]["stored"]["4"] = None
		issues = analyze(central, editions)["issues"]
		self.assertIn("STORED_LINKS_ABSENT", issues)
		self.assertIn("STORED_LINKS_MALFORMED", issues)


if __name__ == "__main__":
	unittest.main()
