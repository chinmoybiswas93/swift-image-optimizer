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
Harness::suite( 'Dry run is non-destructive' );

$files_before = array();

foreach ( $created as $id ) {
	$files_before[ $id ] = get_attached_file( $id );
}

$report = $runner->dry_run( 5 );

Harness::ok( is_array( $report ), 'dry_run returns a report' );
Harness::ok( isset( $report['estimated_total'] ), 'dry run reports an estimate' );

$unchanged = true;

foreach ( $files_before as $id => $path ) {
	if ( get_attached_file( $id ) !== $path || ! file_exists( $path ) ) {
		$unchanged = false;
	}
}

Harness::ok( $unchanged, 'dry run changed no files on disk' );
Harness::same( 0, (int) $runner->state()['done'], 'dry run did not advance the run counter' );

/* ================================================================ */
Harness::suite( 'Batch processing' );

if ( ! $safe_to_run_bulk ) {
	echo "  SKIP  bulk run - {$pre_existing_pending} real image(s) are pending and would be converted\n";
} else {
	$runner->start();
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
