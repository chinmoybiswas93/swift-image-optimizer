<?php
/**
 * Diagnostic log endpoints.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Http\Controllers;

use SwiftImageOptimizer\App\Services\Logging\Logger;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Reading, downloading and clearing the plugin's log file.
 */
class LogController extends Controller {

    /**
     * Tail of the log.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function index( WP_REST_Request $request ) {
        return $this->sendSuccess(Logger::tail( (int) $request->get_param('lines')));
    }

    /**
     * Send the whole log file as a download.
     *
     * Streamed rather than returned as JSON: the file can be 10MB, and base64
     * inside a JSON envelope would make it larger still. Exits rather than
     * returning, so the REST server never gets to append its own body.
     *
     * @return void
     */
    public function download() {
        $file = Logger::file();

        if (is_wp_error($file) || ! file_exists($file)) {
            status_header(404);
            exit;
        }

        nocache_headers();

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="swift-image-optimizer-' . gmdate('Ymd-His') . '.log"');
        header('Content-Length: ' . filesize($file));

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a plain text file the plugin itself wrote; WP_Filesystem would load it entirely into memory.
        readfile($file);

        exit;
    }

    /**
     * Delete the log and start a fresh one.
     *
     * @return WP_REST_Response
     */
    public function reset() {
        Logger::clear();
        Logger::start_run('admin');
        Logger::mark('logging', 'Log cleared from the Troubleshoot screen.');

        return $this->sendSuccess([
            'cleared' => true,
            'log'     => Logger::tail(100),
        ]);
    }
}
