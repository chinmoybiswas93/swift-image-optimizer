# Progress Tracker

Update this file after every meaningful implementation change. Keep entries short — full
implementation detail belongs in each unit's spec file (`context/specs/done/NN-*.md`,
"## Completion Notes"), not here.

## Current Phase

**Post-v1.1.0 — hardening before WordPress.org submission**

## Current Goal

Units 01–08 built both features. **Unit 10 closed fourteen defects** found in a full read of the
conversion path — three of which destroyed user data silently — and added the Troubleshoot tab,
file-based logging and server diagnostics. Version bumped to **1.1.0**.

**Unit 09 and Unit 11 are done.** `phpcs` has now actually been run (784 → 0), Imagick is
exercised by the suite for the first time, and the four user-reported issues (I-10…I-13) are
fixed. Both release blockers are closed.

**Unit 12 is done.** Closed I-14 (renumbered from I-11, which collided with Unit 11's own I-11).
The Bulk Optimize tab's numbers never reconciled because they came from two unrelated
computations — `Scanner::summary()`'s live mime count (which loses an image from its own totals
the moment that image is optimized, since its mime becomes `image/webp`) and `StatsResource`'s
log-table aggregate. Replaced both with one stored, disk-verified scan snapshot (`ScanRunner`,
cron-driven, invariant 25) that the merged card's ring, the hero and the tiles all read. Bulk
Optimize now chains scan → optimize → scan via `Coordinator`. Scheduled rescans
(manual/daily/weekly/monthly) via `ScanJobRunner`. 131 PHP assertions passing (up from 68), 0
`phpcs` errors/warnings, `owasp-security-review` clean on the five new routes.

What stands between here and a .org submission now is **I-3: nobody has clicked the dashboard.**
Every change in Unit 11 touched that UI, and Unit 12 rewrote the Bulk tab on top of it without a
browser available to click it either. The build is verified, the endpoints are verified, the PHP
is covered by 131 assertions — but the Bulk, Settings, Backups and Troubleshoot tabs have still
never been used in a browser. Unit 12 wrote `tests/e2e/library-scan.spec.js` for the new surface;
it has never been run against a real login, only confirmed to load and to skip cleanly without
credentials.

**Unit 13 is In Progress** — [13-backup-purge-confirm-modal.md](specs/13-backup-purge-confirm-modal.md).
Two user reports on the Backups tab: "Delete all backups now" confirms, toasts and updates the
number without deleting a single file, and the confirmation is a raw `window.confirm()`. The
deletion is manifest-driven only, so orphaned backups (**I-8**) are unreachable — and on cb-test
*every* log row has an empty `backup_path` while 892 KB sits in the folder, so the button can
never do anything. Adds a guarded sweep of the plugin's own backup root, removes the
`backup_expires > 0` / `status = 'optimized'` filters that also excluded "Keep forever" backups,
and replaces every `window.confirm()` in the plugin with a real modal — typed `DELETE` for the
purge.

Next up: a browser pass over the admin UI (now the largest open item), then the `future-specs/`
backlog.

## Completed

> One line per unit. Full detail lives in each unit's spec under "## Completion Notes".

| Unit | Summary | Spec |
|---|---|---|
| 01 | Bootstrap, PSR-4 autoloader, `Database` log table, `Settings`, engine abstraction + detection (Imagick → cwebp → GD) | [01-foundation.md](specs/done/01-foundation.md) |
| 02 | `Optimizer` + `Upload\Interceptor` — Feature 1. Converts at `wp_handle_upload` so WordPress builds every subsize as WebP itself | [02-upload-optimization.md](specs/done/02-upload-optimization.md) |
| 03 | `BackupManager` + `RetentionCron` — protected backup dir, path-traversal guard, daily expiry purge | [03-backup-retention.md](specs/done/03-backup-retention.md) |
| 04 | `UrlMap` + `DatabaseRewriter` + `Fallback404` — serialization-safe rewriting across 5 tables, with dry run | [04-url-rewriting.md](specs/done/04-url-rewriting.md) |
| 05 | `AttachmentConverter` + `Media\ListTable` — Feature 2 convert/restore, Media Library column, row actions, bulk actions | [05-attachment-conversion.md](specs/done/05-attachment-conversion.md) |
| 06 | `Bulk\Scanner` + `Bulk\Runner` + `Rest\Controller` + `Bulk\Cli` — adaptive batching, resumable progress, 8 REST routes, 3 WP-CLI commands | [06-bulk-rest-cli.md](specs/done/06-bulk-rest-cli.md) |
| 07 | React admin — Bulk / Settings / Backups tabs, `@wordpress/scripts` build, custom webpack entry | [07-react-admin.md](specs/done/07-react-admin.md) |
| 08 | Media Library UI — grid-view Bulk Optimize button, selection runner with progress, modal panel; schema v2 (`conversion_ms`) | [08-media-library-ui.md](specs/done/08-media-library-ui.md) |
| 10 | Hardening — 14 defects closed, `Logging\Logger`, `Diagnostics\EnvironmentReport`, `Support\Lock`, Troubleshoot tab, engine chain, schema v3 (URL lookup table) | [10-hardening-troubleshoot.md](specs/done/10-hardening-troubleshoot.md) |
| 09 | PHPCS actually run for the first time: 784 violations → 0. Dead `safe_mode` check removed, `imagedestroy()` version-gated, SQL annotations fixed (they had never been in effect) | [09-phpcs-compliance.md](specs/done/09-phpcs-compliance.md) |
| 11 | The four user reports: toasts + no core notice markup (I-10), one storage folder (I-11), resumable cron-driven bulk (I-12), optimized state verified against disk (I-13). Harnesses rebuilt and **committed**, Imagick covered (I-2) | — |
| 12 | Closed I-14 — one scan-backed dashboard. `ScanRunner` (batched, disk-verified scan), `Coordinator` (scan→optimize→scan chain), `ScanJobRunner` (scheduled rescans), a hand-rolled `ProgressRing`, and the three-card Bulk tab merged into one. Invariant 25 added | [12-centralized-scan-stats.md](specs/done/12-centralized-scan-stats.md) |

## Next Up

| What | Why |
|---|---|
| **Browser pass over the admin UI (I-3)** | The last thing between here and .org. Units 11 and 12 changed every tab; none of it has been clicked, including Unit 12's merged card and its Playwright spec. |
| Run `tests/e2e/library-scan.spec.js` for real | Written and confirmed to load/skip cleanly, but never run against a live login — no admin credentials were available in the session that wrote it. Needs `WP_ADMIN_USER` / `WP_ADMIN_PASSWORD`. |
| Rebuild the upload and media-UI harnesses | Two of the original five were lost and not rewritten; that is the gap between 150 assertions and the old 143 plus what those covered. |
| Fix the 216 pre-existing `prettier`/`jsdoc` errors `lint:js` now surfaces | First successful `lint:js` run ever (see below) — errors are spread across ~10 files under `resources/`, all pre-existing, none on lines touched by the comment cleanup. Out of scope for that unit; needs its own pass, ideally with `--fix` reviewed file by file since some of those files have other uncommitted work in flight. |

### 2026-08-11 — comment cleanup (no spec, too small to warrant one)

Reworded 5 vacuous `Constructor.` docblocks (`Hooks/CLI/Commands.php`,
`Services/AttachmentConverter.php`, `Services/Bulk/Coordinator.php`, `Services/Bulk/Runner.php`,
`Services/Upload/Interceptor.php`) and removed 4 decorative ASCII banner comments in
`resources/media/media.js`. Added a standing "no decorative banners / no placeholder docblocks / no
commented-out code" rule to `context/code-standards.md` under `## Comments`. No behavior changed.

`phpcs` now runs clean (0 errors/warnings, all 75 files) using the WPCS install already present at
`~/.wpcs` per `specs/done/09-phpcs-compliance.md` — just needed that `vendor/bin` on `PATH`.

`lint:js` was crashing outright before this unit, unrelated to the comment cleanup: the committed
`package-lock.json` let a floating `typescript` peer dependency resolve to `7.0.2`, which
`@typescript-eslint@6.21.0` (pulled in by `@wordpress/eslint-plugin`) cannot load — a version
`@typescript-eslint` was never designed to see. Pinned via a `"typescript": "^5.4.5"` entry in
`package.json`'s new `overrides` block (dev-only, doesn't affect `build/` output), then
`rm -rf node_modules && npm install`. `lint:js` now runs for the first time and reports 216
pre-existing `prettier`/`jsdoc` errors, unrelated to this unit — see Next Up.

## Verified behaviour

Measured on cb-test.local (WP 7.0.3):

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
| Upload path with `backup_uploads` on | `backup_path` populated, manifest intact, image **restored** — impossible before Unit 10 |
| Converter path with logging on | Full trail: lock, backup (7 files, 53836 B), encode (cwebp, 71ms), rename, metadata, 7 deletions by absolute path, done |
| Convert → restore, schema v3 | 7 URL lookup rows created, 0 after restore; file, mime and dimensions all reverted |
| Temp sweep | Abandoned file removed, in-flight file left alone |

**Engine availability differs between CLI and web, and it matters:**

| Context | Imagick | Selected |
|---|---|---|
| Local CLI PHP (7.4 → 8.4) | no | `cwebp` |
| The site's web request | **yes** | `imagick` |

So the harnesses exercise cwebp while the site runs Imagick. Tracked as **I-2**.

## Architectural decisions made during the build

1. **No `JsonRewriter` class.** The original plan called for one to handle Elementor/Bricks
   escaped-slash JSON. It turned out to be unnecessary: `UrlMap::with_escaped_slashes()` adds
   the `https:\/\/` form to the plain map, so a `strtr` over the raw string handles JSON
   without decoding it. Simpler and safer than parse-modify-reencode.

2. **React source lives in `admin/`, not `src/`.** `src/` is PHP. `wp-scripts` insists on
   `src/index.js` by default, so `webpack.config.js` overrides the entry point rather than
   mixing JS into the PHP tree.

3. **Backup expiry does not change `status`.** Originally it set a `backup_expired` status,
   which silently removed that image from the savings stats. Availability is now signalled by
   an empty `backup_path`. The `STATUS_BACKUP_EXPIRED` constant was removed.

4. **Soft-error classification is centralized** in `AttachmentConverter::soft_errors()` — split
   in Unit 10 into `PERMANENT_SKIPS` and `RETRYABLE_SKIPS`, still a single definition. It was
   duplicated across `Runner`, `Cli` and `log_failure()` and had already drifted out of sync.

5. **The rewriter fetches the filter column in its main SELECT.** It originally ran a separate
   query per matched row to check the skip list — tens of thousands of round trips on a real
   library. The skip check also moved *before* the counting, so dry-run numbers now match what
   a real run would do.

6. **Custom toolbar buttons rely on core skipping `.media-button`.** `SelectModeToggle`'s
   show/hide never touches elements carrying that class, in either direction
   (`media-grid.js:337` and `:350`). That is what allows an always-visible button beside "Bulk
   select" without patching core — and why both new buttons manage their own `hidden` class.

7. ~~**Upload-optimized images have no backup.**~~ **Resolved in Unit 10.** The open product
   question was settled with the user: uploads are backed up too, behind a `backup_uploads`
   setting defaulting to on. `Interceptor` writes the manifest in the same shape the converter
   path uses, so Restore works for uploads without a single change to the restore code. The
   storage cost is real and is now stated plainly in `readme.txt` rather than left implicit.

8. **Engine selection is a chain, not a choice** (Unit 10). `EngineFactory::get()` used to
   return one engine and a file it could not handle became a failure. It now returns the first
   of a chain, and `Optimizer` walks it — which is what lets cwebp decline an EXIF-rotated JPEG
   it would write sideways, and lets a CMYK JPEG that stops GD dead fall through to Imagick. An
   explicit engine preference moves that engine to the front rather than replacing the chain,
   so the fallbacks still apply.

9. **Locks are options, not transients** (Unit 10). `get_transient()` then `set_transient()` is
   check-then-set: two simultaneous requests both see "unlocked". `Support\Lock` uses
   `add_option()`, whose unique key on `option_name` makes the database arbitrate. The stored
   value is the acquisition time, so a lock orphaned by a fatal can be broken rather than
   blocking that image for good.

10. **The rewriter invalidates precisely** (Unit 10). It used to call `wp_cache_flush()` once
    per batch, which on a site with a persistent object cache discards everything every few
    seconds — on exactly the large sites that can least afford it. Table descriptors now carry
    an `object` column and a cache `group`, collected in the existing SELECT, so only what was
    rewritten is dropped.

## Bugs found and fixed during the build

| Bug | Impact | Where |
|---|---|---|
| Soft-error list duplicated and out of sync | 496 images reported as **failed** that were correctly logged as **skipped** | `Bulk\Runner`, `Bulk\Cli` |
| `Cli::optimize_ids()` called but never defined | `wp swift-image-optimizer optimize --id=N` would fatal | `Bulk\Cli` |
| Per-row query for the skip check | Tens of thousands of extra queries during bulk | `Rewrite\DatabaseRewriter` |
| Backup expiry zeroed savings stats | Stats under-reported over time | `Backup\RetentionCron` |

## Unit 10 — fourteen defects found by reading the conversion path

Found by reading, not by a failing test. Every one failed silently, which is why the logger was
built before any of them were fixed. Full detail in
[specs/done/10-hardening-troubleshoot.md](specs/done/10-hardening-troubleshoot.md).

**Destroyed data:**

| Bug | Impact | Where |
|---|---|---|
| Upload deleted the original with no backup | A 6000px camera JPEG became a 2560px lossy WebP permanently, with no Restore | `Upload\Interceptor` |
| cwebp never rotated, and stripped the EXIF that said to | Every portrait photo written sideways, permanently — and cwebp is the engine the harnesses actually exercise (**I-2**) | `Engine\CwebpEngine` |
| `array_flip()` on a non-injective url_map | Restore rewrote full-size references to a *thumbnail* filename and reported success | `AttachmentConverter::restore` |

**Correctness:**

| Bug | Impact | Where |
|---|---|---|
| Skips and failures were terminal forever | An image skipped for `insufficient-memory` was never retried, even after raising the limit | `Bulk\Scanner` |
| No disk check before backing up | `copy()` failing halfway leaves a truncated file that looks like a valid backup | `Backup\BackupManager` |
| Rewrite matched on filename alone | An attachment literally named `photo-300x200.jpg` collides with another's thumbnail; converting one broke the other | `AttachmentConverter` |
| Comments never scanned | An image embedded in a comment kept pointing at a deleted file | `Rewrite\DatabaseRewriter` |
| Check-then-set locks | Two requests could both convert the same image | `AttachmentConverter`, `Bulk\Runner` |

**Performance and hygiene:**

| Bug | Impact | Where |
|---|---|---|
| `wp_cache_flush()` per batch | Whole object cache discarded every few seconds during a bulk run | `Rewrite\DatabaseRewriter` |
| ~50 unindexable LIKE terms per batch | Two full scans of `postmeta` per five images | `Rewrite\DatabaseRewriter` |
| 404 fallback was `LIKE` over LONGTEXT, basename-only | Unindexed query per 404 under a bot sweep; wrong image across month folders | `Rewrite\Fallback404` |
| Memory estimate applied to cwebp | Large images refused on hosts that would have handled them, out of process | `Optimizer` |
| Temp files written beside the source | A killed process stranded `swift-tmp-*.webp` in month folders, picked up by media scanners and backups | `Optimizer` |
| APNG flattened, CMYK JPEG marked failed | Animation lost silently; CMYK recorded as a hard failure rather than falling through | `Optimizer`, `Engine\GdEngine` |

## Corrections to earlier reporting

Two findings were reported confidently in this tracker and were both **wrong**. Recorded here
so they are not repeated:

1. **"496 orphaned attachments"** — an artifact of running harnesses against cb-test's *files*
   with TuFlamenco's *database* (Local names every DB `local`; only the socket differs).
   cb-test has 62 attachments and **0** with missing files. The Unit 10 spec built on this has
   been deleted, and the plugin's table/options were removed from TuFlamenco. No content there
   was modified.

2. **"Imagick has never executed"** — drawn from a CLI check. The site's web PHP *does* have
   Imagick, and it is the active engine in production. The real problem is different and
   narrower: the harnesses run under CLI PHP, so they exercise cwebp while users get Imagick
   (**I-2**).

A test harness also **destroyed 54 real backups** with a glob-based cleanup. See the incident
note in [current-issues/fix-plan.md](current-issues/fix-plan.md).

## Unit 11 — the four user reports, and what they turned up

Each report had a cause that was one line of misplaced trust, and two of them exposed something
worse than what was reported:

| Reported | Actual cause | Also found |
|---|---|---|
| WP notice on the plugin's page | The plugin emitted core's `notice` class itself, and hooked `admin_notices` on **every** admin screen | `BackupsPage` showed failures in a success-styled notice |
| Three folders in uploads | Three sibling constants, never one parent | — |
| Bulk stops on tab change, restarts from scratch | `start()` overwrote live run state; the UI never reconciled `running` on mount | **A crash between "files renamed" and "references repointed" broke those references permanently** — the batch marks images done before rewriting |
| Restored site shows all processed | `already-optimized` trusted the status column with no disk check | Clearing the row does not re-queue for bulk: mime is already `image/webp` |

The pattern in three of the four is the same one `manifest_is_intact()` was written for in Unit
10: **a database column and the thing it describes are two different facts.** Worth looking for
wherever else the plugin trusts a column.

## Open Questions

1. ~~**The suite never exercises Imagick.**~~ Closed. `tests/php/run.sh --web` runs the harnesses
   under the site's php-fpm, where Imagick exists; `SIO_TEST_ENGINE` forces one per run and the
   harness asserts on the engine actually recorded, so "used" is proven rather than assumed.
2. ~~**Should uploads be backed up?**~~ Settled in Unit 10: yes, behind `backup_uploads`,
   default on.
3. **Dry-run extrapolation is a sample.** `Runner::dry_run()` inspects 25 attachments and
   scales linearly. Acceptable as a "roughly how much will change" signal; do not present it as
   exact.
4. **Multisite is unconsidered.** No testing, no per-site table handling reviewed. Unit 10 added
   a second table (`swift_image_optimizer_urls`) built from `$wpdb->prefix`, so it follows the
   same per-site pattern as the log table — but that is inference, not testing.
5. **The Troubleshoot tab is only partly browser-verified.** The Enable Log toggle is confirmed
   working live (the transition hook's `MARK` line is in the log). The diagnostics table, log
   viewer, Download, Reset, Requeue and Clean up buttons have not been clicked.

## Restructure 2026-08-09: feature folders → layered folders

Reorganized `src/` from feature-based folders (`Admin/`, `Rest/`, `Backup/`, `Bulk/`, `Rewrite/`,
`Upload/`, `Engine/`) to layered folders (`Http/`, `Services/`, `Repositories/`, `Providers/`,
`Hooks/`), matching the sibling `swiftlisting` plugin's architectural style. Pure restructure —
no hook names, REST routes, WP-CLI commands, or DB schema changed. Full mapping in
`context/architecture.md`'s "Class map".

Key moves: `Admin\Settings` → `Repositories\SettingsRepository`, `Stats` →
`Repositories\StatsRepository`, `Rest\Controller` → `Http\Controllers\Controller`,
`Media\ListTable` → `Http\Admin\ListTable`, `Database` (root) → sibling `database/Database.php`
(namespace `SwiftImageOptimizer\Database`), everything else moved under `Services\` or
`Hooks\Scheduler\` with namespaces unchanged in name, only in location.

**Added, dependency-free:** `Support\Container` / `Support\App` (minimal DI container + static
facade) and `Providers\ServiceProvider` / `Providers\PluginBootstrapper`, imitating the *shape*
of `swiftlisting`'s framework (`register()` on every provider, then `boot()` on every provider)
without depending on it — no Composer was added, the plugin still boots via its own hand-rolled
PSR-4-lite autoloader in `swift-image-optimizer.php` (now mapping two roots: `src/` and, for the
`Database` namespace only, the sibling `database/`). `Plugin.php` is now a thin
`PluginBootstrapper::create()->providers([...])->boot()` call instead of a constructor that
directly `new`s every component.

One deliberate deviation from `swiftlisting`'s exact `PluginBootstrapper` behavior: swiftlisting
defers every provider's `boot()` to a `plugins_loaded` hook because its bootstrapper runs at
plugin-file top level. This plugin's entry point (`swift_image_optimizer()`) is *itself* already
hooked to `plugins_loaded`, so deferring boot() to another `plugins_loaded` add_action would be
unreliable (that hook may already be mid-fire). `PluginBootstrapper::boot()` here runs
register-then-boot on every provider synchronously instead — same two-phase ordering guarantee,
correct for this plugin's actual call site.

Verified via `php -l` on every touched file, three structural sweeps (no corrupted/stale FQCNs,
every `use` import resolves to a real file, every class/namespace matches its file path), and a
full runtime smoke test that boots the plugin end-to-end with stubbed WordPress functions —
every hook fired in the same order as the original code. `swiftlisting` itself was never
modified — reference only.

Also added `bin/build-dist.sh` (modeled on `swiftlisting`'s own build script, minus the Composer
steps this plugin doesn't need) to produce an org-ready `dist/swift-image-optimizer-<version>.zip`.

## Tooling added 2026-08-08

- **Knowledge graph** at `graphify-out/` (391 nodes, 498 edges, 56 communities) covering all
  59 source/doc files. Query with `graphify query "..."` before reading files directly — see
  "Graph-first research" in `context/ai-workflow-rules.md`. Re-run `/graphify --update` after
  units that add/change files.
- **Playwright** added as a devDependency for browser-level e2e testing (`npm run test:e2e`),
  config at `playwright.config.js`, tests in `tests/e2e/`. Complements the four PHP harnesses,
  which don't touch the admin UI. See "Browser (e2e) testing with Playwright" in
  `context/ai-workflow-rules.md`.
