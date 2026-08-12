<?php
/**
 * Admin screen hosting the React dashboard.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Hooks\Handlers;

use SwiftImageOptimizer\App\App;
use SwiftImageOptimizer\App\Services\Backup\BackupManager;
use SwiftImageOptimizer\App\Services\Bulk\Scanner;
use SwiftImageOptimizer\App\Services\Bulk\ScanRunner;
use SwiftImageOptimizer\App\Services\Engine\EngineFactory;
use SwiftImageOptimizer\Api\StoreSettings;
use SwiftImageOptimizer\Api\Resource\StatsResource;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Media submenu and loads the built React bundle.
 */
class MenuHandler {

	/**
	 * Menu slug.
	 */
	const SLUG = 'swift-image-optimizer';

	/**
	 * Element ID the admin bundle mounts into.
	 */
	const MOUNT_ID = 'swift-image-optimizer-root';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . SWIFT_IMAGE_OPTIMIZER_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Add the Media submenu entry.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'upload.php',
			__( 'Swift Image Optimizer', 'swift-image-optimizer' ),
			__( 'Bulk Optimize', 'swift-image-optimizer' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Add a settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'upload.php?page=' . self::SLUG ) ),
				esc_html__( 'Settings', 'swift-image-optimizer' )
			)
		);

		return $links;
	}

	/**
	 * Render the mount point.
	 *
	 * @return void
	 */
	public function render() {
		App::view()->render(
			'admin.admin_app',
			array(
				'mountId' => self::MOUNT_ID,
				'title'   => __( 'Loading Swift Image Optimizer…', 'swift-image-optimizer' ),
			)
		);
	}

	/**
	 * Enqueue the built bundle on our screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( 'media_page_' . self::SLUG !== $hook ) {
			return;
		}

		$asset_file = SWIFT_IMAGE_OPTIMIZER_DIR . 'build/admin.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			add_action( 'admin_notices', array( $this, 'missing_build_notice' ) );
			return;
		}

		// The generated dependency list is what ties the bundle to WordPress's
		// own React, apiFetch and i18n scripts. It will contain wp-element,
		// wp-api-fetch and wp-i18n - and deliberately not wp-components, since
		// every control on this screen is the plugin's own.
		$asset = include $asset_file;

		wp_enqueue_script(
			'swift-image-optimizer-admin',
			SWIFT_IMAGE_OPTIMIZER_URL . 'build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// The generated version hash tracks the JavaScript only, so a CSS-only
		// rebuild would keep shipping the old stylesheet from the browser cache.
		// Version the stylesheet by its own mtime instead.
		$style_path = SWIFT_IMAGE_OPTIMIZER_DIR . 'build/admin.css';

		wp_enqueue_style(
			'swift-image-optimizer-admin',
			SWIFT_IMAGE_OPTIMIZER_URL . 'build/admin.css',
			array(),
			file_exists( $style_path ) ? (string) filemtime( $style_path ) : $asset['version']
		);

		wp_set_script_translations( 'swift-image-optimizer-admin', 'swift-image-optimizer' );

		$engine   = EngineFactory::get();
		$snapshot = ScanRunner::snapshot();

		wp_localize_script(
			'swift-image-optimizer-admin',
			'swiftImageOptimizer',
			array(
				'restUrl'       => esc_url_raw( rest_url( App::router()->getNamespace() . '/' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'settings'      => StoreSettings::all(),
				'optionName'    => StoreSettings::OPTION,

				/*
				 * The scan snapshot is what the dashboard renders. It is read,
				 * never taken, here: enqueue runs on every load of the screen,
				 * and a scan is minutes of batched work, not something to start
				 * as a side effect of drawing a page.
				 *
				 * null means the library has never been scanned, which the UI
				 * says in words rather than papering over with a number derived
				 * from the log table - that number is the one being replaced.
				 */
				'snapshot'      => $snapshot,
				'snapshotStale' => ScanRunner::snapshot_is_stale( $snapshot ),

				/*
				 * Kept for one release so a cached build that still reads them
				 * keeps working. Neither is used by the current bundle.
				 */
				'summary'       => Scanner::summary(),
				'stats'         => StatsResource::get(),

				'engine'        => $engine ? $engine->name() : '',
				'engines'       => EngineFactory::availability(),
				'backupBytes'   => BackupManager::disk_usage(),
			)
		);
	}

	/**
	 * Warn when the JavaScript bundle has not been built.
	 *
	 * @return void
	 */
	public function missing_build_notice() {
		App::view()->render(
			'admin.parts.notice',
			array(
				'type'    => 'error',
				'heading' => __( 'Swift Image Optimizer:', 'swift-image-optimizer' ),
				'message' => __( 'The admin assets have not been built. Run "npm install && npm run build" in the plugin directory.', 'swift-image-optimizer' ),
			)
		);
	}
}
