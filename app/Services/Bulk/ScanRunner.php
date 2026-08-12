<?php
/**
 * Batched, disk-verified library scan.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Services\Bulk;

use SwiftImageOptimizer\Api\StoreSettings;
use SwiftImageOptimizer\App\Hooks\Scheduler\ScanJobRunner;
use SwiftImageOptimizer\App\Models\OptimizationLog;
use SwiftImageOptimizer\App\Services\AttachmentConverter;
use SwiftImageOptimizer\App\Services\Lock;
use SwiftImageOptimizer\App\Services\Logging\Logger;
use SwiftImageOptimizer\App\Services\Optimizer;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts what is actually in the library, by asking the disk.
 *
 * Scanner answers "what is left to do", so it filters to convertible mimes and
 * drops terminal rows. That makes it the wrong basis for a dashboard: a
 * converted image becomes image/webp and vanishes from its totals, so the
 * headline figures shrink as the plugin succeeds. Scanner::summary() computed
 * "already processed" as total minus pending - a subtraction between two
 * numbers falling together, which is how a library with hundreds of optimized
 * rows reported three.
 *
 * This asks a different question: what is in the library, and what state is
 * each image in. Every image counts, WebP included, so nothing leaves the
 * denominator by being optimized. Classification is decided against the disk
 * rather than the status column, because a column describing a file is not
 * evidence the file exists (invariant 22).
 *
 * Shaped like Runner on purpose - one state-machine idiom, one lock idiom, one
 * cron idiom - with three deviations noted at cancel(), start() and
 * next_batch_size(). All three come from the same fact: a partial scan is only
 * a count and counting again is cheap, whereas partial conversion work is
 * irreversible.
 */
class ScanRunner {

	/**
	 * Option holding the last completed snapshot.
	 */
	const RESULT_OPTION = 'swift_image_optimizer_library_scan';

	/**
	 * Option holding in-flight scan state.
	 */
	const PROGRESS_OPTION = 'swift_image_optimizer_scan_progress';

	/**
	 * Lock preventing two scans at once. See Services\Lock for why not a transient.
	 */
	const LOCK = 'swift_image_optimizer_scan_lock';

	/**
	 * Snapshot shape version. A mismatch marks a stored snapshot stale.
	 */
	const SNAPSHOT_VERSION = 1;

	/**
	 * Default attachments per batch.
	 */
	const DEFAULT_BATCH = 100;

	/**
	 * Ceiling on the adaptive batch size.
	 *
	 * Twenty-five times Runner's, because the work is not comparable: a batch
	 * here is a stat and an array write per image, not a decode and re-encode.
	 */
	const MAX_BATCH = 500;

	/**
	 * Seconds without a completed batch before a scan is reported as stalled.
	 *
	 * Same value and same reasoning as Runner::STALL_AFTER: WP-Cron only fires
	 * when a request arrives, so on a quiet site with the tab closed this means
	 * "cron is not firing", not "something broke".
	 */
	const STALL_AFTER = 300;

	/**
	 * Mimes that are images but never raster candidates.
	 */
	const EXCLUDED_MIMES = array( 'image/svg+xml' );

	/**
	 * Begin a scan, or hand back the one already running.
	 *
	 * Idempotent for the same reason Runner::start() is (invariant 24): the
	 * server is the authority on whether a scan is active, and a second tab
	 * must not reset the cursor out from under a batch that is mid-flight.
	 *
	 * Unlike Runner there is no resume branch. A stopped scan restarts at zero
	 * rather than continuing, because the only thing a partial scan holds is a
	 * partial count, and publishing that would be worse than recounting.
	 *
	 * @param string $trigger What asked for this scan: manual|schedule|bulk-pre|bulk-post|activation|cli.
	 * @param string $chain   Chain id when this scan is a stage of a larger run, '' otherwise.
	 * @param bool   $force   Discard a scan already in flight and start over.
	 * @return array|WP_Error Scan state.
	 */
	public function start( $trigger = 'manual', $chain = '', $force = false ) {
		$state = $this->raw_state();

		if ( ! empty( $state['running'] ) ) {
			/*
			 * Without $force this is the idempotent branch: hand back the live
			 * scan and re-arm it. With it, the caller is explicitly asking to
			 * start again - which is what the Scan button does once a scan has
			 * stalled, and the only way out of a scan whose cron never fired.
			 * Re-arming alone would not help there: the tick is queued, it is
			 * the request to run it that never arrives.
			 */
			if ( ! $force ) {
				ScanJobRunner::schedule_next();

				return $this->state();
			}

			$this->cancel();
		}

		/*
		 * A scan taken while conversions are in flight is obsolete before it
		 * publishes - every batch moves images between buckets underneath it -
		 * and the ring would jump backwards when it landed. The chain's own
		 * stages are exempt: they run at points where Runner is not active.
		 */
		if ( '' === $chain && $this->bulk_is_running() ) {
			return new WP_Error(
				'bulk-active',
				__( 'A bulk optimization is running. The library will be scanned automatically when it finishes.', 'swift-image-optimizer' ),
				array( 'status' => 409 )
			);
		}

		$state = array(
			'running'        => true,
			'cursor'         => 0,
			'scanned'        => 0,
			'total_estimate' => OptimizationLog::countScannableAttachments( self::EXCLUDED_MIMES ),
			'batch_size'     => self::DEFAULT_BATCH,
			'batches'        => 0,
			'trigger'        => (string) $trigger,
			'chain'          => (string) $chain,
			'started_at'     => time(),
			'last_batch_at'  => time(),
			'buckets'        => $this->empty_buckets(),
		);

		update_option( self::PROGRESS_OPTION, $state, false );
		ScanJobRunner::schedule_next();

		Logger::mark( 'scan', 'Library scan started (' . $trigger . '). ' . (int) $state['total_estimate'] . ' image(s) to inspect.' );

		return $this->state();
	}

	/**
	 * Classify the next page of attachments.
	 *
	 * @return array|WP_Error Scan state.
	 */
	public function process_batch() {
		if ( ! Lock::acquire( self::LOCK ) ) {
			return new WP_Error( 'scan-locked', __( 'Another library scan is already running.', 'swift-image-optimizer' ) );
		}

		$state = $this->raw_state();

		if ( empty( $state['running'] ) ) {
			Lock::release( self::LOCK );

			return new WP_Error( 'not-scanning', __( 'No library scan is in progress.', 'swift-image-optimizer' ) );
		}

		$batch_size = max( 1, (int) $state['batch_size'] );
		$rows       = OptimizationLog::attachmentScanPage( (int) $state['cursor'], $batch_size, self::EXCLUDED_MIMES );

		if ( empty( $rows ) ) {
			$snapshot = $this->finish( $state );

			Lock::release( self::LOCK );

			return $this->state( $snapshot );
		}

		$started = microtime( true );

		/*
		 * Prime the meta cache for the whole page before touching any of it.
		 * get_attached_file() reads _wp_attached_file, and without this every
		 * single image costs its own query - which is the difference between a
		 * scan that finishes and one that does not on the large libraries this
		 * feature exists to serve.
		 */
		$ids = array();

		foreach ( $rows as $row ) {
			$ids[] = (int) $row['attachment_id'];
		}

		update_postmeta_cache( $ids );

		$buckets = $state['buckets'];

		foreach ( $rows as $row ) {
			$state['cursor'] = max( (int) $state['cursor'], (int) $row['attachment_id'] );

			$this->classify( $row, $buckets );

			++$state['scanned'];
		}

		$state['buckets']       = $buckets;
		$elapsed                = microtime( true ) - $started;
		$state['batch_size']    = $this->next_batch_size( $batch_size, $elapsed );
		$state['last_batch_at'] = time();
		++$state['batches'];

		/*
		 * A short page means the library ended here. Finishing now rather than
		 * paying for one more empty query matters when this runs weekly on
		 * every install forever.
		 */
		if ( count( $rows ) < $batch_size ) {
			$snapshot = $this->finish( $state );

			Lock::release( self::LOCK );

			return $this->state( $snapshot );
		}

		update_option( self::PROGRESS_OPTION, $state, false );
		Lock::release( self::LOCK );
		ScanJobRunner::schedule_next();

		return $this->state();
	}

	/**
	 * Decide which bucket one attachment belongs in, and add it.
	 *
	 * Every branch is mutually exclusive and every row lands somewhere, so the
	 * buckets always sum to the number scanned. That property is the whole
	 * point: the previous dashboard could not add up because its "processed"
	 * figure was a subtraction rather than a count.
	 *
	 * @param array $row     Row from OptimizationLog::attachmentScanPage().
	 * @param array $buckets Bucket totals, modified in place.
	 * @return void
	 */
	private function classify( array $row, array &$buckets ) {
		$id     = (int) $row['attachment_id'];
		$mime   = (string) $row['mime'];
		$status = isset( $row['status'] ) ? (string) $row['status'] : '';
		$reason = isset( $row['reason'] ) ? (string) $row['reason'] : '';

		$file = get_attached_file( $id );

		/*
		 * An offloader (WP Offload Media, Cloudinary and friends) filters
		 * get_attached_file() to a remote path. file_exists() on that is false
		 * for a file that is perfectly fine, which would report a fully
		 * optimized library as fully pending - invariant 22 inverted. Refusing
		 * to classify is the honest answer; guessing either way is not.
		 */
		if ( $file && ! $this->path_is_local( $file ) ) {
			++$buckets['unknown'];

			return;
		}

		if ( OptimizationLog::STATUS_OPTIMIZED === $status ) {
			if ( AttachmentConverter::optimized_output_exists( $id, $row ) ) {
				++$buckets['optimized'];

				$buckets['original_bytes']  += max( 0, (int) $row['original_size'] );
				$buckets['optimized_bytes'] += max( 0, (int) $row['optimized_size'] );

				return;
			}

			/*
			 * The row says optimized and the file is gone. It goes back to
			 * pending, and the row is deliberately left alone: deleting is
			 * Scanner::rescan()'s job, and a scan that runs unattended on a
			 * schedule has no business mutating the log table.
			 */
			++$buckets['pending'];

			return;
		}

		if ( OptimizationLog::STATUS_FAILED === $status ) {
			++$buckets['failed'];

			return;
		}

		if ( OptimizationLog::STATUS_SKIPPED === $status ) {
			if ( AttachmentConverter::is_retryable( $reason ) ) {
				++$buckets['skipped_retryable'];
			} else {
				++$buckets['skipped_permanent'];
			}

			return;
		}

		// STATUS_RESTORED, or no row at all, from here down.
		if ( ! in_array( $mime, Optimizer::CONVERTIBLE, true ) ) {
			// A WebP with no row was uploaded that way; anything else is a
			// format the plugin does not convert. Neither will ever change.
			++$buckets['skipped_permanent'];

			return;
		}

		if ( 'image/png' === $mime && ! StoreSettings::get( 'convert_png' ) ) {
			++$buckets['skipped_permanent'];

			return;
		}

		if ( ! $file || ! file_exists( $file ) ) {
			// Convertible on paper, but there is nothing on disk to convert.
			// Retryable rather than permanent: restoring a backup fixes it.
			++$buckets['skipped_retryable'];

			return;
		}

		++$buckets['pending'];

		$size = (int) filesize( $file );

		if ( $size > 0 ) {
			$buckets['pending_bytes'] += $size;
		}
	}

	/**
	 * Whether a resolved attachment path is a real local file path.
	 *
	 * @param string $file Absolute path from get_attached_file().
	 * @return bool
	 */
	private function path_is_local( $file ) {
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $file ) ) {
			return false;
		}

		$uploads = wp_get_upload_dir();

		if ( empty( $uploads['basedir'] ) ) {
			return true;
		}

		return 0 === strpos( $file, $uploads['basedir'] );
	}

	/**
	 * Publish the finished counts and clear the in-flight state.
	 *
	 * @param array $state Final in-flight state.
	 * @return array The published snapshot.
	 */
	private function finish( array $state ) {
		$buckets = wp_parse_args( $state['buckets'], $this->empty_buckets() );

		$total = (int) $buckets['optimized']
			+ (int) $buckets['skipped_permanent']
			+ (int) $buckets['skipped_retryable']
			+ (int) $buckets['failed']
			+ (int) $buckets['pending']
			+ (int) $buckets['unknown'];

		$original  = max( 0, (int) $buckets['original_bytes'] );
		$optimized = max( 0, (int) $buckets['optimized_bytes'] );
		$saved     = max( 0, $original - $optimized );

		$snapshot = array(
			'version'           => self::SNAPSHOT_VERSION,
			'total_images'      => $total,
			'optimized'         => (int) $buckets['optimized'],
			'skipped_permanent' => (int) $buckets['skipped_permanent'],
			'skipped_retryable' => (int) $buckets['skipped_retryable'],
			'failed'            => (int) $buckets['failed'],
			'pending'           => (int) $buckets['pending'],
			'unknown'           => (int) $buckets['unknown'],

			/*
			 * Derived here, not in the browser (invariant 24), so the ring, the
			 * hero and any second tab all render the identical number.
			 *
			 * Permanent skips stay in this denominator by decision: the ring
			 * measures how much of the library is optimized, so an image that
			 * will never be optimized is honestly unresolved. It caps the ring
			 * below 100% on a finished library, which the UI states in words
			 * and draws as a second muted arc.
			 */
			'percent'           => $total > 0 ? (int) min( 100, round( ( (int) $buckets['optimized'] / $total ) * 100 ) ) : 0,
			'unresolved'        => max( 0, $total - (int) $buckets['optimized'] ),

			/*
			 * What a bulk run would take right now, versus what needs Requeue
			 * first. Scanner::next_batch() skips every terminal row, so
			 * retryable skips and failures are not "still to do" until
			 * Scanner::requeue() deletes them - reporting them together is a
			 * smaller version of the confusion this unit exists to fix.
			 */
			'actionable'        => (int) $buckets['pending'],
			'requeueable'       => (int) $buckets['skipped_retryable'] + (int) $buckets['failed'],

			'original_bytes'    => $original,
			'optimized_bytes'   => $optimized,
			'saved_bytes'       => $saved,
			'saved_percent'     => $original > 0 ? round( ( $saved / $original ) * 100, 1 ) : 0.0,
			'pending_bytes'     => max( 0, (int) $buckets['pending_bytes'] ),

			// The regime this was measured under, so the UI can say when a
			// settings change has made it stale.
			'convert_png'       => StoreSettings::get( 'convert_png' ) ? 1 : 0,
			'trigger'           => isset( $state['trigger'] ) ? (string) $state['trigger'] : 'manual',
			'started_at'        => (int) $state['started_at'],
			'completed_at'      => time(),
			'duration_ms'       => max( 0, ( time() - (int) $state['started_at'] ) * 1000 ),
			'batches'           => (int) $state['batches'],
		);

		update_option( self::RESULT_OPTION, $snapshot, false );
		delete_option( self::PROGRESS_OPTION );
		ScanJobRunner::unschedule_batch();

		Logger::mark(
			'scan',
			'Library scan finished. ' . $total . ' image(s): ' . (int) $snapshot['optimized'] . ' optimized, '
			. (int) $snapshot['pending'] . ' pending, ' . (int) $snapshot['skipped_permanent'] . ' permanently skipped, '
			. (int) $snapshot['skipped_retryable'] . ' retryable, ' . (int) $snapshot['failed'] . ' failed.'
		);

		/**
		 * Fires when a library scan publishes a snapshot.
		 *
		 * @param array  $snapshot The published snapshot.
		 * @param string $trigger  What asked for the scan.
		 * @param string $chain    Chain id, '' when standalone.
		 */
		do_action(
			'swift_image_optimizer_scan_completed',
			$snapshot,
			$snapshot['trigger'],
			isset( $state['chain'] ) ? (string) $state['chain'] : ''
		);

		return $snapshot;
	}

	/**
	 * Abandon the running scan.
	 *
	 * Unlike Runner::cancel(), which keeps its cursor so Stop means pause, this
	 * throws the partial counts away. It also leaves the last published
	 * snapshot untouched, so cancelling never blanks the dashboard - the
	 * figures simply stay as old as their timestamp says they are.
	 *
	 * @return array Scan state.
	 */
	public function cancel() {
		$state = $this->raw_state();

		delete_option( self::PROGRESS_OPTION );
		Lock::release( self::LOCK );
		ScanJobRunner::unschedule_batch();

		if ( ! empty( $state['running'] ) ) {
			Logger::mark( 'scan', 'Library scan cancelled after ' . (int) $state['scanned'] . ' image(s).' );
		}

		return $this->state();
	}

	/**
	 * Current scan state, with derived fields.
	 *
	 * @param array|null $snapshot Snapshot to embed, when the caller already has it.
	 * @return array
	 */
	public function state( $snapshot = null ) {
		$state = $this->raw_state();

		$total = (int) $state['total_estimate'];
		$done  = (int) $state['scanned'];

		$state['percent'] = $total > 0 ? (int) min( 100, round( ( $done / $total ) * 100 ) ) : 0;

		$since = (int) $state['last_batch_at'];

		$state['stalled']   = ! empty( $state['running'] ) && $since > 0 && ( time() - $since ) > self::STALL_AFTER;
		$next               = wp_next_scheduled( ScanJobRunner::HOOK );
		$state['cron_next'] = $next ? (int) $next : null;

		if ( null === $snapshot ) {
			$snapshot = self::snapshot();
		}

		$state['snapshot']       = $snapshot;
		$state['snapshot_stale'] = self::snapshot_is_stale( $snapshot );

		// Internal bookkeeping; the client renders the snapshot, not the tally.
		unset( $state['buckets'] );

		return $state;
	}

	/**
	 * Stored in-flight state with defaults filled in and nothing derived.
	 *
	 * @return array
	 */
	private function raw_state() {
		$state = get_option( self::PROGRESS_OPTION, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return wp_parse_args(
			$state,
			array(
				'running'        => false,
				'cursor'         => 0,
				'scanned'        => 0,
				'total_estimate' => 0,
				'batch_size'     => self::DEFAULT_BATCH,
				'batches'        => 0,
				'trigger'        => '',
				'chain'          => '',
				'started_at'     => 0,
				'last_batch_at'  => 0,
				'buckets'        => $this->empty_buckets(),
			)
		);
	}

	/**
	 * A zeroed bucket tally.
	 *
	 * @return array
	 */
	private function empty_buckets() {
		return array(
			'optimized'         => 0,
			'skipped_permanent' => 0,
			'skipped_retryable' => 0,
			'failed'            => 0,
			'pending'           => 0,
			'unknown'           => 0,
			'original_bytes'    => 0,
			'optimized_bytes'   => 0,
			'pending_bytes'     => 0,
		);
	}

	/**
	 * Choose the next batch size from how long the last one took.
	 *
	 * Same budget as Runner::next_batch_size() - half the execution limit - with
	 * a far higher ceiling, because a batch here is a stat per image rather
	 * than a decode and re-encode.
	 *
	 * @param int   $current Current batch size.
	 * @param float $elapsed Seconds the last batch took.
	 * @return int
	 */
	private function next_batch_size( $current, $elapsed ) {
		$limit = (int) ini_get( 'max_execution_time' );

		// A limit of 0 means unlimited, common on CLI.
		$budget = $limit > 0 ? $limit / 2 : 15;

		if ( $elapsed <= 0.01 ) {
			return (int) min( self::MAX_BATCH, $current * 2 );
		}

		$per_image = $elapsed / max( 1, $current );
		$target    = (int) floor( $budget / $per_image );

		return (int) max( 1, min( self::MAX_BATCH, $target ) );
	}

	/**
	 * Whether a bulk run is currently active.
	 *
	 * Reads the option rather than resolving Runner, which would pull in the
	 * converter and the rewriter to answer a yes/no question.
	 *
	 * @return bool
	 */
	private function bulk_is_running() {
		$state = get_option( Runner::PROGRESS_OPTION, array() );

		return is_array( $state ) && ! empty( $state['running'] );
	}

	/**
	 * The last published snapshot, or null when the library has never been scanned.
	 *
	 * @return array|null
	 */
	public static function snapshot() {
		$snapshot = get_option( self::RESULT_OPTION, null );

		return is_array( $snapshot ) && isset( $snapshot['total_images'] ) ? $snapshot : null;
	}

	/**
	 * Whether a snapshot still describes the current configuration.
	 *
	 * A stale snapshot is still rendered - blanking the screen because a
	 * setting changed helps nobody - but it is flagged, so the figures are
	 * never presented as current when they are not.
	 *
	 * @param array|null $snapshot Snapshot to test.
	 * @return bool
	 */
	public static function snapshot_is_stale( $snapshot ) {
		if ( ! is_array( $snapshot ) ) {
			return false;
		}

		if ( ! isset( $snapshot['version'] ) || self::SNAPSHOT_VERSION !== (int) $snapshot['version'] ) {
			return true;
		}

		$now = StoreSettings::get( 'convert_png' ) ? 1 : 0;

		return isset( $snapshot['convert_png'] ) && (int) $snapshot['convert_png'] !== $now;
	}

	/**
	 * Whether a scan is currently marked active.
	 *
	 * @return bool
	 */
	public static function is_running() {
		$state = get_option( self::PROGRESS_OPTION, array() );

		return is_array( $state ) && ! empty( $state['running'] );
	}
}
