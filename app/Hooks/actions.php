<?php
/**
 * Action registrations.
 *
 * The plugin's registration manifest: one readable list of everything that
 * wires itself into WordPress. This replaces the eight service providers the
 * plugin used to boot through - each of which existed only to hold one or two
 * of these lines.
 *
 * Required by Framework\Application::requireCommonFiles(). $app is in scope.
 *
 * @package SwiftImageOptimizer
 */

use SwiftImageOptimizer\App\App;
use SwiftImageOptimizer\App\Hooks\CLI\Commands;
use SwiftImageOptimizer\App\Hooks\Handlers\AssetHandler;
use SwiftImageOptimizer\App\Hooks\Handlers\MediaLibraryHandler;
use SwiftImageOptimizer\App\Hooks\Handlers\MenuHandler;
use SwiftImageOptimizer\App\Hooks\Handlers\NoticeHandler;
use SwiftImageOptimizer\App\Hooks\Scheduler\JobRunner;
use SwiftImageOptimizer\Api\StoreSettings;
use SwiftImageOptimizer\App\Services\Engine\EngineFactory;
use SwiftImageOptimizer\App\Services\Logging\Logger;
use SwiftImageOptimizer\App\Services\Rewrite\Fallback404;
use SwiftImageOptimizer\App\Services\Upload\Interceptor;

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Settings. register_setting() expects to run on init, so this manifest only
 * schedules it - unlike the rest of the file, which registers immediately
 * because add_action/add_filter are safe at any point before their hook fires.
 */
add_action('init', static function () {
    (new StoreSettings())->register();
});

// A settings change can alter which engine gets selected.
add_action('update_option_' . StoreSettings::OPTION, [EngineFactory::class, 'reset']);

// ... and whether logging is on, which Logger caches for the request.
add_action('update_option_' . StoreSettings::OPTION, [Logger::class, 'settings_changed'], 10, 2);

/*
 * Uploads. Intercepts new attachments so they are converted on the way in.
 */
(new Interceptor(App::make('optimizer')))->register();

/*
 * Backups. Daily retention sweep.
 */
(new JobRunner())->register();

/*
 * URL rewriting. Serves the converted file when a pre-conversion URL is hit.
 */
(new Fallback404())->register();

/*
 * Admin UI.
 */
add_action('init', static function () {
    if (!is_admin()) {
        return;
    }

    (new NoticeHandler())->register();
    (new MediaLibraryHandler())->register();
    (new MenuHandler())->register();
    (new AssetHandler())->register();
}, 5);

/*
 * WP-CLI.
 */
if (defined('WP_CLI') && WP_CLI) {
    $commands = App::make(Commands::class);

    WP_CLI::add_command('swift-image-optimizer optimize', [$commands, 'optimize']);
    WP_CLI::add_command('swift-image-optimizer restore', [$commands, 'restore']);
    WP_CLI::add_command('swift-image-optimizer stats', [$commands, 'stats']);
    WP_CLI::add_command('swift-image-optimizer diagnostics', [$commands, 'diagnostics']);
    WP_CLI::add_command('swift-image-optimizer logs', [$commands, 'logs']);
    WP_CLI::add_command('swift-image-optimizer requeue', [$commands, 'requeue']);
}
