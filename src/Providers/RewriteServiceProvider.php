<?php
/**
 * Old-URL 404 fallback wiring.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Providers;

use SwiftImageOptimizer\Services\Rewrite\Fallback404;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the fallback that serves the WebP when an old image URL 404s.
 */
class RewriteServiceProvider extends ServiceProvider {

	/**
	 * {@inheritDoc}
	 */
	public function register() {}

	/**
	 * {@inheritDoc}
	 */
	public function boot() {
		( new Fallback404() )->register();
	}
}
