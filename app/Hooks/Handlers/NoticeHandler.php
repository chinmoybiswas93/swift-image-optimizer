<?php
/**
 * Environment diagnostics shown in the admin.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Hooks\Handlers;

use SwiftImageOptimizer\App\App;
use SwiftImageOptimizer\App\Services\Engine\EngineFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warns when the host cannot convert images, rather than failing silently.
 */
class NoticeHandler {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'maybe_render_no_engine_notice' ) );
	}

	/**
	 * Tell the administrator when no conversion engine is available.
	 *
	 * @return void
	 */
	public function maybe_render_no_engine_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->is_relevant_screen() ) {
			return;
		}

		if ( EngineFactory::has_engine() ) {
			return;
		}

		App::view()->render(
			'admin.parts.notice',
			array(
				'type'    => 'error',
				'heading' => __( 'Swift Image Optimizer:', 'swift-image-optimizer' ),
				'message' => __( 'No image conversion engine was found on this server. Ask your host to enable the GD extension with WebP support, or the Imagick extension. Image optimization is paused until then.', 'swift-image-optimizer' ),
			)
		);
	}

	/**
	 * Whether this screen is one where the notice is worth showing.
	 *
	 * It used to render on every admin screen, which put the plugin's own
	 * message in front of people doing something unrelated, and is the kind of
	 * thing this plugin objects to when other plugins do it. The condition is
	 * about optimizing images, so it belongs on the screens where images are
	 * managed: the plugin's page, the Media Library, and the plugin list where
	 * someone has just activated it.
	 *
	 * @return bool
	 */
	private function is_relevant_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		$allowed = array(
			'upload',
			'plugins',
			'media_page_' . MenuHandler::SLUG,
		);

		return in_array( $screen->id, $allowed, true );
	}
}
