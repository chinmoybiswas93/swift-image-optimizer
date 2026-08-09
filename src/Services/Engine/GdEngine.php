<?php
/**
 * GD conversion engine.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Services\Engine;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Universal fallback engine. Available on virtually every WordPress host.
 *
 * GD discards ICC profiles and EXIF, so orientation is applied manually before
 * encoding to avoid sideways images.
 */
class GdEngine extends AbstractEngine {

	/**
	 * Whether GD with WebP write support is present.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return extension_loaded( 'gd' ) && function_exists( 'imagewebp' ) && function_exists( 'imagecreatetruecolor' );
	}

	/**
	 * Engine identifier.
	 *
	 * @return string
	 */
	public function name() {
		return 'gd';
	}

	/**
	 * Convert a source image to WebP using GD.
	 *
	 * @param string $source      Absolute path to the source image.
	 * @param string $destination Absolute path to write the WebP to.
	 * @param array  $options     Conversion options.
	 * @return true|WP_Error
	 */
	public function convert( $source, $destination, array $options ) {
		$options = $this->parse_options( $options );

		$info = @getimagesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid images are handled via the false check below.

		if ( ! $info ) {
			return new WP_Error( 'swift_image_optimizer_unreadable', __( 'Could not read the source image.', 'swift-image-optimizer' ) );
		}

		$image = $this->create_resource( $source, $info[2] );

		if ( ! $image ) {
			return new WP_Error( 'swift_image_optimizer_decode_failed', __( 'GD could not decode the source image.', 'swift-image-optimizer' ) );
		}

		if ( IMAGETYPE_JPEG === $info[2] ) {
			$image = $this->apply_exif_orientation( $image, $source );
		}

		$src_width  = imagesx( $image );
		$src_height = imagesy( $image );

		list( $width, $height ) = $this->constrain( $src_width, $src_height, $options['max_dimension'] );

		if ( $width !== $src_width || $height !== $src_height ) {
			$resized = imagecreatetruecolor( $width, $height );

			if ( ! $resized ) {
				imagedestroy( $image );
				return new WP_Error( 'swift_image_optimizer_resize_failed', __( 'Could not allocate the resized image.', 'swift-image-optimizer' ) );
			}

			$this->preserve_alpha( $resized );

			imagecopyresampled( $resized, $image, 0, 0, 0, 0, $width, $height, $src_width, $src_height );
			imagedestroy( $image );
			$image = $resized;
		}

		$written = imagewebp( $image, $destination, $options['quality'] );

		imagedestroy( $image );

		if ( ! $written || ! file_exists( $destination ) || 0 === filesize( $destination ) ) {
			return new WP_Error( 'swift_image_optimizer_encode_failed', __( 'GD failed to write the WebP file.', 'swift-image-optimizer' ) );
		}

		return true;
	}

	/**
	 * Create a GD resource from a file.
	 *
	 * @param string $source     Absolute path.
	 * @param int    $image_type One of the IMAGETYPE_* constants.
	 * @return resource|\GdImage|false
	 */
	private function create_resource( $source, $image_type ) {
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- Corrupt images emit warnings; the false return is handled by the caller.
		switch ( $image_type ) {
			case IMAGETYPE_JPEG:
				return function_exists( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $source ) : false;

			case IMAGETYPE_PNG:
				$image = function_exists( 'imagecreatefrompng' ) ? @imagecreatefrompng( $source ) : false;

				if ( $image ) {
					$this->preserve_alpha( $image );
				}

				return $image;

			default:
				return false;
		}
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Configure a GD image to keep its alpha channel through encoding.
	 *
	 * @param resource|\GdImage $image GD image.
	 * @return void
	 */
	private function preserve_alpha( $image ) {
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
	}

	/**
	 * Rotate a JPEG according to its EXIF orientation flag.
	 *
	 * GD strips EXIF on save, so without this images shot in portrait would be
	 * written sideways.
	 *
	 * @param resource|\GdImage $image  GD image.
	 * @param string            $source Absolute path to the source file.
	 * @return resource|\GdImage
	 */
	private function apply_exif_orientation( $image, $source ) {
		if ( ! function_exists( 'exif_read_data' ) || ! function_exists( 'imagerotate' ) ) {
			return $image;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing or malformed EXIF is expected and non-fatal.
		$exif = @exif_read_data( $source );

		if ( ! is_array( $exif ) || empty( $exif['Orientation'] ) ) {
			return $image;
		}

		$degrees = 0;

		switch ( (int) $exif['Orientation'] ) {
			case 3:
				$degrees = 180;
				break;
			case 6:
				$degrees = 270;
				break;
			case 8:
				$degrees = 90;
				break;
		}

		if ( 0 === $degrees ) {
			return $image;
		}

		$rotated = imagerotate( $image, $degrees, 0 );

		if ( ! $rotated ) {
			return $image;
		}

		imagedestroy( $image );
		$this->preserve_alpha( $rotated );

		return $rotated;
	}
}
