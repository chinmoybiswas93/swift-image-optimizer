# Progress tracker

Where the project is now. Finished units and their reasoning are in
[memory/units.md](memory/units.md); open issues in [issues.md](issues.md); surfaces that are merely
untested in [pre-release-checks.md](pre-release-checks.md).

Keep this file short. When a section here becomes history, move it to `memory/`.

## Phase

**v1.2.0 — hardening before WordPress.org submission.** Both release blockers are closed and the
dashboard has had a real browser pass. Units 01–14 are done. 1.2.0 adds the block-editor status
panel and WordPress 7.1 client-side media processing support; `readme.txt` is tested to 7.1.

Not shippable yet: [issues.md](issues.md) issue 1 is a data-loss bug in the upload backup path.

## Where things stand

- **I-19** — Images uploaded through Gutenberg were converted to WebP correctly but the panel
  showed **nothing at all** — no status, no error. Root cause is **WordPress 7.1's client-side
  media processing**, which is on by default in a secure context: the block editor uploads the
  original with `generate_sub_sizes=false`, generates every size *in the browser* from the
  full-resolution file, and `POST`s each one to `/wp/v2/media/<id>/sideload`. That endpoint uploads
  through `wp_handle_upload()` with context `'upload'`, so the Interceptor was treating all eight
  files as fresh uploads — eight conversions and eight backups per image — and core's
  `update_attached_file()` for the `scaled` sideload then left the log row naming the pre-sideload
  file. `optimized_output_exists()` went false, `optimization_payload()` blanked the status, and
  `Panel.jsx:108` returned `null`. Closed with invariant 28: route detection on
  `rest_request_before_callbacks`, a dedicated `handle_sideload()` that converts without backing up
  or parking, and a repoint of `optimized_file` for `scaled`/`original`.

  This also answers the question that ran through the whole I-18 investigation — why Gutenberg and
  the Media Library behaved differently. The per-subsize `wp_handle_upload` calls were never coming
  from core PHP; they come from the browser.

  Two smaller defects fixed in the same pass: `already_belongs_to_an_attachment()` only ever looked
  up the *incoming* extension, so a derivative of an already-converted parent (`photo-scaled.jpg`
  against `photo.webp`) never resolved; and `optimization_payload()`'s `$stale` branch zeroed the
  reported sizes on two lines that were immediately overwritten, so a stale row reported an empty
  status beside real savings figures.

  Verified by reproducing the exact CSMP sequence against the live REST endpoints: the row now
  matches `_wp_attached_file` (`csmp-scaled-1.webp`), the panel reports `optimized` at 95.4%, the
  backup folder gains exactly **one** file, and the library scan reports **0 pending**. Confirmed
  afterwards by a real block-editor upload. Still only WordPress 7.1 on one install with Imagick —
  `pre-release-checks.md` item 6 has what is left.

  The same regression run turned up a **pre-existing data-loss bug, unrelated to this work**, now
  issue 1: upload backups collide by filename and silently overwrite each other. Left unfixed
  deliberately — the layout change touches `backup_file()`, `backup()`, `manifest_is_intact()` and
  `reconcile()` together and has to stay readable to manifests already written.

- **I-18** — Gutenberg uploads reported "Too large for the available memory" on
  photos the Media Library optimized happily. Not flaky: `has_memory_for()`
  charged `w × h × 4 × 2` for the **pre-scale** original, so anything past
  ~28 MP was refused on a 256M limit (7728 × 5152 → 304 MB estimated to
  produce a 2560px WebP), while every later path — Try again, the row action,
  a bulk run — calls `can_optimize()` on `get_attached_file()`, by then
  WordPress's 2560px `-scaled` copy at 4.4 MP. Same image, two answers,
  because the two paths were handed different files. Fixed by charging the
  second frame at the size it will actually be, exempting Imagick from an
  estimate that only models a PHP-heap decode, and adding the `jpeg:size`
  decode hint that makes that exemption safe.

  The same investigation confirmed I-17's guard could never fire during
  initial metadata generation — it consulted `_wp_attachment_metadata['sizes']`,
  which WordPress writes only *after* generating every subsize — so subsize
  files were still being converted and left unbindable. Attachments #8 and #10
  on cb-test are in that state (WebP on disk, `post_mime_type` still
  `image/jpeg`, log row still `skipped: insufficient-memory`); they need
  `repair-mime` run once. Guard now resolves by name alone and fails closed.

  Verified end to end over the real REST endpoint Gutenberg posts to
  (`?rest_route=/wp/v2/media`, plain permalinks): the 7728 × 5152 / 17 MB photo
  that used to skip now returns `optimized`, 17,091,942 → 1,010,398 bytes
  (94.1%), `engine=imagick`, and leaves exactly **one** backup file rather than
  one per generated subsize — which is the guard fix holding.

  Left open as issue 8: an upload-path row's `optimized_file` does not match the
  `-scaled` file WordPress attaches when the converted WebP is still wider than
  the scaling threshold, so such images bucket as `pending` forever. Pre-existing
  (attachments #9 and #11), and the cause of `bulk-e2e`'s one failing assertion.
  Not reachable under the current defaults.

- **I-17** — Gutenberg showed no optimization status at all (the classic
  modal's data never reaches attachments fetched over REST) — closed with a
  `register_rest_field` plus a new `resources/editor/` block-editor bundle.
  Same investigation also found a second path to I-16's exact corruption
  class: `Interceptor` had no guard against `wp_handle_upload` firing on a
  file that already belongs to an existing attachment, confirmed live on
  attachment #17. Closed with `Interceptor::already_belongs_to_an_attachment()`
  plus a `repair-mime` command for attachments already affected. The memory
  pre-check's `wp_raise_memory_limit('image')` ordering was tightened in the
  same pass. Browser-verified live (Playwright): panel renders correctly
  under the Image block's Settings tab with real optimized-state data. A
  follow-up browser pass found a fourth, unrelated bug in the same
  dashboard: `Services/http.js`'s `request()` mangled any call carrying its
  own query string on a site using plain permalinks (this one), producing
  "No route was found" on the Troubleshoot tab. Fixed and re-verified live.

- **I-16** — `do_convert()` was not crash-safe: a PHP OOM fatal during core's
  `wp_generate_attachment_metadata()` (confirmed live on attachment #656) left the file renamed to
  WebP with no log row, so the next Optimize attempt re-encoded it and wrote a row whose
  `original_file` pointed at the crash-artifact WebP — Restore original then restored the wrong
  file. Fixed by writing an `OptimizationLog` row (new `STATUS_PENDING`) and the `post_mime_type`
  update *before* the memory-hungry regen step, refusing to reprocess a pending row, and letting
  `restore()` recover from one. `rewriter-test` still passes; no test yet forces the actual OOM.

The three closures before that were all corrections to entries that were themselves wrong, which is
the useful pattern to carry forward:

- **I-4** — the CLI's bulk flags had never run. The conversion was never the untested part; flag
  parsing, the clamps and the `WP_CLI::error` exit codes were, and none of them exist when you
  call a `Commands` method directly.
- **I-8** — the entry blamed TEXT truncation, which cannot happen at these sizes. The real causes
  were an unchecked `wp_json_encode()` return and the gap between copying backup files and writing
  the row. `BackupManager::reconcile()` closes it, and invariant 26 records the ordering it
  depends on: **repair before sweep**, because the purge deletes exactly what repair recovers.
- **I-9** — the entry said coverage was thin. There was none: every backup assertion ran through
  the variant that deliberately drops the expiry filter.

Nothing is in progress. Next work comes off [issues.md](issues.md), the verification passes in
[pre-release-checks.md](pre-release-checks.md), or the `future-specs/` backlog.

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
