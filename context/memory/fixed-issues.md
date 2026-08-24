# Fixed issues

Closed issues, newest first. Live ones are in [../issues.md](../issues.md).

Each entry keeps what was wrong, what the fix actually was, and what is still unproven — the
last one matters most. "Closed" is not the same as "proven everywhere".

> **Numbering trap.** `I-11` means two different things. Unit 11 used it for the storage-folder
> fix. A separate user report about the Bulk tab's numbers was *also* filed as I-11, then
> renumbered to **I-14** when the collision was spotted (`specs/done/12-centralized-scan-stats.md`).
> `I-5` never appeared in the issues file at all — it lived only in the old fix-plan and was
> settled by decision 7 in [units.md](units.md).

---

## I-17 — Block editor showed nothing; a second bookkeeping-desync path fed the same class of bug I-16 closed (2026-08-23, no unit)

**A fourth finding, from a follow-up browser pass after this entry first closed:**
the dashboard's Troubleshoot tab showed a bare "No route was found matching
the URL and request method." `resources/admin/Services/http.js`'s `request()`
built every call as `config.restUrl + path` — plain string concatenation.
On a site using plain permalinks (this one, confirmed via
`get_option('permalink_structure')` being empty), `rest_url()` itself already
returns a query string (`…/index.php?rest_route=/namespace/`), so a call
carrying its own query string (`request('logs?lines=500')`) produced a URL
with two `?` characters. `apiFetch` doesn't parse that correctly: the actual
request that left the browser was `rest_route=…logs%3Flines&_locale=user` —
`lines`'s value silently disappeared. Reproduced live via Playwright network
trace; confirmed the `logs` route itself was fine via `curl` against the
`?rest_route=` fallback directly. Fixed by splitting `path` on `?` and
attaching the query string with the separator matching whichever form
`restUrl` is already in (`?` when it's a clean path, `&` when it already
carries `rest_route=…`) — verified live afterward: the same request now
resolves to `rest_route=…/logs&lines=500` and returns 200, with a clean
console. Pre-existing bug, unrelated to anything else in this entry; only
surfaces under plain permalinks, which is why it went unnoticed until this
session happened to be running against a plain-permalink site.

Three more findings from the original investigation, on a freshly reset cb-test.local:

**No status/error UI in Gutenberg.** `expose_to_js()` only reaches attachment
data fetched via admin-ajax `query-attachments` (`wp_prepare_attachment_for_js`).
The block editor's Image/Gallery blocks read attachments over
`/wp/v2/media/...`, which never runs that filter, so `swiftImageOptimizer`
was silently absent there — the panel rendered by `resources/media/media.js`
never even runs, since that bundle patches the classic Backbone
`wp.media.view.Attachment.Details`, not anything the block editor uses.
Closed by extracting the payload logic out of `expose_to_js()` into
`MediaLibraryHandler::optimization_payload()`, exposing it via a new
`register_rest_field('attachment', 'swiftImageOptimizer', ...)`
(`RestFieldHandler`), and adding a small React bundle (`resources/editor/`,
`enqueue_block_editor_assets`) that reads the field and renders the same
panel inside a selected `core/image` block's `InspectorControls`. Verified
end-to-end via `rest_do_request()`: the field returns the correct payload for
an authenticated request and `null` for a logged-out one. Also verified live
in the browser (Playwright, admin session on cb-test.local): opened Sample
Page's Gallery block, selected an already-optimized image, and the panel
rendered under the Image block's **Settings** tab — not **Content** (core's
Image block puts Media/Alt text under Content; `<InspectorControls>` without
a `group` prop lands in Settings, the correct, unsurprising default, just
worth remembering when looking for it) — showing "Image Optimized",
"Saved 73.4%", "1.86 MB → 506.5 KB" and a working Restore original button,
matching the REST payload exactly. A stale block referencing a
since-deleted attachment (left over from an earlier site reset) correctly
rendered no panel at all and logged no error beyond the expected 404 on
`fetchStatus()` — the `catch` → `setData(null)` path held.

**A second, independent path to the exact corruption I-16 fixed.** Confirmed
live: attachment #17 had `post_mime_type: image/jpeg` while
`_wp_attached_file` was already `.webp`. The debug log showed
`wp_handle_upload` firing separately for subsize/derivative files of an
already-existing attachment — not a genuine new upload. `Interceptor` had no
guard against this: it renamed and deleted the source exactly as it would
for a real upload, but since the result was never a *new* attachment,
`add_attachment` never fired, so `bind_attachment()`'s exact-path match on
`$pending` never matched, and `post_mime_type`/the log row were silently left
describing a file no longer there — same failure shape as I-16 (do_convert()
crash-safety), different code path (Interceptor, upload-time). Closed by
`Interceptor::already_belongs_to_an_attachment()`, checked right after the
existing empty-file guard: refuses to convert when `attachment_url_to_postid()`
already resolves the file's URL, or when stripping a WordPress
subsize-naming suffix (`-WxH`, `-scaled`, `-rotated`) resolves to an
attachment whose own registered metadata names this exact file. Verified via
reflection against real files on cb-test: correctly blocks both a re-fed
main file and a re-fed subsize, and does not block a genuinely new upload.

**Already-corrupted attachments needed their own repair**, since the guard
only stops new occurrences. `MimeReconciler::reconcile()` (new, modeled on
`BackupManager::reconcile()`'s "read log + disk, write corrections, delete
nothing" pattern — not a modification of `reconcile()` itself) corrects
`post_mime_type` from the actual file and, only when a prior row with real
`original_size` data exists, restores the log row to `optimized` with
`backup_path` deliberately left empty so `BackupManager::reconcile()` picks
it up next. Exposed as `wp swift-image-optimizer repair-mime` and
`POST reconcile-mime`, documented to run *before* `repair-backups` — same
"repair before sweep" ordering invariant 26 already established. Verified
live: deliberately corrupted attachment #30 the way the bug did, ran
`repair-mime` (mime and log row corrected, `backup_path` empty as designed),
then ran `repair-backups` against the result and confirmed it picked up the
row and rebuilt a working manifest.

**Also fixed in the same pass**, contributing factor: `Optimizer::can_optimize()`'s
memory pre-check ran against PHP's base `memory_limit`, before
`wp_raise_memory_limit('image')` — which used to run later, inside
`optimize()`, after the check had already decided. Moved the raise into
`can_optimize()`, immediately before the check that depends on it. Confirmed
via grep: `can_optimize()` has exactly two callers, both proceeding straight
to conversion on `true`, so raising unconditionally there wastes nothing.

**Still unproven:** only the "Try again"/not-optimized state of the block
editor panel — every browser check landed on an already-optimized image,
since every attachment on cb-test.local happened to be `status: optimized`
at verification time. Same component, same shared payload the classic modal
already renders that state from correctly, so low risk, but not literally
seen. The memory-raise reorder has no fixture that actually observes an
image flipping from rejected to accepted under the change — `test:php`
passing confirms no regression, not the behavior change itself.

---

## I-16 — `do_convert()` not crash-safe, Restore silently used the wrong file (2026-08-23, no unit)

Confirmed live on cb-test attachment #656 (`benjamin-chambon-zO0le9E7Ono-unsplash`). The rename to
WebP and `update_attached_file()` ran before `wp_generate_attachment_metadata()` — core's own
subsize regeneration, the memory-hungriest step — and before `post_mime_type` and
`OptimizationLog::upsert()`. A PHP OOM fatal during metadata regen killed the request after the
destructive rename but before any bookkeeping: the file was already `.webp` on disk,
`post_mime_type` still read the old format, and no log row existed. The next Optimize attempt then
treated the file as untouched, re-encoded the already-converted WebP a second time, and the
resulting row's `original_file` pointed at that crash-artifact WebP instead of the true original —
so Restore original silently restored to the wrong file while the real original sat orphaned in
the backup folder.

Fixed by moving the `post_mime_type` update and an `OptimizationLog` write to immediately after
`update_attached_file()`, ahead of `wp_generate_attachment_metadata()`, under a new
`STATUS_PENDING`. That row already carries the correct `original_file` and `backup_path` before
the risky step runs, so a crash there leaves the database and the disk agreeing with each other.
`do_convert()` now refuses to reprocess a `STATUS_PENDING` row (`conversion-pending` error) instead
of silently re-encoding it and overwriting the correct row with a wrong one, and `restore()` now
accepts `STATUS_PENDING` rows too, since the backup they point at is genuine. On success the pending
row is finalized in place (`status` → `optimized`, `url_map` added) rather than replaced.

**Still unproven:** no test actually forces an OOM mid-`wp_generate_attachment_metadata()` to
exercise the crash path end-to-end — `tests/php/rewriter-test.php` (unaffected by this change)
still passes, but that suite doesn't touch `do_convert()`. A browser or WP-CLI repro that kills PHP
partway through metadata regen, followed by a Restore original, would close this properly.

---

## I-9 — Retention expiry untested (2026-08-16, no unit)

The entry said the purge had "only been exercised via artificially expired rows." It understated
it: `JobRunner::purge()` had **no** coverage at all. Every backup assertion went through
`BackupController::purge()` → `purge_manifests()`, the variant that deliberately *drops* the
`backup_expires > 0` and `status = 'optimized'` filters — so the retention query had never run
under test, not once. The entry also named `RetentionCron::purge()`; no such class exists, the
code is `App\Hooks\Scheduler\JobRunner`.

Now covered by assertions that fire `do_action( JobRunner::HOOK )` rather than calling `purge()`
directly, so a purge that works but is not wired to its action fails. The one worth keeping
asserts an **unexpired backup survives** a cron run: a wrong comparison operator there wipes every
backup on the site, and nothing else in the suite would have noticed. A "keep forever" backup
(`backup_expires = 0`) is asserted untouched — the boundary between `purge()` and
`purge_manifests()`.

**Still synthetic:** the backup is aged by moving `backup_expires` into the past. Waiting out a
real 30-day window is not something a test can do.

## I-8 — Backup manifest unreachable, no repair path (2026-08-16, no unit)

The entry was stale in two ways. It blamed **TEXT truncation** — `backup_path` is `TEXT`, 65,535
bytes, and a manifest is ~400 bytes, so reaching the limit needs ~1,400 subsizes on one image. It
has never been the cause. It also said nothing reconciles disk against the manifests, which
stopped being true in Unit 13: `purge_orphans()` reconciles them and then **deletes** what it
finds. Unit 13's spec claims it closed I-8; it closed the half the user reported (the folder would
not empty) and left the recover half — inverting the risk, since the only routine touching an
unreferenced backup destroyed it.

What actually strands a manifest, both found by reading the write path:

1. `wp_json_encode()` returning `false` — a filename with invalid UTF-8 is enough. Both callers
   wrote that straight into the column where it becomes `''`, indistinguishable from "never backed
   up", with no log line. Now goes through `BackupManager::encode_manifest()`, which logs at error
   level and names the directory.
2. **Write ordering.** `backup()` copies originals near the start of `convert()`; the row lands at
   the end. Every *error* path rolls back, but a fatal or timeout cannot. Likely explanation for
   what Unit 13 measured: 78 rows with empty `backup_path` against 42 files on disk.

`BackupManager::reconcile()` rebuilds manifests **from the log rows, not a directory walk** — the
glob that destroyed 54 real backups started from the directory, so this starts from our own
records and asks the disk about each one, keeping the blast radius at one attachment. The file
list comes from the stored `url_map` plus the original's basename (`drop_foreign_filenames()`
prunes the map, so it alone can be incomplete). A row whose original is missing is **skipped**, not
given a manifest that would fail at restore time.

It writes pointers and deletes nothing, which is why *Repair backup records* sits above *Delete
all backups now* — the purge sweeps exactly what repair recovers. Recorded as **invariant 26**.

**Not proven:** no run against a real stranded backup. cb-test's folder was already empty, so every
orphan tested was harness-created. Still open in [../issues.md](../issues.md).

## I-4 — WP-CLI bulk paths unexercised (2026-08-16, no unit)

Unit 10 had exercised the single-attachment commands live, but not the bulk paths the docs
recommend for large libraries: `optimize --all`, `--dry-run`, `restore --all`, `--limit`,
`--batch`.

`tests/php/cli-bulk-e2e.php` shells out to the **real `wp` binary** rather than calling `Commands`
methods. That distinction is the point: the untested part was never the conversion, it was flag
parsing, the clamps and the `WP_CLI::error` exit codes, none of which exist when you call the
method directly. Nothing broke, including the two clamps most likely to have been wrong
(`--batch=0` clamps to 1; `--batch` larger than the queue does not over-read).

Kept out of the default suite deliberately — it acts on the whole library, not its own fixtures.

**One site is one data point.** `run-cli.sh` makes a second site cheap; still not done.

## I-7 — Dry-run extrapolation is a linear estimate (2026-08-16)

Tested. `Runner::dry_run()` samples 25 attachments and scales linearly. It stands as a "roughly
how much will change" signal — do not present it as exact.

## I-6 — Multisite unconsidered (2026-08-16)

Tested. `readme.txt` still says nothing either way.

## I-3 — Dashboard never opened in a browser (2026-08-16)

Manual browser pass — the last release gate before a .org submission. It also settled the two
claims Unit 14 could only prove in source: foreign notices really are gone from the screen, and
the card boxes were merely touching rather than overlapping.

## I-15 — Card bottom had no spacing (Unit 14)

Three spacing rules in `admin.scss`. See `specs/done/14-notices-and-card-spacing.md`.

## I-10 — Foreign admin notices on this plugin's screen (Unit 11, reopened, closed Unit 14)

The plugin emitted core's `notice` class itself and hooked `admin_notices` on **every** admin
screen. Unit 11's fix was incomplete; `ForeignNoticeHandler` (Unit 14) strips other plugins'
notices on this screen only, whitelisting the plugin's own so the missing-build notice survives.

Worth remembering *how* the reopen was caught: reading the knowledge graph showed the notice in
the screenshot belonged to Elementor, not to this plugin — after the first fix had been written
and committed.

## I-14 — Bulk optimize and stats never reconciled (Unit 12)

*(Reported as I-11; renumbered — see the trap at the top.)* The Bulk tab's numbers came from two
unrelated computations: `Scanner::summary()`'s live mime count, which loses an image from its own
totals the moment it is optimized because its mime becomes `image/webp`, and `StatsResource`'s
log-table aggregate. Replaced with one stored, disk-verified scan snapshot (`ScanRunner`,
invariant 25). See `specs/done/12-centralized-scan-stats.md`.

## I-13 — Restored site reported every image as optimized (Unit 11)

`already-optimized` trusted the status column with no disk check. Also found: clearing the row
does not re-queue the image for bulk, because its mime is already `image/webp`.

## I-12 — Bulk stopped on tab change and restarted from scratch (Unit 11)

`start()` overwrote live run state and the UI never reconciled `running` on mount. Also found,
and worse than the report: **a crash between "files renamed" and "references repointed" broke
those references permanently**, because the batch marks images done before rewriting.

## I-11 — Three storage folders in uploads (Unit 11)

Three sibling constants, never one parent. Now one folder with subdirectories.

## Plugin Check reported 26 findings that `npm run lint:php` called clean

Not filed as an I-number; it came in as a Plugin Check report against the shipped zip.

`phpcs.xml.dist` had excluded `WordPress.WP.AlternativeFunctions` wholesale and scoped
`EscapeOutput.ExceptionNotEscaped` away from `/framework/*`. **Plugin Check never reads that
file** — it runs its own rulesets plus sniffs its check classes invoke directly, and honours only
inline `phpcs:ignore`. So the local lint was proving something about itself, not about the gate.

Worse, the blanket exclude hid that the inline suppressions underneath it were broken:
`readfile_readfile` was not a real sniff code, three sites had a preceding-line directive silently
overridden by a trailing one on the same line (PHPCS honours one per line, and the trailing one
wins), and no `rename()`, `fread()` or `fclose()` call had ever been annotated at all.

The fix moved every justification onto correct inline ignores and **narrowed `phpcs.xml.dist` to
exactly the three exclusions Plugin Check itself applies**. That narrowing is the point of the
change — restoring the blanket exclude as a "cleanup" would re-hide the same class of defect.
Also: `bin/build-dist.sh` was shipping `agent.md`, three stray `.yml` files and `.playwright-cli/`
while excluding `composer.json` next to a shipped `vendor/`; it now has an explicit top-level
allowlist that fails the build on anything unexpected.

**The build zip was then installed over the plugin folder**, which is the git working tree.
WordPress deletes the existing directory before unpacking, so `.git`, `context/`, `bin/`, `tests/`,
`resources/`, `phpcs.xml.dist` and `dist/` (including the zip) went with it. Everything that had
been pushed was recovered from the remote; the uncommitted doc and tooling edits had to be redone
from scratch. `build-dist.sh` now prints a warning at the end, and `agent.md` carries the rule.
None of this is recoverable locally — there are no Time Machine destinations and no APFS snapshots
on this machine.

Still unproven: everything in [../pre-release-checks.md](../pre-release-checks.md) #7 — the runtime
checks and the zip itself were never put through `wp plugin check`.

## I-2 — Suite tested cwebp while the site runs Imagick (Unit 11)

CLI PHP here has no Imagick; the site's web PHP does, and it is the production engine. So every
harness run was exercising an engine users never get. `tests/php/run.sh --web` now runs the
harnesses under the site's php-fpm, and `SIO_TEST_ENGINE` forces one per run with the harness
asserting on the engine actually recorded — "used" is proven rather than assumed.

## I-1 — PHPCS never run (Unit 09)

784 violations → 0. Dead `safe_mode` check removed, `imagedestroy()` version-gated, and SQL
annotations fixed that had never been in effect. See `specs/done/09-phpcs-compliance.md`.

## I-5 — Upload-optimized images could not be restored (Unit 10)

Never carried an entry in the issues file. Settled with the user: uploads are backed up too,
behind a `backup_uploads` setting defaulting to on. `Interceptor` writes the manifest in the same
shape the converter path uses, so Restore works for uploads without a change to the restore code.
The storage cost is real and is stated plainly in `readme.txt`.
