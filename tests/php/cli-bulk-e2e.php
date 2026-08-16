<?php
/**
 * The WP-CLI bulk paths, exercised through a real `wp` process.
 *
 * Closes the "not yet exercised" half of I-4: `optimize --all`, `--dry-run`,
 * `--limit`, `--batch`, and `restore --all`. Everything here runs the actual
 * binary rather than calling Commands methods directly, because the flag
 * parsing, the clamps and the WP_CLI::error exit codes are the part that has
 * never been observed.
 *
 *   tests/php/run-cli.sh
 *
 * Portable across sites: the WordPress root comes from this file's location,
 * and the socket, binaries and expected siteurl come from the environment.
 *
 * @package SwiftImageOptimizer
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB.DirectDatabaseQuery, Squiz.Commenting, Generic.Commenting, WordPress.PHP.NoSilencedErrors, Universal.Files.SeparateFunctionsFromOO, WordPress.PHP.DiscouragedPHPFunctions

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/wp.php';

use SwiftImageOptimizer\App\Models\OptimizationLog;
use SwiftImageOptimizer\App\Services\Bulk\Scanner;

/**
 * The site this run is allowed to touch.
 *
 * Local names every database `local` and tells sites apart only by socket, so
 * "it connected" proves nothing. The runner passes the siteurl it believes it
 * pointed at; if the database disagrees, the socket was wrong and every
 * assertion below would be measuring a different site's media library.
 */
$expected_siteurl = getenv( 'SIO_EXPECTED_SITEURL' );
harness_require_site( $expected_siteurl ? $expected_siteurl : 'https://cb-test.local' );

harness_set_baseline_settings( array( 'backup_originals' => true ) );

echo 'engine chain: ', implode( ' -> ', harness_engine_names() ), "\n";

// ---------------------------------------------------------------------------
// The `wp` invocation.
// ---------------------------------------------------------------------------

/**
 * Resolve the pieces of the command line, all overridable per site.
 */
function cli_binaries() {
	static $resolved = null;

	if ( null !== $resolved ) {
		return $resolved;
	}

	$php  = getenv( 'SIO_PHP' );
	$wp   = getenv( 'SIO_WP' );
	$sock = getenv( 'SIO_SOCKET' );

	if ( ! $php || ! is_executable( $php ) ) {
		fwrite( STDERR, "SIO_PHP is not set to an executable PHP binary.\nRun this through tests/php/run-cli.sh, which discovers it.\n" );
		exit( 2 );
	}

	if ( ! $wp || ! file_exists( $wp ) ) {
		fwrite( STDERR, "SIO_WP is not set to a wp-cli phar.\nRun this through tests/php/run-cli.sh, which discovers it.\n" );
		exit( 2 );
	}

	$resolved = array(
		'php'    => $php,
		'wp'     => $wp,
		'socket' => $sock,
		'path'   => ABSPATH,
		'url'    => get_option( 'siteurl' ),
	);

	return $resolved;
}

/**
 * Run one `wp swift-image-optimizer ...` invocation.
 *
 * @param string $args Everything after the command namespace.
 * @return array{code:int,out:string,err:string,all:string}
 */
function cli_run( $args ) {
	$bin = cli_binaries();

	$command = escapeshellarg( $bin['php'] );

	if ( $bin['socket'] ) {
		$command .= ' -d ' . escapeshellarg( 'mysqli.default_socket=' . $bin['socket'] );
	}

	$command .= ' ' . escapeshellarg( $bin['wp'] )
		. ' --path=' . escapeshellarg( $bin['path'] )
		. ' --url=' . escapeshellarg( $bin['url'] )
		. ' swift-image-optimizer ' . $args;

	$descriptors = array(
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	$process = proc_open( $command, $descriptors, $pipes );

	if ( ! is_resource( $process ) ) {
		fwrite( STDERR, "Could not start: {$command}\n" );
		exit( 2 );
	}

	$out = stream_get_contents( $pipes[1] );
	$err = stream_get_contents( $pipes[2] );

	fclose( $pipes[1] );
	fclose( $pipes[2] );

	$code = proc_close( $process );

	echo "\n  \$ wp swift-image-optimizer {$args}\n";

	foreach ( preg_split( '/\R/', trim( $out . "\n" . $err ) ) as $line ) {
		if ( '' !== trim( $line ) ) {
			echo '  | ', $line, "\n";
		}
	}

	return array(
		'code' => $code,
		'out'  => $out,
		'err'  => $err,
		'all'  => $out . "\n" . $err,
	);
}

/**
 * Assert a fragment appears in a command's combined output.
 */
function cli_says( array $result, $needle, $label ) {
	return Harness::ok( false !== strpos( $result['all'], $needle ), $label . " [saw: \"{$needle}\"]" );
}

/**
 * How many of the harness's own fixtures are recorded with a given status.
 */
function cli_status_count( array $ids, $status ) {
	$count = 0;

	foreach ( $ids as $id ) {
		$row = OptimizationLog::find( (int) $id );

		if ( $row && $status === $row['status'] ) {
			++$count;
		}
	}

	return $count;
}

/**
 * Does every fixture still have its original file on disk?
 */
function cli_originals_present( array $ids ) {
	$present = 0;

	foreach ( $ids as $id ) {
		$file = get_attached_file( (int) $id );

		if ( $file && file_exists( $file ) ) {
			++$present;
		}
	}

	return $present;
}

// ---------------------------------------------------------------------------
// Refuse to run over anyone's real media.
//
// `optimize --all` converts every pending image in the library and
// `restore --all` reverts every optimized one. On a site with real media that
// is not a test, it is an unattended bulk edit of user data. The harness only
// runs when the library is otherwise idle.
// ---------------------------------------------------------------------------

$pre_pending = Scanner::count_pending();

global $wpdb;
$log_table    = OptimizationLog::table();
$pre_restorab = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$log_table} WHERE status = %s AND backup_path != ''",
		OptimizationLog::STATUS_OPTIMIZED
	)
);

echo "pre-existing pending: {$pre_pending}\n";
echo "pre-existing restorable: {$pre_restorab}\n";

if ( ( $pre_pending > 0 || $pre_restorab > 0 ) && '1' !== getenv( 'SIO_CLI_ALLOW_DIRTY' ) ) {
	fwrite(
		STDERR,
		"\nREFUSING TO RUN.\n"
		. "This suite runs `optimize --all` and `restore --all`, which act on the whole\n"
		. "library, not just its own fixtures. Found {$pre_pending} pending image(s) and\n"
		. "{$pre_restorab} restorable one(s) that the harness did not create.\n\n"
		. "Run it on a scratch site, or set SIO_CLI_ALLOW_DIRTY=1 if you genuinely want\n"
		. "every pending image on this site converted and every backup restored.\n"
	);
	exit( 2 );
}

// ---------------------------------------------------------------------------
// Fixtures.
// ---------------------------------------------------------------------------

$created              = array();
$baseline_attachments = harness_count_attachments();
$baseline_log_rows    = harness_count_log_rows();

register_shutdown_function(
	static function () use ( &$created, $baseline_attachments, $baseline_log_rows ) {
		harness_cleanup( $created );

		if ( harness_count_attachments() !== $baseline_attachments || harness_count_log_rows() !== $baseline_log_rows ) {
			fwrite( STDERR, "\nWARNING: state drift after cleanup\n" );
		}
	}
);

$fixture_count = 5;

for ( $i = 0; $i < $fixture_count; $i++ ) {
	$source    = harness_make_jpeg( 700 + ( $i * 40 ), 500 + ( $i * 30 ) );
	$created[] = harness_import_attachment( $source );
	unlink( $source );
}

echo 'fixtures: ', implode( ', ', $created ), "\n";

Harness::same( $fixture_count, Scanner::count_pending(), 'the five fixtures are the only pending images' );

// ---------------------------------------------------------------------------
Harness::suite( 'Refusals — a bulk flag is never implied' );
// ---------------------------------------------------------------------------

$bare = cli_run( 'optimize' );
Harness::ok( 0 !== $bare['code'], 'optimize with no flags exits non-zero' );
cli_says( $bare, 'Pass --all', 'optimize with no flags explains the three options' );
Harness::same( $fixture_count, Scanner::count_pending(), 'a refused optimize converted nothing' );

$bare_restore = cli_run( 'restore' );
Harness::ok( 0 !== $bare_restore['code'], 'restore with no flags exits non-zero' );
cli_says( $bare_restore, 'Pass --id', 'restore with no flags names its two options' );

// A limit on its own is not a licence to run.
$limit_only = cli_run( 'optimize --limit=2' );
Harness::ok( 0 !== $limit_only['code'], '--limit without --all is still refused' );
Harness::same( $fixture_count, Scanner::count_pending(), '--limit without --all converted nothing' );

// ---------------------------------------------------------------------------
Harness::suite( 'optimize --dry-run writes nothing' );
// ---------------------------------------------------------------------------

$rows_before = harness_count_log_rows();
$dry         = cli_run( 'optimize --dry-run' );

Harness::same( 0, $dry['code'], 'dry run exits zero' );
cli_says( $dry, 'Pending images: ' . $fixture_count, 'dry run reports the real pending count' );
cli_says( $dry, 'Sampled: ' . $fixture_count, 'dry run samples the pending images' );
cli_says( $dry, 'Estimated total references', 'dry run prints the extrapolated estimate' );
cli_says( $dry, 'Nothing was written', 'dry run says so explicitly' );

Harness::same( $rows_before, harness_count_log_rows(), 'dry run created no log rows' );
Harness::same( $fixture_count, Scanner::count_pending(), 'dry run left every image pending' );
Harness::same( $fixture_count, cli_originals_present( $created ), 'dry run left every original in place' );

// --dry-run wins over --all: the preview must never fall through to the run.
$dry_all = cli_run( 'optimize --all --dry-run' );
Harness::same( 0, $dry_all['code'], '--all --dry-run exits zero' );
cli_says( $dry_all, 'Nothing was written', '--dry-run takes precedence over --all' );
Harness::same( $fixture_count, Scanner::count_pending(), '--all --dry-run converted nothing' );

// ---------------------------------------------------------------------------
Harness::suite( 'optimize --all honours --limit' );
// ---------------------------------------------------------------------------

// batch=1 forces one rewrite pass per image, so the cursor advances five
// times rather than once - the paging path the single-id runs never touched.
$two = cli_run( 'optimize --all --limit=2 --batch=1' );

Harness::same( 0, $two['code'], '--all --limit=2 --batch=1 exits zero' );
cli_says( $two, 'Optimizing 2 of', '--limit caps the announced total' );
Harness::same( $fixture_count - 2, Scanner::count_pending(), '--limit=2 left three images pending' );
Harness::same( 2, cli_status_count( $created, OptimizationLog::STATUS_OPTIMIZED ), 'exactly two fixtures are recorded optimized' );

// batch=0 must clamp to 1 rather than loop forever on an empty batch.
$clamped = cli_run( 'optimize --all --limit=1 --batch=0' );

Harness::same( 0, $clamped['code'], '--batch=0 clamps instead of hanging' );
Harness::same( $fixture_count - 3, Scanner::count_pending(), '--batch=0 still processed exactly one image' );

// A batch larger than the queue must not over-read or double-count.
$rest = cli_run( 'optimize --all --batch=100' );

Harness::same( 0, $rest['code'], '--batch larger than the queue exits zero' );
Harness::same( 0, Scanner::count_pending(), 'the library is fully processed' );
Harness::same( $fixture_count, cli_status_count( $created, OptimizationLog::STATUS_OPTIMIZED ), 'every fixture ended optimized' );

// ---------------------------------------------------------------------------
Harness::suite( 'optimize --all on an empty queue' );
// ---------------------------------------------------------------------------

$again = cli_run( 'optimize --all' );
Harness::same( 0, $again['code'], 'a second --all exits zero rather than erroring' );
cli_says( $again, 'Nothing to do', 'a second --all reports there is nothing to do' );

$over_limit = cli_run( 'optimize --all --limit=999' );
Harness::same( 0, $over_limit['code'], '--limit beyond the queue exits zero' );
cli_says( $over_limit, 'Nothing to do', '--limit beyond the queue does not invent work' );
Harness::same( $fixture_count, cli_status_count( $created, OptimizationLog::STATUS_OPTIMIZED ), 'no fixture was reprocessed' );

// ---------------------------------------------------------------------------
Harness::suite( 'restore --all' );
// ---------------------------------------------------------------------------

$restore = cli_run( 'restore --all' );

Harness::same( 0, $restore['code'], 'restore --all exits zero' );
cli_says( $restore, 'images restored', 'restore --all reports a count' );
Harness::same( $fixture_count, cli_status_count( $created, OptimizationLog::STATUS_RESTORED ), 'every fixture is recorded restored' );
Harness::same( $fixture_count, cli_originals_present( $created ), 'every original JPEG is back on disk' );

// A restored image is not a terminal outcome: it belongs in the queue again.
Harness::same( $fixture_count, Scanner::count_pending(), 'restored images return to the pending queue' );

$restore_again = cli_run( 'restore --all' );
Harness::same( 0, $restore_again['code'], 'a second restore --all exits zero' );
cli_says( $restore_again, 'Nothing to restore', 'a second restore --all finds nothing left' );

// ---------------------------------------------------------------------------
Harness::suite( 'the queue survives a full round trip' );
// ---------------------------------------------------------------------------

$round_trip = cli_run( 'optimize --all --limit=1 --batch=1' );
Harness::same( 0, $round_trip['code'], 'a restored image can be optimized again' );
Harness::same( $fixture_count - 1, Scanner::count_pending(), 'the re-run consumed exactly one image' );

Harness::summary();
