# Unit 13 — A purge that empties the folder, and a modal that replaces `window.confirm()`

## Goal

Two user-reported defects on the **Backups** tab:

1. **"Delete all backups now" does not delete the backups.** The dialog appears, the toast
   appears, the stat re-renders — and every file is still on disk.
2. **The confirmation is a browser alert.** Replace it with the plugin's own modal, gated on the
   operator typing `DELETE`. Every other `window.confirm()` in the plugin moves to the same modal
   (without the typed word).

This claims to close **I-8** — "backup pointer can outlive its files… there
is no routine that reconciles the two".

## Why the purge deletes nothing

Confirmed against the live cb-test database (socket `aRpCXvFUz`): all **78** rows in
`wp_swift_image_optimizer_log` carry an **empty `backup_path`** and `backup_expires = 0`, while
`uploads/swift-image-optimizer/backup/` holds **42 files / 892 KB**. Deletion is entirely
manifest-driven, so it resolves nothing to delete, returns `purged: 0`, and `disk_usage()` reports
the same 831.4 KB it reported before.

Three separate exclusions, each sufficient on its own to reproduce the report:

| # | Exclusion | Where |
|---|---|---|
| 1 | Nothing reconciles disk against the manifest. A backup whose log row was deleted, or whose pointer was already cleared, is unreachable forever | I-8 |
| 2 | `UPDATE … WHERE backup_expires > 0` and `SELECT … WHERE backup_expires > 0`. Retention **"Keep forever"** stores `backup_expires = 0`, so the button that claims to delete *every* stored original cannot touch those backups at all | `BackupController.php:38`, `JobRunner.php:96`, `BackupManager::expiry()` |
| 3 | `AND status = 'optimized'`. A row whose status moved off `optimized` keeps its files forever | `JobRunner.php:98` |

A fourth, latent: the `do { } while ( $count > 0 )` loop in the controller has no iteration cap. It
terminates today only because `JobRunner::purge()` clears `backup_path` on every row it counts; a
failing `OptimizationLog::update()` would spin inside one HTTP request.

## The data-loss constraint this unit had to satisfy

`agent.md`: *"Never delete by glob over a shared directory. Delete only paths you created,
resolved from your own records. A glob cleanup already destroyed 54 real user backups."*

Fixing I-8 requires deleting files that are, by definition, **not** in our records. The sweep is
therefore made narrow enough that it cannot become that incident again:

- Scoped to `BackupManager::root()` — a directory this plugin creates and owns
  (`DIRNAME = 'swift-image-optimizer/backup'`), never a shared uploads path. The root must resolve
  and must actually end in `DIRNAME` or the sweep refuses to run.
- Reachable **only** from the explicit typed-confirmation admin action. Never from cron, never
  from `JobRunner`, never from upload or restore.
- Every candidate must be a regular file, must not be a symlink, and its `realpath()` must sit
  inside the resolved root.
- `index.php` and `.htaccess` at the root are preserved.
- `safe_path()` is not touched, weakened, or bypassed.

The incident's glob was `glob( root() . '/*/*/*' )` running automatically, unvalidated, with no
containment check. This is the opposite on all four counts.

## Decisions taken with the user before building

| Question | Decision |
|---|---|
| Orphaned files with no manifest | **Sweep the whole backup root** as part of purge, so the folder genuinely empties and the stat reaches 0 |
| Scope of the modal | **Replace every `window.confirm()`** in the plugin; only the backup purge requires typing `DELETE` |

## Implementation

### `BackupManager::purge_orphans()`

New static method beside `delete()`. `RecursiveIteratorIterator` in `CHILD_FIRST` mode over the
root (same iterator `disk_usage()` already uses), deleting validated files with `wp_delete_file()`
and `@rmdir()`-ing directories left empty on the unwind. Returns
`array( 'files' => int, 'bytes' => int )` — bytes summed before deletion, for the toast.

### `JobRunner`

Row loop extracted to `purge_rows()`. New `purge_manifests()` selects **every** row with a
non-empty `backup_path` — no `backup_expires` filter, no `status` filter — closing exclusions 2
and 3. The cron entry point `purge()` keeps its existing expiry-and-status query verbatim:
retention semantics are not part of this unit.

### `BackupController::purge()`

Manifest pass (bounded loop), then the sweep, then a `Logger` entry. Response gains
`files_removed` and `bytes_freed` alongside `purged` and `backup_bytes`. Policy and nonce are
unchanged — `AdminPolicy` (`manage_options`) plus `X-WP-Nonce`.

### `Modal.jsx` + `ConfirmDialog.jsx`

Hand-rolled; `@wordpress/components` stays banned. `role="dialog"`, `aria-modal`, labelled and
described via `useFieldId`, focus moved in on open and restored on close, Escape and backdrop
close (both disabled while the action is running), Tab trapped, body scroll locked.
`ConfirmDialog` adds the optional `confirmWord` — a `Field`-wrapped text input whose trimmed value
must match the translated word case-insensitively before Confirm enables.

Shared styles live in a new `_modal.scss` `@use`d by both `admin.scss` and `media.scss`, so the
React and Backbone surfaces cannot drift apart.

### Call sites

`BackupsPage` (typed `DELETE`), `BulkPage`, `TroubleshootPage`, and — through a small vanilla
`sioConfirm()` promise wrapper building the same `sio-modal` DOM — `media.js`'s restore and
selection-optimize actions.

`BackupsPage`'s toast is rebuilt from `files_removed` and `bytes_freed`: `'%d backups removed.'`
would have read "0 backups removed" in exactly the orphan-only case this unit exists to fix.

## Completion Notes

Landed. `purge_orphans()`, `purge_manifests()`, `Modal.jsx` and `ConfirmDialog.jsx` all exist, and
no `window.confirm()` remains anywhere in the plugin. The purge has since been driven end to end by
`convert-restore-e2e`, which asserts the folder reaches 0 bytes, the guard files survive, and a
symlink's target outside the root is not touched.

**One claim in this spec was wrong.** It says it closes I-8. It closed the half the user had
reported — the folder would not empty — and left the half I-8 was actually about: a backup on disk
that no row points at was still unreachable, and `purge_orphans()` **deletes** such files rather
than recovering them. That inverted the risk: for a while the only routine that touched an
unreferenced backup destroyed it. `BackupManager::reconcile()` closed the recover half later; see
[memory/fixed-issues.md](../../memory/fixed-issues.md).

The ordering that came out of it is now invariant 26: **repair runs before any sweep**, because the
purge deletes exactly what repair recovers.

Its Files-changed table also claimed an edit to `ui-context.md` that never happened — that file
still described `window.confirm()` two units later.
