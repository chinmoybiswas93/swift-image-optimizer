# Code standards

Written to survive WordPress.org review without a hardening pass later. Where this file and
`agent.md` disagree, `agent.md` wins.

## Every PHP file

```php
<?php
/**
 * One-line description.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Whatever;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
```

Tabs, Yoda conditions against literals, full docblocks, no closing `?>`. **Long array syntax
`array()`, never `[]`** — the IDE will suggest otherwise; ignore it.

## Security

| Rule | Applies to |
|---|---|
| Escape at output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` | Every echo/print |
| Sanitize at input: `absint()`, `sanitize_key()`, `sanitize_text_field()`, `sanitize_file_name()` | Every `$_GET` / `$_POST` / REST arg |
| Nonce **and** capability on every write path | Admin actions, REST routes |
| `$wpdb->prepare()` for every value | All direct queries |
| `escapeshellarg()` on every shell argument | `CwebpEngine` only |
| Validate paths against their root before touching them | `BackupManager::safe_path()` |

Capabilities, and they are not interchangeable:

- `manage_options` — settings, bulk operations, backup purge and repair
- `upload_files` + `edit_posts` — single-image optimize/restore
- `edit_post` (per object) — Media Library row and bulk actions

## Database access

Go through `app/Models/`. The two deliberate exceptions are `Services/Rewrite/DatabaseRewriter`
and `database/DataBackfills`, where serialization-safe multi-table SQL cannot. Inside a model or
an exception, every direct call carries a phpcs justification naming *why*:

```php
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; result cached below.
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE attachment_id = %d", $id ), ARRAY_A );
```

- Identifiers are built internally or come from `$wpdb->posts` — **never** from user input.
- Every **value** goes through a placeholder.
- Long scans page by primary key with `LIMIT`, never `OFFSET`.
- Anything expensive and repeated gets a transient — see `api/Resource/StatsResource.php`.

## Error handling

Return `WP_Error` with a **machine-readable code**, never `false` or `null`, and never throw out
of a public method. Codes drive control flow, so they matter:

```php
return new WP_Error( 'skipped-larger', __( 'Human readable.', 'swift-image-optimizer' ) );
```

Whether a code is a skip or a failure is decided in exactly one place —
`AttachmentConverter::soft_errors()`, built from `PERMANENT_SKIPS` plus `RETRYABLE_SKIPS`. **Never
re-list those codes anywhere else**; they were duplicated once and drifted, which is how 496 images
got reported as failed when they had been correctly skipped. The split matters: a permanent skip
is a property of the image, a retryable one a property of the server at that moment, and only the
latter may be returned to the queue by `Scanner::requeue()`.

## Filesystem

- `wp_mkdir_p()` to create, `wp_is_writable()` before writing, `wp_unique_filename()` for new names
- **`wp_delete_file()` to delete — never bare `unlink()`**
- Silence errors only where the failure is handled on the very next line, with a justification

## i18n

Text domain `swift-image-optimizer` on every string, PHP and JS. `/* translators: */` above every
`sprintf` with placeholders, numbered `%1$s` when there is more than one, `_n()` for anything
countable, `wp_set_script_translations()` for the React bundle.

## Naming

| Kind | Convention | Example |
|---|---|---|
| Class | StudlyCase, namespaced | `SwiftImageOptimizer\App\Services\Bulk\Runner` |
| Method / variable | snake_case | `process_batch()`, `$attachment_id` |
| Constant | UPPER_SNAKE | `PERMANENT_SKIPS` |
| Hook, DB table, option | `swift_image_optimizer_` prefix | `swift_image_optimizer_settings` |
| CSS class | `sio-` prefix, BEM-ish | `sio-progress__fill` |

## Comments

Explain **why**, never what. The codebase's own examples:

```php
// The status stays 'optimized' so the image keeps counting toward the
// savings stats. An empty backup_path is what marks it unrestorable.
```

```php
// strtr applies the longest matching key first and never re-scans
// text it has already substituted, which str_replace does not
// guarantee when one filename is a prefix of another.
```

Applies to PHP, JS/JSX and SCSS alike:

- No decorative banner comments (`/* ---- Section ---- */`) — a named function already does that.
- No placeholder docblocks (`Constructor.`, `Getter.`). The summary must say something the name
  does not, or describe the why.
- No commented-out code, and no `TODO`/`FIXME`/`HACK` markers — record the open item in
  [issues.md](issues.md) instead.
