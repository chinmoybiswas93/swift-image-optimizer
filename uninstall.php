<?php
/**
 * Uninstall routine.
 *
 * Removes the plugin's own data. User media is never touched, and backups are
 * left in place so an accidental uninstall cannot destroy original images.
 *
 * @package SwiftImageOptimizer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// WordPress runs this file without loading the plugin, so the autoloader has
// to be pulled in by hand. The migrator resolves its table names through the
// models, so a single-file require is no longer enough.
require_once __DIR__ . '/vendor/autoload.php';

\SwiftImageOptimizer\Database\DBMigrator::dropTables();

/**
 * Remove one of the plugin's own working directories inside uploads.
 *
 * Logs and scratch files are the plugin's own output and hold nothing the user
 * would want back, so unlike backups they go. Resolved without loading any
 * plugin class, which would drag in the autoloader and the settings.
 *
 * @param string $name Directory name inside uploads.
 * @return void
 */
function swift_image_optimizer_remove_dir( $name ) {
	$uploads = wp_get_upload_dir();

	if ( ! empty( $uploads['error'] ) ) {
		return;
	}

	$dir = trailingslashit( $uploads['basedir'] ) . $name;

	if ( ! is_dir( $dir ) ) {
		return;
	}

	$files = glob( $dir . '/*' );

	if ( is_array( $files ) ) {
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing the plugin's own now-empty directory during uninstall.
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A directory left behind by a failed removal is harmless.
}

swift_image_optimizer_remove_dir( 'swift-image-optimizer/logs' );
swift_image_optimizer_remove_dir( 'swift-image-optimizer/temp' );

/*
 * Then the parent, which only succeeds once it is empty.
 *
 * `swift-image-optimizer/backup` is deliberately not removed - those are the
 * user's original images and often the only copy. rmdir() refuses a directory
 * that still has anything in it, so this tidies up after an install that never
 * backed anything up, and leaves the folder alone the moment it holds
 * originals. Nothing here globs into the backup directory.
 */
swift_image_optimizer_remove_dir( 'swift-image-optimizer' );

delete_option( 'swift_image_optimizer_settings' );
delete_option( 'swift_image_optimizer_schema_version' );
delete_option( 'swift_image_optimizer_bulk_progress' );
delete_option( 'swift_image_optimizer_log_suffix' );
delete_option( 'swift_image_optimizer_bulk_lock' );
delete_option( 'swift_image_optimizer_bulk_phase' );
delete_option( 'swift_image_optimizer_library_scan' );
delete_option( 'swift_image_optimizer_scan_progress' );
delete_option( 'swift_image_optimizer_scan_lock' );

// Per-attachment conversion locks are options rather than transients, so they
// need clearing explicitly. Normally released as each conversion ends; a
// process killed mid-conversion can leave one behind.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall routine; no core API deletes options by prefix.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'swift_image_optimizer_lock_' ) . '%'
	)
);

delete_transient( 'swift_image_optimizer_stats' );

wp_clear_scheduled_hook( 'swift_image_optimizer_purge_backups' );
wp_clear_scheduled_hook( 'swift_image_optimizer_scan_batch' );
wp_clear_scheduled_hook( 'swift_image_optimizer_scheduled_scan' );
