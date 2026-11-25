import unittest

from interlanguageMappingAudit import analyze, LANGS


def page(page_id, key, lookup=None, redirect=False, namespace=0, dbkey=None, **extra):
	return dict(status="exists", id=page_id, key=key, lookup=lookup or key.replace("_", " "),
		redirect=redirect, namespace=namespace,
		dbkey=dbkey if dbkey is not None else (key.split(":", 1)[1] if namespace else key), **extra)


def missing(key, namespace=0, status="missing"):
	return dict(status=status, namespace=namespace, dbkey=key, key=key)


def fixture():
	fr = page(1, "Débat")
	en = page(3, "Debate")
	de = page(4, "Debatte")
	central = {
		"pages": {"1": fr, "2": page(2, "Autre")},
		"links": [
			["langlinks", 1, "en", "Debate"],
			["interlanguage_links", 1, "de", "Debatte"],
		],
	}
	editions = {lang: {"targets": {}, "stored": {}} for lang in LANGS}
	editions["fr"]["targets"]["Débat"] = fr
	editions["en"] = {"targets": {"Debate": en},
		"stored": {"3": [["fr", "Débat"], ["de", "Debatte"]]}, "stored_pages": {"3": en}}
	editions["de"] = {"targets": {"Debatte": de},
		"stored": {"4": [["fr", "Débat"], ["en", "Debate"]]}, "stored_pages": {"4": de}}
	return central, editions


class MappingAuditTests(unittest.TestCase):
	def test_reciprocal_mixed_cluster(self):
		central, editions = fixture()
		report = analyze(central, editions)
		self.assertEqual(report["issues"], {})
		self.assertEqual(report["review"], {})
		self.assertEqual(report["counts"]["stored_targets_checked"], 2)

	def test_missing_stored_fallback_peer(self):
		central, editions = fixture()
		editions["en"]["stored"]["3"] = [["fr", "Débat"]]
		row, = analyze(central, editions)["issues"]["STORED_PEER_MISSING"]
		self.assertEqual((row["lang"], row["peer"]), ("en", "de"))

	def test_noncanonical_alias_and_actual_mismatch(self):
		central, editions = fixture()
		central["links"][0][3] = "Project:Debate"
		editions["en"]["targets"]["Project:Debate"] = page(3, "Wikidebates:Debate", "Project:Debate", namespace=4)
		# The persistent string Debate resolves to a different, main-namespace page.
		editions["en"]["targets"]["Debate"] = page(9, "Debate")
		report = analyze(central, editions)
		self.assertIn("SYNTAX_VARIANT", report["observations"])
		self.assertNotIn("NATIVE_REVERSE_LOOKUP_RISK", report["review"])
		self.assertIn("STORED_PEER_MISMATCH", report["issues"])

	def test_equivalent_namespace_aliases_and_whitespace_are_not_errors(self):
		central, editions = fixture()
		fr = page(1, "Wikidébats:Débat", "Project:Débat", namespace=4)
		en = page(3, "Wikidebates:Debate", "Project:Debate", namespace=4)
		central["pages"]["1"] = fr
		central["links"][0][3] = "  Project:__Debate"
		editions["fr"]["targets"] = {"Project: Débat": fr, "Wikidébats:Débat": fr}
		editions["en"]["targets"] = {"  Project:__Debate": en, "Wikidebates:Debate": en}
		editions["en"]["stored"]["3"] = [["fr", "Project: Débat"], ["de", "Debatte"]]
		editions["de"]["stored"]["4"] = [["fr", "Wikidébats:Débat"], ["en", "Wikidebates:Debate"]]
		report = analyze(central, editions)
		self.assertNotIn("STORED_PEER_MISMATCH", report["issues"])
		self.assertIn("SYNTAX_VARIANT", report["observations"])
		self.assertIn("NATIVE_REVERSE_LOOKUP_RISK", report["review"])
		self.assertEqual(report["counts"]["stored_peer_equivalent_namespace_forms"], 1)

	def test_arbitrary_main_namespace_colon_is_not_an_alias(self):
		central, editions = fixture()
		central["pages"]["1"] = page(1, "Topic:Debate")
		editions["fr"]["targets"]["Topic: Debate"] = missing("Topic:_Debate")
		editions["en"]["stored"]["3"][0][1] = "Topic: Debate"
		self.assertIn("STORED_PEER_MISMATCH", analyze(central, editions)["issues"])

	def test_valid_redirect_resolves_destination_and_redundant_fallback(self):
		central, editions = fixture()
		dest = page(3, "Template:Actual", namespace=10)
		alias = page(8, "Debate", redirect=True, redirect_status="valid", destination=dest)
		editions["en"]["targets"] = {"Debate": alias, "Template: Actual": dest}
		editions["de"]["stored"]["4"][1][1] = "Template: Actual"
		central["links"].append(["interlanguage_links", 1, "en", "Template: Actual"])
		report = analyze(central, editions)
		self.assertEqual(report["issues"], {})
		self.assertIn("TARGET_REDIRECT_VALID", report["observations"])
		self.assertIn("REDUNDANT_FALLBACK", report["observations"])
		self.assertEqual(report["counts"]["effective_existing_resolved_targets"], 2)
		row, = report["review"]["HREFLANG_EFFECTIVE_REDIRECT"]
		self.assertEqual((row["target"], row["destination"], row["shared_target"]),
			("Debate", "Template:Actual", False))
		self.assertNotIn("HREFLANG_STORED_REDIRECT", report["review"])

	def test_hreflang_shadowed_redirect_is_not_effective(self):
		central, editions = fixture()
		editions["en"]["targets"]["Old debate"] = page(8, "Old_debate", redirect=True,
			redirect_status="valid", destination=editions["en"]["targets"]["Debate"])
		central["links"].append(["interlanguage_links", 1, "en", "Old debate"])
		report = analyze(central, editions)
		self.assertIn("REDUNDANT_FALLBACK", report["observations"])
		self.assertNotIn("HREFLANG_EFFECTIVE_REDIRECT", report["review"])
		self.assertNotIn("HREFLANG_STORED_REDIRECT", report["review"])

	def test_stored_redirect_is_equivalent_but_not_canonical(self):
		central, editions = fixture()
		editions["en"]["targets"]["Old debate"] = page(8, "Old_debate", redirect=True,
			redirect_status="valid", destination=editions["en"]["targets"]["Debate"])
		editions["de"]["stored"]["4"][1][1] = "Old debate"
		report = analyze(central, editions)
		self.assertEqual(report["issues"], {})
		row, = report["review"]["HREFLANG_STORED_REDIRECT"]
		self.assertEqual((row["lang"], row["peer"], row["actual"], row["destination"]),
			("de", "en", "Old debate", "Debate"))
		self.assertNotIn("HREFLANG_EFFECTIVE_REDIRECT", report["review"])

	def test_shared_redirect_destination_is_not_a_unique_group(self):
		central, editions = fixture()
		editions["en"]["targets"]["Old debate"] = page(8, "Old_debate", redirect=True,
			redirect_status="valid", destination=editions["en"]["targets"]["Debate"])
		central["links"].append(["langlinks", 2, "en", "Old debate"])
		report = analyze(central, editions)
		self.assertIn("TARGET_SHARED_BY_CENTRAL_PAGES", report["issues"])
		row, = report["review"]["HREFLANG_EFFECTIVE_REDIRECT"]
		self.assertTrue(row["shared_target"])

	def test_shared_file_and_message_are_legitimate_virtual_peers(self):
		central, editions = fixture()
		central["links"] += [["langlinks", 1, "it", "File:Logo.png"],
			["langlinks", 1, "es", "MediaWiki:Mainpage"]]
		editions["it"]["targets"]["File:Logo.png"] = missing("Logo.png", 6, "shared_file")
		editions["es"]["targets"]["MediaWiki:Mainpage"] = missing("Mainpage", 8, "message")
		for lang, page_id in (("en", "3"), ("de", "4")):
			editions[lang]["stored"][page_id] += [["it", "File:Logo.png"], ["es", "MediaWiki:Mainpage"]]
		report = analyze(central, editions)
		self.assertEqual(report["issues"], {})
		self.assertIn("TARGET_SHARED_FILE", report["observations"])
		self.assertIn("TARGET_MESSAGE", report["observations"])

	def test_unverified_and_missing_are_review_not_false_extra_peers(self):
		central, editions = fixture()
		central["links"] += [["langlinks", 1, "it", "Missing"], ["langlinks", 1, "es", "File:Unknown.png"]]
		editions["it"]["targets"]["Missing"] = missing("Missing")
		editions["es"]["targets"]["File:Unknown.png"] = missing("Unknown.png", 6, "file_unverified")
		for lang, page_id in (("en", "3"), ("de", "4")):
			editions[lang]["stored"][page_id] += [["it", "Missing"], ["es", "File:Unknown.png"]]
		report = analyze(central, editions)
		self.assertEqual(report["issues"], {})
		self.assertIn("TARGET_MISSING", report["review"])
		self.assertIn("TARGET_FILE_UNVERIFIED", report["review"])

	def test_orphan_broken_redirect_and_conflict(self):
		central, editions = fixture()
		central["links"] += [
			["interlanguage_links", 1, "en", "Other"],
			["langlinks", 2, "it", "Missing"],
			["langlinks", 99, "de", "Debatte"],
			["langlinks", 2, "de", "Debatte"],
		]
		editions["en"]["targets"]["Other"] = page(5, "Other", redirect=True, redirect_status="broken")
		editions["it"]["targets"]["Missing"] = missing("Missing")
		report = analyze(central, editions)
		for kind in ("ORPHAN_SOURCE", "TARGET_REDIRECT_BROKEN",
			"CONFLICT_NATIVE_FALLBACK", "TARGET_SHARED_BY_CENTRAL_PAGES"):
			self.assertIn(kind, report["issues"])
		self.assertIn("TARGET_MISSING", report["review"])
		self.assertEqual(report["counts"]["stored_targets_skipped_ambiguous"], 2)

	def test_loop_and_external_redirect_are_distinguished(self):
		central, editions = fixture()
		editions["en"]["targets"]["Debate"] = page(3, "Debate", redirect=True, redirect_status="loop")
		editions["de"]["targets"]["Debatte"] = page(4, "Debatte", redirect=True, redirect_status="interwiki")
		report = analyze(central, editions)
		self.assertIn("TARGET_REDIRECT_LOOP", report["issues"])
		self.assertIn("TARGET_REDIRECT_INTERWIKI", report["review"])

	def test_absent_and_malformed_stored_properties(self):
		central, editions = fixture()
		editions["en"]["stored"].clear()
		editions["de"]["stored"]["4"] = None
		report = analyze(central, editions)
		self.assertIn("STORED_LINKS_ABSENT", report["review"])
		self.assertIn("STORED_LINKS_MALFORMED", report["issues"])

	def test_unverified_actual_identity_is_not_claimed_as_mismatch(self):
		central, editions = fixture()
		editions["en"]["stored"]["3"][0][1] = "Unknown alias"
		report = analyze(central, editions)
		self.assertIn("STORED_PEER_UNVERIFIED", report["review"])
		self.assertNotIn("STORED_PEER_MISMATCH", report["issues"])


	def test_properties_of_former_peers_are_detected(self):
		central, editions = fixture()
		editions["en"]["stored"]["5"] = [["fr", "Débat"]]
		editions["en"]["stored"]["6"] = [["fr", "Débat"]]
		editions["en"]["stored"]["7"] = None
		editions["en"]["stored_pages"]["5"] = page(5, "Former_peer")
		editions["en"]["stored_pages"]["6"] = page(6, "Old_redirect", redirect=True)
		report = analyze(central, editions)
		self.assertEqual(report["counts"]["stored_properties_without_effective_group"], 3)
		self.assertEqual(report["review"]["STORED_WITHOUT_EFFECTIVE_GROUP"][0]["target"], "Former_peer")
		self.assertIn("STORED_ON_REDIRECT", report["review"])
		self.assertIn("STORED_ORPHAN_PAGE", report["issues"])
		self.assertIn("STORED_LINKS_MALFORMED", report["issues"])

	def test_bounded_exact_variant_coverage_uses_receiver_spellings(self):
		central, editions = fixture()
		raw = "Template: Debate"
		central["links"][0][3] = raw
		target = page(3, "Template:Debate", namespace=10,
			lookup_spellings=["Template:Debate", "Template: Debate"])
		editions["en"]["targets"] = {raw: target, "Debate": target}
		editions["en"]["bounded_exact_lookup"] = True
		report = analyze(central, editions)
		self.assertNotIn("NATIVE_REVERSE_LOOKUP_RISK", report["review"])
		self.assertIn("BOUNDED_VARIANT_SUPPORTED", report["observations"])
		self.assertEqual(report["counts"]["bounded_exact_lookup_languages"], 1)

	def test_unusual_native_and_supplemental_spellings_are_reviewed(self):
		central, editions = fixture()
		raw = " Alias : debate "
		central["links"][0][3] = raw
		central["links"].append(["interlanguage_links", 1, "de", raw])
		target = page(3, "Template:Debate", namespace=10, lookup_spellings=["Template:Debate"])
		editions["en"]["targets"][raw] = target
		editions["de"]["targets"][raw] = target
		report = analyze(central, editions)
		self.assertIn("NATIVE_REVERSE_LOOKUP_RISK", report["review"])
		self.assertIn("SUPPLEMENTAL_REVERSE_LOOKUP_RISK", report["review"])
		self.assertNotIn("BOUNDED_VARIANT_SUPPORTED", report["observations"])

	def test_legacy_risk_retained_without_receiver_spelling_evidence(self):
		central, editions = fixture()
		raw = " Debate "
		central["links"][0][3] = raw
		editions["en"]["targets"][raw] = page(3, "Debate")
		report = analyze(central, editions)
		self.assertIn("NATIVE_REVERSE_LOOKUP_RISK", report["review"])
		self.assertNotIn("BOUNDED_VARIANT_SUPPORTED", report["observations"])


if __name__ == "__main__":
	unittest.main()
