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
}
