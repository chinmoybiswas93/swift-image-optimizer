# Unit 10 — Hardening + Troubleshoot Tab

## Goal

Close fourteen defects found in a full read of the conversion path, three of which destroyed
user data silently, and give the plugin a way to explain itself when something goes wrong.

Ordered deliberately: **visibility first, then fixes**. Every one of the fourteen failed
quietly, and there was no way — for a site owner or for us — to see what had happened to a
specific image. The logger and the Troubleshoot tab were built first so each fix could be
proven from real output rather than asserted.

## Decisions taken with the user before building

| Decision | Choice |
|---|---|
| Upload originals | New `backup_uploads` setting, **default on**, same retention as the converter path |
| Log format | WordPress `debug.log` style plain text, in a protected uploads subdirectory |
| Log size | 10MB cap, one `.1` rollover — never more than ~20MB on disk |
| When logging is off | Errors still written; the per-file trail only when enabled |
| Stuck skipped/failed rows | Explicit **Requeue** button, no automatic retry |
| Tab order | Bulk · Settings · Backups · **Troubleshoot** |

## Part 1 — Logging and diagnostics

### `Services\Logging\Logger` (new)

Static and dependency free, because it is called from the upload handler, the cron, WP-CLI and
REST — contexts where the container may not exist. Never touches the database: a conversion
that goes wrong tends to go wrong on disk, and a log living in the database the rewriter is
halfway through modifying is the last place it should be.

- `uploads/swift-image-optimizer-logs/swift-image-optimizer-<suffix>.log`, hardened the same way
  `BackupManager::ensure_root()` hardens backups, plus a per-site random suffix because hosts
  running nginx ignore `.htaccess` entirely.
- Levels `ERROR` / `WARN` / `INFO` / `MARK`. Only `INFO` is gated on the setting, and that rule
  lives in exactly one place: `Logger::should_write()`.
- A **run id** per bulk run, REST call or upload, persisted in the bulk progress option so a run
  spanning many requests still reads as one story.
- `tail()` seeks backwards from the end, so viewing a 10MB log costs the same as an empty one.

### Instrumented steps

Every point where a file can be lost: eligibility verdict, backup (file count and bytes), engine
chosen, encode result with before/after bytes and timing, rename into place, metadata
regeneration, per-table rewrite counts, **every deletion by absolute path**, and the mirror of
all of it on restore. Deletions are logged by absolute path because "which file disappeared" is
the question the log exists to answer.

### `Services\Diagnostics\EnvironmentReport` (new)

Five sections — engines, PHP, filesystem, WordPress, plugin — each row carrying an OK/warn/error
state and, when not OK, a remedy written for a site owner. Includes *why* an engine is
unavailable, not just that it is.

### REST

`/diagnostics`, `/logs`, `/logs/download`, `/logs/reset`, `/cleanup`, `/requeue` — all
`manage_options`. The download streams the file rather than base64ing it into JSON, and is
reached from an `<a>` carrying `_wpnonce`, since apiFetch's nonce middleware cannot sign a link.

### WP-CLI

`diagnostics`, `logs [--lines=N] [--reset]`, `requeue` — for hosts where the admin is
unreachable and the person debugging only has SSH.

## Part 2 — The fourteen fixes

| # | Defect | Fix |
|---|---|---|
| 1 | Upload deleted the original with no backup | `BackupManager::backup_file()` before the delete, gated on `backup_uploads`. Manifest written in the same shape the converter path uses, so Restore works for uploads **without a line of restore code changing**. Fails closed. |
| 2 | cwebp wrote portrait photos sideways | Per-file engine capability. `CwebpEngine::supports_file()` declines a JPEG whose EXIF orientation is not 1; `EngineFactory::chain()` falls through to one that rotates. |
| 3 | Restore could repoint full-size refs at a thumbnail | `reverse_map()` replaces `array_flip()` and drops pairs whose target is not unique — those are UrlMap's deliberate fallbacks and have no correct inverse. Count returned and logged. |
| 4 | `wp_cache_flush()` after every batch | Targeted invalidation from the object ids actually touched, collected in the existing SELECT via a new `object`/`group` descriptor. |
| 5 | ~50 unindexable LIKE terms per batch | Needles reduced to the **filename stem** shared by every size of one image, then de-duplicated by prefix. Only affects which rows are read; the replacement map is untouched. |
| 6 | Comments never scanned | `comments` and `commentmeta` added to `targets()`. |
| 7 | Skips and failures were terminal forever | `SOFT_ERRORS` split into `PERMANENT_SKIPS` and `RETRYABLE_SKIPS`; `Scanner::requeue()` clears the retryable ones on demand. |
| 8 | 404 fallback was an unindexed LIKE over LONGTEXT, basename-only | New `swift_image_optimizer_urls` table (schema v3, backfilled on upgrade), matched on full path first. |
| 9 | Memory estimate applied to out-of-process cwebp | Gated on `decodes_in_process()`. |
| 10 | Temp files leaked into month folders | Own protected directory, swept by the existing daily cron, with a manual button. |
| 11 | No disk check before backup | `has_space_for()` returns a retryable `insufficient-disk` rather than letting `copy()` fail halfway. |
| 12 | Check-then-set transient locks | `Support\Lock` using `add_option()` — the options table's unique key makes the insert atomic. Stale locks broken after a TTL. |
| 13 | APNG flattened, CMYK JPEG recorded as failure | APNG detected by its `acTL` chunk and skipped; CMYK now falls through the engine chain to one that can decode it. |
| 14 | Filename collisions between attachments | One indexed `_wp_attached_file` lookup per conversion; pairs owned by a different attachment are dropped and logged. |

## Verification performed

- `php -l` on every file under PHP 8.2 and 8.5. No PHP 8.0+ only syntax introduced (verified by
  grep), so the 7.4 floor holds.
- `npm run build` clean.
- **47-assertion logic harness**, invoking the real private methods via reflection rather than
  copies: reverse map, stem/needle reduction, lock atomicity and staleness, cwebp orientation
  refusal, `constrain()` regression, logger levels/format/rotation/tail, UrlMap expansion.
- **Live on cb-test.local** via WP-CLI (socket `aRpCXvFUz`, `siteurl` confirmed first):
  - A hand-built JPEG carrying EXIF Orientation=6 — no exiftool here, so the APP1 segment was
    assembled by hand. Plugin output **800x400 upright via gd**; raw cwebp on the same file
    produced **400x800 sideways**, which is the bug, reproduced and fixed side by side.
  - Converter path on a real attachment: full log trail, one run id, seven backups, every
    deletion by path, restore round trip clean, URL rows created and removed.
  - Upload path with `auto_optimize` on: `backup_path: PRESENT`, `manifest_is_intact: YES`, and
    the image **restored** — impossible before this unit. The log shows `engine=gd`, confirming
    the chain skipped cwebp at `wp_handle_upload` time, which is *before* core's own EXIF
    rotation runs.
  - Temp sweep: abandoned file removed, in-flight file left alone.
  - Site returned to its exact pre-test state; test files deleted by explicit path, never by
    glob, per the incident rule in `context/ai-workflow-rules.md`.

## Completion Notes

**Still outstanding.** Imagick was absent from CLI PHP during this work (the I-2 split), so the
engine chain was exercised on `cwebp` and `gd` only. The Imagick branch is unchanged by this
unit except for inheriting `supports_file()`/`decodes_in_process()` defaults, but it has not been
run since. The browser pass on the new tab is also partial: the Enable Log toggle was confirmed
working live — a `MARK` line from the transition hook is in the log — but the diagnostics table,
log viewer, Download, Reset, Requeue and Clean up buttons have not been clicked.

**Design note.** `EngineFactory::get()` was kept as a thin wrapper over `chain()` so the settings
screen, `Notices` and the diagnostics report needed no changes. An explicit engine preference now
moves that engine to the *front* of the chain rather than replacing it, so the fallbacks still
apply to files it cannot handle.

**Restore signature changed.** `AttachmentConverter::restore()` now returns
`array('ambiguous' => int)` instead of `true`. Every caller only tested `is_wp_error()`, so this
is safe, and the REST response now reports when a restore was partial rather than claiming a
clean success.
