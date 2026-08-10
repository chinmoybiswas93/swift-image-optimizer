<?php
/**
 * Finds attachments that still need optimizing.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Services\Bulk;

use SwiftImageOptimizer\App\Models\OptimizationLog;
use SwiftImageOptimizer\App\Repositories\SettingsRepository;
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

		if ( SettingsRepository::get( 'convert_png' ) ) {
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are internal; values are prepared.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are internal; values are prepared.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; identifiers are internal, every value is prepared.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE status IN ( %s, %s )
					AND ( reason IS NULL OR reason NOT IN ( {$placeholders} ) )",
				$params
			)
		);
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; identifiers are internal, every value is prepared.
		$removed = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				WHERE status IN ( %s, %s )
					AND ( reason IS NULL OR reason NOT IN ( {$placeholders} ) )",
				$params
			)
		);

		OptimizationLog::flushStatsCache();

		return max( 0, (int) $removed );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are internal; values are prepared.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				WHERE post_type = 'attachment' AND post_mime_type IN ( {$placeholders} )",
				$mimes
			)
		);

		$pending = self::count_pending();

		return array(
			'total'     => $total,
			'pending'   => $pending,
			'processed' => max( 0, $total - $pending ),
		);
	}
}
