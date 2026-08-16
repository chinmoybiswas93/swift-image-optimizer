<?php
/**
 * Serialization-safety suite for the URL rewriter. Needs no WordPress.
 *
 * These assertions guard architecture invariants 1, 2, 3 and 11 - the rules
 * that stop a search-and-replace from destroying a site. Run this before and
 * after any change to Services/Rewrite/.
 *
 *   php tests/php/rewriter-test.php
 *
 * @package SwiftImageOptimizer
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, Squiz.Commenting, Generic.Commenting, WordPress.PHP.DiscouragedPHPFunctions, WordPress.PHP.NoSilencedErrors, Universal.Files.SeparateFunctionsFromOO

require __DIR__ . '/bootstrap.php';

/*
 * Minimal WordPress stubs. Only what the rewriter actually reaches on these
 * code paths - deliberately not a WordPress emulator.
 */
if ( ! function_exists( 'is_serialized' ) ) {
	function is_serialized( $data, $strict = true ) {
		if ( ! is_string( $data ) ) {
			return false;
		}

		$data = trim( $data );

		if ( 'N;' === $data ) {
			return true;
		}

		if ( strlen( $data ) < 4 || ':' !== $data[1] ) {
			return false;
		}

		return (bool) preg_match( '/^([adObis]):/', $data );
	}
}

if ( ! function_exists( 'wp_basename' ) ) {
	function wp_basename( $path, $suffix = '' ) {
		return urldecode( basename( str_replace( array( '%2F', '%5C' ), '/', urlencode( $path ) ), $suffix ) );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( 'wp_make_link_relative' ) ) {
	function wp_make_link_relative( $link ) {
		return preg_replace( '|^(https?:)?//[^/]+(/?.*)|i', '$2', $link );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require dirname( __DIR__, 2 ) . '/app/Services/Rewrite/UrlMap.php';
require dirname( __DIR__, 2 ) . '/app/Services/Rewrite/DatabaseRewriter.php';

use SwiftImageOptimizer\App\Services\Rewrite\DatabaseRewriter;
use SwiftImageOptimizer\App\Services\Rewrite\UrlMap;

/**
 * Reach a private method. The rewriter's dangerous logic is private by design;
 * testing it through the public replace() would require a database.
 */
function rewriter_invoke( DatabaseRewriter $rewriter, $method, array $args ) {
	$ref = new ReflectionMethod( DatabaseRewriter::class, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		// Required on the 7.4 floor; a deprecated no-op from 8.1 onward.
		$ref->setAccessible( true );
	}

	return $ref->invokeArgs( $rewriter, $args );
}

function rewriter_replace_value( DatabaseRewriter $rewriter, $value, array $map, &$changed, &$safe ) {
	$ref = new ReflectionMethod( DatabaseRewriter::class, 'replace_value' );
	if ( PHP_VERSION_ID < 80100 ) {
		// Required on the 7.4 floor; a deprecated no-op from 8.1 onward.
		$ref->setAccessible( true );
	}

	$args = array( $value, $map, &$changed, &$safe );

	return $ref->invokeArgs( $rewriter, $args );
}

$rewriter = ( new ReflectionClass( DatabaseRewriter::class ) )->newInstanceWithoutConstructor();

$map = array(
	'photo-300x200.jpg' => 'photo-300x200.webp',
	'photo.jpg'         => 'photo.webp',
);

/* ---------------------------------------------------------------- */
Harness::suite( 'Plain strings' );

$changed = false;
$safe    = true;
$out     = rewriter_replace_value( $rewriter, 'A file called photo.jpg here.', $map, $changed, $safe );
Harness::same( 'A file called photo.webp here.', $out, 'plain string is rewritten' );
Harness::ok( $changed, 'changed flag is set' );
Harness::ok( $safe, 'plain string is safe' );

$changed = false;
$out     = rewriter_replace_value( $rewriter, 'Nothing to see.', $map, $changed, $safe );
Harness::same( 'Nothing to see.', $out, 'untouched string is returned verbatim' );
Harness::ok( ! $changed, 'changed flag stays false when nothing matched' );

/*
 * Invariant 3: strtr applies the longest key first. str_replace would rewrite
 * the "photo.jpg" fragment inside "photo-300x200.jpg" first and corrupt it.
 */
$changed = false;
$out     = rewriter_replace_value( $rewriter, 'photo-300x200.jpg', $map, $changed, $safe );
Harness::same( 'photo-300x200.webp', $out, 'longest key wins over its own prefix (invariant 3)' );

$changed = false;
$out     = rewriter_replace_value( $rewriter, 'photo.jpg and photo-300x200.jpg', $map, $changed, $safe );
Harness::same( 'photo.webp and photo-300x200.webp', $out, 'both forms in one string rewrite independently' );

/* ---------------------------------------------------------------- */
Harness::suite( 'Serialized data (invariant 1)' );

$original   = array(
	'url'   => 'https://example.com/wp-content/uploads/photo.jpg',
	'thumb' => 'https://example.com/wp-content/uploads/photo-300x200.jpg',
	'depth' => array( 'nested' => 'photo.jpg' ),
);
$serialized = serialize( $original );

$changed = false;
$safe    = true;
$out     = rewriter_replace_value( $rewriter, $serialized, $map, $changed, $safe );

Harness::ok( $changed, 'serialized payload reports a change' );
Harness::ok( $safe, 'serialized payload is safe' );

$decoded = @unserialize( $out, array( 'allowed_classes' => false ) );
Harness::ok( false !== $decoded, 'rewritten payload still unserializes - byte lengths intact' );
Harness::same(
	'https://example.com/wp-content/uploads/photo.webp',
	is_array( $decoded ) ? $decoded['url'] : null,
	'nested url rewritten'
);
Harness::same(
	'https://example.com/wp-content/uploads/photo-300x200.webp',
	is_array( $decoded ) ? $decoded['thumb'] : null,
	'thumbnail url rewritten'
);
Harness::same(
	'photo.webp',
	is_array( $decoded ) && isset( $decoded['depth']['nested'] ) ? $decoded['depth']['nested'] : null,
	'deeply nested value rewritten'
);

/*
 * The failure mode this whole design exists to prevent: a naive str_replace
 * over the serialized string leaves the declared length disagreeing with the
 * actual byte count, and the value can never be unserialized again.
 */
$naive = str_replace( 'photo.jpg', 'photo.webp', $serialized );
Harness::ok(
	false === @unserialize( $naive, array( 'allowed_classes' => false ) ),
	'naive str_replace over serialized data does corrupt it - the bug being prevented'
);

$changed = false;
$out     = rewriter_replace_value( $rewriter, serialize( array( 'a' => 'nothing here' ) ), $map, $changed, $safe );
Harness::ok( ! $changed, 'serialized payload with no match reports no change' );

/* ---------------------------------------------------------------- */
Harness::suite( 'Serialized keys, not just values' );

$keyed   = serialize( array( 'photo.jpg' => 'some value' ) );
$changed = false;
$out     = rewriter_replace_value( $rewriter, $keyed, $map, $changed, $safe );
$decoded = @unserialize( $out, array( 'allowed_classes' => false ) );

Harness::ok( $changed, 'array key match reports a change' );
Harness::ok( is_array( $decoded ) && array_key_exists( 'photo.webp', $decoded ), 'array key itself is rewritten' );
Harness::ok( is_array( $decoded ) && ! array_key_exists( 'photo.jpg', $decoded ), 'old array key is gone' );

/* ---------------------------------------------------------------- */
Harness::suite( 'Object payloads are refused (invariant 2)' );

/*
 * Unserializing with allowed_classes => false turns objects into
 * __PHP_Incomplete_Class. Re-serializing that would write the placeholder
 * back over real user data, so such rows must be left completely untouched.
 */
$object_payload = 'a:1:{s:3:"obj";O:8:"stdClass":1:{s:3:"url";s:9:"photo.jpg";}}';

$changed = false;
$safe    = true;
$out     = rewriter_replace_value( $rewriter, $object_payload, $map, $changed, $safe );

Harness::same( $object_payload, $out, 'payload containing an object is returned byte-identical' );
Harness::ok( ! $safe, 'payload containing an object is flagged unsafe' );

$malformed = 'a:2:{s:3:"url";s:9:"photo.jpg";}';
$changed   = false;
$safe      = true;
$out       = rewriter_replace_value( $rewriter, $malformed, $map, $changed, $safe );

Harness::same( $malformed, $out, 'malformed serialized value is left untouched' );
Harness::ok( ! $safe, 'malformed serialized value is flagged unsafe' );

$changed = false;
$safe    = true;
$out     = rewriter_replace_value( $rewriter, 'b:0;', $map, $changed, $safe );
Harness::ok( $safe, 'serialized false (b:0;) is not mistaken for a failed unserialize' );

/* ---------------------------------------------------------------- */
Harness::suite( 'JSON without decoding (Elementor/Bricks)' );

/*
 * Page builders store JSON with escaped slashes. UrlMap adds the escaped form
 * to the plain map so a strtr over the raw string handles it - no
 * parse-modify-reencode, which is why there is no JsonRewriter class.
 */
$json_map = UrlMap::with_escaped_slashes(
	array( 'https://example.com/uploads/photo.jpg' => 'https://example.com/uploads/photo.webp' )
);

$json    = '{"image":{"url":"https:\/\/example.com\/uploads\/photo.jpg"}}';
$changed = false;
$out     = rewriter_replace_value( $rewriter, $json, $json_map, $changed, $safe );

Harness::ok( $changed, 'escaped-slash JSON reports a change' );
Harness::ok( false !== json_decode( $out, true ), 'rewritten JSON still parses' );
$parsed = json_decode( $out, true );
Harness::same(
	'https://example.com/uploads/photo.webp',
	isset( $parsed['image']['url'] ) ? $parsed['image']['url'] : null,
	'escaped-slash URL rewritten inside JSON'
);

$keys = array_keys( $json_map );
Harness::ok(
	strlen( $keys[0] ) >= strlen( $keys[ count( $keys ) - 1 ] ),
	'with_escaped_slashes sorts longest key first'
);

/* ---------------------------------------------------------------- */
Harness::suite( 'UrlMap::build' );

$before = array(
	'file'  => '2026/08/photo.jpg',
	'sizes' => array(
		'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
		'medium'    => array( 'file' => 'photo-300x200.jpg' ),
	),
);
$after  = array(
	'file'  => '2026/08/photo.webp',
	'sizes' => array(
		'thumbnail' => array( 'file' => 'photo-150x150.webp' ),
		'medium'    => array( 'file' => 'photo-300x200.webp' ),
	),
);

$built = UrlMap::build( $before, $after, 'https://example.com' );

/*
 * The map is keyed by full URLs, not bare basenames, and carries both the
 * absolute and the protocol-relative form of each. That is deliberate: a bare
 * filename key would match unrelated text elsewhere in a post, and invariant
 * 10 limits rewriting to hardcoded URL strings.
 */
Harness::same(
	'https://example.com/photo.webp',
	isset( $built['https://example.com/photo.jpg'] ) ? $built['https://example.com/photo.jpg'] : null,
	'main file maps by absolute URL'
);
Harness::same(
	'//example.com/photo.webp',
	isset( $built['//example.com/photo.jpg'] ) ? $built['//example.com/photo.jpg'] : null,
	'main file also maps in protocol-relative form'
);
Harness::same(
	'https://example.com/photo-150x150.webp',
	isset( $built['https://example.com/photo-150x150.jpg'] ) ? $built['https://example.com/photo-150x150.jpg'] : null,
	'thumbnail size is mapped by absolute URL'
);
Harness::same(
	'https://example.com/photo-300x200.webp',
	isset( $built['https://example.com/photo-300x200.jpg'] ) ? $built['https://example.com/photo-300x200.jpg'] : null,
	'medium size is mapped by absolute URL'
);
Harness::ok(
	! isset( $built['photo.jpg'] ),
	'bare basename is deliberately NOT a map key (invariant 10)'
);

/*
 * Invariant 10: an unchanged filename must never enter the map. A no-op pair
 * would make strtr rescan text it had already rewritten.
 */
$identical = UrlMap::build(
	array( 'file' => '2026/08/same.webp' ),
	array( 'file' => '2026/08/same.webp' ),
	'https://example.com'
);
Harness::ok( ! isset( $identical['same.webp'] ), 'identical before/after produces no map entry' );

Harness::summary();
