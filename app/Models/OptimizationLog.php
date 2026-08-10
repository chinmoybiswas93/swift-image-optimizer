<?php
/**
 * The optimization log.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One row per attachment the plugin has touched.
 *
 * This single table backs the stats dashboard, the restore feature, bulk
 * dedupe and the media-library column. It was previously the read/write half
 * of the Database god-class.
 */
class OptimizationLog extends Model
{
    /**
     * Table name without the site prefix.
     *
     * @var string
     */
    protected static $table = 'swift_image_optimizer_log';

    /**
     * Row statuses.
     */
    const STATUS_OPTIMIZED = 'optimized';
    const STATUS_SKIPPED   = 'skipped';
    const STATUS_FAILED    = 'failed';
    const STATUS_RESTORED  = 'restored';

    /**
     * Transient holding the cached stats aggregate.
     */
    const STATS_CACHE_KEY = 'swift_image_optimizer_stats';

    /**
     * Default column values for a new row.
     *
     * @return array<string, mixed>
     */
    public static function defaults()
    {
        return [
            'status'         => self::STATUS_OPTIMIZED,
            'original_file'  => '',
            'original_size'  => 0,
            'original_mime'  => '',
            'optimized_file' => '',
            'optimized_size' => 0,
            'backup_path'    => '',
            'backup_expires' => 0,
            'url_map'        => '',
            'engine'         => '',
            'conversion_ms'  => 0,
            'reason'         => '',
            'created_at'     => current_time('mysql'),
        ];
    }

    /**
     * Insert or replace a row.
     *
     * @param int                  $attachmentId Attachment ID.
     * @param array<string, mixed> $data         Column values.
     * @return bool True on success.
     */
    public static function upsert($attachmentId, array $data)
    {
        $attachmentId = (int) $attachmentId;

        if ($attachmentId <= 0) {
            return false;
        }

        $row                  = wp_parse_args($data, self::defaults());
        $row['attachment_id'] = $attachmentId;

        if (is_array($row['url_map'])) {
            $row['url_map'] = wp_json_encode($row['url_map']);
        }

        $result = self::replaceRow($row);

        self::flushStatsCache();

        return $result;
    }

    /**
     * Fetch a single row by attachment ID.
     *
     * @param int $attachmentId Attachment ID.
     * @return array<string, mixed>|null Row, or null when absent.
     */
    public static function find($attachmentId)
    {
        $attachmentId = (int) $attachmentId;

        if ($attachmentId <= 0) {
            return null;
        }

        $db    = self::db();
        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built internally; value is prepared.
        $row = $db->get_row(
            $db->prepare("SELECT * FROM {$table} WHERE attachment_id = %d", $attachmentId),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        $row['url_map'] = $row['url_map'] ? json_decode($row['url_map'], true) : [];

        return $row;
    }

    /**
     * Update selected columns on an existing row.
     *
     * @param int                  $attachmentId Attachment ID.
     * @param array<string, mixed> $data         Column values.
     * @return bool True on success.
     */
    public static function update($attachmentId, array $data)
    {
        if (isset($data['url_map']) && is_array($data['url_map'])) {
            $data['url_map'] = wp_json_encode($data['url_map']);
        }

        $result = self::updateWhere($data, ['attachment_id' => (int) $attachmentId]);

        self::flushStatsCache();

        return $result;
    }

    /**
     * Delete a row.
     *
     * @param int $attachmentId Attachment ID.
     * @return bool True on success.
     */
    public static function delete($attachmentId)
    {
        $result = self::deleteWhere(['attachment_id' => (int) $attachmentId]);

        self::flushStatsCache();

        return $result;
    }

    /**
     * Invalidate the cached stats aggregate.
     *
     * @return void
     */
    public static function flushStatsCache()
    {
        delete_transient(self::STATS_CACHE_KEY);
    }
}
