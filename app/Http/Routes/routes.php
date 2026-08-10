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

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Router instance supplied by the route loader.
 *
 * @var \SwiftImageOptimizer\Framework\Router $router
 */
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $router is consumed by api.php, which is required into this closure's scope.
$router->group(function ( $router ) {
    require __DIR__ . '/api.php';
});
