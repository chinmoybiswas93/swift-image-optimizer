# Unit 14 — Only this plugin's notices on this plugin's screen, and a card that breathes at the bottom

## Goal

Two user-reported defects on the **Bulk Optimize** screen
(`upload.php?page=swift-image-optimizer`, screen id `media_page_swift-image-optimizer`):

1. **I-10 (reopened)** — other plugins' admin notices render on this plugin's page. In this
   plugin's UI, only this plugin's own notices and toasts appear.
2. **I-15** — the bottom of the library card has no spacing: the info notice sits flush against
   the Bulk optimize button, and the "Preview what would change" disclosure above it is an
   unstyled `<details>`.

## I-10 is a reopen, not a regression

Unit 11 fixed the half the plugin owned: it stopped emitting core's `notice` class and stopped
hooking `admin_notices` on every admin screen. It then added `<hr class="wp-header-end">` so
foreign notices stopped being injected *through the middle* of the React masthead — and the
view's docblock argued explicitly that hiding them "would be its own kind of rude."

The screenshot behind that decision showed, in practice: PixelYourSite,
FluentSMTP and Elementor notices, correctly positioned, filling the viewport above the plugin's
own header. The user has now decided the other way, and that decision supersedes the docblock.

## Decisions taken with the user before building

| Question | Decision |
|---|---|
| Which screens are cleared | **This plugin's screen only.** Not `upload.php`, not `plugins.php` |
| Where the "already optimized" notice goes | **Stays below the button.** Fix the spacing, not the reading order |
| Browser reproduction of I-15 first | **No.** Fix from source, verify by build. I-3 stays open |

## Part 1 — suppression

New handler `app/Hooks/Handlers/ForeignNoticeHandler.php`, registered from `actions.php` beside
`NoticeHandler` inside the existing `is_admin()` block.

**Hook: `in_admin_header`.** Verified against this site's `wp-admin/admin-header.php`:
`admin_enqueue_scripts` fires at line 123, `in_admin_header` at 277, and the four notice hooks at
299–321. So by `in_admin_header` every plugin has registered — including `MenuHandler::enqueue()`,
which adds `missing_build_notice` conditionally at enqueue time — and none has rendered.

**Whitelist, don't blanket-remove.** `remove_all_actions()` would also take out
`NoticeHandler::maybe_render_no_engine_notice` and `MenuHandler::missing_build_notice`. The second
one *must* survive: when the bundle is missing, React cannot report anything, so that notice is
the only thing standing between the user and a blank page. The handler therefore walks
`$GLOBALS['wp_filter'][ $hook ]->callbacks` and removes each callback that is not this plugin's,
across all four notice hooks (`admin_notices`, `all_admin_notices`, `user_admin_notices`,
`network_admin_notices`).

Reading `$wp_filter` is the only way to enumerate registered callbacks. It is a WordPress global,
not `$wpdb`, so `agent.md`'s "never `global $wpdb`" rule does not apply — but the reason is
written into the docblock so a later reader does not file it as a smell. Each priority bucket is
copied before iterating, because `remove_action()` mutates the structure being walked.

**Escape hatch:** `apply_filters( 'swift_image_optimizer_hide_foreign_notices', true )`. If a
host's critical notice is ever the thing a user needs to see, there is a one-line way back.

`.wp-header-end` stays — the plugin's own two notices still ride the same hooks and still need
positioning. Its docblock is rewritten to state the current policy.

**WordPress.org:** scoping to one screen id is what keeps this within guideline 11 ("not hijack
the admin dashboard"). Clearing a screen the plugin owns is established practice; clearing a core
screen is not, which is why `upload.php` and `plugins.php` are untouched and
`MediaLibraryHandler::bulk_notice` is left alone.

## Part 2 — card spacing

CSS only, in `resources/styles/admin.scss`. `LibraryCard.jsx` already renders the right elements
in the order the user chose to keep.

| Symptom | Cause | Fix |
|---|---|---|
| Info notice flush against the Bulk optimize button | `.sio-notice` has `margin: 0 0 16px` — bottom only | `.sio-actions + .sio-notice { margin-top: 16px }`, scoped to the adjacency rather than changing the shared `.sio-notice` margin every screen uses |
| Dangling gap under the last notice | Same bottom margin, on top of the card body's 24px padding | `.sio-card__body > :last-child { margin-bottom: 0 }` |
| "Preview what would change" unstyled, flush against the button | `.sio-dryrun__panel` has **no rule at all** — grep across `resources/styles/` returns nothing | A `.sio-dryrun__panel` block mirroring `.sio-scanmeta__details`, including a focus ring on `summary:focus-visible` — a `<summary>` is interactive, and every other control on this screen keeps one |

**Honest limit.** No rule in the source produces literal *overlap*; what the source produces is
zero separation, which is what `image-8.png`'s abutting boxes are consistent with. If after the
rebuild the boxes still overlap rather than merely sit tight, that is a distinct defect needing
the browser pass (I-3) — say so rather than declaring I-15 closed.

## Completion Notes

Shipped as specified. Two things worth recording:

**The harness was worth more than expected.** `tests/php/notice-strip-test.php` runs against
WordPress's own `wp-includes/plugin.php` and `WP_Hook` rather than a stub, because the whole
handler is an assertion about how `WP_Hook` stores callbacks — a hand-rolled hook emulator would
have proved nothing about the structure it actually walks. It needs no database, so it runs
beside `rewriter-test` at the front of the suite. 9 assertions: the four hooks, the
missing-build notice surviving, closures counting as foreign, `upload.php` and `plugins.php`
untouched, and the filter escape hatch. Suite total 33 + 9 + 68 + 131.

**What is *not* established: the word "overlap".** `image-8.png` reads as boxes overlapping by
some pixels. Nothing in the source produces overlap — `.sio-notice`'s missing top margin produces
*zero separation*, which is what abutting boxes look like. The fix is correct for zero separation
and was verified through the compiled `build/admin.css`. If a real page load still shows overlap,
this unit did not close I-15 and the cause is still unfound. Recorded in the I-15 entry and added
to I-3 rather than left implicit.

**Not run:** `/security-review`. The working tree carries Unit 13's uncommitted work across ~30
files, so a diff review would report on unfinished code belonging to another unit rather than on
this one. This unit adds no input handling, no output, no SQL, no filesystem access and no REST
surface — it reads `$wp_filter` and calls `remove_action()`. Worth running once Unit 13 lands and
the tree is clean.
