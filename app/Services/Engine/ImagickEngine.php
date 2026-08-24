<?php
/**
 * Imagick conversion engine.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Services\Engine;

use Imagick;
use ImagickException;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preferred engine when available.
 *
 * Unlike GD, Imagick can preserve the ICC colour profile, which prevents the
 * visible desaturation that occurs when a Display-P3 photo is stripped to a
 * bare sRGB assumption.
 */
class ImagickEngine extends AbstractEngine {

	/**
	 * Whether Imagick with WebP delegate support is present.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick' ) ) {
			return false;
		}

		try {
			$formats = Imagick::queryFormats( 'WEBP' );
		} catch ( \Exception $e ) {
			return false;
		}

		return ! empty( $formats );
	}

	/**
	 * Engine identifier.
	 *
	 * @return string
	 */
	public function name() {
		return 'imagick';
	}

	/**
	 * Imagick decodes outside PHP's memory_limit.
	 *
	 * The pixel buffer lives in ImageMagick's own C heap, governed by its
	 * resource limits - which page to disk rather than fataling - so PHP's
	 * memory_limit says nothing about whether a decode will survive. Applying
	 * the in-process estimate here refused 40-megapixel photos that Imagick
	 * converts comfortably, the same mistake cwebp was exempted from.
	 *
	 * Safe to exempt only because convert() sets the `jpeg:size` decode hint,
	 * which keeps the frame Imagick actually allocates near the output size.
	 *
	 * @return bool
	 */
	public function decodes_in_process() {
		return false;
	}

	/**
	 * Convert a source image to WebP using Imagick.
	 *
	 * @param string $source      Absolute path to the source image.
	 * @param string $destination Absolute path to write the WebP to.
	 * @param array  $options     Conversion options.
	 * @return true|WP_Error
	 */
	public function convert( $source, $destination, array $options ) {
		$options = $this->parse_options( $options );

		$image = null;

		try {
			$image = new Imagick();

			/*
			 * Ask libjpeg to DCT-scale during decode rather than decoding the
			 * full frame and throwing most of it away in resizeImage() below.
			 * A 7728px JPEG bound for 2560px decodes at half scale, cutting
			 * peak decode memory roughly fourfold. libjpeg only ever picks a
			 * scale that still covers the requested box, so the pixels the
			 * resize needs are all there. Decoders that do not understand the
			 * hint ignore it.
			 *
			 * The hint is the constrained target, not a square of
			 * max_dimension, and it is set only when that is genuinely
			 * smaller than the source. libjpeg scales *up* to reach the box
			 * it is given - up to 2x - so a square hint enlarged anything
			 * whose shorter edge fell below max_dimension: the 900x600
			 * harness fixture came back 1800x1200 and its WebP was bigger
			 * than the JPEG it came from.
			 */
			if ( $options['max_dimension'] > 0 ) {
				$size = @getimagesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Handled by the is_array check; a hint is optional.

				if ( is_array( $size ) ) {
					list( $hint_width, $hint_height ) = $this->constrain( (int) $size[0], (int) $size[1], $options['max_dimension'] );

					if ( $hint_width < (int) $size[0] ) {
						$image->setOption( 'jpeg:size', $hint_width . 'x' . $hint_height );
					}
				}
			}

			$image->readImage( $source );

			// Flatten multi-frame sources (for example a layered PNG) to a single frame.
			if ( $image->getNumberImages() > 1 ) {
				$image = $image->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
			}

			$this->apply_orientation( $image );

			$icc = $this->extract_icc( $image );

			$width  = $image->getImageWidth();
			$height = $image->getImageHeight();

			list( $target_width, $target_height ) = $this->constrain( $width, $height, $options['max_dimension'] );

			if ( $target_width !== $width || $target_height !== $height ) {
				$image->resizeImage( $target_width, $target_height, Imagick::FILTER_LANCZOS, 1 );
			}

			// Drop EXIF and other bulk metadata, then restore only the colour profile.
			$image->stripImage();

			if ( $icc ) {
				$image->profileImage( 'icc', $icc );
			}

			$image->setImageFormat( 'webp' );
			$image->setImageCompressionQuality( $options['quality'] );
			$image->setOption( 'webp:method', '6' );
			$image->setOption( 'webp:use-sharp-yuv', 'true' );

			$image->writeImage( $destination );
			$image->clear();
			$image->destroy();
		} catch ( ImagickException $e ) {
			if ( $image instanceof Imagick ) {
				$image->clear();
				$image->destroy();
			}

			return new WP_Error( 'swift_image_optimizer_imagick_failed', $e->getMessage() );
		} catch ( \Exception $e ) {
			if ( $image instanceof Imagick ) {
				$image->clear();
				$image->destroy();
			}

			return new WP_Error( 'swift_image_optimizer_imagick_failed', $e->getMessage() );
		}

		if ( ! file_exists( $destination ) || 0 === filesize( $destination ) ) {
			return new WP_Error( 'swift_image_optimizer_encode_failed', __( 'Imagick failed to write the WebP file.', 'swift-image-optimizer' ) );
		}

		return true;
	}

	/**
	 * Bake the EXIF orientation into the pixel data.
	 *
	 * @param Imagick $image Image instance.
	 * @return void
	 */
	private function apply_orientation( Imagick $image ) {
		if ( method_exists( $image, 'autoOrient' ) ) {
			$image->autoOrient();
			return;
		}

		// Older Imagick builds lack autoOrient(); rotate manually.
		$orientation = $image->getImageOrientation();
		$white       = new \ImagickPixel( 'none' );

		switch ( $orientation ) {
			case Imagick::ORIENTATION_BOTTOMRIGHT:
				$image->rotateImage( $white, 180 );
				break;
			case Imagick::ORIENTATION_RIGHTTOP:
				$image->rotateImage( $white, 90 );
				break;
			case Imagick::ORIENTATION_LEFTBOTTOM:
				$image->rotateImage( $white, -90 );
				break;
		}

		$image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
	}

	/**
	 * Read the ICC profile off an image, if it carries one.
	 *
	 * @param Imagick $image Image instance.
	 * @return string Raw profile data, or an empty string.
	 */
	private function extract_icc( Imagick $image ) {
		try {
			$profiles = $image->getImageProfiles( 'icc', true );
		} catch ( \Exception $e ) {
			return '';
		}

		return isset( $profiles['icc'] ) ? $profiles['icc'] : '';
	}
}
