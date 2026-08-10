# AI Workflow Rules

## Skills — what is installed and when to reach for it

Project skills live in `app/public/.agents/skills/`, symlinked into `app/public/.claude/skills/`.
They are project-scoped: they load when working from `app/public`, not globally.

| Skill | Invoke for | Do **not** use it for |
|---|---|---|
| `graphify` | Any "where is X / how does X work / what calls X" question. **Always first.** | Editing. It reads, it does not write. |
| `owasp-security-review` | Auditing a whole file or subsystem — REST routes, upload handling, the `engine` shell-out, backup paths. | The pending diff — the built-in `/security-review` covers that and is cheaper. |
| `wp-plugin-directory-guidelines` | Anything touching `readme.txt`, the plugin header, the slug, licensing, or premium/upsell UI. Run the full 18-guideline pass before any .org submission. | Runtime security. It is a submission-compliance reference, not a vulnerability scanner. |
| `wordpress-pro` | The WordPress **API surface**: which sanitize/escape function fits which input, nonce and capability patterns, hook signatures and priorities, i18n, transients and object caching. | **Architecture.** It assumes a conventional plugin. See the constraint below. |
| `php-pro` | PHP language questions — typed properties, attributes, generics in docblocks. | **Its workflow**, and any syntax above PHP 7.4. See the constraint below. |
| `playwright-cli` | Driving a real browser against the admin UI. | Anything the PHP harnesses already assert. |

### `wordpress-pro` is scoped to the WordPress API, not to architecture

Use it to answer *which WordPress function to call and how to call it safely*. It is the best
reference installed for the four-part input rule this plugin already mandates — capability check,
nonce, sanitize on input, escape on output — and for picking `sanitize_text_field` vs
`wp_kses_post` vs `esc_url_raw`, or `esc_html` vs `esc_attr`. Load `references/hooks-filters.md`
and `references/performance-security.md` when that is the question.

Do not take architecture from it. It describes a conventional single-file plugin; this one is a
FluentCart-style kernel. Where they disagree, `agent.md` and this file win:

| It says | Here |
|---|---|
| `global $wpdb` + `$wpdb->prepare()` as the DB pattern | `app/Models/` (`OptimizationLog`, `UrlLookup`). `prepare()` still applies *inside* the two documented exceptions, `Services/Rewrite/DatabaseRewriter` and `database/DataBackfills` |
| Validate with `phpcs --standard=WordPress` | `npm run lint:php`. `phpcs.xml.dist` is WordPress-Extra + WordPress-Docs, `minimum_wp_version 6.0`, `testVersion 7.4-` |
| Baseline "WordPress 6.4+, PHP 8.1+" | **PHP 7.4+ / WP 6.2+.** No enums, readonly, constructor promotion, or `never`. `PHPCompatibilityWP` fails the build, so this is a lint error, not a preference |
| `register_rest_route()` with an inline `permission_callback` | `app/Http/Routes/api.php` fluent DSL. The permission callback *is* the Policy, and a route without one is refused |
| Settings API, `WP_List_Table`, `add_menu_page` scaffolding | Deliberately absent. `boot/`, `App::`, `Router`, `App::view()`. Several of these names still haunt the stale graph — do not resurrect them |
| `wp_enqueue_script()` with a directory URI and a literal version | `app/Hooks/Handlers/AssetHandler.php`; deps and version come from generated `build/*.asset.php` |
| "Don't bundle libraries when WordPress APIs suffice" | Correct for the runtime — that is why `@wordpress/element`, `@wordpress/api-fetch` and `@wordpress/i18n` are externalized and `react` is not a dependency. **Not** correct for UI: `@wordpress/components` is banned |
| "Run a security audit checklist" | Implementation-time hygiene only. It does not replace step 2 or 3 of the order of operations below |

Never load `references/theme-development.md` or `references/gutenberg-blocks.md` — no theme, no
blocks, no FSE here. WooCommerce and ACF are not installed. `references/plugin-architecture.md`
is useful only for plugin-header and uninstall semantics.

It knows nothing about this plugin's backups, and nothing in it relaxes the data-loss rules.

### `php-pro` is scoped to language questions only

That skill is written for Laravel/Symfony apps and prescribes PHPStan level 9, PHPUnit/Pest, and
80% coverage. **None of that applies here.** This is a WordPress plugin: no Composer autoloader in
play, no PHPStan, no PHPUnit — lint is `npm run lint:php` (phpcs against `phpcs.xml.dist`) and tests
are the standalone harnesses below. Take its PHP-language guidance; ignore its tooling and framework
sections entirely. If it suggests installing a package, that collides with the scoping rules below —
don't.

It also assumes PHP 8.3+. **The floor here is 7.4** — `swift-image-optimizer.php`, `composer.json`
and `phpcs.xml.dist` (`testVersion 7.4-`) all say so, and `PHPCompatibilityWP` enforces it. Enums,
readonly properties, constructor promotion, `never`, and first-class callable syntax are lint
errors, not style choices. The same cap applies to `wordpress-pro`.

### The security order of operations

Security is not a final step; run it at the point it is cheapest to fix.

1. **While writing** — for any handler that accepts input, confirm the four WordPress basics
   before moving on: capability check, nonce verification, sanitize on input, escape on output.
   These are what an image optimizer gets wrong most often.
2. **Before marking a unit complete** — `/security-review` on the diff.
3. **When a unit touched a whole subsystem** — invoke `owasp-security-review` on those files.
   Mandatory for anything in this plugin's actual attack surface:
   - REST routes under `swift-image-optimizer/v1` — permission callbacks, not just nonces
   - Upload and attachment handling — MIME validation, path traversal on generated
     WebP/AVIF paths, symlinks under `wp-content/uploads/`
   - Any shell-out (`cwebp` and friends) — argument escaping, never string-interpolated paths
   - `Backup/` and restore paths — these read and write user-owned files by path
   - Serialized-data handling in `Rewrite/` — object injection via `unserialize`
4. **Before any .org submission or version bump** — `wp-plugin-directory-guidelines`.

Report security findings the way the skill does: HIGH confidence only, with the data-flow trace
that proves the input is attacker-controlled. A pattern match is not a finding.

## Graph-first research (saves ~20x tokens — always do this first)

A knowledge graph of the full plugin lives at `graphify-out/` (391 nodes, 498 edges, 56
communities, built from all 59 source/doc files).

**Before reading any file, query the graph:**

```bash
graphify query "how does BackupManager expire backups"
graphify path "AttachmentConverter" "DatabaseRewriter"
graphify explain "Scanner"
```

One query returns relevant nodes, edges, and source locations for a fraction of the tokens of
reading files directly. Only read a file directly when the graph result points at a specific
line to edit, the spec's "Read first" section lists it explicitly, or an exact method signature
is needed.

**Use it to scope security work too** — before auditing anything, ask the graph what actually
reaches it, so the audit covers real callers instead of guessed ones:

```bash
graphify query "which classes handle REST requests"
graphify query "where does the plugin write files to disk"
graphify path "Rest" "AttachmentConverter"      # is there an unauthenticated route to conversion?
```

Then hand that file list to `owasp-security-review` rather than pointing it at the whole plugin.

Re-run `/graphify --update` after any unit that adds or changes files, so the graph doesn't go
stale. A stale graph is worse than none — it will confidently name a class that no longer exists.

## One unit at a time

Read `progress-tracker.md` → read the spec for the current unit → implement exactly what the
spec says → update `progress-tracker.md`. Nothing more.

## Before starting any unit

1. Read `progress-tracker.md` — confirm which unit is In Progress
2. Read `context/specs/NN-unit-name.md` for that unit
3. Read the **Architecture invariants** table in `architecture.md`. There are 13 and they are
   short. Several encode failure modes that destroy user data, so do not skip this.
4. Read only the files listed under "Read first" in the spec
5. If anything is ambiguous — add it to Open Questions in `progress-tracker.md` and ask

## Scoping rules

- Only modify files listed in the spec's "Files changed" section
- Do not refactor code outside the spec's scope, even if it looks improvable
- Do not install Composer or npm packages not listed in the spec
- Do not rename any class, hook, option or table not in the current spec
- If you notice a bug in an adjacent file, add it to Open Questions — do not fix it now

## Protected files — never modify without explicit spec instruction

- `build/**` — compiled output, regenerate with `npm run build`, never hand-edit
- `node_modules/**`
- Anything under `wp-content/uploads/swift-image-optimizer/backup/` — that is user data, and
  it is the only copy of their original images

## The destructive-path rule

Any change touching `AttachmentConverter`, `Rewrite/`, or `Backup/` is operating on code that
can permanently destroy a user's media library and content. For those files specifically:

1. **Re-run the rewriter test suite before and after.** 21 assertions, no WordPress needed.
2. **Never weaken a guard to make a test pass.** If `is_serialized()` or the
   `__PHP_Incomplete_Class` check is in the way, the test is wrong.
3. **Test the round trip, not just the forward path.** Convert → verify → restore → verify the
   restore is byte-identical to the source.
4. Work against a **copy** of a database, never a live one.

## Testing

There is no PHPUnit setup. Tests are standalone PHP harnesses run against the real Local
install, and they are **committed under `tests/php/`**. Three exist, 110 assertions:

| Harness | Needs WP? | Asserts | Guards |
|---|---|---|---|
| `rewriter-test.php` | No — stubs `is_serialized` etc. | 33 | Invariants 1, 2, 3, 10 — serialization safety |
| `convert-restore-e2e.php` | Yes | 38 | Round trip byte-identical, backup manifest, orientation |
| `bulk-e2e.php` | Yes | 39 | Pending definition, cursor paging, lock, cancel, dry run |

```bash
npm run test:php                  # all three, socket pinned by the runner
tests/php/run.sh rewriter-test    # just one
SIO_TEST_ENGINE=gd npm run test:php
```

> **They were previously kept in the session scratchpad rather than committed, and were lost** —
> taking 143 assertions of regression cover with them, on a plugin that deletes user media. That
> is why they now live in the repository. An earlier set covered the upload interceptor and the
> media-library UI; those two have not been rewritten yet, which is the gap between 110 and the
> old 143.

Every WordPress-backed harness calls `harness_require_site()` first and **exits rather than run**
if `siteurl` is not `https://cb-test.local`. Shared fixtures and the narrow cleanup live in
`tests/php/wp.php`; assertions and the site guard in `tests/php/bootstrap.php`.

### Confirm the database before trusting any result

Local gives each site its own MySQL socket and **names every database `local`**. Passing the
wrong socket silently pairs one site's files with another site's database, and everything
appears to work.

| Socket | Site |
|---|---|
| `1fWQjOkKt` | tuflamenco.dev — **not this project** |
| `aRpCXvFUz` | **cb-test.local** |

```bash
SOCK="$HOME/Library/Application Support/Local/run/aRpCXvFUz/mysql/mysqld.sock"
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" -d mysqli.default_socket="$SOCK" -r 'define("WP_USE_THEMES",false); require "wp-load.php"; echo get_option("siteurl"), "\n";'
"$PHP" -d mysqli.default_socket="$SOCK" upload-e2e.php
```

Getting this wrong once produced a confidently-reported "496 orphaned attachments" finding that
was pure fiction, plus an entire spec built on top of it.

### Browser (e2e) testing with Playwright

`@playwright/test` is a devDependency (`npm install`, then `npx playwright install chromium`
once per machine). Config: `playwright.config.js`. Tests live in `tests/e2e/*.spec.js`.

```bash
npm run test:e2e                            # tests/e2e against WP_BASE_URL or http://cb-test.local
npm run test:e2e -- tests/e2e/bulk.spec.js  # one file
npm run test:e2e -- --headed --debug        # watch it, or step through a failure
WP_BASE_URL=http://other.local npm run test:e2e
```

**Two ways in, and they are not interchangeable:**

- **Committed specs** (`npm run test:e2e`) — the default. Anything that should keep being checked
  goes here. A UI change is not done until a spec covers it and that spec passes.
- **The `playwright-cli` skill** — for one-off exploration: reproducing a bug a user reported,
  reading console errors off an admin page, grabbing a screenshot, or finding the right selector
  before writing a spec. Exploration is not evidence. Once it tells you something worth keeping,
  write it into a spec under `tests/e2e/` and run it the committed way.

Both hit a **real WordPress install with real user media**. Everything in the harness rules below
applies unchanged — especially: clean up exactly what you created, assert on deltas, and check
`Scanner::count_pending()` before anything that triggers a bulk run.

Use Playwright for anything the four PHP harnesses can't cover — actual admin-UI interaction
(Media Library row/bulk actions, the React Bulk/Settings/Backups/Troubleshoot tabs, the
grid-view modal). It complements, not replaces, the PHP harnesses: PHP harnesses assert on
conversion/rewrite correctness; Playwright asserts on what a user actually sees and clicks.
Same rules apply — clean up any attachments/state created during a run, assert on deltas, and
don't claim a UI change works without having actually run it through Playwright or a manual
browser check.

**Rules for test harnesses:**

- **Never delete by glob over a shared directory.** Delete only the specific paths the test
  created, resolved from the test's own records — for backups, read `backup_path` off that
  attachment's own log row. A `glob( BackupManager::root() . '/*/*/*' )` cleanup destroyed 54
  real user backups (228 original files). This is the most expensive mistake made on this
  project; see the incident note in `current-issues/fix-plan.md`.
- **Snapshot before, diff after.** Capture counts (log rows, attachments, posts) and the
  settings array up front; compare at the end. Any drift is a harness bug until proven
  otherwise.
- **Check `Scanner::count_pending()` before running a destructive harness.** If it is non-zero,
  the bulk run will convert real user images, not just the test's own.
- Always clean up: delete created attachments, posts, options, and log rows at the end
- Never capture-and-restore settings (`Settings::all()` then write it back) — if a previous
  crashed run left a dirty value you will faithfully restore the dirty value. Set an explicit
  baseline from `Settings::defaults()` instead. This has already bitten once.
- Assert on **deltas** against a captured baseline, not absolute counts — the dev library has
  pre-existing rows
- If a harness crashes mid-run it leaves state behind; clean up before trusting the next run

## Before writing any conversion code

Check what the environment actually provides rather than assuming:

```bash
php -r 'echo "GD: ", extension_loaded("gd")?"y":"n";
  if(extension_loaded("gd")){$i=gd_info(); echo " webp:", !empty($i["WebP Support"])?"y":"n";}
  echo " imagick:", extension_loaded("imagick")?"y":"n", "\n";'
which cwebp
```

**CLI PHP and web PHP differ on this machine, and it matters:**

| Context | GD+WebP | cwebp | Imagick | Engine selected |
|---|---|---|---|---|
| Local's CLI PHP (7.4 → 8.4) | yes | yes | **no** | `cwebp` |
| The site's web / php-fpm request | yes | yes | **yes** | `imagick` |

So every PHP harness exercises the **cwebp** path while the site actually runs **Imagick**. Do
not conclude "Imagick is unavailable" from a CLI check — that mistake was made and reported as
fact. Confirm from the real request context instead:

```bash
# from the browser console on any admin page
fetch('/wp-json/swift-image-optimizer/v1/scan', {
  headers: { 'X-WP-Nonce': swiftImageOptimizerMedia.nonce }, credentials: 'same-origin'
}).then( r => r.json() ).then( j => console.log( j.engine, j.engines ) );
```

To test an engine the CLI cannot reach, force it via the `engine` setting rather than relying
on auto-detection.

## Reporting rules

- If tests fail, say so and show the output. Never describe partial work as complete.
- Distinguish **test-fixture bugs** from **code bugs** explicitly. Several "failures" during
  the initial build were bad fixtures (a flat PNG that genuinely compresses better as WebP,
  `is_admin()` being false under CLI). Saying "fixed the bug" when the code was right is
  misleading.
- When a count looks wrong, find the cause before adjusting the assertion. The 496 "failures"
  in the first bulk run were a real classification bug hiding behind plausible-looking numbers.
- **Verify the environment before drawing conclusions from it.** Two findings on this project
  were reported confidently and were both wrong: "496 orphaned attachments" (wrong database) and
  "Imagick has never executed" (wrong PHP binary). Both looked entirely plausible. When a result
  says something surprising about the *environment* rather than the code, check the environment
  first.
- **Correct the record when it turns out wrong.** Delete the invalid finding from `issues.md`
  and anything built on it, rather than leaving it to mislead later.

## After completing any unit

Gate it first — a unit is not complete until all four pass:

1. `npm run lint:php` clean (and `npm run lint:js` if JS changed)
2. The relevant PHP harnesses pass, plus `npm run test:e2e` if the unit touched admin UI
3. `/security-review` on the diff — and `owasp-security-review` on the files if the unit touched
   REST, upload, shell-out, or backup/restore code
4. `/graphify --update`, so the next unit starts from an accurate graph

Then update `progress-tracker.md`:

- Move the spec file from `context/specs/` to `context/specs/done/`
- Add the row to the Completed table with a one-line summary and a link
- Set the next unit as In Progress
- Record any architectural decision that future work must respect in `architecture.md`

If implementation changed the architecture, naming, or standards, update the relevant context
file **before** continuing.
