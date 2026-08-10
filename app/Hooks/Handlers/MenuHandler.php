<?php
/**
 * Admin screen hosting the React dashboard.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Hooks\Handlers;

use SwiftImageOptimizer\App\App;
use SwiftImageOptimizer\App\Vite;
use SwiftImageOptimizer\App\Services\Backup\BackupManager;
use SwiftImageOptimizer\App\Services\Bulk\Scanner;
use SwiftImageOptimizer\App\Services\Engine\EngineFactory;
use SwiftImageOptimizer\App\Repositories\SettingsRepository;
use SwiftImageOptimizer\App\Repositories\StatsRepository;

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

		if ( ! Vite::isBuilt() ) {
			add_action( 'admin_notices', array( $this, 'missing_build_notice' ) );
			return;
		}

		// wp-i18n is the only WordPress script the bundle depends on: the app
		// imports its translation functions from the global that this handle
		// defines. No wp-components, no wp-element - React is bundled.
		$handle = Vite::enqueueScript(
			'swift-image-optimizer-admin',
			'admin/bootstrap/app.jsx',
			array( 'wp-i18n' )
		);

		wp_set_script_translations( $handle, 'swift-image-optimizer' );

		$engine = EngineFactory::get();

		wp_localize_script(
			$handle,
			'swiftImageOptimizer',
			array(
				'restUrl'     => esc_url_raw( rest_url( App::router()->getNamespace() . '/' ) ),
				// Settings are saved through core's own settings endpoint, which
				// lives outside the plugin namespace.
				'wpRestUrl'   => esc_url_raw( rest_url() ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'settings'    => SettingsRepository::all(),
				'optionName'  => SettingsRepository::OPTION,
				'summary'     => Scanner::summary(),
				'stats'       => StatsRepository::get(),
				'engine'      => $engine ? $engine->name() : '',
				'engines'     => EngineFactory::availability(),
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
