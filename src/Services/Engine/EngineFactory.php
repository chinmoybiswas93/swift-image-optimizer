<?php
/**
 * Engine detection and selection.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Services\Engine;

use SwiftImageOptimizer\Repositories\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picks the best available conversion engine.
 *
 * Preference order is Imagick, then cwebp, then GD. Imagick leads because it
 * is the only engine that preserves ICC profiles through the conversion.
 */
class EngineFactory {

	/**
	 * Engine identifier to class name, in preference order.
	 *
	 * @var array<string, class-string<EngineInterface>>
	 */
	private static $engines = array(
		'imagick' => ImagickEngine::class,
		'cwebp'   => CwebpEngine::class,
		'gd'      => GdEngine::class,
	);

	/**
	 * Resolved engines for this request, in the order they should be tried.
	 *
	 * @var EngineInterface[]|null
	 */
	private static $resolved = null;

	/**
	 * Every usable engine, best first, honoring the configured override.
	 *
	 * Returning a chain rather than a single engine is what lets a conversion
	 * survive one engine's blind spot: cwebp cannot rotate, GD cannot decode
	 * CMYK JPEGs, and neither limitation should turn into a failed image when
	 * another installed engine handles the file correctly.
	 *
	 * @return EngineInterface[] Empty when no engine can run here.
	 */
	public static function chain() {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}

		$available = array();

		foreach ( self::$engines as $key => $class ) {
			if ( call_user_func( array( $class, 'is_available' ) ) ) {
				$available[ $key ] = new $class();
			}
		}

		$preferred = SettingsRepository::get( 'engine', 'auto' );

		// An explicit preference moves that engine to the front of the chain
		// rather than replacing it, so the fallbacks still apply to files it
		// cannot handle.
		if ( 'auto' !== $preferred && isset( $available[ $preferred ] ) ) {
			$first     = $available[ $preferred ];
			$available = array( $preferred => $first ) + $available;
		}

		self::$resolved = array_values( $available );

		return self::$resolved;
	}

	/**
	 * The engine that would normally be used.
	 *
	 * Kept for the settings screen, the diagnostics report and the no-engine
	 * notice, all of which ask "can this server convert at all".
	 *
	 * @return EngineInterface|null Null when no engine can run here.
	 */
	public static function get() {
		$chain = self::chain();

		return $chain ? $chain[0] : null;
	}

	/**
	 * The engines able to handle one specific file, in preference order.
	 *
	 * @param string $file Absolute path to the source image.
	 * @param string $mime Source mime type.
	 * @return EngineInterface[]
	 */
	public static function for_file( $file, $mime ) {
		$out = array();

		foreach ( self::chain() as $engine ) {
			if ( $engine->supports_file( $file, $mime ) ) {
				$out[] = $engine;
			}
		}

		return $out;
	}

	/**
	 * List which engines are available in this environment.
	 *
	 * Used by the settings screen and the diagnostics notice.
	 *
	 * @return array<string, bool> Engine identifier to availability.
	 */
	public static function availability() {
		$out = array();

		foreach ( self::$engines as $key => $class ) {
			$out[ $key ] = (bool) call_user_func( array( $class, 'is_available' ) );
		}

		return $out;
	}

	/**
	 * Whether any engine can run here.
	 *
	 * @return bool
	 */
	public static function has_engine() {
		return in_array( true, self::availability(), true );
	}

	/**
	 * Reset the cached instance. Used after a settings change.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$resolved = null;
	}
}
