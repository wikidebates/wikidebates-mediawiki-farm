# CHANGELOG Wikidebates

## Deferred VEForAll, mapping efficiency and deeper recovery (November 2026)
- Lazy-load VEForAll's platform and editors on Page Forms only after visibility
  or focus, via the matching wikidebates-veforall revision. Preserve native
  textarea editing while scripts load; wait for in-progress initialization
  before submitting, without initializing offscreen editors.
- Skip repeated SMW property lookups and repeated template label expansions
  for identical values within the same request. No cache or SQL schema changes.
- Add read-only real-wiki category/SMW benchmark and browser resource report;
  report actual measured timings rather than inferring performance gains.
- Validate and restore nested repeating instances up to a global budget of 500;
  restore rating values with their visual state. Restore tree selections only
  for unambiguous labels, synchronized on jsTree readiness, keeping unknown or
  duplicated labels export-only.
- Test browser interactions in both Chromium and Firefox, and run an extra
  disposable MediaWiki 1.43/SMW integration job.

## Progressive editing and local recovery (November 2026)
- Initialize individual near-viewport widgets instead of their whole form; keep
  values available before initialization, including the hidden tree value.
- Unify dependent suggestions, remove synchronous token lookups, deduplicate
  requests, expire completed values, ignore stale responses and retain selections
  when the returned choice list is incomplete. Keep focused widgets on refresh.
- Link accessible error summaries to fields; reveal minimized instances and
  recheck only fields already reported invalid. Provide keyboard block movement,
  reversible removal and plain-text collapsed summaries.
- Share semantic dirty state between save-and-continue and leave-page warnings.
  Acknowledge only the sent snapshot, including editor and structural changes.
- Offer opt-in seven-day local recovery drafts, isolated per wiki/user/form/target
  and per tab. Store structured user values, never tokens or executable form HTML.
  Verify current revisions before restoration; require explicit confirmation,
  reject unsupported schemas and retain recovery/export data on failure.
- Discover category trees separately from limited suggestions, query in batches,
  rank exact/prefix/word matches and add bounded category-local word fallback.
  Report an explicit traversal-budget error rather than silently omit a branch.
- Resume native submission in a new browser task after asynchronous validation:
  real Chromium rejects a reentrant requestSubmit during the original dispatch.
- No fixed/sticky action bar. VisualEditor initialization and FullCalendar's
  module-wide initialization are intentionally not deferred by this pass.

## Safe labels, resilient saving and asynchronous validation
- Restore Select2's default escaping for plain labels and selected values; render
  only the extension's escaped match-highlighting markup as a DOM fragment.
- Read `result.target`, preserve the generated target across subsequent saves,
  encode submitted summaries and accept only well-formed successful responses.
- Safely display HTTP/network/API errors, preserve edits, and distinguish an
  unconfirmed save from a confirmed failure; do not automatically retry writes.
- Replace blocking SMW/Cargo uniqueness requests with asynchronous checks that
  distinguish duplicates from failed checks and ignore obsolete responses.
- Share preparation, timeouts and a double-submit lock across native saves,
  previews and save-and-continue. Preserve the clicked action and other submit
  handlers; release the edit warning only on an accepted native submission.
- Wait for editor conversions, reject edits made during validation/conversion,
  and keep changes made during an in-flight AJAX save marked as unsaved.
- Add regression coverage (92 JavaScript tests in total). See tests/README.md for
  the promise-returning validation API and the live acceptance-test limitations.

## Preserve token input focus after deletion
- Keep the inline search input attached while refreshing selected tokens instead
  of removing and refocusing it. An asynchronous refresh after deleting text or
  cleaning up old suggestions must not interrupt a pending dead-key accent.
- Insert token choices before the existing input to preserve their order without
  moving the input. Only restore focus after a refresh if it was actually lost.
- Add native focus-event regressions for partial/full deletion, composition during
  asynchronous updates and Backspace token editing.

## Accents and composition in token fields
- Let the browser finish dead-key accents and IME composition before searching
  or splitting tokens. Opening suggestions must not interrupt a pending accent.
- Preserve native Enter, Backspace and Tab behavior while composing, then resume
  normal token selection after composition ends.
- Add regression tests using the bundled Select2 and the actual token input class.

## Autocomplete reliability and targeted performance improvements
- Ignore stale autocomplete responses, cancel obsolete requests even on cache hits,
  and release pending requests and change handlers when rebuilding a combobox.
- Retain recently reused suggestions in the bounded request cache.
- Fix local HTML option loading and preserve option labels, choices and the selected
  value when refreshing a combobox.
- Check the category-link schema once per traversal instead of once per category.
- Preserve numeric page titles and trailing `@` characters when sorting suggestions.
- Treat invalid mapping-template names like missing templates, and skip template
  lookup entirely for empty input lists.
- Add PHP regression tests and JavaScript tests using the bundled Select2 widget.
  See [tests/README.md](tests/README.md) for commands and validation limits.

## Major changes
- Tableless form layout (`pf-formlayout`)
- Select2 combobox migration
- Lazy widget initialization
- Conditional ResourceLoader loading
- Multi-instance rendering fixes
