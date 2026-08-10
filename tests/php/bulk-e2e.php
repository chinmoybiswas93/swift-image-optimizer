<?php
/**
 * Bulk scanner + runner behaviour, against real WordPress.
 *
 * Written before the Unit 11 queue rework so there is a regression net around
 * the parts of bulk that must not change: what counts as pending, cursor
 * paging, the lock, cancel semantics, and dry-run being non-destructive.
 *
 *   "$PHP" -d mysqli.default_socket="$SOCK" tests/php/bulk-e2e.php
 *
 * @package SwiftImageOptimizer
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB.DirectDatabaseQuery, Squiz.Commenting, Generic.Commenting, WordPress.PHP.NoSilencedErrors, Universal.Files.SeparateFunctionsFromOO, WordPress.PHP.DiscouragedPHPFunctions

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/wp.php';

use SwiftImageOptimizer\App\Hooks\Scheduler\BulkJobRunner;
use SwiftImageOptimizer\App\Models\OptimizationLog;
use SwiftImageOptimizer\App\Services\Bulk\Runner;
use SwiftImageOptimizer\App\Services\Bulk\Scanner;
use SwiftImageOptimizer\App\Services\Lock;

harness_require_site();
harness_set_baseline_settings( array( 'backup_originals' => true ) );

echo 'engine chain: ', implode( ' -> ', harness_engine_names() ), "\n";

/*
 * A bulk run converts every pending image in the library, not just the
 * harness's own. If real user images are pending, running one here would
 * convert them for real - so the destructive section is skipped instead.
 */
$pre_existing_pending = Scanner::count_pending();
$safe_to_run_bulk     = ( 0 === $pre_existing_pending );

echo 'pre-existing pending: ', $pre_existing_pending, $safe_to_run_bulk ? " (safe to run bulk)\n" : " (bulk run will be SKIPPED)\n";

$created              = array();
$baseline_attachments = harness_count_attachments();
$baseline_log_rows    = harness_count_log_rows();

register_shutdown_function(
	static function () use ( &$created, $baseline_attachments, $baseline_log_rows ) {
		// Always clear bulk state, even on a failure exit, so the next run and
		// the real admin screen do not inherit a half-finished run.
		delete_option( Runner::PROGRESS_OPTION );
		Lock::release( Runner::LOCK );
		BulkJobRunner::unschedule();

		harness_cleanup( $created );

		if ( harness_count_attachments() !== $baseline_attachments || harness_count_log_rows() !== $baseline_log_rows ) {
			fwrite( STDERR, "\nWARNING: state drift after cleanup\n" );
		}
	}
);

// Start from a clean slate rather than whatever a previous run left behind.
delete_option( Runner::PROGRESS_OPTION );
Lock::release( Runner::LOCK );

/* ================================================================ */
Harness::suite( 'Scanner counts' );

$summary_before = Scanner::summary();

Harness::ok( isset( $summary_before['total'] ), 'summary reports total' );
Harness::ok( isset( $summary_before['pending'] ), 'summary reports pending' );
Harness::ok( isset( $summary_before['processed'] ), 'summary reports processed' );
Harness::same(
	$summary_before['total'] - $summary_before['pending'],
	$summary_before['processed'],
	'processed is total minus pending'
);
Harness::ok( $summary_before['pending'] >= 0, 'pending is never negative' );

// Three fixtures, so batch paging has something to page through.
for ( $i = 0; $i < 3; $i++ ) {
	$created[] = harness_import_attachment( harness_make_jpeg( 500, 400 ) );
}

$summary_after_import = Scanner::summary();

Harness::same(
	$summary_before['total'] + 3,
	$summary_after_import['total'],
	'total rises by exactly the three fixtures'
);
Harness::same(
	$summary_before['pending'] + 3,
	$summary_after_import['pending'],
	'an attachment with no log row counts as pending'
);

/* ================================================================ */
Harness::suite( 'Cursor paging' );

$first = Scanner::next_batch( 2, 0 );
Harness::same( 2, count( $first ), 'next_batch respects the limit' );
Harness::ok( $first[0] < $first[1], 'batch is ordered by ascending ID' );

$second = Scanner::next_batch( 2, $first[0] );
Harness::ok( ! in_array( $first[0], $second, true ), 'cursor excludes the ID it was given' );
Harness::ok( $second && $second[0] > $first[0], 'cursor returns only higher IDs' );

$none = Scanner::next_batch( 5, PHP_INT_MAX );
Harness::same( 0, count( $none ), 'a cursor past the end returns nothing' );

/*
 * Pending is defined by the absence of a terminal log row, independent of the
 * cursor. That is what makes the queue idempotent and resumable.
 */
$probe_id = $created[0];
OptimizationLog::upsert(
	$probe_id,
	array(
		'status'     => OptimizationLog::STATUS_OPTIMIZED,
		'created_at' => current_time( 'mysql' ),
	)
);

Harness::same(
	$summary_after_import['pending'] - 1,
	Scanner::count_pending(),
	'a terminal log row removes an image from pending'
);
Harness::ok(
	! in_array( $probe_id, Scanner::next_batch( 50, 0 ), true ),
	'a terminal row is excluded from next_batch regardless of cursor'
);

OptimizationLog::delete( $probe_id );
Harness::same(
	$summary_after_import['pending'],
	Scanner::count_pending(),
	'deleting the log row returns the image to pending'
);

/* ================================================================ */
Harness::suite( 'Lock semantics (invariant 18)' );

Harness::ok( Lock::acquire( Runner::LOCK ), 'lock can be acquired' );
Harness::ok( ! Lock::acquire( Runner::LOCK ), 'a held lock cannot be acquired twice' );
Lock::release( Runner::LOCK );
Harness::ok( Lock::acquire( Runner::LOCK ), 'lock can be re-acquired after release' );
Lock::release( Runner::LOCK );

/* ================================================================ */
Harness::suite( 'Runner state' );

$runner = new Runner( harness_make_converter(), new SwiftImageOptimizer\App\Services\Rewrite\DatabaseRewriter() );

$state = $runner->state();
Harness::ok( is_array( $state ), 'state() returns an array' );
Harness::ok( array_key_exists( 'running', $state ), 'state has a running flag' );
Harness::ok( array_key_exists( 'cursor', $state ), 'state has a cursor' );
Harness::ok( array_key_exists( 'done', $state ), 'state has a done count' );
Harness::ok( ! $state['running'], 'no run is active on a clean slate' );

$started = $runner->start();
Harness::ok( $started['running'], 'start() marks the run active' );
Harness::same( 0, (int) $started['cursor'], 'a fresh run starts at cursor 0' );
Harness::same( 0, (int) $started['done'], 'a fresh run starts with done 0' );
Harness::ok( ! empty( $started['run_id'] ), 'a run id is issued' );

$cancelled = $runner->cancel();
Harness::ok( ! $cancelled['running'], 'cancel() clears the running flag' );

/* ================================================================ */
Harness::suite( 'Server is the authority on whether a run is active' );

/*
 * The reported bug: a second tab could call start() on a live run and reset
 * run_id, cursor and every counter out from under the batch in flight.
 */
$live      = $runner->start();
$live_run  = $live['run_id'];
$again     = $runner->start();

Harness::ok( $again['running'], 'start() on a live run still reports running' );
Harness::same( $live_run, $again['run_id'], 'start() on a live run does NOT re-issue the run id' );

$runner->cancel();

/* ================================================================ */
Harness::suite( 'Stop means pause, not restart' );

/*
 * Resuming is only meaningful with work left over, so this section needs more
 * images than a single batch will take. Three more fixtures and a batch size
 * of one guarantees the run stops part-finished.
 */
for ( $i = 0; $i < 3; $i++ ) {
	$created[] = harness_import_attachment( harness_make_jpeg( 420, 320 ) );
}

$runner->start( true );

$forced               = get_option( Runner::PROGRESS_OPTION );
$forced['batch_size'] = 1;
update_option( Runner::PROGRESS_OPTION, $forced, false );

$runner->process_batch();

Harness::ok( Scanner::count_pending() > 0, 'work remains after the first batch' );

$mid = $runner->state();
Harness::ok( (int) $mid['cursor'] > 0, 'a batch advanced the cursor' );

$paused = $runner->cancel();
Harness::same( (int) $mid['cursor'], (int) $paused['cursor'], 'cancel() keeps the cursor' );
Harness::same( (int) $mid['done'], (int) $paused['done'], 'cancel() keeps the done count' );
Harness::ok( $paused['resumable'], 'a stopped run with progress reports resumable' );

$resumed = $runner->start();
Harness::ok( $resumed['running'], 'start() resumes a stopped run' );
Harness::same( (int) $mid['cursor'], (int) $resumed['cursor'], 'resume continues from the cursor, not from zero' );
Harness::same( (int) $mid['done'], (int) $resumed['done'], 'resume keeps the done count' );
Harness::same( $mid['run_id'], $resumed['run_id'], 'resume keeps the same run id' );

/*
 * fresh=true is ignored while a run is live - the running guard wins, because
 * a batch in flight would overwrite the reset when it saves. Restarting is
 * therefore Stop, then Start(fresh).
 */
$ignored = $runner->start( true );
Harness::same( $mid['run_id'], $ignored['run_id'], 'start(fresh) does not restart a LIVE run' );

$runner->cancel();

$restarted = $runner->start( true );
Harness::same( 0, (int) $restarted['cursor'], 'start(fresh) after stopping resets the cursor' );
Harness::same( 0, (int) $restarted['done'], 'start(fresh) after stopping resets the done count' );
Harness::ok( $restarted['run_id'] !== $mid['run_id'], 'start(fresh) after stopping issues a new run id' );

$runner->cancel();

/* ================================================================ */
Harness::suite( 'Derived state is computed server-side' );

$runner->start( true );
$derived = $runner->state();

Harness::ok( array_key_exists( 'percent', $derived ), 'state exposes a server-computed percent' );
Harness::ok( array_key_exists( 'resumable', $derived ), 'state exposes resumable' );
Harness::ok( array_key_exists( 'stalled', $derived ), 'state exposes stalled' );
Harness::ok( array_key_exists( 'cron_next', $derived ), 'state exposes cron_next' );
Harness::ok( ! array_key_exists( 'pending_rewrite', $derived ), 'internal pending_rewrite is not exposed to clients' );
Harness::ok( $derived['percent'] >= 0 && $derived['percent'] <= 100, 'percent is within 0-100' );
Harness::ok( ! $derived['stalled'], 'a run that just started is not stalled' );

// The derived fields must never be written back into the stored option.
$stored = get_option( Runner::PROGRESS_OPTION );
Harness::ok( is_array( $stored ) && ! array_key_exists( 'percent', $stored ), 'percent is not persisted into the option' );
Harness::ok( is_array( $stored ) && ! array_key_exists( 'stalled', $stored ), 'stalled is not persisted into the option' );
Harness::ok( is_array( $stored ) && array_key_exists( 'pending_rewrite', $stored ), 'pending_rewrite IS kept in the stored option' );

/* ================================================================ */
Harness::suite( 'Cron drives the run' );

Harness::ok(
	(bool) wp_next_scheduled( BulkJobRunner::HOOK ),
	'an active run has a batch queued on cron'
);

$runner->cancel();

Harness::ok(
	! wp_next_scheduled( BulkJobRunner::HOOK ),
	'cancelling clears the queued cron batch'
);

/*
 * The orphan window: a batch renames files and writes terminal log rows, then
 * repoints references in one pass. A crash in between leaves those references
 * broken forever, because Scanner treats a terminal row as finished. The map is
 * parked before the rewrite so the next batch can finish the job.
 */
Harness::suite( 'Interrupted rewrite is recoverable' );

$runner->start( true );

$parked          = $runner->state();
$stored          = get_option( Runner::PROGRESS_OPTION );
$stored['pending_rewrite'] = array( 'https://example.com/never-used-by-this-site.jpg' => 'https://example.com/never-used-by-this-site.webp' );
update_option( Runner::PROGRESS_OPTION, $stored, false );

$after = $runner->process_batch();

Harness::ok( ! is_wp_error( $after ), 'a batch runs with a parked rewrite map' );

$stored_after = get_option( Runner::PROGRESS_OPTION );
Harness::ok(
	empty( $stored_after['pending_rewrite'] ),
	'the parked rewrite map is flushed and cleared by the next batch'
);

$runner->cancel();

/* ================================================================ */
Harness::suite( 'Dry run is non-destructive' );

$files_before = array();

foreach ( $created as $id ) {
	$files_before[ $id ] = get_attached_file( $id );
}

$done_before = (int) $runner->state()['done'];
$report      = $runner->dry_run( 5 );

Harness::ok( is_array( $report ), 'dry_run returns a report' );
Harness::ok( isset( $report['estimated_total'] ), 'dry run reports an estimate' );

$unchanged = true;

foreach ( $files_before as $id => $path ) {
	if ( get_attached_file( $id ) !== $path || ! file_exists( $path ) ) {
		$unchanged = false;
	}
}

Harness::ok( $unchanged, 'dry run changed no files on disk' );
Harness::same( $done_before, (int) $runner->state()['done'], 'dry run did not advance the run counter' );

/* ================================================================ */
Harness::suite( 'Batch processing' );

if ( ! $safe_to_run_bulk ) {
	echo "  SKIP  bulk run - {$pre_existing_pending} real image(s) are pending and would be converted\n";
} else {
	// Earlier sections consumed the fixtures; this one needs its own.
	for ( $i = 0; $i < 2; $i++ ) {
		$created[] = harness_import_attachment( harness_make_jpeg( 480, 360 ) );
	}

	$runner->start( true );
	$after_batch = $runner->process_batch();

	Harness::ok( ! is_wp_error( $after_batch ), 'process_batch() ran' );

	if ( ! is_wp_error( $after_batch ) ) {
		Harness::ok( (int) $after_batch['done'] > 0, 'batch processed at least one image' );
		Harness::ok( (int) $after_batch['cursor'] > 0, 'cursor advanced past the processed IDs' );
		Harness::ok(
			(int) $after_batch['optimized'] + (int) $after_batch['skipped'] + (int) $after_batch['failed'] === (int) $after_batch['done'],
			'optimized + skipped + failed equals done'
		);
	}

	// Drain whatever is left so the library ends where it started.
	$guard = 0;

	while ( $runner->state()['running'] && $guard < 25 ) {
		$result = $runner->process_batch();
		++$guard;

		if ( is_wp_error( $result ) ) {
			break;
		}
	}

	Harness::ok( $guard < 25, 'run drained without hitting the loop guard' );
	Harness::ok( ! $runner->state()['running'], 'run clears its own running flag when the queue empties' );
	Harness::same( 0, Scanner::count_pending(), 'nothing is pending once the run completes' );
}

Harness::summary();
