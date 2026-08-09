<?php
/**
 * Builds the old-to-new URL pairs for a converted attachment.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Services\Rewrite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces every string that needs replacing when an attachment changes format.
 *
 * A single conversion can invalidate a dozen URLs: the full-size file, the
 * -scaled variant, the untouched original, and one per registered thumbnail
 * size. Missing any one of them leaves a broken image somewhere.
 */
class UrlMap {

	/**
	 * Build the replacement pairs from before/after attachment metadata.
	 *
	 * Both metadata arrays must be captured around the conversion: the "before"
	 * one before any file is touched, the "after" one once subsizes have been
	 * regenerated.
	 *
	 * @param array  $before   Metadata as it was prior to conversion.
	 * @param array  $after    Metadata after conversion and subsize regeneration.
	 * @param string $base_url Upload directory URL for the attachment's folder, no trailing slash.
	 * @return array<string, string> Old URL to new URL, longest key first.
	 */
	public static function build( array $before, array $after, $base_url ) {
		$base_url = untrailingslashit( $base_url );

		$pairs = array();

		$old_main = isset( $before['file'] ) ? wp_basename( $before['file'] ) : '';
		$new_main = isset( $after['file'] ) ? wp_basename( $after['file'] ) : '';

		if ( $old_main && $new_main && $old_main !== $new_main ) {
			$pairs[ $old_main ] = $new_main;
		}

		// The pre-scaling original, when WordPress kept one.
		if ( ! empty( $before['original_image'] ) ) {
			$old_original = wp_basename( $before['original_image'] );
			$replacement  = ! empty( $after['original_image'] ) ? wp_basename( $after['original_image'] ) : $new_main;

			if ( $old_original && $replacement && $old_original !== $replacement ) {
				$pairs[ $old_original ] = $replacement;
			}
		}

		$old_sizes = isset( $before['sizes'] ) && is_array( $before['sizes'] ) ? $before['sizes'] : array();
		$new_sizes = isset( $after['sizes'] ) && is_array( $after['sizes'] ) ? $after['sizes'] : array();

		foreach ( $old_sizes as $name => $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}

			$old_file = wp_basename( $size['file'] );

			// Prefer the same named size; fall back to the full-size file so the
			// reference at least resolves to a real image rather than a 404.
			if ( ! empty( $new_sizes[ $name ]['file'] ) ) {
				$new_file = wp_basename( $new_sizes[ $name ]['file'] );
			} else {
				$new_file = $new_main;
			}

			if ( $old_file && $new_file && $old_file !== $new_file ) {
				$pairs[ $old_file ] = $new_file;
			}
		}

		return self::expand( $pairs, $base_url );
	}

	/**
	 * Turn basename pairs into the full set of strings found in the database.
	 *
	 * The same image is referenced as an absolute URL, a protocol-relative URL,
	 * a root-relative path and sometimes a bare filename. All four forms have to
	 * be covered.
	 *
	 * @param array<string, string> $pairs    Old basename to new basename.
	 * @param string                $base_url Directory URL, no trailing slash.
	 * @return array<string, string> Ordered longest-key-first.
	 */
	private static function expand( array $pairs, $base_url ) {
		$out = array();

		$relative_base = wp_make_link_relative( $base_url );
		$schemeless    = preg_replace( '#^https?:#', '', $base_url );

		foreach ( $pairs as $old => $new ) {
			// Absolute URL, the most common form.
			$out[ $base_url . '/' . $old ] = $base_url . '/' . $new;

			// Protocol-relative, produced by some CDN and migration plugins.
			if ( $schemeless !== $base_url ) {
				$out[ $schemeless . '/' . $old ] = $schemeless . '/' . $new;
			}

			// Root-relative, common in hand-written content.
			if ( $relative_base && $relative_base !== $base_url ) {
				$out[ $relative_base . '/' . $old ] = $relative_base . '/' . $new;
			}
		}

		// Replace the longest strings first so a size variant is never partially
		// matched by the shorter full-size filename it contains.
		uksort(
			$out,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		return $out;
	}

	/**
	 * Add the escaped-slash form of every pair, for JSON-encoded content.
	 *
	 * Elementor and Bricks store their page data as JSON inside a meta value, so
	 * URLs appear as https:\/\/example.com\/photo.jpg.
	 *
	 * @param array<string, string> $map Plain URL pairs.
	 * @return array<string, string> The plain pairs plus their escaped variants.
	 */
	public static function with_escaped_slashes( array $map ) {
		$out = $map;

		foreach ( $map as $old => $new ) {
			$escaped_old = str_replace( '/', '\\/', $old );
			$escaped_new = str_replace( '/', '\\/', $new );

			if ( $escaped_old !== $old ) {
				$out[ $escaped_old ] = $escaped_new;
			}
		}

		uksort(
			$out,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		return $out;
	}
}
