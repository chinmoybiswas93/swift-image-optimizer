# Pre-release checks

Surfaces that have never been exercised, or exercised on only one machine. **None of these is a
known defect** — that is exactly why they are here rather than in [issues.md](issues.md), which is
for things that need a code fix. Mixing the two made the old list impossible to act on.

Each entry names what would actually settle it. An entry leaves this file when it has been run, not
when it has been reasoned about.

| # | Surface | What settles it |
|---|---|---|
| 1 | **`repair-backups` has never run against real orphans.** I-8 closed on synthetic ones only: cb-test's backup folder was empty, so every stranded backup tested was harness-created. The routine reports "nothing to recover" there — correct and uninformative. | Run it on the first site that has real orphans, *before* anything sweeps them. |
| 2 | **`tests/e2e/library-scan.spec.js` has never run against a real login.** Confirmed only to load and skip cleanly without credentials. | `WP_ADMIN_USER` / `WP_ADMIN_PASSWORD` and a real run. |
| 3 | **The CLI suite has one site's evidence.** I-4 closed on cb-test alone. `run-cli.sh` is site-agnostic and `--smoke` is safe anywhere. | A run on a site with a different PHP version. |
| 4 | **The Troubleshoot tab is only partly browser-verified.** The Enable Log toggle is confirmed live. The diagnostics table, log viewer, Download, Reset, Requeue and Clean up buttons have not been clicked. | A browser pass over the remaining controls. |
| 5 | **Multisite is inferred, not tested.** Both tables are built from `$wpdb->prefix`, so they should follow the per-site pattern — but that is inference. `readme.txt` says nothing either way. | A multisite install, or an explicit "single site only" line in `readme.txt`. |
| 6 | **Client-side media processing is confirmed working, but only on this machine.** The support landed against a scripted reproduction of the exact sequence Gutenberg performs — `POST /wp/v2/media?generate_sub_sizes=false`, then `POST /wp/v2/media/<id>/sideload` for `scaled` and for a registered size, with the parameters core's `media-utils` sends — and a real block-editor upload on cb-test then confirmed the panel reports correctly. Only WordPress 7.1 on this one install, one browser, one engine (Imagick). | A block-editor upload on a second site, ideally one where cwebp or GD is the active engine, and on a non-Chromium browser — `wp_set_client_side_media_processing_flag()` varies its behaviour by Chromium version. |
| 7 | **Plugin Check has only been cleared sniff-by-sniff, not through the plugin itself.** Every code check was reproduced with Plugin Check's own bundled PHPCS (`plugin-check/vendor/squizlabs/php_codesniffer/bin/phpcs`) against its rulesets and its individually-invoked sniffs, all clean. The runtime checks — readme parsing, plugin headers, trademark and file-type scans — were not exercised, and nothing was run against the built zip. | `wp plugin check` on the extracted `dist/` zip, on the site's web PHP. Extract it somewhere disposable — never install it over the working tree. |
| 8 | **`readme.txt`'s `Tested up to` has no owner.** It reached WordPress.org review one version stale. Nothing bumps it, and nothing fails when it is behind. | Fold it into the release step alongside the version bump in `swift-image-optimizer.php`, and confirm it in the Plugin Check run from #7. |
