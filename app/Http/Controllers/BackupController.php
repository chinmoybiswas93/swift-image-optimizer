<?php
/**
 * Backup and scratch-file endpoints.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Http\Controllers;

use SwiftImageOptimizer\App\Hooks\Scheduler\JobRunner;
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
     * How many batches one request will run before giving up.
     *
     * At JobRunner::BATCH rows each that is far more than any real library
     * holds. It exists so a row that refuses to clear cannot re-select itself
     * forever inside a single HTTP request, which the previous unbounded
     * do/while could.
     */
    const MAX_BATCHES = 500;

    /**
     * Delete every stored backup immediately.
     *
     * Two passes, because a backup can be on disk without a record pointing at
     * it. The manifest pass clears the rows and the files they name; the sweep
     * then removes whatever is left in the plugin's own backup directory. Only
     * the second one makes the folder actually empty, and this explicit,
     * typed-confirmation action is its only caller - see
     * BackupManager::purge_orphans().
     *
     * @return WP_REST_Response
     */
    public function purge() {
        $before  = BackupManager::disk_usage();
        $removed = 0;
        $batches = 0;

        // The purge is batched, so loop until it stops finding work.
        do {
            $count    = JobRunner::purge_manifests();
            $removed += $count;
            ++$batches;
        } while ($count > 0 && $batches < self::MAX_BATCHES);

        $swept = BackupManager::purge_orphans();

        $after = BackupManager::disk_usage();

        // Measured off the directory rather than summed from the two passes,
        // so it reports what the operator can verify: what the folder used to
        // hold and what it holds now.
        $freed = max(0, $before - $after);

        Logger::start_run('admin');
        Logger::mark(
            'backup',
            sprintf(
                '%d backup(s) cleared, %d unreferenced file(s) removed, %d byte(s) reclaimed.',
                $removed,
                $swept['files'],
                $freed
            )
        );

        return $this->sendSuccess([
            'purged'        => $removed,
            'files_removed' => $swept['files'],
            'bytes_freed'   => $freed,
            'backup_bytes'  => $after,
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
