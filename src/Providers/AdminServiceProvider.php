<?php
/**
 * Admin-only screens and assets.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Providers;

use SwiftImageOptimizer\Http\Admin\Assets;
use SwiftImageOptimizer\Http\Admin\ListTable;
use SwiftImageOptimizer\Http\Admin\Notices;
use SwiftImageOptimizer\Http\Admin\SettingsPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires everything that only makes sense on wp-admin requests.
 */
class AdminServiceProvider extends ServiceProvider {

	/**
	 * {@inheritDoc}
	 */
	public function register() {}

	/**
	 * {@inheritDoc}
	 */
	public function boot() {
		if ( ! is_admin() ) {
			return;
		}

		( new Notices() )->register();
		( new ListTable() )->register();
		( new SettingsPage() )->register();
		( new Assets() )->register();
	}
}
