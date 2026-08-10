<?php
/**
 * Base model.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Models;

use wpdb;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * A thin, prepared-statement-only wrapper over $wpdb for the plugin's own
 * tables.
 *
 * Deliberately not an ORM. The plugin owns two tables with fixed shapes; a
 * query builder would be more code than the queries it replaces. What this
 * does buy is a single place where the table prefix is applied and where the
 * stats cache is invalidated, so callers stop reaching for global $wpdb.
 */
abstract class Model {

    /**
     * Table name without the site prefix. Subclasses must set this.
     *
     * @var string
     */
    protected static $table = '';

    /**
     * Fully qualified table name.
     *
     * @return string
     */
    public static function table() {
        return self::db()->prefix . static::$table;
    }

    /**
     * The WordPress database handle.
     *
     * @return wpdb
     */
    protected static function db() {
        global $wpdb;

        return $wpdb;
    }

    /**
     * Insert a row, replacing any existing row with the same primary key.
     *
     * @param array<string, mixed> $row Column values.
     * @return bool True on success.
     */
    protected static function replaceRow( array $row ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table, no core API available.
        return false !== self::db()->replace(static::table(), $row);
    }

    /**
     * Insert a row.
     *
     * @param array<string, mixed> $row Column values.
     * @return bool True on success.
     */
    protected static function insertRow( array $row ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table, no core API available.
        return false !== self::db()->insert(static::table(), $row);
    }

    /**
     * Update rows matching $where.
     *
     * @param array<string, mixed> $data  Column values.
     * @param array<string, mixed> $where Match conditions.
     * @return bool True on success.
     */
    protected static function updateWhere( array $data, array $where ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table, no core API available.
        return false !== self::db()->update(static::table(), $data, $where);
    }

    /**
     * Delete rows matching $where.
     *
     * @param array<string, mixed> $where Match conditions.
     * @return bool True on success.
     */
    protected static function deleteWhere( array $where ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table, no core API available.
        return false !== self::db()->delete(static::table(), $where);
    }
}
