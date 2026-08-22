# Unit 09 — PHPCS Compliance

## Goal

Actually run the WordPress Coding Standards against this plugin and fix what they find.

**This blocks WordPress.org submission.** Everything is *written* to standard, but no linter
has ever confirmed it — WPCS is not installed in this environment. Until this unit runs,
"WordPress.org compliant" is an intention, not a fact.

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
4. **Re-run all five test harnesses afterwards.** 143 assertions must still pass. Auto-fixers
   (`phpcbf`) in particular can silently change semantics around array syntax and spacing.

## Also verify in this unit

- `readme.txt` parses against the .org readme validator
- No external HTTP request exists anywhere (invariant 13): `grep -rnE "wp_remote_|curl_|file_get_contents\( *'http" app/ api/ framework/ database/ boot/`
- No `error_log` / `var_dump` / `print_r` left in `app/`, `api/`, `framework/` or `database/`
- `uninstall.php` removes options and the table but **never** touches media or backups
- The plugin header's `Requires PHP` and `Requires at least` match reality
- Every file parses under PHP 7.4

## Completion Notes

**784 violations → 0**, the first time `phpcs` had ever been run on this plugin.

Three of the fixes were real bugs rather than style:

- A dead `safe_mode` check — removed; the ini setting has not existed since PHP 5.4.
- `imagedestroy()` called unconditionally — version-gated; it is a no-op and deprecated at 8.0+.
- **The SQL annotations had never been in effect.** The `phpcs:ignore` comments on the direct
  queries named sniffs that did not match, so every one of those queries had been unreviewed the
  whole time. They were rewritten to name the sniffs that actually fire, which is why the exact
  comment format is now pinned in `code-standards.md`.

WPCS is installed **outside** the plugin, at `~/.wpcs`, and needs its `vendor/bin` on `PATH`.
It is deliberately not a `require-dev` here: the committed `vendor/` must stay autoloader-only
(invariant 12), because .org ships the plugin with no build step on the destination server.
