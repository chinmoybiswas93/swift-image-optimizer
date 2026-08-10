<?php
/**
 * Log query request schema.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Http\Requests;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * How many lines of the log tail to return.
 */
class LogQueryRequest
{
    /**
     * Lines returned when the caller does not say.
     */
    const DEFAULT_LINES = 500;

    /**
     * Upper bound, so a large "lines" value cannot be used to pull a whole
     * multi-megabyte log into a JSON response. The download route exists for
     * that and streams instead.
     */
    const MAX_LINES = 5000;

    /**
     * WordPress REST args schema.
     *
     * @return array<string, mixed>
     */
    public static function args()
    {
        return [
            'lines' => [
                'type'              => 'integer',
                'default'           => self::DEFAULT_LINES,
                'sanitize_callback' => [self::class, 'sanitizeLines'],
            ],
        ];
    }

    /**
     * Clamp the requested line count into range.
     *
     * @param mixed $value Raw input.
     * @return int
     */
    public static function sanitizeLines($value)
    {
        $lines = absint($value);

        if ($lines < 1) {
            return self::DEFAULT_LINES;
        }

        return min($lines, self::MAX_LINES);
    }
}
