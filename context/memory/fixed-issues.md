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
