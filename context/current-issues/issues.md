# Current Issues

Known gaps. Nothing here is a live bug in shipped behaviour — these are unverified paths,
environment limits and untested surfaces.

Last reviewed: 2026-08-11, after Unit 11 (the I-10…I-13 user reports, plus Unit 09).

**Closed in Unit 11:** I-1 (phpcs finally run, 784 → 0), I-2 (Imagick exercised at last),
I-10, I-11, I-12, I-13. Open: I-3, I-4, I-6, I-7, I-8, I-9.

Unit 10 is a caution about this file. It closed fourteen defects that were **not** listed here,
three of which destroyed user data, and all of which were found by reading the conversion path
rather than by any test or any entry below. An empty issues list means nobody has looked
recently, not that nothing is wrong.

---

## I-1 — PHPCS has never been run · **CLOSED**

**Severity:** ~~High~~ Resolved 2026-08-11

WPCS is now installed **outside the plugin tree** — the committed `vendor/` must hold nothing but
the generated autoloader (invariant 12), so a `require-dev` would have shipped seven dev packages
unless every future build remembered `--no-dev`. `npm run lint:php` uses `phpcs` from `PATH` and
prints a pointer to the spec when it is missing.

First run: **784 violations. Now 0.** 576 were pure formatting (phpcbf, every file re-parsed).

Real defects it caught:

- `CwebpEngine` tested `ini_get('safe_mode')`, removed from PHP in 5.4 — dead code on a 7.4 floor.
- `imagedestroy()` is deprecated as of PHP 8.4, so 8.4+ users were accumulating deprecation
  notices; it is also the only thing that frees the resource on 7.4. Centralised in
  `GdEngine::free_image()` and version-gated so neither end of the range regresses.
- Two `count()` calls hoisted out of loop conditions.

**The interesting one:** every direct query already carried a justification comment, but
`phpcs:ignore` only covers the *next* line and the violations sit inside multi-line statements —
so not one of them was in effect. They were written correctly and never verified, because the
linter had never run. Now `disable`/`enable` blocks.

Justified exclusions, each with its reasoning in `phpcs.xml.dist`: PSR-4 file naming (renaming
breaks the autoloader at runtime), direct filesystem calls (WP_Filesystem is wrong for binary
image data and a tailable log), hook names owned by core and Elementor, framework exception
messages, and `tests/` (never shipped).

**Spec corrected too** — [specs/09-phpcs-compliance.md](../specs/done/09-phpcs-compliance.md) said to
add `vendor/` to `.gitignore` "(already done)". Both halves were wrong and would have broken the
shipped autoloader. It also claimed 107 assertions across four harnesses and referenced a `src/`
directory that no longer exists.

---

## I-2 — The test suite exercises a different engine than the site runs · **CLOSED**

**Severity:** ~~High~~ Resolved 2026-08-11

**Closed.** The harnesses can now run under the site's own php-fpm via
`tests/php/run.sh --web`, where Imagick is present, and the engine is forced per run with
`SIO_TEST_ENGINE`. All three engines are exercised and each is confirmed *used* rather than
merely first in the chain — the harness asserts on the `engine` recorded in the log row:

| Run | Chain | Engine recorded | Result |
|---|---|---|---|
| `SIO_TEST_ENGINE=imagick` | imagick → cwebp → gd | `imagick` | 38/38 |
| `SIO_TEST_ENGINE=cwebp` | cwebp → imagick → gd | `cwebp` | 38/38 |
| `SIO_TEST_ENGINE=gd` | gd → imagick → cwebp | `gd` | 38/38 |

Crucially the assertions are on **produced output**, not return values: the portrait fixture is
re-decoded from disk and its real dimensions checked, and the restored file is compared
byte-for-byte against the source. Checking only the return value is exactly how the cwebp
rotation bug survived.

The environment split itself is unchanged and still worth knowing about — it is a property of
Local, not a defect:

| Context | Imagick | Engine actually used |
|---|---|---|
| Local's **CLI** PHP (all builds 7.4 → 8.5) | absent | `cwebp` |
| The site's **web/php-fpm** request | **present** | `imagick` |

So a plain `npm run test:php` still exercises cwebp. Use `--web` when the change touches an
engine. Verified directly rather than assumed: a probe served through php-fpm reported
`{"sapi":"fpm-fcgi","imagick":true}` while the same file under CLI reported `imagick:false`.

<details>
<summary>Original entry, for the record</summary>

**Severity:** High

The environment has a split that produced a materially wrong report earlier in this project:

| Context | Imagick | Engine actually used |
|---|---|---|
| Local's **CLI** PHP (all builds 7.4 → 8.4) | absent | `cwebp` |
| The site's **web/php-fpm** request | **present** | `imagick` |

Every harness runs under CLI PHP, so **all 143 assertions exercise the cwebp path**. The site
itself runs Imagick. Confirmed via `/scan` from the browser:
`{"imagick":true,"cwebp":true,"gd":true}`.

This supersedes the earlier claim that "ImagickEngine has never executed" — it has, in
production on cb-test, producing a correct 92.1% conversion. But it remains **untested by the
suite**.

**Fix:** force the engine per-run in the harnesses (`Settings::engine`) and run each suite three
times, once per engine. Until then, do not describe Imagick as covered by tests.

**Unit 10 update — this gap has now cost us something concrete.** `CwebpEngine` never applied
EXIF orientation and passed `-metadata icc`, which discards the orientation flag, so every
portrait photo it converted was written permanently sideways. It survived because cwebp is the
engine the harnesses run and *nobody looked at the output*, only at the return value. The bug is
fixed (the engine now declines rotated JPEGs and the chain falls through), and it was reproduced
and verified live on cb-test — but the lesson stands: exercising an engine is not the same as
checking what it produced. Unit 10's live verification covered `cwebp` and `gd`. **Imagick is
still uncovered.**

</details>

---

## I-3 — The React dashboard has never been opened in a browser

**Severity:** Medium

Unit 08's media UI is now browser-verified, but the **Bulk Optimize dashboard** (Unit 07) is
not. Its build, asset manifest, enqueue path and every REST endpoint it calls are verified —
but nobody has clicked anything.

Unverified: tab switching, dry-run report rendering, live progress, stop actually stopping,
settings persisting through `/wp/v2/settings`, and the confirm dialogs.

**Fix:** a manual pass. The media UI check took about ten minutes and found the environment
issue above, so this is worth doing properly.

**Unit 10 update:** the settings write path *is* now confirmed live — toggling Enable Log on the
new Troubleshoot tab saved through `/wp/v2/settings` and fired the transition hook, whose `MARK`
line is in the log file. Everything else in this entry still stands, and Unit 10 added a fourth
tab's worth of unclicked surface: the diagnostics table, the log viewer, Download, Reset,
Requeue and Clean up.

---

## I-4 — WP-CLI commands are partly verified at runtime

**Severity:** Low (was Medium)

**Mostly closed in Unit 10.** WP-CLI turned out to be available after all, via Local's bundled
phar driven by Local's PHP with the site's MySQL socket:

```bash
PHP="/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
WP="/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/posix/wp"
SOCK="$HOME/Library/Application Support/Local/run/aRpCXvFUz/mysql/mysqld.sock"
"$PHP" -d mysqli.default_socket="$SOCK" "$WP" swift-image-optimizer diagnostics
```

Confirm `option get siteurl` returns `https://cb-test.local` before trusting anything — that
command pairing is the guard against the two-database trap in [fix-plan.md](fix-plan.md).

Exercised live: `optimize --id`, `restore --id`, `stats`, `diagnostics`, `logs`,
`logs --reset`, `requeue`. **Not yet exercised:** `optimize --all`, `optimize --dry-run`,
`restore --all`, and the `--limit` / `--batch` flags — which is to say the bulk paths, still the
recommended route for large libraries.

---

## I-6 — Multisite is unconsidered

**Severity:** Unknown

No testing, no review of per-site table handling, no thought given to network activation or
shared uploads directories. The plugin may work fine or may be actively wrong.

**Fix:** either test and support it, or declare it unsupported in `readme.txt`. Silence is the
worst option.

---

## I-7 — Dry-run extrapolation is a linear estimate

**Severity:** Low

`Bulk\Runner::dry_run()` samples 25 attachments and scales the reference count linearly. On a
library with uneven reference density — a few images used everywhere, most used nowhere — that
estimate will be well off.

Acceptable as a "roughly how much will change" signal. The UI says "Estimated", which is
correct. Keep it that way.

---

## I-8 — A backup manifest can become unreachable, with no repair path

**Severity:** Low

**Half of this closed in Unit 10.** The direction that had actually bitten us — files vanishing
while the column still advertised them, leaving `canRestore: true` on a Restore that would fail
— is fixed. `BackupManager::manifest_is_intact()` checks every named file on disk, and both the
media modal and the list-table row action now ask it rather than trusting the column. That was
the concrete case behind the incident in [fix-plan.md](fix-plan.md).

**What remains:** `backup_path` still stores `{relative_dir, files[], expires}` as JSON in a TEXT
column. If that value is truncated or malformed, the files are on disk but unreachable, and
there is no routine that reconciles the two. Restore now fails cleanly with `backup-expired`
instead of misleading the user, so this is a recoverability gap rather than a correctness one.

**Fix when it matters:** a reconcile routine that walks the backup directory and rebuilds
manifests from what is actually there.

---

## I-9 — Time-based backup expiry is untested with real aged data

**Severity:** Low

`RetentionCron::purge()` has only been exercised via artificially expired rows. A backup that
genuinely aged past its retention window has never been observed being collected.

**Fix:** set `backup_expires` to a past timestamp on a real backup and run the cron hook.

---

## Resolved

| Was | Outcome |
|---|---|
| **Media grid modal shows nothing** | Fixed in Unit 08; verified live in all three panel states |
| **ImagickEngine has never executed** | False — it is the production engine on cb-test. Reframed as I-2, which is the real (and different) problem |
| **496 orphaned attachments** | **Invalid finding.** An artifact of testing cb-test's *files* against TuFlamenco's *database*. cb-test has 62 attachments and 0 missing files. The spec built on this has been deleted |
| **I-5 — upload-optimized images cannot be restored** | Settled with the user in Unit 10: uploads are backed up too, behind `backup_uploads`, default on. Restore works for them with no change to the restore code |
| **I-8, the half that had actually bitten us** | `canRestore` now verifies the files exist instead of trusting the column. The remaining half — an unreachable manifest with no repair path — is still open under I-8 |



## I-10 — WordPress default admin notices leak into the plugin's page · **CLOSED**

**Severity:** ~~Unspecified~~ Resolved 2026-08-11

The WordPress-looking notice was the plugin's own doing.
`app/Views/admin/parts/notice.php` deliberately emitted core's bare `notice` class alongside the
`sio-*` ones, to borrow two behaviours: WordPress repositions elements carrying it, and binds
dismissal to `is-dismissible`. Borrowing them also meant inheriting core's notice styling — which
is precisely what the hard rule against `notice notice-*` exists to prevent.

Both classes dropped. Position is now `.sio-notice--standalone`. There is deliberately **no
dismiss button**: the two bootstrap notices (no engine, assets not built) describe conditions
that are still true after being dismissed, and the Media Library result notice is gone on the
next page load. Adding one meant inline JavaScript, or a script not guaranteed to have loaded, to
hide a message that should not be hidden.

`NoticeHandler` also hooked `admin_notices` on **every** admin screen with no guard, so the
plugin was interrupting people doing unrelated work — the behaviour it objects to in other
plugins. Scoped to its own page, the Media Library, and the plugins list.

New `Toast` component and provider (`resources/admin/Components/Toast.jsx`) for transient
feedback, which is what a notice was being misused for. No `@wordpress/components`, no toast
library. Errors do not auto-dismiss. Converted: settings saved, log cleared, requeue, rescan,
cleanup, diagnostics copied, backups purged.

Bug found in passing: `BackupsPage` reported failures through the same info-styled notice as
successes, so a failed purge read like a completed one.

**Still unverified in a browser** — that is I-3.

---

## I-11 — Three storage folders, not one · **CLOSED**

**Severity:** ~~Unspecified~~ Resolved 2026-08-11

Confirmed as a real defect, not just something to check: the plugin created three siblings under
`wp-content/uploads/` — `swift-image-optimizer-backups`, `-tmp` and `-logs`, each from its own
constant. Now one `swift-image-optimizer/` folder with `backup`, `temp` and `logs` inside it.

Three constants, no new machinery. `wp_mkdir_p()` already creates intermediate directories, and
`BackupManager::safe_path()` resolves through `realpath()` against `root()`, so the traversal
guard works unchanged at the extra depth — it was **not** relaxed to make a path resolve.

No migration: the plugin is not in production anywhere, confirmed before the change. The legacy
directories are left exactly where they are. Nothing here deletes by glob over a shared uploads
directory.

`uninstall.php` follows the new paths and still spares backups — it removes logs and temp, then
attempts the parent, which `rmdir()` refuses while anything remains.

---

## I-12 — Bulk optimize was not resumable or asynchronous · **CLOSED**

**Severity:** ~~Unspecified~~ Resolved 2026-08-11

Three reported symptoms, one cause: nothing on the server owned the run.

`start()` overwrote the progress option unconditionally, so a second tab — or the same tab after
a reload — could reset `run_id`, cursor and every counter out from under a batch in flight. The
frontend made it reachable: the mount effect set progress but never `running`, so a live run
rendered the **Start** button as though nothing were happening. Clicking it reset the run, the
in-flight batch still held the lock, and the next call returned "already running". The button and
the error were both telling the truth about different pieces of state.

- `start()` is idempotent. Live run → handed back untouched. Stopped run with progress → resumes
  at its cursor. Only `fresh` starts over, and it is ignored while a run is live, because a batch
  in flight would overwrite the reset when it saves. Restarting is Stop, then Start.
- `cancel()` keeps the cursor and counters. **Stop means pause.**
- `BulkJobRunner` advances the run from WP-Cron, so it survives the tab closing. It re-arms a
  single event per batch (batch size is adaptive) and calls `process_batch()` in-process — never
  over HTTP, which invariant 13 forbids.
- `state()` computes `percent`, `resumable`, `stalled`, `cron_next`. The client used to do its
  own arithmetic; two clients over different snapshots is why the figures disagreed mid-run.
- `raw_state()` splits stored from presented state, so derived fields are never persisted.

**Latent data bug found while designing this, fixed here.** A batch renames files and writes
terminal log rows per image, then repoints references once at the end. Die in between and those
images are marked done forever with references pointing at filenames that no longer exist —
`Scanner` treats a terminal row as finished, so nothing revisits them. Survivable when a batch
was one foreground request; not once batches run unattended from cron. The map is now parked
before the rewrite and flushed by whichever worker arrives next.

**Verified live:** a run left at done=1/pending=3 with no browser attached advanced to
done=4/pending=0 from a single `wp-cron.php` hit, then unscheduled itself.

**The honest limit, surfaced rather than hidden:** WP-Cron only fires on an incoming request, so
on a quiet site with the tab closed a run does stall. That is what `stalled` and the warning
notice say. A real system cron is the answer for large libraries.

---

## I-13 — A restored site reported every image as optimized · **CLOSED**

**Severity:** ~~Unspecified~~ Resolved 2026-08-11

`do_convert()` refused with `already-optimized` purely on the log row's status column, and the
Media Library read the same column. A backup restore can put the database and the uploads
directory at different points in time, so rows survive while the WebP files they describe do not.
The column and reality disagreed, and the column was winning.

Same class of bug as the one `manifest_is_intact()` was written for, at the other end of the
pipeline — so, the same treatment. `AttachmentConverter::optimized_output_exists()` asks the
disk: the attached file must exist, and where the row names an output, the attached file has to
*be* that output. The second check matters, because after a restore the original can be back
under its old name while the row still describes the WebP.

Applied at the four places trusting the column: the convert gate, the Media Library column, the
row actions, and `canOptimize`/`canRestore`. A stale row found during convert is dropped and the
conversion proceeds; a row whose file is intact is never touched.

Library-wide counts stay one SQL join — file existence cannot be expressed in SQL, and a stat per
attachment would punish exactly the large libraries bulk exists to serve. Reconciliation is
explicit: `Scanner::rescan()`, as `POST rescan`, `wp swift-image-optimizer rescan`, and a
Troubleshoot button. It deletes rather than restatuses, following `requeue()` and keeping
invariant 9.

**Worth knowing, and asserted rather than left implicit:** clearing the row stops the false claim
and unblocks Optimize, but does **not** return the attachment to the *bulk* queue. Bulk selects
on mime, and a converted attachment is recorded as `image/webp`.

Reproduced in `convert-restore-e2e`. Not yet re-checked against the user's own tuflamenco.dev
install — worth doing, with the socket confirmed first.
