#!/usr/bin/env python3
"""Compare public Flow topic-list and TOC API responses, without editing or purging.

Timings include network latency. The first request is NOT guaranteed to be cold.
No message content, cookies or credentials are written to the report.
"""
import argparse
import gzip
import io
import json
import statistics
import sys
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urlsplit
from urllib.request import Request, urlopen

MAX_BYTES = 32 * 1024 * 1024


def fetch(api, page, limit, toconly):
	params = {
		'action': 'flow', 'submodule': 'view-topiclist', 'page': page,
		'format': 'json', 'formatversion': 2, 'vtllimit': limit,
		'vtlsortby': 'newest', 'vtlformat': 'fixed-html', 'maxlag': 5,
	}
	if toconly:
		params['vtltoconly'] = 1
	request = Request(api + '?' + urlencode(params), headers={
		'User-Agent': 'Wikidebates-Flow-Benchmark/1.0 (read-only; sequential)',
		'Accept-Encoding': 'gzip',
	})
	started = time.perf_counter()
	with urlopen(request, timeout=60) as response:
		body = response.read(MAX_BYTES + 1)
		if len(body) > MAX_BYTES:
			raise ValueError('API response exceeds the 32 MiB safety limit')
		elapsed = time.perf_counter() - started
		encoding = response.headers.get('Content-Encoding', '')
	wire_size = len(body)
	if encoding == 'gzip':
		with gzip.GzipFile(fileobj=io.BytesIO(body)) as compressed:
			body = compressed.read(MAX_BYTES + 1)
	if len(body) > MAX_BYTES:
		raise ValueError('Decoded response exceeds the 32 MiB safety limit')
	data = json.loads(body)
	if 'error' in data:
		raise ValueError('API error: ' + str(data['error'].get('code', 'unknown')))
	flow = data.get('flow', {})
	if flow.get('status') == 'error':
		raise ValueError('Flow returned an error; check the page and its content model')
	result = flow.get('view-topiclist', {}).get('result', {}).get('topiclist')
	if not isinstance(result, dict):
		raise ValueError('Response does not contain a Flow topic list')
	return {
		'mode': 'toc' if toconly else 'full',
		'elapsed_ms': round(elapsed * 1000, 2),
		'response_body_bytes': wire_size,
		'decoded_json_bytes': len(body),
		'topics': len(result.get('roots', [])),
		'posts': len(result.get('posts', {})),
		'revisions': len(result.get('revisions', {})),
	}


def main():
	parser = argparse.ArgumentParser(description=__doc__)
	parser.add_argument('--api', required=True, help='Absolute URL of api.php, without query parameters')
	parser.add_argument('--page', required=True, help='Existing Flow discussion board title')
	parser.add_argument('--runs', type=int, default=3, help='1 to 5 comparisons (default 3)')
	parser.add_argument('--limit', type=int, default=10, help='1 to 100 topics (default 10)')
	args = parser.parse_args()
	url = urlsplit(args.api)
	if url.scheme not in ('http', 'https') or not url.netloc or url.query or url.fragment or url.username or url.password:
		parser.error('--api must be an HTTP(S) URL without credentials, query or fragment')
	if not 1 <= args.runs <= 5 or not 1 <= args.limit <= 100:
		parser.error('--runs must be 1..5 and --limit must be 1..100')
	rows = []
	try:
		for run in range(args.runs):
			# Alternate mode ordering to reduce systematic warm-cache bias.
			for toconly in ((False, True) if run % 2 == 0 else (True, False)):
				if rows:
					time.sleep(1)
				row = fetch(args.api, args.page, args.limit, toconly)
				row['run'] = run + 1
				rows.append(row)
	except (HTTPError, URLError, TimeoutError, ValueError, OSError) as error:
		print(f'Benchmark aborted: {error}', file=sys.stderr)
		return 1
	medians = {
		mode: round(statistics.median(row['elapsed_ms'] for row in rows if row['mode'] == mode), 2)
		for mode in ('full', 'toc')
	}
	print(json.dumps({
		'page': args.page, 'limit': args.limit,
		'note': 'Anonymous, sequential requests. No cache purge. Includes network latency; not server CPU time. TOC is not a replacement for full rendering.',
		'median_ms': medians, 'samples': rows,
	}, indent='\t'))
	return 0


if __name__ == '__main__':
	sys.exit(main())
