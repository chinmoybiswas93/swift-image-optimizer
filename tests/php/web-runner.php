<?php
/**
 * Run a harness under the site's web PHP instead of CLI PHP.
 *
 * This exists for one reason: Imagick is present in php-fpm here and absent
 * from every CLI build, so a CLI run silently exercises cwebp no matter what
 * engine is requested. That gap is issue I-2, and it is what let the cwebp
 * rotation bug reach production.
 *
 * Access control, in order:
 *
 *   1. `tests/` never ships - bin/build-dist.sh excludes it.
 *   2. This script is inert unless `.web-token` exists beside it, which means
 *      an attacker needs filesystem write access to the plugin directory,
 *      at which point they do not need this file.
 *   3. The token must match the request and be less than two minutes old, so
 *      it is a one-shot credential rather than a standing one.
 *
 * Driven by `tests/php/run.sh --web`, which creates the token, makes the
 * request, and deletes the token afterwards.
 *
 * @package SwiftImageOptimizer
 */

// phpcs:disable

header( 'Content-Type: text/plain; charset=utf-8' );

$token_file = __DIR__ . '/.web-token';

if ( ! file_exists( $token_file ) ) {
	http_response_code( 404 );
	exit( "not enabled\n" );
}

if ( time() - filemtime( $token_file ) > 120 ) {
	@unlink( $token_file );
	http_response_code( 403 );
	exit( "token expired\n" );
}

$expected = trim( (string) file_get_contents( $token_file ) );
$given    = isset( $_GET['token'] ) ? trim( (string) $_GET['token'] ) : '';

if ( '' === $expected || ! hash_equals( $expected, $given ) ) {
	http_response_code( 403 );
	exit( "bad token\n" );
}

$suite = isset( $_GET['suite'] ) ? basename( (string) $_GET['suite'] ) : '';
$suite = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $suite ) );
$file  = __DIR__ . '/' . $suite . '.php';

if ( '' === $suite || ! file_exists( $file ) ) {
	http_response_code( 404 );
	exit( "no such suite\n" );
}

if ( isset( $_GET['engine'] ) ) {
	$engine = preg_replace( '/[^a-z]/', '', strtolower( (string) $_GET['engine'] ) );

	if ( in_array( $engine, array( 'imagick', 'cwebp', 'gd', 'auto' ), true ) ) {
		putenv( 'SIO_TEST_ENGINE=' . $engine );
		$_ENV['SIO_TEST_ENGINE'] = $engine;
	}
}

// Harnesses are written for CLI and check PHP_SAPI; tell the bootstrap this
// request is a deliberate exception.
define( 'SIO_HARNESS_WEB', true );

set_time_limit( 600 );

echo "sapi: ", PHP_SAPI, "  php: ", PHP_VERSION, "\n";
echo "imagick: ", extension_loaded( 'imagick' ) ? 'yes' : 'no', "\n";

require $file;
