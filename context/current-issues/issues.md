# Current Issues

Known gaps. Nothing here is a live bug in shipped behaviour — these are unverified paths,
environment limits and untested surfaces.

Last reviewed: 2026-08-16 — I-10, I-14 and I-15 verified against the shipped code, **I-3,
I-4, I-6 and I-7 closed by testing**, **I-8 closed by `BackupManager::reconcile()`**, and
**I-9 closed by covering the retention cron**.

**Open: nothing.** Everything closed is in the table at the bottom; the reasoning behind each
closure lives in that unit's spec, or in a closure-detail section here where there was no unit.

Read the Unit 10 caution below before treating that as good news.

Unit 10 is a caution about this file. It closed fourteen defects that were **not** listed here,
three of which destroyed user data, and all of which were found by reading the conversion path
rather than by any test or any entry below. An empty issues list means nobody has looked
recently, not that nothing is wrong.

---

## Closed

The reasoning behind each closure lives in the unit's spec; the historical record of the
pre-Unit-11 ones is in [progress-tracker.md](../progress-tracker.md), not here.

| Issue | Closed in | Detail |
|---|---|---|
| I-1 — PHPCS never run | Unit 09 | [specs/done/09-phpcs-compliance.md](../specs/done/09-phpcs-compliance.md) |
| I-2 — Suite tested cwebp while the site runs Imagick | Unit 11 | [progress-tracker.md](../progress-tracker.md) |
| I-11 — One storage folder | Unit 11 | [progress-tracker.md](../progress-tracker.md) |
| I-12 — Resumable cron-driven bulk | Unit 11 | [progress-tracker.md](../progress-tracker.md) |
| I-13 — Optimized state verified against disk | Unit 11 | [progress-tracker.md](../progress-tracker.md) |
| I-14 — Bulk optimization and stats centralized | Unit 12 | [specs/done/12-centralized-scan-stats.md](../specs/done/12-centralized-scan-stats.md) |
| I-10 — Foreign admin notices on this plugin's screen | Unit 14 | [specs/done/14-notices-and-card-spacing.md](../specs/done/14-notices-and-card-spacing.md) |
| I-15 — Card bottom had no spacing | Unit 14 | [specs/done/14-notices-and-card-spacing.md](../specs/done/14-notices-and-card-spacing.md) |
| I-3 — Dashboard never opened in a browser | 2026-08-16 | Manual browser pass — no unit; the last release gate |
| I-6 — Multisite unconsidered | 2026-08-16 | Tested — no unit. `readme.txt` still says nothing either way |
| I-7 — Dry-run extrapolation is a linear estimate | 2026-08-16 | Tested — no unit. The estimate stands as a "roughly how much will change" signal |
| I-4 — WP-CLI bulk paths unexercised | 2026-08-16 | `tests/php/cli-bulk-e2e.php` — 42 assertions through the real `wp` binary. See below |
| I-8 — Unreachable backup manifest | 2026-08-16 | No unit. `BackupManager::reconcile()` — see below |
| I-9 — Retention expiry untested | 2026-08-16 | No unit. 15 assertions through the real cron hook — see below |

The two caveats Unit 14 could not settle in source — whether foreign notices are really gone
from the screen, and whether the cards *overlapped* or merely touched — went to I-3, and I-3's
browser pass has now closed it. No per-tab detail from that pass was recorded here; if it turned
up anything worth keeping, that belongs in a new entry rather than a reopened one.

Still unverified in a browser: `tests/e2e/library-scan.spec.js`, which has never run against a
real login — see **Next Up** in [progress-tracker.md](../progress-tracker.md).

### I-4 closure detail (no unit — the reasoning lives here)

Unit 10 had already exercised `optimize --id`, `restore --id`, `stats`, `diagnostics`, `logs`,
`logs --reset` and `requeue` live. What remained were the bulk paths — `optimize --all`,
`optimize --dry-run`, `restore --all`, and the `--limit` / `--batch` flags — which is to say the
route actually recommended for large libraries.

`tests/php/cli-bulk-e2e.php` now covers all of them, and it shells out to the real `wp` binary
rather than calling `Commands` methods. That distinction is the point: the untested part was
never the conversion, it was the flag parsing, the clamps and the `WP_CLI::error` exit codes,
none of which exist when you call the method directly.

Covered: the three refusals (bare `optimize`, bare `restore`, `--limit` without `--all`) exit
non-zero and convert nothing; `--dry-run` creates no log rows and leaves every original in place,
and wins over `--all` rather than falling through to it; `--limit` caps the announced total;
`--batch=0` clamps to 1 instead of looping on an empty batch; `--batch` larger than the queue
does not over-read; a second `--all` reports "Nothing to do" without reprocessing; `restore --all`
puts every original back on disk, records `STATUS_RESTORED`, returns those images to the pending
queue, and finds nothing on a second run.

**Result:** 42 assertions, all passing on cb-test.local (2026-08-16). Nothing broke — including
the two clamps most likely to have been wrong.

**Run it elsewhere with `tests/php/run-cli.sh`.** It resolves the socket, the site's *own* PHP
version and the expected domain from Local's `sites.json`, so it is not pinned to this machine's
one site — `--sites` lists what is available and `--smoke` runs only the read-only commands. Both
that runner and the harness refuse rather than guess: the runner if `option get siteurl` does not
match the domain Local has on record, the harness if the library holds any pending or restorable
image it did not create, since `--all` acts on the whole library and not just its own fixtures.

One site is one data point. The guards make a second site cheap; run it on one before treating
the CLI as proven everywhere.

### I-8 closure detail (no unit — the reasoning lives here)

The issue text asked for "a reconcile routine that walks the backup directory and rebuilds
manifests from what is actually there." It is built, but not the way that sentence describes,
and the difference is the whole safety argument.

**Two of the three things the old text said were wrong.**

- It blamed **TEXT truncation**. `backup_path` is `TEXT` — 65,535 bytes — and a manifest is a
  relative dir plus basenames, roughly 400 bytes for a normal attachment. Reaching the limit
  needs about 1,400 subsizes on one image. It has never been the cause and realistically cannot
  be. (WordPress does strip `STRICT_TRANS_TABLES`, so a truncation *would* be silent. It just
  never happens.)
- It said "no routine reconciles the two", which stopped being true in **Unit 13**.
  `BackupManager::purge_orphans()` reconciles disk against the manifests — and then **deletes**
  what it finds. Unit 13's spec claims it closes I-8; it closed the half the user had reported
  (the folder would not empty) and left the half I-8 is actually about. Worse, it inverted the
  risk: until now the only routine that touched an unreferenced backup destroyed it.

**What actually strands a manifest**, both found by reading the write path:

1. `wp_json_encode()` returning `false` — a filename with bytes that are not valid UTF-8 is
   enough. Both callers wrote the result straight into the column, where `false` becomes `''`:
   indistinguishable from "never backed up", with no log line. Now goes through
   `BackupManager::encode_manifest()`, which logs at error level and says which directory.
2. **Write ordering.** `backup()` copies the originals near the start of `convert()`; the row is
   written at the end, after the encode, the file move and the site-wide URL rewrite. Every
   *error* path in between rolls the backup back, but a fatal or a timeout cannot — and leaves
   files on disk with no row. This is the likely explanation for what Unit 13 measured on
   cb-test: 78 rows with an empty `backup_path` against 42 files on disk. Truncation does not
   produce that.

**`BackupManager::reconcile()` is driven from the log rows, not from a directory walk.** The glob
that destroyed 54 real backups started from the directory. This starts from our own records —
rows claiming `optimized` with no pointer — and asks the disk about each one, so the blast radius
is one attachment. The file list is rebuilt from two independent records: the stored `url_map`,
which named every file the conversion replaced, and the original's own basename, since WordPress
names every subsize after it (`drop_foreign_filenames()` prunes the map, so the map alone can be
incomplete). A row whose original is not on disk is **skipped**, not given a manifest that would
fail at restore time.

**It writes pointers and deletes nothing.** That is what makes it safe to offer without a typed
confirmation, and it is why the Backups tab puts *Repair backup records* above *Delete all
backups now*: the purge sweeps exactly the files repair recovers. Recovered backups start a fresh
retention window — the original expiry died with the pointer, and inheriting "keep forever" would
exempt them from the setting the user chose.

**Verified:** 17 new assertions in `tests/php/convert-restore-e2e.php` (85 in that harness, 258
across the suite), passing under CLI PHP and under php-fpm. They assert the thing that matters —
that a recovered backup can actually be restored and the original file comes back under its
original name — not merely that the column looks right. `wp swift-image-optimizer repair-backups`
confirmed registered and running against cb-test.local.

**Not proven:** no run against a real stranded backup. cb-test's backup folder was already empty
by the time this landed, so every orphan tested was one the harness created. The routine reports
"nothing to recover" on that site, which is correct but uninformative. The first site that has
real orphans is the real test.

### I-9 closure detail (no unit — the reasoning lives here)

The issue was right that this was untested, and understated it. It said the purge had "only been
exercised via artificially expired rows"; in fact `JobRunner::purge()` had **no** test coverage
at all. Every backup assertion in the suite went through `BackupController::purge()`, which calls
`purge_manifests()` — the variant Unit 13 added precisely because it *drops* the
`backup_expires > 0` and `status = 'optimized'` filters. So the retention query itself had never
run under test, not once.

The issue also names `RetentionCron::purge()`. There is no `RetentionCron` class; the code lives
in `App\Hooks\Scheduler\JobRunner`. `agent.md` already lists `RetentionCron` among the phantom
classes purged from the graph, so the entry had been stale for some time.

**15 assertions now cover it**, driving `do_action( JobRunner::HOOK )` rather than calling
`JobRunner::purge()` directly — a purge that works but is not wired to its action fails here.
Covered: the hook is registered and the daily event scheduled; a real conversion writes a real
future expiry from `BackupManager::expiry()`; an **unexpired** backup survives a cron run with its
pointer intact; an aged one is deleted from disk, has its pointer cleared and its expiry reset,
while the row stays `optimized` so the image keeps counting toward savings; Restore is correctly
refused afterwards; and a **"keep forever"** backup (`backup_expires = 0`) is untouched, which is
the boundary between `purge()` and `purge_manifests()` and the one most likely to be broken by a
careless edit to either.

The unexpired-survives case is the one worth keeping. A wrong comparison operator in that query —
the single most likely defect in it — deletes every backup on the site on the next cron run, and
nothing else in the suite would have noticed.

**What is still not real:** the backup is aged by moving `backup_expires` into the past. Genuinely
waiting out a 30-day retention window is not something a test can do, so that remains the one
synthetic step. Everything around it — the conversion, the files, the expiry the plugin computed,
the hook firing — is real.

**Result:** 100 assertions in `convert-restore-e2e` (up from 85), passing under CLI PHP and under
php-fpm on cb-test.local (2026-08-16).
