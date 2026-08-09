# Unit 07 — React Admin

## Goal

A Media → Bulk Optimize screen that drives the bulk runner, exposes settings, and manages
backups — built with `@wordpress/components` so it looks like part of WordPress.

## Read first

- `src/Rest/Controller.php` — every route the UI calls
- `src/Admin/Settings.php` — `show_in_rest` schema, so settings save via `/wp/v2/settings`

## Files changed

| File | Purpose |
|---|---|
| `src/Admin/SettingsPage.php` | Media submenu, React mount, `wp_localize_script` |
| `admin/index.js` | The dashboard |
| `admin/index.scss` | Styles, all `sio-` prefixed |
| `package.json` | `@wordpress/scripts` as the only dev dependency |
| `webpack.config.js` | Entry-point override |
| `.gitignore` | Ignores `node_modules/`, keeps `build/` |

## Build setup

`wp-scripts` expects `src/index.js`. `src/` here is PHP, so `webpack.config.js` spreads the
default config and overrides `entry` to `admin/index.js`. Output lands in `build/admin.js`,
`build/admin.css`, `build/admin-rtl.css` and `build/admin.asset.php`.

```bash
npm install && npm run build
```

**`build/` is committed.** WordPress.org ships the directory as-is; there is no build step on
the user's server. `SettingsPage::enqueue()` shows an actionable notice if
`build/admin.asset.php` is missing rather than failing silently.

## Structure

Three tabs via `TabPanel`:

| Tab | Contents |
|---|---|
| **Bulk Optimize** | Stat tiles, engine status, dry run, progress, start/stop, failure list |
| **Settings** | Conversion / Existing images / WordPress behaviour panels |
| **Backups** | Disk usage, retention explanation, purge |

Settings save through `/wp/v2/settings` using the `show_in_rest` schema registered in Unit 01 —
no custom settings endpoint.

## The bulk loop

```js
POST bulk/start
do {
  if ( stopped.current ) break;
  state = await POST bulk/batch;
  setProgress( state );
} while ( state.running );
```

Each batch is one request, so a slow server takes more requests rather than timing out. Stop
uses a `useRef` so the loop breaks immediately rather than waiting for a re-render. On mount
the dashboard calls `bulk/status` and resumes a run that is already live.

## Safety in the UI

- `window.confirm()` before starting bulk and before purging backups, both naming exactly what
  will happen
- The dry-run card sits **above** the start button — the user meets the preview before the
  trigger
- Start is disabled when no engine is available or nothing is pending
- Destructive setting toggles carry honest help text, e.g. disabling URL rewriting says
  *"Turning this off will leave broken images."*

## Completion Notes

Build succeeds: `admin.js` 12.6 KB minified, `admin.css` 1.37 KB, plus an RTL stylesheet
generated automatically.

All 8 REST routes verified registered and reachable by `bulk-e2e.php`.

**The React UI has not been exercised in a browser.** Verification covered the build, the
asset manifest, the enqueue path and every endpoint it calls — but no click-through happened.
Before release, confirm by hand: tab switching, the dry-run report rendering, progress
updating during a live run, stop actually stopping, settings persisting, and the confirm
dialogs appearing.

Not built: the media **grid** modal sidebar panel. `wp_prepare_attachment_for_js` already
attaches a `swiftImageOptimizer` key with status, sizes, reason and `canRestore`, but nothing
consumes it. The list-view column is currently the only place status is visible. Drafted as
Unit 09.
