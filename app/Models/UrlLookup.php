<?php
/**
 * The old-URL lookup table.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maps an attachment's pre-conversion URLs to where the file now lives.
 *
 * The 404 fallback used to search the log table's url_map column with a LIKE
 * over LONGTEXT, which no index can help and which every bot probing an old
 * filename would trigger. This is the same data in a shape the database can
 * actually look up.
 */
class UrlLookup extends Model
{
    /**
     * Table name without the site prefix.
     *
     * @var string
     */
    protected static $table = 'swift_image_optimizer_urls';

    /**
     * Record where each of an attachment's old URLs now points.
     *
     * Only one row per file is stored, keyed by its uploads-relative path -
     * the absolute, protocol-relative and escaped variants in the map all
     * reduce to the same path, and the fallback only ever has a request path
     * to match against.
     *
     * @param int                   $attachmentId Attachment ID.
     * @param array<string, string> $urlMap       Old URL to new URL.
     * @return int Rows written.
     */
    public static function remember($attachmentId, array $urlMap)
    {
        $attachmentId = (int) $attachmentId;

        self::forget($attachmentId);

        if ($attachmentId <= 0 || !$urlMap) {
            return 0;
        }

        $uploads = wp_get_upload_dir();
        $base    = wp_parse_url(untrailingslashit($uploads['baseurl']), PHP_URL_PATH);
        $seen    = [];
        $written = 0;

        foreach ($urlMap as $old => $new) {
            $path = wp_parse_url(str_replace('\\/', '/', $old), PHP_URL_PATH);

            if (!$path) {
                continue;
            }

            $relative = ($base && 0 === strpos($path, $base))
                ? ltrim(substr($path, strlen($base)), '/')
                : ltrim($path, '/');

            if ('' === $relative || isset($seen[$relative])) {
                continue;
            }

            $seen[$relative] = true;

            self::insertRow([
                'attachment_id' => $attachmentId,
                'old_path'      => $relative,
                'old_basename'  => wp_basename($relative),
                'new_url'       => $new,
            ]);

            ++$written;
        }

        return $written;
    }

    /**
     * Drop an attachment's lookup rows, after a restore.
     *
     * @param int $attachmentId Attachment ID.
     * @return void
     */
    public static function forget($attachmentId)
    {
        self::deleteWhere(['attachment_id' => (int) $attachmentId]);
    }

    /**
     * Find where a requested file now lives.
     *
     * Matches on the full uploads-relative path when the request carries one,
     * which is what keeps 2023/01/photo.jpg and 2024/05/photo.jpg apart. Only
     * when that fails does it fall back to the basename alone.
     *
     * @param string $relativePath Uploads-relative path from the request, may be empty.
     * @param string $basename     Requested filename.
     * @return string Replacement URL, or an empty string.
     */
    public static function lookup($relativePath, $basename)
    {
        $db    = self::db();
        $table = self::table();

        if ($relativePath) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; value prepared, result cached by the caller.
            $exact = $db->get_var(
                $db->prepare("SELECT new_url FROM {$table} WHERE old_path = %s LIMIT 1", $relativePath)
            );

            if ($exact) {
                return (string) $exact;
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; value prepared, result cached by the caller.
        $fallback = $db->get_var(
            $db->prepare("SELECT new_url FROM {$table} WHERE old_basename = %s LIMIT 1", $basename)
        );

        return $fallback ? (string) $fallback : '';
    }
}
