"""Offline tests of the actual checker with isolated command doubles."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

CHECK = Path(__file__).resolve().parents[2] / "scripts/luasandbox/wikidebates-luasandbox-check"
MOCK = r"""#!/usr/bin/python3
import json
import os
from pathlib import Path
import sys

statefile = Path(os.environ["TEST_STATE"])
state = json.loads(statefile.read_text())
name = Path(sys.argv[0]).name
args = sys.argv[1:]
with open(os.environ["TEST_LOG"], "a") as log:
	log.write(json.dumps([name, *args]) + "\n")

def save():
	statefile.write_text(json.dumps(state))
	maps = Path(os.environ["TEST_MAPS"])
	maps.write_text("/usr/lib/php/20240924/luasandbox.so\n" if state["live"] else "")

if name == "php8.4":
	sys.exit(0 if (state["version"] if "PHP_MAJOR_VERSION" in args[-1] else state["cli"]) else 1)
if name == "php-fpm8.4":
	if args == ["-m"]:
		print("luasandbox" if state["fpm"] else "json")
		sys.exit(0)
	sys.exit(0 if state["config"] else 1)
if name == "dpkg-query":
	if state["package"]:
		print("install ok installed", end="")
		sys.exit(0)
	sys.exit(1)
if name == "apt-get":
	if state["apt_fail"]:
		sys.exit(1)
	state.update(package=True, cli=True, fpm=True)
	save()
elif name == "phpenmod":
	if not state["enable_fail"]:
		state["cli" if "cli" in args else "fpm"] = True
		save()
	else:
		sys.exit(1)
elif name == "systemctl":
	if args[0] == "is-active":
		sys.exit(0 if state["active"] else 1)
	if args[0] == "show":
		print("123")
	elif args[0] == "reload":
		if state["reload_fail"]:
			sys.exit(1)
		if not state["reload_ineffective"]:
			state["live"] = True
			save()
sys.exit(0)
"""


class CheckTest(unittest.TestCase):
	def setUp(self):
		self.temp = tempfile.TemporaryDirectory()
		self.addCleanup(self.temp.cleanup)
		self.root = Path(self.temp.name)
		self.bin = self.root / "bin"
		self.bin.mkdir()
		for name in ("php8.4", "php-fpm8.4", "dpkg-query", "apt-get", "apt-mark",
			"phpenmod", "systemctl", "logger", "sleep"):
			path = self.bin / name
			path.write_text(MOCK)
			path.chmod(0o755)
		self.ini = self.root / "luasandbox.ini"
		self.ini.touch()
		self.maps = self.root / "maps"
		self.state = self.root / "state.json"
		self.log = self.root / "calls.jsonl"
		self.lock = self.root / "lock"
		source = CHECK.read_text()
		# Modify only the ephemeral test copy, never the installed script.
		replacements = {
			"export PATH=/usr/sbin:/usr/bin:/sbin:/bin": f'export PATH="{self.bin}:/usr/bin:/bin"',
			"PHP=/usr/bin/php8.4": f'PHP="{self.bin}/php8.4"',
			"FPM=/usr/sbin/php-fpm8.4": f'FPM="{self.bin}/php-fpm8.4"',
			"/run/wikidebates-luasandbox.lock": str(self.lock),
			"/etc/php/8.4/mods-available/luasandbox.ini": str(self.ini),
			'"/proc/$pid/maps"': f'"{self.maps}"',
			'(( EUID == 0 )) || fail "Executer en root"': ":",
		}
		for old, new in replacements.items():
			self.assertEqual(source.count(old), 1, old)
			source = source.replace(old, new)
		self.script = self.root / "check"
		self.script.write_text(source)

	def run_check(self, **changes):
		state = dict(package=True, cli=True, fpm=True, live=True, active=True,
			version=True, config=True, apt_fail=False, enable_fail=False,
			reload_fail=False, reload_ineffective=False)
		state.update(changes)
		self.state.write_text(json.dumps(state))
		self.maps.write_text("/usr/lib/php/20240924/luasandbox.so\n" if state["live"] else "")
		self.log.write_text("")
		env = dict(os.environ, TEST_STATE=str(self.state), TEST_LOG=str(self.log),
			TEST_MAPS=str(self.maps))
		result = subprocess.run(["bash", str(self.script)], env=env,
			capture_output=True, text=True, timeout=20)
		calls = [json.loads(line) for line in self.log.read_text().splitlines()]
		return result, calls

	def assert_success(self, result):
		self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

	def mutations(self, calls):
		return [c for c in calls if c[0] in ("apt-get", "apt-mark", "phpenmod")
			or c[:2] == ["systemctl", "reload"]]

	def test_healthy_is_read_only(self):
		result, calls = self.run_check()
		self.assert_success(result)
		self.assertEqual(self.mutations(calls), [])

	def test_missing_package_installs_and_reloads(self):
		result, calls = self.run_check(package=False, cli=False, fpm=False, live=False)
		self.assert_success(result)
		apt = [c for c in calls if c[0] == "apt-get"]
		self.assertEqual(len(apt), 1)
		self.assertIn("--no-remove", apt[0])
		self.assertNotIn("--reinstall", apt[0])
		self.assertEqual(apt[0][-2:], ["install", "php-luasandbox"])
		self.assertIn(["apt-mark", "manual", "php-luasandbox"], calls)
		self.assertIn(["systemctl", "reload", "php8.4-fpm.service"], calls)

	def test_disabled_module_only_reenables(self):
		result, calls = self.run_check(cli=False, fpm=False)
		self.assert_success(result)
		self.assertEqual([c for c in self.mutations(calls) if c[0] != "phpenmod"], [])
		self.assertEqual(sum(c[0] == "phpenmod" for c in calls), 2)

	def test_missing_ini_reinstalls(self):
		self.ini.unlink()
		result, calls = self.run_check(cli=False, fpm=False)
		self.assert_success(result)
		apt = [c for c in calls if c[0] == "apt-get"]
		self.assertEqual(len(apt), 1)
		self.assertIn("--reinstall", apt[0])
		self.assertIn("Dpkg::Options::=--force-confmiss", apt[0])

	def test_failed_apt_exits_without_reload(self):
		result, calls = self.run_check(package=False, apt_fail=True)
		self.assertNotEqual(result.returncode, 0)
		self.assertFalse(any(c[:2] == ["systemctl", "reload"] for c in calls))

	def test_bad_fpm_configuration_does_not_reload(self):
		result, calls = self.run_check(live=False, config=False)
		self.assertNotEqual(result.returncode, 0)
		self.assertFalse(any(c[:2] == ["systemctl", "reload"] for c in calls))

	def test_ineffective_reload_is_failure(self):
		result, calls = self.run_check(live=False, reload_ineffective=True)
		self.assertNotEqual(result.returncode, 0)
		self.assertEqual(sum(c[:2] == ["systemctl", "reload"] for c in calls), 1)

	def test_inactive_fpm_not_started(self):
		result, calls = self.run_check(active=False, live=False)
		self.assertNotEqual(result.returncode, 0)
		self.assertEqual(self.mutations(calls), [])

	def test_wrong_php_no_repairs(self):
		result, calls = self.run_check(version=False, package=False)
		self.assertNotEqual(result.returncode, 0)
		self.assertEqual(self.mutations(calls), [])


if __name__ == "__main__":
	unittest.main()
