# Unit 03 — Backups & Retention

> **Names below are historical.** `RetentionCron` became
> `App\Hooks\Scheduler\JobRunner`, and the `src/` paths were replaced in the 2026-08-10
> restructure. The graph still surfaces `RetentionCron` from this file — it does not exist in code.

## Goal

Make destructive conversion reversible. Nothing in Unit 04 or 05 may be written until this
unit exists, because those units delete user originals.

## Layout

```
uploads/swift-image-optimizer-backups/2026/08/photo.jpg
                                      ├── .htaccess   (Deny from all)
                                      └── index.php   (silence)
```

The backup tree **mirrors the uploads path**. Two images with the same basename in different
months would otherwise collide and silently overwrite each other's originals.

A backup covers the main file, `original_image` (the pre-scaling copy WordPress keeps when it
creates a `-scaled` version) and every generated subsize.

## Rules

**Roll back a partial backup.** If any file fails to copy, the ones already copied are deleted
before returning `WP_Error`. A half-backup that looks complete is worse than no backup.

**Every path is validated against the backup root.** `safe_path()` resolves with `realpath()`
and refuses anything that does not sit inside the root. `relative_dir` comes out of the
database, and a restore writes files — that is a traversal vector if left unchecked.

**Retention: 7 / 30 / 90 days, or keep forever.** Default 30. `expires = 0` means never.

**Expiry does not change `status`.** This was the original design and it was wrong: setting
`backup_expired` removed that image from the savings aggregate in `Stats`, so the reported
total quietly shrank over time. Availability is now signalled by an **empty `backup_path`**,
and the `STATUS_BACKUP_EXPIRED` constant was deleted.

## Cron

`swift_image_optimizer_purge_backups`, daily, 200 rows per run. Self-heals: `register()`
reschedules the event if it has gone missing, which happens after some migrations and
staging-to-live copies.

## Completion Notes

Directory creation and protection verified — `.htaccess` and `index.php` are written on first
use and the directory is writable.

Backup and restore verified as part of `convert-restore-e2e.php` (Unit 05): 6 files backed up
for a single attachment (main + 5 subsizes), and the restored original came back
**byte-identical** to the source file.

The retention cron's purge loop is exercised by `Rest\Controller::purge_backups()`, which
expires everything and then calls `RetentionCron::purge()` until it stops finding work. The
time-based expiry path itself has not been tested with a genuinely aged backup — only with
artificially expired rows.

One cleanup note: a crashed test run left 6 orphaned backup files behind. Harnesses that create
backups must clean the backup directory in their teardown, not just the log table.
