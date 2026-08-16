<?php
/**
 * Library scan endpoints.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Http\Controllers;

use SwiftImageOptimizer\App\Services\Bulk\ScanRunner;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Drives the library scan.
 *
 * Shaped like BulkController because the scan is the same kind of thing: a
 * long job the server owns, advanced a batch at a time by whichever of cron or
 * the open tab gets there first.
 */
class ScanController extends Controller {

    /**
     * Scan engine.
     *
     * @var ScanRunner
     */
    private $scanner;

    /**
     * Wire the controller to the scan engine.
     *
     * @param ScanRunner $scanner Scan engine.
     */
    public function __construct( ScanRunner $scanner ) {
        $this->scanner = $scanner;
    }

    /**
     * Begin a scan, or report the one already running.
     *
     * Returns the bulk-active error verbatim rather than swallowing it, so the
     * dashboard can explain why the button did nothing instead of appearing
     * to fail.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function start( $request ) {
        $force = $request instanceof WP_REST_Request ? (bool) $request->get_param('force') : false;

        // `force` restarts a scan that is already in flight - the case being a
        // stalled one, where the queued cron tick is fine and it is the request
        // to run it that never arrived. It does not override the bulk-active
        // guard, which is what keeps the published figures coherent.
        $state = $this->scanner->start('manual', '', $force);

        return is_wp_error($state) ? $state : $this->sendSuccess($state);
    }

    /**
     * Current scan state, with the published snapshot attached.
     *
     * @return WP_REST_Response
     */
    public function status() {
        return $this->sendSuccess($this->scanner->state());
    }

    /**
     * Classify the next page of attachments.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function batch() {
        $state = $this->scanner->process_batch();

        return is_wp_error($state) ? $state : $this->sendSuccess($state);
    }

    /**
     * Abandon the running scan.
     *
     * @return WP_REST_Response
     */
    public function cancel() {
        return $this->sendSuccess($this->scanner->cancel());
    }

    /**
     * The last published snapshot on its own.
     *
     * @return WP_REST_Response
     */
    public function snapshot() {
        $snapshot = ScanRunner::snapshot();

        return $this->sendSuccess([
            'snapshot' => $snapshot,
            'stale'    => ScanRunner::snapshot_is_stale($snapshot),
        ]);
    }
}
