# Graph Report - .  (2026-08-10)

## Corpus Check
- 32 files · ~60,163 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 805 nodes · 1273 edges · 98 communities (43 shown, 55 thin omitted)
- Extraction: 82% EXTRACTED · 18% INFERRED · 0% AMBIGUOUS · INFERRED: 233 edges (avg confidence: 0.81)
- Token cost: 238,754 input · 4,200 output

## Community Hubs (Navigation)
- [[_COMMUNITY_Engine Abstraction & Selection|Engine Abstraction & Selection]]
- [[_COMMUNITY_Media Library UI & Agent Rules|Media Library UI & Agent Rules]]
- [[_COMMUNITY_Attachment Conversion & URL Map|Attachment Conversion & URL Map]]
- [[_COMMUNITY_File-Based Activity Logger|File-Based Activity Logger]]
- [[_COMMUNITY_REST Controller Endpoints|REST Controller Endpoints]]
- [[_COMMUNITY_Admin Icon Set|Admin Icon Set]]
- [[_COMMUNITY_Media Modal Progress UI|Media Modal Progress UI]]
- [[_COMMUNITY_List Table Actions & Rewriter Design|List Table Actions & Rewriter Design]]
- [[_COMMUNITY_Bulk Run Progress State|Bulk Run Progress State]]
- [[_COMMUNITY_WP-CLI Commands & Scanner|WP-CLI Commands & Scanner]]
- [[_COMMUNITY_Environment Diagnostics Report|Environment Diagnostics Report]]
- [[_COMMUNITY_Backup Manager|Backup Manager]]
- [[_COMMUNITY_Log Table & URL Index|Log Table & URL Index]]
- [[_COMMUNITY_Bulk Runner & Lock|Bulk Runner & Lock]]
- [[_COMMUNITY_Service Container & Bootstrap|Service Container & Bootstrap]]
- [[_COMMUNITY_Database Rewriter|Database Rewriter]]
- [[_COMMUNITY_Build Tooling Manifest|Build Tooling Manifest]]
- [[_COMMUNITY_Outcome Logging Fail-Closed Rules|Outcome Logging Fail-Closed Rules]]
- [[_COMMUNITY_Backup Retention Cron|Backup Retention Cron]]
- [[_COMMUNITY_Optimizer Core|Optimizer Core]]
- [[_COMMUNITY_Deferred Batch Rewrite|Deferred Batch Rewrite]]
- [[_COMMUNITY_Upload Interceptor Path|Upload Interceptor Path]]
- [[_COMMUNITY_List Table Rendering|List Table Rendering]]
- [[_COMMUNITY_Restore & Backup Manifest|Restore & Backup Manifest]]
- [[_COMMUNITY_Engine Fallback Chain|Engine Fallback Chain]]
- [[_COMMUNITY_Engine Factory Availability|Engine Factory Availability]]
- [[_COMMUNITY_Settings Page & Stats|Settings Page & Stats]]
- [[_COMMUNITY_Retryable Skips & Requeue|Retryable Skips & Requeue]]
- [[_COMMUNITY_Troubleshoot & Log Viewer|Troubleshoot & Log Viewer]]
- [[_COMMUNITY_Media Grid Modal Fix Plan|Media Grid Modal Fix Plan]]
- [[_COMMUNITY_React Admin Shell|React Admin Shell]]
- [[_COMMUNITY_Conversion Pipeline Specs|Conversion Pipeline Specs]]
- [[_COMMUNITY_Optimizer Legacy Nodes|Optimizer Legacy Nodes]]
- [[_COMMUNITY_Engine Convert Implementations|Engine Convert Implementations]]
- [[_COMMUNITY_Settings & Backup Expiry|Settings & Backup Expiry]]
- [[_COMMUNITY_Logging Provider Boot|Logging Provider Boot]]
- [[_COMMUNITY_Disk Usage & Space Checks|Disk Usage & Space Checks]]
- [[_COMMUNITY_Backup Deletion Incident Notes|Backup Deletion Incident Notes]]
- [[_COMMUNITY_Release Gate Issues|Release Gate Issues]]
- [[_COMMUNITY_Orphaned Attachments|Orphaned Attachments]]
- [[_COMMUNITY_Admin & Rewrite Providers|Admin & Rewrite Providers]]
- [[_COMMUNITY_DI Container|DI Container]]
- [[_COMMUNITY_404 Fallback Serving|404 Fallback Serving]]
- [[_COMMUNITY_Log Verbosity Gating|Log Verbosity Gating]]
- [[_COMMUNITY_Admin Asset Enqueue|Admin Asset Enqueue]]
- [[_COMMUNITY_Backup & Rewrite Unit Specs|Backup & Rewrite Unit Specs]]
- [[_COMMUNITY_Foundation Unit Specs|Foundation Unit Specs]]
- [[_COMMUNITY_Upload Timing Design Facts|Upload Timing Design Facts]]
- [[_COMMUNITY_E2E Test Harness|E2E Test Harness]]
- [[_COMMUNITY_Localized JS Config|Localized JS Config]]
- [[_COMMUNITY_Bulk Panel UI|Bulk Panel UI]]
- [[_COMMUNITY_Settings Panel & Option|Settings Panel & Option]]
- [[_COMMUNITY_Dry-Run Estimation|Dry-Run Estimation]]
- [[_COMMUNITY_No-Engine Admin Notice|No-Engine Admin Notice]]
- [[_COMMUNITY_Engine Availability Surfacing|Engine Availability Surfacing]]
- [[_COMMUNITY_App Provider Boot|App Provider Boot]]
- [[_COMMUNITY_Lock Mutual Exclusion|Lock Mutual Exclusion]]
- [[_COMMUNITY_Webpack Config|Webpack Config]]
- [[_COMMUNITY_Community 63|Community 63]]
- [[_COMMUNITY_Community 64|Community 64]]
- [[_COMMUNITY_Community 65|Community 65]]
- [[_COMMUNITY_Community 67|Community 67]]
- [[_COMMUNITY_Community 68|Community 68]]
- [[_COMMUNITY_Community 69|Community 69]]
- [[_COMMUNITY_Community 70|Community 70]]
- [[_COMMUNITY_Community 71|Community 71]]
- [[_COMMUNITY_Community 72|Community 72]]
- [[_COMMUNITY_Community 73|Community 73]]
- [[_COMMUNITY_Community 74|Community 74]]
- [[_COMMUNITY_Community 75|Community 75]]
- [[_COMMUNITY_Community 76|Community 76]]
- [[_COMMUNITY_Community 77|Community 77]]
- [[_COMMUNITY_Community 78|Community 78]]
- [[_COMMUNITY_Community 79|Community 79]]
- [[_COMMUNITY_Community 80|Community 80]]
- [[_COMMUNITY_Community 81|Community 81]]
- [[_COMMUNITY_Community 82|Community 82]]
- [[_COMMUNITY_Community 83|Community 83]]
- [[_COMMUNITY_Community 84|Community 84]]
- [[_COMMUNITY_Community 85|Community 85]]
- [[_COMMUNITY_Community 86|Community 86]]
- [[_COMMUNITY_Community 87|Community 87]]
- [[_COMMUNITY_Community 88|Community 88]]
- [[_COMMUNITY_Community 89|Community 89]]
- [[_COMMUNITY_Community 90|Community 90]]
- [[_COMMUNITY_Community 91|Community 91]]
- [[_COMMUNITY_Community 92|Community 92]]
- [[_COMMUNITY_Community 93|Community 93]]
- [[_COMMUNITY_Community 95|Community 95]]
- [[_COMMUNITY_Community 96|Community 96]]
- [[_COMMUNITY_Community 97|Community 97]]

## God Nodes (most connected - your core abstractions)
1. `BackupManager` - 54 edges
2. `Logger` - 52 edges
3. `Controller::scan` - 45 edges
4. `Scanner` - 37 edges
5. `SettingsRepository` - 35 edges
6. `Database` - 33 edges
7. `Database` - 30 edges
8. `EnvironmentReport` - 26 edges
9. `App` - 22 edges
10. `DatabaseRewriter` - 21 edges

## Surprising Connections (you probably didn't know these)
- `Cimo — the direct inspiration, and why we diverged` --semantically_similar_to--> `Interceptor (Upload)`  [INFERRED] [semantically similar]
  context/competitor-features.md → src/Services/Upload/Interceptor.php
- `EnvironmentReport` --conceptually_related_to--> `Troubleshoot screen (diagnostics + requeue)`  [INFERRED]
  src/Services/Diagnostics/EnvironmentReport.php → context/ui-context.md
- `Swift Image Optimizer readme.txt` --references--> `EngineFactory`  [INFERRED]
  readme.txt → src/Engine/EngineFactory.php
- `Media > Bulk Optimize React dashboard` --references--> `BackupManager::disk_usage`  [INFERRED]
  context/ui-context.md → src/Backup/BackupManager.php
- `Swift Image Optimizer readme.txt` --references--> `BackupManager`  [INFERRED]
  readme.txt → src/Services/Backup/BackupManager.php

## Communities (98 total, 55 thin omitted)

### Community 0 - "Engine Abstraction & Selection"
Cohesion: 0.06
Nodes (14): Backup path-traversal guard, Engine Selection, The Three Architectural Camps, AbstractEngine, CwebpEngine, CwebpEngine, EngineFactory, EngineInterface (+6 more)

### Community 1 - "Media Library UI & Agent Rules"
Cohesion: 0.06
Nodes (46): BackupsPanel(), ListTable::expose_to_js() (log row into media modal), Skip/failure reason codes vocabulary, ListTable::reason_label() (reason code to human text), ListTable::render_column() (renders log row by status), ListTable::add_row_actions() (Optimize / Restore links), agent.md — Swift Image Optimizer agent instructions, Environment pitfalls: per-site MySQL socket, CLI vs web Imagick (+38 more)

### Community 2 - "Attachment Conversion & URL Map"
Cohesion: 0.07
Nodes (4): Bug: soft-error list duplicated and out of sync, UrlMap, Database, Stats

### Community 5 - "Admin Icon Set"
Cohesion: 0.14
Nodes (13): IconArchive(), IconBolt(), IconDisk(), IconDocument(), IconGear(), IconImage(), IconLayers(), IconSliders() (+5 more)

### Community 6 - "Media Modal Progress UI"
Cohesion: 0.15
Nodes (11): buildPanel(), el(), formatBytes(), formatDuration(), formatFormat(), OptimizeProgress, registerToolbar(), renderPanel() (+3 more)

### Community 7 - "List Table Actions & Rewriter Design"
Cohesion: 0.13
Nodes (5): The Rewriter, The Delivery Question, and Why We Answered It Differently, Decision: No JsonRewriter Class, ListTable, Swift Image Optimizer readme.txt

### Community 8 - "Bulk Run Progress State"
Cohesion: 0.13
Nodes (15): Cli::logs(), Runner::cancel(), Runner::process_batch(), Bulk progress option (swift_image_optimizer_bulk_progress), Runner::start(), Scanner::count_pending(), Scanner::summary(), Terminal status rows keep an image out of the pending queue (+7 more)

### Community 16 - "Build Tooling Manifest"
Cohesion: 0.13
Nodes (14): description, devDependencies, @playwright/test, @wordpress/scripts, license, name, private, scripts (+6 more)

### Community 17 - "Outcome Logging Fail-Closed Rules"
Cohesion: 0.18
Nodes (14): Fail-closed rule: a backup failure aborts the conversion, Confirm before anything destructive, Copy guidelines: write for a site owner, never imply irreversible is safe, Database::upsert(), Logger::warn(), Logger::wp_error(), AttachmentConverter::drop_foreign_filenames(), AttachmentConverter::log_failure() (+6 more)

### Community 18 - "Backup Retention Cron"
Cohesion: 0.14
Nodes (4): RetentionCron::purge, Architecture Invariants, Decision: Backup Expiry Does Not Change Status, Decision: Soft-Error Classification Centralized

### Community 20 - "Deferred Batch Rewrite"
Cohesion: 0.19
Nodes (10): Cli::optimize(), Deferred rewrite: one combined URL map per batch, Runner::dry_run(), Scanner::next_batch(), Dry-run card placed above the start button, DatabaseRewriter::needles(), DatabaseRewriter::process_table(), DatabaseRewriter::replace() (+2 more)

### Community 21 - "Upload Interceptor Path"
Cohesion: 0.18
Nodes (3): Cimo — the direct inspiration, and why we diverged, Interceptor (Upload), The upload path (Feature 1)

### Community 23 - "Restore & Backup Manifest"
Cohesion: 0.20
Nodes (11): BackupManager::delete, Backup manifest (relative_dir / files / expires), BackupManager::manifest_is_intact(), BackupManager::purge_files(), BackupManager::restore, BackupManager::safe_path, Code Standards: Security, Database::forget_urls() (+3 more)

### Community 24 - "Engine Fallback Chain"
Cohesion: 0.21
Nodes (7): EngineFactory::chain(), Engine fallback chain (imagick, cwebp, gd), EngineFactory::for_file(), Optimizer::can_optimize(), Optimizer::optimize(), Optimizer::temp_dir(), Optimizer::temp_path()

### Community 28 - "Retryable Skips & Requeue"
Cohesion: 0.18
Nodes (10): BackupManager::has_space_for(), Cli::diagnostics(), Cli::requeue(), Scanner::count_retryable(), Scanner::requeue(), Never show a bare error code (ListTable::reason_label), Troubleshoot screen (diagnostics + requeue), Logger::error() (+2 more)

### Community 29 - "Troubleshoot & Log Viewer"
Cohesion: 0.22
Nodes (9): Activity log viewer (tail, filter, download, reset), TroubleshootPanel(), Controller::cleanup() (sweep temp files), Controller::diagnostics(), Controller::download_log() (streams log file), Controller::logs() (log tail endpoint), Controller::requeue() (deletes rows to re-queue), Database::delete() (+1 more)

### Community 30 - "Media Grid Modal Fix Plan"
Cohesion: 0.24
Nodes (10): Fix Plan, Current Issues, I-6 Media grid modal shows nothing, Media/ListTable.php, Rest/Controller.php, Unit 07 - React Admin, Unit 09 - Media Grid Modal Panel, Open question: should Restore appear in modal (+2 more)

### Community 31 - "React Admin Shell"
Cohesion: 0.24
Nodes (6): admin/icons.js inline icon set, admin/index.js React dashboard root, App(), SettingsPage (Media submenu, enqueues React bundle), admin/tabs.js Tabs (WAI-ARIA tab panel), Plugin

### Community 32 - "Conversion Pipeline Specs"
Cohesion: 0.31
Nodes (9): I-9 Backup restore relies on JSON blob, Optimizer.php, Backup/BackupManager.php, Rewrite/DatabaseRewriter.php, Rewrite/UrlMap.php, Unit 05 - Attachment Conversion, Convert pipeline (13 steps), Future Spec: Before/After Quality Preview (+1 more)

### Community 35 - "Engine Convert Implementations"
Cohesion: 0.25
Nodes (7): Code Standards: Error Handling, AbstractEngine::constrain, swift_image_optimizer_cwebp_binary filter, CwebpEngine::convert, CwebpEngine::locate_binary, GdEngine::convert, ImagickEngine::convert

### Community 37 - "Logging Provider Boot"
Cohesion: 0.29
Nodes (4): ListTable (Media Library column, row and bulk actions), LoggingServiceProvider, Plugin bootstrap (boots providers), Provider boot order invariant (AppServiceProvider first)

### Community 38 - "Disk Usage & Space Checks"
Cohesion: 0.33
Nodes (6): BackupManager::disk_usage, BackupManager::ensure_root(), Cli::stats(), EnvironmentReport::filesystem(), Logger::ensure_dir(), Controller::purge_backups

### Community 39 - "Backup Deletion Incident Notes"
Cohesion: 0.52
Nodes (4): 2026-08-08 backup-deletion incident (glob cleanup), Unit 08 - Media Library UI spec (done), CLI PHP vs web PHP engine discrepancy (I-2), Upload-path images have no backup (I-5)

### Community 40 - "Release Gate Issues"
Cohesion: 0.29
Nodes (7): Release gate issue ordering, I-1 PHPCS never run, I-3 React UI never opened in a browser, I-4 WP-CLI commands untested at runtime, I-8 Multisite unconsidered, Bulk/Cli.php, Bug: Cli::optimize_ids() called but never defined

### Community 41 - "Orphaned Attachments"
Cohesion: 0.38
Nodes (7): I-5 496 orphaned attachments, Database.php, Unit 06 - Bulk, REST and WP-CLI, Bulk/Scanner.php, Unit 10 - Orphaned Attachment Report, OrphanScanner.php (new), Bulk Optimize tab layout

### Community 45 - "Log Verbosity Gating"
Cohesion: 0.29
Nodes (6): Errors always written, verbose only when enabled, Logger::info(), Logger::is_enabled(), Logger::should_write(), Logger::write(), AttachmentConverter::delete_files()

### Community 47 - "Backup & Rewrite Unit Specs"
Cohesion: 0.33
Nodes (6): Unit 03 - Backups & Retention, Backup tree mirrors uploads path, Unit 04 - URL Rewriting, Never plain string-replace serialized data, Ordering principle: reversibility before destruction, Backups tab layout

### Community 48 - "Foundation Unit Specs"
Cohesion: 0.40
Nodes (6): Unit 01 - Foundation, Engine/EngineFactory.php, Unit 02 - Upload Optimization, Unit Plan: Plugin Build Plan, Rules for fixing sniff findings, Unit 09 - PHPCS Compliance spec

### Community 49 - "Upload Timing Design Facts"
Cohesion: 0.33
Nodes (6): wp_handle_upload timing insight, Upload/Interceptor.php, AttachmentConverter.php, Project Overview, No non-destructive mode decision, Upload happens before wp_insert_attachment (design fact)

### Community 50 - "E2E Test Harness"
Cohesion: 0.33
Nodes (4): wp-login page loads test, swift_image_optimizer() bootstrap, failedTests, status

### Community 51 - "Localized JS Config"
Cohesion: 0.50
Nodes (5): window.swiftImageOptimizer localized config, request(), request(), SettingsPage::enqueue() (localizes swiftImageOptimizer), Controller (swift-image-optimizer/v1 REST routes)

### Community 52 - "Bulk Panel UI"
Cohesion: 0.40
Nodes (5): BulkPanel(), formatBytes(), HeroStats(), runSelection(), Controller::bulk() (start/status/cancel/batch)

### Community 53 - "Settings Panel & Option"
Cohesion: 0.40
Nodes (4): SettingsPanel(), swift_image_optimizer_settings option, SettingsRepository::register() (show_in_rest schema), SettingsRepository::sanitize() (rebuilds whole array)

### Community 54 - "Dry-Run Estimation"
Cohesion: 0.40
Nodes (5): I-7 Dry-run extrapolation is linear estimate, $defer_rewrite parameter, Bulk/Runner.php, Future Spec: Optimize Files Outside the Media Library, Future Spec: Scheduled Background Optimization

### Community 56 - "Engine Availability Surfacing"
Cohesion: 0.50
Nodes (4): Assets::enqueue, EnvironmentReport::engines(), EngineFactory::availability, EngineFactory::get

### Community 60 - "Lock Mutual Exclusion"
Cohesion: 0.67
Nodes (3): Lock (add_option-based mutual exclusion), Why add_option, not transients (check-then-set race), Uninstall sweep of swift_image_optimizer_lock_* options

## Ambiguous Edges - Review These
- `Notices` → `Controller::scan`  [AMBIGUOUS]
  src/Http/Admin/Notices.php · relation: semantically_similar_to

## Knowledge Gaps
- **86 isolated node(s):** `{ defineConfig }`, `defaultConfig`, `path`, `name`, `version` (+81 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **55 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **What is the exact relationship between `Notices` and `Controller::scan`?**
  _Edge tagged AMBIGUOUS (relation: semantically_similar_to) - confidence is low._
- **Why does `BackupManager` connect `Backup Manager` to `Engine Abstraction & Selection`, `Media Library UI & Agent Rules`, `Attachment Conversion & URL Map`, `REST Controller Endpoints`, `List Table Actions & Rewriter Design`, `WP-CLI Commands & Scanner`, `Environment Diagnostics Report`, `Log Table & URL Index`, `Outcome Logging Fail-Closed Rules`, `Backup Retention Cron`, `Upload Interceptor Path`, `List Table Rendering`, `Restore & Backup Manifest`, `Engine Factory Availability`, `Settings Page & Stats`, `Retryable Skips & Requeue`, `Settings Repository`, `Settings & Backup Expiry`, `Disk Usage & Space Checks`, `Backup Deletion Incident Notes`, `Log Verbosity Gating`, `Foundation Unit Specs`?**
  _High betweenness centrality (0.218) - this node is a cross-community bridge._
- **Why does `Controller::scan` connect `REST Controller Endpoints` to `Attachment Conversion & URL Map`, `File-Based Activity Logger`, `Disk Usage & Space Checks`, `WP-CLI Commands & Scanner`, `Environment Diagnostics Report`, `Backup Manager`, `Log Table & URL Index`, `Bulk Runner & Lock`, `Admin Asset Enqueue`, `Backup Retention Cron`, `List Table Rendering`, `No-Engine Admin Notice`, `Engine Availability Surfacing`, `Engine Factory Availability`, `Settings Page & Stats`?**
  _High betweenness centrality (0.132) - this node is a cross-community bridge._
- **Why does `App()` connect `React Admin Shell` to `Media Library UI & Agent Rules`, `Admin Icon Set`, `Media Modal Progress UI`, `Service Container & Bootstrap`, `Bulk Panel UI`, `Settings Panel & Option`, `Settings Page & Stats`, `Troubleshoot & Log Viewer`?**
  _High betweenness centrality (0.126) - this node is a cross-community bridge._
- **Are the 21 inferred relationships involving `BackupManager` (e.g. with `.do_convert()` and `.restore()`) actually correct?**
  _`BackupManager` has 21 INFERRED edges - model-reasoned connections that need verification._
- **Are the 28 inferred relationships involving `Logger` (e.g. with `.replace()` and `.start()`) actually correct?**
  _`Logger` has 28 INFERRED edges - model-reasoned connections that need verification._
- **Are the 2 inferred relationships involving `Controller::scan` (e.g. with `SettingsPage.php` and `ListTable`) actually correct?**
  _`Controller::scan` has 2 INFERRED edges - model-reasoned connections that need verification._