"""Offline tests for the read-only measurement helper."""
import gzip
import importlib.util
import io
import json
from pathlib import Path
import unittest
from unittest.mock import patch

module_path = Path(__file__).resolve().parents[2] / 'scripts/benchmark-topiclist.py'
spec = importlib.util.spec_from_file_location('benchmark_topiclist', module_path)
benchmark = importlib.util.module_from_spec(spec)
spec.loader.exec_module(benchmark)


class Response(io.BytesIO):
	def __init__(self, body, encoding=''):
		super().__init__(body)
		self.headers = {'Content-Encoding': encoding}


class BenchmarkTest(unittest.TestCase):
	def test_response_and_read_only_parameters(self):
		body = json.dumps({'flow': {'view-topiclist': {'result': {'topiclist': {
			'roots': ['topic'], 'posts': {'topic': ['revision']}, 'revisions': {'revision': {}}
		}}}}}).encode()
		with patch.object(benchmark, 'urlopen', return_value=Response(body)) as request:
			result = benchmark.fetch('https://example.invalid/api.php', 'Talk:Test', 10, False)
		self.assertEqual(result['topics'], 1)
		self.assertEqual(result['response_body_bytes'], len(body))
		url = request.call_args.args[0].full_url
		self.assertIn('action=flow', url)
		self.assertIn('submodule=view-topiclist', url)
		self.assertNotIn('vtlsavesortby', url)
		self.assertNotIn('vtltoconly', url)
		self.assertEqual(request.call_args.args[0].get_method(), 'GET')

	def test_gzip_toc_response(self):
		body = json.dumps({'flow': {'view-topiclist': {'result': {'topiclist': {'roots': []}}}}}).encode()
		with patch.object(benchmark, 'urlopen', return_value=Response(gzip.compress(body), 'gzip')) as request:
			result = benchmark.fetch('https://example.invalid/api.php', 'Talk:Test', 10, True)
		self.assertEqual(result['mode'], 'toc')
		self.assertEqual(result['decoded_json_bytes'], len(body))
		self.assertIn('vtltoconly=1', request.call_args.args[0].full_url)

	def test_api_error(self):
		with patch.object(benchmark, 'urlopen', return_value=Response(b'{"error":{"code":"maxlag"}}')):
			with self.assertRaisesRegex(ValueError, 'maxlag'):
				benchmark.fetch('https://example.invalid/api.php', 'Talk:Test', 10, False)

	def test_unexpected_response(self):
		with patch.object(benchmark, 'urlopen', return_value=Response(b'{}')):
			with self.assertRaisesRegex(ValueError, 'does not contain'):
				benchmark.fetch('https://example.invalid/api.php', 'Talk:Test', 10, False)


if __name__ == '__main__':
	unittest.main()
