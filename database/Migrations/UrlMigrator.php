<?php
/**
 * URL lookup table schema.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Database\Migrations;

use SwiftImageOptimizer\App\Models\UrlLookup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades {prefix}swift_image_optimizer_urls, the indexed map
 * backing the 404 fallback.
 */
class UrlMigrator {

	/**
	 * Apply the schema.
	 *
	 * @return void
	 */
	public static function migrate() {
		global $wpdb;

		$table   = UrlLookup::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT(20) UNSIGNED NOT NULL,
			old_path VARCHAR(191) NOT NULL DEFAULT '',
			old_basename VARCHAR(191) NOT NULL DEFAULT '',
			new_url TEXT NULL,
			PRIMARY KEY  (id),
			KEY old_path (old_path),
			KEY old_basename (old_basename),
			KEY attachment_id (attachment_id)
		) {$collate};";

		dbDelta( $sql );
	}
}
