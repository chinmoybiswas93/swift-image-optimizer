<?php
/**
 * Global helper functions.
 *
 * Autoloaded by Composer, so these are available from the moment
 * vendor/autoload.php is required. Declares functions only.
 *
 * @package SwiftImageOptimizer
 */

use SwiftImageOptimizer\App\App;
use SwiftImageOptimizer\App\Services\Logging\Logger;

if ( ! defined('ABSPATH')) {
    exit;
}

if ( ! function_exists('swift_image_optimizer_app')) {
    /**
     * The running application, or a service resolved from it.
     *
     * @param string|false $module Binding key, or false for the application.
     * @return mixed
     */
    function swift_image_optimizer_app( $module = false ) {
        if ($module) {
            return App::make($module);
        }

        return App::getInstance();
    }
}

if ( ! function_exists('swift_image_optimizer_config')) {
    /**
     * Read a config value by dot-notated key.
     *
     * @param string $key     Dot-notated key, e.g. 'app.slug'.
     * @param mixed  $default Returned when the key is absent.
     * @return mixed
     */
    function swift_image_optimizer_config( $key, $default = null ) {
        return App::config()->get($key, $default);
    }
}

if ( ! function_exists('swift_image_optimizer_view')) {
    /**
     * Render a view and return it as a string.
     *
     * @param string               $view View path, dot or slash notation.
     * @param array<string, mixed> $data Variables for the template.
     * @return string
     */
    function swift_image_optimizer_view( $view, array $data = [] ) {
        return App::view()->make($view, $data);
    }
}

if ( ! function_exists('swift_image_optimizer_log')) {
    /**
     * Write a line to the plugin log.
     *
     * @param string $message Message to record.
     * @param string $channel Channel the entry belongs to.
     * @return void
     */
    function swift_image_optimizer_log( $message, $channel = 'general' ) {
        Logger::mark($channel, $message);
    }
}
