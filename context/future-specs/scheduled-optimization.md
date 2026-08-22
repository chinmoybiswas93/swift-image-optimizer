# Future Spec — Scheduled Background Optimization

**Mostly built.** `BulkJobRunner` (Unit 11) drives batches from cron; `ScanJobRunner` (Unit 12)
adds a genuinely recurring event with manual/daily/weekly/monthly frequencies; `Coordinator`
chains scan → optimize → scan behind one button. A bulk run no longer needs an open tab or shell
access.

One deliberate deviation from the original proposal: **no fixed 5-minute interval.**
`BulkJobRunner` re-arms a single event per batch instead, because batch size is adaptive and a
fixed interval would either idle or lag.

## What is still open

1. **A kill switch.** There is no single control that stops all scheduled work.
2. **Automatic stop after N consecutive failures.** A run that fails every batch keeps being
   re-armed.
3. **The mandatory dry run.** The dry-run panel exists, but nothing blocks Bulk Optimize on having
   run one first. This mattered less when every run had someone watching it; it matters more now
   that unattended scheduled scans exist, because running **destructive** conversions unattended
   is a materially different risk profile. If this is built, the setting should say plainly that
   URL rewriting will happen without supervision.

## The risk worth remembering

WP-Cron only fires when someone visits the site. On a low-traffic site a run can stall for days,
and the UI has to say so rather than showing a frozen progress bar.

Consider Action Scheduler where WooCommerce already provides it — but never add it as a dependency.
