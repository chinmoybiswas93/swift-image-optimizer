<?php
/**
 * Feature 2: convert an existing attachment and repoint everything at it.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Services;

use SwiftImageOptimizer\App\Models\OptimizationLog;
use SwiftImageOptimizer\App\Models\UrlLookup;
use SwiftImageOptimizer\Api\StoreSettings;
use SwiftImageOptimizer\App\Services\Backup\BackupManager;
use SwiftImageOptimizer\App\Services\Logging\Logger;
use SwiftImageOptimizer\App\Services\Rewrite\DatabaseRewriter;
use SwiftImageOptimizer\App\Services\Rewrite\UrlMap;
use SwiftImageOptimizer\App\Services\Lock;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts an attachment that is already in the Media Library.
 *
 * This is the destructive path. Unlike a fresh upload, the file already has
 * references scattered through posts, meta and options, so the conversion is
 * only half the job: the rest is repointing every reference and keeping a way
 * back if something goes wrong.
 */
class AttachmentConverter {

	/**
	 * Skips that will never become conversions, however the site changes.
	 *
	 * A library of any age contains formats nobody wants converted and images
	 * already at their best size. None of those are failures, and reporting
	 * them as such buries the handful of genuine problems in noise. Together
	 * with RETRYABLE_SKIPS this is the single definition of a soft error,
	 * shared by the converter, the bulk runner and the CLI.
	 *
	 * @var string[]
	 */
	const PERMANENT_SKIPS = array(
		'already-optimized',
		'already-webp',
		'animated-image',
		'png-disabled',
		'skipped-larger',
		'unsupported-format',
	);

	/**
	 * Skips caused by the environment rather than by the image.
	 *
	 * These say "not now" rather than "not ever": raising the memory limit,
	 * freeing disk space or installing an engine changes the answer. They are
	 * still excluded from the pending queue so a bulk run terminates, but the
	 * Troubleshoot screen can clear them so the next run tries again. Hard
	 * failures are retryable for the same reason without being listed here -
	 * anything that is not a soft error at all is retryable by definition.
	 *
	 * @var string[]
	 */
	const RETRYABLE_SKIPS = array(
		'engine-unsupported',
		'insufficient-disk',
		'insufficient-memory',
		'locked',
		'missing-file',
	);

	/**
	 * Every code that counts as a skip rather than a failure.
	 *
	 * @return string[]
	 */
	public static function soft_errors() {
		return array_merge( self::PERMANENT_SKIPS, self::RETRYABLE_SKIPS );
	}

	/**
	 * Whether an error code represents a skip rather than a failure.
	 *
	 * @param string $code Error code.
	 * @return bool
	 */
	public static function is_soft_error( $code ) {
		return in_array( $code, self::soft_errors(), true );
	}

	/**
	 * Whether a recorded outcome is worth trying again.
	 *
	 * A permanent skip is a property of the image; everything else is a
	 * property of the environment at the moment it ran.
	 *
	 * @param string $code Error code stored in the row's reason column.
	 * @return bool
	 */
	public static function is_retryable( $code ) {
		return ! in_array( $code, self::PERMANENT_SKIPS, true );
	}

	/**
	 * Whether a row claiming `optimized` still describes a file on disk.
	 *
	 * The column saying an image was optimized and that image actually being
	 * optimized are two different facts, and they come apart whenever the
	 * database and the uploads directory are restored from different points in
	 * time - which is exactly what a plugin backup restore does. The reported
	 * case was a library showing every image as processed, with no way to
	 * optimize any of them, while the files on disk were the untouched
	 * originals.
	 *
	 * Same reasoning as BackupManager::manifest_is_intact(), applied to the
	 * other end of the pipeline: ask the disk rather than trust the column.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $log           Row, if already loaded.
	 * @return bool
	 */
	public static function optimized_output_exists( $attachment_id, $log = null ) {
		if ( null === $log ) {
			$log = OptimizationLog::find( $attachment_id );
		}

		if ( ! is_array( $log ) || OptimizationLog::STATUS_OPTIMIZED !== $log['status'] ) {
			return false;
		}

		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return false;
		}

		/*
		 * The attached file existing is not enough on its own: after a restore
		 * of the uploads directory the original may be back in place under its
		 * old name while the row still describes the WebP. If the row names an
		 * output, the file actually attached has to be that output.
		 */
		if ( ! empty( $log['optimized_file'] ) && wp_basename( $file ) !== $log['optimized_file'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Optimizer instance.
	 *
	 * @var Optimizer
	 */
	private $optimizer;

	/**
	 * Database rewriter instance.
	 *
	 * @var DatabaseRewriter
	 */
	private $rewriter;

	/**
	 * Wire up the optimizer and rewriter this converter depends on.
	 *
	 * @param Optimizer        $optimizer Optimizer instance.
	 * @param DatabaseRewriter $rewriter  Rewriter instance.
	 */
	public function __construct( Optimizer $optimizer, DatabaseRewriter $rewriter ) {
		$this->optimizer = $optimizer;
		$this->rewriter  = $rewriter;
	}

	/**
	 * Convert one attachment to WebP.
	 *
	 * @param int  $attachment_id  Attachment ID.
	 * @param bool $defer_rewrite  When true the database rewrite is skipped and
	 *                             the URL map is returned instead, so a bulk run
	 *                             can rewrite many images in a single pass.
	 * @return array|WP_Error {
	 *     @type array $url_map        Old to new URLs.
	 *     @type int   $original_size  Bytes before.
	 *     @type int   $optimized_size Bytes after.
	 * }
	 */
	public function convert( $attachment_id, $defer_rewrite = false ) {
		$attachment_id = (int) $attachment_id;

		if ( ! Logger::run_id() ) {
			Logger::start_run( 'convert' );
		}

		$lock = 'swift_image_optimizer_lock_' . $attachment_id;

		if ( ! Lock::acquire( $lock ) ) {
			Logger::warn( 'lock', 'Already being processed, skipping.', $attachment_id );

			return new WP_Error( 'locked', __( 'This image is already being processed.', 'swift-image-optimizer' ) );
		}

		Logger::info( 'lock', 'Acquired.', $attachment_id );

		$result = $this->do_convert( $attachment_id, $defer_rewrite );

		Lock::release( $lock );

		Logger::info( 'lock', 'Released.', $attachment_id );

		if ( is_wp_error( $result ) ) {
			$this->log_failure( $attachment_id, $result );
		}

		return $result;
	}

	/**
	 * The conversion itself, wrapped by convert() for locking.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $defer_rewrite Whether to skip the database rewrite.
	 * @return array|WP_Error
	 */
	private function do_convert( $attachment_id, $defer_rewrite ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'not-attachment', __( 'That is not an attachment.', 'swift-image-optimizer' ) );
		}

		$existing = OptimizationLog::find( $attachment_id );

		if ( $existing && OptimizationLog::STATUS_OPTIMIZED === $existing['status'] ) {
			if ( self::optimized_output_exists( $attachment_id, $existing ) ) {
				return new WP_Error( 'already-optimized', __( 'This image has already been optimized.', 'swift-image-optimizer' ) );
			}

			/*
			 * The row says optimized but the output is gone, so the row is
			 * describing a file that no longer exists - typically because the
			 * database and the uploads directory were restored from different
			 * points in time. Refusing here is what left a library reporting
			 * every image as processed with no way to optimize any of them.
			 *
			 * Drop the stale row and carry on. The conversion below writes a
			 * fresh one. This does not touch a row whose file is intact, so it
			 * cannot discard a real result.
			 */
			OptimizationLog::delete( $attachment_id );
			OptimizationLog::flushStatsCache();

			Logger::info(
				'convert',
				'Discarded a stale optimized record: the file it described is missing.',
				$attachment_id
			);

			$existing = null;
		}

		$file = get_attached_file( $attachment_id );
		$mime = get_post_mime_type( $attachment_id );

		$eligible = $this->optimizer->can_optimize( $file, $mime );

		if ( is_wp_error( $eligible ) ) {
			$this->log_skip( $attachment_id, $file, $mime, $eligible );
			return $eligible;
		}

		$before = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $before ) ) {
			$before = array();
		}

		// Everything from here on is destructive, so back up first.
		$backup = BackupManager::backup( $attachment_id );

		if ( is_wp_error( $backup ) ) {
			Logger::wp_error( 'backup', $backup, $attachment_id );
			return $backup;
		}

		$optimized = $this->optimizer->optimize( $file, $mime, array(), $attachment_id );

		if ( is_wp_error( $optimized ) ) {
			BackupManager::delete( $backup['relative_dir'], $backup['files'] );

			Logger::info( 'backup', 'Rolled back, conversion did not proceed.', $attachment_id );

			if ( 'skipped-larger' === $optimized->get_error_code() ) {
				$this->log_skip( $attachment_id, $file, $mime, $optimized );
			}

			return $optimized;
		}

		$target = $this->optimizer->target_path( $file );

		if ( ! @rename( $optimized['temp_file'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Handled by the rollback below.
			wp_delete_file( $optimized['temp_file'] );
			BackupManager::delete( $backup['relative_dir'], $backup['files'] );

			$moved = new WP_Error( 'move-failed', __( 'The converted file could not be moved into place.', 'swift-image-optimizer' ) );

			Logger::error( 'rename', 'move-failed: could not move the WebP to ' . $target, $attachment_id );

			return $moved;
		}

		Logger::info( 'rename', 'Moved into place: ' . $target, $attachment_id );

		// Collect the old files before metadata is regenerated and the list is lost.
		$old_files = $this->existing_files( $file, $before );

		// Repoint WordPress at the new file, then rebuild the subsizes as WebP.
		update_attached_file( $attachment_id, $target );

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$after = wp_generate_attachment_metadata( $attachment_id, $target );

		if ( ! is_array( $after ) ) {
			$after = array();
		}

		wp_update_attachment_metadata( $attachment_id, $after );

		Logger::info(
			'metadata',
			'Regenerated subsizes.',
			$attachment_id,
			array(
				'before' => isset( $before['sizes'] ) && is_array( $before['sizes'] ) ? count( $before['sizes'] ) : 0,
				'after'  => isset( $after['sizes'] ) && is_array( $after['sizes'] ) ? count( $after['sizes'] ) : 0,
			)
		);

		wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => 'image/webp',
				'guid'           => wp_get_attachment_url( $attachment_id ),
			)
		);

		$uploads  = wp_get_upload_dir();
		$base_url = untrailingslashit( $uploads['baseurl'] ) . '/' . ltrim( str_replace( untrailingslashit( $uploads['basedir'] ), '', dirname( $target ) ), '/' );

		$url_map = UrlMap::build( $before, $after, $base_url );
		$url_map = $this->drop_foreign_filenames( $url_map, $attachment_id );

		if ( ! $defer_rewrite && StoreSettings::get( 'rewrite_urls' ) ) {
			$report = $this->rewriter->replace( $url_map );

			Logger::info(
				'rewrite',
				'Repointed references.',
				$attachment_id,
				array(
					'values' => (int) $report['replacements'],
					'rows'   => (int) $report['rows'],
					'unsafe' => (int) $report['skipped'],
				)
			);
		} elseif ( $defer_rewrite ) {
			Logger::info( 'rewrite', 'Deferred to the end of the batch.', $attachment_id, array( 'pairs' => count( $url_map ) ) );
		}

		// Only now remove the old files; a failure above leaves them intact.
		$this->delete_files( $old_files, $target, $after, $attachment_id );

		$manifest = BackupManager::encode_manifest( $backup, $attachment_id );

		OptimizationLog::upsert(
			$attachment_id,
			array(
				'status'         => OptimizationLog::STATUS_OPTIMIZED,
				'original_file'  => wp_basename( $file ),
				'original_size'  => $optimized['original_size'],
				'original_mime'  => $mime,
				'optimized_file' => wp_basename( $target ),
				'optimized_size' => $optimized['optimized_size'],
				'backup_path'    => $manifest,
				'backup_expires' => '' !== $manifest ? $backup['expires'] : 0,
				'url_map'        => $url_map,
				'engine'         => $optimized['engine'],
				'conversion_ms'  => isset( $optimized['duration_ms'] ) ? (int) $optimized['duration_ms'] : 0,
				'reason'         => '',
			)
		);

		// Indexed lookup rows for the 404 fallback, so a request for an old
		// filename is a key hit rather than a scan.
		UrlLookup::remember( $attachment_id, $url_map );

		Logger::info(
			'done',
			'Converted ' . wp_basename( $file ) . ' to ' . wp_basename( $target ),
			$attachment_id,
			array(
				'before' => $optimized['original_size'],
				'after'  => $optimized['optimized_size'],
				'saved'  => max( 0, $optimized['original_size'] - $optimized['optimized_size'] ),
				'engine' => $optimized['engine'],
			)
		);

		return array(
			'url_map'        => $url_map,
			'original_size'  => $optimized['original_size'],
			'optimized_size' => $optimized['optimized_size'],
		);
	}

	/**
	 * Put an attachment back the way it was.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|WP_Error {
	 *     @type int $ambiguous References that could not be reversed and were
	 *                          left pointing at the full-size image.
	 * }
	 */
	public function restore( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$row           = OptimizationLog::find( $attachment_id );

		if ( ! Logger::run_id() ) {
			Logger::start_run( 'restore' );
		}

		if ( ! $row || OptimizationLog::STATUS_OPTIMIZED !== $row['status'] ) {
			$missing = new WP_Error( 'nothing-to-restore', __( 'This image has not been optimized by this plugin.', 'swift-image-optimizer' ) );

			Logger::wp_error( 'restore', $missing, $attachment_id, true );

			return $missing;
		}

		$backup = json_decode( (string) $row['backup_path'], true );

		if ( ! is_array( $backup ) || empty( $backup['relative_dir'] ) ) {
			$expired = new WP_Error( 'backup-expired', __( 'The backup for this image is no longer available.', 'swift-image-optimizer' ) );

			Logger::wp_error( 'restore', $expired, $attachment_id, true );

			return $expired;
		}

		$files = isset( $backup['files'] ) && is_array( $backup['files'] ) ? $backup['files'] : array();

		$restored = BackupManager::restore( $attachment_id, $backup['relative_dir'], $files );

		if ( is_wp_error( $restored ) ) {
			Logger::wp_error( 'restore', $restored, $attachment_id );
			return $restored;
		}

		Logger::info( 'restore', 'Copied originals back from the backup.', $attachment_id, array( 'files' => count( $files ) ) );

		$current = get_attached_file( $attachment_id );
		$meta    = wp_get_attachment_metadata( $attachment_id );
		$webp    = $this->existing_files( $current, is_array( $meta ) ? $meta : array() );

		// Reverse the rewrite before the files disappear, so a failure here
		// still leaves a working site.
		$dropped = 0;

		if ( ! empty( $row['url_map'] ) && is_array( $row['url_map'] ) ) {
			$reverse = $this->reverse_map( $row['url_map'], $dropped );

			if ( $dropped > 0 ) {
				Logger::warn(
					'restore',
					'Some references cannot be reversed and were left pointing at the full-size image.',
					$attachment_id,
					array( 'ambiguous' => $dropped )
				);
			}

			if ( $reverse && StoreSettings::get( 'rewrite_urls' ) ) {
				$this->rewriter->replace( $reverse );
			}
		}

		$uploads  = wp_get_upload_dir();
		$original = trailingslashit( trailingslashit( $uploads['basedir'] ) . $backup['relative_dir'] ) . $row['original_file'];

		update_attached_file( $attachment_id, $original );

		require_once ABSPATH . 'wp-admin/includes/image.php';

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $original ) );

		wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => $row['original_mime'] ? $row['original_mime'] : 'image/jpeg',
				'guid'           => wp_get_attachment_url( $attachment_id ),
			)
		);

		// Remove the WebP files that are no longer referenced.
		foreach ( $webp as $path ) {
			if ( file_exists( $path ) && $path !== $original ) {
				wp_delete_file( $path );

				Logger::info( 'delete', 'Removed WebP after restore: ' . $path, $attachment_id );
			}
		}

		BackupManager::delete( $backup['relative_dir'], $files );

		// The old URLs are live again, so the fallback must stop redirecting
		// them at a WebP that no longer exists.
		UrlLookup::forget( $attachment_id );

		Logger::info( 'restore', 'Restored to ' . wp_basename( $original ), $attachment_id );

		OptimizationLog::upsert(
			$attachment_id,
			array(
				'status'        => OptimizationLog::STATUS_RESTORED,
				'original_file' => $row['original_file'],
				'original_mime' => $row['original_mime'],
				'reason'        => 'restored',
			)
		);

		return array( 'ambiguous' => $dropped );
	}

	/**
	 * Remove pairs whose old filename belongs to a different attachment.
	 *
	 * Thumbnails are named after their source, so an attachment uploaded as
	 * "photo-300x200.jpg" collides exactly with the 300x200 thumbnail of an
	 * attachment named "photo.jpg" in the same month folder. Rewriting on
	 * filename alone would then repoint references to somebody else's image at
	 * this conversion's WebP - a broken picture in a post that was never part
	 * of this operation.
	 *
	 * One indexed lookup per conversion against _wp_attached_file settles it:
	 * if another attachment owns that exact path as its main file, the pair is
	 * dropped and its references are left alone.
	 *
	 * @param array $map           Old URL to new URL.
	 * @param int   $attachment_id The attachment being converted.
	 * @return array The map, minus any pair that is not ours to rewrite.
	 */
	private function drop_foreign_filenames( array $map, $attachment_id ) {
		global $wpdb;

		if ( empty( $map ) ) {
			return $map;
		}

		$uploads  = wp_get_upload_dir();
		$baseurl  = untrailingslashit( $uploads['baseurl'] );
		$relative = array();

		foreach ( array_keys( $map ) as $old ) {
			// _wp_attached_file stores the uploads-relative path, so the URL
			// has to be reduced to the same shape before it can be compared.
			$path = wp_parse_url( $old, PHP_URL_PATH );

			if ( ! $path ) {
				continue;
			}

			$base = wp_parse_url( $baseurl, PHP_URL_PATH );

			if ( $base && 0 === strpos( $path, $base ) ) {
				// UrlMap emits an absolute, a protocol-relative and a
				// root-relative form of every file, and all three reduce to
				// the same path - so each one maps to a list, not a single URL.
				$relative[ ltrim( substr( $path, strlen( $base ) ), '/' ) ][] = $old;
			}
		}

		if ( empty( $relative ) ) {
			return $map;
		}

		$paths        = array_keys( $relative );
		$placeholders = implode( ', ', array_fill( 0, count( $paths ), '%s' ) );
		$params       = array_merge( $paths, array( (int) $attachment_id ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a core identifier and cannot be bound; $placeholders is a generated %s list and every value goes through prepare() in $params.
		$owned = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta}
				WHERE meta_key = '_wp_attached_file'
					AND meta_value IN ( {$placeholders} )
					AND post_id != %d",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $owned ) ) {
			return $map;
		}

		foreach ( $owned as $path ) {
			if ( ! isset( $relative[ $path ] ) ) {
				continue;
			}

			Logger::warn(
				'rewrite',
				'Left ' . wp_basename( $path ) . ' alone: another image in the library owns that filename.',
				$attachment_id
			);

			foreach ( $relative[ $path ] as $url ) {
				unset( $map[ $url ] );
			}
		}

		return $map;
	}

	/**
	 * Invert a URL map, refusing the pairs that have no single inverse.
	 *
	 * The forward map is not always injective. When a size fails to
	 * regenerate, UrlMap deliberately points it at the full-size file so the
	 * reference still resolves - which means several old URLs can share one
	 * new URL. Inverting that with array_flip() silently keeps whichever pair
	 * came last, so a reference to the full-size image would be rewritten to a
	 * thumbnail filename and the restore would report success while quietly
	 * degrading the page.
	 *
	 * Those pairs are dropped instead. The reference is left pointing at the
	 * full-size image, which is wrong in dimension but right in content.
	 *
	 * @param array $map     Forward map, old URL to new URL.
	 * @param int   $dropped Set to the number of pairs that could not be inverted.
	 * @return array<string, string> Reverse map.
	 */
	private function reverse_map( array $map, &$dropped = 0 ) {
		$dropped = 0;

		$targets = array_count_values( array_map( 'strval', array_values( $map ) ) );
		$reverse = array();

		foreach ( $map as $old => $new ) {
			if ( isset( $targets[ (string) $new ] ) && $targets[ (string) $new ] > 1 ) {
				++$dropped;
				continue;
			}

			$reverse[ $new ] = $old;
		}

		// Longest first, for the same reason the forward map is ordered that
		// way: a shorter filename must never partially match a longer one.
		uksort(
			$reverse,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		return $reverse;
	}

	/**
	 * Every file on disk belonging to an attachment, given its metadata.
	 *
	 * @param string $main Absolute path to the main file.
	 * @param array  $meta Attachment metadata.
	 * @return string[] Absolute paths.
	 */
	private function existing_files( $main, array $meta ) {
		$files = array( $main );
		$dir   = trailingslashit( dirname( $main ) );

		if ( ! empty( $meta['original_image'] ) ) {
			$files[] = $dir . $meta['original_image'];
		}

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . $size['file'];
				}
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Delete superseded files, never touching one the new metadata still uses.
	 *
	 * @param string[] $old_files     Absolute paths from before the conversion.
	 * @param string   $target        The new main file.
	 * @param array    $after         The new metadata.
	 * @param int      $attachment_id Attachment ID, for the log.
	 * @return void
	 */
	private function delete_files( array $old_files, $target, array $after, $attachment_id = 0 ) {
		$keep = array_flip( $this->existing_files( $target, $after ) );

		foreach ( $old_files as $path ) {
			if ( isset( $keep[ $path ] ) ) {
				continue;
			}

			if ( file_exists( $path ) ) {
				wp_delete_file( $path );

				// Logged by absolute path: "which file disappeared" is the
				// question this log exists to answer.
				Logger::info( 'delete', 'Removed superseded file: ' . $path, $attachment_id );
			}
		}
	}

	/**
	 * Record a skip.
	 *
	 * @param int       $attachment_id Attachment ID.
	 * @param string    $file          Absolute path.
	 * @param string    $mime          Mime type.
	 * @param \WP_Error $error         Reason.
	 * @return void
	 */
	private function log_skip( $attachment_id, $file, $mime, $error ) {
		OptimizationLog::upsert(
			$attachment_id,
			array(
				'status'        => OptimizationLog::STATUS_SKIPPED,
				'original_file' => $file ? wp_basename( $file ) : '',
				'original_size' => $file && file_exists( $file ) ? (int) filesize( $file ) : 0,
				'original_mime' => $mime,
				'reason'        => $error->get_error_code(),
			)
		);
	}

	/**
	 * Record a failure.
	 *
	 * @param int       $attachment_id Attachment ID.
	 * @param \WP_Error $error         Reason.
	 * @return void
	 */
	private function log_failure( $attachment_id, $error ) {
		if ( self::is_soft_error( $error->get_error_code() ) ) {
			return;
		}

		Logger::wp_error( 'failed', $error, $attachment_id );

		$file = get_attached_file( $attachment_id );

		OptimizationLog::upsert(
			$attachment_id,
			array(
				'status'        => OptimizationLog::STATUS_FAILED,
				'original_file' => $file ? wp_basename( $file ) : '',
				'original_mime' => get_post_mime_type( $attachment_id ),
				'reason'        => $error->get_error_code(),
			)
		);
	}
}
