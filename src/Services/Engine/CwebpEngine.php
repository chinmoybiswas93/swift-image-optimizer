<?php
/**
 * cwebp binary conversion engine.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Services\Engine;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opt-in engine that shells out to Google's cwebp binary.
 *
 * No binary is bundled with this plugin. The engine only activates when a
 * cwebp binary already exists on the host and shell execution is permitted,
 * and silently steps aside otherwise.
 */
class CwebpEngine extends AbstractEngine {

	/**
	 * Cached absolute path to the binary, or an empty string when unavailable.
	 *
	 * @var string|null
	 */
	private static $binary = null;

	/**
	 * Whether shell execution is permitted in this environment.
	 *
	 * @return bool
	 */
	private static function can_exec() {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}

		$disabled = explode( ',', (string) ini_get( 'disable_functions' ) );
		$disabled = array_map( 'trim', $disabled );

		if ( in_array( 'exec', $disabled, true ) ) {
			return false;
		}

		// Safe mode style hardening on some hosts.
		if ( ini_get( 'safe_mode' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Locate the cwebp binary.
	 *
	 * @return string Absolute path, or an empty string when not found.
	 */
	private static function locate_binary() {
		if ( null !== self::$binary ) {
			return self::$binary;
		}

		self::$binary = '';

		if ( ! self::can_exec() ) {
			return self::$binary;
		}

		/**
		 * Filters the cwebp binary path.
		 *
		 * Allows a site to point at a binary in a non-standard location.
		 *
		 * @param string $path Absolute path to cwebp, or an empty string to auto-detect.
		 */
		$configured = apply_filters( 'swift_image_optimizer_cwebp_binary', '' );

		if ( $configured && is_executable( $configured ) ) {
			self::$binary = $configured;
			return self::$binary;
		}

		$candidates = array(
			'/usr/bin/cwebp',
			'/usr/local/bin/cwebp',
			'/opt/homebrew/bin/cwebp',
			'/opt/local/bin/cwebp',
		);

		foreach ( $candidates as $candidate ) {
			if ( is_executable( $candidate ) ) {
				self::$binary = $candidate;
				return self::$binary;
			}
		}

		return self::$binary;
	}

	/**
	 * Whether a usable cwebp binary is present.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return '' !== self::locate_binary();
	}

	/**
	 * Engine identifier.
	 *
	 * @return string
	 */
	public function name() {
		return 'cwebp';
	}

	/**
	 * cwebp runs as a separate process, outside PHP's memory limit.
	 *
	 * @return bool
	 */
	public function decodes_in_process() {
		return false;
	}

	/**
	 * Whether cwebp can correctly convert this particular file.
	 *
	 * cwebp does not rotate. It has no equivalent of Imagick's autoOrient()
	 * or the manual rotation GdEngine performs, and `-metadata icc` discards
	 * the EXIF block that carries the orientation flag - so a photo shot in
	 * portrait would be written permanently sideways with nothing left to say
	 * it was ever rotated. Rather than produce that, the engine steps aside
	 * and lets the factory fall through to one that can.
	 *
	 * @param string $file Absolute path to the source image.
	 * @param string $mime Source mime type.
	 * @return bool
	 */
	public function supports_file( $file, $mime ) {
		if ( ! $this->supports( $mime ) ) {
			return false;
		}

		if ( 'image/jpeg' !== $mime ) {
			return true;
		}

		return 1 === $this->exif_orientation( $file );
	}

	/**
	 * Convert a source image to WebP using cwebp.
	 *
	 * @param string $source      Absolute path to the source image.
	 * @param string $destination Absolute path to write the WebP to.
	 * @param array  $options     Conversion options.
	 * @return true|WP_Error
	 */
	public function convert( $source, $destination, array $options ) {
		$binary = self::locate_binary();

		if ( '' === $binary ) {
			return new WP_Error( 'swift_image_optimizer_no_binary', __( 'The cwebp binary is not available.', 'swift-image-optimizer' ) );
		}

		$options = $this->parse_options( $options );

		$args = array(
			escapeshellarg( $binary ),
			'-quiet',
			'-q ' . (int) $options['quality'],
			'-m 6',
			'-sharp_yuv',
			'-metadata icc',
		);

		if ( $options['max_dimension'] > 0 ) {
			$info = @getimagesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid images are handled by the false check.

			if ( $info ) {
				list( $width, $height ) = $this->constrain( (int) $info[0], (int) $info[1], $options['max_dimension'] );

				if ( (int) $info[0] !== $width || (int) $info[1] !== $height ) {
					// cwebp treats a zero dimension as "preserve aspect ratio".
					$args[] = '-resize ' . (int) $width . ' 0';
				}
			}
		}

		$args[] = escapeshellarg( $source );
		$args[] = '-o ' . escapeshellarg( $destination );

		$command = implode( ' ', $args ) . ' 2>&1';

		$output      = array();
		$return_code = 1;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Guarded by can_exec(); every argument is escaped above.
		@exec( $command, $output, $return_code ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failures are reported through $return_code.

		if ( 0 !== $return_code || ! file_exists( $destination ) || 0 === filesize( $destination ) ) {
			return new WP_Error(
				'swift_image_optimizer_cwebp_failed',
				sprintf(
					/* translators: %s: error output from the cwebp binary. */
					__( 'cwebp failed: %s', 'swift-image-optimizer' ),
					implode( ' ', array_slice( $output, 0, 3 ) )
				)
			);
		}

		return true;
	}
}
