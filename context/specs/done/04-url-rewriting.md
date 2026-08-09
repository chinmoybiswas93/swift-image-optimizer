# Unit 04 — URL Rewriting

## Goal

When an existing image changes filename, repoint every reference to it — across post content,
serialized meta, page-builder JSON, and options — without corrupting anything.

**This is the most dangerous code in the plugin.** It is built and verified in complete
isolation before Unit 05 calls it.

## Read first

- `src/Database.php` — the `url_map` column
- WordPress's `is_serialized()` in `wp-includes/functions.php`

## Files changed

| File | Purpose |
|---|---|
| `src/Rewrite/UrlMap.php` | Builds old→new pairs for every size and URL form |
| `src/Rewrite/DatabaseRewriter.php` | Serialization-safe replace across 5 tables |
| `src/Rewrite/Fallback404.php` | Serves the WebP when an old URL is requested |

## The rule everything else follows

**Never run a plain string replace over serialized data.**

PHP serialization embeds byte lengths: `s:9:"photo.jpg"`. Replacing `photo.jpg` with
`photo.webp` without updating the `9` produces a value that can never be unserialized again.
The row is silently destroyed, and it is usually a page builder's entire page layout.

Every value is therefore unserialized → walked → re-serialized. Test 4 of the rewriter suite
asserts this directly: it performs the naive `str_replace` and confirms the result **fails**
to unserialize, while ours survives with correct declared lengths.

Supporting rules:

- Unserialize with `allowed_classes => false` — blocks object injection
- If the result contains `__PHP_Incomplete_Class`, **leave the row untouched** and mark it
  unsafe. Writing back a payload we cannot faithfully reconstruct is worse than skipping it.
- If a string looks serialized but will not unserialize, leave it alone entirely
- Use `strtr()`, not `str_replace()` — it applies the longest key first and never re-scans
  text it has already substituted

## URL forms

`UrlMap::build()` covers the main file, the `-scaled` variant, `original_image`, and every
registered subsize. `expand()` then produces each in three forms:

| Form | Example |
|---|---|
| Absolute | `https://site.com/wp-content/uploads/2026/08/photo.jpg` |
| Protocol-relative | `//site.com/wp-content/uploads/...` |
| Root-relative | `/wp-content/uploads/...` |

`with_escaped_slashes()` adds `https:\/\/site.com\/...`, which is how Elementor and Bricks
store URLs inside JSON meta values.

**This is why there is no `JsonRewriter` class**, despite the original plan calling for one.
Because the escaped form is in the map, a plain `strtr` over the raw string rewrites JSON
correctly without decoding it. Parse-modify-reencode would risk changing unrelated formatting.

Map keys are sorted longest-first so `photo-300x200.jpg` is never partially matched by a
shorter key.

## Tables scanned

`posts` (`post_content`, `post_excerpt`, `post_content_filtered`), `postmeta`, `options`,
`termmeta`, `usermeta`.

Skipped by name — regenerated from source data, so rewriting them is wasted work in a format
we do not control: `_elementor_css`, `_elementor_inspector_data`, `_bricks_css*`,
`_transient_*`, `_wp_attachment_metadata`, `_wp_attached_file`. These are flushed instead.

**Attachment references stored as IDs are not touched.** They resolve through metadata, which
Unit 05 updates separately.

## Query strategy

`OR LIKE` built from bare filenames — every URL form contains the filename — chunked at 40
terms, paging by primary key in batches of 500. Memory stays flat regardless of library size.

## Fallback404

`template_redirect` at priority 0: if the request is a 404 for a `.jpg`/`.jpeg`/`.png` path
whose basename appears in a stored `url_map`, redirect **302** to the replacement.

Works because WordPress's standard `.htaccess` routes non-existent files through `index.php`.
302 rather than 301 deliberately — a permanent redirect would be cached by browsers and CDNs
forever, and becomes wrong the moment the image is restored from backup.

## Completion Notes

`rewriter-test.php` — **21 assertions, all passing**, no WordPress required:

| # | Case | Result |
|---|---|---|
| 1 | Plain string | Replaced |
| 2 | Longest-match-first | Size variant and full size both correct |
| 3 | Serialized array | Unserializes; nested rewritten; **declared byte length correct** |
| 4 | Naive `str_replace` comparison | **Confirmed to corrupt** — this is the control |
| 5 | JSON with escaped slashes | Rewritten, still valid JSON |
| 6 | Serialized JSON-in-string (Elementor shape) | Both layers survive |
| 7 | Multibyte (`Café naïve 日本語 🎉`) | Byte lengths stay correct |
| 8 | Serialized object | Marked unsafe, returned **untouched** |
| 9 | No match | Byte-identical passthrough |
| 10 | 5-level nested array | Rewritten |

### Post-build fix

The skip check originally ran a **separate query per matched row** to read `meta_key` /
`option_name`. On a library this size that is tens of thousands of extra round trips. The
filter column is now selected as part of the main query, and the check moved *before* the
replacement counting — so dry-run numbers now reflect what a real run would actually change.
