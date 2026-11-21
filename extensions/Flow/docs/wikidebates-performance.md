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


## Nested formatting measurements

The first real phase benchmarks of `Wikidébats:Bistro` confirmed that
formatting accounts for approximately 73% of the wall-clock time in warm
runs: 30.58 ms out of 41.93 ms for 69 messages, and 115.70 ms out of
156.86 ms for 242 messages. Fetching topic workflows and root revisions
takes a much smaller share. This does **not** demonstrate that all
formatting time is spent converting HTML.

With `--breakdown`, samples now also contain `format_detail_ms`:

- `revision_serialization`: time spent iterating and serializing post
  revisions, including content conversion, permission checks, generated
  actions and links, and recursive summary serialization
- `content_conversion`: accumulated time **inside** the
  `Templating::getContent()` calls made by the revision serializer,
  including summaries (a subset of `revision_serialization`)
- `topic_aggregation`: time spent linking replies and computing
  per-topic reply counts and last-updated metadata

All `format_detail_ms` values are nested in `phases_ms.formatting`;
`content_conversion` is also nested in `revision_serialization`.
**Do not sum these fields as if they were independent.** A difference
between revision serialization and content conversion is a useful
indicator of the cost of links/actions/users/permissions/other metadata,
but does not attribute it to one specific operation.

The same `--breakdown` command is used; no new option is needed.
When the maintenance timer is off, the formatters do not measure time.
The observer is removed after each instrumented render, including
exception paths. No content, revision, or private data is logged.

## Decomposed revision-serialization costs (opt-in)

A before/after comparison on the real Bistro board with 242 messages found
**no clear speedup** from the batch-scoped workflow edit check introduced in
PR #16: warm CPU time stayed close to 145 ms. The 2 warm wall-clock samples
are too few to establish a regression or improvement. Because this introduced
extra complexity around permission decisions without measurable benefit, that
specific edit-check cache was removed. Per-message permission checks remain
in place. Continue profiling before changing permissions further.

The existing `--breakdown` option now reports additional keys under
`format_detail_ms`, summed across all serialized revisions:

- `revision_view_permission`: the initial per-revision permission check
  performed by `RevisionFormatter::formatApi()`
- `moderated_revision_lookup`: lookup of the moderated/current revision
- `revision_links`: generation of read links via `buildLinks()`
- `revision_actions`: action permissions and write-link generation via
  `buildActions()`
- `user_serialization`: usernames, genders and user-page/contribution links,
  for all user roles serialized in the output
- `topic_title_params`: the two additional topic-title parameters, including
  plaintext and wikitext conversions and the title permission checks

These timers are nested within `revision_serialization`; do not add
them to its duration or to `phases_ms.formatting`.
`topic_title_params` contains additional content conversions **not counted**
in `content_conversion` (which covers the main `getContent()` call in
each formatted revision). Some permission checks also occur inside
`revision_actions`, `user_serialization`, and `topic_title_params`;
the categories describe code boundaries, not disjoint types of work.

The benchmark reports wall-clock time measured in the same PHP process,
not per-request browser latency or database query counts. Recording several
small operations adds measurement overhead, so do not compare subphase runs
directly to earlier uninstrumented baselines as a precise speedup estimate.
The callback is used only by the read-only maintenance helper and cleaned up
after each formatting batch. Regular Flow rendering does not create timers.

## Bounded last-revision cache: avoid warm-render thrashing

Real measurements on the French Bistro board with 50 topics / 242 posts
showed that `format_detail_ms.moderated_revision_lookup` jumped from
0.50 ms on the initial benchmark iteration to 51.80 and 54.13 ms on
the next two iterations, while the initial per-revision permission
checks dropped from 100.28 ms to about 0.4 ms. This is not evidence
of a change in moderation state: it is consistent with an LRU capacity
problem.

`CollectionCache::getLastRevisionFor()` uses a per-process
`MapCacheLRU`. Previously its capacity was only **50 entries**.
The topic-list serializer visits **242 different post collections**
in a single 50-topic render. As separate Flow permissions caches warm,
the latest revisions stop being prefetched by those permission checks,
and an ordered second pass can repeatedly evict and reload nearly
every last revision. This is an avoidable cost, not a reason to skip
the safety-critical `getModeratedRevision()` lookup.

Increase the bounded last-revision cache from 50 to **512 entries**.
The existing key remains per collection and revision type; model
insert/update/remove events still clear the entry and advance cache
generation, and explicit clears replace the entire LRU. There is no
shared cache or cross-request data, no new bypass of moderation checks,
and no schema, configuration, API or HTML change. The maximum number
of cached references increases; benchmark peak memory after deployment
to check the actual overhead in the target environment.

The old CLI smoke double for `MapCacheLRU` ignored its capacity,
so it could not detect this. It now simulates a bounded LRU.
A new test checks that 242 distinct last revisions remain cached
on a second full pass, confirms that insert/update/remove/clear
re-fetch current revisions, and verifies eviction beyond 512 entries.

Re-run the read-only benchmark on a real board:

```sh
php maintenance/run.php Flow:FlowBenchmarkTopicList \
	--wiki=fr --page='Wikidébats:Bistro' \
	--runs=3 --limit=50 --sortby=updated --breakdown
```

Compare the two warm `moderated_revision_lookup` times against
51.80 ms and 54.13 ms before the patch, as well as wall, CPU and peak
memory. This maintenance benchmark reuses process-local caches and
**does not directly prove** an equal improvement for independent HTTP
requests. Check real logged-in and anonymous page loads, and include
a moderation/restore test on the actual wiki before declaring a gain.

## Split HTML content retrieval from DOM processing (read-only)

After removing the edit-check cache and retaining the 512-entry
last-revision LRU, the measured French Bistro board (50 topics, 242 posts)
takes approximately 111.71 ms at warm render (two samples), including
47.52 ms for the main `Templating::getContent()` calls. Neither the
first run nor the two warm runs establish an HTTP-request baseline.

When `--breakdown` is enabled, `format_detail_ms` additionally contains:

- `content_source_html`: `AbstractRevision::getContent( 'html' )`,
  including any conversion, safety hooks or legacy head normalization
- `content_dom_parse`: `ContentFixer::createDOM()`, for content
  containing markup/entities
- `content_dom_fixers`: running all applicable DOM fixers, including
  MediaWiki LinkBatch work and red-link rendering
- `content_dom_serialize`: `Utils::getInnerHtml()`, including empty-node
  corrections and XML serialization

These four values are nested within `content_conversion`, **only** for
`fixed-html` paths where permissions permit access to the content.
They do not include the additional title conversions measured by
`topic_title_params`, which are separate operations.

New `format_detail_calls` provides event counts for each named
`format_detail_ms` stage. In particular, the difference between
`content_source_html` and `content_dom_parse` event counts helps
identify how often the existing plaintext fast path avoids parsing
(more precisely, only among successfully retrieved HTML inputs).
Never sum overlapping duration fields, and do not interpret event
counts as unique messages: summaries or repeated conversions can
trigger additional calls.

This profiling is activated **only** for the maintenance benchmark.
The normal rendering path preserves the same `ContentFixer::getContent()`
and `ContentFixer::apply()` behavior without timing callbacks.
Observers are cleared when the formatter's benchmark is over.
The profile does not log or persist message contents.

Use the existing command for warm in-process timings:

```sh
php maintenance/run.php Flow:FlowBenchmarkTopicList \
	--wiki=fr \
	--page='Wikidébats:Bistro' \
	--runs=3 --limit=50 --sortby=updated --breakdown
```

A separate-process sample, useful for comparison with the above
but still **not equivalent** to distinct browser requests, is:

```sh
for i in 1 2 3 4 5; do
	php maintenance/run.php Flow:FlowBenchmarkTopicList \
		--wiki=fr --page='Wikidébats:Bistro' \
		--runs=1 --limit=50 --sortby=updated --breakdown
done
```

Do not alter the DOM bypass conditions based on these timings alone:
the current conversion also handles red links, invalid images and
compatibility HTML normalization. Any future shortcut must prove
identical returned HTML, entity handling, moderation and links.

## Cold action-permission cost: targeted read-only measurements

On the French Bistro benchmark (50 topics, 242 posts), five independent
PHP processes each running a single `--runs=1 --breakdown` measurement
took an average of **559.97 ms wall-clock / 493.97 ms CPU**. The
`format_detail_ms.revision_actions` stage averaged **171.78 ms**, and
`revision_view_permission` averaged **62.39 ms**. These are server-side
maintenance process measurements, not actual HTTP TTFB.

The existing `--breakdown` mode now reports two timings **nested within**
`revision_actions`:

- `action_workflow_edit_permission`: checking
  `Workflow::userCan( 'edit', $user )` for each serialized revision
- `action_specific_permission`: combined time across individual
  `RevisionActionPermissions::isAllowed( $revision, $type )` checks inside
  the action-generation loop, after the workflow gate has passed

`format_detail_calls` gives the number of checks for each stage, not the
number of unique posts or authorized actions. The difference between
`revision_actions` and these two times includes actual link generation,
any further checks within individual actions, and measurement overhead.
These stages are **not additive** with `revision_actions`.

The logic intentionally still rechecks the workflow permission for every
post: a speculative batch-scoped permission cache introduced in PR #16 was
removed by PR #19 because its effect on overall render time was not
demonstrated. No permission results are cached by this instrumentation.
The non-benchmark path invokes the original permission methods directly;
the observer is installed only within the existing maintenance benchmark.

After deploying, obtain both the in-process (warm) profile and the
cross-process (cold) profile, for example:

```sh
php maintenance/run.php Flow:FlowBenchmarkTopicList \
	--wiki=fr --page='Wikidébats:Bistro' \
	--runs=3 --limit=50 --sortby=updated --breakdown

for i in 1 2 3 4 5; do
	php maintenance/run.php Flow:FlowBenchmarkTopicList \
		--wiki=fr --page='Wikidébats:Bistro' \
		--runs=1 --limit=50 --sortby=updated --breakdown
done
```

Only optimize a permissions path after its measured cost is isolated,
and retain the current moderation, user rights and invalidation behavior.

## Cold permission-decision cost, beyond the action-loop timer

The French Bistro (50 topics, 242 posts) gives **2,147**
`action_specific_permission` calls per complete render. Across five
independent PHP processes on the 2026-11-21 revision, the combined
time of these permission calls averaged **150.98 ms**, compared with
approximately **2.24 ms** for the second and third iterations within
one PHP process. The workflow edit gate was much less costly:
approximately **13.44 ms** cold and **6.54 ms** warm. This strongly
suggests that filling `RevisionActionPermissions::isAllowed()` caches
is a better investigation target than repeating the workflow-edit cache
from withdrawn PR #16. It does **not** establish that the user rights
or moderation checks can be safely skipped.

With `--breakdown`, the permission service now reports the following
additional events under `format_detail_ms` and `format_detail_calls`:

- `permission_cache_hit`: a **counting-only** event with 0.0 ms;
  it means a matching revision-ID, moderation-state and action key
  already had a cached decision
- `permission_cache_miss`: aggregate duration of actual
  `computeIsAllowed()` calls (including refused decisions)
- `permission_revision_rights`: first revision-level right check
- `permission_root_lookup` and `permission_root_rights`:
  locating the current topic root and checking applicable root rights
- `permission_board_lookup` and `permission_board_rights`:
  locating the board and checking its visibility
- `permission_latest_revision` and `permission_latest_revision_rights`:
  checking the current revision and, for historical revisions,
  its permissions
- `permission_write_flag_lookup`: retrieving whether a Flow action
  performs a write
- `permission_rule_lookup`: loading a moderation-specific rule
- `permission_rule_closure`: evaluating any dynamic rule closure
  (only emitted for closure-based rules)

All these measurements are **nested** inside a parent
`permission_cache_miss` or other measured permission work; some are
nested further inside `action_specific_permission`,
`revision_view_permission` or `content_conversion`.
Do not add these values to `revision_actions`, `formatting`, or the
total. Stage counts describe executed operations, not unique posts:
one `isAllowed()` call can perform several rights and rule checks,
while others return at an early denial.

These measurements only run when the maintenance benchmark installs
a timing observer. Outside `--breakdown`, the original permission
methods execute directly and no authorization decisions are newly
memoized. The permissions observer is removed with the formatter
observer after rendering. The more fine-grained timings themselves
have some overhead, especially with thousands of calls. Compare stages
**within** a benchmark run, not absolute times across instrumentation
versions. This profiling does not alter moderator rights, suppressions,
deleted content, historical checks, user restrictions or cache
invalidation.

To identify the dominant cold path, run five separate PHP processes:

```sh
for i in 1 2 3 4 5; do
	php maintenance/run.php Flow:FlowBenchmarkTopicList \
		--wiki=fr --page='Wikidébats:Bistro' \
		--runs=1 --limit=50 --sortby=updated --breakdown
done
```

The permission cache is per process and checks changes in the
collection generation, the viewer, and the revision moderation state.
Before considering any permission shortcut, verify those conditions
remain enforced and inspect the distribution of actual cold misses.

## Memoizing successful topic identities for permission cache keys

On Wikidébats:Bistro with 50 topics / 242 posts, five independent
PHP-process benchmarks after the cold permission breakdown reported:

- `permission_cache_miss`: about 244.87 ms total
- `permission_root_lookup`: about 97.47 ms across 1,278 calls
- `permission_board_lookup`: about 112.32 ms across 1,278 calls
- `permission_rule_lookup`: only about 3.91 ms

The root and board lookups are expensive **even when the cached root
revision and board workflow are already available**. The cache-key
constructor `getTopicCacheKey()` calls
`PostRevision::getCollection()->getWorkflowId()`. The collection
factory constructs a **new collection object each time**, so the
collection-local `rootId` memo cannot save the repeated tree lookup.
This happens for each distinct, initially uncached Flow action.

To address this specific duplication, `RevisionActionPermissions`
now remembers successful topic-key resolutions using
`revision type + collection ID` for the same permission service.
All normal root and board permission checks still take place.
The original keys and values of `rootRevisionCache`,
`boardWorkflowCache`, `rootAllowedCache` and
`boardAllowedCache` are unchanged. No individual action authorization
is cached differently. For post summaries, the existing direct
`summaryTargetId` key stays unchanged.

This memo is cleared with all existing permission caches whenever the
viewer changes, a Flow collection mutation increments its generation
or `clearCache()` is called. If looking up a topic fails with
`DataModelException`, **the original revision-ID fallback is kept**
and deliberately not memoized by collection ID, so historical
revisions never share an invalid fallback key.

The smoke suite covers repeated calls, different revision IDs of
one post, distinct collections, summary shortcuts, an updated tree
association, viewer changes, an explicit clear and missing-root
fallbacks. Existing tests check topic locks, hiding/deletion,
restoration, suppression and admin/reader isolation.

After deployment, measure the actual impact with both warm and cold
benchmarks. Although the **number** of root/board permission checks
may remain 1,278 each, their **duration** should fall if repeated
root-key lookups are the cause. No gain is assumed before comparing
production measurements.

The benchmark contains optional instrumentation overhead and does not
perfectly model anonymous and logged-in HTTP responses. Compare
un-instrumented `--breakdown`-free measurements and server-side
requests if available.


## Use the existing subtree membership to prime topic identities

After PR #25, five independent cold CLI runs on Wikidébats:Bistro
(50 topics, 242 posts) averaged 372.97 ms wall-clock and 341.77 ms CPU,
down from 620.18 ms / 545.55 ms. The memoized topic key cut the
`permission_root_lookup` stage from 97.47 to 29.64 ms and
`permission_board_lookup` from 112.32 to 12.93 ms, without changing
the 2,438 permission misses or 2,147 individual action checks.

The query already calls `TreeRepository::fetchSubtreeNodeList()` to
load the complete set of posts for a requested topic page. That result
also identifies which root topic each reply belongs to. Rather than
querying the tree again for the first permission check of **every post**,
`TopicListQuery` now preserves the post-to-root association from this
existing subtree result and primes the successful per-post topic-key
memo in `RevisionActionPermissions` before its normal view checks.

Only **non-title PostRevision objects** selected by the same query
may be primed. Unrequested roots and posts appearing under multiple
roots are excluded. On missing or ambiguous membership, other query
types, or historical callers, the original lookup and
`DataModelException` fallback remain in effect. A conflicting topic
key that was already resolved is not overwritten by priming.

This method does **not** prime permission decisions and it does not
skip `isAllowed()`, the current root revision, board checks or
moderation visibility. The memo remains per permission-service instance
and is cleared on viewer change or a Flow storage generation change.
The query's additional subtree-membership cache is cleared by the
same generation listener as its existing post-ID cache.

Standalone smoke tests check the ordinary permissions, invalidation,
topic titles, conflicting keys, two-topic subtree mapping, repeat
queries, reparenting after mutation and ambiguous membership.

Benchmark both profiled and unprofiled output after deployment:

```sh
for i in 1 2 3 4 5; do
	php maintenance/run.php Flow:FlowBenchmarkTopicList \
		--wiki=fr --page='Wikidébats:Bistro' \
		--runs=1 --limit=50 --sortby=updated --breakdown
done

for i in 1 2 3 4 5; do
	php maintenance/run.php Flow:FlowBenchmarkTopicList \
		--wiki=fr --page='Wikidébats:Bistro' \
		--runs=1 --limit=50 --sortby=updated
done
```

Compare the first set to the PR #25 baseline of 372.97 ms cold,
29.64 ms `permission_root_lookup` and 12.93 ms
`permission_board_lookup` (average of five processes). The
unprofiled wall/CPU measurements are the stronger performance signal:
timing thousands of permission calls can itself be expensive.
A production HTTP render and moderator/reply/locked-topic checks
remain necessary for a full real-world validation. Do not assume
any measurable speedup until these comparisons are performed.


## Breakdown of the topic query's message loading and permissions

Five independent cold CLI benchmark runs on Wikidébats:Bistro
(50 topics, 242 posts) after PR #27 averaged 358.49 ms wall and
330.49 ms CPU with `--breakdown`. The
`messages_and_permissions` phase averaged **116.45 ms**, down from
131.90 ms after PR #25; `formatting` remained stable at about
225.69 ms. With no `--breakdown`, the five new independent runs
averaged 328.56 ms wall / 305.20 ms CPU; there was no corresponding
unprofiled before-PR #27 baseline, so avoid claiming an exact
unprofiled speedup.

Before another cache or SQL optimization, the `TopicListQuery::getResults()`
process is now divided into nested read-only timings in a new
`query_detail_ms` field when `--breakdown` is enabled:

- `prepare_roots`: prepare requested IDs and reuse already fetched
  current root revisions for this page
- `collect_post_ids`: collect the full subtree of post IDs, including
  the post-to-root membership reuse introduced by PR #27
- `collect_revisions`: fetch remaining latest post revisions
- `load_metadata`: `AbstractQuery::loadMetadataBatch()`, including
  the batch tree, workflow, revision and metadata lookups
- `prime_topic_keys`: reuse the subtree membership in permission cache keys
- `prime_permissions`: prefetch root revisions and board workflows
  used by the existing view checks
- `filter_visible_topics`: check topic-title visibility before
  requesting topic summaries and watch status
- `collect_summaries`: fetch current visible topic summaries
- `collect_watch_status`: request watched-state metadata
- `build_visible_rows`: apply the original per-post visibility checks
  and build the resulting formatter rows
- `attach_replies`: populate reply lists from the visible rows

`query_detail_calls` counts observer events for these stages. Durations
are **nested within `phases_ms.messages_and_permissions`**. Do not
add them to the total or to the independent `format_detail_ms`
values, which describe formatting. A small portion of the parent
phase also includes work in `TopicListBlock` before the query
itself. Measurements include any SQL and request-cache effects.

The query observer is installed **only** for the read-only maintenance
benchmark and removed in a `finally` block, including on exceptions.
Normal web and API rendering make the same data and permission calls
with no timing observer. The smoke suite checks output preservation
and verifies that the measured query stages have nonnegative timings
and that measurements cease after disabling the observer.

After deployment, run:

```sh
for i in 1 2 3 4 5; do
	php maintenance/run.php Flow:FlowBenchmarkTopicList \
		--wiki=fr --page='Wikidébats:Bistro' \
		--runs=1 --limit=50 --sortby=updated --breakdown
done
```

Do not compare absolute timings with precision finer than the
variation between processes. This instrumentation is diagnostic,
not an optimization: identify the dominant stage before changing
tree, metadata, permission or summary loading.


## Distinguish view checks, row-building overhead and prewarm work

Five independent cold PHP processes after PR #29 (50 Bistro topics,
242 messages) averaged 367.75 ms wall-clock, 336.29 ms CPU and
120.04 ms in `messages_and_permissions`. Within that query phase:

- `build_visible_rows`: **45.85 ms**, including the per-post visibility
  check and `AbstractQuery::buildResult()` row construction
- `filter_visible_topics`: **24.31 ms** for filtering the 50 topic
  titles before fetching visible summaries
- `prime_permissions`: **23.99 ms** for prewarming the root and board
  data needed by later permission checks
- `load_metadata`: **11.87 ms** for metadata batch loading

The first three together account for approximately 78.4% of the query
phase, but do **not** establish how much time is actual rights evaluation
versus building the visible rows. To diagnose the distinction before
modifying any permission behavior, the read-only `--breakdown` mode
adds three nested `query_detail_ms` measurements:

- `visible_row_permissions`: total time spent in
  `RevisionActionPermissions::isAllowed( $post, 'view' )` inside
  `build_visible_rows`; the difference between the two stages
  includes row creation, metadata attachment and loop overhead
- `prime_root_revisions`: time spent retrieving cached or missing
  current root revisions inside `primeCacheForView()`
- `prime_board_workflows`: time spent retrieving cached or missing
  board workflows inside `primeCacheForView()`

The last two stages are nested inside `prime_permissions`. Each is
reported **once per query**, as an aggregated timing for all posts,
rather than as one event per post. `query_detail_calls` therefore
reports 1 for each aggregation, not 242 actual lookups. No new
`format_detail_ms` measurements are introduced, and previous
permission-count meanings remain unchanged.

This instrumentation never caches a new authorization decision, nor
skips the existing view check. The query-only observer is distinct from
the formatter's permission observer, and is cleared at query completion
(including errors). Outside the benchmark there are no observers and
the original operations run in the same order. In particular, do not
delete `primeCacheForView()` merely because the new timing is large:
without it, the cost may simply shift to a later permission check.

After deployment, run the standard independent cold benchmark:

```sh
for i in 1 2 3 4 5; do
	php maintenance/run.php Flow:FlowBenchmarkTopicList \
		--wiki=fr --page='Wikidébats:Bistro' \
		--runs=1 --limit=50 --sortby=updated --breakdown
done
```

The timings include SQL and caches. Do not add nested phases to their
parents, and compare with the same instrumented version when making
small relative timing claims. The PR is a diagnostic step, not a
performance improvement in itself.


## A/B conclusion: retain eager permission prewarming (PR #31)

Six alternating pairs of **independent PHP processes** compared the
original eager `primeCacheForView( $posts )` with the maintenance-only
lazy variant, for the same 50-topic / 242-post Bistro page. Neither
variant used `--breakdown`.

| Measure | Eager | Lazy |
| --- | ---: | ---: |
| Mean wall time | 341.01 ms | 341.25 ms |
| Mean CPU time | 312.25 ms | 312.60 ms |
| Median wall time | 346.31 ms | 341.26 ms |
| Faster pairwise runs | 3 / 6 | 3 / 6 |

The lazy variant averaged **0.24 ms slower** (0.07%) in wall time,
and **0.35 ms slower** (0.11%) in CPU time, far below the observed
variation across runs. The paired wall-time differences (lazy minus
eager) were -4.81, +0.62, +11.61, -1.23, +1.17 and -5.93 ms. The
benchmark's structural SHA-256 digest and counts of 50 roots,
242 posts and 242 revisions were identical for all twelve runs.

**Conclusion:** removing eager prewarm did not provide a measurable
gain. Its apparent ~23 ms exclusive cost was deferred to the later
permission checks rather than eliminated. The site retains the
original eager prewarming, current-root and board permission checks,
moderation checks and existing cache invalidation. The temporary
`--skip-permission-prewarm` flag and its associated opt-in
skip code were removed after the experiment. Do not use this now
removed flag in subsequent benchmarks.

The `result_structure_sha256` sample field remains available for
future regression comparisons; it fingerprints visible IDs and
relationships, not the entire API payload and not authorization
equivalence. The remaining baseline for **unprofiled** cold
rendering is approximately 341 ms wall / 312 ms CPU under the
conditions of this paired experiment. These figures are from
maintenance CLI, not real request latency.

With that possibility ruled out, investigate the approximately
75–80 ms spent in `format_detail_ms.content_conversion`, including
`content_source_html`, DOM fixers, and serialization, before
introducing any more permission cache changes. Maintain HTML safety
and compare equivalent returned content, not just row counts.


## Read-only A/B: reuse the parsed Parsoid HTML DOM

Following PR #31, the default eager permission prewarm remains enabled.
Our benchmark attributes about 75–80 ms to HTML content conversion for
the 242-post Bistro list, including 192 HTML bodies.

For replies stored as HTML, `AbstractRevision::getContent( 'html' )`
normally invokes `Utils::decodeHeadInfo()`, which parses the stored
Parsoid HTML, normalizes empty elements and serializes a body with
restored head/base metadata. Immediately afterwards,
`ContentFixer::apply()` parses the resulting HTML **again** before
running WikiLink, bad-image, base-href and external-link fixers.

The new **maintenance-only** `--reuse-html-dom` switch compares
reusing the first parsed DOM to the original decode-then-reparse path.
It still calls `AbstractRevision::getContent( 'html' )` *first*,
retaining the original content decompression, XSS hook and denial,
legacy stubs, conversion rules and cached-format handling. All
`Templating` moderation checks, fixers and final XML serialization
are retained. If the decoded DOM is unavailable (already converted
content, a custom revision, etc.) the original parsing path is used.
DOM ownership transfers once and is cleared immediately, preventing
cross-request or cross-user mutation.

**This experiment began as maintenance-only.** Following real-wiki
A/B tests and opt-in production operation, single-pass rendering is
now enabled by default via `FlowReuseDecodedDom`. Reusing a DOM rather
than reparsing serialized HTML can produce a different tree on malformed
historical content; keep the explicit legacy fallback and compare
byte-for-byte output if any regression is suspected.

Every sample now includes `result_content_sha256`, an ordered hash
of every revision's exact HTML and content format, and the existing
`result_structure_sha256` for its IDs and relationships. Both
must match between variants, but even matching hashes do not prove
authorization or complete API equivalence. The output also labels
`html_dom_reuse` as `enabled` or `disabled`.

Run alternating **independent PHP processes**, six per variant,
without detailed instrumentation first:

```sh
php maintenance/run.php Flow:FlowBenchmarkTopicList \
	--wiki=fr --page='Wikidébats:Bistro' \
	--runs=1 --limit=50 --sortby=updated

php maintenance/run.php Flow:FlowBenchmarkTopicList \
	--wiki=fr --page='Wikidébats:Bistro' \
	--runs=1 --limit=50 --sortby=updated \
	--reuse-html-dom
```

Repeat with `--breakdown` to attribute any improvement.
In the reuse variant, `format_detail_calls.content_reused_dom`
counts successful single-pass renders, and `content_dom_parse`
counts fallback parses. `content_source_html` includes the first
parse, and the others remain nested inside `content_conversion`.
The per-operation measurements must not be added to the total.

The smoke suite compares byte-identical HTML for ordinary Parsoid,
WikiLinks, blocked images, relative/external links, body-less legacy
content, empty elements, cached HTML, overriding subclasses and XSS
denial. These synthetic cases cannot replace the real-wiki
comparison. If canonical per-revision content **differs**, restore the
legacy renderer on the affected wiki until the discrepancy is understood.
Do not confuse insertion-order-only differences with HTML changes.

### Compare older topics on the same board

The benchmark can follow the existing `newest`-sort paging contract to
sample messages outside the first 50 topics, without changing the
public renderer or expanding the request limit. After running the
first page with `--sortby=newest`, copy the resulting
`samples[0].last_root_id` to a new invocation as `--offset-id`.
Pass the same cursor to **both** A/B variants and compare their
`result_structure_sha256` and `result_content_sha256`.

```sh
php maintenance/run.php Flow:FlowBenchmarkTopicList \
	--wiki=fr --page='Wikidébats:Bistro' \
	--runs=1 --limit=50 --sortby=newest \
	--offset-id='ROOT_ID_FROM_PREVIOUS_PAGE'

php maintenance/run.php Flow:FlowBenchmarkTopicList \
	--wiki=fr --page='Wikidébats:Bistro' \
	--runs=1 --limit=50 --sortby=newest \
	--offset-id='ROOT_ID_FROM_PREVIOUS_PAGE' --reuse-html-dom
```

`--offset-id` is rejected for `--sortby=updated`, which uses a different,
composite paging cursor. The option and last-root metadata affect only
the maintenance benchmark. Stop at an empty page or if the next cursor
does not advance. Do not infer equivalence of full API responses from
these two hashes alone.


### Diagnose intermittent A/B fingerprint mismatches (maintenance only)

The original two A/B hashes are sensitive to associative insertion order:
posts or revision IDs returned in a different order can change both
digests even when the same revisions contain the same HTML. A one-off
mismatch is not automatically proof of a DOM regression.

Enable `--diagnose-digests` for a **targeted, read-only** comparison of
the same board, sort mode, and cursor in independent PHP processes.
The diagnostic is generated **after** timing and is disabled by default.

```sh
php maintenance/run.php Flow:FlowBenchmarkTopicList \
	--wiki=fr --page='Discussion:Le cannabis doit-il être légalisé ?' \
	--sortby=newest --limit=50 --runs=1 --diagnose-digests

php maintenance/run.php Flow:FlowBenchmarkTopicList \
	--wiki=fr --page='Discussion:Le cannabis doit-il être légalisé ?' \
	--sortby=newest --limit=50 --runs=1 --diagnose-digests --reuse-html-dom
```

Each sample's `diagnostic` includes hashes for ordered and sorted root
IDs, ordered and sorted post mappings, ordered and sorted revision IDs,
and content sorted by revision ID. The canonical content hash is
independent of revision-map insertion order, but retains every revision's
exact content/format object. The diagnostic additionally provides root
IDs, post-to-revision IDs and one HMAC-SHA256 per revision to pinpoint
content differences without printing raw message HTML, plaintext, or
the private HMAC key. HMACs use the configured site `SecretKey`, must
not be compared between wikis, and will refuse to run without a strong
configured key.

If only ordered hashes differ while sorted hashes match, investigate
unstable ordering rather than a change of rendered content. If the
canonical content digest changes, compare `content_hmac_by_revision_id`
to identify the exact revision IDs; turn off the site-level optimization
if a genuine content regression is discovered. Also repeat **original
vs original** to separate renderer differences from naturally unstable
data. The diagnostic remains maintenance-only and does not alter
normal web/API rendering.

### Single-pass Flow HTML rendering (enabled by default)

`FlowReuseDecodedDom` defaults to **true** in the Flow extension itself.
Its runtime logic is unchanged: the optimized renderer is used for ordinary
`fixed-html` content on each wiki without a `LocalSettings.php` override.
The existing A/B comparisons cover the French Bistro and selected French
and English boards; the measured gains are modest and individual results
can vary due to database/cache conditions.

If `LocalSettings.php` currently sets `$wgFlowReuseDecodedDom = true;`,
remove that redundant override after deploying this new default. For
an emergency rollback on one or all wikis, explicitly set it to false:

```php
$wgFlowReuseDecodedDom = false;
```

The flag is read when the FlowTemplating service is constructed, so it
applies to the wiki's normal `fixed-html` rendering in web and API
contexts. It does not change wikitext or topic-title conversion.
All existing content/permission checks, the Flow HTML XSS hook,
moderation and DOM fixers still execute. The optimized path falls
back to original parsing if no decoded DOM is available.

Rollback: set the flag to false in the wiki configuration, or revert
the extension default in the repository; removing the flag override now
**enables**, rather than disables, the optimized rendering path.
Restart/reload PHP workers as required by the deployment's
configuration/OPcache setup. Verify the live site and API after
configuration changes; do not assume cached HTML has been refreshed.
Production was already enabled by explicit configuration during the A/B
rollout; this change only makes that behavior the extension default.

The read-only maintenance benchmark continues to compare both
alternatives independently of the site flag. Its temporary override
is always cleared in a `finally` block, including on exceptions.

The subtree query in `TreeRepository::fetchSubtreeNodeListFromDb()`
does not specify an `ORDER BY`. The original rendering can therefore
return equivalent content in different associative iteration orders.
Do not confuse the benchmark's order-sensitive digests with a proven
HTML difference. Inspect the sorted hashes, per-revision HMACs and
root sets when comparing A/B output. Stabilizing visible reply
ordering is a separate functional change that requires explicit tests.

## Read-only profiling: content root-visibility checks

First renders of the French Bistro (50 topics, 242 posts) showed 2,438
initial permission-cache misses, followed by zero misses on the next
render in the same PHP process. Since the caches work as designed,
do not bypass view/root/moderation checks or extend permission decisions
across viewers or requests merely to optimize the first render.

To isolate one remaining cost inside `content_conversion`, the existing
`--breakdown` option now measures two events for non-title posts:

- `format_detail_ms.content_root_lookup`: time spent calling the existing
  `PostRevision::getRootPost()` from `Templating::getContent()`.
- `format_detail_ms.content_root_view_permission`: time spent checking
  that root with `RevisionActionPermissions::isAllowed( $root, 'view' )`.

The corresponding `format_detail_calls` count invocations. These two
timings are nested in `content_conversion`, and are not additive to it.
Ordinary web/API requests remain uninstrumented and invoke the same code.
No permissions, cache policy, moderation checks, HTML rendering, or stored
data change. Profiling these extra operations incurs measurement overhead;
compare results from the same instrumentation version.

Run the already-deployed read-only maintenance benchmark with
`--reuse-html-dom --breakdown --runs=3` and inspect the new keys in each
sample. Compare first and subsequent runs before proposing an optimization.

## Per-fixer HTML diagnostics (read-only)

After verifying that content root lookups take about 0.001 ms and current-root
view checks only about 0.2 ms across 192 replies, leave the permission path
unchanged. The next target is `format_detail_ms.content_dom_fixers`, which
costs about 18.5 ms in the cold sample and 13.8 ms on warm rerenders.

The existing `--breakdown` benchmark now includes the following nested
`format_detail_ms` stages for each HTML fixer:

- `content_fixer_wikilinkfixer_xpath`, `_preload`, `_apply`
- `content_fixer_badimageremover_xpath`, `_apply`
- `content_fixer_basehreffixer_xpath`, `_apply`
- `content_fixer_extlinkfixer_xpath`, `_apply`

`_xpath` includes evaluating the fixer's XPath; `_preload` includes the
existing MediaWiki LinkBatch for WikiLinks; `_apply` includes the loop over
matching nodes, which may include nested HTML processing.

All these events are nested under `content_dom_fixers` and are **not**
independent costs that should be added to it. The corresponding
`format_detail_calls` count fixer invocations per HTML document, **not**
the number of matched nodes or database queries. Results are aggregated
across the whole topic list and may include profiling overhead.

The normal web/API path does not install this observer, does not generate
timings and retains the same XPath queries, fixer order, link batching,
image checks and serializations. All changes are purely diagnostic.

Run `Flow:FlowBenchmarkTopicList --wiki=fr --page='Wikidébats:Bistro'`
with `--runs=3 --limit=50 --sortby=updated --reuse-html-dom --breakdown`.
Compare cold and warm runs and decide whether **any single fixer** merits
a follow-up optimization; do not skip security checks or change the
returned HTML based on profiling alone.

## A/B result: WikiLink title reuse rejected (2026-11-21)

The maintenance-only `--reuse-wikilink-titles` experiment in PR #46
reused `Title` objects that `WikiLinkFixer::preload()` had already
resolved, instead of resolving the same href again during `apply()`.
Six alternated independent PHP processes per variant rendered the
same 50 French Bistro topics and 242 posts with `--reuse-html-dom`.
Exact canonical content, post associations and root sets remained
identical across all twelve runs.

| Measure (median of 6) | Original | Target reuse |
| --- | ---: | ---: |
| Wall time | 331.545 ms | 333.115 ms |
| CPU time | 300.40 ms | 304.43 ms |

The experiment did **not** demonstrate a performance benefit (wall
+1.57 ms; CPU +4.03 ms, both within plausible noise), so its extra
memo, maintenance option, and A/B-only tests were removed. Original
`LinkBatch` batching and link transformation continue unchanged.
The separately confirmed default HTML DOM reuse is retained.
Do not reintroduce the Title memo without a new specific bottleneck
and repeatable overall performance evidence.

## Read-only per-link HTML fragment timing

The HTML-fixer breakdown for Wikidébats:Bistro (50 topics, 242 posts,
192 fixed HTML contents) found that WikiLink preloading and replacement
were the largest individual fixer stages: ~10.66 ms preload and 9.18 ms
apply on the first render; ~2.93 ms preload and 5.36 ms apply on later
renders. The attempted memo of preloaded Title objects in PR #46 was
removed because its A/B comparison showed no whole-render improvement.

Before considering a different change, measure the per-link HTML fragment
parse already performed by `WikiLinkFixer::apply()` when it turns
`LinkRenderer::makeLink()` output into a DOM node.

The optional `--breakdown` observer emits the following counters and
timings (all under `format_detail_`):

- `content_wikilink_candidate`: counting-only (0 ms) for each
  XPath-selected DOMElement handed to WikiLinkFixer, even if the
  link is skipped because it has an empty or unresolvable href.
- `content_wikilink_render_html`: time spent generating the link
  HTML with LinkRenderer and serializing the link label.
- `content_wikilink_parse_fragment`: time spent parsing that generated
  HTML into a temporary DOM and selecting its `<a>` node.
- `content_wikilink_insert_node`: import and replacement of the node
  inside the original document.
- `content_wikilink_transformed`: counting-only (0 ms), emitted only
  after a link has actually been replaced.

`format_detail_calls` gives the number of candidate and transformed
links or timed operations. These stages nest within
`content_fixer_wikilinkfixer_apply`, which itself nests in
`content_dom_fixers`; do not add them to their parent duration.
No link text or target URLs are reported. The per-link profiler is
disabled in normal requests and cleared with the parent observer.
Every existing resolver, XSS check, LinkBatch operation, rendered
fragment parse, DOM insertion and permission check remains unchanged.

Run the existing read-only benchmark with `--reuse-html-dom --breakdown`
and inspect the new values in the first and subsequent renders before
deciding whether HTML-fragment reparsing is worth a separate A/B trial.

## Cold-request diagnosis: topic visibility and action-link construction

The November 21 French Bistro cold-run profile for 50 topics and 242
messages showed roughly 118 ms inside `messages_and_permissions` and
219 ms inside `formatting`. The initial permission cache correctly
served 585 hits and computed 2,438 misses, while subsequent in-process
renders reused 3,023 decisions without misses. Do not bypass or share
viewer-specific permissions to speed up cold requests.

Two remaining ambiguous costs are now measured with the existing
read-only `--breakdown` observer:

- `query_detail_ms.visible_topic_permissions`: the summed time for
  `RevisionActionPermissions::isAllowed( $topicTitle, 'view' )`
  inside `filter_visible_topics` (which previously took ~24 ms cold).
  This is aggregated into a single observer event per query.
- `format_detail_ms.action_allowed_branch`: summed time spent in the
  action-type switch *after* the original permission check, including
  action-specific extra checks and any URL construction. This is
  a broad branch cost, not pure URL-generation time. The corresponding
  call count is the number of granted action branches processed.
- `format_detail_calls.action_output_link`: count of write links in
  the final action arrays (one 0-ms event per output link).
- `format_detail_calls.read_output_link`: count of read links in the
  final read-link arrays (one 0-ms event per output link).

`visible_topic_permissions` is nested within `filter_visible_topics`;
`action_allowed_branch` is nested within `revision_actions`. The
existing `revision_links` measures overall read-link construction.
Do not add nested times to their parents. Zero-ms counters are not
elapsed durations, and instrumentation introduces timing overhead.

No visibility predicate, moderation behavior, per-action permission,
URL-generation switch, LinkBatch step, or output value is changed.
Ordinary web/API rendering installs no observer and does not emit
these measurements. All branch and link timers are removed when the
benchmark observer is disabled; the smoke suite covers permitted
and denied actions and exact URL parity.

After deploying, run `Flow:FlowBenchmarkTopicList --wiki=fr` with
`--page='Wikidébats:Bistro' --runs=3 --limit=50 --sortby=updated`
`--reuse-html-dom --breakdown`. Compare the new subphases at cold
and warm passes, and use an independent-process A/B experiment
before retaining any future functional performance change.

## A/B experiment: duplicate current-revision metadata retrieval

First renders of the French Bistro (50 topics, 242 posts) spent
11.80 ms in `load_metadata`, versus ~7.76 ms on subsequent in-process
renders. The topic query already calls `collectRevisions()` with
`findMulti( 'PostRevision', ..., sort rev_id DESC, limit 1 )` to load
each post's latest revision. Next, `AbstractQuery::loadMetadataBatch()`
unconditionally loads latest revisions for every collection again.

`TopicListQuery::getCurrentRevision()` returns the provided revision
unchanged because its inputs are already latest revisions. This makes
the second current-revision retrieval a plausible avoidable cost.
Other metadata loading (root identity maps, workflows, previous
revisions and summaries) is unaffected by this experiment.

The new **maintenance-only** `--skip-current-revision-refetch` flag
skips only the `AbstractQuery` current-revision preload stage for
`TopicListQuery`. All other query subclasses retain the original
behavior. The flag is false by default, passed through the benchmark
block, and cleared on the shared query service in a `finally` block.
No production configuration or rendered API/HTML output changes.

The experiment's JSON result reports
`current_revision_refetch_skipped: enabled|disabled`. Test the same
50 topics with 12 independent `--runs=1 --reuse-html-dom` processes
alternating this flag. Confirm exact
`diagnostic.content_sorted_by_revision_id_sha256`,
`diagnostic.posts_sorted_by_id_sha256`, and
`diagnostic.roots_sorted_sha256` parity, plus 242 posts, before
interpreting the time difference. Collect medians without
`--breakdown` for the primary A/B, and optionally use the breakdown
to isolate whether `load_metadata` falls. Do **not** activate for
regular traffic before confirming semantic parity, tests and
a reproducible whole-render benefit.
