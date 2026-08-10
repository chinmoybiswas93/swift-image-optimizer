<?php
/**
 * Plugin deactivation.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Hooks\Handlers;

use SwiftImageOptimizer\App\Hooks\Scheduler\JobRunner;

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

        do_action('swift_image_optimizer/deactivated');
    }
}
