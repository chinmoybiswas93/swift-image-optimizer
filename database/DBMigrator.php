<?php
/**
 * Schema migrator.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Database;

use SwiftImageOptimizer\App\Models\OptimizationLog;
use SwiftImageOptimizer\App\Models\UrlLookup;
use SwiftImageOptimizer\Database\Migrations\LogMigrator;
use SwiftImageOptimizer\Database\Migrations\UrlMigrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs every table migrator, gated on a stored schema version.
 *
 * Called eagerly on activation and again on init, where it is a no-op unless
 * the stored version is behind. That second call is what upgrades a site whose
 * plugin files were replaced without the activation hook ever firing - the
 * normal case for an FTP or WP-CLI update.
 */
class DBMigrator {

	/**
	 * Schema version. Bump when any migrator's table structure changes.
	 *
	 * 2 — added conversion_ms.
	 * 3 — added the URL lookup table backing the 404 fallback.
	 */
	const SCHEMA_VERSION = 3;

	/**
	 * Option storing the installed schema version.
	 */
	const SCHEMA_OPTION = 'swift_image_optimizer_schema_version';

	/**
	 * Migrators to run, in order.
	 *
	 * @var array<int, string>
	 */
	private static $migrators = array(
		LogMigrator::class,
		UrlMigrator::class,
	);

	/**
	 * Create or upgrade every table.
	 *
	 * @return void
	 */
	public static function migrateUp() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$installed = (int) get_option( self::SCHEMA_OPTION, 0 );

		foreach ( self::$migrators as $migrator ) {
			$migrator::migrate();
		}

		self::runBackfills( $installed );

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Apply pending schema changes, if any.
	 *
	 * @return void
	 */
	public static function maybeMigrateDBChanges() {
		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) === self::SCHEMA_VERSION ) {
			return;
		}

		self::migrateUp();
	}

	/**
	 * Drop every table. Used by uninstall only.
	 *
	 * @return void
	 */
	public static function dropTables() {
		global $wpdb;

		foreach ( array( OptimizationLog::table(), UrlLookup::table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built internally; uninstall routine.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( self::SCHEMA_OPTION );
	}

	/**
	 * Run data migrations for the version being upgraded from.
	 *
	 * Guarded on $installed > 0 so a fresh install never runs them - there is
	 * nothing to backfill, and the queries would scan an empty table.
	 *
	 * @param int $installed Schema version before this run.
	 * @return void
	 */
	private static function runBackfills( $installed ) {
		if ( $installed > 0 && $installed < 3 ) {
			DataBackfills::backfillUrls();
		}
	}
}
