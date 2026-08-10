<?php
/**
 * Batched bulk optimization.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Services\Bulk;

use SwiftImageOptimizer\Api\StoreSettings;
use SwiftImageOptimizer\App\Services\AttachmentConverter;
use SwiftImageOptimizer\App\Services\Logging\Logger;
use SwiftImageOptimizer\App\Services\Rewrite\DatabaseRewriter;
use SwiftImageOptimizer\App\Services\Lock;
use SwiftImageOptimizer\App\Hooks\Scheduler\BulkJobRunner;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs conversions in batches sized to the server's execution limit.
 *
 * Two things make bulk work at scale. First, the batch size adapts: if a batch
 * takes too long the next one shrinks, so a slow host degrades to one image per
 * request instead of timing out. Second, URL rewriting is deferred and applied
 * once per batch rather than once per image, which turns N table scans into one.
 */
class Runner {

	/**
	 * Option holding run state.
	 */
	const PROGRESS_OPTION = 'swift_image_optimizer_bulk_progress';

	/**
	 * Lock preventing two runs at once. See Services\Lock for why it is not a transient.
	 */
	const LOCK = 'swift_image_optimizer_bulk_lock';

	/**
	 * Default images per batch.
	 */
	const DEFAULT_BATCH = 5;

	/**
	 * Seconds without a completed batch before a run is reported as stalled.
	 *
	 * Comfortably longer than a slow batch, so this means "cron is not firing"
	 * rather than "this batch is taking a while". WP-Cron needs an incoming
	 * request to run at all, so on a quiet site with the tab closed this is
	 * expected rather than a fault - which is why it is surfaced instead of
	 * being treated as an error.
	 */
	const STALL_AFTER = 300;

	/**
	 * Converter instance.
	 *
	 * @var AttachmentConverter
	 */
	private $converter;

	/**
	 * Rewriter instance.
	 *
	 * @var DatabaseRewriter
	 */
	private $rewriter;

	/**
	 * Constructor.
	 *
	 * @param AttachmentConverter $converter Converter instance.
	 * @param DatabaseRewriter    $rewriter  Rewriter instance.
	 */
	public function __construct( AttachmentConverter $converter, DatabaseRewriter $rewriter ) {
		$this->converter = $converter;
		$this->rewriter  = $rewriter;
	}

	/**
	 * Begin, resume, or hand back a run.
	 *
	 * This used to overwrite the progress option unconditionally, which is what
	 * made the reported bug possible: a second tab could call start() on a live
	 * run and reset run_id, cursor and every counter out from under the batch
	 * that was mid-flight. The server is now the authority on whether a run is
	 * active, and start() is idempotent.
	 *
	 * A stopped-but-unfinished run is resumed in place rather than restarted.
	 * The cursor already persists, so this needs no new state - only the
	 * decision to keep it.
	 *
	 * @param bool $fresh Discard any existing progress and start from zero.
	 * @return array Progress state.
	 */
	public function start( $fresh = false ) {
		$state = $this->raw_state();

		if ( ! empty( $state['running'] ) ) {
			/*
			 * Already active. Hand back the live state untouched; do not
			 * re-issue the run id or reset a single counter.
			 *
			 * This wins over $fresh deliberately. A batch may be mid-flight,
			 * and it will write its own copy of the state when it finishes -
			 * so resetting the option here would simply be overwritten a
			 * moment later, which is the very race this guard exists to stop.
			 * Restarting from scratch is therefore Stop, then Start with
			 * $fresh, which is also the more honest confirmation flow.
			 */
			BulkJobRunner::schedule_next();

			return $this->state();
		}

		if ( ! $fresh && $this->is_resumable( $state ) ) {
			$state['running']       = true;
			$state['last_batch_at'] = time();

			update_option( self::PROGRESS_OPTION, $state, false );
			BulkJobRunner::schedule_next();

			Logger::resume_run( isset( $state['run_id'] ) ? $state['run_id'] : '' );
			Logger::mark( 'bulk', 'Run resumed at cursor ' . (int) $state['cursor'] . ', ' . (int) $state['done'] . ' already done.' );

			return $this->state();
		}

		$summary = Scanner::summary();

		// One identifier for the whole run, persisted so every batch request
		// tags its lines the same way and the run reads as one story.
		$run_id = Logger::start_run( 'bulk' );

		$state = array(
			'running'         => true,
			'run_id'          => $run_id,
			'cursor'          => 0,
			'total'           => $summary['pending'],
			'done'            => 0,
			'optimized'       => 0,
			'skipped'         => 0,
			'failed'          => 0,
			'saved'           => 0,
			'batch_size'      => self::DEFAULT_BATCH,
			'errors'          => array(),
			'started_at'      => time(),
			'last_batch_at'   => time(),
			'pending_rewrite' => array(),
		);

		update_option( self::PROGRESS_OPTION, $state, false );
		BulkJobRunner::schedule_next();

		Logger::mark( 'bulk', 'Run started. ' . (int) $summary['pending'] . ' image(s) pending.' );

		return $this->state();
	}

	/**
	 * Whether a stopped run has progress worth continuing from.
	 *
	 * @param array $state Current state.
	 * @return bool
	 */
	private function is_resumable( array $state ) {
		return (int) $state['cursor'] > 0
			&& ! empty( $state['run_id'] )
			&& Scanner::count_pending() > 0;
	}

	/**
	 * Process the next batch.
	 *
	 * @return array|WP_Error Progress state.
	 */
	public function process_batch() {
		if ( ! Lock::acquire( self::LOCK ) ) {
			return new WP_Error( 'bulk-locked', __( 'Another bulk optimization is already running.', 'swift-image-optimizer' ) );
		}

		$state = $this->raw_state();

		if ( empty( $state['running'] ) ) {
			Lock::release( self::LOCK );

			return new WP_Error( 'not-running', __( 'No bulk optimization is in progress.', 'swift-image-optimizer' ) );
		}

		Logger::resume_run( isset( $state['run_id'] ) ? $state['run_id'] : '' );

		$state = $this->flush_pending_rewrite( $state );

		$batch_size = max( 1, (int) $state['batch_size'] );
		$ids        = Scanner::next_batch( $batch_size, (int) $state['cursor'] );

		if ( empty( $ids ) ) {
			$state['running'] = false;
			update_option( self::PROGRESS_OPTION, $state, false );
			Lock::release( self::LOCK );
			BulkJobRunner::unschedule();

			Logger::mark( 'bulk', 'Run finished. Nothing left to process.' );

			return $this->state();
		}

		Logger::info( 'batch', 'Starting a batch of ' . count( $ids ) . '.', 0, array( 'cursor' => (int) $state['cursor'] ) );

		$started      = microtime( true );
		$combined_map = array();

		foreach ( $ids as $id ) {
			$state['cursor'] = max( (int) $state['cursor'], (int) $id );

			// Defer the rewrite so the whole batch is repointed in one pass.
			$result = $this->converter->convert( $id, true );

			++$state['done'];

			if ( is_wp_error( $result ) ) {
				if ( AttachmentConverter::is_soft_error( $result->get_error_code() ) ) {
					++$state['skipped'];
				} else {
					++$state['failed'];

					$state['errors'][] = array(
						'id'      => $id,
						'title'   => get_the_title( $id ),
						'message' => $result->get_error_message(),
					);

					// Keep only the most recent failures so the option stays small.
					$state['errors'] = array_slice( $state['errors'], -50 );
				}

				continue;
			}

			++$state['optimized'];
			$state['saved'] += max( 0, $result['original_size'] - $result['optimized_size'] );

			$combined_map += $result['url_map'];
		}

		if ( ! empty( $combined_map ) && StoreSettings::get( 'rewrite_urls' ) ) {
			/*
			 * Park the map before rewriting, and clear it after.
			 *
			 * Each convert() above has already renamed files and written a
			 * terminal log row, but the references to them are only repointed
			 * by the single replace() below - batching that pass is the reason
			 * batches exist at all. If the process dies in between, those
			 * images are permanently marked done with their references still
			 * pointing at filenames that no longer exist, and nothing would
			 * ever revisit them: Scanner treats a terminal row as finished.
			 *
			 * Surviving that window mattered less when a batch was one
			 * foreground HTTP request. Now that batches also run from cron,
			 * unattended, an OOM kill or a process-manager timeout is an
			 * ordinary occurrence rather than a rarity.
			 */
			$state['pending_rewrite'] = $combined_map;
			update_option( self::PROGRESS_OPTION, $state, false );

			$this->rewriter->replace( $combined_map );

			$state['pending_rewrite'] = array();
		}

		$elapsed               = microtime( true ) - $started;
		$next_size             = $this->next_batch_size( $batch_size, $elapsed );
		$state['last_elapsed'] = round( $elapsed, 2 );

		if ( $next_size !== $batch_size ) {
			Logger::info(
				'batch',
				'Adjusted the batch size to fit the execution limit.',
				0,
				array(
					'from'    => $batch_size,
					'to'      => $next_size,
					'seconds' => round( $elapsed, 2 ),
				)
			);
		}

		$state['batch_size'] = $next_size;

		Logger::info(
			'batch',
			'Batch complete.',
			0,
			array(
				'done'      => (int) $state['done'],
				'total'     => (int) $state['total'],
				'optimized' => (int) $state['optimized'],
				'skipped'   => (int) $state['skipped'],
				'failed'    => (int) $state['failed'],
				'seconds'   => round( $elapsed, 2 ),
			)
		);

		$state['last_batch_at'] = time();

		if ( Scanner::count_pending() <= 0 ) {
			$state['running'] = false;

			Logger::mark( 'bulk', 'Run finished. ' . (int) $state['optimized'] . ' optimized, ' . (int) $state['skipped'] . ' skipped, ' . (int) $state['failed'] . ' failed.' );
		}

		update_option( self::PROGRESS_OPTION, $state, false );
		Lock::release( self::LOCK );

		/*
		 * Re-arm before returning. The browser calls this route in a loop while
		 * someone is watching, and cron calls the same method when nobody is -
		 * whichever gets there first, the run keeps moving, and the shared lock
		 * above stops them overlapping.
		 */
		if ( ! empty( $state['running'] ) ) {
			BulkJobRunner::schedule_next();
		} else {
			BulkJobRunner::unschedule();
		}

		return $this->state();
	}

	/**
	 * Apply a rewrite map left behind by a batch that died before finishing.
	 *
	 * Runs at the top of the next batch, whichever worker gets there first, so
	 * the gap between "files renamed" and "references repointed" closes as soon
	 * as anything picks the run back up.
	 *
	 * @param array $state Current state.
	 * @return array Updated state.
	 */
	private function flush_pending_rewrite( array $state ) {
		if ( empty( $state['pending_rewrite'] ) || ! is_array( $state['pending_rewrite'] ) ) {
			return $state;
		}

		$map = $state['pending_rewrite'];

		Logger::info(
			'batch',
			'Completing a URL rewrite left unfinished by an interrupted batch.',
			0,
			array( 'entries' => count( $map ) )
		);

		if ( StoreSettings::get( 'rewrite_urls' ) ) {
			$this->rewriter->replace( $map );
		}

		$state['pending_rewrite'] = array();
		update_option( self::PROGRESS_OPTION, $state, false );

		return $state;
	}

	/**
	 * Choose the next batch size from how long the last one took.
	 *
	 * Targets roughly half the execution limit, so a batch never gets close to
	 * timing out even if the next images are heavier than the last.
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
			return min( 20, $current + 1 );
		}

		$per_image = $elapsed / max( 1, $current );
		$target    = (int) floor( $budget / $per_image );

		return max( 1, min( 20, $target ) );
	}

	/**
	 * Current run state.
	 *
	 * @return array
	 */
	public function state() {
		$state = $this->raw_state();

		/*
		 * Derived fields. These are computed here rather than in the browser so
		 * that every consumer - the batch response, the status poll, a second
		 * tab - renders the identical number. Two clients each doing their own
		 * arithmetic on different snapshots is what made the progress figures
		 * disagree with each other.
		 */
		$total = (int) $state['total'];
		$done  = (int) $state['done'];

		$state['percent'] = $total > 0 ? (int) min( 100, round( ( $done / $total ) * 100 ) ) : 0;

		/*
		 * Deliberately the same predicate start() uses, including its pending
		 * check. Reporting `resumable` on a run with nothing left would put a
		 * Resume button on screen that silently started a fresh run instead.
		 * It costs one count per status call; the dashboard already runs that
		 * count for its own summary.
		 */
		$state['resumable'] = empty( $state['running'] ) && $this->is_resumable( $state );

		/*
		 * WP-Cron only fires when a request comes in. On a site with no traffic
		 * and no system cron, a backgrounded run genuinely does stall - so say
		 * so rather than let the UI imply progress that is not happening.
		 */
		$since = (int) $state['last_batch_at'];

		$state['stalled']   = ! empty( $state['running'] ) && $since > 0 && ( time() - $since ) > self::STALL_AFTER;
		$next               = wp_next_scheduled( BulkJobRunner::HOOK );
		$state['cron_next'] = $next ? (int) $next : null;

		// Internal bookkeeping: of no use to a client, and potentially large.
		unset( $state['pending_rewrite'] );

		return $state;
	}

	/**
	 * Stored state, with defaults filled in and nothing derived.
	 *
	 * Everything that writes the option goes through this rather than state(),
	 * so the derived fields are never persisted and `pending_rewrite` - which
	 * state() strips - survives a round trip.
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
				'running'         => false,
				'run_id'          => '',
				'cursor'          => 0,
				'total'           => 0,
				'done'            => 0,
				'optimized'       => 0,
				'skipped'         => 0,
				'failed'          => 0,
				'saved'           => 0,
				'batch_size'      => self::DEFAULT_BATCH,
				'errors'          => array(),
				'started_at'      => 0,
				'last_batch_at'   => 0,
				'pending_rewrite' => array(),
			)
		);
	}

	/**
	 * Stop the current run.
	 *
	 * @return array Progress state.
	 */
	public function cancel() {
		$state            = $this->raw_state();
		$state['running'] = false;

		update_option( self::PROGRESS_OPTION, $state, false );
		Lock::release( self::LOCK );
		BulkJobRunner::unschedule();

		Logger::resume_run( isset( $state['run_id'] ) ? $state['run_id'] : '' );
		Logger::mark( 'bulk', 'Run stopped by the user after ' . (int) $state['done'] . ' image(s).' );

		/*
		 * The cursor and counters are deliberately kept. Stop means pause: the
		 * next start() sees a resumable run and continues from here rather than
		 * converting everything again from the beginning.
		 */
		return $this->state();
	}

	/**
	 * Report what a full run would change, without writing anything.
	 *
	 * Samples the pending queue rather than every image, because building a
	 * complete map would mean converting everything first.
	 *
	 * @param int $sample How many attachments to inspect.
	 * @return array Dry-run report.
	 */
	public function dry_run( $sample = 25 ) {
		$ids = Scanner::next_batch( $sample, 0 );
		$map = array();

		foreach ( $ids as $id ) {
			$url = wp_get_attachment_url( $id );

			if ( ! $url ) {
				continue;
			}

			$map[ $url ] = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $url );

			$meta = wp_get_attachment_metadata( $id );

			if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
				continue;
			}

			$base = dirname( $url );

			foreach ( $meta['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}

				$old         = $base . '/' . $size['file'];
				$map[ $old ] = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $old );
			}
		}

		$report = $this->rewriter->replace( $map, true );

		$summary = Scanner::summary();

		$report['sampled']       = count( $ids );
		$report['pending_total'] = $summary['pending'];

		// Extrapolate from the sample so the number means something at library scale.
		$report['estimated_total'] = count( $ids ) > 0
			? (int) round( ( $report['replacements'] / count( $ids ) ) * $summary['pending'] )
			: 0;

		return $report;
	}
}
