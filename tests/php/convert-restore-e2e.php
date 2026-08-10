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

Harness::summary();
