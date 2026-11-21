# Wikidebates Flow: correctness and performance

Target: MediaWiki 1.43. Reviewed farm base: `8150dc732e4b354ba61d6002c3a67a27d67b0165`.

## Changes

The latest-revision SQL batch query now aggregates the `rev_id` column, not the literal string `'rev_id'`. Batching, revision types, ordering and storage formats are unchanged.

Permission decisions are reused only for the same User object and storage generation. Flow's existing collection-cache listener advances an in-process generation after inserts, updates, removals and clears. It is registered for post, summary, header and workflow storage before their other non-index listeners. Dependent permission and topic-list caches are discarded on the next read, including negative results. This is not a new persistent cache, database table, job or cross-request counter. Explicit `RevisionActionPermissions::clearCache()` is available for in-process rights changes on the same User object. A historical topic title cannot seed the current-root cache used to authorize replies.

Board workflow cache keys no longer perform the very board lookup they are intended to avoid. Summary lookups remember confirmed absence, deduplicate requested topics and do not record failures as absence. Successful summary creation invalidates negative entries through the storage listener. The topic query also keeps all sibling reply IDs instead of overwriting earlier siblings.

The REL1_43 security change `d2ac09ccb6e20b424a6f04b27aaab34098056f47` is backported to the category widget and opt-in tour. System messages are escaped before insertion into HTML. Notification wording and translations are untouched.

Browser topic registration is constant work per topic. Detached nodes are pruned once after the existing `makeContentInteractive` batch handler, not once per topic. A deterministic isolated test registers 200 topics with 200 membership checks rather than 20,100. This is an operation count, not a measured page-load speedup.

## Checks

Run from the farm root:

```sh
php extensions/Flow/tests/smoke/FlowCacheTest.php
node extensions/Flow/tests/smoke/flow-loadmore.test.js
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover \
	-s extensions/Flow/tests/smoke -p 'test_*.py' -v
```

The GitHub workflow runs PHP syntax checks and the standalone regressions on PHP 8.1 and 8.4, plus JavaScript syntax checks. The PHP smoke suite loads real Flow production classes with small MediaWiki boundary doubles. The SQL test executes the production aggregate expression against a real SQLite fixture; it does not bootstrap MediaWiki's database layer. The JavaScript test executes the production mixin with boundary doubles. Escaping guards protect the two upstream call sites.

These checks do not replace the full MediaWiki PHPUnit/QUnit suites, browser tests or testing against the farm database. Before deployment acceptance, exercise anonymous and logged-in reading, new topics, replies, locking/unlocking, hiding/deleting/restoring, summaries, board sorting and repeated loading of more topics. Check direct notification links and no-JavaScript reading too. No schema update or Composer update is required for this patch.

## Large-topic loading: measure before changing the interface

The proposed initial lightweight topic list is deliberately NOT enabled or implemented in this patch. The existing API, full rendering, direct post links and no-JavaScript behaviour are preserved. Implementing lazy discussion bodies needs a separate browser-tested change covering moderation, failures, retries and direct anchors. `toconly` is an existing minimal representation, not a drop-in replacement for a discussion board.

A bounded, anonymous, read-only helper compares full topic-list and TOC responses:

```sh
python3 extensions/Flow/scripts/benchmark-topiclist.py \
	--api https://fr.wikidebates.org/w/api.php \
	--page 'Discussion:Dieu existe-t-il ?' \
	--runs 3 \
	--limit 10
```

Use an existing Flow board title. Requests are sequential, spaced one second apart, with a maximum of ten requests and `maxlag=5`. The helper does not edit, authenticate, purge shared caches or change user preferences. It reports counts, response-body sizes and elapsed time without storing message content. Stop on API errors, HTTP errors and invalid responses. The sample API path must match the farm's actual `api.php` URL.

The first request is not necessarily cold. Times include network and HTTP latency, not just PHP or SQL. Compare the same boards, limits and user state before/after deployment. Collect browser performance traces and server-side SQL/CPU measurements separately before attributing time to the renderer or changing how bodies are loaded.

## Follow-up: in-place optimizations without changing initial topic rendering

This second batch deliberately keeps **all topic messages in the server-rendered
HTML and in the first API response**. It is unrelated to the rejected
lightweight-topic-preview proposal (PR #7, closed and never merged).

- `mw.flow.dm.System::fetchMoreTopics()` now counts returned ToC roots using
  the requested `tocPostsLimit` and releases its shared pending promise after
  **both success and failure**. Full-render callbacks no longer incorrectly
  overwrite the independent ToC continuation flag. `APIHandler::getTopicList()`
  accepts an explicit limit while retaining its previous defaults (50/10).
- `TopicListFormatter` temporarily indexes the current `PostRevision` objects
  already delivered by `TopicListQuery`. When `RevisionFormatter::buildActions()`
  looks up an ancestor for a reply action, it uses this batch first and falls
  back to `PostCollection::getLastRevision()` when unavailable. The index is
  cleared even if formatting throws, so it cannot leak between render batches.
- `WikiLinkFixer` now uses its injected MediaWiki 1.43 `LinkBatch` to preload
  existence information for the current message's wikilinks **once** before
  `LinkRenderer::makeLink()` calls. The batch is reset after execution; links
  created on later messages are still handled and red links are not hardcoded.
- `ContentFixer` skips `DOMDocument` when the original content is plain text
  with no HTML markup or entities. All actual HTML continues through the
  previous fixers, and no markup is removed or deferred.
- `TopicListBlock::renderTocApi()` reuses an **already complete** initial page
  for `updated` sorting when there is **no forward pagination link and no
  requested offset**. This avoids one duplicate first-page query for small
  boards. On larger boards it deliberately retains the original query:
  advancing solely by update timestamp can silently skip distinct topics with
  identical timestamps. A correct composite cursor or shared prefetched pager
  would need separate integration tests and profiling before deployment.
- `RevisionFormatter::serializeUserLinks()` clears its user-link cache when the
  viewer User object changes; viewer-dependent block links are never reused
  from a different permission context. The cache still applies within a
  request for the same viewer.

Run the new isolated checks (requires PHP's `dom` extension for the HTML test):

```sh
php extensions/Flow/tests/smoke/FlowFormatterOptimizationTest.php
php extensions/Flow/tests/smoke/FlowContentFixerTest.php
node extensions/Flow/tests/smoke/flow-pagination.test.js
```

GitHub Actions runs the smoke checks under PHP 8.1 and PHP 8.4. Isolated tests
are not proof of improved production latency. Measure before/after SQL queries,
`ContentFixer::apply()` CPU time, rendered HTML equivalence, ToC continuation,
link existence (including newly created pages) and reply actions at maximum
threading depth on the MediaWiki 1.43 farm. Neither DB migrations nor Composer
changes are required. Full browser and permission testing is still necessary.

## Follow-up: reused topic roots and stable last-updated cursors

The topic-list pager already loads each root's latest revision to determine
whether its topic is moderated. `TopicListQuery::getResults()` now accepts an
**optional, current-page-only** map of these revisions. It reuses them when
building the full reply tree and still fetches every uncached reply, performs
ordinary permission checks and accepts calls without the new parameter.

Sorting by last activity now has a stable tie-breaker: `workflow_last_update_timestamp`
followed by `topic_id` (descending). A continuation cursor is
`YYYYMMDDHHMMSS|<topic-alphadecimal-id>`. Historical timestamp-only offsets
remain accepted. Navigation, table-of-contents pagination, and jumping to a
topic all send both components. The SQL continuation uses a lexicographic
comparison and reverse queries use the opposite order before returning results
to the pager. Cursors beyond the first 500 entries go to the backing database
instead of exhausting the `TopKIndex` first-page cache. A versioned cache key
prevents reuse of stale timestamp-only index buckets. **No DB schema change.**

Standalone regression tests cover 660 synthetic topics, including 560 sharing
one timestamp, forward/reverse paging, a deep link with inclusive offset,
legacy timestamp offsets and rejected invalid input; root-reuse tests check
missing and wrong-page revisions, user permissions and cache invalidation.
The Node tests verify the outgoing API cursor and direct topic-jump parameters.
Run the bounded production renderer benchmark (requires an existing Flow board):

```sh
php maintenance/run.php Flow:FlowBenchmarkTopicList \
	--wiki=fr --page='Wikidébats:Bistro' --runs=3 --limit=10 --sortby=updated
```

Replace `Wikidébats:Bistro` with an actual Flow board title (not an article or
ordinary wikitext talk page). It reports wall time, process CPU time, peak
memory, and topic/post/revision counts in JSON. This read-only tool does not
warm a shared page cache on purpose, purge caches, log discussion content, or
count database queries; database profiling requires separate measurement.
The first sample may reflect a cold request and the later samples can benefit
from in-process caches. Test both sort modes, both wikis, and boards of varying
size before inferring any relative speedup.

A second, anonymous HTTP API benchmark compares full rendering and a table of
contents (not a proposed replacement for full rendering). It can now also
select the updated sort:

```sh
python3 extensions/Flow/scripts/benchmark-topiclist.py \
	--api https://fr.wikidebates.org/w/api.php \
	--page 'Wikidébats:Bistro' --runs 3 --limit 10 --sortby updated
```

The HTTP benchmark includes network latency, does not authenticate or modify
pages, and is limited to ten sequential requests with one-second intervals.
It does **not** benchmark HTML rendering in the browser or report SQL counts.
Full MediaWiki browser and SQL profiler tests still need to be run on the farm,
including hidden/deleted/locked topics, same-second updates and links to
specific comments. Do not infer production performance from isolated tests.

## Opt-in renderer phase timing (read-only)

The initial benchmarks of `Wikidébats:Bistro` showed approximately 49.5 ms
per warmed render for 69 posts, 70.7 ms for 112 posts, and 156.2 ms for 242
posts (three in-process samples per limit). These figures **are not** a
cross-process or before/after speedup measurement and do not identify the
source of CPU consumption.

The new `--breakdown` switch adds non-invasive stage timings to the
existing read-only maintenance command:

```sh
php maintenance/run.php Flow:FlowBenchmarkTopicList \
	--wiki=fr --page='Wikidébats:Bistro' \
	--runs=3 --limit=50 --sortby=updated --breakdown
```

The optional `phases_ms` field contains wall-clock times for:

- `prepare`: option processing and serializer setup
- `page_and_root_revisions`: pagination, including root moderation filter
  and root revisions
- `workflows`: batch loading of topic workflows
- `messages_and_permissions`: post tree, revisions, summaries, watches,
  metadata and view permissions
- `formatting`: API serialization, converted text, HTML and link handling

`other_ms` is the remaining wall-clock time not included in those stages
(including loop bookkeeping, JSON assembly and measurement rounding). The
timings exclude the block's `init()`, run in one PHP process, and include
any database work inside each stage. They are **not** SQL counts or HTML
browser render times. This feature adds no database queries, cache purge,
persistent state, API fields or public rendering changes. It only records
stages when explicitly enabled on the maintenance-created block.
