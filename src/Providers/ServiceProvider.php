<?php
/**
 * Base service provider contract.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every provider registers bindings first, then boots hooks once all
 * providers have registered.
 */
abstract class ServiceProvider {

	/**
	 * Register bindings into the container.
	 *
	 * Only bind things here - do not resolve from the container or attach
	 * WordPress hooks, other providers may not have registered yet.
	 *
	 * @return void
	 */
	abstract public function register();

	/**
	 * Boot the provider after every provider has registered.
	 *
	 * Safe to resolve from the container and attach WordPress hooks here.
	 *
	 * @return void
	 */
	public function boot() {}
}
