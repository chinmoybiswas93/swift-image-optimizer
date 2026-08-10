<?php
/**
 * Scheduled purge of expired backups.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Hooks\Scheduler;

use SwiftImageOptimizer\App\Models\OptimizationLog;
use SwiftImageOptimizer\App\Services\Backup\BackupManager;
use SwiftImageOptimizer\App\Services\Logging\Logger;
use SwiftImageOptimizer\App\Services\Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes backups once their retention period has elapsed.
 */
class JobRunner {

	/**
	 * Cron hook name.
	 */
	const HOOK = 'swift_image_optimizer_purge_backups';

	/**
	 * How many rows to process per run.
	 */
	const BATCH = 200;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK, array( __CLASS__, 'purge' ) );

		// Self-heal if the event was lost, for example after a migration.
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	/**
	 * Schedule the daily event.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Clear the scheduled event.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );

		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Delete every backup whose retention period has passed.
	 *
	 * @return int Number of backups removed.
	 */
	public static function purge() {
		global $wpdb;

		// Scratch files from conversions that were interrupted. An hour is
		// comfortably longer than any single encode, so anything older than
		// that belongs to a process that is no longer running.
		$swept = Optimizer::sweep_temp_files();

		if ( $swept > 0 ) {
			Logger::start_run( 'cron' );
			Logger::info( 'cleanup', 'Removed ' . $swept . ' abandoned temporary file(s).' );
		}

		$table = OptimizationLog::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name cannot be bound; every value is prepared. Cron context, caching not applicable.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT attachment_id, backup_path, url_map
				FROM {$table}
				WHERE backup_expires > 0
					AND backup_expires < %d
					AND status = %s
				LIMIT %d",
				time(),
				OptimizationLog::STATUS_OPTIMIZED,
				self::BATCH
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			return 0;
		}

		$removed = 0;

		foreach ( $rows as $row ) {
			$backup = json_decode( (string) $row['backup_path'], true );

			if ( is_array( $backup ) && ! empty( $backup['relative_dir'] ) ) {
				$files = isset( $backup['files'] ) && is_array( $backup['files'] ) ? $backup['files'] : array();
				BackupManager::delete( $backup['relative_dir'], $files );
			}

			// The status stays 'optimized' so the image keeps counting toward the
			// savings stats. An empty backup_path is what marks it unrestorable.
			OptimizationLog::update(
				(int) $row['attachment_id'],
				array(
					'backup_path'    => '',
					'backup_expires' => 0,
				)
			);

			++$removed;
		}

		return $removed;
	}
}
