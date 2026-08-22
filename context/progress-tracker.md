# Progress tracker

Where the project is now. Finished units and their reasoning are in
[memory/units.md](memory/units.md); open issues in [issues.md](issues.md).

Keep this file short. When a section here becomes history, move it to `memory/`.

## Phase

**Post-v1.1.0 — hardening before WordPress.org submission.** Both release blockers are closed and
the dashboard has had a real browser pass. Units 01–14 are done.

## Where things stand

The last three closures were all corrections to entries that were themselves wrong, which is the
useful pattern to carry forward:

- **I-4** — the CLI's bulk flags had never run. The conversion was never the untested part; flag
  parsing, the clamps and the `WP_CLI::error` exit codes were, and none of them exist when you
  call a `Commands` method directly.
- **I-8** — the entry blamed TEXT truncation, which cannot happen at these sizes. The real causes
  were an unchecked `wp_json_encode()` return and the gap between copying backup files and writing
  the row. `BackupManager::reconcile()` closes it, and invariant 26 records the ordering it
  depends on: **repair before sweep**, because the purge deletes exactly what repair recovers.
- **I-9** — the entry said coverage was thin. There was none: every backup assertion ran through
  the variant that deliberately drops the expiry filter.

Nothing is in progress. Next work comes off [issues.md](issues.md) or the `future-specs/` backlog.

## Verified behaviour

Measured on cb-test.local (WP 7.0.3). This table is evidence, not narrative — it is the one record
of what the plugin actually did on real files.

| Case | Result |
|---|---|
| 267 KB JPEG upload | → 130 KB WebP, 51.4% saved, all 5 subsizes WebP |
| 1.52 MB photographic PNG with alpha | → 69.8 KB WebP, transparency preserved |
| Flat-colour alpha PNG (4.4 KB) | Correctly **skipped** — WebP would be larger |
| Existing WebP upload | Passed through byte-identical, no log row |
| Convert → restore round trip | Restored file byte-identical to source; all references reverted |
| Serialized meta, Elementor JSON meta, options, post_content | All repointed, all still valid after rewrite |
| 884 KB PNG via the site's own upload path | → 69.7 KB WebP, **92.1% saved**, Imagick, 800 ms |
| Grid toolbar + modal panel | Verified live in the browser, zero console errors |
| Portrait JPEG, EXIF Orientation=6, via the plugin | → **800x400 upright**, engine `gd` (chain declined cwebp) |
| The same file through cwebp directly | → **400x800 sideways** — the bug, reproduced beside its fix |
| Upload path with `backup_uploads` on | Manifest intact, image **restored** — impossible before Unit 10 |
| Convert → restore, schema v3 | 7 URL lookup rows created, 0 after restore; file, mime and dimensions reverted |
| Temp sweep | Abandoned file removed, in-flight file left alone |
| Backup purge | Folder reaches 0 bytes, guard files survive, a symlink's target outside the root untouched |
| Retention cron | Unexpired backup survives; aged one collected; "keep forever" untouched |

## Architectural decisions

Why the code is shaped the way it is. These are not recoverable by reading it.

1. **No `JsonRewriter` class.** The plan called for one to handle Elementor/Bricks escaped-slash
   JSON. Unnecessary: `UrlMap::with_escaped_slashes()` adds the `https:\/\/` form to the plain map,
   so a `strtr` over the raw string handles JSON without decoding it — simpler and safer than
   parse-modify-reencode.
2. **React source lives outside the PHP tree.** `wp-scripts` insists on `src/index.js` by default,
   so `webpack.config.js` overrides the entry point rather than mixing JS into PHP directories.
3. **Backup expiry does not change `status`.** It originally set a `backup_expired` status, which
   silently removed that image from the savings stats. Availability is signalled by an empty
   `backup_path` instead.
4. **Soft-error classification is centralized** in `AttachmentConverter::soft_errors()`. It was
   duplicated across three call sites and had already drifted out of sync.
5. **The rewriter fetches the filter column in its main SELECT.** It used to run a separate query
   per matched row — tens of thousands of round trips on a real library. The skip check also moved
   *before* the counting, so dry-run numbers match what a real run would do.
6. **Custom toolbar buttons rely on core skipping `.media-button`.** `SelectModeToggle`'s
   show/hide never touches elements carrying that class, in either direction. That is what allows
   an always-visible button beside "Bulk select" without patching core — and why both buttons
   manage their own `hidden` class.
7. **Uploads are backed up too**, behind `backup_uploads`, default on. `Interceptor` writes the
   manifest in the same shape the converter path uses, so Restore works for uploads with no
   separate restore code. The storage cost is real and is stated in `readme.txt`.
8. **Engine selection is a chain, not a choice.** `EngineFactory::get()` returns the first of a
   chain and `Optimizer` walks it — which lets cwebp decline an EXIF-rotated JPEG it would write
   sideways, and lets a CMYK JPEG that stops GD dead fall through to Imagick. An explicit
   preference moves that engine to the front rather than replacing the chain.
9. **Locks are options, not transients.** `get_transient()` then `set_transient()` is
   check-then-set: two simultaneous requests both see "unlocked". `add_option()`'s unique key makes
   the database arbitrate. The stored value is the acquisition time, so a lock orphaned by a fatal
   can be broken rather than blocking that image forever.
10. **The rewriter invalidates precisely.** It used to call `wp_cache_flush()` once per batch,
    discarding a persistent object cache every few seconds — worst on the large sites that can
    least afford it. Descriptors carry an `object` column and a cache `group`, collected in the
    existing SELECT, so only what was rewritten is dropped.
