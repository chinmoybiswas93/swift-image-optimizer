<?php
/**
 * Plugin activation.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Hooks\Handlers;

use SwiftImageOptimizer\Api\StoreSettings;
use SwiftImageOptimizer\App\Hooks\Scheduler\JobRunner;
use SwiftImageOptimizer\App\Hooks\Scheduler\ScanJobRunner;
use SwiftImageOptimizer\App\Services\Bulk\ScanRunner;
use SwiftImageOptimizer\Database\DBMigrator;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Creates the schema and schedules the retention job.
 *
 * Activation is the only place the schema is created eagerly; on every other
 * request the migrator runs on init and no-ops when the version matches.
 */
class ActivationHandler {

    /**
     * Run activation.
     *
     * @param bool $networkWide Whether the plugin was network-activated.
     * @return void
     */
    public function handle( $networkWide = false ) {
        if ($networkWide && is_multisite()) {
            $this->handleNetwork();

            return;
        }

        $this->activateSite();
    }

    /**
     * Activate on every site in the network.
     *
     * @return void
     */
    private function handleNetwork() {
        $sites = get_sites([ 'fields' => 'ids', 'number' => 0 ]);

        foreach ($sites as $siteId) {
            switch_to_blog($siteId);

            $this->activateSite();

            restore_current_blog();
        }
    }

    /**
     * Activate on the current site.
     *
     * @return void
     */
    private function activateSite() {
        DBMigrator::migrateUp();

        JobRunner::schedule();
        ScanJobRunner::schedule(StoreSettings::get('scan_frequency'));

        /*
         * Kick a first scan so the dashboard has real figures the first time it
         * is opened. This only writes the progress option and queues a cron
         * tick - the work itself happens in batches afterwards, so activation
         * costs the same on a library of ten images or ten thousand.
         *
         * Constructed directly rather than resolved from the container: the
         * activation hook can fire before bindings are registered, and this
         * class takes no dependencies.
         */
        ( new ScanRunner() )->start('activation');

        do_action('swift_image_optimizer/activated');
    }
}
