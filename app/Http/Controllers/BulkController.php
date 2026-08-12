<?php
/**
 * Bulk run endpoints.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Http\Controllers;

use SwiftImageOptimizer\App\Services\Bulk\Coordinator;
use SwiftImageOptimizer\App\Services\Bulk\Runner;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Drives the bulk runner.
 *
 * These were previously one endpoint switching on a regex-captured {action}
 * segment. They are four routes now, which is what lets each one declare its
 * own HTTP verb - polling status over GET rather than POST.
 */
class BulkController extends Controller {

    /**
     * Bulk runner instance.
     *
     * @var Runner
     */
    private $runner;

    /**
     * Full-run coordinator.
     *
     * @var Coordinator
     */
    private $coordinator;

    /**
     * Wire the controller to the bulk runner and the full-run coordinator.
     *
     * @param Runner      $runner      Runner instance.
     * @param Coordinator $coordinator Coordinator instance.
     */
    public function __construct( Runner $runner, Coordinator $coordinator ) {
        $this->runner      = $runner;
        $this->coordinator = $coordinator;
    }

    /**
     * Begin a run, resume a stopped one, or report the one already going.
     *
     * `fresh` discards existing progress and starts from the first image.
     * Without it, a stopped run continues from its cursor and a live run is
     * returned untouched - start() is idempotent, so a second tab clicking
     * Start can no longer reset a run that is mid-flight.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function start( $request ) {
        $fresh = $request instanceof WP_REST_Request ? (bool) $request->get_param('fresh') : false;

        return $this->sendSuccess($this->runner->start($fresh));
    }

    /**
     * Current run state.
     *
     * @return WP_REST_Response
     */
    public function status() {
        return $this->sendSuccess($this->runner->state());
    }

    /**
     * Process the next batch.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function batch() {
        $state = $this->runner->process_batch();

        return is_wp_error($state) ? $state : $this->sendSuccess($state);
    }

    /**
     * Stop the current run.
     *
     * Cancels through the coordinator so a chained run does not leave its
     * closing scan queued behind a run that is no longer happening.
     *
     * @return WP_REST_Response
     */
    public function cancel() {
        return $this->sendSuccess($this->coordinator->cancel());
    }

    /**
     * Begin a full run: measure, optimize, measure again.
     *
     * The two scans are the point. Without the first, the run starts from
     * whatever the log table last claimed; without the second, it leaves those
     * claims behind as the dashboard's figures. With both, every number the
     * user ends up looking at was counted against the disk.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function run() {
        $state = $this->coordinator->start_full_run();

        return is_wp_error($state) ? $state : $this->sendSuccess($state);
    }

    /**
     * Everything the dashboard polls, in one response.
     *
     * Scan state, run state and the published snapshot travel together so the
     * ring, the progress bar and the tiles cannot disagree by having been
     * fetched a moment apart.
     *
     * @return WP_REST_Response
     */
    public function phase() {
        return $this->sendSuccess($this->coordinator->state());
    }
}
