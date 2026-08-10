<?php
/**
 * Route manifest.
 *
 * Required by Framework\Application on rest_api_init, with $router in scope.
 * Routes are declared here and flushed through register_rest_route() in one
 * pass afterwards.
 *
 * @package SwiftImageOptimizer
 */

if (!defined('ABSPATH')) {
    exit;
}

/** @var \SwiftImageOptimizer\Framework\Router $router */
$router->group(function ($router) {
    require __DIR__ . '/api.php';
});
