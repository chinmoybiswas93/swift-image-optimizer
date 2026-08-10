<?php
/**
 * Site-administrator policy.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Http\Policies;

use WP_REST_Request;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Guards everything that changes plugin-wide state or reads server internals:
 * bulk runs, diagnostics, logs, backup purges.
 *
 * Replaces the old Controller::can_manage().
 */
class AdminPolicy extends Policy {

    /**
     * {@inheritDoc}
     *
     * @param WP_REST_Request $request Request.
     * @return bool
     */
    public function verifyRequest( WP_REST_Request $request ) {
        unset($request);

        return current_user_can('manage_options');
    }
}
