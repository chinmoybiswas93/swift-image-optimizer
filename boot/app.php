<?php
/**
 * Application bootstrap.
 *
 * Returns the closure the main plugin file invokes with __FILE__. Everything
 * the plugin does starts here.
 *
 * @package SwiftImageOptimizer
 */

use SwiftImageOptimizer\Framework\Application;
use SwiftImageOptimizer\App\Hooks\Handlers\ActivationHandler;
use SwiftImageOptimizer\App\Hooks\Handlers\DeactivationHandler;
use SwiftImageOptimizer\Database\DBMigrator;

if (!defined('ABSPATH')) {
    exit;
}

return function ($file) {

    // Constructing the Application also points the App facade at it, loads
    // boot/bindings.php, and requires the hook manifests.
    $app = new Application($file);

    register_activation_hook($file, function ($networkWide = false) use ($app) {
        $app->make(ActivationHandler::class)->handle($networkWide);
    });

    register_deactivation_hook($file, function () use ($app) {
        $app->make(DeactivationHandler::class)->handle();
    });

    add_action('plugins_loaded', function () use ($app) {
        do_action('swift_image_optimizer/loaded', $app);

        add_action('init', function () use ($app) {
            // Apply any pending schema change before anything reads the tables.
            DBMigrator::migrateUp();

            do_action('swift_image_optimizer/init', $app);
        }, 1);
    });

    return $app;
};
