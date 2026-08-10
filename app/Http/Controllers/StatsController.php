<?php
/**
 * Statistics endpoint.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Http\Controllers;

use SwiftImageOptimizer\App\Repositories\StatsRepository;
use SwiftImageOptimizer\App\Services\Backup\BackupManager;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Aggregate savings across the library.
 */
class StatsController extends Controller
{
    /**
     * The stats aggregate.
     *
     * Forces a fresh read rather than serving the transient: the dashboard
     * asks for this right after a run, when a stale total is the one thing
     * the user will notice.
     *
     * @return WP_REST_Response
     */
    public function index()
    {
        $stats                 = StatsRepository::get(true);
        $stats['backup_bytes'] = BackupManager::disk_usage();

        return $this->sendSuccess($stats);
    }
}
