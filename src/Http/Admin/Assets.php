<?php
/**
 * Enqueues the Media Library integration assets.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Http\Admin;

use SwiftImageOptimizer\Services\Bulk\Scanner;
use SwiftImageOptimizer\Services\Engine\EngineFactory;
use SwiftImageOptimizer\Http\Controllers\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the media modal panel and the grid-view toolbar button.
 *
 * SettingsPage only enqueues on the plugin's own screen, but the media modal
 * appears almost everywhere: the post editor, upload.php, widgets, the
 * customizer and inside page builders. Rather than enumerating those screens,
 * this hooks late and enqueues wherever WordPress has already loaded the media
 * views — which is exactly the set of screens where the modal can appear.
 */
class Assets {

	/**
	 * Script handle.
	 */
	const HANDLE = 'swift-image-optimizer-media';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Priority 100: wp_enqueue_media() runs during the normal enqueue pass,
		// so the check below is only meaningful once that has happened.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 100 );
	}

	/**
	 * Enqueue the bundle when the media views are present.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! wp_script_is( 'media-views', 'enqueued' ) && ! wp_script_is( 'media-views', 'to_do' ) ) {
			return;
		}

		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$asset_file = SWIFT_IMAGE_OPTIMIZER_DIR . 'build/media.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			SWIFT_IMAGE_OPTIMIZER_URL . 'build/media.js',
			array_merge( $asset['dependencies'], array( 'media-views' ) ),
			$asset['version'],
			true
		);

		wp_enqueue_style(
			self::HANDLE,
			SWIFT_IMAGE_OPTIMIZER_URL . 'build/media.css',
			array(),
			$asset['version']
		);

		wp_set_script_translations( self::HANDLE, 'swift-image-optimizer' );

		wp_localize_script(
			self::HANDLE,
			'swiftImageOptimizerMedia',
			array(
				'restUrl'   => esc_url_raw( rest_url( Controller::NAMESPACE_V1 . '/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'isGrid'    => $this->is_grid_screen(),
				'hasEngine' => (bool) EngineFactory::get(),
				'mimeTypes' => array_values( Scanner::mime_types() ),
				// Sending more than a few per request risks a timeout, since
				// each conversion is a synchronous encode plus a URL rewrite.
				'chunkSize' => 3,
			)
		);
	}

	/**
	 * Whether the current screen is the Media Library in grid mode.
	 *
	 * The grid toolbar button is only meaningful there; list mode already has
	 * a column, row actions and a bulk-actions dropdown.
	 *
	 * @return bool
	 */
	private function is_grid_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'upload' !== $screen->id ) {
			return false;
		}

		// Grid is the default; list mode is opt-in via ?mode=list.
		$mode = get_user_option( 'media_library_mode' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a display preference, not acting on it.
		if ( isset( $_GET['mode'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a display preference, not acting on it.
			$mode = sanitize_key( wp_unslash( $_GET['mode'] ) );
		}

		return 'list' !== $mode;
	}
}
