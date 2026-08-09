# Agent Instructions — Swift Image Optimizer

You are working inside the **swift-image-optimizer** WordPress plugin.
PHP 7.4+ and React (`@wordpress/scripts`). Not Node, not Next.js, no TypeScript, no Composer.

Everything lives under `app/public/wp-content/plugins/swift-image-optimizer/`. Namespace is
`SwiftImageOptimizer\`, autoloaded by the hand-rolled PSR-4-lite loader in
`swift-image-optimizer.php` — `src/` for everything, plus `database/` for the `Database`
namespace only. Text domain, slug, and option prefix are all `swift-image-optimizer`.

## What makes this plugin different from any other

**It rewrites and deletes user media, and the backup is often the only copy of the original.**
A bug here does not produce a broken page — it produces a media library the user cannot get
back. That single fact drives every rule below. `context/ai-workflow-rules.md` has the full
version; read it before your first edit in a session.

## Skills — reach for these before doing it by hand

| Skill | When |
|---|---|
| `graphify` | **First, always.** Any "where is X / how does X work / what calls X" question. |
| `playwright-cli` | Driving a real browser — repro a bug, read console errors, find a selector. |
| `owasp-security-review` | Auditing a file or subsystem for vulnerabilities. |
| `/security-review` (built-in) | The pending diff, before a unit is marked complete. |
| `wp-plugin-directory-guidelines` | `readme.txt`, plugin header, slug, GPL, upsell/trialware. |
| `php-pro` | PHP 8.x **language** questions only — see the constraint below. |

`php-pro` is written for Laravel/Symfony and prescribes PHPStan level 9, PHPUnit/Pest, and 80%
coverage. **None of that exists here.** Take its language guidance; ignore its tooling and
framework sections entirely. If it tells you to install a package, that is out of scope — don't.

Project skills live in `app/public/.agents/skills/` and load when working from `app/public`.

## Research the graph, don't read the tree

The knowledge graph at `graphify-out/` covers all 59 source and doc files (391 nodes, 498 edges).
One query costs a fraction of what reading files costs.

```bash
graphify query "how does BackupManager expire backups"
graphify path "AttachmentConverter" "DatabaseRewriter"
graphify explain "Scanner"
```

Read a file directly only when the graph points at a specific line to edit, the spec's
"Read first" section names it, or you need an exact signature. Re-run `/graphify --update`
after any unit that adds or changes files — a stale graph will confidently name a class that no
longer exists.

## Context files — query, don't read whole

| Need | Where |
|---|---|
| Current unit, what's done, open questions | `context/progress-tracker.md` — **read directly**, it's small and always current |
| The unit you're implementing | `context/specs/NN-*.md` — **read directly**, always required |
| Class map, data model, engine selection | `graphify query`, not `architecture.md` in full |
| The 13 **Architecture invariants** | `context/architecture.md` — **read directly**, they're short and several encode data-loss failure modes |
| Security, DB access, filesystem, i18n rules | `context/code-standards.md` |
| Known bugs and the backup-deletion incident | `context/current-issues/` |
| Not-yet-scoped work (AVIF, resize, scheduling) | `context/future-specs/` — do not implement unspecced |

## Before writing any code

1. Read `progress-tracker.md` — confirm which unit is In Progress
2. Read that unit's spec
3. Read the Architecture invariants table in `architecture.md`
4. `graphify query` every class, table, hook, or pattern the spec names
5. Read only the files listed under the spec's "Read first"
6. Anything ambiguous → add to Open Questions in `progress-tracker.md` and ask

## Hard rules

- **Never `global $wpdb` in plugin code** — go through `Database` / the repositories
- **Never delete by glob over a shared directory.** Delete only paths you created, resolved from
  your own records. A glob cleanup already destroyed 54 real user backups.
- Never weaken a guard (`is_serialized()`, the `__PHP_Incomplete_Class` check, the path-traversal
  check in `BackupManager`) to make a test pass — the test is wrong
- Never hand-edit `build/**` — regenerate with `npm run build`
- Never touch `wp-content/uploads/swift-image-optimizer-backups/` — that is user data
- Never modify a file outside the current spec's "Files changed"
- Never install a Composer or npm package not named in the spec
- Never rename a class, hook, option, or table not in the current spec
- Every new PHP file: `if ( ! defined( 'ABSPATH' ) ) { exit; }`, correct namespace, path matching
  the namespace
- Every input handler: capability check **and** nonce **and** sanitize on input **and** escape on
  output. This is the plugin's real attack surface — REST routes, upload MIME handling, generated
  WebP paths, the `cwebp` shell-out, backup/restore file paths, `unserialize` in `Services/Rewrite/`.

## Verify before claiming anything works

The environment lies in two specific ways, and both have already produced confidently-reported
findings that were pure fiction:

- **Local names every database `local`** — only the socket differs. cb-test.local is socket
  `aRpCXvFUz`. The wrong socket silently pairs one site's files with another site's database.
- **CLI PHP has no Imagick; the site's web PHP does.** So the harnesses exercise `cwebp` while
  users get `imagick`. Never conclude an engine is unavailable from a CLI check.

Both cases are written up with the exact commands in `context/ai-workflow-rules.md`. When a
result says something surprising about the *environment* rather than the code, check the
environment first.

## A unit is not complete until

1. `npm run lint:php` clean (and `npm run lint:js` if JS changed)
2. The relevant PHP harnesses pass; `npm run test:e2e` too if admin UI changed
3. `/security-review` on the diff — plus `owasp-security-review` on the files if the unit touched
   REST, upload, shell-out, or backup/restore
4. `/graphify --update`
5. `progress-tracker.md` updated: spec moved to `specs/done/`, row added to Completed, next unit
   set In Progress, any architectural decision recorded in `architecture.md`

If tests fail, say so and show the output. Never describe partial work as complete, and
distinguish a test-fixture bug from a code bug explicitly — several early "failures" here were
bad fixtures, and calling those "fixed the bug" is misleading.
