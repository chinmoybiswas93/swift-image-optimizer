<?php
/**
 * Application configuration.
 *
 * @package SwiftImageOptimizer
 */

if (!defined('ABSPATH')) {
    exit;
}

return [
    'name'           => 'Swift Image Optimizer',
    'slug'           => 'swift-image-optimizer',
    'text_domain'    => 'swift-image-optimizer',
    'domain_path'    => '/languages',

    // Every do_action/apply_filters the plugin owns is prefixed with this.
    'hook_prefix'    => 'swift_image_optimizer/',

    // Together these form the REST base: /wp-json/swift-image-optimizer/v1
    // These values are load-bearing - the admin bundle calls these URLs.
    'rest_namespace' => 'swift-image-optimizer',
    'rest_version'   => 'v1',

    // 'dev' makes Vite serve assets from the dev server instead of assets/.
    'env'            => 'production',
];
