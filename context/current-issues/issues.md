# Current Issues

Known gaps. Nothing here is a live bug in shipped behaviour — these are unverified paths,
environment limits and untested surfaces.

Last reviewed: 2026-08-16 — I-10, I-14 and I-15 verified against the shipped code, and **I-3,
I-6 and I-7 closed by testing**.

**Open:** I-4, I-8, I-9. Everything closed is in the table at the bottom; the
reasoning behind each closure lives in that unit's spec.

Unit 10 is a caution about this file. It closed fourteen defects that were **not** listed here,
three of which destroyed user data, and all of which were found by reading the conversion path
rather than by any test or any entry below. An empty issues list means nobody has looked
recently, not that nothing is wrong.

---

## I-4 — WP-CLI commands are partly verified at runtime

**Severity:** Low (was Medium)

**Mostly closed in Unit 10.** WP-CLI turned out to be available after all, via Local's bundled
phar driven by Local's PHP with the site's MySQL socket:

```bash
PHP="/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
WP="/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/posix/wp"
SOCK="$HOME/Library/Application Support/Local/run/aRpCXvFUz/mysql/mysqld.sock"
"$PHP" -d mysqli.default_socket="$SOCK" "$WP" swift-image-optimizer diagnostics
```

Confirm `option get siteurl` returns `https://cb-test.local` before trusting anything — that
command pairing is the guard against the two-database trap in [fix-plan.md](fix-plan.md).

Exercised live: `optimize --id`, `restore --id`, `stats`, `diagnostics`, `logs`,
`logs --reset`, `requeue`. **Not yet exercised:** `optimize --all`, `optimize --dry-run`,
`restore --all`, and the `--limit` / `--batch` flags — which is to say the bulk paths, still the
recommended route for large libraries.

---

## I-8 — A backup manifest can become unreachable, with no repair path

**Severity:** Low

**Half of this closed in Unit 10.** The direction that had actually bitten us — files vanishing
while the column still advertised them, leaving `canRestore: true` on a Restore that would fail
— is fixed. `BackupManager::manifest_is_intact()` checks every named file on disk, and both the
media modal and the list-table row action now ask it rather than trusting the column. That was
the concrete case behind the incident in [fix-plan.md](fix-plan.md).

**What remains:** `backup_path` still stores `{relative_dir, files[], expires}` as JSON in a TEXT
column. If that value is truncated or malformed, the files are on disk but unreachable, and
there is no routine that reconciles the two. Restore now fails cleanly with `backup-expired`
instead of misleading the user, so this is a recoverability gap rather than a correctness one.

**Fix when it matters:** a reconcile routine that walks the backup directory and rebuilds
manifests from what is actually there.

---

## I-9 — Time-based backup expiry is untested with real aged data

**Severity:** Low

`RetentionCron::purge()` has only been exercised via artificially expired rows. A backup that
genuinely aged past its retention window has never been observed being collected.

**Fix:** set `backup_expires` to a past timestamp on a real backup and run the cron hook.

---

## Closed

The reasoning behind each closure lives in the unit's spec; the historical record of the
pre-Unit-11 ones is in [progress-tracker.md](../progress-tracker.md), not here.

| Issue | Closed in | Detail |
|---|---|---|
| I-1 — PHPCS never run | Unit 09 | [specs/done/09-phpcs-compliance.md](../specs/done/09-phpcs-compliance.md) |
| I-2 — Suite tested cwebp while the site runs Imagick | Unit 11 | [progress-tracker.md](../progress-tracker.md) |
| I-11 — One storage folder | Unit 11 | [progress-tracker.md](../progress-tracker.md) |
| I-12 — Resumable cron-driven bulk | Unit 11 | [progress-tracker.md](../progress-tracker.md) |
| I-13 — Optimized state verified against disk | Unit 11 | [progress-tracker.md](../progress-tracker.md) |
| I-14 — Bulk optimization and stats centralized | Unit 12 | [specs/done/12-centralized-scan-stats.md](../specs/done/12-centralized-scan-stats.md) |
| I-10 — Foreign admin notices on this plugin's screen | Unit 14 | [specs/done/14-notices-and-card-spacing.md](../specs/done/14-notices-and-card-spacing.md) |
| I-15 — Card bottom had no spacing | Unit 14 | [specs/done/14-notices-and-card-spacing.md](../specs/done/14-notices-and-card-spacing.md) |
| I-3 — Dashboard never opened in a browser | 2026-08-16 | Manual browser pass — no unit; the last release gate |
| I-6 — Multisite unconsidered | 2026-08-16 | Tested — no unit. `readme.txt` still says nothing either way |
| I-7 — Dry-run extrapolation is a linear estimate | 2026-08-16 | Tested — no unit. The estimate stands as a "roughly how much will change" signal |

The two caveats Unit 14 could not settle in source — whether foreign notices are really gone
from the screen, and whether the cards *overlapped* or merely touched — went to I-3, and I-3's
browser pass has now closed it. No per-tab detail from that pass was recorded here; if it turned
up anything worth keeping, that belongs in a new entry rather than a reopened one.

Still unverified in a browser: `tests/e2e/library-scan.spec.js`, which has never run against a
real login — see **Next Up** in [progress-tracker.md](../progress-tracker.md).
