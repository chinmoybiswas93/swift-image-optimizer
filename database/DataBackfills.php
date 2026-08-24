<?php
/**
 * Post-upgrade data migrations.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Database;

use SwiftImageOptimizer\App\Models\OptimizationLog;
use SwiftImageOptimizer\App\Models\UrlLookup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moves data into a shape a new schema version expects. Separate from the
 * migrators because these read and rewrite rows rather than alter tables.
 */
class DataBackfills {

	/**
	 * Populate the lookup table from url_map values already on record.
	 *
	 * Sites upgrading from schema 2 already have conversions whose old URLs are
	 * only recorded in the log table's url_map column. Without this their 404
	 * fallback would silently stop working the moment it switched to the new
	 * table.
	 *
	 * @return int Attachments processed.
	 */
	public static function backfillUrls() {
		global $wpdb;

		$table = OptimizationLog::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table name cannot be bound; the status value is prepared. One-off schema upgrade.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT attachment_id, url_map FROM {$table} WHERE status = %s AND url_map != ''",
				OptimizationLog::STATUS_OPTIMIZED
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$done = 0;

		foreach ( $rows as $row ) {
			$map = json_decode( (string) $row['url_map'], true );

			if ( ! is_array( $map ) || empty( $map ) ) {
				continue;
			}

			UrlLookup::remember( (int) $row['attachment_id'], $map );

			++$done;
		}

		return $done;
	}
}
