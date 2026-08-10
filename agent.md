# Agent Instructions — Swift Image Optimizer

You are working inside the **swift-image-optimizer** WordPress plugin.
PHP 7.4+ and React built with `@wordpress/scripts`. Not Node, not Next.js, no TypeScript.

Everything lives under `app/public/wp-content/plugins/swift-image-optimizer/`. Composer
autoloads three roots, with **no runtime dependencies** — `vendor/` holds only the generated
autoloader, and it is committed because WordPress.org runs no build step on the destination
server:

| Namespace | Path | Autoload |
|---|---|---|
| `SwiftImageOptimizer\App\` | `app/` | psr-4 — application code |
| `SwiftImageOptimizer\Framework\` | `framework/` | psr-4 — the kernel |
| `SwiftImageOptimizer\Api\` | `api/` | psr-4 — stable data-access layer |
| `SwiftImageOptimizer\Database\` | `database/` | classmap |

Text domain, slug, and option prefix are all `swift-image-optimizer`.

## Layout

The plugin follows a FluentCart-style layout. There is **no `src/`, no `Providers/`, no
`Repositories/`, no `Foundation/` inside `app/`, and no `Http/Admin/`** — do not reintroduce them.
`app/` holds application code only; the kernel lives at its own root in `framework/`, the way
FluentCart keeps `FluentCart\Framework\*` outside `app/`.

```
swift-image-optimizer.php   IIFE bootstrap: require boot/app.php + vendor/autoload.php
boot/       app.php (kernel closure), bindings.php (container singletons), globals.php
config/     app.php, optimizer.php, vite.php — plain arrays, read via App::config()->get('app.slug')
framework/  Application, Container (reflection auto-wiring), Config, View, Router, Route
app/
  App.php           static facade: make/view/config/router/path/url
  Hooks/            actions.php is the registration manifest; Handlers/, Scheduler/, CLI/
  Http/             Controllers/, Policies/, Requests/, Routes/{routes,api}.php
  Models/           Model (thin $wpdb base), OptimizationLog, UrlLookup
  Modules/          reserved for self-contained feature packages
  Services/         the domain layer (Optimizer, AttachmentConverter, Engine/, Backup/, …)
  Views/            plain-PHP templates; App::view()->render('admin.admin_app', $data)
api/        StoreSettings (singleton, aliased `settings`), Resource/{BaseResourceApi,StatsResource}
database/   DBMigrator, DataBackfills, Migrations/{LogMigrator,UrlMigrator}
resources/  React + SCSS source (excluded from the shipped zip)
build/      wp-scripts output + *.asset.php (committed)
```

Registration goes in `app/Hooks/actions.php` — one readable list of `(new Handler)->register();`
lines. Routes go in `app/Http/Routes/api.php` using the fluent DSL
(`$router->prefix('bulk')->withPolicy('AdminPolicy')->group(...)`); every route needs a policy,
because a route without one is refused rather than made public.

## What makes this plugin different from any other

**It rewrites and deletes user media, and the backup is often the only copy of the original.**
A bug here does not produce a broken page — it produces a media library the user cannot get
back. That single fact drives every rule below. `context/ai-workflow-rules.md` has the full
version; read it before your first edit in a session.

## Skills — reach for these before doing it by hand

| Skill | When |
|---|---|
| `graphify` | **First, always.** Any "where is X / how does X work / what calls X" question. |
| `playwright-cli` | Driving a real browser — repro a bug, read console errors, find a selector. |
| `owasp-security-review` | Auditing a file or subsystem for vulnerabilities. |
| `/security-review` (built-in) | The pending diff, before a unit is marked complete. |
| `wp-plugin-directory-guidelines` | `readme.txt`, plugin header, slug, GPL, upsell/trialware. |
| `wordpress-pro` | WordPress **API** questions — which sanitize/escape function, hook signatures and priorities, capability/nonce patterns, i18n, transients. Scoped below. |
| `php-pro` | PHP **language** questions only — see the constraint below. |

`php-pro` is written for Laravel/Symfony and prescribes PHPStan level 9, PHPUnit/Pest, and 80%
coverage. **None of that exists here.** Take its language guidance; ignore its tooling and
framework sections entirely. If it tells you to install a package, that is out of scope — don't.

Project skills live in `app/public/.agents/skills/` and load when working from `app/public`.

### `wordpress-pro` is scoped to the WordPress API, not to architecture

It is the right reference for *which WordPress function to call* — `sanitize_text_field` vs
`wp_kses_post` vs `esc_url_raw`, `esc_html` vs `esc_attr` on output, nonce and
`current_user_can()` patterns, hook priorities, `__()`/text-domain rules, transients and object
caching. That is exactly the four-part input rule this plugin already mandates, and
`references/hooks-filters.md` and `references/performance-security.md` are worth loading for it.

It is the wrong reference for how this plugin is built, because it assumes a conventional plugin.
Where they disagree, **this file wins**:

| `wordpress-pro` says | Here |
|---|---|
| `global $wpdb` + `$wpdb->prepare()` | Go through `app/Models/`. `prepare()` still applies *inside* the two documented exceptions — see Hard rules |
| `phpcs --standard=WordPress` | `npm run lint:php` — `phpcs.xml.dist` is WordPress-Extra + WordPress-Docs, `testVersion 7.4-` |
| "WordPress 6.4+, PHP 8.1+" | **PHP 7.4+ / WP 6.2+.** No enums, readonly, constructor promotion, or `never` — `PHPCompatibilityWP` fails the build. This caps `php-pro` too |
| `wp_enqueue_script()` with `get_*_directory_uri()` and a hand-written version string | `app/Hooks/Handlers/AssetHandler.php`. Dependencies and version come from the generated `build/*.asset.php` — never hand-list them |
| "don't bundle libraries when WordPress APIs suffice" | True for the **runtime** (`@wordpress/element`, `@wordpress/api-fetch`, `@wordpress/i18n` — all externalized by wp-scripts) and it is why `react`/`react-dom` are not in `package.json`. It is **not** true for UI: `@wordpress/components` is banned, every control is ours, markup uses `sio-*`. Never add a Composer runtime dependency either |
| `register_rest_route()` with an inline permission callback | `app/Http/Routes/api.php` fluent DSL; the permission callback *is* the Policy |
| Settings API, `WP_List_Table`, `add_menu_page` scaffolding | Deliberately absent — `boot/`, `App::`, `Router`, `App::view()`. Do not reintroduce |
| "run a security audit checklist" | Implementation-time hygiene only. The verdict is still `/security-review` on the diff and `owasp-security-review` on the subsystem |

Never load `references/theme-development.md` or `references/gutenberg-blocks.md` — there is no
theme, no block, and no FSE in this plugin. WooCommerce and ACF are not installed. Read
`references/plugin-architecture.md` only for plugin-header and uninstall semantics.

Nothing in this skill relaxes the data-loss rules below. It knows nothing about this plugin's
backups.

## Research the graph, don't read the tree

The knowledge graph at `graphify-out/` indexes the source and doc files. One query costs a
fraction of what reading files costs.

> **The graph is stale.** It was built against the old `src/` + `Providers/` + `Repositories/`
> layout and still names classes that no longer exist (`Plugin`, `*ServiceProvider`,
> `SettingsPage`, `ListTable`, `RetentionCron`, `Database`, `Services\Bulk\Cli`,
> `SettingsRepository`, `StatsRepository`, `App\Foundation\*`, `App\Vite`). Run
> `/graphify --update` before trusting it.

```bash
graphify query "how does BackupManager expire backups"
graphify path "AttachmentConverter" "DatabaseRewriter"
graphify explain "Scanner"
```

Read a file directly only when the graph points at a specific line to edit, the spec's
"Read first" section names it, or you need an exact signature. Re-run `/graphify --update`
after any unit that adds or changes files — a stale graph will confidently name a class that no
longer exists.

## Context files — query, don't read whole

| Need | Where |
|---|---|
| Current unit, what's done, open questions | `context/progress-tracker.md` — **read directly**, it's small and always current |
| The unit you're implementing | `context/specs/NN-*.md` — **read directly**, always required |
| Class map, data model, engine selection | `graphify query`, not `architecture.md` in full |
| The 13 **Architecture invariants** | `context/architecture.md` — **read directly**, they're short and several encode data-loss failure modes |
| Security, DB access, filesystem, i18n rules | `context/code-standards.md` |
| Known bugs and the backup-deletion incident | `context/current-issues/` |
| Not-yet-scoped work (AVIF, resize, scheduling) | `context/future-specs/` — do not implement unspecced |

## Before writing any code

1. Read `progress-tracker.md` — confirm which unit is In Progress
2. Read that unit's spec
3. Read the Architecture invariants table in `architecture.md`
4. `graphify query` every class, table, hook, or pattern the spec names
5. Read only the files listed under the spec's "Read first"
6. Anything ambiguous → add to Open Questions in `progress-tracker.md` and ask

## Hard rules

- **Never `global $wpdb` in plugin code** — go through `app/Models/` (`OptimizationLog`,
  `UrlLookup`). The two deliberate exceptions are `Services/Rewrite/DatabaseRewriter` and
  `database/DataBackfills`, where serialization-safe multi-table SQL cannot go through a model
- **Never delete by glob over a shared directory.** Delete only paths you created, resolved from
  your own records. A glob cleanup already destroyed 54 real user backups.
- Never weaken a guard (`is_serialized()`, the `__PHP_Incomplete_Class` check, the path-traversal
  check in `BackupManager`) to make a test pass — the test is wrong
- Never hand-edit `build/**` — that is wp-scripts output; edit `resources/**` and `npm run build`
- **The runtime comes from WordPress; the UI does not.** Import React from `@wordpress/element`,
  HTTP from `@wordpress/api-fetch`, translations from `@wordpress/i18n` — wp-scripts externalizes
  all three to the scripts WordPress already loads, and writes them into `build/*.asset.php`.
  **Never import `@wordpress/components`**, and never add `react`/`react-dom` to `package.json`.
  Every control is ours (`resources/admin/Components/`); markup uses `sio-*` classes, never core's
  `notice notice-*`, `button button-*` or `.description`. `build/admin.asset.php` is the check:
  it must list `wp-element` and `wp-api-fetch`, and must not list `wp-components`
- Never touch `wp-content/uploads/swift-image-optimizer-backups/` — that is user data
- Never modify a file outside the current spec's "Files changed"
- Never add a Composer *runtime* dependency — the plugin ships dependency-free by design.
  npm packages are build-time only and still need to be named in the spec
- Never rename a class, hook, option, or table not in the current spec
- Every new PHP file: `if ( ! defined( 'ABSPATH' ) ) { exit; }`, correct namespace, path matching
  the namespace
- Every input handler: capability check **and** nonce **and** sanitize on input **and** escape on
  output. This is the plugin's real attack surface — REST routes, upload MIME handling, generated
  WebP paths, the `cwebp` shell-out, backup/restore file paths, `unserialize` in `Services/Rewrite/`.

## Verify before claiming anything works

The environment lies in two specific ways, and both have already produced confidently-reported
findings that were pure fiction:

- **Local names every database `local`** — only the socket differs. cb-test.local is socket
  `aRpCXvFUz`. The wrong socket silently pairs one site's files with another site's database.
- **CLI PHP and the site's web PHP have different extensions.** Never conclude an engine is
  unavailable from a CLI check — read engine availability off the admin diagnostics screen, which
  runs under web PHP.

Both cases are written up with the exact commands in `context/ai-workflow-rules.md`. When a
result says something surprising about the *environment* rather than the code, check the
environment first.

## A unit is not complete until

1. `npm run lint:php` clean (and `npm run lint:js` if JS changed)
2. `composer dump-autoload -o` clean, and every moved class still resolves — a namespace/path
   mismatch is a fatal at call time, not at load
3. `npm run build` succeeds, `build/admin.asset.php` still excludes `wp-components`, and the admin
   screen still mounts; `npm run test:e2e` if the UI changed
4. `/security-review` on the diff — plus `owasp-security-review` on the files if the unit touched
   REST, upload, shell-out, or backup/restore
5. `/graphify --update`
6. `progress-tracker.md` updated: spec moved to `specs/done/`, row added to Completed, next unit
   set In Progress, any architectural decision recorded in `architecture.md`

If tests fail, say so and show the output. Never describe partial work as complete, and
distinguish a test-fixture bug from a code bug explicitly — several early "failures" here were
bad fixtures, and calling those "fixed the bug" is misleading.
