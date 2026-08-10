<?php
/**
 * Diagnostics endpoint.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Http\Controllers;

use SwiftImageOptimizer\App\Services\Bulk\Scanner;
use SwiftImageOptimizer\App\Services\Diagnostics\EnvironmentReport;
use SwiftImageOptimizer\App\Services\Logging\Logger;
use SwiftImageOptimizer\App\Services\Optimizer;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server and plugin environment report backing the Troubleshoot screen.
 */
class DiagnosticsController extends Controller
{
    /**
     * The full report.
     *
     * @return WP_REST_Response
     */
    public function index()
    {
        $report = EnvironmentReport::get();

        $report['text']      = EnvironmentReport::as_text();
        $report['retryable'] = Scanner::count_retryable();
        $report['tempFiles'] = Optimizer::count_temp_files();
        $report['log']       = [
            'enabled' => Logger::is_enabled(),
            'bytes'   => Logger::size(),
        ];

        return $this->sendSuccess($report);
    }
}
