<?php
/**
 * Feature 1: convert-on-upload wiring.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Providers;

use SwiftImageOptimizer\Services\Upload\Interceptor;
use SwiftImageOptimizer\Support\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the upload interceptor that converts new images before
 * WordPress creates the attachment.
 */
class UploadServiceProvider extends ServiceProvider {

	/**
	 * {@inheritDoc}
	 */
	public function register() {}

	/**
	 * {@inheritDoc}
	 */
	public function boot() {
		( new Interceptor( App::make( 'optimizer' ) ) )->register();
	}
}
