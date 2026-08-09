# Code Standards

Target: **WordPress.org public repository**. Everything below is written to survive .org review
without a hardening pass later.

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

- Tabs for indentation, not spaces
- **Long array syntax** `array()`, never `[]` — WordPress standard, and the IDE will suggest
  otherwise. Ignore it.
- Yoda conditions for comparisons against literals
- Full docblocks on every class, method and property, with `@param` / `@return`
- No `?>` closing tag

## PHP version floor

**PHP 7.4.** Verified by linting every file against 7.4, 8.2 and 8.4. That means:

- No union types, no constructor promotion, no `match`, no enums, no readonly
- No named arguments
- `??` and `?->` are fine at 7.4? — `??` yes, `?->` **no** (8.0+)

## Security

| Rule | Applies to |
|---|---|
| Escape at output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` | Every echo/print |
| Sanitize at input: `absint()`, `sanitize_key()`, `sanitize_text_field()`, `sanitize_file_name()` | Every `$_GET` / `$_POST` / REST arg |
| Nonce + capability on every write path | Admin actions, REST routes |
| `$wpdb->prepare()` for every value | All direct queries |
| `escapeshellarg()` on every shell argument | `CwebpEngine` only |
| Validate paths against their root before touching them | `BackupManager::safe_path()` |

Capabilities used:

- `manage_options` — settings, bulk operations, backup purge
- `upload_files` + `edit_posts` — single-image optimize/restore
- `edit_post` (per object) — Media Library row and bulk actions

## Database access

There is no ORM here. Direct `$wpdb` is expected, but every such call carries a phpcs
justification comment naming why:

```php
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; result cached below.
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE attachment_id = %d", $id ), ARRAY_A );
```

Rules:

- Table and column identifiers are built internally or come from `$wpdb->posts` etc. — never
  from user input
- Every **value** goes through a placeholder
- Long scans page by primary key with `LIMIT`, never `OFFSET`
- Anything expensive and repeated gets a transient (see `Stats::get()`)

## Error handling

Return `WP_Error` with a **machine-readable code**, never `false` or `null`, and never throw
out of a public method. Codes are used for control flow, so they matter:

```php
return new WP_Error( 'skipped-larger', __( 'Human readable.', 'swift-image-optimizer' ) );
```

Whether a code counts as a skip or a failure is decided in exactly one place —
`AttachmentConverter::soft_errors()`, built from `PERMANENT_SKIPS` plus `RETRYABLE_SKIPS`. Never
re-list those codes anywhere else. The split matters: a permanent skip is a property of the
image, a retryable one a property of the server at that moment, and only the latter may be
returned to the queue by `Scanner::requeue()`.

## Filesystem

- `wp_mkdir_p()` to create directories
- `wp_delete_file()` to delete — never bare `unlink()`
- `wp_is_writable()` before writing
- `wp_unique_filename()` for any new filename
- Silence errors only where the failure is explicitly handled on the next line, with a phpcs
  justification

## i18n

- Text domain `swift-image-optimizer` on every string, PHP and JS
- `/* translators: */` comments above every `sprintf` with placeholders
- Numbered placeholders `%1$s` when there is more than one
- `_n()` for anything countable
- `wp_set_script_translations()` for the React bundle

## JavaScript

- `@wordpress/*` packages only — no external runtime libraries
- `@wordpress/element` (not bare `react`)
- `@wordpress/components` for UI so it matches core admin
- All CSS classes prefixed `sio-`
- All strings through `__()` / `sprintf()` from `@wordpress/i18n`

## Naming

| Kind | Convention | Example |
|---|---|---|
| Class | StudlyCase, namespaced | `SwiftImageOptimizer\Services\Bulk\Runner` |
| Method / variable | snake_case | `process_batch()`, `$attachment_id` |
| Constant | UPPER_SNAKE | `PERMANENT_SKIPS`, `PROGRESS_OPTION` |
| Hook | `swift_image_optimizer_` prefix | `swift_image_optimizer_urls_rewritten` |
| DB / option | `swift_image_optimizer_` prefix | `swift_image_optimizer_settings` |
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

Do not add comments that restate the code.

## Verification before calling anything done

```bash
# Parse under the version floor and the current stable
for v in 7.4 8.2 8.4; do find src -name '*.php' -exec php -l {} \; ; done

# Coding standards (requires WPCS installed)
composer install && phpcs --standard=phpcs.xml.dist -s

# Build must succeed and land in build/
npm run build
```

There are four test harnesses in the scratchpad pattern described in
`ai-workflow-rules.md`. **107 assertions currently pass.** Anything touching `Rewrite/` must
re-run the rewriter suite before being considered complete.
