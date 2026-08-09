<?php
/**
 * Diagnostic logging wiring.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Providers;

use SwiftImageOptimizer\Repositories\SettingsRepository;
use SwiftImageOptimizer\Services\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the logger's cached state in step with the settings.
 */
class LoggingServiceProvider extends ServiceProvider {

	/**
	 * {@inheritDoc}
	 */
	public function register() {}

	/**
	 * {@inheritDoc}
	 */
	public function boot() {
		add_action( 'update_option_' . SettingsRepository::OPTION, array( $this, 'settings_changed' ), 10, 2 );
	}

	/**
	 * React to a settings save.
	 *
	 * The enabled flag is cached per request, so it has to be dropped. Turning
	 * logging on also creates the directory immediately rather than on the
	 * first write, so a permissions problem surfaces on the screen that has
	 * the toggle rather than silently swallowing the first run's output.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 * @return void
	 */
	public function settings_changed( $old_value, $value ) {
		Logger::reset();

		$was_enabled = is_array( $old_value ) && ! empty( $old_value['enable_log'] );
		$is_enabled  = is_array( $value ) && ! empty( $value['enable_log'] );

		if ( $is_enabled && ! $was_enabled ) {
			Logger::ensure_dir();
			Logger::start_run( 'admin' );
			Logger::mark( 'logging', 'Logging enabled.' );
		}

		if ( ! $is_enabled && $was_enabled ) {
			Logger::start_run( 'admin' );
			Logger::mark( 'logging', 'Logging disabled. Errors will still be recorded.' );
		}
	}
}
