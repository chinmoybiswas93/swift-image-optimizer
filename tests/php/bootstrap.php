<?php
/**
 * Shared harness bootstrap: assertions, WordPress stubs, and the socket guard.
 *
 * These harnesses are standalone PHP scripts, not PHPUnit. They are committed
 * because the previous set lived only in a session scratchpad and was lost,
 * taking 143 assertions of regression cover with it.
 *
 * @package SwiftImageOptimizer
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB.DirectDatabaseQuery, Squiz.Commenting, Generic.Commenting, WordPress.WP.GlobalVariablesOverride

if ( PHP_SAPI !== 'cli' ) {
	exit( 'Harnesses are CLI-only.' );
}

final class Harness {

	/** @var int */
	public static $passed = 0;

	/** @var string[] */
	public static $failures = array();

	/** @var string */
	public static $suite = '';

	public static function suite( $name ) {
		self::$suite = $name;
		echo "\n=== {$name} ===\n";
	}

	public static function ok( $condition, $label ) {
		if ( $condition ) {
			++self::$passed;
			echo "  PASS  {$label}\n";
			return true;
		}

		self::$failures[] = self::$suite . ' :: ' . $label;
		echo "  FAIL  {$label}\n";
		return false;
	}

	public static function same( $expected, $actual, $label ) {
		$equal = ( $expected === $actual );

		if ( ! $equal ) {
			$label .= sprintf(
				' (expected %s, got %s)',
				self::render( $expected ),
				self::render( $actual )
			);
		}

		return self::ok( $equal, $label );
	}

	private static function render( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_scalar( $value ) || null === $value ) {
			return var_export( $value, true );
		}

		return str_replace( array( "\n", '  ' ), '', var_export( $value, true ) );
	}

	/**
	 * Print the tally and exit non-zero on any failure, so CI and shell
	 * chaining can rely on the exit code rather than on reading output.
	 */
	public static function summary() {
		echo "\n";
		echo str_repeat( '-', 60 ), "\n";

		if ( self::$failures ) {
			printf( "FAILED: %d passed, %d failed\n", self::$passed, count( self::$failures ) );

			foreach ( self::$failures as $failure ) {
				echo "  - {$failure}\n";
			}

			exit( 1 );
		}

		printf( "OK: %d assertions passed\n", self::$passed );
		exit( 0 );
	}
}

/**
 * Refuse to run against the wrong database.
 *
 * Local names every database `local` and distinguishes sites only by socket.
 * Pairing one site's files with another site's database looks like it works
 * and has already produced two confidently-reported findings that were pure
 * fiction, plus a harness that deleted 54 real backups. Every WordPress-backed
 * harness calls this before touching anything.
 */
function harness_require_site( $expected = 'https://cb-test.local' ) {
	$actual = get_option( 'siteurl' );

	if ( $actual !== $expected ) {
		fwrite(
			STDERR,
			"REFUSING TO RUN.\n"
			. "Expected siteurl {$expected} but found {$actual}.\n"
			. "This almost certainly means the wrong MySQL socket was passed and\n"
			. "the harness is looking at a different site's database.\n"
		);
		exit( 2 );
	}

	echo "site: {$actual}\n";
}
