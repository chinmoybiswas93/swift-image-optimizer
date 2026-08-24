<?php
/**
 * The optimization log.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Models;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * One row per attachment the plugin has touched.
 *
 * This single table backs the stats dashboard, the restore feature, bulk
 * dedupe and the media-library column. It was previously the read/write half
 * of the Database god-class.
 */
class OptimizationLog extends Model {

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
    const STATUS_PENDING   = 'pending';

    /**
     * Transient holding the cached stats aggregate.
     */
    const STATS_CACHE_KEY = 'swift_image_optimizer_stats';

    /**
     * Default column values for a new row.
     *
     * @return array<string, mixed>
     */
    public static function defaults() {
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
    public static function upsert( $attachmentId, array $data ) {
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
    public static function find( $attachmentId ) {
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

        if ( ! $row) {
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
    public static function update( $attachmentId, array $data ) {
        if (isset($data['url_map']) && is_array($data['url_map'])) {
            $data['url_map'] = wp_json_encode($data['url_map']);
        }

        $result = self::updateWhere($data, [ 'attachment_id' => (int) $attachmentId ]);

        self::flushStatsCache();

        return $result;
    }

    /**
     * Delete a row.
     *
     * @param int $attachmentId Attachment ID.
     * @return bool True on success.
     */
    public static function delete( $attachmentId ) {
        $result = self::deleteWhere([ 'attachment_id' => (int) $attachmentId ]);

        self::flushStatsCache();

        return $result;
    }

    /**
     * Invalidate the cached stats aggregate.
     *
     * @return void
     */
    public static function flushStatsCache() {
        delete_transient(self::STATS_CACHE_KEY);
    }

    /**
     * Every image attachment the library scan considers, one page at a time.
     *
     * Deliberately not Scanner's predicate. Scanner asks "what is left to do",
     * so it filters to convertible mimes and drops terminal rows - which is why
     * an optimized image, whose mime is now image/webp, disappears from its
     * totals entirely. The scan asks a different question: what is in the
     * library and what state is each item in. So the universe here is every
     * image, WebP included, and the classification happens in PHP against the
     * disk rather than in SQL against the status column (invariant 22).
     *
     * Paged by primary key rather than OFFSET, so a library of any size costs
     * the same per page.
     *
     * @param int      $after           Only rows with a greater ID.
     * @param int      $limit           Page size.
     * @param string[] $excludedMimes   Mime types to leave out entirely.
     * @return array<int, array<string, mixed>> Rows of id, mime, status, reason, optimized_file.
     */
    public static function attachmentScanPage( $after, $limit, array $excludedMimes = [] ) {
        $db    = self::db();
        $table = self::table();

        // Bound rather than inlined: wpdb::prepare() reads a literal % in the
        // SQL as a placeholder, so 'image/%' has to arrive as a value.
        $params = [ 'image/%' ];
        $filter = '';

        if ($excludedMimes) {
            $placeholders = implode(', ', array_fill(0, count($excludedMimes), '%s'));
            $filter       = "AND p.post_mime_type NOT IN ( {$placeholders} )";
            $params       = array_merge($params, $excludedMimes);
        }

        $params[] = (int) $after;
        $params[] = (int) $limit;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table names are internal identifiers and cannot be bound; $placeholders is a generated %s list and every value goes through prepare() in $params.
        $rows = $db->get_results(
            $db->prepare(
                "SELECT p.ID AS attachment_id, p.post_mime_type AS mime,
                        l.status AS status, l.reason AS reason,
                        l.optimized_file AS optimized_file,
                        l.original_size AS original_size, l.optimized_size AS optimized_size
                FROM {$db->posts} p
                LEFT JOIN {$table} l ON l.attachment_id = p.ID
                WHERE p.post_type = 'attachment'
                    AND p.post_mime_type LIKE %s
                    {$filter}
                    AND p.ID > %d
                ORDER BY p.ID ASC
                LIMIT %d",
                $params
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return is_array($rows) ? $rows : [];
    }

    /**
     * Rows that claim no backup, paged by primary key.
     *
     * The counterpart to the manifest-driven queries in `JobRunner`: those
     * find rows that point at files, this finds rows that point at nothing.
     * A row here either never had a backup, had its pointer cleared by a
     * purge, or lost it to a conversion that died between copying the files
     * and writing the row - and only the disk can tell those apart, which is
     * `BackupManager::reconcile()`'s job.
     *
     * Paged by `attachment_id` rather than OFFSET for the same reason
     * attachmentScanPage() is: reconcile rewrites the very column the WHERE
     * clause filters on, so an offset would skip rows as the set shrinks.
     *
     * @param int $after Only rows with a greater attachment ID.
     * @param int $limit Page size.
     * @return array<int, array<string, mixed>> Rows of attachment_id, original_file, url_map.
     */
    public static function unbackedPage( $after, $limit ) {
        $db    = self::db();
        $table = self::table();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name cannot be bound; every value is prepared.
        $rows = $db->get_results(
            $db->prepare(
                "SELECT attachment_id, original_file, url_map
                FROM {$table}
                WHERE status = %s
                    AND ( backup_path IS NULL OR backup_path = '' )
                    AND original_file <> ''
                    AND attachment_id > %d
                ORDER BY attachment_id ASC
                LIMIT %d",
                self::STATUS_OPTIMIZED,
                (int) $after,
                (int) $limit
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( ! is_array($rows)) {
            return [];
        }

        foreach ($rows as $index => $row) {
            $rows[ $index ]['url_map'] = $row['url_map'] ? json_decode($row['url_map'], true) : [];

            if ( ! is_array($rows[ $index ]['url_map'])) {
                $rows[ $index ]['url_map'] = [];
            }
        }

        return $rows;
    }

    /**
     * Attachments whose recorded mime type no longer matches the file
     * WordPress actually has attached - the signature a re-fed upload
     * leaves behind: post_mime_type still says image/jpeg or image/png
     * while _wp_attached_file was silently renamed to .webp underneath it
     * (see Interceptor::already_belongs_to_an_attachment()).
     *
     * Paged by post ID for the same reason attachmentScanPage() and
     * unbackedPage() are: nothing here rewrites post_mime_type mid-page,
     * but MimeReconciler::reconcile() does, between pages, so an OFFSET
     * would skip rows as the mismatched set shrinks.
     *
     * @param int $after Only rows with a greater attachment ID.
     * @param int $limit Page size.
     * @return array<int, array<string, mixed>> Rows of attachment_id, mime, attached_file.
     */
    public static function mimeMismatchPage( $after, $limit ) {
        $db = self::db();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table names cannot be bound; every value is prepared.
        $rows = $db->get_results(
            $db->prepare(
                "SELECT p.ID AS attachment_id, p.post_mime_type AS mime, pm.meta_value AS attached_file
                FROM {$db->posts} p
                INNER JOIN {$db->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
                WHERE p.post_type = 'attachment'
                    AND p.post_mime_type IN ( 'image/jpeg', 'image/png' )
                    AND pm.meta_value LIKE %s
                    AND p.ID > %d
                ORDER BY p.ID ASC
                LIMIT %d",
                '%.webp',
                (int) $after,
                (int) $limit
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * How many attachments a full scan will walk.
     *
     * Used only to size the scan's own progress bar. The published total is
     * what was actually classified, so a library that grows mid-scan still
     * yields a self-consistent snapshot.
     *
     * @param string[] $excludedMimes Mime types to leave out entirely.
     * @return int
     */
    public static function countScannableAttachments( array $excludedMimes = [] ) {
        $db = self::db();

        $filter = '';

        // Same reason as attachmentScanPage(): a literal % in prepared SQL is
        // read as a placeholder, so the LIKE pattern is bound as a value.
        $params = [ 'image/%' ];

        if ($excludedMimes) {
            $placeholders = implode(', ', array_fill(0, count($excludedMimes), '%s'));
            $filter       = "AND post_mime_type NOT IN ( {$placeholders} )";
            $params       = array_merge($params, $excludedMimes);
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name is a core identifier and cannot be bound; $placeholders is a generated %s list and every value goes through prepare().
        $count = $db->get_var(
            $db->prepare(
                "SELECT COUNT(*) FROM {$db->posts}
                WHERE post_type = 'attachment'
                    AND post_mime_type LIKE %s
                    {$filter}",
                $params
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return (int) $count;
    }
}
