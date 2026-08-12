# Future Spec — Scheduled Background Optimization

**Largely built in Unit 12** (`context/specs/done/12-centralized-scan-stats.md`, closing issue
I-14). `BulkJobRunner` already existed from Unit 11 as the in-process cron driver this spec asked
for; Unit 12 added `ScanJobRunner` (a genuinely recurring event, manual/daily/weekly/monthly) and
`Coordinator` to chain scan → optimize → scan behind one button. What differs from the proposal
below: no 5-minute recurring interval was added — `BulkJobRunner` re-arms a single event per
batch instead, since batch size is adaptive and a fixed interval would either idle or lag. Still
open from this file: an explicit kill switch and an automatic stop after N consecutive failures.
The mandatory-dry-run gate was not built as a hard requirement — the dry-run panel remains, but
nothing blocks Bulk Optimize on having run one first; worth reconsidering given unattended
scheduled scans now exist.

## Idea

Let a bulk run proceed on WP-Cron in the background, instead of requiring an open browser tab
or shell access.

## Why

Today there are exactly two ways to bulk optimize: keep the dashboard open while it works
through batches, or use WP-CLI. Many site owners have neither the patience for the first nor
access to the second.

EWWW offers this, and it is a common reason people pick it.

## Approach

Reuse `Bulk\Runner` unchanged. It is already batched, locked and resumable, which is exactly
what a cron driver needs — the scheduler just calls `process_batch()` on a recurring event
until `running` goes false.

- A custom cron interval (every 5 minutes) rather than hooking `hourly`
- Very conservative batch sizing; cron runs share resources with real traffic
- A kill switch, plus automatic stop after N consecutive failures
- Progress visible in the dashboard whether the run is browser-driven or cron-driven
- Consider Action Scheduler when WooCommerce is already present, but never add it as a
  dependency

## Risks

WP-Cron is unreliable on low-traffic sites — it only fires when someone visits. A run could
stall for days, and the UI must say so rather than showing a frozen progress bar.

More seriously: running **destructive** conversions unattended is a materially different risk
profile from running them with someone watching. A dry run should be mandatory before this can
be enabled, and the setting should say plainly that URL rewriting will happen without
supervision.

## Effort

Small-to-medium. The runner already does the hard part.
