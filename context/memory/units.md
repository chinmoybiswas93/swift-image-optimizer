# Build history

What was built, in order, and what it cost to learn. Current state is in
[../progress-tracker.md](../progress-tracker.md); closed issues are in
[fixed-issues.md](fixed-issues.md).

## Units

| Unit | What it did | Spec |
|---|---|---|
| 01 | Bootstrap, log table, settings, engine abstraction + detection (Imagick → cwebp → GD) | [01-foundation.md](../specs/done/01-foundation.md) |
| 02 | `Optimizer` + `Upload\Interceptor` — converts at `wp_handle_upload` so WordPress builds every subsize as WebP itself | [02-upload-optimization.md](../specs/done/02-upload-optimization.md) |
| 03 | Backups + retention — protected backup dir, path-traversal guard, daily expiry purge | [03-backup-retention.md](../specs/done/03-backup-retention.md) |
| 04 | `UrlMap` + `DatabaseRewriter` + `Fallback404` — serialization-safe rewriting across 5 tables, with dry run | [04-url-rewriting.md](../specs/done/04-url-rewriting.md) |
| 05 | `AttachmentConverter` — convert/restore, Media Library column, row and bulk actions | [05-attachment-conversion.md](../specs/done/05-attachment-conversion.md) |
| 06 | Bulk scanner and runner, REST routes, WP-CLI — adaptive batching, resumable progress | [06-bulk-rest-cli.md](../specs/done/06-bulk-rest-cli.md) |
| 07 | React admin — Bulk / Settings / Backups tabs, `@wordpress/scripts` build | [07-react-admin.md](../specs/done/07-react-admin.md) |
| 08 | Media Library UI — grid toolbar button, selection runner, modal panel; schema v2 | [08-media-library-ui.md](../specs/done/08-media-library-ui.md) |
| 10 | Hardening — fourteen defects, `Logger`, `EnvironmentReport`, `Lock`, Troubleshoot tab, engine chain, schema v3 | [10-hardening-troubleshoot.md](../specs/done/10-hardening-troubleshoot.md) |
| 09 | PHPCS run for the first time: 784 → 0 | [09-phpcs-compliance.md](../specs/done/09-phpcs-compliance.md) |
| 11 | The four user reports (I-10, I-11, I-12, I-13). Harnesses rebuilt and **committed**; Imagick covered (I-2) | **none — see below** |
| 12 | One scan-backed dashboard (I-14). `ScanRunner`, `Coordinator`, `ScanJobRunner`, `ProgressRing`. Invariant 25 | [12-centralized-scan-stats.md](../specs/done/12-centralized-scan-stats.md) |
| 13 | Backups tab: `purge_orphans()` sweeps the backup root, `purge_manifests()` drops the filters that hid "Keep forever" rows, `Modal`/`ConfirmDialog` replace every `window.confirm()` | [13-backup-purge-confirm-modal.md](../specs/done/13-backup-purge-confirm-modal.md) |
| 14 | I-10 (reopened) and I-15 — `ForeignNoticeHandler`, card spacing | [14-notices-and-card-spacing.md](../specs/done/14-notices-and-card-spacing.md) |

> **Unit 11 has no spec file.** `specs/done/` jumps 10 → 12. The write-up below is the only record
> of what it found, and it is the sole closure record for I-2, I-11, I-12 and I-13.

## Unit 11 — the four user reports, and what they turned up

Each report had a cause that was one line of misplaced trust, and two exposed something worse than
what was reported:

| Reported | Actual cause | Also found |
|---|---|---|
| WP notice on the plugin's page | The plugin emitted core's `notice` class itself, and hooked `admin_notices` on **every** admin screen | `BackupsPage` showed failures in a success-styled notice |
| Three folders in uploads | Three sibling constants, never one parent | — |
| Bulk stops on tab change, restarts from scratch | `start()` overwrote live run state; the UI never reconciled `running` on mount | **A crash between "files renamed" and "references repointed" broke those references permanently** — the batch marks images done before rewriting |
| Restored site shows all processed | `already-optimized` trusted the status column with no disk check | Clearing the row does not re-queue for bulk: mime is already `image/webp` |

The pattern in three of the four is the one `manifest_is_intact()` was written for in Unit 10:
**a database column and the thing it describes are two different facts.** Worth looking for
wherever else the plugin trusts a column.

## Unit 10 — fourteen defects found by reading the conversion path

Found by reading, not by a failing test. Every one failed silently, which is why the logger was
built before any of them were fixed. Full detail in
[10-hardening-troubleshoot.md](../specs/done/10-hardening-troubleshoot.md).

**Destroyed data:**

| Bug | Impact | Where |
|---|---|---|
| Upload deleted the original with no backup | A 6000px camera JPEG became a 2560px lossy WebP permanently, with no Restore | `Upload\Interceptor` |
| cwebp never rotated, and stripped the EXIF that said to | Every portrait photo written sideways, permanently — and cwebp is the engine the harnesses actually exercise | `Engine\CwebpEngine` |
| `array_flip()` on a non-injective url_map | Restore rewrote full-size references to a *thumbnail* filename and reported success | `AttachmentConverter::restore` |

**Correctness:**

| Bug | Impact | Where |
|---|---|---|
| Skips and failures were terminal forever | An image skipped for `insufficient-memory` was never retried, even after raising the limit | `Bulk\Scanner` |
| No disk check before backing up | `copy()` failing halfway leaves a truncated file that looks like a valid backup | `Backup\BackupManager` |
| Rewrite matched on filename alone | An attachment named `photo-300x200.jpg` collides with another's thumbnail; converting one broke the other | `AttachmentConverter` |
| Comments never scanned | An image embedded in a comment kept pointing at a deleted file | `Rewrite\DatabaseRewriter` |
| Check-then-set locks | Two requests could both convert the same image | `AttachmentConverter`, `Bulk\Runner` |

**Performance and hygiene:**

| Bug | Impact | Where |
|---|---|---|
| `wp_cache_flush()` per batch | Whole object cache discarded every few seconds during a bulk run | `Rewrite\DatabaseRewriter` |
| ~50 unindexable LIKE terms per batch | Two full scans of `postmeta` per five images | `Rewrite\DatabaseRewriter` |
| 404 fallback was `LIKE` over LONGTEXT, basename-only | Unindexed query per 404 under a bot sweep; wrong image across month folders | `Rewrite\Fallback404` |
| Memory estimate applied to cwebp | Large images refused on hosts that would have handled them, out of process | `Optimizer` |
| Temp files written beside the source | A killed process stranded `swift-tmp-*.webp` in month folders | `Optimizer` |
| APNG flattened, CMYK JPEG marked failed | Animation lost silently; CMYK recorded as a hard failure rather than falling through | `Optimizer`, `Engine\GdEngine` |

## Earlier bugs found during the build

| Bug | Impact | Where |
|---|---|---|
| Soft-error list duplicated and out of sync | 496 images reported as **failed** that were correctly logged as **skipped** | `Bulk\Runner`, `Bulk\Cli` |
| `optimize_ids()` called but never defined | `wp swift-image-optimizer optimize --id=N` would fatal | `Bulk\Cli` |
| Per-row query for the skip check | Tens of thousands of extra queries during bulk | `Rewrite\DatabaseRewriter` |
| Backup expiry zeroed savings stats | Stats under-reported over time | the retention cron |

## Corrections to earlier reporting

Two findings were reported confidently and were both **wrong**. Recorded so they are not repeated:

1. **"496 orphaned attachments"** — an artifact of running harnesses against cb-test's *files*
   with another site's *database*. cb-test has 62 attachments and **0** with missing files. The
   spec built on this was deleted, and the plugin's table and options were removed from the other
   site; no content there was modified.
2. **"Imagick has never executed"** — drawn from a CLI check. The site's web PHP *does* have
   Imagick and it is the active engine in production. The real problem was narrower: the harnesses
   run under CLI PHP, so they exercised cwebp while users get Imagick (I-2).

Both are why `ai-workflow-rules.md` opens with the environment traps: when a result says something
surprising about the *environment* rather than the code, check the environment first.

## Layout: two restructures

**2026-08-09 — feature folders → layered folders.** `src/` was reorganized from `Admin/`, `Rest/`,
`Backup/`, `Bulk/` into `Http/`, `Services/`, `Repositories/`, `Providers/`, with a hand-rolled DI
container and service providers imitating the sibling `swiftlisting` plugin's shape. No hook
names, routes, commands or schema changed.

**2026-08-10 — layered folders → the current FluentCart-style layout.** Superseded the above
entirely: `src/` was replaced by `app/` with a separate `framework/` kernel root, Composer PSR-4
took over from the hand-rolled autoloader, and the eight service providers collapsed into
`app/Hooks/actions.php`. `SettingsRepository` became `api/StoreSettings`; `StatsRepository` became
`api/Resource/StatsResource`.

Worth knowing because **the done specs for units 01–07 still list a `src/` tree that no longer
exists**, and `graphify` may still surface class names from the first layout. Current structure is
in [../architecture.md](../architecture.md); trust that over any spec's file list.

## Tooling

- **Knowledge graph** at `graphify-out/`, rebuilt with `graphify update .`. Current size is in
  `graphify-out/GRAPH_REPORT.md` — no number is repeated here, because every copy of it has gone
  stale within a unit or two.
- **Playwright** for browser e2e (`npm run test:e2e`), config at `playwright.config.js`, tests in
  `tests/e2e/`. Complements the PHP harnesses, which never touch the admin UI.
