# Open issues

Defects and gaps that need a **code fix** before release. Surfaces that are merely untested live in
[pre-release-checks.md](pre-release-checks.md) — keeping the two apart is what makes this list
actionable.

Closed issues and the reasoning behind each closure: [memory/fixed-issues.md](memory/fixed-issues.md).

> **A short list means nobody has looked recently, not that nothing is wrong.** Unit 10 closed
> fourteen defects that were never listed here — three of them destroyed user data — and every one
> was found by reading the conversion path, not by a test or an entry below.

| # | Issue | What would close it |
|---|---|---|
| 1 | **Upload backups collide by filename and silently overwrite each other — the stored original is destroyed.** `BackupManager::backup_file()` copies to `trailingslashit( $target_dir ) . wp_basename( $file )` with no uniqueness check, and the upload path *deletes the source JPEG after converting it*, which frees that filename in `uploads/` — so the next upload of a file with the same name gets the identical basename from `wp_unique_filename()` and its backup lands on top of the previous one. Reproduced live: attachments #60 and #61 both stored `2026/08/csmp-test.jpg`; uploading a different image under that name for #62 replaced it (17,091,942 bytes / `05acbf…` → 1,405,706 bytes / `c9b9b6…`). Both earlier rows still carry a manifest naming that file, and `manifest_is_intact()` still returns **true** because a file is there — so "Restore original" is still offered and would restore *someone else's photo*. `IMG_1234.jpg` from two phones is enough to trigger it. `backup()` builds its target the same way. **Pre-existing; not introduced by the CSMP work.** | Make backup targets unique per attachment — a per-attachment subdirectory, or `wp_unique_filename()` against the backup dir — and keep existing manifests readable, since they name bare basenames inside `relative_dir`. Decide the layout once and change `backup_file()`, `backup()`, `manifest_is_intact()` and `reconcile()` together. |
| 2 | **`notice-strip-test` is missing from `web-runner.php`'s suite allowlist.** Unit 14 added the harness but never registered it for web mode, so `tests/php/run.sh --web` reports SUITE FAILED with "no such suite" while the CLI run passes. This is the only failure in an otherwise green web run, so it masks real ones. | A considered one-line change to `$suites` — it is a deliberate security allowlist, not an oversight to patch reflexively. |
| 3 | **Empty `relative_dir` when year/month upload folders are off.** `backup()` derives the dir from the attachment's own folder; with "Organize into month- and year-based folders" disabled that is the uploads basedir, so `relative_dir` is `''` — and `manifest_is_intact()` rejects it via `empty()`. Restore is never offered on such a site. `reconcile()` skips those rows rather than papering over it. | Decide whether `''` is a legitimate manifest value, then fix `backup()` and the guard together. |
| 4 | **`lint:js` reports 2 errors.** `resources/admin/Components/Tabs.jsx:54` — a `tablist` role that is not focusable (`jsx-a11y/interactive-supports-focus`); `resources/media/media.js:399` — `jsdoc/no-undefined-types` on `Backbone`. Down from the ~220 this entry used to claim. | Fix both; the a11y one is a real keyboard-navigation defect, not a lint preference. |
