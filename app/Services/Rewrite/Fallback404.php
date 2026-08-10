<?php
/**
 * Serves the WebP when an old image URL is requested.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Services\Rewrite;

use SwiftImageOptimizer\App\Models\UrlLookup;
use SwiftImageOptimizer\App\Repositories\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safety net for references the rewriter could not reach.
 *
 * No database rewrite catches everything: URLs live in on-disk CSS and JS, in
 * external systems, in search-engine caches and in other people's hotlinks.
 * WordPress's standard rewrite rules route requests for non-existent files
 * through index.php, so a missing image can be matched against the stored URL
 * map and redirected to its replacement instead of 404ing.
 */
class Fallback404 {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! SettingsRepository::get( 'enable_404_fallback' ) ) {
			return;
		}

		add_action( 'template_redirect', array( $this, 'maybe_serve_replacement' ), 0 );
	}

	/**
	 * Redirect a request for a converted image to its WebP.
	 *
	 * @return void
	 */
	public function maybe_serve_replacement() {
		if ( ! is_404() ) {
			return;
		}

		$request = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( ! $request ) {
			return;
		}

		$path = wp_parse_url( $request, PHP_URL_PATH );

		if ( ! $path ) {
			return;
		}

		$filename  = wp_basename( $path );
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			return;
		}

		$replacement = $this->lookup( $filename, $this->relative_path( $path ) );

		if ( ! $replacement ) {
			return;
		}

		// A permanent redirect would be cached by browsers and CDNs forever,
		// which becomes wrong the moment the image is restored from backup.
		wp_safe_redirect( $replacement, 302 );
		exit;
	}

	/**
	 * Reduce a request path to the form stored in the lookup table.
	 *
	 * @param string $path Path from the request URI.
	 * @return string Uploads-relative path, or an empty string.
	 */
	private function relative_path( $path ) {
		$uploads = wp_get_upload_dir();
		$base    = wp_parse_url( untrailingslashit( $uploads['baseurl'] ), PHP_URL_PATH );

		if ( ! $base || 0 !== strpos( $path, $base ) ) {
			return '';
		}

		return ltrim( substr( $path, strlen( $base ) ), '/' );
	}

	/**
	 * Find the replacement URL for an old file.
	 *
	 * The path is tried before the basename so that two images sharing a
	 * filename in different month folders resolve to their own replacement
	 * rather than to whichever row the database happened to return first.
	 *
	 * @param string $filename      Old basename, for example photo-300x200.jpg.
	 * @param string $relative_path Uploads-relative path, when the request had one.
	 * @return string Replacement URL, or an empty string when unknown.
	 */
	private function lookup( $filename, $relative_path = '' ) {
		$cache_key = 'swift_image_optimizer_404_' . md5( $relative_path . '|' . $filename );
		$cached    = wp_cache_get( $cache_key, 'swift-image-optimizer' );

		if ( false !== $cached ) {
			return (string) $cached;
		}

		$replacement = UrlLookup::lookup( $relative_path, $filename );

		// Cached either way. A miss is the common case under a bot sweeping
		// old URLs, and it is the case that must not reach the database twice.
		wp_cache_set( $cache_key, $replacement, 'swift-image-optimizer', HOUR_IN_SECONDS );

		return $replacement;
	}
}
