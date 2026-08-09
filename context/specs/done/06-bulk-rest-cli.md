# Unit 06 — Bulk, REST and WP-CLI

## Goal

Run Unit 05's conversion across an entire Media Library without timing out, and expose it over
REST for the admin UI and over WP-CLI for libraries too large for a browser.

## Read first

- `src/AttachmentConverter.php` — particularly the `$defer_rewrite` parameter
- `src/Database.php` — the log table drives the "what is left" query

## Files changed

| File | Purpose |
|---|---|
| `src/Bulk/Scanner.php` | Finds outstanding work |
| `src/Bulk/Runner.php` | Batching, locking, resumable progress, dry run |
| `src/Bulk/Cli.php` | `optimize` / `restore` / `stats` commands |
| `src/Rest/Controller.php` | 8 routes under `swift-image-optimizer/v1` |

## Scanner

A `LEFT JOIN` from `posts` against the log table, filtering on convertible mime types and
excluding rows already `optimized` / `skipped` / `failed`.

Chosen over the two obvious alternatives: `NOT IN (subquery)` degrades badly as the log grows,
and building a list of IDs up front means a ~100 KB option for this library and no resumability
if settings change mid-run. The join is fast, always current, and stateless.

Paging is by `ID > cursor`, never `OFFSET`.

## Runner

Two things make bulk work at scale:

**Adaptive batch size.** `next_batch_size()` measures elapsed time and targets half of
`max_execution_time`, clamped to 1–20. A slow host degrades to one image per request instead of
timing out. `max_execution_time = 0` (CLI) assumes a 15 s budget.

**Deferred rewriting.** Every image in a batch is converted with `$defer_rewrite = true`, the
maps are merged, and `DatabaseRewriter::replace()` runs **once per batch**. One table scan
instead of one per image.

State lives in the `swift_image_optimizer_bulk_progress` option, so closing the tab and
returning resumes. A `swift_image_optimizer_bulk_lock` transient stops two admins colliding.
Only the last 50 errors are kept so the option cannot grow unbounded.

## Dry run

Samples the first 25 pending attachments, builds the URL map they *would* produce, and runs the
rewriter in dry-run mode. Reports per-table counts and extrapolates linearly to the full
pending count.

The extrapolation is an estimate and should be presented as one — a library with uneven
reference density will not scale linearly.

## REST routes

```
GET  /scan             library summary + engine availability + backup size
GET  /stats            savings aggregate
POST /dry-run          reference-change report, writes nothing
POST /optimize         { ids: [] }   also serves the single-image row action
POST /restore          { ids: [] }
POST /bulk/start|batch|status|cancel
POST /backups/purge
```

`manage_options` for everything except `/optimize` and `/restore`, which accept
`upload_files` + `edit_posts`.

## WP-CLI

```bash
wp swift-image-optimizer optimize --dry-run
wp swift-image-optimizer optimize --all [--limit=N] [--batch=25]
wp swift-image-optimizer optimize --id=123
wp swift-image-optimizer restore --all|--id=123
wp swift-image-optimizer stats
```

This is the recommended path for a large library: no execution limit, no browser tab.

## Completion Notes

`bulk-e2e.php` — **29 assertions, all passing**. Verified: container wiring, all 8 REST routes
registered, media hooks registered, scanner paging, dry run writes nothing, full run completes,
batch rewriting repoints references, pending count reaches zero, and all images restore.

Run against the real library: **499 convertible attachments, 26 batches**, adaptive sizing
grew from 5 to 20.

### Two bugs found here

**1. Soft-error list duplicated and out of sync.** The first run reported **496 failures**. The
log table said `skipped/missing-file` for all of them — they are orphaned attachment rows whose
files are not on disk. `Runner` had its own copy of the soft-error list which omitted
`missing-file`, so it classified them as failures and pushed 496 entries into the error array.

Fixed by centralizing the list in `AttachmentConverter::SOFT_ERRORS` and having `Runner`,
`Cli` and `log_failure()` all defer to it. **Do not re-inline this list anywhere.**

Worth noting how close this came to shipping: the numbers looked plausible and the run
completed successfully. It was only wrong in classification.

**2. `Cli::optimize_ids()` called but never defined.** `optimize --id=N` would have fatalled.
Caught by IDE diagnostics, not by tests — no harness exercises the CLI, since WP-CLI is not
installed here. **The CLI commands remain untested at runtime.**

### Note on the 496

They are a pre-existing data problem in this dev install, not something the plugin caused.
Handled correctly, but worth surfacing to users — drafted as Unit 10.
