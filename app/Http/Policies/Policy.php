<?php
/**
 * Base route policy.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Http\Policies;

use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A policy is the permission_callback for every route in its group.
 *
 * Keeping authorization in its own class - rather than a method on the
 * controller it guards - is what makes it reviewable: every route's policy is
 * visible in the route file, and there is one place per capability rule.
 */
abstract class Policy
{
    /**
     * Whether the request may proceed.
     *
     * @param WP_REST_Request $request Request.
     * @return bool
     */
    abstract public function verifyRequest(WP_REST_Request $request);
}
