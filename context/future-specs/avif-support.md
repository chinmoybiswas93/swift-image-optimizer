# Future Spec — AVIF Support

## Idea

Offer AVIF as an output format alongside WebP.

AVIF is typically 15-25% smaller than WebP at equivalent quality. GD on PHP 8.1+ supports it
(confirmed available on this machine's PHP 8.2+ builds), and Imagick supports it where the
delegate is compiled in. `avifenc` is also present on this machine.

## Why it was deferred from v1.0.0

Encoding cost. AVIF is roughly 5-10x slower to encode than WebP. Across a library of several
thousand images that turns a coffee-break bulk run into an overnight job, and makes the upload
path noticeably laggy.

Browser support is now good (Chrome, Firefox, Safari 16+, Edge), but "good" is not "universal"
the way WebP is, so a fallback story is still needed.

## What it would need

- An `output_format` setting: WebP (default) / AVIF
- Per-engine AVIF capability detection — `gd_info()['AVIF Support']`,
  `Imagick::queryFormats('AVIF')`, and `avifenc` for the binary path
- A much more conservative batch size when AVIF is selected. The current adaptive sizing
  targets half of `max_execution_time` and would thrash badly at 5-10x the cost per image.
- An explicit warning in the UI about encode time before a bulk run
- A decision on fallback: serve AVIF to everyone and accept the tail, or keep a WebP alongside
  — which reintroduces the dual-file problem this plugin deliberately avoided

## Open question

Does this belong in a plugin whose entire delivery model is "replace the file, rewrite the
URLs"? AVIF's weaker browser support argues for a `<picture>` fallback, which is precisely the
architecture we rejected. It is possible AVIF only makes sense if the delivery model changes
too — in which case this is a much larger piece of work than it first appears.

## Effort

Medium for the encoding. Large once the fallback question is taken seriously.
