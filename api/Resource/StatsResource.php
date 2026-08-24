<?php
/**
 * Aggregate savings figures.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Api\Resource;

use SwiftImageOptimizer\App\Models\OptimizationLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the log table to produce the dashboard numbers.
 */
class StatsResource extends BaseResourceApi {

	/**
	 * Transient caching the aggregate.
	 *
	 * Defined by the model that invalidates it, so the writer and the reader
	 * cannot drift onto different keys.
	 */
	const CACHE_KEY = OptimizationLog::STATS_CACHE_KEY;

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected static $cacheKey = self::CACHE_KEY;

	/**
	 * Compute the aggregate savings.
	 *
	 * @param bool $force Skip the cache.
	 * @return array {
	 *     @type int   $optimized      Number of images optimized.
	 *     @type int   $skipped        Number of images skipped.
	 *     @type int   $failed         Number of failures.
	 *     @type int   $original_bytes Total original size.
	 *     @type int   $optimized_bytes Total optimized size.
	 *     @type int   $saved_bytes    Bytes saved.
	 *     @type float $saved_percent  Percentage saved.
	 * }
	 */
	public static function get( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		global $wpdb;

		$table = OptimizationLog::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table name cannot be bound; every status value is prepared. Result is cached in a transient below.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM( CASE WHEN status = %s THEN 1 ELSE 0 END ) AS optimized,
					SUM( CASE WHEN status = %s THEN 1 ELSE 0 END ) AS skipped,
					SUM( CASE WHEN status = %s THEN 1 ELSE 0 END ) AS failed,
					SUM( CASE WHEN status = %s THEN original_size ELSE 0 END ) AS original_bytes,
					SUM( CASE WHEN status = %s THEN optimized_size ELSE 0 END ) AS optimized_bytes
				FROM {$table}",
				OptimizationLog::STATUS_OPTIMIZED,
				OptimizationLog::STATUS_SKIPPED,
				OptimizationLog::STATUS_FAILED,
				OptimizationLog::STATUS_OPTIMIZED,
				OptimizationLog::STATUS_OPTIMIZED
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$original  = isset( $row['original_bytes'] ) ? (int) $row['original_bytes'] : 0;
		$optimized = isset( $row['optimized_bytes'] ) ? (int) $row['optimized_bytes'] : 0;
		$saved     = max( 0, $original - $optimized );

		$stats = array(
			'optimized'       => isset( $row['optimized'] ) ? (int) $row['optimized'] : 0,
			'skipped'         => isset( $row['skipped'] ) ? (int) $row['skipped'] : 0,
			'failed'          => isset( $row['failed'] ) ? (int) $row['failed'] : 0,
			'original_bytes'  => $original,
			'optimized_bytes' => $optimized,
			'saved_bytes'     => $saved,
			'saved_percent'   => $original > 0 ? round( ( $saved / $original ) * 100, 1 ) : 0.0,
		);

		set_transient( self::CACHE_KEY, $stats, HOUR_IN_SECONDS );

		return $stats;
	}
}
