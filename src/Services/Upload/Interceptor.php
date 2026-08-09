<?php
/**
 * Feature 1: convert images to WebP during upload.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Services\Upload;

use SwiftImageOptimizer\Database\Database;
use SwiftImageOptimizer\Repositories\SettingsRepository;
use SwiftImageOptimizer\Services\Backup\BackupManager;
use SwiftImageOptimizer\Services\Logging\Logger;
use SwiftImageOptimizer\Services\Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts uploads before WordPress creates the attachment.
 *
 * Running at wp_handle_upload time means the attachment is born as a WebP, so
 * WordPress generates every subsize as WebP on its own and no URL anywhere
 * needs rewriting. This is what makes the upload path far simpler than the
 * bulk path.
 */
class Interceptor {

	/**
	 * Results awaiting an attachment ID, keyed by absolute file path.
	 *
	 * wp_handle_upload runs before wp_insert_attachment in the same request, so
	 * results are parked here and bound on add_attachment.
	 *
	 * @var array<string, array>
	 */
	private $pending = array();

	/**
	 * Optimizer instance.
	 *
	 * @var Optimizer
	 */
	private $optimizer;

	/**
	 * Constructor.
	 *
	 * @param Optimizer $optimizer Optimizer instance.
	 */
	public function __construct( Optimizer $optimizer ) {
		$this->optimizer = $optimizer;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_handle_upload', array( $this, 'handle_upload' ), 10, 2 );
		add_filter( 'upload_mimes', array( $this, 'allow_webp' ) );
		add_filter( 'big_image_size_threshold', array( $this, 'maybe_disable_scaling' ) );
		add_action( 'add_attachment', array( $this, 'bind_attachment' ) );
	}

	/**
	 * Ensure WebP uploads are permitted.
	 *
	 * @param array $mimes Allowed mime types keyed by extension pattern.
	 * @return array
	 */
	public function allow_webp( $mimes ) {
		if ( ! isset( $mimes['webp'] ) ) {
			$mimes['webp'] = 'image/webp';
		}

		return $mimes;
	}

	/**
	 * Honor the "disable WordPress scaling" setting.
	 *
	 * @param int $threshold Longest edge before WordPress creates a -scaled copy.
	 * @return int|false
	 */
	public function maybe_disable_scaling( $threshold ) {
		return SettingsRepository::get( 'disable_wp_scaling' ) ? false : $threshold;
	}

	/**
	 * Convert the freshly uploaded file to WebP.
	 *
	 * @param array  $upload  Keys: file, url, type.
	 * @param string $context Either 'upload' or 'sideload'.
	 * @return array The upload array, pointing at the WebP when conversion succeeded.
	 */
	public function handle_upload( $upload, $context = 'upload' ) {
		unset( $context );

		if ( ! SettingsRepository::get( 'auto_optimize' ) ) {
			return $upload;
		}

		if ( empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return $upload;
		}

		/**
		 * Filters whether a given upload should be optimized.
		 *
		 * @param bool  $should Whether to optimize.
		 * @param array $upload The upload array.
		 */
		if ( ! apply_filters( 'swift_image_optimizer_should_optimize_upload', true, $upload ) ) {
			return $upload;
		}

		Logger::start_run( 'upload' );
		Logger::info( 'upload', 'Received ' . wp_basename( $upload['file'] ), 0, array( 'mime' => $upload['type'] ) );

		$result = $this->optimizer->optimize( $upload['file'], $upload['type'] );

		if ( is_wp_error( $result ) ) {
			$this->park_skip( $upload, $result );
			return $upload;
		}

		// The source is about to be deleted, so preserve it first. Without
		// this an upload is a one-way door: the original is replaced by a
		// lossy, possibly downscaled WebP with nothing to go back to.
		$backup = array();

		if ( SettingsRepository::get( 'backup_uploads' ) ) {
			$backup = BackupManager::backup_file( $upload['file'] );

			if ( is_wp_error( $backup ) ) {
				// Fail closed. Keeping an unconvertible original is always
				// better than an irreversible conversion the user did not
				// agree to.
				wp_delete_file( $result['temp_file'] );

				Logger::wp_error( 'backup', $backup );
				Logger::warn( 'upload', 'Conversion abandoned so the original is not lost.' );

				$this->park_skip( $upload, $backup );

				return $upload;
			}
		}

		$target = $this->optimizer->target_path( $upload['file'] );

		// Move the converted file into its final place.
		if ( ! @rename( $result['temp_file'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is handled by keeping the original.
			wp_delete_file( $result['temp_file'] );

			if ( ! empty( $backup['relative_dir'] ) ) {
				BackupManager::delete( $backup['relative_dir'], $backup['files'] );
			}

			Logger::error( 'rename', 'move-failed: could not move the WebP to ' . $target . '. The original was kept.' );

			return $upload;
		}

		Logger::info( 'rename', 'Moved into place: ' . $target );

		$original_file = $upload['file'];
		$original_mime = $upload['type'];

		// Point WordPress at the WebP, then drop the source.
		$upload['file'] = $target;
		$upload['url']  = dirname( $upload['url'] ) . '/' . wp_basename( $target );
		$upload['type'] = 'image/webp';

		wp_delete_file( $original_file );

		Logger::info( 'delete', 'Removed the uploaded source: ' . $original_file );

		$this->pending[ $target ] = array(
			'status'         => Database::STATUS_OPTIMIZED,
			'original_file'  => wp_basename( $original_file ),
			'original_size'  => $result['original_size'],
			'original_mime'  => $original_mime,
			'optimized_file' => wp_basename( $target ),
			'optimized_size' => $result['optimized_size'],
			'engine'         => $result['engine'],
			'conversion_ms'  => isset( $result['duration_ms'] ) ? (int) $result['duration_ms'] : 0,
			// Written in the same shape the converter path uses, which is what
			// makes Restore work for uploads without a line of restore code.
			'backup_path'    => $backup ? wp_json_encode( $backup ) : '',
			'backup_expires' => $backup ? $backup['expires'] : 0,
			'reason'         => '',
		);

		return $upload;
	}

	/**
	 * Record a skipped upload so the media library can explain itself.
	 *
	 * Formats we never convert are not worth a row; only genuine skips are kept.
	 *
	 * @param array     $upload The upload array.
	 * @param \WP_Error $error  Why the conversion did not happen.
	 * @return void
	 */
	private function park_skip( $upload, $error ) {
		$code = $error->get_error_code();

		$noise = array( 'unsupported-format', 'already-webp', 'png-disabled' );

		if ( in_array( $code, $noise, true ) ) {
			return;
		}

		$this->pending[ $upload['file'] ] = array(
			'status'         => Database::STATUS_SKIPPED,
			'original_file'  => wp_basename( $upload['file'] ),
			'original_size'  => file_exists( $upload['file'] ) ? (int) filesize( $upload['file'] ) : 0,
			'original_mime'  => $upload['type'],
			'optimized_file' => '',
			'optimized_size' => 0,
			'engine'         => '',
			'reason'         => $code,
		);
	}

	/**
	 * Attach a parked result to the attachment WordPress just created.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function bind_attachment( $attachment_id ) {
		if ( empty( $this->pending ) ) {
			return;
		}

		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! isset( $this->pending[ $file ] ) ) {
			return;
		}

		$data = $this->pending[ $file ];
		unset( $this->pending[ $file ] );

		Database::upsert( $attachment_id, $data );

		Logger::info( 'done', 'Bound to attachment.', $attachment_id, array( 'status' => $data['status'] ) );
	}
}
