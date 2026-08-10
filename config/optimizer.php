<?php
/**
 * Optimizer defaults and constraints.
 *
 * These were previously hardcoded in SettingsRepository::defaults() and
 * ::sanitize(). Keeping the bounds here as data means the sanitizer and the
 * admin UI can be driven from one source rather than drifting apart.
 *
 * @package SwiftImageOptimizer
 */

if (!defined('ABSPATH')) {
    exit;
}

return [
    // The option row that holds the user's saved settings.
    'option_name' => 'swift_image_optimizer_settings',

    'defaults' => [
        'auto_optimize'       => 1,
        'quality'             => 82,
        'max_dimension'       => 2560,
        'engine'              => 'auto',
        'backup_retention'    => 30,
        'disable_wp_scaling'  => 0,
        'skip_if_larger'      => 1,
        'convert_png'         => 1,
        'rewrite_urls'        => 1,
        'enable_404_fallback' => 1,
        'backup_uploads'      => 1,
        'enable_log'          => 0,
    ],

    // Settings stored as 0/1. Anything falsy becomes 0.
    'booleans' => [
        'auto_optimize',
        'disable_wp_scaling',
        'skip_if_larger',
        'convert_png',
        'rewrite_urls',
        'enable_404_fallback',
        'backup_uploads',
        'enable_log',
    ],

    'quality' => [
        'min' => 1,
        'max' => 100,
    ],

    // 0 means "do not downscale"; any other value is clamped into the range.
    'max_dimension' => [
        'min' => 100,
        'max' => 10000,
    ],

    'engines' => ['auto', 'imagick', 'cwebp', 'gd'],

    // Backup retention in days. 0 means "keep forever".
    'retention_days' => [0, 7, 30, 90],
];
