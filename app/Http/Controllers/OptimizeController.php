<?php
/**
 * Conversion endpoints.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Http\Controllers;

use SwiftImageOptimizer\App\Services\AttachmentConverter;
use SwiftImageOptimizer\App\Services\Backup\BackupManager;
use SwiftImageOptimizer\App\Services\Bulk\Runner;
use SwiftImageOptimizer\App\Services\Bulk\Scanner;
use SwiftImageOptimizer\App\Services\Engine\EngineFactory;
use SwiftImageOptimizer\App\Services\Logging\Logger;
use SwiftImageOptimizer\App\Services\Upload\MimeReconciler;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Optimizing and restoring individual attachments, plus the library scan that
 * tells the admin screen how much work is outstanding.
 */
class OptimizeController extends Controller {

    /**
     * How many batches one request will run before giving up.
     *
     * Same purpose as BackupController::MAX_BATCHES: reconcileMime()'s query
     * filters on the very column it rewrites, so this bounds a row that
     * cannot be resolved from re-selecting itself forever.
     */
    const MAX_BATCHES = 500;

    /**
     * Converter instance.
     *
     * @var AttachmentConverter
     */
    private $converter;

    /**
     * Bulk runner instance.
     *
     * @var Runner
     */
    private $runner;

    /**
     * Wire the controller to the converter and the bulk runner.
     *
     * @param AttachmentConverter $converter Converter instance.
     * @param Runner              $runner    Runner instance.
     */
    public function __construct( AttachmentConverter $converter, Runner $runner ) {
        $this->converter = $converter;
        $this->runner    = $runner;
    }

    /**
     * Library summary plus the engine picture.
     *
     * @return WP_REST_Response
     */
    public function scan() {
        $summary = Scanner::summary();

        $engine = EngineFactory::get();

        $summary['engine']       = $engine ? $engine->name() : '';
        $summary['engines']      = EngineFactory::availability();
        $summary['backup_bytes'] = BackupManager::disk_usage();

        return $this->sendSuccess($summary);
    }

    /**
     * Report what a bulk run would change, without writing anything.
     *
     * @return WP_REST_Response
     */
    public function dryRun() {
        return $this->sendSuccess($this->runner->dry_run());
    }

    /**
     * Optimize one or more attachments.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function optimize( WP_REST_Request $request ) {
        $results = [];

        foreach ( (array) $request->get_param('ids') as $id) {
            $result = $this->converter->convert($id);

            $results[] = is_wp_error($result)
                ? [
                    'id'      => $id,
                    'ok'      => false,
                    'code'    => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ]
                : [
                    'id'      => $id,
                    'ok'      => true,
                    'saved'   => max(0, $result['original_size'] - $result['optimized_size']),
                    'percent' => $result['original_size'] > 0
                        ? round(( 1 - ( $result['optimized_size'] / $result['original_size'] ) ) * 100, 1)
                        : 0,
                ];
        }

        return $this->sendSuccess([ 'results' => $results ]);
    }

    /**
     * Restore one or more attachments to their originals.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function restore( WP_REST_Request $request ) {
        $results = [];

        foreach ( (array) $request->get_param('ids') as $id) {
            $result = $this->converter->restore($id);

            $results[] = is_wp_error($result)
                ? [
                    'id'      => $id,
                    'ok'      => false,
                    'code'    => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ]
                : [
                    'id'        => $id,
                    'ok'        => true,
                    // Non-zero means some references could not be reversed and
                    // still point at the full-size image. The restore itself
                    // succeeded; saying so plainly beats a silent half-result.
                    'ambiguous' => isset($result['ambiguous']) ? (int) $result['ambiguous'] : 0,
                ];
        }

        return $this->sendSuccess([ 'results' => $results ]);
    }

    /**
     * Clear rows that are worth trying again.
     *
     * Deleting the row is what returns the attachment to the pending queue -
     * Scanner decides what is outstanding by the absence of a terminal row.
     *
     * @return WP_REST_Response
     */
    public function requeue() {
        $removed = Scanner::requeue();

        Logger::start_run('admin');
        Logger::mark('requeue', $removed . ' row(s) cleared for another attempt.');

        return $this->sendSuccess([
            'requeued' => $removed,
            'summary'  => Scanner::summary(),
        ]);
    }

    /**
     * Reconcile recorded results against the files actually on disk.
     *
     * For a library whose database and uploads directory were restored from
     * different points in time: rows claiming `optimized` for files that are
     * no longer there are dropped, which returns those images to the queue.
     * Rows whose file is intact are left alone.
     *
     * @return WP_REST_Response
     */
    public function rescan() {
        $result = Scanner::rescan();

        Logger::start_run('admin');
        Logger::mark(
            'rescan',
            sprintf(
                'Checked %d optimized record(s); cleared %d whose file was missing.',
                $result['checked'],
                $result['cleared']
            )
        );

        return $this->sendSuccess([
            'checked' => $result['checked'],
            'cleared' => $result['cleared'],
            'summary' => Scanner::summary(),
        ]);
    }

    /**
     * Re-point attachments whose post_mime_type went stale.
     *
     * The repair half of the guard Interceptor now applies going forward:
     * a re-fed upload that slipped through before the guard existed leaves
     * post_mime_type describing a file that is no longer there. Writes
     * corrections only - see MimeReconciler::reconcile(). Run before
     * "Repair backups", since a repaired row is what makes it visible to
     * that pass in the first place.
     *
     * @return WP_REST_Response
     */
    public function reconcileMime() {
        $repaired_mime = 0;
        $repaired_log  = 0;
        $skipped       = 0;
        $after         = 0;
        $batches       = 0;

        do {
            $result         = MimeReconciler::reconcile($after);
            $repaired_mime += $result['repaired_mime'];
            $repaired_log  += $result['repaired_log'];
            $skipped       += $result['skipped'];
            $moved          = $result['last_id'] > $after;
            $after          = $result['last_id'];
            ++$batches;
        } while ($moved && $batches < self::MAX_BATCHES);

        Logger::start_run('admin');
        Logger::mark(
            'upload',
            sprintf('%d mime type(s) corrected, %d log row(s) updated, %d row(s) skipped.', $repaired_mime, $repaired_log, $skipped)
        );

        return $this->sendSuccess([
            'repaired_mime' => $repaired_mime,
            'repaired_log'  => $repaired_log,
            'skipped'       => $skipped,
        ]);
    }
}
