<?php
/**
 * Plugin deactivation.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Hooks\Handlers;

use SwiftImageOptimizer\App\Hooks\Scheduler\BulkJobRunner;
use SwiftImageOptimizer\App\Hooks\Scheduler\JobRunner;
use SwiftImageOptimizer\App\Hooks\Scheduler\ScanJobRunner;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Clears scheduled events. Data, backups and converted files are left intact -
 * deactivation is not uninstallation, and a site that reactivates should find
 * its optimized library exactly as it left it.
 */
class DeactivationHandler {

    /**
     * Run deactivation.
     *
     * @return void
     */
    public function handle() {
        JobRunner::unschedule();

        // A run left active would otherwise keep re-arming its own cron event
        // on the next activation and resume converting unannounced.
        BulkJobRunner::unschedule();

        // Both the batch tick and the recurring scan. The recurring one in
        // particular would otherwise keep firing on a deactivated plugin until
        // something cleared it.
        ScanJobRunner::unschedule();

        do_action('swift_image_optimizer/deactivated');
    }
}
