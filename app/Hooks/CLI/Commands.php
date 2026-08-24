<?php
/**
 * WP-CLI commands.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Hooks\CLI;

use SwiftImageOptimizer\App\Models\OptimizationLog;
use SwiftImageOptimizer\Api\Resource\StatsResource;
use SwiftImageOptimizer\App\Services\AttachmentConverter;
use SwiftImageOptimizer\App\Services\Backup\BackupManager;
use SwiftImageOptimizer\App\Services\Bulk\Runner;
use SwiftImageOptimizer\App\Services\Bulk\Scanner;
use SwiftImageOptimizer\App\Services\Diagnostics\EnvironmentReport;
use SwiftImageOptimizer\App\Services\Logging\Logger;
use SwiftImageOptimizer\App\Services\Rewrite\DatabaseRewriter;
use SwiftImageOptimizer\App\Services\Upload\MimeReconciler;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optimize images from the command line.
 *
 * For a library of any real size this is the recommended path: there is no
 * execution-time limit to work around and no browser tab to keep open.
 */
class Commands {

	/**
	 * Converter instance.
	 *
	 * @var AttachmentConverter
	 */
	private $converter;

	/**
	 * Rewriter instance.
	 *
	 * @var DatabaseRewriter
	 */
	private $rewriter;

	/**
	 * Wire up the converter and rewriter the CLI commands delegate to.
	 *
	 * @param AttachmentConverter $converter Converter instance.
	 * @param DatabaseRewriter    $rewriter  Rewriter instance.
	 */
	public function __construct( AttachmentConverter $converter, DatabaseRewriter $rewriter ) {
		$this->converter = $converter;
		$this->rewriter  = $rewriter;
	}

	/**
	 * Optimize images in the Media Library.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Process every unoptimized image.
	 *
	 * [--id=<id>]
	 * : Process a single attachment.
	 *
	 * [--limit=<number>]
	 * : Stop after this many images.
	 *
	 * [--batch=<number>]
	 * : Images per URL-rewrite pass. Larger is faster but uses more memory.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp swift-image-optimizer optimize --dry-run
	 *     wp swift-image-optimizer optimize --all
	 *     wp swift-image-optimizer optimize --id=123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function optimize( $args, $assoc_args ) {
		unset( $args );

		$batch_size = max( 1, (int) ( isset( $assoc_args['batch'] ) ? $assoc_args['batch'] : 25 ) );
		$limit      = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		if ( isset( $assoc_args['dry-run'] ) ) {
			$runner = new Runner( $this->converter, $this->rewriter );
			$report = $runner->dry_run( 50 );

			WP_CLI::log( sprintf( 'Pending images: %d', $report['pending_total'] ) );
			WP_CLI::log( sprintf( 'Sampled: %d attachments', $report['sampled'] ) );
			WP_CLI::log( sprintf( 'References found in sample: %d across %d rows', $report['replacements'], $report['rows'] ) );
			WP_CLI::log( sprintf( 'Estimated total references to rewrite: ~%d', $report['estimated_total'] ) );

			foreach ( $report['by_table'] as $table => $count ) {
				WP_CLI::log( sprintf( '  %-10s %d', $table, $count ) );
			}

			if ( $report['skipped'] > 0 ) {
				WP_CLI::warning( sprintf( '%d values were skipped as unsafe to rewrite.', $report['skipped'] ) );
			}

			WP_CLI::success( 'Dry run complete. Nothing was written.' );
			return;
		}

		if ( isset( $assoc_args['id'] ) ) {
			$this->optimize_ids( array( (int) $assoc_args['id'] ) );
			return;
		}

		if ( ! isset( $assoc_args['all'] ) ) {
			WP_CLI::error( 'Pass --all to process the library, --id=<id> for one image, or --dry-run to preview.' );
		}

		$summary = Scanner::summary();
		$total   = $limit > 0 ? min( $limit, $summary['pending'] ) : $summary['pending'];

		if ( $total <= 0 ) {
			WP_CLI::success( 'Nothing to do. Every image is already processed.' );
			return;
		}

		WP_CLI::log( sprintf( 'Optimizing %d of %d images.', $total, $summary['total'] ) );

		$progress = WP_CLI\Utils\make_progress_bar( 'Optimizing', $total );

		$cursor    = 0;
		$processed = 0;
		$optimized = 0;
		$skipped   = 0;
		$failed    = 0;
		$saved     = 0;

		while ( $processed < $total ) {
			$want = min( $batch_size, $total - $processed );
			$ids  = Scanner::next_batch( $want, $cursor );

			if ( empty( $ids ) ) {
				break;
			}

			$map = array();

			foreach ( $ids as $id ) {
				$cursor = max( $cursor, (int) $id );
				++$processed;

				$result = $this->converter->convert( $id, true );

				if ( is_wp_error( $result ) ) {
					if ( AttachmentConverter::is_soft_error( $result->get_error_code() ) ) {
						++$skipped;
					} else {
						++$failed;
						WP_CLI::debug( sprintf( '#%d failed: %s', $id, $result->get_error_message() ), 'swift-image-optimizer' );
					}
				} else {
					++$optimized;
					$saved += max( 0, $result['original_size'] - $result['optimized_size'] );
					$map   += $result['url_map'];
				}

				$progress->tick();
			}

			if ( ! empty( $map ) ) {
				$this->rewriter->replace( $map );
			}
		}

		$progress->finish();

		WP_CLI::success(
			sprintf(
				'%d optimized, %d skipped, %d failed. Saved %s.',
				$optimized,
				$skipped,
				$failed,
				size_format( $saved )
			)
		);
	}

	/**
	 * Optimize a specific list of attachment IDs.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return void
	 */
	private function optimize_ids( array $ids ) {
		$map     = array();
		$saved   = 0;
		$done    = 0;
		$skipped = 0;

		foreach ( $ids as $id ) {
			$result = $this->converter->convert( $id, true );

			if ( is_wp_error( $result ) ) {
				if ( AttachmentConverter::is_soft_error( $result->get_error_code() ) ) {
					++$skipped;
					WP_CLI::log( sprintf( '#%d skipped: %s', $id, $result->get_error_message() ) );
					continue;
				}

				WP_CLI::error( sprintf( '#%d failed: %s', $id, $result->get_error_message() ), false );
				continue;
			}

			++$done;
			$saved += max( 0, $result['original_size'] - $result['optimized_size'] );
			$map   += $result['url_map'];
		}

		if ( ! empty( $map ) ) {
			$report = $this->rewriter->replace( $map );
			WP_CLI::log( sprintf( 'Rewrote %d references across %d rows.', $report['replacements'], $report['rows'] ) );
		}

		WP_CLI::success( sprintf( '%d optimized, %d skipped. Saved %s.', $done, $skipped, size_format( $saved ) ) );
	}

	/**
	 * Restore images to their originals.
	 *
	 * ## OPTIONS
	 *
	 * [--id=<id>]
	 * : Restore a single attachment.
	 *
	 * [--all]
	 * : Restore every optimized image that still has a backup.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function restore( $args, $assoc_args ) {
		unset( $args );

		if ( isset( $assoc_args['id'] ) ) {
			$result = $this->converter->restore( (int) $assoc_args['id'] );

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			WP_CLI::success( 'Restored.' );
			return;
		}

		if ( ! isset( $assoc_args['all'] ) ) {
			WP_CLI::error( 'Pass --id=<id> or --all.' );
		}

		global $wpdb;

		$table = OptimizationLog::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name cannot be bound; the status value is prepared. CLI maintenance command.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT attachment_id FROM {$table} WHERE status = %s AND backup_path != ''",
				OptimizationLog::STATUS_OPTIMIZED
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $ids ) ) {
			WP_CLI::success( 'Nothing to restore.' );
			return;
		}

		$progress = WP_CLI\Utils\make_progress_bar( 'Restoring', count( $ids ) );
		$done     = 0;

		foreach ( $ids as $id ) {
			if ( ! is_wp_error( $this->converter->restore( (int) $id ) ) ) {
				++$done;
			}

			$progress->tick();
		}

		$progress->finish();

		WP_CLI::success( sprintf( '%d images restored.', $done ) );
	}

	/**
	 * Show optimization statistics.
	 *
	 * @return void
	 */
	public function stats() {
		$stats   = StatsResource::get( true );
		$summary = Scanner::summary();

		WP_CLI::log( sprintf( 'Images in library : %d', $summary['total'] ) );
		WP_CLI::log( sprintf( 'Processed         : %d', $summary['processed'] ) );
		WP_CLI::log( sprintf( 'Pending           : %d', $summary['pending'] ) );
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Optimized         : %d', $stats['optimized'] ) );
		WP_CLI::log( sprintf( 'Skipped           : %d', $stats['skipped'] ) );
		WP_CLI::log( sprintf( 'Failed            : %d', $stats['failed'] ) );
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Original size     : %s', size_format( $stats['original_bytes'] ) ) );
		WP_CLI::log( sprintf( 'Optimized size    : %s', size_format( $stats['optimized_bytes'] ) ) );
		WP_CLI::log( sprintf( 'Saved             : %s (%s%%)', size_format( $stats['saved_bytes'] ), $stats['saved_percent'] ) );
		WP_CLI::log( sprintf( 'Backups on disk   : %s', size_format( BackupManager::disk_usage() ) ) );
	}

	/**
	 * Print the server environment report.
	 *
	 * The same information as the Troubleshoot screen, for hosts where the
	 * admin is unreachable or the person debugging only has SSH.
	 *
	 * ## EXAMPLES
	 *
	 *     wp swift-image-optimizer diagnostics
	 *
	 * @return void
	 */
	public function diagnostics() {
		WP_CLI::log( EnvironmentReport::as_text() );

		$retryable = Scanner::count_retryable();

		if ( $retryable > 0 ) {
			WP_CLI::log( '' );
			WP_CLI::warning( sprintf( '%d image(s) are worth trying again. Run: wp swift-image-optimizer requeue', $retryable ) );
		}
	}

	/**
	 * Read, or clear, the diagnostic log.
	 *
	 * ## OPTIONS
	 *
	 * [--lines=<number>]
	 * : How many of the most recent lines to print.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--reset]
	 * : Delete the log file instead of printing it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp swift-image-optimizer logs --lines=500
	 *     wp swift-image-optimizer logs --reset
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function logs( $args, $assoc_args ) {
		unset( $args );

		if ( ! empty( $assoc_args['reset'] ) ) {
			Logger::clear();
			Logger::start_run( 'cli' );
			Logger::mark( 'logging', 'Log cleared from WP-CLI.' );

			WP_CLI::success( 'Log cleared.' );

			return;
		}

		$lines = max( 1, (int) ( isset( $assoc_args['lines'] ) ? $assoc_args['lines'] : 100 ) );
		$log   = Logger::tail( $lines );

		if ( empty( $log['lines'] ) ) {
			WP_CLI::log( 'The log is empty.' );

			if ( ! $log['enabled'] ) {
				WP_CLI::log( 'Only failures are being recorded. Turn on logging in Media > Bulk Optimize > Troubleshoot for the full detail.' );
			}

			return;
		}

		foreach ( $log['lines'] as $line ) {
			WP_CLI::log( $line );
		}
	}

	/**
	 * Return skipped and failed images to the queue.
	 *
	 * Only outcomes caused by the environment are cleared. Images that simply
	 * cannot be improved are left as they are.
	 *
	 * ## EXAMPLES
	 *
	 *     wp swift-image-optimizer requeue
	 *
	 * @return void
	 */
	public function requeue() {
		$removed = Scanner::requeue();

		Logger::start_run( 'cli' );
		Logger::mark( 'requeue', $removed . ' row(s) cleared for another attempt.' );

		WP_CLI::success( sprintf( '%d image(s) will be tried again on the next run.', $removed ) );
	}

	/**
	 * Rebuild backup manifests for originals still sitting on disk.
	 *
	 * The mirror image of `rescan`. That one clears records whose file is
	 * gone; this one restores records for files that are still there. A row
	 * loses its backup pointer when a purge cleared it, when the manifest
	 * could not be encoded, or when a conversion died between copying the
	 * originals and writing the row - and in every one of those cases the
	 * originals are still on disk with nothing able to reach them.
	 *
	 * Writes pointers only. Nothing is deleted, and nothing is overwritten:
	 * rows that already name a backup are not examined. Run it *before*
	 * "Delete all backups now", which removes exactly what this recovers.
	 *
	 * ## EXAMPLES
	 *
	 *     wp swift-image-optimizer repair-backups
	 *
	 * @return void
	 */
	public function repair_backups() {
		$repaired = 0;
		$skipped  = 0;
		$after    = 0;

		do {
			$result    = BackupManager::reconcile( $after );
			$repaired += $result['repaired'];
			$skipped  += $result['skipped'];
			$moved     = $result['last_id'] > $after;
			$after     = $result['last_id'];
		} while ( $moved );

		Logger::start_run( 'cli' );
		Logger::mark(
			'backup',
			sprintf( '%d backup manifest(s) rebuilt, %d row(s) had no files left to find.', $repaired, $skipped )
		);

		if ( 0 === $repaired ) {
			WP_CLI::success( sprintf( 'No backups to recover. %d row(s) claim no backup and have no files on disk.', $skipped ) );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Rebuilt %d backup manifest(s); %d row(s) had no files left to find. Those images can be restored again.',
				$repaired,
				$skipped
			)
		);
	}

	/**
	 * Re-point attachments whose post_mime_type went stale.
	 *
	 * A re-fed upload (a sideload, or a re-submission of a leftover
	 * derivative file) that slipped through before Interceptor's guard
	 * existed leaves post_mime_type describing a file that is no longer
	 * there, while the file on disk is genuinely WebP already. This writes
	 * corrections only - see MimeReconciler::reconcile(). Run before
	 * `repair-backups`, since a repaired row is what makes it visible to
	 * that pass in the first place.
	 *
	 * ## EXAMPLES
	 *
	 *     wp swift-image-optimizer repair-mime
	 *
	 * @return void
	 */
	public function repair_mime() {
		$repaired_mime = 0;
		$repaired_log  = 0;
		$skipped       = 0;
		$after         = 0;

		do {
			$result         = MimeReconciler::reconcile( $after );
			$repaired_mime += $result['repaired_mime'];
			$repaired_log  += $result['repaired_log'];
			$skipped       += $result['skipped'];
			$moved          = $result['last_id'] > $after;
			$after          = $result['last_id'];
		} while ( $moved );

		Logger::start_run( 'cli' );
		Logger::mark(
			'upload',
			sprintf( '%d mime type(s) corrected, %d log row(s) updated, %d row(s) skipped.', $repaired_mime, $repaired_log, $skipped )
		);

		if ( 0 === $repaired_mime ) {
			WP_CLI::success( sprintf( 'No stale mime types found. %d row(s) skipped.', $skipped ) );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Corrected %d mime type(s); %d log row(s) updated; %d row(s) skipped. Run repair-backups next to recover any lost manifests.',
				$repaired_mime,
				$repaired_log,
				$skipped
			)
		);
	}

	/**
	 * Reconcile recorded results against the files on disk.
	 *
	 * Use after restoring a site, when the database and the uploads directory
	 * may have come from different points in time. Records claiming an image
	 * was optimized are checked against the file they name; those whose file is
	 * missing are cleared, which returns the image to the queue. Records whose
	 * file is intact are left untouched.
	 *
	 * ## EXAMPLES
	 *
	 *     wp swift-image-optimizer rescan
	 *
	 * @return void
	 */
	public function rescan() {
		$result = Scanner::rescan();

		Logger::start_run( 'cli' );
		Logger::mark(
			'rescan',
			sprintf(
				'Checked %d optimized record(s); cleared %d whose file was missing.',
				$result['checked'],
				$result['cleared']
			)
		);

		if ( 0 === $result['cleared'] ) {
			WP_CLI::success( sprintf( 'Checked %d record(s). Every optimized file is present.', $result['checked'] ) );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Checked %d record(s) and cleared %d whose file was missing. Those images are pending again.',
				$result['checked'],
				$result['cleared']
			)
		);
	}
}
