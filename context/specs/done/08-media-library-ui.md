# Unit 08 — Media Library UI

## Goal

Make the plugin visible and usable from the Media Library:

1. A **Bulk Optimize** button in the grid-view toolbar, driving a multi-select run
2. An **Optimize** button in the attachment modal for images not yet converted
3. An **optimization card** in the modal for images that are

Grid view is WordPress's default and had nothing at all — no way to optimize, no sign the
plugin existed. The modal payload was already being emitted by Unit 05 and nothing consumed it.

## Read first

- `wp-includes/js/media-views.js` — `AttachmentsBrowser.createToolbar()` around line 4521
- `wp-includes/js/media-grid.js` — `DeleteSelectedButton` (236), `SelectModeToggle` (296),
  `Attachment.Details.TwoColumn` (134)
- `src/Media/ListTable.php` — `expose_to_js()` and `reason_label()`

## The core mechanic everything depends on

`wp.media.view.Button` renders with `className: 'media-button'`, and core's
`SelectModeToggle.toggleBulkEditHandler()` **skips `.media-button` in both directions**:

```js
// media-grid.js:337 — entering select mode
children.not( '.spinner, .media-button' ).hide();
// media-grid.js:350 — leaving select mode
children.not( '.media-button' ).show();
```

A `wp.media.view.Button` in the toolbar is therefore never touched by core's show/hide logic.
Its visibility is entirely ours to manage. That is what makes an always-visible custom button
possible without patching core — and it is also why both new buttons have to toggle their own
`hidden` class on `select:activate` / `select:deactivate`.

## Files changed

| File | Purpose |
|---|---|
| `src/Database.php` | Schema v2: `conversion_ms` column |
| `src/Optimizer.php` | Times the encode, returns `duration_ms` |
| `src/Upload/Interceptor.php` | Persists `conversion_ms` |
| `src/AttachmentConverter.php` | Persists `conversion_ms` |
| `src/Media/ListTable.php` | `expose_to_js()` widened; emits for convertible mimes even with no log row |
| `src/Admin/Assets.php` *(new)* | Enqueues the bundle wherever the media views are loaded |
| `src/Plugin.php` | Registers `Assets` |
| `admin/media.js` *(new)* | Toolbar buttons, progress runner, modal panel |
| `admin/media.scss` *(new)* | Card and progress styles |
| `webpack.config.js` | Second entry point |

## Design notes

**Two buttons, distinct roles.** `OptimizeModeButton` ("Bulk Optimize") is the always-visible
entry point; clicking it activates core's select mode. `OptimizeSelectedButton` ("Optimize (N)")
appears only in select mode and mirrors `DeleteSelectedButton` exactly — same
`selection:toggle` / `select:activate` listeners, same disabled-when-empty behaviour.

**Client-side chunking.** `Rest\Controller::optimize()` loops over every ID in one request with
no internal batching, so a large selection would guarantee a timeout. The runner sends 3 IDs
per call (`chunkSize` in the localized config).

**Refetch is mandatory, not cosmetic.** Conversion changes the filename, so every converted
thumbnail in the grid would 404. `model.fetch()` re-runs `wp_prepare_attachment_for_js` and
returns both the new URL and the fresh payload.

**Progress bar sits outside the priority lists.** It is appended straight to the toolbar
element rather than to `media-toolbar-primary` / `-secondary`, so core's select-mode show/hide
— which targets `> *` of those two containers — never touches it.

**No React.** The media modal is Backbone. The bundle depends on `wp-i18n` only and weighs
9.8 KB minified, versus the dashboard's 12.6 KB plus React. It loads on many admin screens, so
that matters.

**Both details views are patched.** `Attachment.Details` and `Attachment.Details.TwoColumn` —
the modal uses a different one depending on how it was opened, and patching only one leaves the
other blank.

## Completion Notes

Verified in the real browser on cb-test.local (WP 7.0.3), which is the first browser
verification this plugin has ever had.

**Toolbar**

| Check | Result |
|---|---|
| Normal mode | "Bulk Optimize" visible immediately after "Bulk select" |
| Click it | Enters select mode; Bulk Optimize hides, "Optimize" + "Delete permanently" appear, both disabled |
| Select one image | "Optimize (1)", both buttons enable |
| Cancel | Filters, dates and search all return; both mode buttons hide; no leftovers |
| Console | Zero errors |

**Modal panel** — all three states confirmed live:

| Attachment | State | Rendered |
|---|---|---|
| #11 | optimized, backup live | Card + **Restore original** |
| #12 | skipped | "Already efficient, WebP would be larger" + **Try again** |
| #106 | optimized, no backup | Card + "No original stored, so this cannot be restored." |

A real card reads:

```
Image Optimized
Saved 92.1%  (-814.1 KB)
Original:     883.9 KB
Optimized:  ↓  69.7 KB
Converted to WebP
Engine: imagick
Done in 800 ms
```

#11 correctly omits the duration line — it is a pre-migration row with `conversion_ms = 0`.

**Schema migration** — the plugin's first ever. Verified against a seeded v1 table: column
added, existing row preserved with the `0` default, version option advanced 1 → 2. It also ran
unattended on cb-test during a normal page load, exactly as intended.

**Test suite** — 143 assertions across five harnesses, all passing.

### The backup asymmetry

Images optimized **on upload** have no backup: `Upload\Interceptor` deletes the source rather
than copying it. Only the bulk/modal path (`AttachmentConverter`) creates one. So "Restore
original" appears for some optimized images and not others.

This was verified live on two of the site's own uploads: `#105` went through the converter
(backup present) and `#106` through the upload path (no backup). Rather than silently omitting
the button, the panel now says *"No original stored, so this cannot be restored."*

Whether uploads should also be backed up is a genuine open product question — it would double
storage for every upload, forever. Left as-is for now.
