# Future Spec — Scheduled Background Optimization

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
