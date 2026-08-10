<?php
/**
 * Media-editor policy.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Http\Policies;

use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Guards per-attachment actions, which an editor may perform without being an
 * administrator - optimizing or restoring a single image from the Media
 * Library is ordinary editorial work.
 *
 * Replaces the old Controller::can_edit_media(). Both capabilities are
 * required: upload_files alone would let a contributor-like role act on
 * attachments it has no business editing.
 */
class MediaPolicy extends Policy
{
    /**
     * {@inheritDoc}
     *
     * @param WP_REST_Request $request Request.
     * @return bool
     */
    public function verifyRequest(WP_REST_Request $request)
    {
        unset($request);

        return current_user_can('upload_files') && current_user_can('edit_posts');
    }
}
