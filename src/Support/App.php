<?php
/**
 * Static facade over the plugin's Container.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entry point for binding and resolving plugin services.
 */
class App {

	/**
	 * The container instance.
	 *
	 * @var Container|null
	 */
	private static $container;

	/**
	 * Get (creating if needed) the container instance.
	 *
	 * @return Container
	 */
	public static function container() {
		if ( null === self::$container ) {
			self::$container = new Container();
		}

		return self::$container;
	}

	/**
	 * Resolve a binding from the container.
	 *
	 * @param string $abstract Binding key.
	 * @return mixed
	 */
	public static function make( $abstract ) {
		return self::container()->make( $abstract );
	}

	/**
	 * Register a shared (singleton) binding.
	 *
	 * @param string   $abstract Binding key.
	 * @param callable $concrete Factory returning the concrete instance.
	 * @return void
	 */
	public static function singleton( $abstract, callable $concrete ) {
		self::container()->singleton( $abstract, $concrete );
	}

	/**
	 * Register a non-shared binding.
	 *
	 * @param string   $abstract Binding key.
	 * @param callable $concrete Factory returning the concrete instance.
	 * @return void
	 */
	public static function bind( $abstract, callable $concrete ) {
		self::container()->bind( $abstract, $concrete, false );
	}

	/**
	 * Register an already-built instance.
	 *
	 * @param string $abstract Binding key.
	 * @param mixed  $instance Instance to store.
	 * @return void
	 */
	public static function instance( $abstract, $instance ) {
		self::container()->instance( $abstract, $instance );
	}

	/**
	 * Whether a binding exists.
	 *
	 * @param string $abstract Binding key.
	 * @return bool
	 */
	public static function bound( $abstract ) {
		return self::container()->bound( $abstract );
	}
}
