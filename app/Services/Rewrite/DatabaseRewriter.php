<?php
/**
 * Serialization-safe URL replacement across the database.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Services\Rewrite;

use SwiftImageOptimizer\App\Services\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces old image URLs with new ones everywhere they are stored.
 *
 * The single most important rule here: never run a plain string replace over
 * serialized data. PHP serialization embeds byte lengths, so changing
 * "photo.jpg" to "photo.webp" inside a serialized string without updating its
 * length prefix produces a value that can no longer be unserialized. That is
 * the classic way a search-and-replace destroys a WordPress site. Every value
 * is therefore unserialized, walked, and re-serialized.
 */
class DatabaseRewriter {

	/**
	 * Rows to read per query.
	 */
	const BATCH = 500;

	/**
	 * Maximum LIKE terms per query, to keep the SQL a sane size.
	 */
	const LIKE_CHUNK = 40;

	/**
	 * Meta keys and option names holding derived caches.
	 *
	 * These are regenerated from source data, so rewriting them is wasted work
	 * and risks corrupting a cache format we do not control. They are flushed
	 * instead.
	 *
	 * @var string[]
	 */
	private $skip_patterns = array(
		'_elementor_css',
		'_elementor_inspector_data',
		'_bricks_css',
		'_transient_',
		'_site_transient_',
		'_wp_attachment_metadata',
		'_wp_attached_file',
	);

	/**
	 * Replace every occurrence in the map across all supported tables.
	 *
	 * @param array $map     Old string to new string. Longest keys first.
	 * @param bool  $dry_run When true, count matches without writing.
	 * @return array {
	 *     @type int   $replacements Total values changed.
	 *     @type int   $rows         Rows inspected and matched.
	 *     @type array $by_table     Per-table change counts.
	 *     @type array $skipped      Rows skipped because they held objects.
	 * }
	 */
	public function replace( array $map, $dry_run = false ) {
		if ( empty( $map ) ) {
			return $this->empty_report();
		}

		$map = UrlMap::with_escaped_slashes( $map );

		$needles = $this->needles( $map );

		$report  = $this->empty_report();
		$touched = array();

		foreach ( $this->targets() as $target ) {
			$result = $this->process_table( $target, $map, $needles, $dry_run );

			$report['replacements']              += $result['replacements'];
			$report['rows']                      += $result['rows'];
			$report['skipped']                   += $result['skipped'];
			$report['by_table'][ $target['label'] ] = $result['replacements'];

			if ( $result['replacements'] > 0 ) {
				$group             = $target['group'];
				$touched[ $group ] = isset( $touched[ $group ] )
					? array_merge( $touched[ $group ], $result['touched'] )
					: $result['touched'];
			}

			if ( ! $dry_run && $result['replacements'] > 0 ) {
				Logger::info(
					'rewrite',
					'Table ' . $target['label'] . ' updated.',
					0,
					array(
						'values' => (int) $result['replacements'],
						'rows'   => (int) $result['rows'],
					)
				);
			}

			if ( $result['skipped'] > 0 ) {
				Logger::warn(
					'rewrite',
					'Left ' . (int) $result['skipped'] . ' value(s) in ' . $target['label'] . ' untouched: not safely unserializable.'
				);
			}
		}

		if ( ! $dry_run && $report['replacements'] > 0 ) {
			$this->flush_caches( $touched );
		}

		return $report;
	}

	/**
	 * Tables and columns to scan.
	 *
	 * @return array[]
	 */
	private function targets() {
		global $wpdb;

		// 'object' names the column identifying what the row belongs to, and
		// 'group' the object cache group to invalidate for it. For meta tables
		// the primary key is the meta row, not the object, so the two differ -
		// and invalidating on the wrong one silently serves stale URLs.
		return array(
			array(
				'label'   => 'posts',
				'table'   => $wpdb->posts,
				'key'     => 'ID',
				'columns' => array( 'post_content', 'post_excerpt', 'post_content_filtered' ),
				'filter'  => '',
				'object'  => 'ID',
				'group'   => 'post',
			),
			array(
				'label'   => 'postmeta',
				'table'   => $wpdb->postmeta,
				'key'     => 'meta_id',
				'columns' => array( 'meta_value' ),
				'filter'  => 'meta_key',
				'object'  => 'post_id',
				'group'   => 'post_meta',
			),
			array(
				'label'   => 'options',
				'table'   => $wpdb->options,
				'key'     => 'option_id',
				'columns' => array( 'option_value' ),
				'filter'  => 'option_name',
				'object'  => '',
				'group'   => 'options',
			),
			array(
				'label'   => 'termmeta',
				'table'   => $wpdb->termmeta,
				'key'     => 'meta_id',
				'columns' => array( 'meta_value' ),
				'filter'  => 'meta_key',
				'object'  => 'term_id',
				'group'   => 'term_meta',
			),
			array(
				'label'   => 'usermeta',
				'table'   => $wpdb->usermeta,
				'key'     => 'umeta_id',
				'columns' => array( 'meta_value' ),
				'filter'  => 'meta_key',
				'object'  => 'user_id',
				'group'   => 'user_meta',
			),
			// Comments were originally left out, which left an image embedded
			// in a comment pointing at a file that no longer exists.
			array(
				'label'   => 'comments',
				'table'   => $wpdb->comments,
				'key'     => 'comment_ID',
				'columns' => array( 'comment_content' ),
				'filter'  => '',
				'object'  => 'comment_ID',
				'group'   => 'comment',
			),
			array(
				'label'   => 'commentmeta',
				'table'   => $wpdb->commentmeta,
				'key'     => 'meta_id',
				'columns' => array( 'meta_value' ),
				'filter'  => 'meta_key',
				'object'  => 'comment_id',
				'group'   => 'comment_meta',
			),
		);
	}

	/**
	 * Scan and rewrite one table.
	 *
	 * @param array $target  Table descriptor.
	 * @param array $map     Replacement map.
	 * @param array $needles Distinct substrings to match on.
	 * @param bool  $dry_run Whether to suppress writes.
	 * @return array Partial report.
	 */
	private function process_table( array $target, array $map, array $needles, $dry_run ) {
		global $wpdb;

		$replacements = 0;
		$rows_matched = 0;
		$skipped      = 0;
		$touched      = array();

		$table   = $target['table'];
		$key     = $target['key'];
		$columns = $target['columns'];

		foreach ( array_chunk( $needles, self::LIKE_CHUNK ) as $chunk ) {
			$where  = array();
			$params = array();

			foreach ( $chunk as $needle ) {
				$like = '%' . $wpdb->esc_like( $needle ) . '%';

				foreach ( $columns as $column ) {
					$where[]  = "`{$column}` LIKE %s";
					$params[] = $like;
				}
			}

			$where_sql = '( ' . implode( ' OR ', $where ) . ' )';
			$last_id   = 0;

			// Select the filter column (meta_key / option_name) alongside the
			// data so the skip check costs nothing extra. Reading it per row in
			// a separate query would mean tens of thousands of round trips on a
			// large library.
			$select = $columns;

			if ( ! empty( $target['filter'] ) ) {
				$select[] = $target['filter'];
			}

			// The object column rides along for the same reason: knowing which
			// post or comment a meta row belongs to is what makes precise cache
			// invalidation possible without a second query per row.
			if ( ! empty( $target['object'] ) && $target['object'] !== $key && ! in_array( $target['object'], $select, true ) ) {
				$select[] = $target['object'];
			}

			$column_sql = '`' . implode( '`, `', $select ) . '`';

			do {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Identifiers are internal table/column descriptors and cannot be bound; $where_sql contributes its own placeholders, all of which are satisfied by $params.
				$query = $wpdb->prepare(
					"SELECT `{$key}`, {$column_sql} FROM `{$table}` WHERE {$where_sql} AND `{$key}` > %d ORDER BY `{$key}` ASC LIMIT %d",
					array_merge( $params, array( $last_id, self::BATCH ) )
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared above; bulk maintenance operation.
				$rows = $wpdb->get_results( $query, ARRAY_A );

				if ( empty( $rows ) ) {
					break;
				}

				$fetched = count( $rows );

				foreach ( $rows as $row ) {
					$last_id = (int) $row[ $key ];

					// Derived caches are regenerated from source data, so skip
					// them before doing any work. Checking here rather than at
					// write time keeps the dry-run counts honest.
					if ( $this->is_skipped_row( $target, $row ) ) {
						continue;
					}

					$update = array();

					foreach ( $columns as $column ) {
						$original = $row[ $column ];

						if ( null === $original || '' === $original ) {
							continue;
						}

						$changed = false;
						$new     = $this->replace_value( $original, $map, $changed, $safe );

						if ( ! $safe ) {
							++$skipped;
							continue;
						}

						if ( $changed && $new !== $original ) {
							$update[ $column ] = $new;
						}
					}

					if ( empty( $update ) ) {
						continue;
					}

					++$rows_matched;
					$replacements += count( $update );

					if ( $dry_run ) {
						continue;
					}

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk maintenance write; the affected caches are invalidated afterwards.
					$wpdb->update( $table, $update, array( $key => (int) $row[ $key ] ) );

					if ( ! empty( $target['object'] ) && isset( $row[ $target['object'] ] ) ) {
						$touched[] = (int) $row[ $target['object'] ];
					}
				}
			} while ( self::BATCH === $fetched );
		}

		return array(
			'replacements' => $replacements,
			'rows'         => $rows_matched,
			'skipped'      => $skipped,
			'touched'      => array_values( array_unique( $touched ) ),
		);
	}

	/**
	 * Whether a matched row holds derived data that must not be rewritten.
	 *
	 * The filter column is fetched as part of the main SELECT, so this is a
	 * pure in-memory check.
	 *
	 * @param array $target Table descriptor.
	 * @param array $row    The row being considered.
	 * @return bool
	 */
	private function is_skipped_row( array $target, array $row ) {
		if ( empty( $target['filter'] ) || ! isset( $row[ $target['filter'] ] ) ) {
			return false;
		}

		$name = (string) $row[ $target['filter'] ];

		foreach ( $this->skip_patterns as $pattern ) {
			if ( false !== strpos( $name, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Replace inside a value, descending into serialized structures.
	 *
	 * @param mixed $value   The stored value.
	 * @param array $map     Replacement map.
	 * @param bool  $changed Set to true when something was replaced.
	 * @param bool  $safe    Set to false when the value must not be written back.
	 * @return mixed The rewritten value.
	 */
	private function replace_value( $value, array $map, &$changed, &$safe = true ) {
		$safe = true;

		if ( is_string( $value ) && is_serialized( $value ) ) {
			// allowed_classes => false blocks object injection. If the payload
			// genuinely contained objects the result is unusable, which is
			// detected below and the row is left untouched.
			$data = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Malformed payloads are handled via the false check.

			if ( false === $data && 'b:0;' !== $value ) {
				// Not actually unserializable. Leave it completely alone rather
				// than risk a byte-length mismatch.
				$safe = false;
				return $value;
			}

			if ( $this->contains_incomplete_class( $data ) ) {
				$safe = false;
				return $value;
			}

			$rewritten = $this->walk( $data, $map, $changed );

			return $changed ? serialize( $rewritten ) : $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Restoring the value to its original storage format.
		}

		return $this->walk( $value, $map, $changed );
	}

	/**
	 * Recursively apply the map to a decoded structure.
	 *
	 * @param mixed $data    Decoded value.
	 * @param array $map     Replacement map.
	 * @param bool  $changed Set to true when something was replaced.
	 * @return mixed
	 */
	private function walk( $data, array $map, &$changed ) {
		if ( is_string( $data ) ) {
			// strtr applies the longest matching key first and never re-scans
			// text it has already substituted, which str_replace does not
			// guarantee when one filename is a prefix of another.
			$new = strtr( $data, $map );

			if ( $new !== $data ) {
				$changed = true;
			}

			return $new;
		}

		if ( is_array( $data ) ) {
			$out = array();

			foreach ( $data as $key => $item ) {
				$new_key = is_string( $key ) ? strtr( $key, $map ) : $key;

				if ( $new_key !== $key ) {
					$changed = true;
				}

				$out[ $new_key ] = $this->walk( $item, $map, $changed );
			}

			return $out;
		}

		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $key => $item ) {
				$data->$key = $this->walk( $item, $map, $changed );
			}

			return $data;
		}

		return $data;
	}

	/**
	 * Detect placeholders left by unserializing with classes disallowed.
	 *
	 * @param mixed $data Decoded value.
	 * @return bool
	 */
	private function contains_incomplete_class( $data ) {
		if ( $data instanceof \__PHP_Incomplete_Class ) {
			return true;
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $item ) {
				if ( $this->contains_incomplete_class( $item ) ) {
					return true;
				}
			}
		}

		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $item ) {
				if ( $this->contains_incomplete_class( $item ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Reduce the map to the distinct substrings worth querying on.
	 *
	 * Filenames alone are enough for the LIKE: any absolute, relative,
	 * protocol-relative or escaped form contains the bare filename.
	 *
	 * @param array $map Replacement map.
	 * @return string[]
	 */
	private function needles( array $map ) {
		$needles = array();

		foreach ( array_keys( $map ) as $old ) {
			$name = wp_basename( str_replace( '\\/', '/', $old ) );

			if ( ! $name ) {
				continue;
			}

			$needles[ $this->stem( $name ) ] = true;
		}

		// A stem can subsume another - "photo" already matches every row
		// "photo-2" would. Dropping the redundant ones keeps the OR list short.
		$stems = array_keys( $needles );

		usort(
			$stems,
			static function ( $a, $b ) {
				return strlen( $a ) - strlen( $b );
			}
		);

		$out = array();

		foreach ( $stems as $stem ) {
			foreach ( $out as $kept ) {
				if ( false !== strpos( $stem, $kept ) ) {
					continue 2;
				}
			}

			$out[] = $stem;
		}

		return $out;
	}

	/**
	 * The shared prefix every size of one image has in common.
	 *
	 * A single attachment contributes a dozen filenames to the map - the full
	 * size, the -scaled copy, the untouched original and one per registered
	 * thumbnail - and every one of them begins with the same stem. Querying on
	 * the stem instead turns roughly fifty LIKE terms per batch into five.
	 *
	 * This only decides which rows are *read*. The replacement map itself is
	 * untouched, so nothing is matched less precisely than before; the query
	 * simply stops asking the database the same question twelve times.
	 *
	 * @param string $filename Basename, for example photo-1-300x200.jpg.
	 * @return string Stem, for example photo-1.
	 */
	private function stem( $filename ) {
		$name = pathinfo( $filename, PATHINFO_FILENAME );

		// Strip WordPress's own suffixes: -300x200 for a thumbnail, -scaled
		// for the copy it makes of an oversized upload.
		$name = preg_replace( '/-scaled$/', '', $name );
		$name = preg_replace( '/-\d+x\d+$/', '', $name );

		// A stem short enough to appear inside unrelated text is worse than no
		// optimization at all, so fall back to the full filename.
		return strlen( $name ) >= 4 ? $name : $filename;
	}

	/**
	 * Clear caches that would otherwise still serve the old URLs.
	 *
	 * @param int[] $touched Object IDs whose caches were invalidated by the rewrite.
	 * @return void
	 */
	private function flush_caches( array $touched = array() ) {
		// Deliberately not wp_cache_flush(). On a site with a persistent object
		// cache that empties everything, and a bulk run calls this once per
		// batch - so the whole cache would be discarded every few seconds, on
		// exactly the large sites that can least afford it. Only what was
		// actually rewritten is invalidated.
		foreach ( $touched as $group => $ids ) {
			if ( 'options' === $group ) {
				// Options are cached as one serialized alloptions blob plus
				// individual keys. Dropping the blob is the only reliable
				// invalidation available without knowing the names.
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				continue;
			}

			foreach ( $ids as $id ) {
				switch ( $group ) {
					case 'post':
						clean_post_cache( $id );
						break;

					case 'post_meta':
						wp_cache_delete( $id, 'post_meta' );
						break;

					case 'term_meta':
						wp_cache_delete( $id, 'term_meta' );
						break;

					case 'user_meta':
						wp_cache_delete( $id, 'user_meta' );
						break;

					case 'comment':
						clean_comment_cache( $id );
						break;

					case 'comment_meta':
						wp_cache_delete( $id, 'comment_meta' );
						break;
				}
			}
		}

		// Elementor keeps compiled CSS containing image URLs.
		if ( class_exists( '\Elementor\Plugin' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Elementor's own action; it has to keep Elementor's name to clear Elementor's cache.
			do_action( 'elementor/core/files/clear_cache' );
		}

		/**
		 * Fires after image URLs have been rewritten in the database.
		 *
		 * Page-cache plugins can hook this to purge their own stores.
		 */
		do_action( 'swift_image_optimizer_urls_rewritten' );
	}

	/**
	 * An empty report structure.
	 *
	 * @return array
	 */
	private function empty_report() {
		return array(
			'replacements' => 0,
			'rows'         => 0,
			'by_table'     => array(),
			'skipped'      => 0,
		);
	}
}
