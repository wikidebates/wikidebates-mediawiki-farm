# Regression checks

Run these commands from the MediaWiki checkout root with its development
dependencies installed and PageFormsWikidebates loaded in a development wiki.

```sh
node_modules/.bin/jest --config extensions/PageFormsWikidebates/tests/jest/jest.config.js --runInBand
vendor/bin/phpunit --filter 'PF(Values|Mapping)UtilsRegressionTest' extensions/PageFormsWikidebates/tests/phpunit
vendor/bin/phpunit extensions/PageFormsWikidebates/tests/phpunit
```

## Progressive editing and local recovery

Run the Jest command above. The added suites exercise actual lazy/dependency,
error-navigation, instance-undo, edit-state and draft modules. Network responses,
revision checks and editor targets are controlled; they do not contact production.
Existing Select2 composition tests are retained. Additional token/combobox tests
keep real Select2 instances and focused composing inputs alive across refreshes.

A real Chromium smoke test can be run with Python Playwright 1.57.0 and a local
Chromium binary (override `CHROMIUM` as needed):

```sh
python extensions/PageFormsWikidebates/tests/browser/editing-smoke.py
```

It injects the tracked JavaScript into an empty local browser document, without
an HTTP server. MediaWiki services are simulated. It tests visibility-based
initialization, offscreen values, real focus, error correction, undo, synthetic
composition events and native submission. It reproduced the ignored native
submission before the new-task replay fix. This is not an operating-system
keyboard/IME test or a live MediaWiki browser test.

The `pageforms-integration-checks.yml` workflow bootstraps a disposable MediaWiki
1.43 / PHP 8.4 SQLite wiki and runs the new category/ranking/tree tests plus the
existing focused mapping/category regression tests. It does not change a deployed
wiki, its database, configuration, Composer lockfile or submodules.

### Configuration and intentionally bounded behavior

`$wgPageFormsCategoryTraversalLimit` defaults to 2000 discovered categories.
Discovery and page matches are separate; SQL category lists are batched in groups
of 50. Results are ranked among bounded candidates, not an exhaustive search of
all matching pages. `$wgPageFormsAutocompleteWordFallback` (default true) adds a
category-local contains-words fallback when fewer than ten prefix matches are
available, for queries of 3 to 128 characters (at most six terms). There is no
new persistent cache. Existing autocomplete caches keep their configured TTL.

`$wgPageFormsEnableLocalDrafts` (default true) offers the local-draft control; it
NEVER enables storage until the contributor opts in. Local data expires after
seven days (cleaned when a form is opened), with a 1 MiB record limit. Drafts are
plain browser-profile data, not encrypted private storage. Other users of the same
browser profile can read them; browser quota/privacy settings can prevent writes
or erase them. Do not enable on shared devices. No API token, password, upload,
or arbitrary hidden field is stored. Known tree/rating hidden user values can be
exported, but automatic restoration of these rich widgets is conservatively
refused. Nested repeating-template structures also remain export-only. Regular
text/select/checkbox/radio fields, tokens, comboboxes, first-level repeating
instances and VisualEditor wikitext are supported. File uploads themselves are
never recoverable from a text draft. Schema/enum/count checks run before mutation;
a separate local pre-restore backup protects against third-party initializer
errors. Restoring never publishes a revision.

The most recent matching draft is offered without replacing any current input.
Other tabs have separate record keys. Current page/form revisions are checked
before confirmation; reload is required if the open form became stale. An older
draft requires a separate explicit conflict confirmation. Review the restored
content before saving. This does not replace MediaWiki's edit-conflict checks.

VisualEditor and FullCalendar initialization is unchanged; only tokens,
comboboxes, auto-growing textareas, ratings and trees receive per-widget lazy
initialization. No sticky toolbar is included. Measure real forms and database
queries before attributing a performance gain; no production timing claim is made.

Before deployment, exercise the real French/English forms: save/preview/diff,
mandatory/unique/dependent fields, collapsed errors, two save-and-continue actions,
undo and reorder, source/visual mode, generated page names, draft restore after a
page revision changed, storage denial, and keyboard input on your actual devices.

## Safe labels and asynchronous submission (November 2026)

The current JavaScript suite contains 92 tests across five suites. It runs the
actual PageForms main/submission/preview code, MediaWiki's bundled jQuery and the
bundled Select2 in Jest/jsdom. The original 26 autocomplete/composition/focus
regressions remain included. API requests and VisualEditor targets are controlled
in these tests; no production API is called.

Coverage includes plain-text local/remote/new-tag labels and safe highlighting;
SMW/Cargo uniqueness response validation, cancellation, stale answers, parallel
fields, retries and timeout recovery; normal save/preview/diff submitter
preservation; jQuery synthetic submits, native fallback, third-party submit
vetoes, back-forward restoration and a shared double-submit lock; and two AJAX
saves using a generated target, malformed/HTML/core API errors, summary encoding,
changes during saving, editor conversion failures and edits during conversion.

`validateAll()` and `$.fn.validateUniqueField()` now return `Promise<boolean>`.
All built-in callers have been adapted, including AJAX preview. Site scripts
must await the result: `if (validateAll())` is no longer valid. Registered input
validators may return either a boolean or a promise of a boolean; existing
validation hooks remain synchronous. `pf.formValidationAfter` fires after the
checks settle on the normal validation path. `pf.formSubmit` is fired only when
the validated native submission has not been cancelled by another listener.
`mw.pageFormsActivateVEFields()` returns a promise and retains its optional
callback argument. Submission preparation has a 30-second upper bound;
uniqueness requests have a 15-second timeout. A changed form is not submitted
using an answer for its old values: the user is asked to submit again.

Combobox labels, including display titles, are now plain text. Embedded HTML is
not rendered; the extension's own match highlighting is retained. No bundled
Select2 library or token-composition code was replaced.

These checks do NOT constitute a live MediaWiki/SMW/Cargo or browser acceptance
test. Before production deployment, use a test wiki with the site's real forms:
check a new generated page followed by a second save, save/preview/diff, expired
tokens, offline/timeouts, mandatory and unique fields, conditional/multiple
instances, and VisualEditor in both source and visual modes. Verify that contents
persist after reopening the page. Retest dead-key accents on the actual browsers
and keyboards as described below. Uniqueness remains advisory: this change does
not add a database uniqueness constraint or eliminate SMW indexing delays and
concurrent-editor races. No PHP or database schema was changed in this pass.

## Earlier autocomplete and composition checks

The JavaScript tests use MediaWiki's jQuery, its Jest/jsdom development dependencies,
and the Select2 version bundled with this extension. They exercise request races,
cancellation, cache reuse, destruction, local option preservation and dependent
field refresh. HTTP is controlled in these tests; no external service is needed.

The token tests exercise the real token input class and bundled Select2 with
synthetic native keyboard/input events: dead keys, in-progress composition,
composition completion, IME key code 229, token separators and keyboard selection.
These tests cannot emulate an operating system's keyboard layout. After deployment,
also type `^` then `e` and `¨` then `o` in a token field, both initially and after
selecting another token. Expect `ê` and `ö`, including after saving and reopening
the form. Also check normal Enter/Tab selection and token deletion. Test the
browsers and keyboard layouts used on the wiki.

After the composition and focus fixes, all 26 JavaScript checks pass: 12 combobox
checks and 14 token checks. Before the fixes, the new checks reproduced premature
searches, token changes during composition and refocusing after partial/full text
deletion. Focus regressions use native event listeners: jQuery listeners attached
to the input are themselves removed by the old selection redraw and cannot detect
the subsequent focus event reliably.

A local Chromium check also reproduced an asynchronous native focus event after
deleting text before the focus fix, and no such event afterwards; token selection
and Backspace editing still worked. Firefox with the user's actual keyboard layout
has not been exercised here. In that browser, also check deleting part or all of
the typed text, then immediately entering a dead-key accent; repeat after using
Backspace in an empty field to bring the last token back for editing.

The PHP tests use MediaWiki's integration runner and test database. They cover
invalid/missing mapping templates, empty values, category cycles and duplicate
membership, numeric titles, and titles ending in `@`.

Use `MW_CONFIG_FILE` to select a dedicated development test configuration when
other installed extensions interfere. The existing suite assumes English in
several assertions. The site's normal language need not be changed.

## Validation on MediaWiki 1.43 / PHP 8.2

- New regression checks: 12 JavaScript tests and 8 PHPUnit runner checks passed
  (including the integration runner's database setup/teardown checks).
- A French development wiki with Wikidebates and PageFormsWikidebates enabled was
  exercised over HTTP: render a form, autocomplete a category title ending in `@`,
  save through `pfautoedit`, inspect the stored wikitext, and reopen the form with
  accented characters and the selected value preserved.
- Existing English PHPUnit suite before changes: 183 checks, 392 assertions,
  1 error, 11 failures and 22 skipped. After changes: 191 checks, 404 assertions,
  the same error, failures and skipped checks. No new failures were observed.
- The existing failures concern old markup expectations and category-default-form
  expectations. The error comes from a mapping-property test attempting to use
  installed but unconfigured Semantic MediaWiki (`smwgDefaultStore` is unset).

These checks do not cover the full production forms, browser rendering on actual
devices, or integrations with an active Semantic MediaWiki/Cargo installation.
The synchronous uniqueness requests were not changed by that earlier maintenance
pass; they are replaced in the November 2026 changes documented above. Bundled
third-party library versions remain unchanged.
