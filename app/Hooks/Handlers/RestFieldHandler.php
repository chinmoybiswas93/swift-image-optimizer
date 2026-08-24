<?php
/**
 * REST exposure of the optimization payload.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Hooks\Handlers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds `swiftImageOptimizer` to the core attachment REST response.
 *
 * `MediaLibraryHandler::expose_to_js()` only reaches admin-ajax's
 * `wp_prepare_attachment_for_js` calls, so an attachment fetched over the
 * REST API - which is how the block editor's Image/Gallery blocks read
 * attachment data - never carries this field. Registered separately, and
 * outside the `is_admin()` gate the rest of the admin UI handlers share:
 * `is_admin()` is false for `/wp-json/` requests, so a REST field has to be
 * wired unconditionally.
 */
class RestFieldHandler {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_field' ) );
	}

	/**
	 * Register the field on the core attachment REST controller.
	 *
	 * @return void
	 */
	public function register_field() {
		register_rest_field(
			'attachment',
			'swiftImageOptimizer',
			array(
				'get_callback' => array( $this, 'get_field' ),
				'schema'       => array(
					'description' => __( 'Swift Image Optimizer status for this attachment.', 'swift-image-optimizer' ),
					'type'        => array( 'object', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}

	/**
	 * Build the field value for one attachment.
	 *
	 * `register_rest_field` has no policy/permission hook of its own, so the
	 * gate lives here - mirroring the $editable check already inside
	 * MediaLibraryHandler::optimization_payload() for the classic modal.
	 *
	 * @param array $object Prepared REST attachment data, including 'id'.
	 * @return array|null
	 */
	public function get_field( $object ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return null;
		}

		return MediaLibraryHandler::optimization_payload( (int) $object['id'] );
	}
}
