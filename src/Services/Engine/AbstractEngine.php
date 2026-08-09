<?php
/**
 * Shared engine behaviour.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Services\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class holding dimension math and option parsing common to all engines.
 */
abstract class AbstractEngine implements EngineInterface {

	/**
	 * Source mime types every engine is expected to read.
	 *
	 * @var string[]
	 */
	protected $supported = array( 'image/jpeg', 'image/png' );

	/**
	 * Whether this engine can read the given source mime type.
	 *
	 * @param string $mime Source mime type.
	 * @return bool
	 */
	public function supports( $mime ) {
		return in_array( $mime, $this->supported, true );
	}

	/**
	 * Whether this engine can correctly handle one specific file.
	 *
	 * Engines that have no per-file limitation inherit this and accept
	 * anything their format list already allows.
	 *
	 * @param string $file Absolute path to the source image.
	 * @param string $mime Source mime type.
	 * @return bool
	 */
	public function supports_file( $file, $mime ) {
		unset( $file );

		return $this->supports( $mime );
	}

	/**
	 * Whether this engine decodes the image inside the PHP process.
	 *
	 * An engine that shells out to a separate binary is not bound by PHP's
	 * memory_limit, so the caller's pre-decode memory estimate does not apply
	 * to it and would otherwise refuse images it could handle comfortably.
	 *
	 * @return bool
	 */
	public function decodes_in_process() {
		return true;
	}

	/**
	 * The EXIF orientation flag of a JPEG, if it has one.
	 *
	 * @param string $file Absolute path to the source image.
	 * @return int Orientation from 1 to 8, or 1 when there is none to read.
	 */
	protected function exif_orientation( $file ) {
		if ( ! function_exists( 'exif_read_data' ) ) {
			return 1;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing or malformed EXIF is expected and non-fatal.
		$exif = @exif_read_data( $file );

		if ( ! is_array( $exif ) || empty( $exif['Orientation'] ) ) {
			return 1;
		}

		$orientation = (int) $exif['Orientation'];

		return ( $orientation >= 1 && $orientation <= 8 ) ? $orientation : 1;
	}

	/**
	 * Normalize the options array.
	 *
	 * @param array $options Raw options.
	 * @return array Keys: quality, max_dimension.
	 */
	protected function parse_options( array $options ) {
		$quality = isset( $options['quality'] ) ? (int) $options['quality'] : 82;
		$max     = isset( $options['max_dimension'] ) ? (int) $options['max_dimension'] : 0;

		return array(
			'quality'       => max( 1, min( 100, $quality ) ),
			'max_dimension' => $max > 0 ? $max : 0,
		);
	}

	/**
	 * Compute target dimensions, constrained to a bounding box.
	 *
	 * Never upscales.
	 *
	 * @param int $width  Source width.
	 * @param int $height Source height.
	 * @param int $max    Longest allowed edge. 0 disables the constraint.
	 * @return array{0:int,1:int} Target width and height.
	 */
	protected function constrain( $width, $height, $max ) {
		if ( $max <= 0 ) {
			return array( $width, $height );
		}

		$longest = max( $width, $height );

		if ( $longest <= $max ) {
			return array( $width, $height );
		}

		$ratio = $max / $longest;

		return array(
			max( 1, (int) round( $width * $ratio ) ),
			max( 1, (int) round( $height * $ratio ) ),
		);
	}
}
