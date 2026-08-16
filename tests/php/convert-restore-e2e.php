<?php
/**
 * Convert -> verify -> restore -> verify round trip, against real WordPress.
 *
 * This is the harness that guards the data-loss path. It creates its own
 * attachment, converts it, restores it, and asserts the restored file is
 * byte-identical to the source. It never touches pre-existing media.
 *
 *   SOCK="$HOME/Library/Application Support/Local/run/aRpCXvFUz/mysql/mysqld.sock"
 *   PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
 *   "$PHP" -d mysqli.default_socket="$SOCK" tests/php/convert-restore-e2e.php
 *
 * Optional: SIO_TEST_ENGINE=imagick|cwebp|gd forces one engine for the run.
 *
 * @package SwiftImageOptimizer
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB.DirectDatabaseQuery, Squiz.Commenting, Generic.Commenting, WordPress.PHP.NoSilencedErrors, Universal.Files.SeparateFunctionsFromOO, WordPress.PHP.DiscouragedPHPFunctions

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/wp.php';

use SwiftImageOptimizer\Api\StoreSettings;
use SwiftImageOptimizer\App\Models\OptimizationLog;
use SwiftImageOptimizer\App\Services\AttachmentConverter;
use SwiftImageOptimizer\App\Services\Backup\BackupManager;
use SwiftImageOptimizer\App\Services\Bulk\Scanner;
use SwiftImageOptimizer\App\Http\Controllers\BackupController;
use SwiftImageOptimizer\App\Hooks\Scheduler\JobRunner;
use SwiftImageOptimizer\App\Services\Engine\EngineFactory;

harness_require_site();
harness_set_baseline_settings(
	array(
		'backup_originals' => true,
		'convert_png'      => true,
	)
);

echo 'engine chain: ', implode( ' -> ', harness_engine_names() ), "\n";

$created = array();
$baseline_attachments = harness_count_attachments();
$baseline_log_rows    = harness_count_log_rows();

register_shutdown_function(
	static function () use ( &$created, $baseline_attachments, $baseline_log_rows ) {
		harness_cleanup( $created );

		$attachments = harness_count_attachments();
		$log_rows    = harness_count_log_rows();

		if ( $attachments !== $baseline_attachments || $log_rows !== $baseline_log_rows ) {
			fwrite(
				STDERR,
				sprintf(
					"\nWARNING: state drift after cleanup - attachments %d->%d, log rows %d->%d\n",
					$baseline_attachments,
					$attachments,
					$baseline_log_rows,
					$log_rows
				)
			);
		}
	}
);

/* ================================================================ */
Harness::suite( 'Fixture' );

$source = harness_make_jpeg( 900, 600 );
Harness::ok( file_exists( $source ), 'source JPEG written' );

$source_bytes = file_get_contents( $source );
$source_hash  = md5( $source_bytes );
$source_size  = strlen( $source_bytes );

$attachment_id = harness_import_attachment( $source );
$created[]     = $attachment_id;

Harness::ok( $attachment_id > 0, 'attachment created' );

$original_file = get_attached_file( $attachment_id );
Harness::ok( file_exists( $original_file ), 'attached file exists on disk' );
Harness::same( $source_hash, md5_file( $original_file ), 'imported file matches the source bytes' );
Harness::same( 'image/jpeg', get_post_mime_type( $attachment_id ), 'mime is image/jpeg before conversion' );

$meta_before = wp_get_attachment_metadata( $attachment_id );
Harness::ok( is_array( $meta_before ), 'metadata generated' );
Harness::same( 900, isset( $meta_before['width'] ) ? (int) $meta_before['width'] : 0, 'width recorded' );
Harness::same( 600, isset( $meta_before['height'] ) ? (int) $meta_before['height'] : 0, 'height recorded' );

/* ================================================================ */
Harness::suite( 'Convert' );

$converter = harness_make_converter();
$result    = $converter->convert( $attachment_id );

if ( is_wp_error( $result ) ) {
	Harness::ok( false, 'convert() succeeded (got ' . $result->get_error_code() . ': ' . $result->get_error_message() . ')' );
	Harness::summary();
}

Harness::ok( ! is_wp_error( $result ), 'convert() succeeded' );

clean_post_cache( $attachment_id );
$converted_file = get_attached_file( $attachment_id );

Harness::same( 'webp', strtolower( pathinfo( $converted_file, PATHINFO_EXTENSION ) ), 'attached file is now .webp' );
Harness::ok( file_exists( $converted_file ), 'converted file exists on disk' );
Harness::same( 'image/webp', get_post_mime_type( $attachment_id ), 'mime updated to image/webp' );
Harness::ok( ! file_exists( $original_file ), 'original file removed after successful conversion' );

$meta_after = wp_get_attachment_metadata( $attachment_id );
Harness::same( 900, isset( $meta_after['width'] ) ? (int) $meta_after['width'] : 0, 'width preserved through conversion' );
Harness::same( 600, isset( $meta_after['height'] ) ? (int) $meta_after['height'] : 0, 'height preserved through conversion' );

$log = OptimizationLog::find( $attachment_id );
Harness::ok( is_array( $log ), 'log row written' );
Harness::same( OptimizationLog::STATUS_OPTIMIZED, isset( $log['status'] ) ? $log['status'] : null, 'status is optimized' );
Harness::ok( isset( $log['original_size'] ) && (int) $log['original_size'] === $source_size, 'original_size matches the source' );
Harness::ok( isset( $log['optimized_size'] ) && (int) $log['optimized_size'] > 0, 'optimized_size recorded' );
Harness::ok( ! empty( $log['engine'] ), 'engine recorded: ' . ( isset( $log['engine'] ) ? $log['engine'] : '?' ) );
Harness::ok( ! empty( $log['backup_path'] ), 'backup manifest recorded' );

/*
 * The check that Unit 10 added after files and column drifted apart. A
 * Restore offered against a manifest that cannot be satisfied is worse than
 * no Restore at all.
 */
Harness::ok( BackupManager::manifest_is_intact( $log['backup_path'] ), 'backup manifest is intact on disk' );

/* ================================================================ */
Harness::suite( 'Orientation is not lost (the cwebp bug)' );

/*
 * cwebp cannot rotate and `-metadata icc` discards the EXIF flag that says to,
 * so every portrait photo it converted came out permanently sideways. The
 * engine now declines rotated JPEGs and the chain falls through. Assert on the
 * produced dimensions, not the return value - checking only the return value
 * is exactly how the original bug survived.
 */
$portrait_source = harness_make_jpeg( 400, 800 );
$portrait_id     = harness_import_attachment( $portrait_source );
$created[]       = $portrait_id;

$portrait_meta_before = wp_get_attachment_metadata( $portrait_id );
$portrait_result      = $converter->convert( $portrait_id );

Harness::ok( ! is_wp_error( $portrait_result ), 'portrait image converts' );

clean_post_cache( $portrait_id );
$portrait_meta_after = wp_get_attachment_metadata( $portrait_id );

Harness::same(
	400,
	isset( $portrait_meta_after['width'] ) ? (int) $portrait_meta_after['width'] : 0,
	'portrait width stays 400 - not written sideways'
);
Harness::same(
	800,
	isset( $portrait_meta_after['height'] ) ? (int) $portrait_meta_after['height'] : 0,
	'portrait height stays 800 - not written sideways'
);

$portrait_file = get_attached_file( $portrait_id );
$actual_size   = @getimagesize( $portrait_file );
Harness::same( 400, is_array( $actual_size ) ? $actual_size[0] : 0, 'decoded file width really is 400' );
Harness::same( 800, is_array( $actual_size ) ? $actual_size[1] : 0, 'decoded file height really is 800' );

/* ================================================================ */
Harness::suite( 'Restore' );

$restore = $converter->restore( $attachment_id );

if ( is_wp_error( $restore ) ) {
	Harness::ok( false, 'restore() succeeded (got ' . $restore->get_error_code() . ': ' . $restore->get_error_message() . ')' );
	Harness::summary();
}

Harness::ok( ! is_wp_error( $restore ), 'restore() succeeded' );

clean_post_cache( $attachment_id );
$restored_file = get_attached_file( $attachment_id );

Harness::same( 'jpg', strtolower( pathinfo( $restored_file, PATHINFO_EXTENSION ) ), 'attached file is .jpg again' );
Harness::ok( file_exists( $restored_file ), 'restored file exists on disk' );
Harness::same( 'image/jpeg', get_post_mime_type( $attachment_id ), 'mime reverted to image/jpeg' );

// The assertion the whole backup system exists for.
Harness::same( $source_hash, md5_file( $restored_file ), 'restored file is BYTE-IDENTICAL to the source' );

Harness::ok( ! file_exists( $converted_file ), 'webp file removed after restore' );

$meta_restored = wp_get_attachment_metadata( $attachment_id );
Harness::same( 900, isset( $meta_restored['width'] ) ? (int) $meta_restored['width'] : 0, 'width correct after restore' );
Harness::same( 600, isset( $meta_restored['height'] ) ? (int) $meta_restored['height'] : 0, 'height correct after restore' );

$log_after = OptimizationLog::find( $attachment_id );
Harness::ok(
	! $log_after || OptimizationLog::STATUS_RESTORED === $log_after['status'],
	'log row cleared or marked restored'
);

/*
 * Invariant 3 and the array_flip bug: the url_map is not injective, because
 * several sizes can collapse onto one filename. Flipping it made restore
 * rewrite full-size references to a thumbnail filename and report success.
 */
Harness::suite( 'Double convert is refused' );

$again = $converter->convert( $attachment_id );
$again2 = null;

if ( ! is_wp_error( $again ) ) {
	// It converted again, which is legitimate after a restore. Convert once
	// more to prove the already-optimized guard fires the second time.
	clean_post_cache( $attachment_id );
	$again2 = $converter->convert( $attachment_id );
	Harness::ok( is_wp_error( $again2 ), 'converting an already-optimized image is refused' );
	Harness::same(
		'already-optimized',
		is_wp_error( $again2 ) ? $again2->get_error_code() : null,
		'refusal uses the already-optimized code'
	);
} else {
	Harness::ok( true, 'convert after restore refused: ' . $again->get_error_code() );
	Harness::ok( true, 'guard fired without needing a second pass' );
}

/* ================================================================ */
Harness::suite( 'A record whose file is gone does not block re-optimizing' );

/*
 * The reported scenario: a site restored from a plugin backup where the
 * database says every image is optimized but the uploads directory holds the
 * untouched originals. The library showed "all processed" with no way to
 * optimize anything.
 *
 * Reproduced by converting an image and then deleting the WebP its own log row
 * names, which reaches the same end state.
 */
$stale_id  = harness_import_attachment( harness_make_jpeg( 500, 380 ) );
$created[] = $stale_id;

$stale_result = $converter->convert( $stale_id );
Harness::ok( ! is_wp_error( $stale_result ), 'fixture converted' );

clean_post_cache( $stale_id );
$stale_file = get_attached_file( $stale_id );

Harness::ok(
	AttachmentConverter::optimized_output_exists( $stale_id ),
	'a healthy record reports its output as present'
);

// Remove exactly the one file this attachment's own row names.
unlink( $stale_file );

Harness::ok(
	! AttachmentConverter::optimized_output_exists( $stale_id ),
	'a record whose file is gone is detected as stale'
);

$row_before = OptimizationLog::find( $stale_id );
Harness::same(
	OptimizationLog::STATUS_OPTIMIZED,
	is_array( $row_before ) ? $row_before['status'] : null,
	'the row still claims optimized - the column alone would lie'
);

/*
 * The user-facing symptom was that Optimize was refused outright. Before the
 * disk check, this returned `already-optimized` on the strength of the column
 * alone and there was no way past it.
 */
$blocked = $converter->convert( $stale_id );
Harness::ok(
	! is_wp_error( $blocked ) || 'already-optimized' !== $blocked->get_error_code(),
	'a stale record no longer refuses the image as already-optimized'
);

$rescan = Scanner::rescan();

Harness::ok( $rescan['checked'] > 0, 'rescan inspected records' );
Harness::ok( $rescan['cleared'] >= 0, 'rescan reports how many it cleared' );

/*
 * The stale claim is gone. There may well be a row here - the convert attempt
 * above wrote its own outcome - but nothing now asserts an optimized result
 * for a file that is not there.
 */
$row_now = OptimizationLog::find( $stale_id );
Harness::ok(
	! is_array( $row_now )
		|| OptimizationLog::STATUS_OPTIMIZED !== $row_now['status']
		|| AttachmentConverter::optimized_output_exists( $stale_id, $row_now ),
	'no record claims optimized for a file that is missing'
);

/*
 * Note what clearing the row does and does not do. It stops the library
 * claiming a result that no longer exists, and it unblocks Optimize. It does
 * not put this attachment back in the *bulk* queue, because bulk selects on
 * mime and this attachment's recorded mime is already image/webp. Bulk
 * converts JPEG and PNG; an attachment recorded as WebP is not a candidate no
 * matter what its log row says.
 */
Harness::ok(
	! in_array( get_post_mime_type( $stale_id ), Scanner::mime_types(), true ),
	'a converted attachment is not a bulk candidate regardless of its row'
);

/*
 * Healthy records must survive a rescan untouched, or this would be a way to
 * silently discard real results.
 */
$healthy_id = harness_import_attachment( harness_make_jpeg( 500, 380 ) );
$created[]  = $healthy_id;
$converter->convert( $healthy_id );

$before_rescan = OptimizationLog::find( $healthy_id );
Scanner::rescan();
$after_rescan = OptimizationLog::find( $healthy_id );

Harness::ok( is_array( $after_rescan ), 'a healthy record survives a rescan' );
Harness::same(
	is_array( $before_rescan ) ? $before_rescan['optimized_file'] : null,
	is_array( $after_rescan ) ? $after_rescan['optimized_file'] : null,
	'a healthy record is left alone by a rescan'
);

/* ================================================================ */
Harness::suite( 'Retention expiry collects aged backups via the cron hook' );

/*
 * I-9. Everything else in this file exercises BackupController::purge(), which
 * deliberately drops the expiry and status filters - so the retention query
 * itself (`backup_expires > 0 AND backup_expires < time()`) had never been run
 * by a test at all. This suite drives the real cron hook rather than calling
 * JobRunner::purge() directly, so a purge that works but is not wired to its
 * action still fails here.
 *
 * A backup cannot be genuinely aged inside a test without waiting out the
 * retention window, so the timestamp is moved into the past. What is real is
 * everything else: a real conversion, a real backup on disk, a real expiry
 * written by BackupManager::expiry(), and the real hook firing.
 */
Harness::ok(
	has_action( JobRunner::HOOK ) !== false,
	'the purge is actually hooked to ' . JobRunner::HOOK
);
Harness::ok( wp_next_scheduled( JobRunner::HOOK ) !== false, 'and the daily event is scheduled' );

harness_set_baseline_settings(
	array(
		'convert_png'      => true,
		'backup_retention' => 30,
	)
);

$aged_id   = harness_import_attachment( harness_make_jpeg( 400, 300 ) );
$created[] = $aged_id;

harness_make_converter()->convert( $aged_id );

$aged_row      = OptimizationLog::find( $aged_id );
$aged_manifest = json_decode( (string) $aged_row['backup_path'], true );
$aged_expires  = (int) $aged_row['backup_expires'];

Harness::ok( is_array( $aged_manifest ), 'the conversion stored a manifest' );
Harness::ok( $aged_expires > time(), 'with a real future expiry from the retention setting' );

$aged_dir   = trailingslashit( BackupManager::root() ) . ltrim( (string) $aged_manifest['relative_dir'], '/' );
$aged_first = trailingslashit( $aged_dir ) . ( (array) $aged_manifest['files'] )[0];

Harness::ok( file_exists( $aged_first ), 'and its files on disk' );

// A "keep forever" backup must survive the retention purge. purge() filters on
// backup_expires > 0 precisely so it cannot reach these; purge_manifests() is
// the one that ignores retention, and this proves the two stay different.
harness_set_baseline_settings(
	array(
		'convert_png'      => true,
		'backup_retention' => 0,
	)
);

$forever_id = harness_import_attachment( harness_make_jpeg( 360, 240 ) );
$created[]  = $forever_id;

harness_make_converter()->convert( $forever_id );

$forever_row      = OptimizationLog::find( $forever_id );
$forever_manifest = json_decode( (string) $forever_row['backup_path'], true );
$forever_first    = trailingslashit( trailingslashit( BackupManager::root() ) . ltrim( (string) $forever_manifest['relative_dir'], '/' ) )
	. ( (array) $forever_manifest['files'] )[0];

Harness::same( 0, (int) $forever_row['backup_expires'], 'a kept-forever backup expires at 0' );

// Fire the hook while nothing has aged yet. A wrong comparison here - the
// mistake this query is most likely to contain - collects both backups.
do_action( JobRunner::HOOK );

Harness::ok( file_exists( $aged_first ), 'an unexpired backup survives a cron run' );
Harness::ok(
	'' !== (string) OptimizationLog::find( $aged_id )['backup_path'],
	'and keeps its pointer'
);

// Now age it. This is the one thing the test cannot do for real.
OptimizationLog::update( $aged_id, array( 'backup_expires' => time() - DAY_IN_SECONDS ) );

do_action( JobRunner::HOOK );

$aged_after = OptimizationLog::find( $aged_id );

Harness::ok( ! file_exists( $aged_first ), 'an aged backup is deleted from disk by the cron hook' );
Harness::same( '', (string) $aged_after['backup_path'], 'its pointer is cleared' );
Harness::same( 0, (int) $aged_after['backup_expires'], 'and its expiry reset' );
Harness::same(
	OptimizationLog::STATUS_OPTIMIZED,
	$aged_after['status'],
	'while the image still counts toward the savings stats'
);
Harness::ok(
	! BackupManager::manifest_is_intact( $aged_after['backup_path'] ),
	'so Restore is correctly refused afterwards'
);

Harness::ok( file_exists( $forever_first ), 'the kept-forever backup is untouched by the retention purge' );
Harness::same(
	$forever_row['backup_path'],
	OptimizationLog::find( $forever_id )['backup_path'],
	'and keeps its manifest intact'
);

harness_set_baseline_settings( array( 'convert_png' => true ) );

/* ================================================================ */
Harness::suite( 'Backup purge deletes files, not just records' );

/*
 * This suite empties the backup directory, which is the only way to test a
 * routine whose whole job is to empty the backup directory. It therefore
 * refuses to run if the folder holds anything it did not put there: on a site
 * with real backups it reports a skip rather than destroying them.
 */
$backup_root = BackupManager::root();

$harness_foreign_backups = static function () use ( $backup_root ) {
	if ( ! is_dir( $backup_root ) ) {
		return array();
	}

	$foreign = array();

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $backup_root, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}

		$name = $file->getFilename();

		if ( 'index.php' === $name || '.htaccess' === $name ) {
			continue;
		}

		if ( false !== strpos( $name, 'sio-harness-' ) ) {
			continue;
		}

		$foreign[] = $file->getPathname();
	}

	return $foreign;
};

/*
 * I-8: a backup on disk that no row points at. The purge below proves it can
 * be reclaimed; this proves it can be recovered first, which is the half that
 * gets the user's originals back rather than deleting them.
 */
harness_set_baseline_settings(
	array(
		'convert_png'      => true,
		'backup_retention' => 30,
	)
);

$orphan_id = harness_import_attachment( harness_make_jpeg( 640, 400 ) );
$created[] = $orphan_id;

harness_make_converter()->convert( $orphan_id );

$before_row      = OptimizationLog::find( $orphan_id );
$before_manifest = is_array( $before_row ) && ! empty( $before_row['backup_path'] )
	? json_decode( $before_row['backup_path'], true )
	: null;

Harness::ok( is_array( $before_manifest ), 'the conversion stored a manifest before it was lost' );

$orphan_dir   = trailingslashit( BackupManager::root() ) . ltrim( (string) $before_manifest['relative_dir'], '/' );
$orphan_files = (array) $before_manifest['files'];

// Exactly what a purge, a failed encode, or a death mid-conversion leaves:
// the files untouched, the pointer gone.
OptimizationLog::update(
	$orphan_id,
	array(
		'backup_path'    => '',
		'backup_expires' => 0,
	)
);

$lost_row = OptimizationLog::find( $orphan_id );

Harness::same( '', (string) $lost_row['backup_path'], 'the row no longer points at its backup' );
Harness::ok(
	! BackupManager::manifest_is_intact( $lost_row['backup_path'] ),
	'and Restore is correctly refused while the pointer is gone'
);
Harness::ok(
	file_exists( trailingslashit( $orphan_dir ) . $orphan_files[0] ),
	'but the original is still sitting on disk'
);

// Measured off the folder itself: the harness's own files are excluded from
// $harness_foreign_backups(), so counting that would prove nothing here.
$bytes_before_repair = BackupManager::disk_usage();
$repair              = ( new BackupController() )->reconcile()->get_data();

Harness::ok( $repair['repaired'] >= 1, 'reconcile rebuilds at least one manifest' );
Harness::ok( $bytes_before_repair > 0, 'there were backup bytes on disk to protect' );
Harness::same(
	$bytes_before_repair,
	BackupManager::disk_usage(),
	'and reconcile deletes nothing while doing it'
);

$repaired_row = OptimizationLog::find( $orphan_id );

Harness::ok(
	BackupManager::manifest_is_intact( $repaired_row['backup_path'] ),
	'the rebuilt manifest passes the same disk check the Restore button asks'
);

$repaired_manifest = json_decode( $repaired_row['backup_path'], true );

Harness::same(
	$before_manifest['relative_dir'],
	$repaired_manifest['relative_dir'],
	'the rebuilt manifest names the same directory'
);
Harness::ok(
	in_array( $repaired_row['original_file'], (array) $repaired_manifest['files'], true ),
	'and names the original itself, without which a restore is pointless'
);

sort( $orphan_files );
$rebuilt_files = (array) $repaired_manifest['files'];
sort( $rebuilt_files );

Harness::same( $orphan_files, $rebuilt_files, 'every file the original manifest named is found again' );

// The assertion that matters: not that the column looks right, but that the
// user actually gets their image back.
$repaired_restore = harness_make_converter()->restore( $orphan_id );

Harness::ok( ! is_wp_error( $repaired_restore ), 'a recovered backup can actually be restored' );

$restored_path = get_attached_file( $orphan_id );

Harness::ok(
	$restored_path && file_exists( $restored_path ),
	'and the original file is back in the uploads directory'
);
Harness::same(
	$repaired_row['original_file'],
	wp_basename( (string) $restored_path ),
	'under its original name'
);

// The other direction: a row with no pointer AND no files must stay skipped
// rather than being given a manifest that would fail at restore time.
$gone_id   = harness_import_attachment( harness_make_jpeg( 320, 240 ) );
$created[] = $gone_id;

harness_make_converter()->convert( $gone_id );

$gone_row      = OptimizationLog::find( $gone_id );
$gone_manifest = json_decode( (string) $gone_row['backup_path'], true );

BackupManager::delete( $gone_manifest['relative_dir'], (array) $gone_manifest['files'] );

OptimizationLog::update(
	$gone_id,
	array(
		'backup_path'    => '',
		'backup_expires' => 0,
	)
);

$second_repair = ( new BackupController() )->reconcile()->get_data();

Harness::ok( $second_repair['skipped'] >= 1, 'a row whose files are genuinely gone is skipped' );

// Asserted on this row rather than on the totals: reconcile is site-wide, so
// a site that genuinely has other recoverable backups would make a global
// "repaired == 0" fail for the right reason and look like the wrong one.
Harness::same(
	'',
	(string) OptimizationLog::find( $gone_id )['backup_path'],
	'and is left claiming no backup rather than being given a manifest that would fail'
);
Harness::ok(
	! BackupManager::manifest_is_intact( OptimizationLog::find( $gone_id )['backup_path'] ),
	'so Restore stays correctly refused for it'
);

$foreign_before = $harness_foreign_backups();

if ( $foreign_before ) {
	Harness::ok(
		true,
		sprintf(
			'SKIPPED - the backup folder holds %d file(s) this harness did not create',
			count( $foreign_before )
		)
	);
} else {
	// "Keep forever" is the retention the old purge could never reach.
	harness_set_baseline_settings(
		array(
			'convert_png'      => true,
			'backup_retention' => 0,
		)
	);

	$keep_id   = harness_import_attachment( harness_make_jpeg( 480, 320 ) );
	$created[] = $keep_id;

	harness_make_converter()->convert( $keep_id );

	$keep_row      = OptimizationLog::find( $keep_id );
	$keep_manifest = is_array( $keep_row ) && ! empty( $keep_row['backup_path'] )
		? json_decode( $keep_row['backup_path'], true )
		: null;

	Harness::ok( is_array( $keep_manifest ), 'a kept-forever conversion still stores a backup manifest' );
	Harness::same( 0, is_array( $keep_row ) ? (int) $keep_row['backup_expires'] : -1, 'kept-forever backups expire at 0' );

	$keep_dir   = trailingslashit( $backup_root ) . ltrim( (string) $keep_manifest['relative_dir'], '/' );
	$keep_files = array();

	foreach ( (array) $keep_manifest['files'] as $name ) {
		$keep_files[] = trailingslashit( $keep_dir ) . $name;
	}

	Harness::ok( $keep_files && file_exists( $keep_files[0] ), 'its backup files are on disk before the purge' );

	// An orphan: a file in the backup tree that no log row points at. This is
	// what the folder was left full of (I-8).
	$orphan_dir = trailingslashit( $backup_root ) . '2099/01';
	wp_mkdir_p( $orphan_dir );
	$orphan = trailingslashit( $orphan_dir ) . 'sio-harness-orphan-' . wp_generate_password( 8, false ) . '.jpg';
	file_put_contents( $orphan, 'not a real jpeg, but a real file' );

	Harness::ok( file_exists( $orphan ), 'an unreferenced backup file exists before the purge' );

	// A symlink out of the backup root. Removing the link must never remove
	// what it points at.
	$uploads = wp_get_upload_dir();
	$outside = trailingslashit( $uploads['basedir'] ) . 'sio-harness-outside-' . wp_generate_password( 8, false ) . '.txt';
	file_put_contents( $outside, 'this file lives outside the backup root' );
	$link   = trailingslashit( $backup_root ) . 'sio-harness-link-' . wp_generate_password( 8, false );
	$linked = @symlink( $outside, $link );

	$result = ( new BackupController() )->purge()->get_data();

	Harness::ok( isset( $result['purged'] ) && $result['purged'] >= 1, 'the purge reports at least one backup cleared' );
	Harness::ok( isset( $result['files_removed'] ) && $result['files_removed'] >= 1, 'the purge reports unreferenced files removed' );
	Harness::ok( isset( $result['bytes_freed'] ) && $result['bytes_freed'] > 0, 'the purge reports space reclaimed' );

	Harness::ok( ! file_exists( $keep_files[0] ), 'a kept-forever backup file is GONE from disk' );
	Harness::ok( ! file_exists( $orphan ), 'the unreferenced file is GONE from disk' );

	$keep_row_after = OptimizationLog::find( $keep_id );
	Harness::same( '', is_array( $keep_row_after ) ? (string) $keep_row_after['backup_path'] : 'missing', 'the log row no longer claims a backup' );
	Harness::same(
		OptimizationLog::STATUS_OPTIMIZED,
		is_array( $keep_row_after ) ? $keep_row_after['status'] : 'missing',
		'the image still counts toward the savings stats'
	);

	Harness::ok( file_exists( trailingslashit( $backup_root ) . 'index.php' ), 'index.php guard survives the purge' );
	Harness::ok( file_exists( trailingslashit( $backup_root ) . '.htaccess' ), '.htaccess guard survives the purge' );

	if ( $linked ) {
		Harness::ok( ! is_link( $link ), 'the symlink itself was removed' );
		Harness::ok( file_exists( $outside ), 'what it pointed at, OUTSIDE the root, was NOT touched' );
	}

	@unlink( $outside );
	@unlink( $link );

	Harness::same( 0, BackupManager::disk_usage(), 'the backup folder reports 0 bytes afterwards' );
	Harness::same( array(), $harness_foreign_backups(), 'nothing but the two guard files is left in the folder' );

	// A second purge on an already-empty folder must be a quiet no-op.
	$again = ( new BackupController() )->purge()->get_data();
	Harness::same( 0, (int) $again['files_removed'], 'a second purge finds nothing to remove' );
	Harness::same( 0, (int) $again['bytes_freed'], 'and reports no space reclaimed' );

	harness_set_baseline_settings( array( 'convert_png' => true ) );
}

Harness::summary();
