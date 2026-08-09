<?php
/**
 * Admin screen hosting the React dashboard.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Http\Admin;

use SwiftImageOptimizer\Services\Backup\BackupManager;
use SwiftImageOptimizer\Services\Bulk\Scanner;
use SwiftImageOptimizer\Services\Engine\EngineFactory;
use SwiftImageOptimizer\Http\Controllers\Controller;
use SwiftImageOptimizer\Repositories\SettingsRepository;
use SwiftImageOptimizer\Repositories\StatsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Media submenu and loads the built React bundle.
 */
class SettingsPage {

	/**
	 * Menu slug.
	 */
	const SLUG = 'swift-image-optimizer';

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
		echo '<div class="wrap"><div id="swift-image-optimizer-root"></div></div>';
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
			array( 'wp-components' ),
			file_exists( $style_path ) ? (string) filemtime( $style_path ) : $asset['version']
		);

		wp_set_script_translations( 'swift-image-optimizer-admin', 'swift-image-optimizer' );

		$engine = EngineFactory::get();

		wp_localize_script(
			'swift-image-optimizer-admin',
			'swiftImageOptimizer',
			array(
				'restUrl'    => esc_url_raw( rest_url( Controller::NAMESPACE_V1 . '/' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'settings'   => SettingsRepository::all(),
				'optionName' => SettingsRepository::OPTION,
				'summary'    => Scanner::summary(),
				'stats'      => StatsRepository::get(),
				'engine'     => $engine ? $engine->name() : '',
				'engines'    => EngineFactory::availability(),
				'backupBytes' => BackupManager::disk_usage(),
			)
		);
	}

	/**
	 * Warn when the JavaScript bundle has not been built.
	 *
	 * @return void
	 */
	public function missing_build_notice() {
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Swift Image Optimizer:', 'swift-image-optimizer' ),
			esc_html__( 'The admin assets have not been built. Run "npm install && npm run build" in the plugin directory.', 'swift-image-optimizer' )
		);
	}
}
