<?php
/**
 * The scan, optimize, scan chain behind one Bulk Optimize button.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Services\Bulk;

use SwiftImageOptimizer\App\Services\Logging\Logger;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sequences a full run: scan, optimize what the scan found, scan again.
 *
 * The point of the leading scan is that the figures a run starts from are
 * measured rather than assumed, and the point of the trailing one is that the
 * figures it leaves behind are measured too. Between them, no number on the
 * dashboard is ever inferred from the log table's status column.
 *
 * It is built as a listener rather than by teaching Runner to scan, which would
 * point the dependency the wrong way - Runner is the lower-level piece and has
 * no business knowing a dashboard exists. Both stages already advance
 * themselves from cron, so the chain inherits their backgrounding rather than
 * adding any of its own.
 */
class Coordinator {

	/**
	 * Option holding the current phase.
	 */
	const PHASE_OPTION = 'swift_image_optimizer_bulk_phase';

	/**
	 * Chain id passed to ScanRunner, marking a scan as one of our stages.
	 */
	const CHAIN = 'bulk';

	/**
	 * Phase names.
	 */
	const PHASE_IDLE      = '';
	const PHASE_SCAN_PRE  = 'scanning-before';
	const PHASE_OPTIMIZE  = 'optimizing';
	const PHASE_SCAN_POST = 'scanning-after';

	/**
	 * Share of the overall progress bar each phase owns.
	 *
	 * The scans are quick relative to conversion, so they take a tenth each.
	 * Without this the bar would sit at nothing through a long optimize stage
	 * and then leap, which reads as a stall.
	 *
	 * @var array<string, int>
	 */
	const WEIGHTS = array(
		self::PHASE_SCAN_PRE  => 10,
		self::PHASE_OPTIMIZE  => 80,
		self::PHASE_SCAN_POST => 10,
	);

	/**
	 * Scan engine.
	 *
	 * @var ScanRunner
	 */
	private $scanner;

	/**
	 * Bulk runner.
	 *
	 * @var Runner
	 */
	private $runner;

	/**
	 * Wire up the scan and bulk-run engines the coordinator chains together.
	 *
	 * @param ScanRunner $scanner Scan engine.
	 * @param Runner     $runner  Bulk runner.
	 */
	public function __construct( ScanRunner $scanner, Runner $runner ) {
		$this->scanner = $scanner;
		$this->runner  = $runner;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'swift_image_optimizer_scan_completed', array( $this, 'on_scan_completed' ), 10, 3 );
		add_action( 'swift_image_optimizer_bulk_completed', array( $this, 'on_bulk_completed' ) );
	}

	/**
	 * Begin a full run.
	 *
	 * @return array|WP_Error Phase state.
	 */
	public function start_full_run() {
		if ( $this->runner_is_running() ) {
			// Already optimizing. Hand back the live phase rather than
			// restarting anything; invariant 24, same as Runner::start().
			return $this->state();
		}

		/*
		 * A standalone scan may be in flight. Cancel it rather than wait: bulk
		 * is the more valuable work, and the chain's own leading scan replaces
		 * whatever that one would have published.
		 */
		if ( ScanRunner::is_running() ) {
			$this->scanner->cancel();
		}

		$this->set_phase( self::PHASE_SCAN_PRE );

		$started = $this->scanner->start( 'bulk-pre', self::CHAIN );

		if ( is_wp_error( $started ) ) {
			$this->set_phase( self::PHASE_IDLE );

			return $started;
		}

		Logger::mark( 'bulk', 'Full run started: scanning before optimizing.' );

		return $this->state();
	}

	/**
	 * Advance the chain when a scan finishes.
	 *
	 * @param array  $snapshot Published snapshot.
	 * @param string $trigger  What asked for the scan.
	 * @param string $chain    Chain id, '' when standalone.
	 * @return void
	 */
	public function on_scan_completed( $snapshot, $trigger, $chain = '' ) {
		if ( self::CHAIN !== $chain ) {
			return;
		}

		$phase = $this->phase();

		if ( self::PHASE_SCAN_PRE === $phase ) {
			$pending = is_array( $snapshot ) && isset( $snapshot['actionable'] ) ? (int) $snapshot['actionable'] : 0;

			if ( $pending <= 0 ) {
				// Nothing to do, and the snapshot that proves it is already
				// published. Running the optimize and trailing scan stages
				// would only re-measure what was just measured.
				$this->set_phase( self::PHASE_IDLE );

				Logger::mark( 'bulk', 'Full run finished at the scan: nothing to optimize.' );

				return;
			}

			$this->set_phase( self::PHASE_OPTIMIZE );

			/*
			 * $fresh, deliberately. The chain has just measured what is
			 * outstanding, so the run starts from that measurement with its
			 * counters zeroed - which is what stops a monotonic `done` from
			 * being rendered against a total frozen by an earlier run.
			 */
			$this->runner->start( true );

			return;
		}

		if ( self::PHASE_SCAN_POST === $phase ) {
			$this->set_phase( self::PHASE_IDLE );

			Logger::mark( 'bulk', 'Full run complete. Figures refreshed from the library.' );
		}
	}

	/**
	 * Advance the chain when the bulk run finishes.
	 *
	 * @param array $state Final run state.
	 * @return void
	 */
	public function on_bulk_completed( $state ) {
		unset( $state );

		if ( self::PHASE_OPTIMIZE !== $this->phase() ) {
			return;
		}

		$this->set_phase( self::PHASE_SCAN_POST );

		$started = $this->scanner->start( 'bulk-post', self::CHAIN );

		if ( is_wp_error( $started ) ) {
			// Nothing left to drive the chain, so do not leave the UI showing a
			// phase that will never advance.
			$this->set_phase( self::PHASE_IDLE );

			Logger::mark( 'bulk', 'Could not start the closing scan: ' . $started->get_error_message() );
		}
	}

	/**
	 * Stop everything the chain owns.
	 *
	 * @return array Phase state.
	 */
	public function cancel() {
		$this->runner->cancel();
		$this->scanner->cancel();
		$this->set_phase( self::PHASE_IDLE );

		return $this->state();
	}

	/**
	 * The whole picture the dashboard polls.
	 *
	 * One request answers what is happening, how far along it is, and what the
	 * library looks like - so the ring, the bar and the tiles cannot disagree
	 * by being fetched at different moments.
	 *
	 * @return array
	 */
	public function state() {
		$phase = $this->phase();
		$scan  = $this->scanner->state();
		$bulk  = $this->runner->state();

		$percent = $this->overall_percent( $phase, $scan, $bulk );

		return array(
			'phase'          => $phase,
			'phase_label'    => $this->phase_label( $phase, $bulk ),
			'phase_index'    => $this->phase_index( $phase ),
			'phase_count'    => count( self::WEIGHTS ),
			'percent'        => $percent,
			'busy'           => self::PHASE_IDLE !== $phase || ! empty( $bulk['running'] ) || ! empty( $scan['running'] ),
			'scan'           => $scan,
			'bulk'           => $bulk,
			'snapshot'       => isset( $scan['snapshot'] ) ? $scan['snapshot'] : null,
			'snapshot_stale' => ! empty( $scan['snapshot_stale'] ),
		);
	}

	/**
	 * Overall progress across the three phases.
	 *
	 * Computed on the server for the same reason every other derived figure is
	 * (invariant 24): one number, rendered identically everywhere.
	 *
	 * @param string $phase Current phase.
	 * @param array  $scan  Scan state.
	 * @param array  $bulk  Bulk state.
	 * @return int
	 */
	private function overall_percent( $phase, array $scan, array $bulk ) {
		$scan_percent = isset( $scan['percent'] ) ? (int) $scan['percent'] : 0;
		$bulk_percent = isset( $bulk['percent'] ) ? (int) $bulk['percent'] : 0;

		switch ( $phase ) {
			case self::PHASE_SCAN_PRE:
				return (int) round( self::WEIGHTS[ self::PHASE_SCAN_PRE ] * ( $scan_percent / 100 ) );

			case self::PHASE_OPTIMIZE:
				return (int) round(
					self::WEIGHTS[ self::PHASE_SCAN_PRE ]
					+ ( self::WEIGHTS[ self::PHASE_OPTIMIZE ] * ( $bulk_percent / 100 ) )
				);

			case self::PHASE_SCAN_POST:
				return (int) round(
					self::WEIGHTS[ self::PHASE_SCAN_PRE ] + self::WEIGHTS[ self::PHASE_OPTIMIZE ]
					+ ( self::WEIGHTS[ self::PHASE_SCAN_POST ] * ( $scan_percent / 100 ) )
				);
		}

		// Outside a chain, a standalone bulk run is the only thing to report on.
		return ! empty( $bulk['running'] ) ? $bulk_percent : 0;
	}

	/**
	 * A sentence describing the current phase.
	 *
	 * @param string $phase Current phase.
	 * @param array  $bulk  Bulk state.
	 * @return string
	 */
	private function phase_label( $phase, array $bulk ) {
		switch ( $phase ) {
			case self::PHASE_SCAN_PRE:
				return __( 'Checking your library', 'swift-image-optimizer' );

			case self::PHASE_OPTIMIZE:
				return sprintf(
					/* translators: 1: images optimized so far, 2: images in this run. */
					__( 'Optimizing %1$d of %2$d', 'swift-image-optimizer' ),
					isset( $bulk['done'] ) ? (int) $bulk['done'] : 0,
					isset( $bulk['total'] ) ? (int) $bulk['total'] : 0
				);

			case self::PHASE_SCAN_POST:
				return __( 'Updating your figures', 'swift-image-optimizer' );
		}

		if ( ! empty( $bulk['running'] ) ) {
			return __( 'Optimizing', 'swift-image-optimizer' );
		}

		return '';
	}

	/**
	 * Which of the three steps is current, 1-based. Zero when idle.
	 *
	 * @param string $phase Current phase.
	 * @return int
	 */
	private function phase_index( $phase ) {
		$index = array_search( $phase, array_keys( self::WEIGHTS ), true );

		return false === $index ? 0 : (int) $index + 1;
	}

	/**
	 * The stored phase.
	 *
	 * @return string
	 */
	private function phase() {
		$phase = get_option( self::PHASE_OPTION, self::PHASE_IDLE );

		if ( ! is_string( $phase ) || ! isset( self::WEIGHTS[ $phase ] ) ) {
			return self::PHASE_IDLE;
		}

		return $phase;
	}

	/**
	 * Store the phase.
	 *
	 * @param string $phase Phase name.
	 * @return void
	 */
	private function set_phase( $phase ) {
		if ( self::PHASE_IDLE === $phase ) {
			delete_option( self::PHASE_OPTION );

			return;
		}

		update_option( self::PHASE_OPTION, $phase, false );
	}

	/**
	 * Whether the bulk runner is active.
	 *
	 * @return bool
	 */
	private function runner_is_running() {
		$state = get_option( Runner::PROGRESS_OPTION, array() );

		return is_array( $state ) && ! empty( $state['running'] );
	}
}
