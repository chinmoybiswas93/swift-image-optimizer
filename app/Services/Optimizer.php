<?php
/**
 * Core single-image optimization routine.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Services;

use SwiftImageOptimizer\App\Repositories\SettingsRepository;
use SwiftImageOptimizer\App\Services\Engine\EngineFactory;
use SwiftImageOptimizer\App\Services\Logging\Logger;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Takes one image file and produces an optimized WebP beside it.
 *
 * This class is deliberately unaware of attachments, the database and URLs. It
 * deals only in files, so the upload path and the bulk path can both use it.
 */
class Optimizer {

	/**
	 * Source mime types eligible for conversion.
	 *
	 * @var string[]
	 */
	const CONVERTIBLE = array( 'image/jpeg', 'image/png' );

	/**
	 * Scratch directory inside uploads, for conversions in progress.
	 */
	const TEMP_DIRNAME = 'swift-image-optimizer-tmp';

	/**
	 * Decide whether a file should be converted at all.
	 *
	 * @param string $file Absolute path to the image.
	 * @param string $mime Mime type.
	 * @return true|WP_Error True when eligible, WP_Error carrying a machine-readable reason otherwise.
	 */
	public function can_optimize( $file, $mime ) {
		if ( ! $file || ! file_exists( $file ) || ! is_readable( $file ) ) {
			return new WP_Error( 'missing-file', __( 'The file does not exist or is not readable.', 'swift-image-optimizer' ) );
		}

		if ( 'image/webp' === $mime ) {
			return new WP_Error( 'already-webp', __( 'The image is already a WebP.', 'swift-image-optimizer' ) );
		}

		if ( ! in_array( $mime, self::CONVERTIBLE, true ) ) {
			return new WP_Error( 'unsupported-format', __( 'This image format is not converted.', 'swift-image-optimizer' ) );
		}

		if ( 'image/png' === $mime && ! SettingsRepository::get( 'convert_png' ) ) {
			return new WP_Error( 'png-disabled', __( 'PNG conversion is disabled in settings.', 'swift-image-optimizer' ) );
		}

		if ( 'image/png' === $mime && $this->is_animated_png( $file ) ) {
			return new WP_Error( 'animated-image', __( 'Animated images are left alone, because converting one would keep only its first frame.', 'swift-image-optimizer' ) );
		}

		if ( ! EngineFactory::chain() ) {
			return new WP_Error( 'no-engine', __( 'No image conversion engine is available on this server.', 'swift-image-optimizer' ) );
		}

		$engines = EngineFactory::for_file( $file, $mime );

		if ( ! $engines ) {
			return new WP_Error( 'engine-unsupported', __( 'No engine on this server can convert this image correctly.', 'swift-image-optimizer' ) );
		}

		// The memory estimate models an in-process decode. It says nothing
		// about an engine that shells out to a separate binary, and applying
		// it to one would refuse images that engine handles comfortably.
		if ( $engines[0]->decodes_in_process() && ! $this->has_memory_for( $file ) ) {
			return new WP_Error( 'insufficient-memory', __( 'The image is too large to process within the available memory limit.', 'swift-image-optimizer' ) );
		}

		return true;
	}

	/**
	 * Convert a file to WebP.
	 *
	 * The source file is left untouched; the caller decides whether to replace
	 * it. On success the returned array carries the temporary WebP path.
	 *
	 * @param string $file          Absolute path to the source image.
	 * @param string $mime          Source mime type.
	 * @param array  $args          Optional overrides for quality and max_dimension.
	 * @param int    $attachment_id Attachment ID when there is one, for log correlation only.
	 * @return array|WP_Error {
	 *     @type string $temp_file      Absolute path to the generated WebP.
	 *     @type int    $original_size  Source size in bytes.
	 *     @type int    $optimized_size Result size in bytes.
	 *     @type string $engine         Engine identifier used.
	 *     @type int    $duration_ms    Encode time in milliseconds.
	 * }
	 */
	public function optimize( $file, $mime, array $args = array(), $attachment_id = 0 ) {
		$eligible = $this->can_optimize( $file, $mime );

		if ( is_wp_error( $eligible ) ) {
			Logger::wp_error( 'eligibility', $eligible, $attachment_id, true );
			return $eligible;
		}

		$engines = EngineFactory::for_file( $file, $mime );

		$options = wp_parse_args(
			$args,
			array(
				'quality'       => (int) SettingsRepository::get( 'quality', 82 ),
				'max_dimension' => $this->effective_max_dimension(),
			)
		);

		Logger::info(
			'eligibility',
			'Eligible for conversion: ' . wp_basename( $file ),
			$attachment_id,
			array(
				'mime'    => $mime,
				'engine'  => $engines[0]->name(),
				'quality' => (int) $options['quality'],
				'maxdim'  => (int) $options['max_dimension'],
			)
		);

		wp_raise_memory_limit( 'image' );

		$temp = $this->temp_path( $file );

		if ( is_wp_error( $temp ) ) {
			Logger::wp_error( 'encode', $temp, $attachment_id );
			return $temp;
		}

		$started   = microtime( true );
		$converted = null;
		$engine    = null;

		// Walk the chain. A format an engine claims to read can still defeat
		// it - a CMYK JPEG stops GD dead - and another installed engine
		// usually handles the same file without complaint.
		foreach ( $engines as $candidate ) {
			$engine    = $candidate;
			$converted = $candidate->convert( $file, $temp, $options );

			if ( ! is_wp_error( $converted ) ) {
				break;
			}

			$this->cleanup( $temp );

			Logger::warn(
				'encode',
				$candidate->name() . ' could not convert this image: ' . $converted->get_error_message(),
				$attachment_id
			);
		}

		$duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $converted ) ) {
			$this->cleanup( $temp );
			Logger::wp_error( 'encode', $converted, $attachment_id );
			return $converted;
		}

		$original_size  = (int) filesize( $file );
		$optimized_size = (int) filesize( $temp );

		if ( SettingsRepository::get( 'skip_if_larger' ) && $optimized_size >= $original_size ) {
			$this->cleanup( $temp );

			$larger = new WP_Error(
				'skipped-larger',
				__( 'The converted image was not smaller than the original, so the original was kept.', 'swift-image-optimizer' ),
				array(
					'original_size'  => $original_size,
					'optimized_size' => $optimized_size,
				)
			);

			Logger::warn(
				'encode',
				'skipped-larger: WebP was not smaller, keeping the original.',
				$attachment_id,
				array(
					'before' => $original_size,
					'after'  => $optimized_size,
				)
			);

			return $larger;
		}

		Logger::info(
			'encode',
			'Encoded ' . wp_basename( $temp ),
			$attachment_id,
			array(
				'engine' => $engine->name(),
				'before' => $original_size,
				'after'  => $optimized_size,
				'ms'     => $duration_ms,
			)
		);

		return array(
			'temp_file'      => $temp,
			'original_size'  => $original_size,
			'optimized_size' => $optimized_size,
			'engine'         => $engine->name(),
			'duration_ms'    => $duration_ms,
		);
	}

	/**
	 * The WebP path a given source file should become.
	 *
	 * Guarantees uniqueness so an existing photo.webp is never clobbered.
	 *
	 * @param string $file Absolute path to the source image.
	 * @return string Absolute path.
	 */
	public function target_path( $file ) {
		$dir       = dirname( $file );
		$base      = wp_basename( $file );
		$extension = pathinfo( $base, PATHINFO_EXTENSION );
		$name      = $extension ? substr( $base, 0, - ( strlen( $extension ) + 1 ) ) : $base;

		$candidate = $name . '.webp';

		if ( ! file_exists( $dir . '/' . $candidate ) ) {
			return $dir . '/' . $candidate;
		}

		return $dir . '/' . wp_unique_filename( $dir, $candidate );
	}

	/**
	 * The max dimension to apply, respecting WordPress's own scaling threshold.
	 *
	 * @return int Longest allowed edge, or 0 when unconstrained.
	 */
	private function effective_max_dimension() {
		$configured = (int) SettingsRepository::get( 'max_dimension', 0 );

		if ( SettingsRepository::get( 'disable_wp_scaling' ) ) {
			return $configured;
		}

		$threshold = (int) apply_filters( 'big_image_size_threshold', 2560, array( 0, 0 ), '', 0 );

		if ( $configured > 0 && $threshold > 0 ) {
			return min( $configured, $threshold );
		}

		return $configured > 0 ? $configured : $threshold;
	}

	/**
	 * Whether enough memory remains to decode this image.
	 *
	 * A decoded truecolour image costs roughly four bytes per pixel, plus a
	 * working copy during resize. Checking up front turns the most common
	 * shared-host fatal into a logged skip.
	 *
	 * @param string $file Absolute path to the image.
	 * @return bool
	 */
	private function has_memory_for( $file ) {
		$info = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Handled by the false check.

		if ( ! $info ) {
			return false;
		}

		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

		// A limit of -1 means unlimited.
		if ( $limit <= 0 ) {
			return true;
		}

		$pixels = (int) $info[0] * (int) $info[1];

		// Source frame plus a resized working copy, with headroom for the encoder.
		$required = $pixels * 4 * 2;
		$available = $limit - memory_get_usage( true );

		/**
		 * Filters whether an image is considered safe to decode.
		 *
		 * @param bool   $safe      Whether the decode should proceed.
		 * @param int    $required  Estimated bytes needed.
		 * @param int    $available Bytes remaining under the memory limit.
		 * @param string $file      Absolute path to the image.
		 */
		return (bool) apply_filters( 'swift_image_optimizer_has_memory', $required < $available, $required, $available, $file );
	}

	/**
	 * Whether a PNG carries animation.
	 *
	 * An APNG is a normal PNG with an extra acTL chunk, so every engine reads
	 * one happily and silently writes back only its first frame. Detected by
	 * looking for that chunk before the first IDAT, which is where the spec
	 * requires it - reading a small header rather than decoding the image.
	 *
	 * @param string $file Absolute path to the image.
	 * @return bool
	 */
	private function is_animated_png( $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading the first few bytes of a header; WP_Filesystem has no partial read.
		$handle = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Handled by the false check.

		if ( ! $handle ) {
			return false;
		}

		// The acTL chunk must appear before IDAT, and PNG headers are small,
		// so a fixed window is enough without reading a large file.
		$header = fread( $handle, 8192 );

		fclose( $handle );

		if ( ! is_string( $header ) ) {
			return false;
		}

		$idat = strpos( $header, 'IDAT' );
		$actl = strpos( $header, 'acTL' );

		if ( false === $actl ) {
			return false;
		}

		return false === $idat || $actl < $idat;
	}

	/**
	 * Build a unique temporary path for the conversion output.
	 *
	 * @param string $file Absolute path to the source image.
	 * @return string|WP_Error
	 */
	private function temp_path( $file ) {
		// The destination directory still has to be writable, because the
		// finished file is renamed into it moments later. Checking here means
		// that failure surfaces before any encoding work is done.
		if ( ! wp_is_writable( dirname( $file ) ) ) {
			return new WP_Error( 'directory-not-writable', __( 'The uploads directory is not writable.', 'swift-image-optimizer' ) );
		}

		// Written to a directory of its own rather than beside the source. A
		// process killed mid-conversion cannot clean up after itself, and a
		// stray swift-tmp-*.webp sitting in a month folder gets picked up by
		// media scanners and site backups as though it were real content.
		$dir = self::temp_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		return trailingslashit( $dir ) . wp_unique_filename( $dir, 'swift-tmp-' . wp_generate_password( 8, false ) . '.webp' );
	}

	/**
	 * The scratch directory, created and protected on first use.
	 *
	 * @return string|WP_Error Absolute path.
	 */
	public static function temp_dir() {
		$uploads = wp_get_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::TEMP_DIRNAME;

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'temp-dir-failed', __( 'The temporary directory could not be created.', 'swift-image-optimizer' ) );
		}

		$htaccess = $dir . '/.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a protective file inside the plugin's own directory.
			@file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort hardening; failure is non-fatal.
		}

		if ( ! wp_is_writable( $dir ) ) {
			return new WP_Error( 'directory-not-writable', __( 'The uploads directory is not writable.', 'swift-image-optimizer' ) );
		}

		return $dir;
	}

	/**
	 * Delete scratch files left behind by interrupted conversions.
	 *
	 * @param int $older_than Seconds. Files younger than this are in use.
	 * @return int Files removed.
	 */
	public static function sweep_temp_files( $older_than = HOUR_IN_SECONDS ) {
		$dir = self::temp_dir();

		if ( is_wp_error( $dir ) ) {
			return 0;
		}

		$files = glob( trailingslashit( $dir ) . 'swift-tmp-*.webp' );

		if ( ! is_array( $files ) ) {
			return 0;
		}

		$removed  = 0;
		$deadline = time() - max( 60, (int) $older_than );

		foreach ( $files as $file ) {
			if ( ! is_file( $file ) || filemtime( $file ) > $deadline ) {
				continue;
			}

			wp_delete_file( $file );
			++$removed;
		}

		return $removed;
	}

	/**
	 * How many scratch files are currently orphaned.
	 *
	 * @param int $older_than Seconds.
	 * @return int
	 */
	public static function count_temp_files( $older_than = HOUR_IN_SECONDS ) {
		$uploads = wp_get_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::TEMP_DIRNAME;

		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$files = glob( trailingslashit( $dir ) . 'swift-tmp-*.webp' );

		if ( ! is_array( $files ) ) {
			return 0;
		}

		$count    = 0;
		$deadline = time() - max( 60, (int) $older_than );

		foreach ( $files as $file ) {
			if ( is_file( $file ) && filemtime( $file ) <= $deadline ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Remove a temporary file if it exists.
	 *
	 * @param string $path Absolute path.
	 * @return void
	 */
	private function cleanup( $path ) {
		if ( $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
