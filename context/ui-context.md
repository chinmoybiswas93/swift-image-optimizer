# UI Context

## Where the plugin appears in wp-admin

| Location | What | Built by |
|---|---|---|
| **Media → Bulk Optimize** | The React dashboard: Bulk / Settings / Backups / Troubleshoot tabs | `Hooks\Handlers\MenuHandler` + `resources/admin/` |
| **Media Library, list view** | "Optimization" column | `MediaLibraryHandler::column_state()` |
| **Media Library, row hover** | Optimize / Restore original links | `MediaLibraryHandler::add_row_actions()` |
| **Media Library, bulk dropdown** | Optimize images / Restore originals | `MediaLibraryHandler` |
| **Media modal, grid view** | Optimization panel in the attachment sidebar | `resources/media/media.js` (Unit 08) |
| **Plugins screen** | "Settings" action link | `Hooks\Handlers\MenuHandler` |
| **This plugin's screen** | Other plugins' notices stripped, ours kept | `Hooks\Handlers\ForeignNoticeHandler` |

## Design rules

**No control is `@wordpress/components`.** Every one is the plugin's own, in
`resources/admin/Components/` — `Toggle`, `Range`, `Select`,
`NumberInput`, `Button`, `Notice`, `Spinner`, plus `ProgressRing` added in Unit 12. `Card` /
`CardHeader` / `CardBody` are the plugin's own too, not core's.

**The chrome around those controls is ours.** The masthead, the savings hero and the section
headers are custom so the screen reads as a finished product rather than an options page. The
line to hold: brand the *frame*, never the *controls*. No upsell strip, no fake pro teasers.

Components in use: `Card` / `CardHeader` / `CardBody`, `Button`, `Notice`, `Toggle`, `Range`,
`Select`, `NumberInput`, `Spinner`, `ProgressRing`.

**Tabs are ours** (`admin/tabs.js`), not core's `TabPanel`. Core draws the active indicator as an
`::after` pinned to the item's bottom edge and animated with `transition: all`, which cannot be
made to sit flush with a rule beneath it — every override left a second, offset line mid-switch.
Ours uses the tab's own `border-bottom`, pulled down 1px to cover the rule. It implements the
full WAI-ARIA tabs pattern: `tablist`/`tab`/`tabpanel`, roving `tabindex`, and arrow/Home/End
keys with wraparound. Two rules matter if you touch it:

- **Never transition `border-color`** on the tab. Fading it means the outgoing and incoming tabs
  both carry a part-coloured underline for the duration — the bug this component was built to
  kill. Only `color` may animate.
- The `::after` carrying `attr(data-title)` at `font-weight: 600` is not decoration; it reserves
  the bold width so activating a tab cannot reflow the row.

Classes are all prefixed `sio-`:

```
sio-app  sio-masthead  sio-masthead__mark  sio-masthead__title  sio-masthead__tagline  sio-pill
sio-hero  sio-hero__label  sio-hero__value  sio-hero__badge  sio-hero__sub  sio-hero__metrics
sio-metric  sio-metric__icon  sio-metric__value  sio-metric__label
sio-tabs  sio-tabs__list  sio-tabs__tab  sio-tabs__panel  sio-card  sio-section  sio-section__header  sio-section__icon  sio-section__title
sio-section__desc  sio-lede  sio-stats  sio-stat  sio-stat__value  sio-stat__label  sio-muted
sio-progress  sio-progress__bar  sio-progress__fill  sio-progress__meta
sio-actions  sio-savebar  sio-dryrun  sio-tablecounts  sio-errors
sio-diagnostics  sio-diagnostics__title  sio-diagnostics__row  sio-diagnostics__label
sio-diagnostics__value  sio-diagnostics__hint  sio-diagnostics__state
sio-logtoolbar  sio-logtoolbar__filter  sio-logviewer  sio-logviewer__line
```

Icons live in `admin/icons.js` — ten hand-rolled inline SVGs plus the logo mark. Deliberately
not a package: the plugin ships no icon dependency and only needs these.

Colours are the WordPress admin palette, with the brand indigo matching the admin accent so the
custom chrome and core's own controls agree:

| Meaning | Colour | Used for |
|---|---|---|
| Brand | `#3858e9` → `#2c3fc0` | Logo mark, section icons, progress fill, active tab |
| Success | `#008a20` | Savings badge, "already processed" |
| Warning | `#bd8600` | Pending count when non-zero |
| Error | `#d63638` | Failed conversions, original-size metric |
| Muted | `#757575` | Labels, help text |

## Page furniture

Above the tabs, on every screen: the **masthead** (logo mark, name, tagline, active-engine pill)
and the **savings hero** — total storage saved as the headline figure with a "% smaller" badge,
beside three metrics (images optimized, original size, optimized size). Every value comes from
`StatsRepository::get()`, already localized; the hero needs no extra request.

`summary` and `stats` live in `App` so a finished bulk run refreshes the hero, not just the tab.

## The four tabs

### Bulk Optimize

**Rewritten in Unit 12.** Three separate cards (stat tiles, dry run, progress) merged into one —
see `resources/admin/Partials/LibraryCard.jsx`. A circular ring (`ProgressRing`) leads, reading
`optimized / total_images` from a stored, disk-verified scan snapshot; tiles, the Scan button and
last-scanned time sit beside it; the dry-run preview lives in a `<details>` disclosure inside the
same card, still above the Bulk Optimize button for the same reason as before. Space saved is
still not repeated here — the hero above headlines it, now from the same snapshot.

The dry-run card is deliberately placed *above* the start button. Given the plugin rewrites
URLs destructively, the user should meet the preview before they meet the trigger.

### Settings

Three always-visible section cards: Conversion, Existing images, WordPress behaviour. Each has
an icon, a title and a one-line description saying what the section governs. Accordions were
dropped — collapsing hid settings behind a click and made the page look unfinished. The Save
button sits in a sticky `sio-savebar` at the foot so it stays reachable down a long page.

Help text on the destructive toggles is written to be honest rather than reassuring, e.g.
disabling URL rewriting says *"Turning this off will leave broken images."*

### Backups

Disk usage, an explanation of retention, and a destructive purge button.

### Troubleshoot

Last, where support tools usually live. Three sections:

**Server information** — the `EnvironmentReport` rendered as a table of label / value / state
dot, grouped into engines, PHP, filesystem, WordPress and plugin. A row that is not OK carries a
one-line remedy under its value, written to the copy rules above ("Ask your host to enable the
WebP delegate", never "queryFormats returned empty"). A **Copy for support** button puts the
whole report on the clipboard as plain text.

**Activity log** — the Enable Log toggle, a filter box, and a fixed-height monospace viewer on a
dark ground (`#1d2327`, the admin's own dark) that auto-scrolls to the newest line. `ERROR` lines
render red, `WARN` amber. Then Refresh, Download and a destructive Reset behind a confirm. The
help text states the three things a user needs to decide with: what is recorded, that failures
are recorded regardless, and that the file is capped and never leaves the server.

**Maintenance** — two stat tiles (images worth retrying, leftover temporary files), each with the
button that acts on it. Both buttons disable at zero, per "disable, don't hide".

The tab is deliberately readable by someone who did not build the plugin: it is the screen a user
is asked to open when they report a problem, so nothing on it assumes prior knowledge.

## Interaction rules

- **Confirm before anything destructive**, through the plugin's own `ConfirmDialog` — there is no
  `window.confirm()` left anywhere, including the Backbone media surface, which uses
  `resources/media/confirm.js` to build the same markup. The backup purge additionally requires
  typing `DELETE`. Repair, which deletes nothing, deliberately does not.
- **The loop is driven from wherever is watching, with cron as the floor.** An open tab still
  pumps batches directly (each is one REST call, continuing while `state.running`), which is
  faster than waiting on WP-Cron — but since Unit 11/12, cron advances the same run on its own via
  `BulkJobRunner` / `ScanJobRunner`, so closing the tab pauses the pace, not the run. A slow server
  takes more requests rather than timing out either way.
- **Stop must be immediate.** A `useRef` flag breaks the loop without waiting for a re-render.
- **Progress survives a closed tab.** State lives in an option; the dashboard calls
  `bulk/status` on mount and resumes if a run is live.
- **Never show a bare error code.** `MediaLibraryHandler::reason_label()` maps every internal code
  to a sentence, e.g. `skipped-larger` → "Already efficient, WebP would be larger".
- **Disable, don't hide.** Start is disabled when no engine exists or nothing is pending.

## Copy guidelines

Write for a site owner, not a developer.

| Instead of | Write |
|---|---|
| "Conversion failed: ENOMEM" | "Too large for the available memory" |
| "skipped-larger" | "Already efficient, WebP would be larger" |
| "GD extension not loaded" | "Ask your host to enable GD with WebP support, or Imagick" |
| "51.4% compression ratio" | "51.4% smaller" |

State what will happen before it happens, and never imply an irreversible action is safe.
The bulk confirmation names the three facts that matter: it converts, it updates references,
originals are backed up first.

## Not built

- **Before/after preview** — no visual quality comparison anywhere. Worth considering given the
  plugin permanently replaces the original. Scoped in `future-specs/before-after-preview.md`.
