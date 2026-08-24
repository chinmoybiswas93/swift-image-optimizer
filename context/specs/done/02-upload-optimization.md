# Unit 02 — Upload Optimization (Feature 1)

## Goal

Convert images to WebP as they are uploaded, so that from WordPress's point of view a `.webp`
was uploaded in the first place.

## The insight this unit is built on

`wp_handle_upload` fires **after** the file is moved into `uploads/` but **before**
`wp_insert_attachment()` runs. Replacing the file and returning an updated `$upload` array
means WordPress creates the attachment as a native WebP and generates every subsize as WebP
itself.

**Nothing references the file yet, so no URL rewriting is needed.** This is what keeps Feature 1
small. Do not confuse it with Feature 2, which has the opposite problem.

In WP 7.0 the same filter fires for sideloads too, with `$context` set to `'sideload'`, so one
hook covers `media_sideload_image()`, importers and form plugins.

## Pipeline

```
1. auto_optimize off?                → return unchanged
2. can_optimize()                    → mime / format / engine / memory
3. optimize()                        → temp .webp beside the original
4. result >= original bytes?         → discard, log 'skipped-larger', keep original
5. rename( temp, photo.webp )
6. wp_delete_file( photo.jpg )
7. return $upload pointing at the WebP
8. park the result; bind it on add_attachment
```

Supporting hooks: `upload_mimes` (allow webp), `big_image_size_threshold` (honour the
"disable WP scaling" setting), `add_attachment` (bind the parked log row).

## Guards

| Guard | Why |
|---|---|
| Skip `image/webp` | Already done |
| Skip anything outside JPEG/PNG | GIF animation, SVG, HEIC are out of scope |
| Skip if the WebP is not smaller | Common for small flat-colour PNGs |
| Estimate `w × h × 4 × 2` against `memory_limit` | The most common shared-host fatal |
| Skip logging for uninteresting reasons | `unsupported-format`, `already-webp`, `png-disabled` produce no row — they would be pure noise |

## Completion Notes

Verified end to end against real WordPress (`upload-e2e.php`, 22 assertions):

| Case | Result |
|---|---|
| 267.5 KB JPEG | → 130.1 KB WebP, 51.4% saved, **all 5 subsizes WebP**, old `.jpg` removed |
| 1.52 MB photographic PNG with a transparent corner | → 69.8 KB WebP, transparency preserved, photo body opaque |
| 4.4 KB flat-colour alpha PNG | Kept as `.png`, logged `skipped/skipped-larger` |
| Existing `.webp` upload | Byte-identical passthrough, **no log row** |
| Stats aggregate | +2 optimized, +1 skipped, saved bytes grew |

**Two test-fixture bugs, not code bugs**, worth recording so they are not re-litigated:

1. An 8×8 flat white PNG was expected to be skipped as "WebP would be larger". It is not —
   103 B → 44 B. Tiny flat PNGs compress *better* as WebP. The skip fixture had to be changed
   to a large flat-colour image **with alpha**, which is PNG's best case and WebP's worst.
2. A synthetic random-ellipse alpha PNG was expected to convert. It was correctly skipped for
   the same reason. The fixture became a real photograph with a punched-out transparent corner,
   which is where WebP genuinely wins.

The lesson both times: the code was right and the expectation was wrong. Check which before
changing anything.
