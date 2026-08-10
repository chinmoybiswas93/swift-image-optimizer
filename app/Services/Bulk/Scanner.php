<?php
/**
 * Finds attachments that still need optimizing.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Services\Bulk;

use SwiftImageOptimizer\App\Models\OptimizationLog;
use SwiftImageOptimizer\Api\StoreSettings;
use SwiftImageOptimizer\App\Services\AttachmentConverter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queries the Media Library for outstanding work.
 *
 * Uses a LEFT JOIN against the log table rather than a NOT IN subquery or a
 * pre-built list of IDs, so the query stays fast and the bulk run stays
 * resumable across sessions on libraries of any size.
 */
class Scanner {

	/**
	 * Mime types eligible for conversion.
	 *
	 * @return string[]
	 */
	public static function mime_types() {
		$types = array( 'image/jpeg' );

		if ( StoreSettings::get( 'convert_png' ) ) {
			$types[] = 'image/png';
		}

		return $types;
	}

	/**
	 * Count how many attachments are still to do.
	 *
	 * @return int
	 */
	public static function count_pending() {
		global $wpdb;

		$log   = OptimizationLog::table();
		$mimes = self::mime_types();

		$placeholders = implode( ', ', array_fill( 0, count( $mimes ), '%s' ) );

		$params = array_merge(
			$mimes,
			array( OptimizationLog::STATUS_OPTIMIZED, OptimizationLog::STATUS_SKIPPED, OptimizationLog::STATUS_FAILED )
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table names are internal identifiers and cannot be bound; $placeholders is a generated %s list and every value goes through prepare() in $params.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->posts} p
				LEFT JOIN {$log} l ON l.attachment_id = p.ID
				WHERE p.post_type = 'attachment'
					AND p.post_mime_type IN ( {$placeholders} )
					AND ( l.attachment_id IS NULL OR l.status NOT IN ( %s, %s, %s ) )",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * Fetch the next batch of attachment IDs to process.
	 *
	 * @param int $limit  How many to return.
	 * @param int $after  Only return IDs greater than this, for cursor paging.
	 * @return int[]
	 */
	public static function next_batch( $limit = 5, $after = 0 ) {
		global $wpdb;

		$log   = OptimizationLog::table();
		$mimes = self::mime_types();

		$placeholders = implode( ', ', array_fill( 0, count( $mimes ), '%s' ) );

		$params = array_merge(
			$mimes,
			array(
				OptimizationLog::STATUS_OPTIMIZED,
				OptimizationLog::STATUS_SKIPPED,
				OptimizationLog::STATUS_FAILED,
				(int) $after,
				(int) $limit,
			)
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table names are internal identifiers and cannot be bound; $placeholders is a generated %s list and every value goes through prepare() in $params.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				LEFT JOIN {$log} l ON l.attachment_id = p.ID
				WHERE p.post_type = 'attachment'
					AND p.post_mime_type IN ( {$placeholders} )
					AND ( l.attachment_id IS NULL OR l.status NOT IN ( %s, %s, %s ) )
					AND p.ID > %d
				ORDER BY p.ID ASC
				LIMIT %d",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map( 'intval', $ids );
	}

	/**
	 * How many recorded outcomes are worth attempting again.
	 *
	 * Terminal rows are what keep an image out of the pending queue. Most of
	 * them should stay that way, but a skip caused by the environment - no
	 * memory, no disk, no engine - stops being true the moment the host
	 * changes, and there would otherwise be no way back into the queue.
	 *
	 * @return int
	 */
	public static function count_retryable() {
		global $wpdb;

		$table        = OptimizationLog::table();
		$permanent    = AttachmentConverter::PERMANENT_SKIPS;
		$placeholders = implode( ', ', array_fill( 0, count( $permanent ), '%s' ) );

		$params = array_merge(
			array( OptimizationLog::STATUS_SKIPPED, OptimizationLog::STATUS_FAILED ),
			$permanent
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Plugin-owned table name cannot be bound; $placeholders is a generated %s list and every value goes through prepare() in $params.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE status IN ( %s, %s )
					AND ( reason IS NULL OR reason NOT IN ( {$placeholders} ) )",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * Delete every retryable row, returning those images to the queue.
	 *
	 * @return int Rows removed.
	 */
	public static function requeue() {
		global $wpdb;

		$table        = OptimizationLog::table();
		$permanent    = AttachmentConverter::PERMANENT_SKIPS;
		$placeholders = implode( ', ', array_fill( 0, count( $permanent ), '%s' ) );

		$params = array_merge(
			array( OptimizationLog::STATUS_SKIPPED, OptimizationLog::STATUS_FAILED ),
			$permanent
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Plugin-owned table name cannot be bound; $placeholders is a generated %s list and every value goes through prepare() in $params.
		$removed = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				WHERE status IN ( %s, %s )
					AND ( reason IS NULL OR reason NOT IN ( {$placeholders} ) )",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		OptimizationLog::flushStatsCache();

		return max( 0, (int) $removed );
	}

	/**
	 * Drop optimized rows whose file is no longer on disk.
	 *
	 * The dashboard's pending count is a single join, and file existence cannot
	 * be expressed in SQL - so a row that outlived its file keeps counting as
	 * processed forever. Checking the disk per attachment on every dashboard
	 * load would mean a stat call per image on exactly the large libraries bulk
	 * exists to serve, so reconciliation is an explicit action instead.
	 *
	 * Rows whose file is intact are never touched, so this cannot discard a
	 * real result. Deleting rather than restatusing follows requeue() and keeps
	 * invariant 9 intact: status is not repurposed to mean availability.
	 *
	 * @param int $limit Maximum rows to inspect, 0 for all.
	 * @return array{checked:int, cleared:int}
	 */
	public static function rescan( $limit = 0 ) {
		global $wpdb;

		$table = OptimizationLog::table();
		$limit = max( 0, (int) $limit );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name cannot be bound; the status value is prepared.
		if ( $limit > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT attachment_id, status, optimized_file FROM {$table} WHERE status = %s ORDER BY attachment_id ASC LIMIT %d",
					OptimizationLog::STATUS_OPTIMIZED,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT attachment_id, status, optimized_file FROM {$table} WHERE status = %s ORDER BY attachment_id ASC",
					OptimizationLog::STATUS_OPTIMIZED
				),
				ARRAY_A
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$checked = 0;
		$cleared = 0;

		foreach ( (array) $rows as $row ) {
			++$checked;

			$attachment_id = (int) $row['attachment_id'];

			if ( AttachmentConverter::optimized_output_exists( $attachment_id, $row ) ) {
				continue;
			}

			OptimizationLog::delete( $attachment_id );
			++$cleared;
		}

		if ( $cleared > 0 ) {
			OptimizationLog::flushStatsCache();
		}

		return array(
			'checked' => $checked,
			'cleared' => $cleared,
		);
	}

	/**
	 * Totals for the dashboard.
	 *
	 * @return array {
	 *     @type int $total     Every convertible image in the library.
	 *     @type int $pending   Still to do.
	 *     @type int $processed Already handled.
	 * }
	 */
	public static function summary() {
		global $wpdb;

		$mimes        = self::mime_types();
		$placeholders = implode( ', ', array_fill( 0, count( $mimes ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name is a core identifier and cannot be bound; $placeholders is a generated %s list and every mime value goes through prepare().
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				WHERE post_type = 'attachment' AND post_mime_type IN ( {$placeholders} )",
				$mimes
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$pending = self::count_pending();

		return array(
			'total'     => $total,
			'pending'   => $pending,
			'processed' => max( 0, $total - $pending ),
		);
	}
}
