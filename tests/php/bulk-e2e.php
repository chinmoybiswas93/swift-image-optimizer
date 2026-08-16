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

use SwiftImageOptimizer\Api\StoreSettings;
use SwiftImageOptimizer\App\Hooks\Scheduler\BulkJobRunner;
use SwiftImageOptimizer\App\Hooks\Scheduler\ScanJobRunner;
use SwiftImageOptimizer\App\Models\OptimizationLog;
use SwiftImageOptimizer\App\Services\Bulk\Coordinator;
use SwiftImageOptimizer\App\Services\Bulk\Runner;
use SwiftImageOptimizer\App\Services\Bulk\Scanner;
use SwiftImageOptimizer\App\Services\Bulk\ScanRunner;
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

		// Same for the scan, whose state is separate. The published snapshot
		// goes too: one taken over harness fixtures describes a library that no
		// longer exists, and the admin screen would render it as current.
		delete_option( ScanRunner::PROGRESS_OPTION );
		delete_option( ScanRunner::RESULT_OPTION );
		delete_option( Coordinator::PHASE_OPTION );
		Lock::release( ScanRunner::LOCK );
		ScanJobRunner::unschedule();

		harness_cleanup( $created );

		if ( harness_count_attachments() !== $baseline_attachments || harness_count_log_rows() !== $baseline_log_rows ) {
			fwrite( STDERR, "\nWARNING: state drift after cleanup\n" );
		}
	}
);

// Start from a clean slate rather than whatever a previous run left behind.
delete_option( Runner::PROGRESS_OPTION );
Lock::release( Runner::LOCK );
delete_option( ScanRunner::PROGRESS_OPTION );
delete_option( Coordinator::PHASE_OPTION );
Lock::release( ScanRunner::LOCK );

/**
 * Run a scan to completion and return the published snapshot.
 *
 * @param ScanRunner $scanner    Scan engine.
 * @param int        $batch_size Force a batch size, 0 to leave the default.
 * @return array|null
 */
function harness_scan_to_completion( ScanRunner $scanner, $batch_size = 0 ) {
	$started = $scanner->start( 'manual', '', true );

	if ( is_wp_error( $started ) ) {
		return null;
	}

	if ( $batch_size > 0 ) {
		$state               = get_option( ScanRunner::PROGRESS_OPTION );
		$state['batch_size'] = $batch_size;
		update_option( ScanRunner::PROGRESS_OPTION, $state, false );
	}

	$guard = 0;

	while ( ScanRunner::is_running() && $guard < 500 ) {
		$result = $scanner->process_batch();
		++$guard;

		if ( is_wp_error( $result ) ) {
			break;
		}
	}

	return ScanRunner::snapshot();
}

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

/* ================================================================ */
Harness::suite( 'Scan buckets account for every image' );

$scanner = new ScanRunner();

// Two fresh fixtures, so there is something in the pending bucket whatever the
// sections above left behind.
for ( $i = 0; $i < 2; $i++ ) {
	$created[] = harness_import_attachment( harness_make_jpeg( 360, 300 ) );
}

$snapshot = harness_scan_to_completion( $scanner );

Harness::ok( is_array( $snapshot ), 'a scan publishes a snapshot' );

$bucket_sum = (int) $snapshot['optimized']
	+ (int) $snapshot['skipped_permanent']
	+ (int) $snapshot['skipped_retryable']
	+ (int) $snapshot['failed']
	+ (int) $snapshot['pending']
	+ (int) $snapshot['unknown'];

/*
 * The property the old dashboard could not hold. Its "already processed" was
 * total minus pending - a subtraction, not a count - so the figures had no
 * reason to reconcile and did not.
 */
Harness::same( (int) $snapshot['total_images'], $bucket_sum, 'the buckets sum to total_images' );

Harness::ok( (int) $snapshot['total_images'] > 0, 'the scan found images' );
Harness::ok( (int) $snapshot['pending'] >= 2, 'the two new fixtures are pending' );
Harness::ok( $snapshot['percent'] >= 0 && $snapshot['percent'] <= 100, 'percent is within 0-100' );
Harness::same(
	(int) $snapshot['total_images'] - (int) $snapshot['optimized'],
	(int) $snapshot['unresolved'],
	'unresolved is everything not optimized'
);
Harness::same(
	(int) $snapshot['skipped_retryable'] + (int) $snapshot['failed'],
	(int) $snapshot['requeueable'],
	'requeueable is retryable skips plus failures'
);
Harness::same( (int) $snapshot['pending'], (int) $snapshot['actionable'], 'actionable is the pending bucket' );
Harness::ok( (int) $snapshot['completed_at'] > 0, 'the snapshot is timestamped' );
Harness::same( ScanRunner::SNAPSHOT_VERSION, (int) $snapshot['version'], 'the snapshot records its shape version' );

/*
 * A converted image keeps its place in the denominator. This is the specific
 * defect behind the report: post_mime_type becomes image/webp on success, and
 * the old summary counted only jpeg and png - so every success made both the
 * total and the processed count smaller.
 */
$webp_probe = $created[ count( $created ) - 1 ];

OptimizationLog::upsert(
	$webp_probe,
	array(
		'status'         => OptimizationLog::STATUS_OPTIMIZED,
		'optimized_file' => wp_basename( get_attached_file( $webp_probe ) ),
		'original_size'  => 1000,
		'optimized_size' => 400,
		'created_at'     => current_time( 'mysql' ),
	)
);

$after_optimized = harness_scan_to_completion( $scanner );

Harness::same(
	(int) $snapshot['total_images'],
	(int) $after_optimized['total_images'],
	'marking an image optimized does NOT shrink total_images'
);
Harness::same(
	(int) $snapshot['optimized'] + 1,
	(int) $after_optimized['optimized'],
	'the optimized bucket grew by one'
);
Harness::same(
	(int) $snapshot['pending'] - 1,
	(int) $after_optimized['pending'],
	'the pending bucket shrank by one'
);
// A delta, not an absolute: the real library already holds optimized rows, so
// asserting the total here would only be measuring this install's history.
Harness::same(
	(int) $snapshot['saved_bytes'] + 600,
	(int) $after_optimized['saved_bytes'],
	'saved_bytes grew by exactly original minus optimized'
);

/* ================================================================ */
Harness::suite( 'The scan asks the disk, not the column (invariant 22)' );

/*
 * The row still claims optimized; the file is gone. A scan that trusted the
 * column would keep counting it as done forever, which is exactly how a
 * restored site reported a fully processed library it could not optimize.
 */
$vanished = get_attached_file( $webp_probe );
$stashed  = $vanished . '.stashed';

rename( $vanished, $stashed );

$after_vanish = harness_scan_to_completion( $scanner );

Harness::same(
	(int) $after_optimized['optimized'] - 1,
	(int) $after_vanish['optimized'],
	'an optimized row whose file is missing leaves the optimized bucket'
);
Harness::same(
	(int) $after_optimized['pending'] + 1,
	(int) $after_vanish['pending'],
	'...and lands back in pending'
);
Harness::same(
	(int) $after_optimized['total_images'],
	(int) $after_vanish['total_images'],
	'...without changing the total'
);

/*
 * And it observes without mutating. Deleting the row is Scanner::rescan()'s
 * job; a scan that runs unattended on a schedule must not touch the log table.
 */
Harness::ok(
	null !== OptimizationLog::find( $webp_probe ),
	'the scan did NOT delete the stale log row'
);

rename( $stashed, $vanished );
OptimizationLog::delete( $webp_probe );

/* ================================================================ */
Harness::suite( 'Cursor paging produces the same answer' );

$paged = harness_scan_to_completion( $scanner, 1 );
$whole = harness_scan_to_completion( $scanner, 10000 );

foreach ( array( 'total_images', 'optimized', 'pending', 'skipped_permanent', 'skipped_retryable', 'failed', 'unknown' ) as $bucket ) {
	Harness::same( (int) $whole[ $bucket ], (int) $paged[ $bucket ], "one-per-batch matches one-big-batch: {$bucket}" );
}

Harness::ok( (int) $paged['batches'] > (int) $whole['batches'], 'a smaller batch size really did take more batches' );

/* ================================================================ */
Harness::suite( 'Scan locking and refusal' );

Harness::ok( Lock::acquire( ScanRunner::LOCK ), 'the scan lock can be acquired' );

$scanner->start( 'manual', '', true );
$locked_out = $scanner->process_batch();

Harness::ok( is_wp_error( $locked_out ), 'a batch is refused while the lock is held' );
Harness::same( 'scan-locked', $locked_out->get_error_code(), '...with the scan-locked code' );

Lock::release( ScanRunner::LOCK );
$scanner->cancel();

Harness::ok( ! ScanRunner::is_running(), 'cancel() clears the running flag' );
Harness::ok(
	is_array( ScanRunner::snapshot() ),
	'cancelling leaves the previously published snapshot intact'
);

/*
 * A scan taken mid-run would be obsolete before it published, and the ring
 * would jump backwards when it landed. The chain's own stages are exempt.
 */
$runner->start( true );

$refused = $scanner->start( 'manual' );

Harness::ok( is_wp_error( $refused ), 'a standalone scan is refused while a bulk run is active' );
Harness::same( 'bulk-active', $refused->get_error_code(), '...with the bulk-active code' );

$chained = $scanner->start( 'bulk-pre', Coordinator::CHAIN );

Harness::ok( ! is_wp_error( $chained ), 'a chained scan is allowed while a bulk run is active' );

$scanner->cancel();
$runner->cancel();

/* ================================================================ */
Harness::suite( 'Scan schedule' );

$sanitized = StoreSettings::sanitize( array( 'scan_frequency' => 'yearly' ) );
Harness::same( 'weekly', $sanitized['scan_frequency'], 'an unknown frequency falls back to weekly' );

foreach ( array( 'manual', 'daily', 'weekly', 'monthly' ) as $frequency ) {
	$round_trip = StoreSettings::sanitize( array( 'scan_frequency' => $frequency ) );
	Harness::same( $frequency, $round_trip['scan_frequency'], "{$frequency} survives sanitize" );
}

$schedules = wp_get_schedules();
Harness::ok( isset( $schedules[ ScanJobRunner::MONTHLY ] ), 'the monthly interval is registered' );
Harness::ok( isset( $schedules['weekly'] ), 'weekly comes from core, not from us' );

ScanJobRunner::schedule( 'monthly' );
Harness::same( ScanJobRunner::MONTHLY, wp_get_schedule( ScanJobRunner::SCHEDULE_HOOK ), 'monthly schedules the custom interval' );

ScanJobRunner::schedule( 'daily' );
Harness::same( 'daily', wp_get_schedule( ScanJobRunner::SCHEDULE_HOOK ), 'changing the frequency re-schedules' );

ScanJobRunner::schedule( 'manual' );
Harness::ok( ! wp_next_scheduled( ScanJobRunner::SCHEDULE_HOOK ), 'manual clears the recurring event' );

/* ================================================================ */
Harness::suite( 'The chain sequences scan, optimize, scan' );

$coordinator = new Coordinator( $scanner, $runner );

Harness::same( '', $coordinator->state()['phase'], 'the chain starts idle' );

$phase_state = $coordinator->state();

Harness::ok( array_key_exists( 'percent', $phase_state ), 'phase state exposes an overall percent' );
Harness::ok( array_key_exists( 'snapshot', $phase_state ), 'phase state carries the snapshot' );
Harness::ok( array_key_exists( 'scan', $phase_state ), 'phase state carries the scan state' );
Harness::ok( array_key_exists( 'bulk', $phase_state ), 'phase state carries the bulk state' );

if ( ! $safe_to_run_bulk ) {
	echo "  SKIP  chain transitions - {$pre_existing_pending} real image(s) are pending\n";
} else {
	$created[] = harness_import_attachment( harness_make_jpeg( 340, 280 ) );

	$coordinator->register();
	$coordinator->start_full_run();

	Harness::same( 'scanning-before', $coordinator->state()['phase'], 'a full run begins by scanning' );

	$percents = array( $coordinator->state()['percent'] );

	// Drive the leading scan; the completion action moves the chain on.
	$guard = 0;

	while ( ScanRunner::is_running() && $guard < 500 ) {
		$scanner->process_batch();
		++$guard;
	}

	$percents[] = $coordinator->state()['percent'];

	Harness::same( 'optimizing', $coordinator->state()['phase'], 'the scan hands over to optimizing' );
	Harness::ok( $runner->state()['running'], '...and the bulk run is live' );
	Harness::same( 0, (int) $runner->state()['done'], '...starting from a zeroed done count' );

	$guard = 0;

	while ( $runner->state()['running'] && $guard < 50 ) {
		$result = $runner->process_batch();
		++$guard;

		if ( is_wp_error( $result ) ) {
			break;
		}
	}

	$percents[] = $coordinator->state()['percent'];

	Harness::same( 'scanning-after', $coordinator->state()['phase'], 'optimizing hands over to the closing scan' );

	$guard = 0;

	while ( ScanRunner::is_running() && $guard < 500 ) {
		$scanner->process_batch();
		++$guard;
	}

	/*
	 * Sampled only while the chain was busy. Once it goes idle the overall
	 * percent is 0 by design - there is nothing running to be a percentage of,
	 * and RunProgress renders only while busy, so that zero never reaches the
	 * screen. Including it here would assert that idle means "complete", which
	 * is not something this state can distinguish from "never started".
	 */
	Harness::same( '', $coordinator->state()['phase'], 'the chain returns to idle' );
	Harness::ok( ! $coordinator->state()['busy'], '...and reports not busy' );
	Harness::same( 0, (int) $coordinator->state()['percent'], '...and reports no progress, having nothing in flight' );

	$monotonic = true;

	for ( $i = 1; $i < count( $percents ); $i++ ) {
		if ( $percents[ $i ] < $percents[ $i - 1 ] ) {
			$monotonic = false;
		}
	}

	Harness::ok( $monotonic, 'overall percent never goes backwards: ' . implode( ' -> ', $percents ) );

	$final = ScanRunner::snapshot();

	Harness::ok( is_array( $final ), 'the chain leaves a fresh snapshot behind' );
	Harness::same( 'bulk-post', $final['trigger'], '...taken by the closing scan' );
	Harness::same( 0, (int) $final['actionable'], '...reporting nothing left to do' );
}

/* ================================================================ */
Harness::suite( 'A frozen total cannot outlive its run' );

/*
 * The reported "369 of 1". total was snapshotted once at start() and never
 * revisited while done kept climbing, so anything that grew the pending set
 * mid-run left the two describing different libraries.
 */
$runner->start( true );

$forced          = get_option( Runner::PROGRESS_OPTION );
$forced['total'] = 1;
$forced['done']  = 369;
update_option( Runner::PROGRESS_OPTION, $forced, false );

$repaired = $runner->state();

Harness::ok(
	(int) $repaired['total'] >= (int) $repaired['done'],
	'total is never left below done: ' . (int) $repaired['done'] . ' of ' . (int) $repaired['total']
);
Harness::ok( $repaired['percent'] <= 100, 'percent stays within bounds' );

$runner->cancel();

Harness::summary();
