# Architecture

## Class map

Restructured 2026-08-10 from the layered `src/` + `Providers/` + `Repositories/` arrangement to a
FluentCart-style layout: Composer PSR-4 with an `app/` root, a small plugin-owned kernel at its own
`framework/` root, and a React front end built with `@wordpress/scripts`. Composer carries **no runtime dependencies** — the
`vendor/` directory holds only the generated autoloader, committed because WordPress.org runs no
build step on the destination server.

`wpfluent/framework`, which produces this layout in FluentCart itself, is not installable: the
whole `wpfluent` GitHub organisation is private and the package is absent from Packagist. The
~1,200 lines under `framework/` reproduce the parts the plugin actually uses — container, config,
view, router — and nothing else. It sits outside `app/` for the same reason FluentCart's does:
`app/` is application code, `framework/` is the machinery underneath it.

React, `apiFetch` and `i18n` all come from WordPress's own registered scripts. `@wordpress/scripts`
externalizes them and records the handles in `build/*.asset.php`, so the plugin ships no React of
its own. **None of WordPress's UI components are used** — every control is the plugin's own.

```
swift-image-optimizer.php   IIFE bootstrap: constants, then boot/app.php + vendor/autoload.php
uninstall.php               Drops tables + options via DBMigrator. Never deletes user media.

boot/
├── app.php                 Returns the kernel closure: builds Application, registers activation/
│                           deactivation handlers, runs the migrator on init.
├── bindings.php            Container singletons: rewriter, optimizer, converter, runner (+ class aliases)
└── globals.php             swift_image_optimizer_app/_config/_view/_log helpers (composer files autoload)

config/                     Plain arrays, read via App::config()->get('app.slug')
├── app.php                 slug, text domain, hook prefix, rest_namespace/version, env
├── optimizer.php           Settings defaults and the bounds the sanitizer enforces
└── vite.php                Dev-server host/port, only consulted when app.env is 'dev'

framework/                  namespace SwiftImageOptimizer\Framework — the kernel
├── Application.php         Loads config, binds view/router, requires the hook manifests,
│                           registers routes on rest_api_init
├── Container.php           bind/singleton/instance/alias + reflection auto-wiring
├── Config.php              Dot-notation access over config/*.php
├── View.php                app/Views renderer, dot or slash notation, path-traversal guarded
├── Router.php              prefix()/withPolicy()/group() DSL over register_rest_route
└── Route.php               One declared route: method, uri, action, policy, args

app/
├── App.php                 Static facade: make/singleton/alias/view/config/router/path/url
├── Hooks/
│   ├── actions.php         THE registration manifest - every hook the plugin owns
│   ├── Handlers/           ActivationHandler, DeactivationHandler, MenuHandler, NoticeHandler,
│   │                       MediaLibraryHandler, AssetHandler
│   ├── Scheduler/JobRunner.php   Daily purge of expired backups
│   ├── Scheduler/BulkJobRunner.php  Advances an active bulk run from cron
│   ├── Scheduler/ScanJobRunner.php  Advances a running scan + the scan_frequency schedule
│   └── CLI/Commands.php    wp swift-image-optimizer optimize|restore|stats|diagnostics|logs|
│                           requeue|rescan|repair-backups
├── Http/
│   ├── Controllers/        Controller (base), Optimize, Bulk, Backup, Diagnostics, Log, Stats
│   ├── Policies/           Policy (base), AdminPolicy (manage_options), MediaPolicy (upload_files+edit_posts)
│   ├── Requests/           OptimizeRequest, LogQueryRequest — WP args schemas + sanitizers
│   └── Routes/             routes.php (aggregator) + api.php (the route table)
├── Models/
│   ├── Model.php           Thin prepared-statement $wpdb base. Not an ORM.
│   ├── OptimizationLog.php The log table: statuses, upsert/find/update/delete, stats cache flush
│   └── UrlLookup.php       The indexed old-URL table: remember/forget/lookup
├── Modules/                Reserved for self-contained feature packages
├── Services/               Domain layer: Optimizer, AttachmentConverter, Lock, Engine/, Backup/,
│                           Bulk/, Rewrite/, Logging/, Diagnostics/, Upload/
│                           Bulk/ also holds Scanner (live counts), ScanRunner (the batched,
│                           disk-verified library scan) and Coordinator (chains scan -> optimize
│                           -> scan behind one Bulk Optimize button)
└── Views/                  Plain-PHP templates
    └── admin/              admin_app.php (SPA mount) + parts/{notice,media-column}.php

api/                        namespace SwiftImageOptimizer\Api — stable data-access layer
├── StoreSettings.php       Options: defaults, sanitize, register_setting. Singleton aliased `settings`
└── Resource/               BaseResourceApi + StatsResource (the dashboard aggregate)

database/                   namespace SwiftImageOptimizer\Database, classmap-autoloaded
├── DBMigrator.php          Schema version gate, migrator list, migrateUp/maybeMigrateDBChanges/dropTables
├── DataBackfills.php       Post-upgrade data migrations (url_map -> lookup table)
└── Migrations/             LogMigrator, UrlMigrator — one dbDelta per table

resources/                  React + SCSS source. Excluded from the shipped zip.
├── admin/{bootstrap,Components,Pages,Partials,Icons,Services}
├── media/media.js          Media Library integration (wp.media Backbone, deliberately no React)
└── styles/                 _controls.scss (the component set), admin.scss, media.scss

build/                      wp-scripts output + *.asset.php — committed, never hand-edited
vendor/                     Composer's autoloader only. No packages.
```

There is deliberately **no `src/`, no `Providers/`, no `Repositories/`, no `Foundation/` inside
`app/`, and no `Http/Admin/`**. The eight service providers collapsed into `app/Hooks/actions.php`;
`SettingsRepository` became `api/StoreSettings` and `StatsRepository` became
`api/Resource/StatsResource`.

## Architecture invariants

These are the rules that make the plugin correct. Breaking one causes data loss or corruption,
so treat them as non-negotiable.

| # | Invariant | Why |
|---|---|---|
| 1 | **Never `str_replace` over serialized data.** Always unserialize → walk → re-serialize. | PHP serialization embeds byte lengths. A naive replace produces a value that can never be unserialized again. This is the classic way search-and-replace destroys a WordPress site. Proven in `rewriter-test.php` test 4. |
| 2 | **Unserialize with `allowed_classes => false`.** If the result contains `__PHP_Incomplete_Class`, leave the row untouched. | Blocks object injection, and refuses to write back a payload it cannot faithfully reconstruct. |
| 3 | **Use `strtr()`, not `str_replace()`, for the map.** | `strtr` applies the longest key first and never re-scans replaced text. `photo.jpg` is a prefix of nothing, but `photo-300x200.jpg` contains `photo`-like fragments — order matters. |
| 4 | **Back up before the first destructive operation, roll back on any failure.** | Steps 2–9 of the convert pipeline are wrapped; a failure at any point restores from backup. |
| 5 | **The `Optimizer` never touches the database or attachments.** Files only. | It is shared by the upload path and the bulk path, which have completely different DB semantics. |
| 6 | **Never delete old files until after metadata regeneration succeeds.** | A failure mid-way must leave a working site. |
| 7 | **Estimate memory before decoding any image.** `width × height × 4 × 2` against `memory_limit`, **only when the chosen engine decodes in-process**. | The most common shared-host fatal. Turns a white screen into a logged skip. Applying it to cwebp, which runs as a separate process, refused images it could handle comfortably. |
| 8 | **Soft errors are defined once**, in `AttachmentConverter::soft_errors()` — `PERMANENT_SKIPS` plus `RETRYABLE_SKIPS`. | This list was duplicated in three places and drifted, causing 496 skipped images to be reported as failures. The permanent/retryable split is what lets `Scanner::requeue()` return environment-caused skips to the queue without retrying images that can never improve. |
| 9 | **The log table's `status` stays `optimized` when a backup expires.** Availability is tracked by an empty `backup_path`. | Changing the status would zero that image's contribution to the savings stats. |
| 10 | **Attachment references stored as IDs need no rewriting.** Only hardcoded URL strings are touched. | IDs resolve through metadata, which is updated separately. Rewriting them would be wrong. |
| 11 | **Never rewrite derived caches** (`_elementor_css`, `_bricks_css*`, `_transient_*`, `_wp_attachment_metadata`). Flush them instead. | They are regenerated from source data and their formats are not ours to edit. |
| 12 | **`build/` and `vendor/` are committed.** | WordPress.org ships the plugin as-is; there is no npm or Composer step on the user's server. Edit `resources/`, never `build/`. |
| 13 | **No external HTTP requests, ever.** | Privacy claim in the readme, and a .org review requirement. |
| 14 | **Custom media-toolbar buttons must manage their own visibility.** Core's `SelectModeToggle` skips `.media-button` in both directions (`media-grid.js:337`, `:350`). | It never hides *or* re-shows them, so a button that does not toggle its own `hidden` class will be stuck in whichever state it started in. |
| 15 | **Refetch the attachment model after converting it.** | Conversion changes the filename. Without `model.fetch()` every thumbnail in the grid 404s and the modal shows stale data. |
| 16 | **Engine selection is a chain, not a choice.** `EngineFactory::chain()` plus `EngineInterface::supports_file()`. | An engine can read a format in general and still be wrong for one file: cwebp cannot rotate, GD cannot decode CMYK. Returning a single engine turned either into a failed or corrupted image. An explicit preference moves an engine to the front, it does not replace the chain. |
| 17 | **The logger writes to a file, never to the database.** | It is called from inside the upload handler, cron and WP-CLI, and its whole job is to record what happened while the rewriter was modifying the database. Errors are always written; only the verbose trail is gated on the setting. |
| 18 | **Cross-request locks use `add_option()`, not transients.** | `get_transient()` then `set_transient()` is check-then-set; two simultaneous requests both see "unlocked". The options table's unique key on `option_name` makes the database arbitrate. See `Services\Lock`. |
| 19 | **The rewriter invalidates the objects it touched, never the whole cache.** Descriptors carry an `object` column and a cache `group`. | `wp_cache_flush()` once per batch discards a persistent object cache every few seconds during a bulk run — worst on the large sites that need bulk most. |
| 20 | **Old URLs resolve from an indexed table** (`swift_image_optimizer_urls`), matched on full path before basename. | The 404 fallback was a `LIKE` over a LONGTEXT column: unindexable, and triggered by every bot probing an old filename. Basename-only matching also confused two images sharing a name in different month folders. |
| 21 | **Uploaded originals are backed up before the source is deleted**, gated on `backup_uploads`, failing closed. | Without it an upload is a one-way door. The manifest is written in the same shape the converter path uses, so Restore needs no separate code path. |
| 22 | **A column describing a file is not evidence the file exists.** Ask the disk: `BackupManager::manifest_is_intact()` before offering Restore, `AttachmentConverter::optimized_output_exists()` before reporting or refusing an optimization. | The database and the uploads directory can be restored from different points in time, and a plugin backup restore does exactly that. Trusting the column showed a whole library as processed with no way to optimize any of it, and offered Restores that could not succeed. Three of Unit 11's four reports were this same mistake. |
| 23 | **A batch persists its pending rewrite map before applying it.** `Runner` parks `pending_rewrite`, rewrites, then clears it; the next batch flushes anything left behind. | Conversion writes a terminal log row per image but repoints references once per batch. A death in between leaves those images marked done forever with references pointing at filenames that no longer exist — `Scanner` never revisits a terminal row. Tolerable when a batch was one foreground request; not once cron runs batches unattended. |
| 24 | **The server owns whether a bulk run is active.** `start()` is idempotent, `cancel()` keeps the cursor, and the client reconciles from `state()` rather than computing its own. | Two clients doing their own arithmetic over different snapshots is why progress figures disagreed, and an unguarded `start()` let a second tab reset a run mid-flight — which surfaced as "already running" from a button that looked available. |
| 25 | **The library scan observes; it never writes to the log table.** A row claiming `optimized` whose file is missing is bucketed `pending` in the published snapshot and left exactly as it was. Deleting the row is `Scanner::rescan()`'s job, not the scan's. | The scan runs unattended on a schedule (invariant 13 already rules out a loopback trigger, so this has to be true for the same reason). A routine that mutates the log table while nobody is watching is a routine that can silently destroy the one record that makes Restore possible. |
| 26 | **A file with no row pointing at it is not evidence there is nothing to restore.** The converse of invariant 22. `BackupManager::reconcile()` rebuilds manifests from files still on disk, driven from the log rows rather than a directory walk; it writes pointers and deletes nothing. Repair runs before any sweep — in the route list, in the Backups tab, and in the docs. | Backup files outlive their pointer routinely: a purge clears it, an encode fails, or a conversion dies between copying the originals and writing the row (the copy is first precisely so a death there is survivable). Until this existed, the only routine that touched an unreferenced backup was `purge_orphans()`, which deletes it — so the recovery path and the demolition path were the same button. Deleting is not a repair. |

## The upload path (Feature 1)

```
wp_handle_upload( $upload )
  ├─ auto_optimize off?              → return unchanged
  ├─ Optimizer::can_optimize()       → mime, format, engine, memory checks
  ├─ Optimizer::optimize()           → temp .webp beside the original
  │    └─ result >= original bytes?  → discard, log 'skipped-larger', keep original
  ├─ rename( temp, photo.webp )
  ├─ wp_delete_file( photo.jpg )
  ├─ return $upload with file/url/type pointing at the WebP
  └─ park result in $pending[ path ]

add_attachment( $id )
  └─ match get_attached_file($id) against $pending → OptimizationLog::upsert()
```

WordPress then generates every subsize as WebP with no further involvement from us.

## The conversion path (Feature 2)

```
AttachmentConverter::convert( $id, $defer_rewrite )
  1. per-attachment transient lock
  2. capture $before = wp_get_attachment_metadata()
  3. BackupManager::backup()          main + original_image + every subsize
  4. Optimizer::optimize()            → temp .webp
  5. rename into place
  6. collect $old_files from $before   (before metadata is regenerated and the list is lost)
  7. update_attached_file()
  8. wp_generate_attachment_metadata() → $after, all subsizes now WebP
  9. wp_update_post()                  post_mime_type + guid
 10. UrlMap::build( $before, $after )  → 18 URL pairs for a typical image
 11. DatabaseRewriter::replace()       unless deferred for batching
 12. delete $old_files not present in $after
 13. OptimizationLog::upsert()                log row incl. url_map and backup pointer
```

`$defer_rewrite = true` returns the map instead of applying it, so `Services\Bulk\Runner` can merge many
maps and rewrite once per batch. This turns N table scans into one and is what makes bulk
viable at library scale.

## The rewriter

`UrlMap::build()` produces, for each of the main file, the `-scaled` variant, the pre-scaling
original and every registered subsize:

- absolute URL — `https://site.com/wp-content/uploads/2026/08/photo.jpg`
- protocol-relative — `//site.com/wp-content/uploads/...`
- root-relative — `/wp-content/uploads/...`

`UrlMap::with_escaped_slashes()` then adds the `https:\/\/site.com\/...` form, which is how
Elementor and Bricks store URLs inside JSON meta. Because the escaped form is handled by the
plain map, **no JSON decoding is needed** — this is why there is no separate `JsonRewriter`
class despite the original plan calling for one.

`DatabaseRewriter::replace()` scans `posts` (`post_content`, `post_excerpt`,
`post_content_filtered`), `postmeta`, `options`, `termmeta` and `usermeta`. It queries with an
`OR LIKE` built from bare filenames (which every URL form contains), chunked at 40 terms, and
pages by primary key in batches of 500 so memory stays flat.

## Data model

`{$wpdb->prefix}swift_image_optimizer_log` — one row per attachment the plugin has touched.
This single table backs the stats dashboard, restore, bulk dedupe **and** the 404 fallback map.

| Column | Notes |
|---|---|
| `attachment_id` | PRIMARY KEY |
| `status` | `optimized` / `skipped` / `failed` / `restored` |
| `original_file`, `original_size`, `original_mime` | Pre-conversion state |
| `optimized_file`, `optimized_size` | Post-conversion state |
| `backup_path` | JSON `{relative_dir, files[], expires}`. Empty means unrestorable. |
| `backup_expires` | Unix timestamp, 0 = keep forever |
| `url_map` | JSON old→new. Powers both restore and the 404 fallback. |
| `engine`, `reason`, `created_at` | Diagnostics |
| `conversion_ms` | Encode time in milliseconds. Added in schema v2; 0 on pre-v2 rows. |

Indexes on `status` and `backup_expires`.

`{$wpdb->prefix}swift_image_optimizer_urls` — the 404 fallback's lookup index, added in
schema v3. One row per old file, not per URL variant: the absolute, protocol-relative and
escaped forms in a `url_map` all reduce to the same path, and an incoming request only ever
carries a path.

| Column | Notes |
|---|---|
| `id` | PRIMARY KEY, auto-increment |
| `attachment_id` | Indexed. Rows are deleted on restore. |
| `old_path` | Indexed. Uploads-relative, e.g. `2026/08/photo-300x200.jpg`. Matched first. |
| `old_basename` | Indexed. Matched only when the request path does not resolve. |
| `new_url` | Where to redirect |

**Schema version 3** — bumping `DBMigrator::SCHEMA_VERSION` triggers `dbDelta` on the next `init`.
Upgrading from v2 also backfills this table from existing `url_map` values, without which the
fallback would silently stop working the moment it switched tables.

Both paths write a `backup_path`. The upload path backs up its single source file via
`BackupManager::backup_file()` before deleting it, gated on the `backup_uploads` setting
(default on) — so upload-optimized images are restorable through exactly the same code as
converter-path ones. `url_map` stays empty for uploads, because an upload has no existing
references to repoint.

## Engine selection

Preference order is **Imagick → cwebp → GD**, overridable in settings. `EngineFactory::chain()`
returns *every* usable engine in that order, and an explicit setting moves one to the front
rather than replacing the list. `Optimizer` walks the chain: `EngineFactory::for_file()` filters
it by `supports_file()`, and a decode or encode failure falls through to the next engine instead
of failing the image. That is what makes cwebp's inability to rotate and GD's inability to read
CMYK survivable rather than destructive.

Imagick leads because it is the only engine that preserves the ICC colour profile; dropping ICC
from a Display-P3 photo makes it visibly desaturate. GD strips ICC unconditionally, so it
compensates by baking EXIF orientation into the pixels before encoding (otherwise portrait
photos would come out sideways).

`CwebpEngine` never ships a binary. It checks `function_exists('exec')`, that `exec` is not in
`disable_functions`, and that a binary exists at a known path or via the
`swift_image_optimizer_cwebp_binary` filter — then escapes every argument. It also **declines any
JPEG whose EXIF orientation is not 1**: cwebp has no rotate option and `-metadata icc` discards
the orientation flag, so it would write portrait photos permanently sideways. It reports
`decodes_in_process() === false`, which exempts it from the memory estimate.

## Extension points

| Filter / action | Purpose |
|---|---|
| `swift_image_optimizer_should_optimize_upload` | Veto conversion for a given upload |
| `swift_image_optimizer_has_memory` | Override the memory-safety estimate |
| `swift_image_optimizer_cwebp_binary` | Point at a cwebp in a non-standard location |
| `swift_image_optimizer_urls_rewritten` | Fires after a rewrite — page caches hook this to purge |
