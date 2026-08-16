<?php
/**
 * Cron driver for bulk optimization.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Hooks\Scheduler;

use SwiftImageOptimizer\App\App;
use SwiftImageOptimizer\App\Services\Bulk\Runner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Advances a bulk run from WP-Cron, so it keeps going after the tab closes.
 *
 * Modelled on JobRunner, with one deliberate difference: the daily purge is a
 * recurring event that should always exist, whereas this re-arms a *single*
 * event after each batch. Batch size is adaptive, so the interval between
 * batches is not knowable in advance, and a recurring schedule would either
 * fire pointlessly on an idle site or lag a busy one.
 *
 * The callback runs Runner::process_batch() in-process. It deliberately does
 * not call the REST route over HTTP: architecture invariant 13 forbids the
 * plugin making external HTTP requests, and a loopback to the site's own
 * admin-ajax is still the plugin issuing one.
 */
class BulkJobRunner {

	/**
	 * Cron hook name.
	 */
	const HOOK = 'swift_image_optimizer_bulk_batch';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );

		/*
		 * Self-heal, but only while a run is actually active. JobRunner re-arms
		 * unconditionally because its event should always exist; doing that
		 * here would schedule a no-op tick on every page load forever. This
		 * also recovers a run left active by an upgrade that replaced the
		 * plugin files without the activation hook firing.
		 */
		if ( self::has_active_run() && ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule_next();
		}
	}

	/**
	 * Advance the run by one batch.
	 *
	 * @return void
	 */
	public static function run() {
		$runner = App::make( Runner::class );

		if ( ! $runner instanceof Runner ) {
			return;
		}

		// process_batch() re-arms the next tick itself while the run is still
		// active, so a failure here ends the chain rather than spinning.
		$runner->process_batch();
	}

	/**
	 * Queue the next batch.
	 *
	 * @return void
	 */
	public static function schedule_next() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	/**
	 * Clear any queued batch.
	 *
	 * @return void
	 */
	public static function unschedule() {
		// One read, one write. See ScanJobRunner::unschedule_batch() for why the
		// ask-again shape is not safe against a lock-free, last-write-wins
		// option - this hook is re-armed on every request during a run too.
		wp_unschedule_hook( self::HOOK );
	}

	/**
	 * Whether a run is currently marked active.
	 *
	 * Reads the option directly rather than through Runner, so that registration
	 * costs one option read and does not resolve the converter and rewriter on
	 * every admin page load.
	 *
	 * @return bool
	 */
	private static function has_active_run() {
		$state = get_option( Runner::PROGRESS_OPTION, array() );

		return is_array( $state ) && ! empty( $state['running'] );
	}
}
