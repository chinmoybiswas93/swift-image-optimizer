<?php
/**
 * Standard plugin boot helper.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Usage:
 *
 *   PluginBootstrapper::create()
 *       ->providers( array( AppServiceProvider::class, AdminServiceProvider::class ) )
 *       ->boot();
 *
 * register() is called on every provider first, then boot() is called on
 * every provider - so bindings from one provider's register() are always
 * available in another provider's boot().
 *
 * Note: unlike a bootstrapper invoked at plugin-file top level, this plugin's
 * entry point (`swift_image_optimizer()`) is itself already hooked to
 * `plugins_loaded`. Deferring boot() to another `plugins_loaded` add_action()
 * would be unreliable (that hook is already firing by the time this runs), so
 * both phases run synchronously, in order, right here.
 */
class PluginBootstrapper {

	/**
	 * Provider class names to register and boot.
	 *
	 * @var array<class-string<ServiceProvider>>
	 */
	private $providers = array();

	/**
	 * Instantiated providers, in registration order.
	 *
	 * @var ServiceProvider[]
	 */
	private $instances = array();

	/**
	 * Create a new bootstrapper instance.
	 *
	 * @return static
	 */
	public static function create() {
		return new static();
	}

	/**
	 * Set the provider classes to register and boot.
	 *
	 * @param array<class-string<ServiceProvider>> $classes Provider class names.
	 * @return static
	 */
	public function providers( array $classes ) {
		$this->providers = $classes;

		return $this;
	}

	/**
	 * Run the boot sequence: register() on every provider, then boot() on
	 * every provider.
	 *
	 * @return void
	 */
	public function boot() {
		foreach ( $this->providers as $class ) {
			/**
			 * Provider instance.
			 *
			 * @var ServiceProvider $instance
			 */
			$instance = new $class();
			$instance->register();
			$this->instances[] = $instance;
		}

		foreach ( $this->instances as $instance ) {
			$instance->boot();
		}
	}
}
