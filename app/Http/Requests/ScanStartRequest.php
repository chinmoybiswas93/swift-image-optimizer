<?php
/**
 * Argument schema for starting a library scan.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Http\Requests;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Validates the one parameter POST library/scan accepts.
 */
class ScanStartRequest {

    /**
     * Route arguments.
     *
     * @return array<string, mixed>
     */
    public static function args() {
        return [
            'force' => [
                'type'              => 'boolean',
                'required'          => false,
                'default'           => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
        ];
    }
}
