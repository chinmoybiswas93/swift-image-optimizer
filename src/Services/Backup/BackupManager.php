<?php
/**
 * Original-file backups.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Services\Backup;

use SwiftImageOptimizer\Repositories\SettingsRepository;
use SwiftImageOptimizer\Services\Logging\Logger;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores pre-optimization copies of images so a conversion can be undone.
 *
 * Backups mirror the uploads directory structure, so two files with the same
 * basename in different months never collide.
 */
class BackupManager {

	/**
	 * Directory name inside uploads.
	 */
	const DIRNAME = 'swift-image-optimizer-backups';

	/**
	 * Absolute path to the backup root.
	 *
	 * @return string
	 */
	public static function root() {
		$uploads = wp_get_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . self::DIRNAME;
	}

	/**
	 * Create the backup root and block direct web access to it.
	 *
	 * @return bool True when the directory exists and is usable.
	 */
	public static function ensure_root() {
		$root = self::root();

		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			return false;
		}

		$htaccess = $root . '/.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a protective file inside the plugin's own directory.
			@file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort hardening; failure is non-fatal.
		}

		$index = $root . '/index.php';

		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a protective file inside the plugin's own directory.
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort hardening; failure is non-fatal.
		}

		return is_dir( $root ) && wp_is_writable( $root );
	}

	/**
	 * Back up an attachment's original file and every generated subsize.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|WP_Error {
	 *     @type string $relative_dir Backup directory relative to the backup root.
	 *     @type array  $files        Basenames that were copied.
	 *     @type int    $expires      Unix timestamp, or 0 when kept forever.
	 * }
	 */
	public static function backup( $attachment_id ) {
		if ( ! self::ensure_root() ) {
			return new WP_Error( 'backup-root-unwritable', __( 'The backup directory could not be created.', 'swift-image-optimizer' ) );
		}

		$main = get_attached_file( $attachment_id );

		if ( ! $main || ! file_exists( $main ) ) {
			return new WP_Error( 'missing-file', __( 'The attachment file could not be found.', 'swift-image-optimizer' ) );
		}

		$sources = self::collect_files( $attachment_id, $main );

		$space = self::has_space_for( $sources );

		if ( is_wp_error( $space ) ) {
			return $space;
		}

		$uploads  = wp_get_upload_dir();
		$basedir  = trailingslashit( $uploads['basedir'] );
		$relative = ltrim( str_replace( $basedir, '', dirname( $main ) ), '/' );

		$target_dir = trailingslashit( self::root() ) . $relative;

		if ( ! is_dir( $target_dir ) && ! wp_mkdir_p( $target_dir ) ) {
			return new WP_Error( 'backup-dir-failed', __( 'The backup subdirectory could not be created.', 'swift-image-optimizer' ) );
		}

		$copied = array();
		$bytes  = 0;

		foreach ( $sources as $path ) {
			$name = wp_basename( $path );

			if ( ! copy( $path, trailingslashit( $target_dir ) . $name ) ) {
				// Roll back the partial backup so a half-copy is never mistaken for a good one.
				self::purge_files( $target_dir, $copied );

				Logger::error( 'backup', 'Copy failed for ' . $path . ', rolled back ' . count( $copied ) . ' file(s).', $attachment_id );

				return new WP_Error(
					'backup-copy-failed',
					sprintf(
						/* translators: %s: file name. */
						__( 'Could not back up %s.', 'swift-image-optimizer' ),
						$name
					)
				);
			}

			$copied[] = $name;
			$bytes   += (int) filesize( $path );
		}

		Logger::info(
			'backup',
			'Stored originals in ' . $target_dir,
			$attachment_id,
			array(
				'files' => count( $copied ),
				'bytes' => $bytes,
			)
		);

		return array(
			'relative_dir' => $relative,
			'files'        => $copied,
			'expires'      => self::expiry(),
		);
	}

	/**
	 * Back up one loose file, before any attachment exists for it.
	 *
	 * The upload path converts at wp_handle_upload time, which is before
	 * WordPress has created the attachment row or any subsize. There is
	 * therefore nothing to enumerate - just the one file that is about to be
	 * replaced. The manifest returned is the same shape backup() returns, so
	 * restore() and the retention cron handle both kinds identically.
	 *
	 * @param string $file Absolute path to the file to preserve.
	 * @return array|WP_Error Manifest, as returned by backup().
	 */
	public static function backup_file( $file ) {
		if ( ! self::ensure_root() ) {
			return new WP_Error( 'backup-root-unwritable', __( 'The backup directory could not be created.', 'swift-image-optimizer' ) );
		}

		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'missing-file', __( 'The file to back up could not be found.', 'swift-image-optimizer' ) );
		}

		$space = self::has_space_for( array( $file ) );

		if ( is_wp_error( $space ) ) {
			return $space;
		}

		$uploads  = wp_get_upload_dir();
		$basedir  = trailingslashit( $uploads['basedir'] );
		$relative = ltrim( str_replace( $basedir, '', dirname( $file ) ), '/' );

		$target_dir = trailingslashit( self::root() ) . $relative;

		if ( ! is_dir( $target_dir ) && ! wp_mkdir_p( $target_dir ) ) {
			return new WP_Error( 'backup-dir-failed', __( 'The backup subdirectory could not be created.', 'swift-image-optimizer' ) );
		}

		$name = wp_basename( $file );

		if ( ! copy( $file, trailingslashit( $target_dir ) . $name ) ) {
			Logger::error( 'backup', 'Copy failed for the uploaded original ' . $file );

			return new WP_Error(
				'backup-copy-failed',
				sprintf(
					/* translators: %s: file name. */
					__( 'Could not back up %s.', 'swift-image-optimizer' ),
					$name
				)
			);
		}

		Logger::info(
			'backup',
			'Stored the uploaded original in ' . $target_dir,
			0,
			array( 'bytes' => (int) filesize( $file ) )
		);

		return array(
			'relative_dir' => $relative,
			'files'        => array( $name ),
			'expires'      => self::expiry(),
		);
	}

	/**
	 * Whether there is room on disk to copy these files.
	 *
	 * Checked up front because a copy() that runs out of space halfway leaves
	 * a truncated file that looks like a valid backup. Asking for twice the
	 * payload leaves room for the WebP that is about to be written beside it.
	 *
	 * @param string[] $files Absolute paths about to be copied.
	 * @return true|WP_Error
	 */
	private static function has_space_for( array $files ) {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return true;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir blocks this on some hosts; handled by the false check below.
		$free = @disk_free_space( self::root() );

		if ( ! $free ) {
			return true;
		}

		$needed = 0;

		foreach ( $files as $file ) {
			if ( file_exists( $file ) ) {
				$needed += (int) filesize( $file );
			}
		}

		if ( $free >= $needed * 2 ) {
			return true;
		}

		Logger::error(
			'backup',
			'Not enough disk space to back up safely.',
			0,
			array(
				'needed' => $needed * 2,
				'free'   => (int) $free,
			)
		);

		return new WP_Error(
			'insufficient-disk',
			__( 'There is not enough free disk space to back up the original safely.', 'swift-image-optimizer' )
		);
	}

	/**
	 * Every on-disk file belonging to an attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $main          Absolute path to the main file.
	 * @return string[] Absolute paths.
	 */
	private static function collect_files( $attachment_id, $main ) {
		$files = array( $main );
		$dir   = trailingslashit( dirname( $main ) );
		$meta  = wp_get_attachment_metadata( $attachment_id );

		// The unscaled original, when WordPress created a -scaled version.
		if ( ! empty( $meta['original_image'] ) ) {
			$original = $dir . $meta['original_image'];

			if ( file_exists( $original ) ) {
				$files[] = $original;
			}
		}

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}

				$path = $dir . $size['file'];

				if ( file_exists( $path ) && ! in_array( $path, $files, true ) ) {
					$files[] = $path;
				}
			}
		}

		return $files;
	}

	/**
	 * Whether a stored manifest still corresponds to files on disk.
	 *
	 * The column advertising a backup and the backup existing are two
	 * different facts, and they have already drifted apart once on this
	 * project when a test harness deleted backup files without touching the
	 * rows. Offering a Restore that cannot succeed is worse than not offering
	 * one, so the button asks this rather than trusting the column.
	 *
	 * @param mixed $backup Decoded manifest, or the raw JSON column value.
	 * @return bool
	 */
	public static function manifest_is_intact( $backup ) {
		if ( is_string( $backup ) ) {
			$backup = json_decode( $backup, true );
		}

		if ( ! is_array( $backup ) || empty( $backup['relative_dir'] ) || empty( $backup['files'] ) ) {
			return false;
		}

		$dir = self::safe_path( $backup['relative_dir'] );

		if ( is_wp_error( $dir ) ) {
			return false;
		}

		foreach ( (array) $backup['files'] as $name ) {
			if ( ! file_exists( trailingslashit( $dir ) . wp_basename( $name ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Copy a backed-up set of files back into the uploads directory.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $relative_dir  Backup directory relative to the backup root.
	 * @param array  $files         Basenames to restore.
	 * @return true|WP_Error
	 */
	public static function restore( $attachment_id, $relative_dir, array $files ) {
		$source_dir = self::safe_path( $relative_dir );

		if ( is_wp_error( $source_dir ) ) {
			return $source_dir;
		}

		if ( ! is_dir( $source_dir ) ) {
			return new WP_Error( 'backup-missing', __( 'The backup for this image no longer exists.', 'swift-image-optimizer' ) );
		}

		$uploads    = wp_get_upload_dir();
		$target_dir = trailingslashit( trailingslashit( $uploads['basedir'] ) . $relative_dir );

		if ( ! is_dir( $target_dir ) && ! wp_mkdir_p( $target_dir ) ) {
			return new WP_Error( 'restore-dir-failed', __( 'The uploads subdirectory could not be recreated.', 'swift-image-optimizer' ) );
		}

		foreach ( $files as $name ) {
			$name = wp_basename( $name );
			$from = trailingslashit( $source_dir ) . $name;

			if ( ! file_exists( $from ) ) {
				continue;
			}

			if ( ! copy( $from, $target_dir . $name ) ) {
				return new WP_Error(
					'restore-copy-failed',
					sprintf(
						/* translators: %s: file name. */
						__( 'Could not restore %s.', 'swift-image-optimizer' ),
						$name
					)
				);
			}
		}

		return true;
	}

	/**
	 * Delete a stored backup.
	 *
	 * @param string $relative_dir Backup directory relative to the backup root.
	 * @param array  $files        Basenames to remove.
	 * @return bool
	 */
	public static function delete( $relative_dir, array $files ) {
		$dir = self::safe_path( $relative_dir );

		if ( is_wp_error( $dir ) || ! is_dir( $dir ) ) {
			return false;
		}

		self::purge_files( $dir, $files );

		// Remove the directory when nothing is left in it.
		$remaining = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Handled by the array check.

		if ( is_array( $remaining ) && 2 === count( $remaining ) ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing the plugin's own empty backup directory.
		}

		return true;
	}

	/**
	 * Delete a list of basenames from a directory.
	 *
	 * @param string $dir   Absolute directory path.
	 * @param array  $files Basenames.
	 * @return void
	 */
	private static function purge_files( $dir, array $files ) {
		foreach ( $files as $name ) {
			$path = trailingslashit( $dir ) . wp_basename( $name );

			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Resolve a relative backup path, refusing anything outside the backup root.
	 *
	 * @param string $relative_dir Untrusted relative directory.
	 * @return string|WP_Error Absolute path.
	 */
	private static function safe_path( $relative_dir ) {
		$root = self::root();
		$path = trailingslashit( $root ) . ltrim( (string) $relative_dir, '/\\' );

		$real_root = realpath( $root );
		$real_path = realpath( $path );

		if ( false === $real_root ) {
			return new WP_Error( 'backup-root-missing', __( 'The backup directory does not exist.', 'swift-image-optimizer' ) );
		}

		// A path that does not exist yet is fine, but it must still resolve inside the root.
		$check = false !== $real_path ? $real_path : $path;

		if ( 0 !== strpos( $check, $real_root ) ) {
			return new WP_Error( 'backup-path-invalid', __( 'Refusing to access a path outside the backup directory.', 'swift-image-optimizer' ) );
		}

		return $path;
	}

	/**
	 * The expiry timestamp for a backup created now.
	 *
	 * @return int Unix timestamp, or 0 when backups are kept forever.
	 */
	public static function expiry() {
		$days = (int) SettingsRepository::get( 'backup_retention', 30 );

		if ( $days <= 0 ) {
			return 0;
		}

		return time() + ( $days * DAY_IN_SECONDS );
	}

	/**
	 * Total disk space used by backups.
	 *
	 * @return int Bytes.
	 */
	public static function disk_usage() {
		$root = self::root();

		if ( ! is_dir( $root ) ) {
			return 0;
		}

		$total = 0;

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$total += $file->getSize();
				}
			}
		} catch ( \Exception $e ) {
			return $total;
		}

		return $total;
	}
}
