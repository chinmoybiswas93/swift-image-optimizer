<?php
/**
 * WordPress bootstrap and shared fixture helpers for the e2e harnesses.
 *
 * Cleanup here is deliberately narrow. A previous harness cleaned up with
 * glob( BackupManager::root() . '/@*'/'@*'/'@*' ) and destroyed 54 real user
 * backups - 228 original files. Nothing in this file deletes by glob. Backups
 * are removed only via the manifest recorded on the harness's own log row.
 *
 * @package SwiftImageOptimizer
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB.DirectDatabaseQuery, Squiz.Commenting, Generic.Commenting, WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions

define( 'WP_USE_THEMES', false );

$harness_wp_root = dirname( __DIR__, 5 );

if ( ! file_exists( $harness_wp_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Could not locate wp-load.php from {$harness_wp_root}\n" );
	exit( 2 );
}

require $harness_wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

use SwiftImageOptimizer\Api\StoreSettings;
use SwiftImageOptimizer\App\Models\OptimizationLog;
use SwiftImageOptimizer\App\Services\AttachmentConverter;
use SwiftImageOptimizer\App\Services\Backup\BackupManager;
use SwiftImageOptimizer\App\Services\Engine\EngineFactory;
use SwiftImageOptimizer\App\Services\Optimizer;
use SwiftImageOptimizer\App\Services\Rewrite\DatabaseRewriter;

/**
 * Write an explicit settings baseline.
 *
 * Never capture-and-restore the live settings: if a previous crashed run left
 * a dirty value, that dirty value gets faithfully written back. Start from
 * defaults every time.
 */
function harness_set_baseline_settings( array $overrides = array() ) {
	$settings = array_merge( StoreSettings::defaults(), $overrides );

	$engine = getenv( 'SIO_TEST_ENGINE' );

	if ( $engine ) {
		$settings['engine'] = $engine;
	}

	update_option( 'swift_image_optimizer_settings', StoreSettings::sanitize( $settings ) );

	if ( method_exists( EngineFactory::class, 'reset' ) ) {
		EngineFactory::reset();
	}
}

function harness_engine_names() {
	$names = array();

	foreach ( EngineFactory::chain() as $engine ) {
		$names[] = $engine->name();
	}

	return $names ? $names : array( 'none' );
}

function harness_make_converter() {
	return new AttachmentConverter( new Optimizer(), new DatabaseRewriter() );
}

function harness_count_attachments() {
	global $wpdb;

	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
}

function harness_count_log_rows() {
	global $wpdb;

	$table = OptimizationLog::table();

	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
}

/**
 * Generate a JPEG with enough real detail that WebP encoding is a meaningful
 * test. A flat-colour image would be correctly skipped as "webp would be
 * larger" and assert nothing.
 */
function harness_make_jpeg( $width, $height ) {
	$image = imagecreatetruecolor( $width, $height );

	for ( $i = 0; $i < 240; $i++ ) {
		$colour = imagecolorallocate(
			$image,
			( $i * 7 ) % 256,
			( $i * 13 ) % 256,
			( $i * 29 ) % 256
		);

		imagefilledellipse(
			$image,
			(int) ( ( $i * 37 ) % $width ),
			(int) ( ( $i * 53 ) % $height ),
			(int) ( $width / 5 ),
			(int) ( $height / 5 ),
			$colour
		);
	}

	$path = wp_tempnam( 'sio-harness-' . wp_generate_password( 8, false ) . '.jpg' );
	$path = preg_replace( '/\.tmp$/', '.jpg', $path );

	imagejpeg( $image, $path, 90 );
	imagedestroy( $image );

	return $path;
}

/**
 * Copy a file into the uploads directory and register it as an attachment,
 * with full metadata. Returns the attachment ID.
 */
function harness_import_attachment( $source_path ) {
	$uploads  = wp_upload_dir();
	$basename = 'sio-harness-' . wp_generate_password( 10, false ) . '-' . basename( $source_path );
	$target   = trailingslashit( $uploads['path'] ) . $basename;

	if ( ! copy( $source_path, $target ) ) {
		fwrite( STDERR, "Could not copy fixture into uploads: {$target}\n" );
		exit( 2 );
	}

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'SIO harness fixture',
			'post_status'    => 'inherit',
		),
		$target
	);

	if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
		fwrite( STDERR, "Could not insert attachment for {$target}\n" );
		exit( 2 );
	}

	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $target ) );

	return (int) $attachment_id;
}

/**
 * Remove exactly what the harness created, and nothing else.
 *
 * Backups are resolved from each attachment's own log row, never by scanning
 * the backup directory.
 */
function harness_cleanup( array $attachment_ids ) {
	foreach ( array_unique( $attachment_ids ) as $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 ) {
			continue;
		}

		$log = OptimizationLog::find( $attachment_id );

		if ( $log && ! empty( $log['backup_path'] ) ) {
			$manifest = json_decode( $log['backup_path'], true );

			if ( is_array( $manifest ) && ! empty( $manifest['relative_dir'] ) && ! empty( $manifest['files'] ) ) {
				// Delete only the paths this attachment's own manifest names.
				BackupManager::delete( $manifest['relative_dir'], (array) $manifest['files'] );
			}
		}

		OptimizationLog::delete( $attachment_id );

		// force_delete removes the file and every generated size.
		wp_delete_attachment( $attachment_id, true );
	}

	OptimizationLog::flushStatsCache();
}
