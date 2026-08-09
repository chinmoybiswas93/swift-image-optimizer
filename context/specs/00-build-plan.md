# Plugin Build Plan

10 units. Units 01–07 built v1.0.0 and are complete. Units 08–10 are what stands between the
current state and a WordPress.org submission.

## Ordering principle

The build order is driven by one rule: **nothing destructive may be written before the thing
that makes it reversible exists.** Backups (03) land before the rewriter (04), and the rewriter
lands before anything that calls it (05).

## Phase 1 — Foundation

| Unit | Spec | What | Depends on |
|---|---|---|---|
| 01 | `01-foundation.md` | Bootstrap, PSR-4 autoloader, `Database` log table, `Settings`, `Engine\*` abstraction + detection | — |

## Phase 2 — Feature 1

| Unit | Spec | What | Depends on |
|---|---|---|---|
| 02 | `02-upload-optimization.md` | `Optimizer` + `Upload\Interceptor`. Independently shippable — a working plugin on its own. | 01 |

## Phase 3 — Safety net

| Unit | Spec | What | Depends on |
|---|---|---|---|
| 03 | `03-backup-retention.md` | `Backup\BackupManager` + `Backup\RetentionCron`. **Must land before any destructive code.** | 01 |

## Phase 4 — The risky part

| Unit | Spec | What | Depends on |
|---|---|---|---|
| 04 | `04-url-rewriting.md` | `Rewrite\UrlMap` + `Rewrite\DatabaseRewriter` + `Rewrite\Fallback404`, with dry run. Built and verified in isolation before anything calls it. | 01 |

## Phase 5 — Feature 2

| Unit | Spec | What | Depends on |
|---|---|---|---|
| 05 | `05-attachment-conversion.md` | `AttachmentConverter` (convert + restore) + `Media\ListTable` | 02, 03, 04 |

## Phase 6 — Scale

| Unit | Spec | What | Depends on |
|---|---|---|---|
| 06 | `06-bulk-rest-cli.md` | `Bulk\Scanner` + `Bulk\Runner` + `Rest\Controller` + `Bulk\Cli` | 05 |

## Phase 7 — Interface

| Unit | Spec | What | Depends on |
|---|---|---|---|
| 07 | `07-react-admin.md` | React dashboard, `@wordpress/scripts` build, `Admin\SettingsPage` | 06 |

## Phase 8 — Ship

| Unit | Spec | What | Depends on |
|---|---|---|---|
| 08 | `08-phpcs-compliance.md` | Install WPCS, run it, fix findings. **Blocks submission.** | 07 |
| 09 | `09-media-grid-modal.md` | Media modal sidebar panel for grid view | 07 |
| 10 | `10-orphan-cleanup-tool.md` | Surface and clean attachment rows with no file on disk | 06 |

## Checkpoints

Units 02 and 05 are the natural stopping points — each leaves a plugin that does something
useful on its own. Unit 02 alone is a complete "optimize on upload" plugin; Unit 05 adds
per-image control of existing media without needing bulk.

## Test coverage by unit

| Unit | Harness | Assertions |
|---|---|---|
| 01, 02 | `upload-e2e.php` | 22 |
| 04 | `rewriter-test.php` | 21 |
| 03, 04, 05 | `convert-restore-e2e.php` | 35 |
| 06, 07 | `bulk-e2e.php` | 29 |
| | **Total** | **107** |
