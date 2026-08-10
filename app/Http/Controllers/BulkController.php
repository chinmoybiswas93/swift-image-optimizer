<?php
/**
 * Bulk run endpoints.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Http\Controllers;

use SwiftImageOptimizer\App\Services\Bulk\Runner;
use WP_Error;
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
     * Wire the controller to the bulk runner.
     *
     * @param Runner $runner Runner instance.
     */
    public function __construct( Runner $runner ) {
        $this->runner = $runner;
    }

    /**
     * Begin a run.
     *
     * @return WP_REST_Response
     */
    public function start() {
        return $this->sendSuccess($this->runner->start());
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
     * @return WP_REST_Response
     */
    public function cancel() {
        return $this->sendSuccess($this->runner->cancel());
    }
}
