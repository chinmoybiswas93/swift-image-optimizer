<?php
/**
 * REST API wiring.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Providers;

use SwiftImageOptimizer\Http\Controllers\Controller;
use SwiftImageOptimizer\Support\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the swift-image-optimizer/v1 REST routes.
 */
class RestServiceProvider extends ServiceProvider {

	/**
	 * {@inheritDoc}
	 */
	public function register() {}

	/**
	 * {@inheritDoc}
	 */
	public function boot() {
		( new Controller( App::make( 'converter' ), App::make( 'runner' ) ) )->register();
	}
}
