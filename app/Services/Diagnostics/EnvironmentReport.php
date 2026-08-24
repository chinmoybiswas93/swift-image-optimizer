<?php
/**
 * Server and plugin environment report.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Services\Diagnostics;

use SwiftImageOptimizer\App\Models\OptimizationLog;
use SwiftImageOptimizer\Database\DBMigrator;
use SwiftImageOptimizer\Api\StoreSettings;
use SwiftImageOptimizer\App\Services\Backup\BackupManager;
use SwiftImageOptimizer\App\Services\Bulk\Scanner;
use SwiftImageOptimizer\App\Services\Engine\EngineFactory;
use SwiftImageOptimizer\App\Services\Logging\Logger;
use SwiftImageOptimizer\App\Hooks\Scheduler\JobRunner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers "why did this fail on my host" without shell access.
 *
 * Every row carries a state and, where the state is not OK, a remedy written
 * for a site owner rather than a developer - the copy rule from ui-context.md.
 */
class EnvironmentReport {

	/**
	 * Row states.
	 */
	const OK    = 'ok';
	const WARN  = 'warn';
	const ERROR = 'error';

	/**
	 * Build the full report.
	 *
	 * @return array{sections: array, generated: string}
	 */
	public static function get() {
		return array(
			'generated' => current_time( 'mysql' ),
			'sections'  => array(
				self::engines(),
				self::php(),
				self::filesystem(),
				self::wordpress(),
				self::plugin(),
			),
		);
	}

	/**
	 * Conversion engine availability and why each one is or is not usable.
	 *
	 * @return array
	 */
	private static function engines() {
		$available = EngineFactory::availability();
		$active    = EngineFactory::get();
		$preferred = StoreSettings::get( 'engine', 'auto' );

		$rows = array();

		$rows[] = self::row(
			__( 'Active engine', 'swift-image-optimizer' ),
			$active ? $active->name() : __( 'None', 'swift-image-optimizer' ),
			$active ? self::OK : self::ERROR,
			$active ? '' : __( 'No image conversion engine was found. Ask your host to enable the Imagick extension, or GD with WebP support.', 'swift-image-optimizer' )
		);

		if ( 'auto' !== $preferred ) {
			$honored = isset( $available[ $preferred ] ) && $available[ $preferred ];

			$rows[] = self::row(
				__( 'Engine preference', 'swift-image-optimizer' ),
				$preferred,
				$honored ? self::OK : self::WARN,
				$honored ? '' : __( 'The engine chosen in Settings is not available here, so the best available one is being used instead.', 'swift-image-optimizer' )
			);
		}

		$rows[] = self::row(
			__( 'Imagick', 'swift-image-optimizer' ),
			self::yes_no( $available['imagick'] ),
			$available['imagick'] ? self::OK : self::WARN,
			$available['imagick'] ? '' : self::imagick_reason()
		);

		if ( $available['imagick'] ) {
			$rows[] = self::row( __( 'ImageMagick version', 'swift-image-optimizer' ), self::imagick_version(), self::OK, '' );
		}

		$rows[] = self::row(
			__( 'cwebp binary', 'swift-image-optimizer' ),
			$available['cwebp'] ? self::cwebp_path() : self::yes_no( false ),
			$available['cwebp'] ? self::OK : self::WARN,
			$available['cwebp'] ? '' : self::cwebp_reason()
		);

		$rows[] = self::row(
			__( 'GD with WebP support', 'swift-image-optimizer' ),
			self::yes_no( $available['gd'] ),
			$available['gd'] ? self::OK : self::WARN,
			$available['gd'] ? '' : __( 'GD is missing or was built without WebP support. It is the universal fallback, so this is worth asking your host about.', 'swift-image-optimizer' )
		);

		$rows[] = self::row(
			__( 'Reads EXIF orientation', 'swift-image-optimizer' ),
			self::yes_no( function_exists( 'exif_read_data' ) ),
			function_exists( 'exif_read_data' ) ? self::OK : self::WARN,
			function_exists( 'exif_read_data' ) ? '' : __( 'Without the EXIF extension, photos taken in portrait may be saved sideways when GD is the active engine.', 'swift-image-optimizer' )
		);

		return self::section( __( 'Conversion engines', 'swift-image-optimizer' ), $rows );
	}

	/**
	 * PHP limits that decide whether a large image can be processed at all.
	 *
	 * @return array
	 */
	private static function php() {
		$memory      = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$max_execute = (int) ini_get( 'max_execution_time' );
		$disabled    = trim( (string) ini_get( 'disable_functions' ) );

		$rows = array(
			self::row( __( 'PHP version', 'swift-image-optimizer' ), PHP_VERSION, version_compare( PHP_VERSION, '7.4', '>=' ) ? self::OK : self::ERROR, '' ),
			self::row(
				__( 'Memory limit', 'swift-image-optimizer' ),
				(string) ini_get( 'memory_limit' ),
				( $memory <= 0 || $memory >= 268435456 ) ? self::OK : self::WARN,
				( $memory <= 0 || $memory >= 268435456 ) ? '' : __( 'Large images may be skipped. 256M or more is recommended; ask your host to raise it.', 'swift-image-optimizer' )
			),
			self::row(
				__( 'Max execution time', 'swift-image-optimizer' ),
				0 === $max_execute ? __( 'Unlimited', 'swift-image-optimizer' ) : $max_execute . 's',
				( 0 === $max_execute || $max_execute >= 30 ) ? self::OK : self::WARN,
				( 0 === $max_execute || $max_execute >= 30 ) ? '' : __( 'Bulk optimization will process fewer images per request. It will still finish, it will just take more steps.', 'swift-image-optimizer' )
			),
			self::row( __( 'Upload max filesize', 'swift-image-optimizer' ), (string) ini_get( 'upload_max_filesize' ), self::OK, '' ),
			self::row( __( 'Post max size', 'swift-image-optimizer' ), (string) ini_get( 'post_max_size' ), self::OK, '' ),
			self::row(
				__( 'Disabled PHP functions', 'swift-image-optimizer' ),
				'' === $disabled ? __( 'None', 'swift-image-optimizer' ) : $disabled,
				'' === $disabled ? self::OK : self::WARN,
				'' === $disabled ? '' : __( 'Some functions are blocked by the host. This only matters if it prevents the cwebp binary from running.', 'swift-image-optimizer' )
			),
		);

		return self::section( __( 'PHP', 'swift-image-optimizer' ), $rows );
	}

	/**
	 * Directories the plugin has to be able to write to.
	 *
	 * @return array
	 */
	private static function filesystem() {
		$uploads = wp_get_upload_dir();
		$free    = self::free_space();
		$log_dir = Logger::ensure_dir();

		$uploads_writable = empty( $uploads['error'] ) && wp_is_writable( $uploads['basedir'] );
		$backup_writable  = BackupManager::ensure_root();

		$rows = array(
			self::row(
				__( 'Uploads directory writable', 'swift-image-optimizer' ),
				self::yes_no( $uploads_writable ),
				$uploads_writable ? self::OK : self::ERROR,
				$uploads_writable ? '' : __( 'Nothing can be converted until the uploads folder is writable. Ask your host to correct its permissions.', 'swift-image-optimizer' )
			),
			self::row(
				__( 'Backup directory writable', 'swift-image-optimizer' ),
				self::yes_no( $backup_writable ),
				$backup_writable ? self::OK : self::ERROR,
				$backup_writable ? '' : __( 'Originals cannot be backed up, so conversions of existing images will be refused rather than risk being irreversible.', 'swift-image-optimizer' )
			),
			self::row(
				__( 'Log directory writable', 'swift-image-optimizer' ),
				self::yes_no( ! is_wp_error( $log_dir ) ),
				is_wp_error( $log_dir ) ? self::WARN : self::OK,
				is_wp_error( $log_dir ) ? __( 'Diagnostic logging is unavailable because the log folder could not be created.', 'swift-image-optimizer' ) : ''
			),
			self::row(
				__( 'Free disk space', 'swift-image-optimizer' ),
				$free > 0 ? size_format( $free ) : __( 'Unknown', 'swift-image-optimizer' ),
				( $free > 0 && $free < 524288000 ) ? self::WARN : self::OK,
				( $free > 0 && $free < 524288000 ) ? __( 'Less than 500MB free. Backups need roughly as much space as the images being converted.', 'swift-image-optimizer' ) : ''
			),
			self::row( __( 'Backup storage used', 'swift-image-optimizer' ), size_format( BackupManager::disk_usage() ), self::OK, '' ),
			self::row( __( 'Log file size', 'swift-image-optimizer' ), size_format( Logger::size() ), self::OK, '' ),
			self::row( __( 'Uploads path', 'swift-image-optimizer' ), $uploads['basedir'], self::OK, '' ),
		);

		return self::section( __( 'Filesystem', 'swift-image-optimizer' ), $rows );
	}

	/**
	 * WordPress-side facts that change how the plugin behaves.
	 *
	 * @return array
	 */
	private static function wordpress() {
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$next_purge    = wp_next_scheduled( JobRunner::HOOK );

		$rows = array(
			self::row( __( 'WordPress version', 'swift-image-optimizer' ), get_bloginfo( 'version' ), self::OK, '' ),
			self::row(
				__( 'Multisite', 'swift-image-optimizer' ),
				self::yes_no( is_multisite() ),
				is_multisite() ? self::WARN : self::OK,
				is_multisite() ? __( 'This plugin has not been tested on a multisite network.', 'swift-image-optimizer' ) : ''
			),
			self::row( __( 'Debug mode', 'swift-image-optimizer' ), self::yes_no( defined( 'WP_DEBUG' ) && WP_DEBUG ), self::OK, '' ),
			self::row(
				__( 'Persistent object cache', 'swift-image-optimizer' ),
				self::yes_no( wp_using_ext_object_cache() ),
				self::OK,
				''
			),
			self::row(
				__( 'WP-Cron', 'swift-image-optimizer' ),
				$cron_disabled ? __( 'Disabled', 'swift-image-optimizer' ) : __( 'Enabled', 'swift-image-optimizer' ),
				$cron_disabled ? self::WARN : self::OK,
				$cron_disabled ? __( 'Expired backups will not be cleaned up automatically unless a real cron job runs WordPress scheduled tasks.', 'swift-image-optimizer' ) : ''
			),
			self::row(
				__( 'Next backup cleanup', 'swift-image-optimizer' ),
				$next_purge ? wp_date( 'Y-m-d H:i', $next_purge ) : __( 'Not scheduled', 'swift-image-optimizer' ),
				$next_purge ? self::OK : self::WARN,
				$next_purge ? '' : __( 'The cleanup task is not scheduled. Deactivating and reactivating the plugin will restore it.', 'swift-image-optimizer' )
			),
		);

		return self::section( __( 'WordPress', 'swift-image-optimizer' ), $rows );
	}

	/**
	 * The plugin's own state.
	 *
	 * @return array
	 */
	private static function plugin() {
		$summary = Scanner::summary();
		$counts  = self::status_counts();

		$rows = array(
			self::row( __( 'Plugin version', 'swift-image-optimizer' ), SWIFT_IMAGE_OPTIMIZER_VERSION, self::OK, '' ),
			self::row( __( 'Database schema version', 'swift-image-optimizer' ), (string) get_option( DBMigrator::SCHEMA_OPTION, 0 ), self::OK, '' ),
			self::row( __( 'Convertible images', 'swift-image-optimizer' ), (string) $summary['total'], self::OK, '' ),
			self::row( __( 'Pending', 'swift-image-optimizer' ), (string) $summary['pending'], self::OK, '' ),
			self::row( __( 'Optimized', 'swift-image-optimizer' ), (string) $counts[ OptimizationLog::STATUS_OPTIMIZED ], self::OK, '' ),
			self::row( __( 'Skipped', 'swift-image-optimizer' ), (string) $counts[ OptimizationLog::STATUS_SKIPPED ], self::OK, '' ),
			self::row(
				__( 'Failed', 'swift-image-optimizer' ),
				(string) $counts[ OptimizationLog::STATUS_FAILED ],
				$counts[ OptimizationLog::STATUS_FAILED ] > 0 ? self::WARN : self::OK,
				$counts[ OptimizationLog::STATUS_FAILED ] > 0 ? __( 'Turn on logging and use Requeue below to try these again.', 'swift-image-optimizer' ) : ''
			),
			self::row( __( 'Restored', 'swift-image-optimizer' ), (string) $counts[ OptimizationLog::STATUS_RESTORED ], self::OK, '' ),
			self::row(
				__( 'Diagnostic logging', 'swift-image-optimizer' ),
				Logger::is_enabled() ? __( 'On', 'swift-image-optimizer' ) : __( 'Off (errors only)', 'swift-image-optimizer' ),
				self::OK,
				''
			),
		);

		return self::section( __( 'Plugin', 'swift-image-optimizer' ), $rows );
	}

	/**
	 * Row counts per status.
	 *
	 * @return array<string, int>
	 */
	private static function status_counts() {
		global $wpdb;

		$counts = array(
			OptimizationLog::STATUS_OPTIMIZED => 0,
			OptimizationLog::STATUS_SKIPPED   => 0,
			OptimizationLog::STATUS_FAILED    => 0,
			OptimizationLog::STATUS_RESTORED  => 0,
		);

		$table = OptimizationLog::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table; diagnostics screen, always read live.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return $counts;
		}

		foreach ( $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Why Imagick is unusable here.
	 *
	 * @return string
	 */
	private static function imagick_reason() {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick' ) ) {
			return __( 'The Imagick extension is not installed. It is the best option because it is the only engine that preserves colour profiles.', 'swift-image-optimizer' );
		}

		return __( 'Imagick is installed but was built without WebP support. Ask your host to add the WebP delegate.', 'swift-image-optimizer' );
	}

	/**
	 * ImageMagick version string.
	 *
	 * @return string
	 */
	private static function imagick_version() {
		try {
			$version = \Imagick::getVersion();
		} catch ( \Exception $e ) {
			return __( 'Unknown', 'swift-image-optimizer' );
		}

		return isset( $version['versionString'] ) ? (string) $version['versionString'] : __( 'Unknown', 'swift-image-optimizer' );
	}

	/**
	 * Why cwebp is unusable here.
	 *
	 * @return string
	 */
	private static function cwebp_reason() {
		if ( ! function_exists( 'exec' ) ) {
			return __( 'Running external programs is disabled on this server, so the cwebp binary cannot be used. This is common on shared hosting and is not a problem as long as another engine is available.', 'swift-image-optimizer' );
		}

		return __( 'No cwebp binary was found on this server. This plugin does not ship one, and does not need it when Imagick or GD is available.', 'swift-image-optimizer' );
	}

	/**
	 * Path to the cwebp binary, for display.
	 *
	 * @return string
	 */
	private static function cwebp_path() {
		/** This filter is documented in src/Services/Engine/CwebpEngine.php */
		$configured = apply_filters( 'swift_image_optimizer_cwebp_binary', '' );

		if ( $configured && is_executable( $configured ) ) {
			return (string) $configured;
		}

		foreach ( array( '/usr/bin/cwebp', '/usr/local/bin/cwebp', '/opt/homebrew/bin/cwebp', '/opt/local/bin/cwebp' ) as $candidate ) {
			if ( is_executable( $candidate ) ) {
				return $candidate;
			}
		}

		return __( 'Found', 'swift-image-optimizer' );
	}

	/**
	 * Free space on the uploads volume.
	 *
	 * @return int Bytes, or 0 when it cannot be determined.
	 */
	private static function free_space() {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return 0;
		}

		$uploads = wp_get_upload_dir();

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir restrictions make this fail on some hosts; handled by the false check.
		$free = @disk_free_space( $uploads['basedir'] );

		return $free ? (int) $free : 0;
	}

	/**
	 * Build a section.
	 *
	 * @param string $title Section heading.
	 * @param array  $rows  Rows.
	 * @return array
	 */
	private static function section( $title, array $rows ) {
		return array(
			'title' => $title,
			'rows'  => $rows,
		);
	}

	/**
	 * Build a row.
	 *
	 * @param string $label Row label.
	 * @param string $value Displayed value.
	 * @param string $state One of the state constants.
	 * @param string $hint  Plain-language remedy, empty when the state is OK.
	 * @return array
	 */
	private static function row( $label, $value, $state, $hint ) {
		return array(
			'label' => $label,
			'value' => (string) $value,
			'state' => $state,
			'hint'  => $hint,
		);
	}

	/**
	 * Localized yes/no.
	 *
	 * @param bool $value The flag.
	 * @return string
	 */
	private static function yes_no( $value ) {
		return $value ? __( 'Yes', 'swift-image-optimizer' ) : __( 'No', 'swift-image-optimizer' );
	}

	/**
	 * Flatten the report to plain text, for the "copy for support" button.
	 *
	 * @return string
	 */
	public static function as_text() {
		$report = self::get();
		$out    = array( '### Swift Image Optimizer diagnostics (' . $report['generated'] . ')' );

		foreach ( $report['sections'] as $section ) {
			$out[] = '';
			$out[] = '## ' . $section['title'];

			foreach ( $section['rows'] as $row ) {
				$line = sprintf( '%-32s %s', $row['label'] . ':', $row['value'] );

				if ( self::OK !== $row['state'] ) {
					$line .= '   [' . strtoupper( $row['state'] ) . ']';
				}

				$out[] = $line;
			}
		}

		return implode( "\n", $out );
	}
}
