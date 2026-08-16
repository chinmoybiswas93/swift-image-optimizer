<?php
/**
 * Argument schema for starting a bulk run.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Http\Requests;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Validates the one parameter POST bulk/start accepts.
 */
class BulkStartRequest {

    /**
     * Route arguments.
     *
     * @return array<string, mixed>
     */
    public static function args() {
        return [
            'fresh' => [
                'type'              => 'boolean',
                'required'          => false,
                'default'           => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
        ];
    }
}
