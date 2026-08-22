# Project overview

Swift Image Optimizer converts WordPress images to WebP and compresses them, entirely on the
site's own server. No API key, no quota, no account, no third-party service. Plain PHP with zero
runtime dependencies and a React admin screen built with `@wordpress/scripts`.

Two features, solving genuinely different problems:

| Feature | Problem | Where it runs |
|---|---|---|
| **Auto-optimize on upload** | New images arrive unoptimized | `wp_handle_upload`, before the attachment exists |
| **Individual / bulk optimization** | Images already in the Media Library | REST batches, WP-CLI, Media Library row actions |

## The design fact everything follows from

Upload conversion happens in `wp_handle_upload`, which fires **after** the file lands in
`uploads/` but **before** `wp_insert_attachment`. WordPress therefore creates the attachment as a
native `.webp` and generates every subsize as WebP itself.

**So the upload path needs no URL rewriting at all** — nothing references the file yet.

Rewriting exists solely for feature 2: retro-fitting images whose references are already scattered
across posts, meta and options. That asymmetry is why the upload interceptor is a few hundred
lines and the converter plus `Rewrite/` are an order of magnitude more.

## Names — the single source of truth

| Thing | Value |
|---|---|
| Slug / directory / text domain | `swift-image-optimizer` |
| PHP namespace | `SwiftImageOptimizer\` |
| Constants prefix | `SWIFT_IMAGE_OPTIMIZER_` |
| Option / function / hook prefix | `swift_image_optimizer_` |
| REST namespace | `swift-image-optimizer/v1` |
| DB tables | `{$wpdb->prefix}swift_image_optimizer_log`, `..._urls` |
| Backup directory | `uploads/swift-image-optimizer/backup/` |
| React mount point | `#swift-image-optimizer-root` |
| CSS class prefix | `sio-` |

**Options:** `swift_image_optimizer_settings` (the settings array — `api/StoreSettings::defaults()`),
`..._schema_version`, `..._bulk_progress` (so a closed tab can resume).

**Locks are options, not transients** — `swift_image_optimizer_bulk_lock` and
`..._lock_{id}` go through `add_option()` so the database arbitrates (invariant 18). The only real
transient is `swift_image_optimizer_stats`, the cached aggregate.

## What it deliberately does not do

- **No client-side conversion.** The `canvas.toBlob` approach some competitors use is fast, but it
  breaks in Safari, needs an open browser tab, and cannot do bulk without downloading and
  re-uploading every image.
- **No AVIF.** WebP only, by decision — AVIF encoding is 5–10x slower, which is punishing across a
  large library. Deferred in `future-specs/avif-support.md`.
- **No bundled binaries.** `cwebp` is used only if the host already has it.
- **No external HTTP requests of any kind.** No telemetry, no phone-home, no licence check.
- **No non-destructive mode.** Originals are replaced and URLs rewritten. That was an explicit
  product decision; the safety net is backups + retention + restore, not a parallel-file mode. It
  is also the reason every rule in `ai-workflow-rules.md` exists.

The one thing no competitor offers is the **dry run** — telling you how many references a bulk run
would rewrite before it touches anything.

## Constraints

- **PHP 7.4** minimum, **WordPress 6.2** minimum (the admin bundle uses `createRoot`, which is
  React 18 / WP 6.2).
- **Composer PSR-4 autoloading, no runtime dependency.** `vendor/` holds only the generated
  autoloader and is committed, because .org runs no build step on the destination server.
- `@wordpress/scripts` is a **dev** dependency; `build/` is committed for distribution.
- Target is the **WordPress.org public repository**, written to review standard from the first
  file: GPL headers, `ABSPATH` guards, full escaping and sanitization, prepared statements,
  prefixed globals, `readme.txt`, i18n throughout.
