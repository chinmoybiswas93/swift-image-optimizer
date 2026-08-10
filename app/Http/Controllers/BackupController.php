<?php
/**
 * Backup and scratch-file endpoints.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Http\Controllers;

use SwiftImageOptimizer\App\Hooks\Scheduler\JobRunner;
use SwiftImageOptimizer\App\Models\OptimizationLog;
use SwiftImageOptimizer\App\Services\Backup\BackupManager;
use SwiftImageOptimizer\App\Services\Logging\Logger;
use SwiftImageOptimizer\App\Services\Optimizer;
use WP_REST_Response;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Reclaiming disk space: stored backups and abandoned temp files.
 */
class BackupController extends Controller {

    /**
     * Delete every stored backup immediately.
     *
     * @return WP_REST_Response
     */
    public function purge() {
        global $wpdb;

        $table = OptimizationLog::table();

        // Expire everything, then let the existing purge routine do the work.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; administrative action.
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET backup_expires = %d WHERE backup_expires > 0", 1));

        $removed = 0;

        // The purge is batched, so loop until it stops finding work.
        do {
            $count    = JobRunner::purge();
            $removed += $count;
        } while ($count > 0);

        return $this->sendSuccess([
            'purged'       => $removed,
            'backup_bytes' => BackupManager::disk_usage(),
        ]);
    }

    /**
     * Delete scratch files left behind by interrupted conversions.
     *
     * @return WP_REST_Response
     */
    public function cleanup() {
        $removed = Optimizer::sweep_temp_files();

        Logger::start_run('admin');
        Logger::mark('cleanup', $removed . ' abandoned temporary file(s) removed.');

        return $this->sendSuccess([
            'removed'   => $removed,
            'remaining' => Optimizer::count_temp_files(),
        ]);
    }
}
