<?php
/**
 * Cron driver for the library scan.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Hooks\Scheduler;

use SwiftImageOptimizer\Api\StoreSettings;
use SwiftImageOptimizer\App\App;
use SwiftImageOptimizer\App\Services\Bulk\Runner;
use SwiftImageOptimizer\App\Services\Bulk\ScanRunner;
use SwiftImageOptimizer\App\Services\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the library scan from WP-Cron, on demand and on a schedule.
 *
 * Two hooks, because they are two different things. HOOK advances a scan that
 * is already running and re-arms itself after each batch, exactly as
 * BulkJobRunner does and for the same reason: batch size is adaptive, so the
 * gap between batches is not knowable in advance. SCHEDULE_HOOK is a genuinely
 * recurring event that starts a scan every day, week or month.
 *
 * Both callbacks run in-process. Neither issues an HTTP request to the site's
 * own admin-ajax or REST route: architecture invariant 13 forbids the plugin
 * making external HTTP requests, and a loopback is still the plugin making one.
 */
class ScanJobRunner {

	/**
	 * Single, self-re-arming event that advances a running scan.
	 */
	const HOOK = 'swift_image_optimizer_scan_batch';

	/**
	 * Recurring event that starts a scheduled scan.
	 */
	const SCHEDULE_HOOK = 'swift_image_optimizer_scheduled_scan';

	/**
	 * Custom cron interval key.
	 *
	 * WordPress ships hourly, twicedaily, daily and weekly. Monthly is the only
	 * one of the plugin's four frequencies core does not provide.
	 */
	const MONTHLY = 'swift_image_optimizer_monthly';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- A monthly interval, far longer than the 15-minute threshold this sniff guards.

		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::SCHEDULE_HOOK, array( __CLASS__, 'run_scheduled' ) );

		/*
		 * Self-heal the batch tick, but only while a scan is actually active -
		 * same reasoning as BulkJobRunner, where re-arming unconditionally
		 * would queue a no-op tick on every page load forever.
		 */
		if ( ScanRunner::is_running() && ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule_next();
		}

		/*
		 * Self-heal the recurring event too. update_option_* does not fire when
		 * the option is first created or saved unchanged, so without this an
		 * install that never touches the setting would never schedule the
		 * default weekly scan.
		 */
		$frequency = StoreSettings::get( 'scan_frequency' );

		if ( 'manual' !== $frequency && ! wp_next_scheduled( self::SCHEDULE_HOOK ) ) {
			self::schedule( $frequency );
		}
	}

	/**
	 * Add the monthly interval.
	 *
	 * Thirty days rather than a calendar month - cron intervals are a fixed
	 * number of seconds, and the UI says "about every 30 days" rather than
	 * implying something it cannot deliver.
	 *
	 * @param array $schedules Registered schedules.
	 * @return array
	 */
	public static function add_schedules( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}

		$schedules[ self::MONTHLY ] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'About every 30 days', 'swift-image-optimizer' ),
		);

		return $schedules;
	}

	/**
	 * Advance a running scan by one batch.
	 *
	 * @return void
	 */
	public static function run() {
		$scanner = App::make( ScanRunner::class );

		if ( ! $scanner instanceof ScanRunner ) {
			return;
		}

		// process_batch() re-arms the next tick itself while the scan is still
		// running, so a failure here ends the chain rather than spinning.
		$scanner->process_batch();
	}

	/**
	 * Start a scan because the schedule came round.
	 *
	 * @return void
	 */
	public static function run_scheduled() {
		if ( self::bulk_is_running() ) {
			/*
			 * Skip rather than queue. The chain runs its own scan when the bulk
			 * run finishes, so the figures will be current shortly anyway, and
			 * the next tick is at most a month away.
			 */
			Logger::mark( 'scan', 'Scheduled scan skipped: a bulk optimization is running.' );

			return;
		}

		$scanner = App::make( ScanRunner::class );

		if ( ! $scanner instanceof ScanRunner ) {
			return;
		}

		$scanner->start( 'schedule' );
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
	 * Reads the cron array once and clears every match in a single write.
	 *
	 * The obvious shape - unschedule, ask again, repeat until the answer is
	 * false - loops forever if the write does not stick, because the only exit
	 * condition is the write sticking. The cron option has no locking and is
	 * last-write-wins, so a concurrent request that read it before this
	 * unschedule and wrote after it puts the event straight back, and a loop
	 * that retries in microseconds hands the racer unlimited chances. Core
	 * documents that exact failure at wp_clear_scheduled_hook(), which is why
	 * that function reads the array once rather than re-querying.
	 *
	 * There is always a racer here: register() re-arms this hook on every
	 * request while a scan is running, and the Bulk screen drives batches and
	 * polls phase concurrently for the whole scan.
	 *
	 * @return void
	 */
	public static function unschedule_batch() {
		wp_unschedule_hook( self::HOOK );
	}

	/**
	 * Schedule the recurring scan for a frequency.
	 *
	 * Always clears first, so changing the setting re-anchors the schedule from
	 * now rather than inheriting the old event's timestamp.
	 *
	 * @param string $frequency manual|daily|weekly|monthly.
	 * @return void
	 */
	public static function schedule( $frequency ) {
		self::unschedule_recurring();

		$recurrence = self::recurrence( $frequency );

		if ( '' === $recurrence ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, self::SCHEDULE_HOOK );
	}

	/**
	 * Clear the recurring scan.
	 *
	 * @return void
	 */
	public static function unschedule_recurring() {
		// One read, one write. See unschedule_batch() for why the ask-again
		// shape is not safe against a lock-free, last-write-wins option.
		wp_unschedule_hook( self::SCHEDULE_HOOK );
	}

	/**
	 * Clear both hooks.
	 *
	 * @return void
	 */
	public static function unschedule() {
		self::unschedule_batch();
		self::unschedule_recurring();
	}

	/**
	 * Re-schedule when the frequency setting changes.
	 *
	 * @param mixed $old Previous option value.
	 * @param mixed $new New option value.
	 * @return void
	 */
	public static function settings_changed( $old, $new ) {
		$before = is_array( $old ) && isset( $old['scan_frequency'] ) ? $old['scan_frequency'] : '';
		$after  = is_array( $new ) && isset( $new['scan_frequency'] ) ? $new['scan_frequency'] : '';

		if ( $before === $after ) {
			return;
		}

		self::schedule( $after );
	}

	/**
	 * Map a frequency setting to a cron recurrence.
	 *
	 * @param string $frequency manual|daily|weekly|monthly.
	 * @return string Recurrence key, or '' for no schedule.
	 */
	private static function recurrence( $frequency ) {
		switch ( (string) $frequency ) {
			case 'daily':
				return 'daily';

			case 'weekly':
				return 'weekly';

			case 'monthly':
				return self::MONTHLY;
		}

		return '';
	}

	/**
	 * Whether a bulk run is currently marked active.
	 *
	 * Reads the option directly so this costs one read and does not resolve the
	 * converter and rewriter behind Runner.
	 *
	 * @return bool
	 */
	private static function bulk_is_running() {
		$state = get_option( Runner::PROGRESS_OPTION, array() );

		return is_array( $state ) && ! empty( $state['running'] );
	}
}
