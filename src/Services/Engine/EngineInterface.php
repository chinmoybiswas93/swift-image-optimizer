<?php
/**
 * Contract every conversion engine implements.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Services\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A conversion engine turns a source image file into a WebP file on disk.
 */
interface EngineInterface {

	/**
	 * Whether this engine can run in the current environment.
	 *
	 * @return bool
	 */
	public static function is_available();

	/**
	 * Machine-readable engine identifier, stored in the log table.
	 *
	 * @return string
	 */
	public function name();

	/**
	 * Whether this engine can read the given source mime type.
	 *
	 * @param string $mime Source mime type.
	 * @return bool
	 */
	public function supports( $mime );

	/**
	 * Whether this engine can correctly handle one specific file.
	 *
	 * Separate from supports(): an engine can read a format in general and
	 * still be the wrong choice for a particular file. The caller uses this to
	 * step past an engine rather than produce a bad result with it.
	 *
	 * @param string $file Absolute path to the source image.
	 * @param string $mime Source mime type.
	 * @return bool
	 */
	public function supports_file( $file, $mime );

	/**
	 * Whether this engine decodes the image inside the PHP process.
	 *
	 * @return bool
	 */
	public function decodes_in_process();

	/**
	 * Convert a source image to WebP.
	 *
	 * @param string $source      Absolute path to the source image.
	 * @param string $destination Absolute path to write the WebP to.
	 * @param array  $options     Keys: quality (int 1-100), max_dimension (int, 0 to disable).
	 * @return true|\WP_Error True on success.
	 */
	public function convert( $source, $destination, array $options );
}
