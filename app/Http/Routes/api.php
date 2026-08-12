<?php
/**
 * Admin API routes, under swift-image-optimizer/v1.
 *
 * The URLs here are load-bearing: the admin bundle calls them by path, so a
 * rename is a breaking change for anyone running a cached build.
 *
 * @package SwiftImageOptimizer
 */

use SwiftImageOptimizer\Framework\Router;
use SwiftImageOptimizer\App\Http\Controllers\BackupController;
use SwiftImageOptimizer\App\Http\Controllers\BulkController;
use SwiftImageOptimizer\App\Http\Controllers\DiagnosticsController;
use SwiftImageOptimizer\App\Http\Controllers\LogController;
use SwiftImageOptimizer\App\Http\Controllers\OptimizeController;
use SwiftImageOptimizer\App\Http\Controllers\ScanController;
use SwiftImageOptimizer\App\Http\Controllers\StatsController;
use SwiftImageOptimizer\App\Http\Requests\BulkStartRequest;
use SwiftImageOptimizer\App\Http\Requests\LogQueryRequest;
use SwiftImageOptimizer\App\Http\Requests\OptimizeRequest;
use SwiftImageOptimizer\App\Http\Requests\ScanStartRequest;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Router instance supplied by the including route loader.
 *
 * @var Router $router
 */

/*
 * Per-attachment actions. An editor may run these; they only ever touch the
 * attachments named in the request.
 */
$router->withPolicy('MediaPolicy')->group(function ( Router $router ) {
    $router->post('optimize', [ OptimizeController::class, 'optimize' ])->args(OptimizeRequest::args());
    $router->post('restore', [ OptimizeController::class, 'restore' ])->args(OptimizeRequest::args());
});

/*
 * Everything else changes plugin-wide state or exposes server internals, so it
 * is administrator-only.
 */
$router->withPolicy('AdminPolicy')->group(function ( Router $router ) {

    $router->get('scan', [ OptimizeController::class, 'scan' ]);
    $router->get('stats', [ StatsController::class, 'index' ]);
    $router->post('dry-run', [ OptimizeController::class, 'dryRun' ]);
    $router->post('requeue', [ OptimizeController::class, 'requeue' ]);
    $router->post('rescan', [ OptimizeController::class, 'rescan' ]);
    $router->post('cleanup', [ BackupController::class, 'cleanup' ]);
    $router->get('diagnostics', [ DiagnosticsController::class, 'index' ]);

    // Previously one route capturing {action} from the path. Four routes now,
    // same URLs. status answers to GET as well as POST: reading run state is a
    // GET semantically, but the shipped admin bundle POSTs to it, and a cached
    // build must not start 404ing the moment this deploys.
    $router->prefix('bulk')->group(function ( Router $router ) {
        $router->post('start', [ BulkController::class, 'start' ])->args(BulkStartRequest::args());
        $router->match([ 'GET', 'POST' ], 'status', [ BulkController::class, 'status' ]);
        $router->post('batch', [ BulkController::class, 'batch' ]);
        $router->post('cancel', [ BulkController::class, 'cancel' ]);

        // Added, not renamed. `start` still begins a bare optimize run for the
        // CLI and for any cached build; `run` is the dashboard's button, which
        // measures the library on the way in and again on the way out.
        $router->post('run', [ BulkController::class, 'run' ]);
        $router->match([ 'GET', 'POST' ], 'phase', [ BulkController::class, 'phase' ]);
    });

    /*
     * The library scan. Under its own prefix because `scan` at the top level is
     * already taken by the old synchronous summary, which stays until no
     * cached build calls it.
     */
    $router->prefix('library')->group(function ( Router $router ) {
        $router->post('scan', [ ScanController::class, 'start' ])->args(ScanStartRequest::args());
        $router->match([ 'GET', 'POST' ], 'scan/status', [ ScanController::class, 'status' ]);
        $router->post('scan/batch', [ ScanController::class, 'batch' ]);
        $router->post('scan/cancel', [ ScanController::class, 'cancel' ]);
        $router->get('snapshot', [ ScanController::class, 'snapshot' ]);
    });

    $router->prefix('backups')->group(function ( Router $router ) {
        $router->post('purge', [ BackupController::class, 'purge' ]);
    });

    $router->prefix('logs')->group(function ( Router $router ) {
        $router->get('/', [ LogController::class, 'index' ])->args(LogQueryRequest::args());
        $router->get('download', [ LogController::class, 'download' ]);
        $router->post('reset', [ LogController::class, 'reset' ]);
    });
});
