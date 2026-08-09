<?php
/**
 * Backup retention wiring.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Providers;

use SwiftImageOptimizer\Hooks\Scheduler\RetentionCron;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the scheduled purge of expired backups.
 */
class BackupServiceProvider extends ServiceProvider {

	/**
	 * {@inheritDoc}
	 */
	public function register() {}

	/**
	 * {@inheritDoc}
	 */
	public function boot() {
		( new RetentionCron() )->register();
	}
}
