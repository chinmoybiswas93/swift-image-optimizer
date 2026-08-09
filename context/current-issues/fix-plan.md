# Fix Plan

Ordering for the issues in [issues.md](issues.md), on the way to a WordPress.org submission.

## Incident — backups destroyed 2026-08-08

**54 backups (228 original image files) on cb-test were deleted by a test harness.** The
cleanup block in `media-ui-e2e.php` used:

```php
foreach ( glob( BackupManager::root() . '/*/*/*' ) as $f ) { @unlink( $f ); }
```

That glob matches **every** backup on the site, not just the ones the test created. It ran
twice before being caught. The harness now reads each test attachment's own `backup_path` from
its log row and deletes only those files.

Consequences that outlived the fix:

- The optimized WebP images are fine; nothing is visually broken
- 54 log rows still advertise `canRestore: true` against files that no longer exist, so Restore
  will fail for them until the uploads directory is restored from a site backup
- This is the concrete case behind **I-8**

**Rules this produced, now in `ai-workflow-rules.md`:**

- A test harness must never delete by glob over a shared directory. Delete only the specific
  paths the test itself created, resolved from the test's own records.
- Snapshot before, diff after. Any drift is a harness bug until proven otherwise.

## Environment trap — two databases

Local gives each site its own MySQL socket, and **both databases are named `local`**. Passing
the wrong socket silently pairs one site's files with another site's database:

| Socket | Site |
|---|---|
| `1fWQjOkKt` | tuflamenco.dev |
| `aRpCXvFUz` | **cb-test.local** ← this project |

Every harness run before 2026-08-08 used the wrong one. That produced the bogus "496 orphaned
attachments" finding — TuFlamenco's attachment rows checked against cb-test's uploads folder.
The plugin's table, options and cron event have been removed from TuFlamenco; no content there
was modified.

**Always confirm `get_option('siteurl')` before trusting any result.**

## Release gate

Must be closed before v1.0.0 is submitted.

| Order | Issue | Why it is a gate | Effort |
|---|---|---|---|
| 1 | **I-1** PHPCS never run | .org review will reject on style and escaping findings; also the cheapest way to catch real security slips | Half a day |
| 2 | **I-2** Suite tests cwebp, site runs Imagick | The engine users actually get is not covered by a single assertion | Half a day |
| 3 | **I-3** Dashboard never opened in a browser | The whole Unit 07 admin surface is unproven | An hour |
| 4 | **I-4** WP-CLI untested | The docs recommend it for large libraries; one bug already found there by static analysis alone | An hour |
| 5 | **I-6** Multisite unconsidered | Cheap to resolve by declaring it unsupported | An hour |

**Do I-1 first** — it may surface problems in the other areas, and running it after manual UI
testing means retesting.

**I-2 is now higher priority than it looks.** The earlier plan proposed demoting Imagick below
GD because it seemed untested. That was based on a wrong reading of the environment: Imagick
*is* the production engine and works. Do not demote it — test it instead.

## First point release

| Order | Issue | Action |
|---|---|---|
| 6 | **I-5** Upload-optimized images cannot be restored | Decide: back up uploads too, or document the asymmetry in `readme.txt` |
| 7 | **I-8** Backup pointer can outlive its files | Make `canRestore` verify the files exist; add a reconcile routine |

I-8 is worth doing sooner than its "Low" severity suggests, precisely because the incident above
proves it is reachable.

## Watch, do not fix yet

| Issue | Trigger to act |
|---|---|
| **I-7** Dry-run extrapolation | Only if users report the estimate being badly misleading |
| **I-9** Aged backup expiry | Verify once, cheaply, then close. Do not build anything |

## Rules for this pass

- **Re-run all five harnesses after every change** — 143 assertions. `phpcbf` in particular can
  silently alter semantics around array syntax and spacing.
- **Never weaken a guard to satisfy a sniff.** If `is_serialized()`, the
  `__PHP_Incomplete_Class` check, or the memory estimate is in the way, the sniff loses and the
  ignore gets a comment explaining why.
- **No behavioural changes during I-1.** It is a lint pass.
- Update `progress-tracker.md` as each issue closes, and delete the entry from `issues.md`
  rather than marking it done — this file is a working list, not a changelog.
