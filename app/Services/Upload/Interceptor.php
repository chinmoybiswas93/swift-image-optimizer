<?php
/**
 * Feature 1: convert images to WebP during upload.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Services\Upload;

use SwiftImageOptimizer\App\Models\OptimizationLog;
use SwiftImageOptimizer\Api\StoreSettings;
use SwiftImageOptimizer\App\Services\Backup\BackupManager;
use SwiftImageOptimizer\App\Services\Logging\Logger;
use SwiftImageOptimizer\App\Services\Optimizer;

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
	 * The wp_handle_upload hook runs before wp_insert_attachment in the same request, so
	 * results are parked here and bound on add_attachment.
	 *
	 * @var array<string, array>
	 */
	private $pending = array();

	/**
	 * The sideload this request is serving, or null when it is not one.
	 *
	 * WordPress 7.1's client-side media processing has the browser generate
	 * every size and POST each one to /wp/v2/media/<id>/sideload. That endpoint
	 * uploads through upload_from_file(), which calls wp_handle_upload() - so
	 * every browser-generated size reaches handle_upload() with context
	 * 'upload', indistinguishable from a first-party upload by context alone.
	 * The route is the only thing that tells them apart, so it is captured
	 * before the endpoint's callback runs.
	 *
	 * @var array{attachment_id: int, image_size: string}|null
	 */
	private $sideload = null;

	/**
	 * Sizes whose file must never be converted.
	 *
	 * `source_original` is the deliberately preserved source-format original -
	 * the HEIC or JXL kept beside its JPEG derivative - which core stores under
	 * its own meta key and never attaches. Converting it would destroy the one
	 * copy whose entire purpose is to stay in its original format. The two
	 * animated companions are an MP4/WebM and its poster frame.
	 *
	 * @var string[]
	 */
	const UNCONVERTIBLE_SIZES = array( 'source_original', 'animated_video', 'animated_video_poster' );

	/**
	 * Sizes that replace the attachment's main file.
	 *
	 * These are the only two `image_size` values for which the sideload
	 * endpoint calls update_attached_file(), so they are the only two that can
	 * leave the log row describing a file that is no longer attached.
	 *
	 * @var string[]
	 */
	const ATTACHING_SIZES = array( 'scaled', 'original' );

	/**
	 * Optimizer instance.
	 *
	 * @var Optimizer
	 */
	private $optimizer;

	/**
	 * Wire up the optimizer used to convert images as they are uploaded.
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

		// Runs after the route has matched and the parameters are resolved,
		// but before the endpoint's callback - so the sideload is known by the
		// time wp_handle_upload fires inside it.
		add_filter( 'rest_request_before_callbacks', array( $this, 'note_sideload' ), 10, 3 );
	}

	/**
	 * Record that this request is a client-side-media-processing sideload.
	 *
	 * @param mixed            $response Untouched; this is an observer, not a filter.
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  The request being dispatched.
	 * @return mixed The response, unchanged.
	 */
	public function note_sideload( $response, $handler, $request ) {
		unset( $handler );

		if ( ! $request instanceof \WP_REST_Request ) {
			return $response;
		}

		if ( ! preg_match( '#^/wp/v2/media/(\d+)/sideload$#', (string) $request->get_route(), $matches ) ) {
			return $response;
		}

		$size = $request['image_size'];

		/*
		 * One file can be registered under several size names at once. Any of
		 * them being an attaching size is what matters, so the array is
		 * flattened to the first attaching name if there is one, and otherwise
		 * to the first name at all.
		 */
		$names = array_values( array_filter( array_map( 'strval', (array) $size ) ) );
		$found = array_values( array_intersect( self::ATTACHING_SIZES, $names ) );

		$this->sideload = array(
			'attachment_id' => (int) $matches[1],
			'image_size'    => $found ? $found[0] : ( $names ? $names[0] : '' ),
		);

		return $response;
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
		return StoreSettings::get( 'disable_wp_scaling' ) ? false : $threshold;
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

		if ( ! StoreSettings::get( 'auto_optimize' ) ) {
			return $upload;
		}

		if ( empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return $upload;
		}

		// A browser-generated size arriving at the sideload endpoint is part of
		// an attachment that already exists, not a new upload.
		if ( null !== $this->sideload ) {
			return $this->handle_sideload( $upload );
		}

		/*
		 * wp_handle_upload can fire for a file that already belongs to an
		 * existing attachment - its full-size original, or one of its
		 * registered subsizes - via a sideload, or a re-submission of a
		 * derivative file already sitting in the uploads folder. Converting
		 * such a file is destructive without being useful: the result can
		 * never match a *new* $pending entry in bind_attachment() (no
		 * add_attachment fires for a file that isn't a brand-new upload), so
		 * the rename would go through while post_mime_type and the log row
		 * for that existing attachment are silently left describing the
		 * file that used to be there. Refusing here is what keeps the disk
		 * and the database in agreement.
		 */
		if ( $this->already_belongs_to_an_attachment( $upload ) ) {
			Logger::info(
				'upload',
				'Skipped: this file already belongs to an existing attachment.',
				0,
				array( 'file' => wp_basename( $upload['file'] ) )
			);

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

		if ( StoreSettings::get( 'backup_uploads' ) ) {
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
		if ( ! @rename( $result['temp_file'], $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- Atomic move of the plugin's own temp file into a path it resolved; WP_Filesystem::move() is neither atomic nor credential-free. Failure is handled by keeping the original.
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

		$manifest = $backup ? BackupManager::encode_manifest( $backup ) : '';

		$this->pending[ $target ] = array(
			'status'         => OptimizationLog::STATUS_OPTIMIZED,
			'original_file'  => wp_basename( $original_file ),
			'original_size'  => $result['original_size'],
			'original_mime'  => $original_mime,
			'optimized_file' => wp_basename( $target ),
			'optimized_size' => $result['optimized_size'],
			'engine'         => $result['engine'],
			'conversion_ms'  => isset( $result['duration_ms'] ) ? (int) $result['duration_ms'] : 0,
			// Written in the same shape the converter path uses, which is what
			// makes Restore work for uploads without a line of restore code.
			'backup_path'    => $manifest,
			'backup_expires' => '' !== $manifest ? $backup['expires'] : 0,
			'reason'         => '',
		);

		return $upload;
	}

	/**
	 * Convert one browser-generated size arriving at the sideload endpoint.
	 *
	 * Under WordPress 7.1's client-side media processing the browser produces
	 * every size itself and POSTs each to /wp/v2/media/<id>/sideload. Those
	 * files are converted so the attachment does not end up a WebP full-size
	 * with JPEG subsizes, but they are emphatically *not* new uploads:
	 *
	 * - No backup. The true original was preserved when the first POST to
	 *   /wp/v2/media came through handle_upload(); a registered subsize is
	 *   regenerable, and backing each one up wrote seven redundant copies per
	 *   image and inflated the manifest for nothing.
	 * - No $pending entry. No add_attachment fires for a sideload, so
	 *   bind_attachment() could never bind one - parking it only leaks.
	 *
	 * @param array $upload Keys: file, url, type.
	 * @return array The upload array, pointing at the WebP when conversion succeeded.
	 */
	private function handle_sideload( array $upload ) {
		$attachment_id = (int) $this->sideload['attachment_id'];
		$image_size    = (string) $this->sideload['image_size'];

		if ( in_array( $image_size, self::UNCONVERTIBLE_SIZES, true ) ) {
			return $upload;
		}

		/** This filter is documented in app/Services/Upload/Interceptor.php */
		if ( ! apply_filters( 'swift_image_optimizer_should_optimize_upload', true, $upload ) ) {
			return $upload;
		}

		Logger::start_run( 'upload' );
		Logger::info(
			'upload',
			'Received sideloaded ' . wp_basename( $upload['file'] ),
			$attachment_id,
			array(
				'mime' => $upload['type'],
				'size' => $image_size,
			)
		);

		$result = $this->optimizer->optimize( $upload['file'], $upload['type'], array(), $attachment_id );

		if ( is_wp_error( $result ) ) {
			// Nothing is parked: the parent's row already describes this
			// attachment, and a subsize that stays in its source format is a
			// cosmetic loss rather than something to report against the image.
			return $upload;
		}

		$target = $this->optimizer->target_path( $upload['file'] );

		if ( ! @rename( $result['temp_file'], $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- Atomic move of the plugin's own temp file into a path it resolved; WP_Filesystem::move() is neither atomic nor credential-free. Failure is handled by keeping the original.
			wp_delete_file( $result['temp_file'] );

			Logger::error( 'rename', 'move-failed: could not move the WebP to ' . $target . '. The original was kept.', $attachment_id );

			return $upload;
		}

		$original_file = $upload['file'];

		$upload['file'] = $target;
		$upload['url']  = dirname( $upload['url'] ) . '/' . wp_basename( $target );
		$upload['type'] = 'image/webp';

		wp_delete_file( $original_file );

		Logger::info( 'rename', 'Sideloaded size converted: ' . wp_basename( $target ), $attachment_id );

		/*
		 * `scaled` and `original` are the only sizes the endpoint calls
		 * update_attached_file() for, so they are the only ones that can leave
		 * the row describing a file that is no longer attached. Settling it
		 * here - in the same request, from the same path core is about to
		 * attach - is what keeps optimized_output_exists() true, and with it
		 * the status the media modal and the block editor panel both read.
		 */
		if ( in_array( $image_size, self::ATTACHING_SIZES, true ) ) {
			$this->settle_attached_output( $attachment_id, $target );
		}

		return $upload;
	}

	/**
	 * Repoint an attachment's log row at the file that is about to be attached.
	 *
	 * Only the output half is rewritten. `original_size` still describes the
	 * file the user actually uploaded, which is what the savings figure has to
	 * compare against.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $target        Absolute path to the converted file.
	 * @return void
	 */
	private function settle_attached_output( $attachment_id, $target ) {
		$row = OptimizationLog::find( $attachment_id );

		if ( ! $row || OptimizationLog::STATUS_OPTIMIZED !== $row['status'] ) {
			return;
		}

		$basename = wp_basename( $target );

		if ( $basename === $row['optimized_file'] ) {
			return;
		}

		OptimizationLog::update(
			$attachment_id,
			array(
				'optimized_file' => $basename,
				'optimized_size' => (int) filesize( $target ),
			)
		);

		Logger::info(
			'done',
			'Row repointed at the sideloaded file WordPress attached: ' . $basename,
			$attachment_id
		);
	}

	/**
	 * Whether this "upload" is actually a file that already belongs to an
	 * existing attachment - its full-size original, or one of its
	 * registered subsizes.
	 *
	 * Chosen over restricting by upload context ('upload' vs 'sideload'):
	 * the failure this guards against - a leftover derivative file getting
	 * fed back through wp_handle_upload - showed up under genuine 'upload'
	 * context too, so a context check alone would not have caught it.
	 *
	 * @param array $upload Keys: file, url, type.
	 * @return bool
	 */
	private function already_belongs_to_an_attachment( array $upload ) {
		if ( empty( $upload['url'] ) ) {
			return false;
		}

		// Exact match: this is already some attachment's full-size file.
		if ( attachment_url_to_postid( $upload['url'] ) ) {
			return true;
		}

		/*
		 * Pattern match: WordPress names subsizes/derivatives by suffixing
		 * the original's basename ("-300x200", "-scaled", "-rotated"). If
		 * stripping a suffix like that resolves to an existing attachment,
		 * this is one of its derivatives being re-fed, not a new upload.
		 *
		 * Resolving by name is the whole test. Confirming it against the
		 * parent's registered metadata used to be, and could never work when
		 * it mattered most: WordPress writes _wp_attachment_metadata only
		 * *after* it has generated every subsize, so during that generation
		 * the sizes array is empty and the guard waved through the very files
		 * it exists to stop - leaving them WebP on disk with post_mime_type
		 * and the log row still describing the JPEG (invariant 27).
		 *
		 * Fails closed on purpose. A false positive costs one unconverted
		 * derivative that the next bulk run picks up; a false negative costs
		 * a renamed file whose database row no longer describes it.
		 */
		$basename  = wp_basename( $upload['file'] );
		$extension = pathinfo( $basename, PATHINFO_EXTENSION );
		$name      = $extension ? substr( $basename, 0, - ( strlen( $extension ) + 1 ) ) : $basename;

		if ( ! preg_match( '/^(.+)-(?:\d+x\d+|scaled|rotated)$/', $name, $matches ) ) {
			return false;
		}

		/*
		 * Try the converted extension as well as the incoming one. A derivative
		 * arrives named after its parent's *source* file (photo-scaled.jpg),
		 * but once this plugin has converted that parent its _wp_attached_file
		 * reads photo.webp - so nothing claims the .jpg URL and looking only
		 * for it never matched. That is the hole the client-side sideloads fell
		 * through, and it stays open for any other re-feed.
		 */
		$extensions = array_unique( array( $extension, 'webp' ) );

		foreach ( $extensions as $candidate_extension ) {
			$candidate_url = str_replace( $basename, $matches[1] . '.' . $candidate_extension, $upload['url'] );

			if ( attachment_url_to_postid( $candidate_url ) ) {
				return true;
			}
		}

		return false;
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
			'status'         => OptimizationLog::STATUS_SKIPPED,
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

		OptimizationLog::upsert( $attachment_id, $data );

		Logger::info( 'done', 'Bound to attachment.', $attachment_id, array( 'status' => $data['status'] ) );
	}
}
