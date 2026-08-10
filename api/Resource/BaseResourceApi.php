<?php
/**
 * Base resource API.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Api\Resource;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared behaviour for the read-side resources under api/.
 *
 * `api/` is the plugin's stable data-access layer: the surface controllers,
 * WP-CLI commands and any future add-on read through, so none of them touch
 * the models or the tables directly. Keeping it separate from `app/` is what
 * lets the internals move without breaking callers.
 */
abstract class BaseResourceApi
{
    /**
     * Transient key backing this resource, or an empty string when it is not
     * cached. Subclasses that cache must override it.
     *
     * @var string
     */
    protected static $cacheKey = '';

    /**
     * Drop this resource's cached payload.
     *
     * @return void
     */
    public static function flushCache()
    {
        if (static::$cacheKey) {
            delete_transient(static::$cacheKey);
        }
    }
}
