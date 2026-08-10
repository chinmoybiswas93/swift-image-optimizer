<?php
/**
 * Optimization log table schema.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Database\Migrations;

use SwiftImageOptimizer\App\Models\OptimizationLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades {prefix}swift_image_optimizer_log.
 */
class LogMigrator {

	/**
	 * Apply the schema.
	 *
	 * @return void
	 */
	public static function migrate() {
		global $wpdb;

		$table   = OptimizationLog::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			attachment_id BIGINT(20) UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'optimized',
			original_file TEXT NULL,
			original_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			original_mime VARCHAR(100) NULL,
			optimized_file TEXT NULL,
			optimized_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			backup_path TEXT NULL,
			backup_expires BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			url_map LONGTEXT NULL,
			engine VARCHAR(32) NULL,
			conversion_ms INT UNSIGNED NOT NULL DEFAULT 0,
			reason VARCHAR(191) NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (attachment_id),
			KEY status (status),
			KEY backup_expires (backup_expires)
		) {$collate};";

		dbDelta( $sql );
	}
}
