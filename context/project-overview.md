# Project Overview

## What this plugin is

Swift Image Optimizer converts WordPress images to WebP and compresses them, entirely on the
site's own server. No API key, no quota, no account, no third-party service. It is built as a
plain PHP plugin with **zero runtime dependencies** and a React admin screen compiled with
`@wordpress/scripts`.

It has two features, and they solve two genuinely different problems:

| Feature | Problem it solves | Where it runs |
|---|---|---|
| **Auto-optimize on upload** | New images arrive unoptimized | `wp_handle_upload`, before the attachment exists |
| **Individual / bulk optimization** | Images already in the Media Library | REST-driven batches, WP-CLI, or Media Library row actions |

## The single most important design fact

Upload conversion happens in the `wp_handle_upload` filter, which fires **after** the file is
moved into `uploads/` but **before** `wp_insert_attachment` runs. WordPress therefore creates
the attachment as a native `.webp` and generates every subsize as WebP on its own.

**Consequence: the upload path needs zero URL rewriting.** Nothing references the file yet.

URL rewriting exists solely for feature 2 — retro-fitting images that already have references
scattered across posts, meta and options. Keep this distinction in mind; it is why
`Upload/Interceptor.php` is 200 lines and `AttachmentConverter.php` plus `Rewrite/` is ten
times that.

## Naming rules — the single source of truth

| Thing | Value |
|---|---|
| Plugin slug / directory | `swift-image-optimizer` |
| PHP namespace | `SwiftImageOptimizer\` |
| Text domain | `swift-image-optimizer` |
| Constants prefix | `SWIFT_IMAGE_OPTIMIZER_` |
| Option / function prefix | `swift_image_optimizer_` |
| REST namespace | `swift-image-optimizer/v1` |
| DB table | `{$wpdb->prefix}swift_image_optimizer_log` |
| Backup directory | `uploads/swift-image-optimizer/backup/` |
| React mount point | `#swift-image-optimizer-root` |
| CSS class prefix | `sio-` |

### Options

| Option | Contents |
|---|---|
| `swift_image_optimizer_settings` | The settings array (see `Admin/Settings::defaults()`) |
| `swift_image_optimizer_schema_version` | Installed DB schema version |
| `swift_image_optimizer_bulk_progress` | Bulk run state, so a closed tab can resume |

### Transients

| Transient | Purpose |
|---|---|
| `swift_image_optimizer_stats` | Cached stats aggregate (1 hour) |
| `swift_image_optimizer_bulk_lock` | Prevents two bulk runs colliding |
| `swift_image_optimizer_lock_{id}` | Per-attachment conversion lock |

## What this plugin deliberately does NOT do

- **No client-side conversion.** Cimo's `canvas.toBlob` approach is fast but breaks in Safari,
  needs an open browser tab, and cannot do bulk without downloading and re-uploading every
  image. See `competitor-features.md`.
- **No AVIF.** WebP only, by decision. AVIF encoding is 5-10x slower, which is punishing across
  a large library. Tracked in `future-specs/avif-support.md`.
- **No bundled binaries.** `cwebp` is used only if the host already has it.
- **No external HTTP requests of any kind.** No telemetry, no phone-home, no licence check.
- **No non-destructive mode.** Originals are replaced and URLs rewritten. This was an explicit
  product decision; the safety net is backups + retention + restore, not a parallel-file mode.

## Target

WordPress.org public repository. Everything is written to .org review standard from the first
file: GPL headers, `ABSPATH` guards, full escaping and sanitization, prepared statements,
prefixed globals, `readme.txt`, i18n throughout.

## Tech constraints

- PHP 7.4 minimum (verified: every file parses under 7.4, 8.2 and 8.4)
- WordPress 6.2 minimum (the admin bundle uses createRoot from @wordpress/element, which is React 18 / WP 6.2)
- No Composer runtime dependency — a hand-rolled PSR-4 autoloader lives in the main plugin file
- `@wordpress/scripts` is a **dev** dependency only; `build/` is committed for distribution
