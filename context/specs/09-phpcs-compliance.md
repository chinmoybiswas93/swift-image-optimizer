# Unit 09 — PHPCS Compliance

## Goal

Actually run the WordPress Coding Standards against this plugin and fix what they find.

**This blocks WordPress.org submission.** Everything is *written* to standard, but no linter
has ever confirmed it — WPCS is not installed in this environment. Until this unit runs,
"WordPress.org compliant" is an intention, not a fact.

## Read first

- `phpcs.xml.dist` — the ruleset, written in Unit 01 but never executed
- `code-standards.md` — the conventions the code claims to follow

## Setup

```bash
cd wp-content/plugins/swift-image-optimizer
composer require --dev \
  wp-coding-standards/wpcs \
  phpcompatibility/phpcompatibility-wp \
  dealerdirect/phpcodesniffer-composer-installer
vendor/bin/phpcs --standard=phpcs.xml.dist -s
```

Composer is a **dev-only** dependency. It must not become a runtime one — the plugin ships with
a hand-rolled autoloader and no `vendor/` directory. Add `vendor/` to `.gitignore` (already
done) and confirm it is excluded from any release archive.

## Expected findings

Predicted from writing the code, in rough order of likely volume:

| Sniff | Where | Expected resolution |
|---|---|---|
| `WordPress.DB.DirectDatabaseQuery` | `Database`, `Stats`, `Scanner`, `DatabaseRewriter`, `Fallback404`, `Cli` | Already annotated — verify each annotation names a real justification |
| `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` | Interpolated table/column identifiers | Already annotated; confirm no **value** is ever interpolated |
| `WordPress.PHP.NoSilencedErrors` | `@rename`, `@unserialize`, `@getimagesize`, `@exec`, `@file_put_contents` | Each must have the failure handled on the following line |
| `WordPress.WP.AlternativeFunctions` | `file_put_contents`, `rmdir` in `BackupManager` | Justify or move to `WP_Filesystem` |
| `WordPress.PHP.DiscouragedPHPFunctions.serialize` | `DatabaseRewriter` | Unavoidable — restoring a value to its storage format |
| `WordPress.Security.NonceVerification` | `ListTable::bulk_notice()` | Already annotated; verify the nonce really is checked before any write |
| `WordPress.NamingConventions.PrefixAllGlobals` | `swift_image_optimizer_autoload`, `swift_image_optimizer()` | Prefixes are already correct; confirm the ruleset agrees |
| `WordPress.WP.I18n` | Every string | Text domain and `translators:` comments |
| `Squiz.Commenting` | Docblocks | Should be clean |

## Rules for fixing

1. **Fix the code, not the sniff.** A `phpcs:ignore` is acceptable only where the sniff is
   genuinely wrong for this context, and the comment must say *why* — never just silence it.
2. **Never weaken a security guard to satisfy a sniff.** If the two conflict, the guard wins
   and the ignore gets a comment.
3. **Do not change behaviour.** This is a lint pass. Any behavioural change belongs in its own
   unit.
4. **Re-run all four test harnesses afterwards.** 107 assertions must still pass. Auto-fixers
   (`phpcbf`) in particular can silently change semantics around array syntax and spacing.

## Also verify in this unit

- `readme.txt` parses against the .org readme validator
- No external HTTP request exists anywhere: `grep -rn "wp_remote_\|curl_\|file_get_contents( *'http" src/`
- No `error_log` / `var_dump` / `print_r` left in `src/`
- `uninstall.php` removes options and the table but **never** touches media or backups
- The plugin header's `Requires PHP` and `Requires at least` match reality
- Every file parses under PHP 7.4

## Files changed

Potentially any file under `src/`, plus `composer.json` (new, dev-only) and `readme.txt`.

## Done when

`vendor/bin/phpcs --standard=phpcs.xml.dist -s` exits clean, and all 107 assertions still pass.
