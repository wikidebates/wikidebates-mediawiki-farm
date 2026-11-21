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

An experimental **initial** lightweight topic list is implemented on the separate
`feature/flow-light-topiclist-20261121` branch. It is **disabled by default**; the
previous, full board rendering is unchanged until explicitly enabled. This is
not included in the first, already-merged Flow optimization batch.

To test on a staging wiki after deploying this second branch, set in the wiki's
configuration:

```php
$wgFlowLightTopicList = true;
```

Only ordinary server-rendered Flow discussion boards display minimal topic
titles, preserving the native `Topic:` link for script-free navigation. Root
view permissions are checked **before** inserting titles into server HTML or
`wgFlowData`. No child messages are loaded while generating the initial board;
the regular `view-topic` API loads one full thread when clicked, after which the
existing Flow event hooks render replies, editors and moderation controls.
The other API routes and topic pages continue to render normally. Subsequent
infinite-scroll pages still use the original, full topic list API. This is
an initial-load optimization, not a rewritten API or a cache.

Direct `#flow-post-...` links reopen the *full* board with `?flow_full=1`, because
URL fragments are not visible to PHP. The same query parameter is an immediate
manual fallback if the compact board behaves unexpectedly. On a failed or
incomplete `view-topic` request, the original Topic: link is restored. The source
Handlebars partial **and its generated PHP template** must both be deployed,
otherwise the server still renders the old full template. No database update
or Composer change is required for this second feature.

Isolated regression coverage for root visibility, compact data shape, browser
hydration, duplicate clicks, network/malformed response fallback and direct-link
fallback is included in GitHub CI. It does **not** replace full PHPUnit/QUnit,
actual browser accessibility testing, session tests or production SQL/CPU
measurements. Before enabling in production, check viewing (anonymous and
logged-in), sorting, pagination, links to replies, long threads, topic summaries,
new topics, editing, watching, moderation (including locks), restores, browser
back/forward, JavaScript disabled, keyboard navigation and localization.

This lightweight mode can reduce the first HTML and API-data payload at the
cost of an extra request when opening a topic; measure both outcomes. In
particular, removing discussion bodies from the initial HTML also changes what
search engines see on the board, so validate SEO expectations before activation.

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
