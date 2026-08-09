<?php
/**
 * Minimal dependency container.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves and stores bindings by string key.
 */
class Container {

	/**
	 * Registered factories, keyed by abstract name.
	 *
	 * @var array<string, callable>
	 */
	private $bindings = array();

	/**
	 * Resolved singleton instances, keyed by abstract name.
	 *
	 * @var array<string, mixed>
	 */
	private $instances = array();

	/**
	 * Abstract names that should be resolved once and cached.
	 *
	 * @var array<string, true>
	 */
	private $shared = array();

	/**
	 * Register a factory binding.
	 *
	 * @param string   $abstract Binding key.
	 * @param callable $concrete Factory returning the concrete instance.
	 * @param bool     $shared   Whether to cache the resolved instance.
	 * @return void
	 */
	public function bind( $abstract, callable $concrete, $shared = false ) {
		$this->bindings[ $abstract ] = $concrete;

		if ( $shared ) {
			$this->shared[ $abstract ] = true;
		}

		unset( $this->instances[ $abstract ] );
	}

	/**
	 * Register a factory binding that resolves once and is cached.
	 *
	 * @param string   $abstract Binding key.
	 * @param callable $concrete Factory returning the concrete instance.
	 * @return void
	 */
	public function singleton( $abstract, callable $concrete ) {
		$this->bind( $abstract, $concrete, true );
	}

	/**
	 * Register an already-built instance.
	 *
	 * @param string $abstract Binding key.
	 * @param mixed  $instance Instance to store.
	 * @return void
	 */
	public function instance( $abstract, $instance ) {
		$this->instances[ $abstract ] = $instance;
		$this->shared[ $abstract ]    = true;
	}

	/**
	 * Resolve a binding.
	 *
	 * @param string $abstract Binding key.
	 * @return mixed
	 *
	 * @throws \RuntimeException When nothing is bound to $abstract.
	 */
	public function make( $abstract ) {
		if ( array_key_exists( $abstract, $this->instances ) ) {
			return $this->instances[ $abstract ];
		}

		if ( ! isset( $this->bindings[ $abstract ] ) ) {
			throw new \RuntimeException( "Nothing bound for '{$abstract}'." );
		}

		$object = call_user_func( $this->bindings[ $abstract ] );

		if ( isset( $this->shared[ $abstract ] ) ) {
			$this->instances[ $abstract ] = $object;
		}

		return $object;
	}

	/**
	 * Whether a binding (or resolved instance) exists for $abstract.
	 *
	 * @param string $abstract Binding key.
	 * @return bool
	 */
	public function bound( $abstract ) {
		return isset( $this->bindings[ $abstract ] ) || array_key_exists( $abstract, $this->instances );
	}
}
