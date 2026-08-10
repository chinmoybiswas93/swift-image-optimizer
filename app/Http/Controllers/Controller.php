<?php
/**
 * Base REST controller.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Http\Controllers;

use WP_Error;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared response helpers.
 *
 * Controllers are resolved from the container by the router, so they declare
 * their dependencies as constructor arguments and never reach for a global.
 */
abstract class Controller
{
    /**
     * Return a successful response.
     *
     * @param mixed $data   Response body.
     * @param int   $status HTTP status.
     * @return WP_REST_Response
     */
    protected function sendSuccess($data, $status = 200)
    {
        return new WP_REST_Response($data, $status);
    }

    /**
     * Return an error response.
     *
     * @param string               $code    Machine-readable error code.
     * @param string               $message Human-readable message.
     * @param int                  $status  HTTP status.
     * @param array<string, mixed> $data    Extra context.
     * @return WP_Error
     */
    protected function sendError($code, $message, $status = 400, array $data = [])
    {
        return new WP_Error($code, $message, array_merge(['status' => $status], $data));
    }
}
