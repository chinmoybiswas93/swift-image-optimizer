# Competitor Features

Reference notes on how the established image optimizers work, and where this plugin sits.

## The three architectural camps

| Camp | Who | How it works | Real trade-off |
|---|---|---|---|
| **External API** | ShortPixel, Imagify, TinyPNG, Kraken | Upload each image to a remote service, download the result | Best compression, zero server load — but quotas, monthly cost, API keys, and every image leaves the site |
| **Server-side local** | EWWW, Smush (local mode), **this plugin** | GD / Imagick / CLI binaries on the host | Unlimited and private, but uses the host's CPU and depends on what the host has installed |
| **Client-side** | Cimo | `canvas.toBlob()` in the admin's browser before upload | Zero server load and instant — but see below |

## Cimo — the direct inspiration, and why we diverged

Cimo (analysed at `wp-content/plugins/cimo-image-optimizer/`) intercepts the browser's upload
events, converts via the Canvas API, then swaps the `File` object in a rebuilt `DataTransfer`
before WordPress ever sees it. It is genuinely clever and the upload experience is excellent.

Where it breaks down:

| Limitation | Consequence |
|---|---|
| `canvas.toBlob('image/webp')` unsupported in Safari | Uploads silently pass through unoptimized |
| Requires an open admin browser tab | No CLI, no cron, no programmatic uploads |
| Bulk means download → convert → re-upload each image | Doubles bandwidth; hours for a large library; dies if the tab closes |
| Canvas strips ICC and EXIF unconditionally | Display-P3 photos visibly desaturate |
| Premium-gated bulk optimization | The feature most users actually need is paywalled |

**Our decision:** server-side for both features. One engine, one code path, works in Safari,
works headless, and bulk is resumable. We give up Cimo's zero-CPU upload in exchange for a
bulk feature that actually works.

## Feature comparison

| Feature | ShortPixel | EWWW | Smush | Cimo | **This plugin** |
|---|---|---|---|---|---|
| WebP conversion | Yes | Yes | Pro | Yes | **Yes** |
| AVIF | Yes | Yes | No | No | No — [future-specs/avif-support.md](future-specs/avif-support.md) |
| Works without an API key | No | Yes | Partly | Yes | **Yes** |
| Unlimited / no quota | No | Yes | Pro | Yes | **Yes** |
| Bulk optimization | Yes | Yes | Yes | Premium | **Yes, free** |
| Restore originals | Yes | Yes | Yes | Premium | **Yes** |
| WP-CLI | Yes | Yes | No | No | **Yes** |
| Replaces the original file | Optional | Optional | No | Yes | **Yes (by design)** |
| Rewrites URLs in content | No | No | No | Premium | **Yes** |
| Dry run before bulk | No | No | No | No | **Yes** |
| Images leave the server | Yes | No | Yes | No | **No** |

## The delivery question, and why we answered it differently

Nearly every competitor keeps `photo.jpg` and writes `photo.jpg.webp` beside it, then serves
the WebP via `.htaccess` rewriting or `<picture>` tag injection. That is the conservative
choice: reversible, no URL changes, nothing breaks.

This plugin replaces the file and rewrites the URLs instead. That is strictly riskier, and it
was a deliberate product decision, not an oversight. What it buys:

- No delivery layer at all — no `.htaccess`, no content filter, no Nginx config
- Works identically behind any CDN
- ~1.6x less disk than keeping both copies
- Honest filenames

What it costs, and what we do about it:

| Cost | Mitigation |
|---|---|
| URLs change | Serialization-safe rewriter across 7 tables |
| Rewriter can miss things | `Fallback404` serves the WebP when an old URL is requested |
| Irreversible in principle | Backups + configurable retention + one-click restore |
| No preview of the blast radius | Mandatory dry run reporting exactly what would change |

**Nobody else offers the dry run.** It is the feature that makes the destructive approach
defensible, and it is worth leading with.

## Ideas worth borrowing

- **EWWW's "resize existing images"** — separate from conversion, catches 4000px uploads
- **ShortPixel's per-folder scanning** — optimize files outside the Media Library
- **Imagify's before/after comparison slider** — visual quality reassurance in the UI
- **Smush's "lazy load" bundling** — probably out of scope; this plugin should stay one thing
- **EWWW's scheduled background optimization** — ours is browser-driven or CLI; a cron mode
  would help users who will not keep a tab open. See `future-specs/scheduled-optimization.md`.
