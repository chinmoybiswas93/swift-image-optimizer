# Unit 01 — Foundation

## Goal

Everything the rest of the plugin stands on: bootstrap, autoloading, the log table, settings,
and the engine abstraction that decides how images actually get converted.

No image is converted in this unit. The deliverable is that `swift_image_optimizer()` boots
without error, the table exists, and `EngineFactory::get()` returns a usable engine on any
host that has one.

## Read first

- Nothing. This is the first unit.

## Files changed

| File | Purpose |
|---|---|
| `swift-image-optimizer.php` | Headers, constants, PSR-4 autoloader, activation/deactivation |
| `uninstall.php` | Drop table + options, never touch media |
| `src/Plugin.php` | Container; constructs and registers everything |
| `src/Database.php` | Table schema via `dbDelta`, CRUD, status constants |
| `src/Admin/Settings.php` | Defaults, `register_setting`, sanitize |
| `src/Admin/Notices.php` | "No engine available" admin notice |
| `src/Stats.php` | Aggregate savings, transient-cached |
| `src/Engine/EngineInterface.php` | The contract |
| `src/Engine/AbstractEngine.php` | Shared option parsing + `constrain()` |
| `src/Engine/GdEngine.php` | Universal fallback |
| `src/Engine/ImagickEngine.php` | Preferred engine |
| `src/Engine/CwebpEngine.php` | Opt-in binary path |
| `src/Engine/EngineFactory.php` | Detection, preference order, settings override |
| `phpcs.xml.dist` | WordPress-Extra + WordPress-Docs + PHPCompatibilityWP |

## Key decisions

**Hand-rolled autoloader, no Composer.** The plugin ships with zero runtime dependencies, so
`swift_image_optimizer_autoload()` maps `SwiftImageOptimizer\Foo\Bar` to `src/Foo/Bar.php`
directly. Composer is not used at all, not even for autoloading.

**One table, not several.** `{prefix}swift_image_optimizer_log` carries stats, restore data,
bulk dedupe state and the 404 URL map. A separate queue table was considered and rejected —
`Bulk\Scanner` gets the same result from a `LEFT JOIN`, and one table is one migration.

**Engine preference is Imagick → cwebp → GD.** Imagick leads because it is the only one that
can preserve an ICC profile. GD is last but is the one that will actually run on most hosts.

**`CwebpEngine` bundles nothing.** It checks `function_exists('exec')`, that `exec` is not in
`disable_functions`, that `safe_mode` is off, and that a binary exists — then steps aside
silently if any check fails. Bundling a binary would fail .org review outright.

## Completion Notes

Verified on this install:

```
imagick : no
cwebp   : YES   (/opt/homebrew/bin/cwebp)
gd      : YES   (WebP + AVIF support)
selected: cwebp
```

Conversion benchmarks from the standalone engine harness:

| Source | cwebp | GD |
|---|---|---|
| 267.5 KB JPEG @ 2048×1365 → 1920px | 100 KB (−62.6%), 1141 ms | 99.9 KB (−62.7%), 242 ms |
| 203.8 KB JPEG @ 1600×710 | 46.8 KB (−77.0%), 164 ms | 50.6 KB (−75.1%), 76 ms |

GD is competitive on output size and markedly faster here. The preference order still puts
cwebp above GD because cwebp preserves ICC (`-metadata icc`) and GD cannot.

Alpha preservation verified independently for both engines: a half-transparent test image came
back with `alpha=127` on the transparent side and `alpha=0` on the opaque side.

`ImagickEngine` has **never executed** — Imagick is not installed on this machine. Its
`autoOrient()` fallback path and ICC extract/reapply logic are unverified. Flagged in
`progress-tracker.md` Open Questions.

Every file parses under PHP 7.4, 8.2 and 8.4.
