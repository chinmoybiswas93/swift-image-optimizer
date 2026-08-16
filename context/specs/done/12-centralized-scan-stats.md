# Unit 12 — One scan-backed dashboard

## Goal

The Bulk Optimize tab reports numbers that cannot be true — *130 convertible / 3 already
processed / 127 still to do*, above a bar reading *369 of 1*. Replace the two disagreeing number
systems with a single **disk-verified scan snapshot** that every figure on the screen reads from,
merge the tab's three cards into one, and make scanning and bulk optimization run in the
background so neither can freeze a request.

Reported as I-11 in `current-issues/issues.md`, renumbered here to **I-14** — Unit 11 already
used I-11 for the storage-folder fix.

## Read first

- `context/architecture.md` — the 24 invariants. This unit lands on 9, 13, 18, 22, 23 and 24
- `app/Services/Bulk/Scanner.php`, `app/Services/Bulk/Runner.php`, `app/Services/Lock.php`
- `app/Hooks/Scheduler/BulkJobRunner.php` — the pattern every new cron driver here copies
- `app/Http/Routes/api.php`, `app/Http/Controllers/BulkController.php`, `app/Http/Requests/BulkStartRequest.php`
- `api/StoreSettings.php`, `app/Models/OptimizationLog.php`
- `resources/admin/Pages/BulkPage.jsx`, `resources/admin/App.jsx`, `resources/admin/Partials/HeroStats.jsx`

## The three defects this unit closes

1. **`Scanner::summary()` counts a universe that empties as work succeeds.** `total` is a
   `COUNT(*)` of `image/jpeg` (plus `image/png` when `convert_png` is on); `processed` is
   `max(0, total - pending)`. `AttachmentConverter::convert()` sets `post_mime_type` to
   `image/webp` on success, so **every optimized image leaves `total`**. `processed` is a
   subtraction between two shrinking numbers, not a count of anything.
2. **`Runner`'s `total` is frozen while `done` is monotonic.** `total` is a one-time snapshot of
   `count_pending()` from `start()` (`Runner.php:143`), never recomputed; `done` only increments
   and survives resumes and successive logical runs. Turn on `convert_png` or run Requeue
   mid-run and `done` climbs past a `total` of 1.
3. **Two sources render on one screen.** `HeroStats` sums the log table via `StatsResource`
   (hour-long transient); the tiles below run a live mime count. No common source, so they can
   never agree.

## Decisions taken with the user before building

| Question | Decision |
|---|---|
| What the ring measures | **% of images optimized, by count** — `optimized / total_images` |
| Permanently-skipped images | **Stay in the denominator as unresolved.** The ring caps below 100% on a fully-processed library, deliberately |
| Scan depth | **Disk-verified and batched.** Stat every file; never trust `status` (invariant 22) |
| Dry-run card | **Folded into the merged card**, not deleted |
| Scan frequency | `Manual / Daily / Weekly / Monthly`, default **Weekly** |
| Dashboard source of truth | **The scan snapshot.** Hero, ring and tiles all read it; `StatsResource` stays for `GET stats` and WP-CLI |

Two deviations from the literal request, both flagged to the user and accepted:

- **The Scan button re-enables when a scan stalls.** "Disabled until the scan completes" is
  correct on a healthy site, but WP-Cron only fires on an incoming request — on a quiet site with
  the tab closed the literal rule leaves the button dead forever. It is
  `disabled={ running && ! stalled }`, reusing `Runner::STALL_AFTER`.
- **The ring draws a second muted arc** for the permanently-skipped share, so it closes visually
  to 100% while the headline number stays `optimized / total_images`. No number changes.

## Files changed

| File | Purpose |
|---|---|
| `app/Services/Bulk/ScanRunner.php` | **New.** The batched, disk-verified scan engine |
| `app/Services/Bulk/Coordinator.php` | **New.** The scan → optimize → scan chain |
| `app/Hooks/Scheduler/ScanJobRunner.php` | **New.** Batch tick + recurring schedule + monthly interval |
| `app/Http/Controllers/ScanController.php` | **New.** start/status/batch/cancel/snapshot |
| `app/Http/Requests/ScanStartRequest.php` | **New.** One `force` boolean |
| `app/Models/OptimizationLog.php` | `attachmentScanPage()`, `countScannableAttachments()` |
| `app/Services/Bulk/Runner.php` | Fire the completion action; recompute `total` in `state()` |
| `app/Http/Routes/api.php` | The `library` prefix group; `bulk/run` and `bulk/phase` |
| `app/Http/Controllers/BulkController.php` | `run()` and `phase()` |
| `boot/bindings.php` | `scanner` and `coordinator` singletons + aliases |
| `app/Hooks/actions.php` | Register `ScanJobRunner` and `Coordinator` |
| `app/Hooks/Handlers/ActivationHandler.php` | Schedule the recurring scan, kick a first one |
| `app/Hooks/Handlers/DeactivationHandler.php` | Unschedule both scan hooks |
| `app/Hooks/Handlers/MenuHandler.php` | Localize `snapshot` and `scanFrequency` |
| `api/StoreSettings.php`, `config/optimizer.php` | The `scan_frequency` setting |
| `uninstall.php` | Delete the four new options, clear both hooks |
| `resources/admin/Components/ProgressRing.jsx` | **New.** Hand-rolled SVG ring |
| `resources/admin/Partials/{LibraryCard,ScanSummary,RunProgress,DryRunPanel}.jsx` | **New.** The merged card |
| `resources/admin/{App.jsx,Pages/BulkPage.jsx,Pages/SettingsPage.jsx,Partials/HeroStats.jsx,Components/index.js}` | Snapshot state, source switch, frequency select |
| `resources/styles/{admin.scss,_controls.scss}` | `.sio-ring`, `.sio-library`, `.sio-scanmeta`, `.sio-phase` |
| `tests/php/bulk-e2e.php`, `tests/e2e/library-scan.spec.js` | Coverage |

## The scan data model

Four options, all `autoload = false`:

| Option | Holds |
|---|---|
| `swift_image_optimizer_library_scan` | The last **completed** snapshot — the dashboard's only source |
| `swift_image_optimizer_scan_progress` | In-flight state; never rendered as truth |
| `swift_image_optimizer_scan_lock` | `Services\Lock` name (invariant 18) |
| `swift_image_optimizer_bulk_phase` | The chain's phase machine |

**`total_images`** is every attachment with `post_mime_type LIKE 'image/%'` except
`image/svg+xml`. **It includes the WebP files the plugin produced** — that is the whole fix.
An optimized JPEG stays in the denominator and moves into the `optimized` bucket, so nothing
collapses toward zero as work succeeds.

Buckets are mutually exclusive and sum to `total_images`. Each is decided from one LEFT JOIN row
plus **one disk stat**:

| Bucket | Rule |
|---|---|
| `optimized` | `status = 'optimized'` **and** `AttachmentConverter::optimized_output_exists()`. The only entry to the numerator |
| `skipped_permanent` | `status = 'skipped'` with `reason ∈ PERMANENT_SKIPS`; or no row and the mime is neither convertible nor WebP; or no row and the mime is `image/webp` (a WebP the user uploaded) |
| `skipped_retryable` | `status = 'skipped'` with `reason ∈ RETRYABLE_SKIPS`; also a pending candidate whose source file is missing |
| `failed` | `status = 'failed'` |
| `pending` | no row and the mime is convertible (honouring `convert_png`); or `status = 'restored'`; or **`status = 'optimized'` whose file is gone** |
| `unknown` | `get_attached_file()` resolved outside the uploads basedir — an offloader filtered the path. Excluded from both numerator and "still to do" rather than guessed wrong |

**The scan observes; it never writes to the log table.** A row marked `optimized` whose file has
vanished is bucketed `pending` and left alone. Deleting stays `Scanner::rescan()`'s job — which
is what keeps the scan safe to run unattended on a schedule, and keeps invariant 9 intact.

`actionable` and `requeueable` are reported separately because they are genuinely different:
`Scanner::next_batch()` skips every terminal row, so a bulk run will not touch retryable skips or
failures until `Scanner::requeue()` deletes them. Folding those into "still to do" is a smaller
version of the same lie this unit exists to kill.

## ScanRunner

Mirrors `Runner`'s shape so there is one state-machine idiom in the codebase. Three deliberate
deviations, all from the same fact — **a partial scan is only a count, and counting is cheap,
whereas partial conversion is irreversible**:

- **No resume.** A stopped scan restarts at cursor 0. Publishing a partial count would be a lie.
- **`cancel()` discards partials** but leaves the previously published snapshot untouched, so
  cancelling never blanks the dashboard.
- **Batch ceiling 500, not 20.** A `stat()` is not an encode.

Cursor-paged by primary key, never `OFFSET`. `update_postmeta_cache()` primes each batch —
without it `get_attached_file()` fires one query per attachment and dominates runtime on exactly
the libraries this exists to serve.

Scan requested while bulk runs is refused (`bulk-active`): a mid-run scan is obsolete before it
publishes and would flicker the ring backwards. Bulk requested while a scan runs cancels the
scan — the chain re-scans anyway. A scheduled scan that fires during bulk defers.

## Cron

`ScanJobRunner` copies `BulkJobRunner` exactly, including its self-heal-only-while-active guard
and its in-process `process_batch()` call — invariant 13 forbids loopback HTTP, so cron in-process
is the only option. `HOOK` is a self-re-arming single event; `SCHEDULE_HOOK` is recurring.
`swift_image_optimizer_monthly` is the **only** custom `cron_schedules` entry needed: WP provides
`daily` and `weekly` natively, `monthly` does not exist. It is 30 days, and the help text says
"about every 30 days" rather than implying a calendar month.

## The chain

`Coordinator` listens to two new actions — `swift_image_optimizer_scan_completed` and
`swift_image_optimizer_bulk_completed` — and walks `scanning-before` → `optimizing` →
`scanning-after`, weighted 10/80/10 into one server-computed percent. Every stage was already
cron-driven, so the chain is background by construction. The chained bulk calls
`Runner::start( true )`, so `total` is fresh and `done` starts at 0 — which alone kills "369 of 1".

`Runner::state()` also recomputes `total` as `done + count_pending()` before deriving `percent`,
making the stale-total bug structurally impossible on the legacy `bulk/start` path too.

## REST routes

`GET scan` exists and the `bulk/*` paths are frozen, so scan routes take a new `library` prefix
and only two names are *added* inside `bulk`. All are in the existing `AdminPolicy` group.

| Method | Path | Handler |
|---|---|---|
| POST | `library/scan` | `ScanController::start` |
| GET+POST | `library/scan/status` | `ScanController::status` |
| POST | `library/scan/batch` | `ScanController::batch` |
| POST | `library/scan/cancel` | `ScanController::cancel` |
| GET | `library/snapshot` | `ScanController::snapshot` |
| POST | `bulk/run` | `BulkController::run` |
| GET+POST | `bulk/phase` | `BulkController::phase` |

## Settings

`scan_frequency` needs four coordinated edits. `config/optimizer.php` is **currently dead data** —
`StoreSettings` hardcodes its own copy and never reads it — so that edit is documentation, not
behaviour. Do not "fix a bug" by editing it and expect anything to change. The load-bearing one
is the `show_in_rest` schema property: omit it and core strips the key **before** `sanitize()`
sees it, and the setting silently never saves.

## First load

Never a synchronous scan on page load — that is the freeze this unit exists to prevent.
Activation kicks a first scan. `MenuHandler::enqueue()` writes nothing, because it runs on every
load. With no snapshot the UI shows a 0% muted ring, "Swift has not scanned your library yet",
and fires one `POST library/scan` behind a ref guard. **No backfill from `StatsResource`** — a
log-derived pre-scan number is exactly the number this feature exists to stop trusting.

## Completion Notes

Built as designed, with two deliberate deviations flagged to and accepted by the user before
starting (see "Two flags before starting" above): the Scan button re-enables on a stalled scan
rather than staying disabled forever, and the ring draws a second muted arc for the
permanently-skipped share so a finished library reads as closed rather than stuck below 100%.

**Verified:**

- `npm run lint:php` — 0 errors, 0 warnings across all 75 files (WPCS installed to `~/.wpcs`,
  outside the plugin tree per Unit 09's setup; `config/optimizer.php`'s array alignment needed
  `phpcbf` after the new `scan_frequencies` key lengthened a column).
- `composer dump-autoload -o` — clean, 63 classes.
- `npm run test:php` — **131 assertions passing** (up from 68), both under CLI PHP and
  `tests/php/run.sh --web`. New suites in `tests/php/bulk-e2e.php`: scan buckets sum to
  `total_images`; a converted image stays in the denominator (the specific defect behind the
  report, reproduced and fixed); disk-verification bucket transitions with the row left
  untouched (invariant 25); cursor paging matches a single big batch; the scan lock and the
  `bulk-active` refusal (with the chain's exemption); settings sanitize round-trip and cron
  schedule registration for all four frequencies; the full chain's phase transitions with a
  monotonic overall percent; the `total = done + count_pending()` repair reproducing and fixing
  "369 of 1" directly.
- `npm run build` — succeeds; `build/admin.asset.php` lists `wp-element` + `wp-api-fetch` and
  still excludes `wp-components`.
- `owasp-security-review` on the new REST surface (five new routes plus `bulk/run` and
  `bulk/phase`) — no findings. Checked specifically: the new `OptimizationLog` queries bind the
  `LIKE 'image/%'` pattern as a value rather than inlining it (a literal `%` in prepared SQL is
  itself a placeholder — caught and fixed during implementation, not left as a defect); every new
  route sits inside the existing `AdminPolicy` (`manage_options`) group; `path_is_local()` treats
  an offloader-filtered attachment path as `unknown` rather than trusting or guessing it.
- `/security-review` could not run against the diff — the session's working directory
  (`app/public`) is not a git repository; the plugin's own checkout is, and `owasp-security-review`
  covered the new surface instead.

**Not verified — the standing I-3 gap, not newly introduced by this unit:**

- No browser click-through was performed. No admin credentials were available in this
  environment, and I would not guess or reset them. `tests/e2e/library-scan.spec.js` was written
  (ring count, `aria-valuenow` bounds, Scan button disable/re-enable, Monthly frequency
  persistence, zero console errors) and correctly **skips** without `WP_ADMIN_USER` /
  `WP_ADMIN_PASSWORD` set — confirmed via `playwright test --list` and a real run (4 skipped, 0
  run). This is the same gap I-3 already tracks for the rest of the dashboard; this unit adds to
  it rather than closing it. Running it live, and a manual pass confirming the site stays
  responsive during a real scan/bulk run, is the next thing to do before this is release-ready.
- `npm run lint:js` fails at config load (`Cannot read properties of undefined ('Intrinsic')` from
  `@typescript-eslint`/`ts-api-utils`), identically on an untouched file (`Button.jsx`) with no
  `package.json`/lockfile changes from this unit — pre-existing broken tooling install, not a
  regression, and out of scope to fix (would mean installing packages, which touches build tooling
  beyond this unit's files).

**Deviations from `future-specs/scheduled-optimization.md`:** no 5-minute recurring interval —
`BulkJobRunner`'s existing self-re-arming single event is reused unchanged; the mandatory
dry-run gate before unattended runs was not implemented as a hard block. See that file's updated
header for detail.
