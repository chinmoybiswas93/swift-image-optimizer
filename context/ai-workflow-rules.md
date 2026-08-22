# Working rules

`agent.md` is the front door — layout, skills, hard rules. This file holds the three things that
have actually gone wrong here: destroyed user data, a lying environment, and harnesses that ate
the library they were testing.

## What makes this plugin dangerous

It rewrites and deletes user media, and the backup is often the only copy of the original. A bug
here does not produce a broken page. It produces a media library the user cannot get back.

## Data-loss rules

**Never delete by glob over a shared directory.** Delete only the specific paths you created,
resolved from your own records — for backups, read `backup_path` off that attachment's own log
row.

> On 2026-08-08 a harness cleanup ran
> `foreach ( glob( BackupManager::root() . '/*/*/*' ) as $f ) { @unlink( $f ); }`.
> That matches **every** backup on the site, not just the test's. It ran twice before anyone
> noticed, and took **54 real backups — 228 original image files**. The optimized WebPs were fine,
> so nothing looked broken; the damage only showed up as Restores that failed. This is the most
> expensive mistake made on this project, and `BackupManager::purge_orphans()` is fenced the way
> it is entirely because of it.

**Never weaken a guard to make a test pass.** If `is_serialized()`, the `__PHP_Incomplete_Class`
check, or `BackupManager::safe_path()` is in the way, the test is wrong.

**Never touch `wp-content/uploads/swift-image-optimizer/backup/`.** User data, and the only copy
of their originals.

**Never hand-edit `build/**`** — regenerate with `npm run build`.

### The destructive path

`AttachmentConverter`, `Services/Rewrite/`, `Services/Backup/`.

**Re-run `tests/php/run.sh rewriter-test` before and after any change here.** It needs no
WordPress, takes seconds, and guards the serialization invariants — the ones that turn a bad
`str_replace` into a database nobody can unserialize. This is the one check that is not optional.

Beyond that: test the round trip, not just the forward path (convert → verify → restore → verify
byte-identical), and work against a copy of a database, never a live one.

## The environment lies, in two specific ways

Both have already produced confidently-reported findings that were pure fiction. **When a result
says something surprising about the *environment* rather than the code, check the environment
first.**

### Every Local database is named `local`

Only the socket differs. The wrong socket silently pairs one site's files with another site's
database, and everything appears to work.

| Socket | Site |
|---|---|
| `aRpCXvFUz` | **cb-test.local** — this project |
| `1fWQjOkKt` | tuflamenco.dev — not this project |

```bash
SOCK="$HOME/Library/Application Support/Local/run/aRpCXvFUz/mysql/mysqld.sock"
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" -d mysqli.default_socket="$SOCK" -r 'define("WP_USE_THEMES",false); require "wp-load.php"; echo get_option("siteurl"), "\n";'
```

Every harness run before 2026-08-08 used the wrong socket. That produced the "496 orphaned
attachments" finding — one site's attachment rows checked against another's uploads folder — and
an entire spec was written on top of it before anyone confirmed `siteurl`.

### CLI PHP and the site's web PHP have different extensions

| Context | GD+WebP | cwebp | Imagick | Engine selected |
|---|---|---|---|---|
| Local's CLI PHP (7.4 → 8.4) | yes | yes | **no** | `cwebp` |
| The site's web / php-fpm request | yes | yes | **yes** | `imagick` |

So every CLI harness run exercises **cwebp** while real users get **Imagick**. Never conclude "the
extension is unavailable" from a CLI check — that was done, and reported as fact. Read engine
availability from a real request instead:

```bash
# browser console, on any admin page
fetch('/wp-json/swift-image-optimizer/v1/scan', {
  headers: { 'X-WP-Nonce': swiftImageOptimizerMedia.nonce }, credentials: 'same-origin'
}).then( r => r.json() ).then( j => console.log( j.engine, j.engines ) );
```

To exercise an engine the CLI cannot reach, use `tests/php/run.sh --web` (runs through php-fpm) or
force it with the `engine` setting rather than relying on auto-detection.

## The harnesses

No PHPUnit. Standalone PHP scripts run against the real Local install, **committed** under
`tests/php/`.

| Harness | Needs WP? | Default suite? | Guards |
|---|---|---|---|
| `rewriter-test.php` | No — stubs `is_serialized` etc. | Yes | Serialization safety |
| `notice-strip-test.php` | No — real `WP_Hook` | Yes | Foreign notices stripped on this screen only |
| `convert-restore-e2e.php` | Yes | Yes | Round trip byte-identical, backup manifests, retention cron, orientation |
| `bulk-e2e.php` | Yes | Yes | Pending definition, cursor paging, lock, cancel, dry run |
| `cli-bulk-e2e.php` | Yes — **and a real `wp` binary** | **No, deliberately** | CLI bulk flags: `--all`, `--dry-run`, `--limit`, `--batch`, `restore --all` |

```bash
npm run test:php                  # default suite, socket pinned by the runner
tests/php/run.sh --web            # same, through php-fpm — the only way to exercise Imagick
tests/php/run.sh rewriter-test    # just one
SIO_TEST_ENGINE=gd npm run test:php

npm run test:cli                  # the CLI suite, separately
tests/php/run-cli.sh --sites      # every Local site: name, PHP, domain, socket
tests/php/run-cli.sh --smoke      # read-only commands only, no fixtures written
```

`cli-bulk-e2e.php` stays out of the default suite because it runs `optimize --all` and
`restore --all`, which act on the whole media library rather than its own fixtures — not something
to trip over while running the others. It refuses to start if the library holds any pending or
restorable image it did not create (`SIO_CLI_ALLOW_DIRTY=1` overrides).

`run-cli.sh` is the one runner **not** pinned to cb-test: socket, the site's own PHP version and
the expected domain all come from Local's `sites.json`. Use it as the model when a harness needs
to run elsewhere.

Every WordPress-backed harness calls `harness_require_site()` and **exits rather than run** if
`siteurl` is not `https://cb-test.local`. Fixtures and cleanup live in `tests/php/wp.php`;
assertions and the site guard in `tests/php/bootstrap.php`.

> Two of the original harnesses were kept in a session scratchpad instead of the repo, and were
> lost — on a plugin that deletes user media. That is why they are committed now. The upload and
> media-UI ones have never been rewritten.

### Writing a harness

- **Snapshot before, diff after.** Capture counts (log rows, attachments, posts) up front and
  compare at the end. Any drift is a harness bug until proven otherwise.
- **Check `Scanner::count_pending()` before anything destructive.** Non-zero means the bulk run
  will convert real user images, not just yours.
- **Assert on deltas**, not absolute counts — the dev library has pre-existing rows.
- **Never capture-and-restore settings** (`StoreSettings::all()` then write it back). A previous
  crashed run leaves a dirty value and you will faithfully restore it. Set an explicit baseline
  from the defaults instead. This has already bitten.
- Clean up everything you created. If a harness crashes mid-run it leaves state behind — clean up
  before trusting the next run.
- **Don't hardcode assertion counts in the docs.** Every number ever written down here went stale
  within a unit or two, and no two copies agreed.

## Browser testing

`@playwright/test` is a devDependency (`npm install`, then `npx playwright install chromium` once
per machine). Config `playwright.config.js`, specs in `tests/e2e/*.spec.js`.

```bash
npm run test:e2e                            # against WP_BASE_URL or http://cb-test.local
npm run test:e2e -- --headed --debug        # watch it, or step through a failure
```

Two ways in, not interchangeable. **Committed specs** are the ones that keep being checked. The
**`playwright-cli` skill** is for one-off exploration — reproducing a reported bug, reading console
errors, finding a selector. Exploration is not evidence: once it tells you something worth
keeping, write it into a spec.

Both hit a real install with real user media, so every harness rule above applies unchanged.

## Verification is on request, not a ritual

Ordinary changes do not need a full gauntlet. Run what the change actually earns, and say plainly
what you ran and what you didn't.

| Tool | Reach for it when |
|---|---|
| `tests/php/run.sh rewriter-test` | **Required** — any change under `Rewrite/`, `Backup/`, or `AttachmentConverter` |
| `npm run test:php` (`--web` too) | The change touches conversion, backups, bulk, or an engine |
| `npm run lint:php` | PHP changed and you want it clean before it ships. Needs WPCS on `PATH` — installed outside the plugin |
| `/security-review` | Asked for, or the change touches REST, upload, shell-out, or backup/restore paths |
| `owasp-security-review` | Asked for — a whole file or subsystem, not a diff |
| `graphify update .` | Asked for, or you moved or renamed enough that the graph would mislead the next session |
| `npm run test:e2e` | The admin UI changed and you want it proven, not assumed |

None of these are gates on a doc edit, a comment fix, or a one-line correction.

## Reporting

If tests fail, say so and show the output. Never describe partial work as complete. Distinguish a
test-fixture bug from a code bug explicitly — several early "failures" here were bad fixtures, and
calling those "fixed the bug" is misleading.

When you find something that turns out to be wrong or unverifiable, delete the claim rather than
softening it. Two findings in this project's history were reported confidently and were pure
fiction; both are written up in [memory/units.md](memory/units.md) so they are not repeated.
