<?php
/**
 * Attachment-ID request schema.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Http\Requests;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The list of attachment IDs shared by /optimize and /restore.
 *
 * This sanitizer used to be an anonymous closure declared inline at the top of
 * register_routes() and reused by reference. As a named class it can be
 * asserted about, and the rule that IDs are positive integers lives in one
 * place rather than being restated per route.
 */
class OptimizeRequest
{
    /**
     * WordPress REST args schema.
     *
     * @return array<string, mixed>
     */
    public static function args()
    {
        return [
            'ids' => [
                'type'              => 'array',
                'required'          => true,
                'items'             => ['type' => 'integer'],
                'sanitize_callback' => [self::class, 'sanitizeIds'],
            ],
        ];
    }

    /**
     * Reduce the input to a list of positive integer IDs.
     *
     * absint turns anything non-numeric into 0, and the filter drops those, so
     * a malformed entry is discarded rather than becoming attachment 0.
     *
     * @param mixed $value Raw input.
     * @return int[]
     */
    public static function sanitizeIds($value)
    {
        return array_values(array_filter(array_map('absint', (array) $value)));
    }
}
