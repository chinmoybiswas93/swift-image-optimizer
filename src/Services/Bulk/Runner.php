<?php
/**
 * Batched bulk optimization.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Services\Bulk;

use SwiftImageOptimizer\Repositories\SettingsRepository;
use SwiftImageOptimizer\Services\AttachmentConverter;
use SwiftImageOptimizer\Services\Logging\Logger;
use SwiftImageOptimizer\Services\Rewrite\DatabaseRewriter;
use SwiftImageOptimizer\Support\Lock;
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
	 * Lock preventing two runs at once. See Support\Lock for why it is not a transient.
	 */
	const LOCK = 'swift_image_optimizer_bulk_lock';

	/**
	 * Default images per batch.
	 */
	const DEFAULT_BATCH = 5;

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
	 * Begin a new run.
	 *
	 * @return array Progress state.
	 */
	public function start() {
		$summary = Scanner::summary();

		// One identifier for the whole run, persisted so every batch request
		// tags its lines the same way and the run reads as one story.
		$run_id = Logger::start_run( 'bulk' );

		$state = array(
			'running'    => true,
			'run_id'     => $run_id,
			'cursor'     => 0,
			'total'      => $summary['pending'],
			'done'       => 0,
			'optimized'  => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'saved'      => 0,
			'batch_size' => self::DEFAULT_BATCH,
			'errors'     => array(),
			'started_at' => time(),
		);

		update_option( self::PROGRESS_OPTION, $state, false );

		Logger::mark( 'bulk', 'Run started. ' . (int) $summary['pending'] . ' image(s) pending.' );

		return $state;
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

		$state = $this->state();

		if ( empty( $state['running'] ) ) {
			Lock::release( self::LOCK );

			return new WP_Error( 'not-running', __( 'No bulk optimization is in progress.', 'swift-image-optimizer' ) );
		}

		Logger::resume_run( isset( $state['run_id'] ) ? $state['run_id'] : '' );

		$batch_size = max( 1, (int) $state['batch_size'] );
		$ids        = Scanner::next_batch( $batch_size, (int) $state['cursor'] );

		if ( empty( $ids ) ) {
			$state['running'] = false;
			update_option( self::PROGRESS_OPTION, $state, false );
			Lock::release( self::LOCK );

			Logger::mark( 'bulk', 'Run finished. Nothing left to process.' );

			return $state;
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

		if ( ! empty( $combined_map ) && SettingsRepository::get( 'rewrite_urls' ) ) {
			$this->rewriter->replace( $combined_map );
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

		if ( Scanner::count_pending() <= 0 ) {
			$state['running'] = false;

			Logger::mark( 'bulk', 'Run finished. ' . (int) $state['optimized'] . ' optimized, ' . (int) $state['skipped'] . ' skipped, ' . (int) $state['failed'] . ' failed.' );
		}

		update_option( self::PROGRESS_OPTION, $state, false );
		Lock::release( self::LOCK );

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
		$state = get_option( self::PROGRESS_OPTION, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return wp_parse_args(
			$state,
			array(
				'running'    => false,
				'run_id'     => '',
				'cursor'     => 0,
				'total'      => 0,
				'done'       => 0,
				'optimized'  => 0,
				'skipped'    => 0,
				'failed'     => 0,
				'saved'      => 0,
				'batch_size' => self::DEFAULT_BATCH,
				'errors'     => array(),
			)
		);
	}

	/**
	 * Stop the current run.
	 *
	 * @return array Progress state.
	 */
	public function cancel() {
		$state            = $this->state();
		$state['running'] = false;

		update_option( self::PROGRESS_OPTION, $state, false );
		Lock::release( self::LOCK );

		Logger::resume_run( isset( $state['run_id'] ) ? $state['run_id'] : '' );
		Logger::mark( 'bulk', 'Run stopped by the user after ' . (int) $state['done'] . ' image(s).' );

		return $state;
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
