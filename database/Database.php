<?php
/**
 * Log table schema and access helpers.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the {prefix}swift_image_optimizer_log table.
 *
 * One row per attachment the plugin has touched. This single table backs the
 * stats dashboard, the restore feature, bulk dedupe and the 404 fallback map.
 */
class Database {

	/**
	 * Schema version. Bump when the table structure changes.
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
	 * Row statuses.
	 */
	const STATUS_OPTIMIZED = 'optimized';
	const STATUS_SKIPPED   = 'skipped';
	const STATUS_FAILED    = 'failed';
	const STATUS_RESTORED  = 'restored';

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'swift_image_optimizer_log';
	}

	/**
	 * Fully qualified name of the URL lookup table.
	 *
	 * @return string
	 */
	public static function url_table() {
		global $wpdb;

		return $wpdb->prefix . 'swift_image_optimizer_urls';
	}

	/**
	 * Create or upgrade the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		$installed = (int) get_option( self::SCHEMA_OPTION, 0 );

		if ( $installed === self::SCHEMA_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
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

		// The 404 fallback used to search the log table's url_map column with
		// a LIKE over LONGTEXT, which no index can help and which every bot
		// probing an old filename would trigger. This is the same data in a
		// shape the database can actually look things up in.
		$urls = self::url_table();

		$sql = "CREATE TABLE {$urls} (
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

		// Sites upgrading from v2 already have conversions whose old URLs are
		// only recorded in the log table's url_map column. Without this their
		// 404 fallback would silently stop working the moment it switched to
		// the new table.
		if ( $installed > 0 && $installed < 3 ) {
			self::backfill_urls();
		}

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Insert or replace a log row.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $data          Column values.
	 * @return bool True on success.
	 */
	public static function upsert( $attachment_id, array $data ) {
		global $wpdb;

		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 ) {
			return false;
		}

		$defaults = array(
			'status'         => self::STATUS_OPTIMIZED,
			'original_file'  => '',
			'original_size'  => 0,
			'original_mime'  => '',
			'optimized_file' => '',
			'optimized_size' => 0,
			'backup_path'    => '',
			'backup_expires' => 0,
			'url_map'        => '',
			'engine'         => '',
			'conversion_ms'  => 0,
			'reason'         => '',
			'created_at'     => current_time( 'mysql' ),
		);

		$row                  = wp_parse_args( $data, $defaults );
		$row['attachment_id'] = $attachment_id;

		if ( is_array( $row['url_map'] ) ) {
			$row['url_map'] = wp_json_encode( $row['url_map'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table, no core API available.
		$result = $wpdb->replace( self::table(), $row );

		self::flush_stats_cache();

		return false !== $result;
	}

	/**
	 * Fetch a single log row.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null Row as an associative array, or null when absent.
	 */
	public static function get( $attachment_id ) {
		global $wpdb;

		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 ) {
			return null;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built internally; value is prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE attachment_id = %d", $attachment_id ), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		$row['url_map'] = $row['url_map'] ? json_decode( $row['url_map'], true ) : array();

		return $row;
	}

	/**
	 * Update selected columns on an existing row.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $data          Column values.
	 * @return bool True on success.
	 */
	public static function update( $attachment_id, array $data ) {
		global $wpdb;

		if ( isset( $data['url_map'] ) && is_array( $data['url_map'] ) ) {
			$data['url_map'] = wp_json_encode( $data['url_map'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table, no core API available.
		$result = $wpdb->update( self::table(), $data, array( 'attachment_id' => (int) $attachment_id ) );

		self::flush_stats_cache();

		return false !== $result;
	}

	/**
	 * Delete a log row.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True on success.
	 */
	public static function delete( $attachment_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table, no core API available.
		$result = $wpdb->delete( self::table(), array( 'attachment_id' => (int) $attachment_id ) );

		self::flush_stats_cache();

		return false !== $result;
	}

	/**
	 * Populate the lookup table from url_map values already on record.
	 *
	 * @return int Attachments processed.
	 */
	private static function backfill_urls() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; one-off schema upgrade.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT attachment_id, url_map FROM {$table} WHERE status = %s AND url_map != ''",
				self::STATUS_OPTIMIZED
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$done = 0;

		foreach ( $rows as $row ) {
			$map = json_decode( (string) $row['url_map'], true );

			if ( ! is_array( $map ) || empty( $map ) ) {
				continue;
			}

			self::remember_urls( (int) $row['attachment_id'], $map );

			++$done;
		}

		return $done;
	}

	/**
	 * Record where each of an attachment's old URLs now points.
	 *
	 * Only one row per file is stored, keyed by its uploads-relative path -
	 * the absolute, protocol-relative and escaped variants in the map all
	 * reduce to the same path, and the fallback only ever has a request path
	 * to match against.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $url_map       Old URL to new URL.
	 * @return int Rows written.
	 */
	public static function remember_urls( $attachment_id, array $url_map ) {
		global $wpdb;

		$attachment_id = (int) $attachment_id;

		self::forget_urls( $attachment_id );

		if ( $attachment_id <= 0 || empty( $url_map ) ) {
			return 0;
		}

		$uploads = wp_get_upload_dir();
		$base    = wp_parse_url( untrailingslashit( $uploads['baseurl'] ), PHP_URL_PATH );
		$seen    = array();
		$written = 0;

		foreach ( $url_map as $old => $new ) {
			$path = wp_parse_url( str_replace( '\\/', '/', $old ), PHP_URL_PATH );

			if ( ! $path ) {
				continue;
			}

			$relative = ( $base && 0 === strpos( $path, $base ) )
				? ltrim( substr( $path, strlen( $base ) ), '/' )
				: ltrim( $path, '/' );

			if ( '' === $relative || isset( $seen[ $relative ] ) ) {
				continue;
			}

			$seen[ $relative ] = true;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table, no core API available.
			$wpdb->insert(
				self::url_table(),
				array(
					'attachment_id' => $attachment_id,
					'old_path'      => $relative,
					'old_basename'  => wp_basename( $relative ),
					'new_url'       => $new,
				)
			);

			++$written;
		}

		return $written;
	}

	/**
	 * Drop an attachment's URL lookup rows, after a restore.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function forget_urls( $attachment_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table, no core API available.
		$wpdb->delete( self::url_table(), array( 'attachment_id' => (int) $attachment_id ) );
	}

	/**
	 * Find where a requested file now lives.
	 *
	 * Matches on the full uploads-relative path when the request carries one,
	 * which is what keeps 2023/01/photo.jpg and 2024/05/photo.jpg apart. Only
	 * when that fails does it fall back to the basename alone.
	 *
	 * @param string $relative_path Uploads-relative path from the request, may be empty.
	 * @param string $basename      Requested filename.
	 * @return string Replacement URL, or an empty string.
	 */
	public static function lookup_url( $relative_path, $basename ) {
		global $wpdb;

		$table = self::url_table();

		if ( $relative_path ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; value prepared, result cached by the caller.
			$exact = $wpdb->get_var(
				$wpdb->prepare( "SELECT new_url FROM {$table} WHERE old_path = %s LIMIT 1", $relative_path )
			);

			if ( $exact ) {
				return (string) $exact;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; value prepared, result cached by the caller.
		$fallback = $wpdb->get_var(
			$wpdb->prepare( "SELECT new_url FROM {$table} WHERE old_basename = %s LIMIT 1", $basename )
		);

		return $fallback ? (string) $fallback : '';
	}

	/**
	 * Invalidate the cached stats aggregate.
	 *
	 * @return void
	 */
	public static function flush_stats_cache() {
		delete_transient( 'swift_image_optimizer_stats' );
	}

	/**
	 * Drop the table. Used by uninstall only.
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		$table = self::table();

		$urls = self::url_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built internally; uninstall routine.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built internally; uninstall routine.
		$wpdb->query( "DROP TABLE IF EXISTS {$urls}" );

		delete_option( self::SCHEMA_OPTION );
	}
}
