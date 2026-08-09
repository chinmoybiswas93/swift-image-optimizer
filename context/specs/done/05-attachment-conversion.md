# Unit 05 — Attachment Conversion (Feature 2)

## Goal

Convert an image that is already in the Media Library, repoint everything that references it,
and be able to put it all back.

## Read first

- `src/Optimizer.php` — reused unchanged from Unit 02
- `src/Backup/BackupManager.php` — Unit 03
- `src/Rewrite/UrlMap.php` and `DatabaseRewriter.php` — Unit 04
- `wp-admin/includes/image.php` — `wp_generate_attachment_metadata()`

## Files changed

| File | Purpose |
|---|---|
| `src/AttachmentConverter.php` | The orchestrator: convert + restore |
| `src/Media/ListTable.php` | Column, row actions, bulk actions, modal data |

## Convert pipeline

```
 1. per-attachment transient lock
 2. capture $before = wp_get_attachment_metadata()
 3. BackupManager::backup()            ← first destructive step is preceded by this
 4. Optimizer::optimize()              → temp .webp
 5. rename into place
 6. collect $old_files from $before    ← before regeneration loses the list
 7. update_attached_file()
 8. wp_generate_attachment_metadata()  → $after, subsizes now WebP
 9. wp_update_post()                    post_mime_type + guid
10. UrlMap::build( $before, $after )
11. DatabaseRewriter::replace()         unless deferred
12. delete $old_files not present in $after
13. Database::upsert()                  log row with url_map + backup pointer
```

**Order matters at three points:**

- Step 3 before step 4 — never touch a file that is not backed up
- Step 6 before step 8 — regeneration overwrites the metadata that lists the old files
- Step 12 last — a failure anywhere above leaves the old files intact and the site working

Failures between 3 and 12 delete the backup and return `WP_Error`, leaving the original in
place.

## `$defer_rewrite`

`convert( $id, true )` returns the URL map instead of applying it. `Bulk\Runner` merges the
maps from a whole batch and rewrites **once**, turning N table scans into one. This is what
makes bulk viable at library scale.

## Restore

Reverses the map with `array_flip()` and rewrites **before** deleting the WebP files, so a
failure part-way still leaves a site that renders. Then restores the files, repoints
`_wp_attached_file`, regenerates metadata, reverts `post_mime_type`, deletes the WebP files,
and removes the backup.

## Soft errors

`AttachmentConverter::SOFT_ERRORS` is the **single definition** of which error codes mean
"nothing to do here" rather than "something went wrong":

```
already-optimized, already-webp, engine-unsupported, insufficient-memory,
locked, missing-file, png-disabled, skipped-larger, unsupported-format
```

`no-engine` is deliberately **not** in the list — that is a real environment problem the user
needs to see.

## Media Library integration

| Hook | Gives |
|---|---|
| `manage_media_columns` + `manage_media_custom_column` | "Optimization" column with % saved |
| `media_row_actions` | Optimize / Restore original |
| `bulk_actions-upload` + `handle_bulk_actions-upload` | Bulk optimize / restore |
| `wp_prepare_attachment_for_js` | Exposes status to the media modal |

Row actions are nonce-protected and capability-checked per object with `edit_post`.
`reason_label()` maps internal codes to sentences a site owner can act on.

## Completion Notes

`convert-restore-e2e.php` — **35 assertions, all passing**. The test plants references in all
four shapes a real site uses, then converts and restores:

| Reference shape | Convert | Restore |
|---|---|---|
| `post_content` `<img src>` (full + medium) | Repointed | Reverted |
| Serialized postmeta, nested arrays + gallery | Repointed, still unserializes, non-strings intact | Reverted |
| Elementor `_elementor_data` escaped-slash JSON | Repointed, still valid JSON | — |
| Option with nested array | Repointed | Reverted |

Also verified: file becomes `.webp`, old `.jpg` and all old subsize files deleted, all new
subsizes WebP and present, `post_mime_type` updated, backup recorded with a future expiry and
6 files on disk, and the **restored file is byte-identical to the source**.

A single conversion produced an 18-entry URL map (main + scaled + 5 subsizes across 3 URL
forms), which is a useful sanity figure — if that number collapses, `UrlMap` has regressed.
