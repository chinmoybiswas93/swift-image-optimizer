# Architecture

## Class map

Restructured 2026-08-09 from feature folders (`Admin/`, `Rest/`, `Backup/`, `Bulk/`, `Rewrite/`,
`Upload/`, `Engine/`) to layered folders (`Http/`, `Services/`, `Repositories/`, `Providers/`,
`Hooks/`), mirroring the sibling `swiftlisting` plugin's architectural style. No dependency was
added — this plugin still ships without Composer, using its own hand-rolled PSR-4-lite
autoloader (`swift-image-optimizer.php`) plus a small plugin-owned `Support\Container`/`App`
facade and `Providers\ServiceProvider`/`PluginBootstrapper` pair that imitate the *shape* of
`swiftlisting`'s DI container without depending on its framework package. See "Architectural
decisions made during the build" in `progress-tracker.md` for the full rationale.

```
swift-image-optimizer.php          Bootstrap: constants, PSR-4 autoloader (src/ + database/ roots), activation hooks
uninstall.php                      Drops table + options. Never deletes user media.

src/
├── Plugin.php                     Thin bootstrap. Builds a PluginBootstrapper with every provider and boots it.
│
├── Support/
│   ├── Container.php              Minimal make()/singleton()/bind()/instance()/bound()
│   ├── App.php                    Static facade over Container — App::make(), App::singleton(), etc.
│   └── Lock.php                   Atomic cross-request lock built on add_option(), with stale-lock breaking
│
├── Providers/
│   ├── ServiceProvider.php        Abstract register()/boot() base every provider extends
│   ├── PluginBootstrapper.php     create()->providers([...])->boot() — register() on all, then boot() on all
│   ├── AppServiceProvider.php     Binds Optimizer/AttachmentConverter/Runner/DatabaseRewriter singletons; schema install + settings-registration + engine-reset hooks
│   ├── UploadServiceProvider.php  Wires Services\Upload\Interceptor (Feature 1)
│   ├── BackupServiceProvider.php  Wires Hooks\Scheduler\RetentionCron
│   ├── RewriteServiceProvider.php Wires Services\Rewrite\Fallback404
│   ├── RestServiceProvider.php    Wires Http\Controllers\Controller
│   ├── AdminServiceProvider.php   Wires Notices/ListTable/SettingsPage/Assets, is_admin()-gated
│   ├── LoggingServiceProvider.php Resets the logger's cached flag on settings save; marks on/off transitions
│   └── CliServiceProvider.php     Wires Services\Bulk\Cli, WP_CLI-gated
│
├── Http/
│   ├── Controllers/Controller.php All REST routes
│   └── Admin/
│       ├── Assets.php             Media Library bundle, enqueued wherever media-views loads
│       ├── ListTable.php          Column, row actions, bulk actions, modal payload
│       ├── Notices.php            "no engine available" warning
│       └── SettingsPage.php       Media submenu + React mount + wp_localize_script
│
├── Repositories/
│   ├── SettingsRepository.php     register_setting + defaults + sanitize (was Admin\Settings)
│   └── StatsRepository.php        Aggregate savings from the log table (was Stats)
│
├── Services/
│   ├── Optimizer.php              File in → WebP file out. Knows nothing about attachments.
│   ├── AttachmentConverter.php    Feature 2 orchestrator. Backup → convert → rewrite → log.
│   │
│   ├── Engine/
│   │   ├── EngineInterface.php    is_available(), name(), supports(), supports_file(), decodes_in_process(), convert()
│   │   ├── AbstractEngine.php     Shared option parsing, dimension math, EXIF orientation read
│   │   ├── ImagickEngine.php      Preferred — the only engine that preserves ICC
│   │   ├── CwebpEngine.php        Opt-in exec() path, guarded
│   │   ├── GdEngine.php           Universal fallback
│   │   └── EngineFactory.php      Detection + chain() + for_file() + settings override
│   │
│   ├── Upload/Interceptor.php     Feature 1. wp_handle_upload.
│   │
│   ├── Rewrite/
│   │   ├── UrlMap.php             Builds old→new pairs for every size and URL form
│   │   ├── DatabaseRewriter.php   Serialization-safe replace across 7 tables, targeted cache invalidation
│   │   └── Fallback404.php        Serves the WebP when an old URL is requested (indexed lookup)
│   │
│   ├── Backup/BackupManager.php   Store / restore / delete / disk usage / manifest verification,
│   │                               disk-space precheck, path-traversal safe
│   │
│   ├── Logging/Logger.php         File-backed debug.log-style trail. Static, DB-free, 10MB cap + 1 rollover
│   │
│   ├── Diagnostics/EnvironmentReport.php  Engines / PHP / filesystem / WordPress / plugin, each row
│   │                               carrying a state and a plain-language remedy
│   │
│   └── Bulk/
│       ├── Scanner.php            LEFT JOIN queries for outstanding work
│       ├── Runner.php             Adaptive batching, locking, resumable progress
│       └── Cli.php                wp swift-image-optimizer optimize|restore|stats|diagnostics|logs|requeue
│
└── Hooks/Scheduler/RetentionCron.php  Daily purge of expired backups (cron scheduling only;
                                        the class this schedules is Services\Backup\BackupManager)

database/Database.php              Sibling PSR-4 root, namespace SwiftImageOptimizer\Database.
                                    Log table + URL lookup table schema, CRUD, status constants.
                                    Mirrors the app/ vs database/ split used by swiftlisting.

admin/index.js                     React dashboard (Bulk / Settings / Backups / Troubleshoot tabs)
admin/index.scss                   Dashboard styles, all sio- prefixed
admin/media.js                     Grid toolbar buttons + modal panel (Backbone, no React)
admin/media.scss                   Card and progress styles
build/                             wp-scripts output — committed, never hand-edited
                                   two entries: admin.* (with React) and media.* (wp-i18n only)
```

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
| 12 | **`build/` is committed.** | WordPress.org ships the directory as-is; there is no build step on the user's server. |
| 13 | **No external HTTP requests, ever.** | Privacy claim in the readme, and a .org review requirement. |
| 14 | **Custom media-toolbar buttons must manage their own visibility.** Core's `SelectModeToggle` skips `.media-button` in both directions (`media-grid.js:337`, `:350`). | It never hides *or* re-shows them, so a button that does not toggle its own `hidden` class will be stuck in whichever state it started in. |
| 15 | **Refetch the attachment model after converting it.** | Conversion changes the filename. Without `model.fetch()` every thumbnail in the grid 404s and the modal shows stale data. |
| 16 | **Engine selection is a chain, not a choice.** `EngineFactory::chain()` plus `EngineInterface::supports_file()`. | An engine can read a format in general and still be wrong for one file: cwebp cannot rotate, GD cannot decode CMYK. Returning a single engine turned either into a failed or corrupted image. An explicit preference moves an engine to the front, it does not replace the chain. |
| 17 | **The logger writes to a file, never to the database.** | It is called from inside the upload handler, cron and WP-CLI, and its whole job is to record what happened while the rewriter was modifying the database. Errors are always written; only the verbose trail is gated on the setting. |
| 18 | **Cross-request locks use `add_option()`, not transients.** | `get_transient()` then `set_transient()` is check-then-set; two simultaneous requests both see "unlocked". The options table's unique key on `option_name` makes the database arbitrate. See `Support\Lock`. |
| 19 | **The rewriter invalidates the objects it touched, never the whole cache.** Descriptors carry an `object` column and a cache `group`. | `wp_cache_flush()` once per batch discards a persistent object cache every few seconds during a bulk run — worst on the large sites that need bulk most. |
| 20 | **Old URLs resolve from an indexed table** (`swift_image_optimizer_urls`), matched on full path before basename. | The 404 fallback was a `LIKE` over a LONGTEXT column: unindexable, and triggered by every bot probing an old filename. Basename-only matching also confused two images sharing a name in different month folders. |
| 21 | **Uploaded originals are backed up before the source is deleted**, gated on `backup_uploads`, failing closed. | Without it an upload is a one-way door. The manifest is written in the same shape the converter path uses, so Restore needs no separate code path. |

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
  └─ match get_attached_file($id) against $pending → Database::upsert()
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
 13. Database::upsert()                log row incl. url_map and backup pointer
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

**Schema version 3** — bumping `Database::SCHEMA_VERSION` triggers `dbDelta` on the next `init`.
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
