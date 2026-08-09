<?php
/**
 * WP-CLI command wiring.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Providers;

use SwiftImageOptimizer\Services\Bulk\Cli;
use SwiftImageOptimizer\Support\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the `wp swift-image-optimizer ...` commands, WP-CLI only.
 */
class CliServiceProvider extends ServiceProvider {

	/**
	 * {@inheritDoc}
	 */
	public function register() {}

	/**
	 * {@inheritDoc}
	 */
	public function boot() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$cli = new Cli( App::make( 'converter' ), App::make( 'rewriter' ) );

		\WP_CLI::add_command( 'swift-image-optimizer optimize', array( $cli, 'optimize' ) );
		\WP_CLI::add_command( 'swift-image-optimizer restore', array( $cli, 'restore' ) );
		\WP_CLI::add_command( 'swift-image-optimizer stats', array( $cli, 'stats' ) );
		\WP_CLI::add_command( 'swift-image-optimizer diagnostics', array( $cli, 'diagnostics' ) );
		\WP_CLI::add_command( 'swift-image-optimizer logs', array( $cli, 'logs' ) );
		\WP_CLI::add_command( 'swift-image-optimizer requeue', array( $cli, 'requeue' ) );
	}
}
