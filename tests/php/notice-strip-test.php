<?php
/**
 * Notice-suppression suite for ForeignNoticeHandler. Needs no database.
 *
 * Runs against WordPress's real WP_Hook and plugin.php rather than a stub of
 * them, because the whole handler is an assertion about how WP_Hook stores
 * callbacks: it enumerates $wp_filter and removes by callback. A hand-rolled
 * hook emulator would prove nothing about the structure it actually walks.
 *
 * The classification is the part worth guarding. Keeping a foreign notice is a
 * cosmetic regression; dropping one of the plugin's own is not, because the
 * missing-build notice is the only thing that can speak when the React bundle
 * is absent.
 *
 *   php tests/php/notice-strip-test.php
 *
 * @package SwiftImageOptimizer
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, Squiz.Commenting, Generic.Commenting, WordPress.PHP.DiscouragedPHPFunctions, Universal.Files.SeparateFunctionsFromOO, WordPress.WP.GlobalVariablesOverride

require __DIR__ . '/bootstrap.php';

$sio_plugin_dir = dirname( __DIR__, 2 );
$sio_wp_root    = dirname( $sio_plugin_dir, 3 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $sio_wp_root . '/' );
}

require $sio_wp_root . '/wp-includes/plugin.php';
require $sio_plugin_dir . '/vendor/autoload.php';

/*
 * The one WordPress function the handler calls that plugin.php does not carry.
 * Backed by a global so the screen guard can be tested both ways.
 */
$GLOBALS['sio_test_screen_id'] = 'media_page_swift-image-optimizer';

if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() {
		return (object) array( 'id' => $GLOBALS['sio_test_screen_id'] );
	}
}

function swift_image_optimizer_test_own_notice() {}
function sio_test_other_plugin_notice() {}

class SIO_Test_Foreign_Notices {

	public function license_mismatch() {}

	public static function upsell() {}
}

use SwiftImageOptimizer\App\Hooks\Handlers\ForeignNoticeHandler;
use SwiftImageOptimizer\App\Hooks\Handlers\MenuHandler;
use SwiftImageOptimizer\App\Hooks\Handlers\NoticeHandler;

/**
 * Every callback currently registered on a hook, rendered as a sortable name.
 *
 * @param string $hook Hook name.
 * @return string[]
 */
function sio_test_survivors( $hook ) {
	$names = array();

	if ( empty( $GLOBALS['wp_filter'][ $hook ] ) ) {
		return $names;
	}

	foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$function = $callback['function'];

			if ( is_string( $function ) ) {
				$names[] = $function;
			} elseif ( is_array( $function ) ) {
				$names[] = ( is_object( $function[0] ) ? get_class( $function[0] ) : $function[0] ) . '::' . $function[1];
			} else {
				$names[] = 'Closure';
			}
		}
	}

	sort( $names );

	return $names;
}

Harness::suite( 'Foreign notices are cleared from the plugin screen' );

$sio_hooks = array( 'admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices' );

$sio_foreign = new SIO_Test_Foreign_Notices();
$sio_notice  = new NoticeHandler();
$sio_menu    = new MenuHandler();

foreach ( $sio_hooks as $sio_hook ) {
	// Foreign, in every shape a plugin can register one.
	add_action( $sio_hook, array( $sio_foreign, 'license_mismatch' ) );
	add_action( $sio_hook, array( 'SIO_Test_Foreign_Notices', 'upsell' ), 20 );
	add_action( $sio_hook, 'SIO_Test_Foreign_Notices::upsell', 30 );
	add_action( $sio_hook, 'sio_test_other_plugin_notice' );
	add_action(
		$sio_hook,
		function () {}
	);
	// Core's own update nag rides the same hook and goes with the rest.
	add_action( $sio_hook, 'update_nag', 5 );

	// Ours, in every shape this plugin uses.
	add_action( $sio_hook, array( $sio_notice, 'maybe_render_no_engine_notice' ) );
	add_action( $sio_hook, array( $sio_menu, 'missing_build_notice' ) );
	add_action( $sio_hook, 'swift_image_optimizer_test_own_notice' );
	add_action( $sio_hook, array( NoticeHandler::class, 'maybe_render_no_engine_notice' ), 40 );
}

( new ForeignNoticeHandler() )->strip_foreign_notices();

$sio_expected = array(
	MenuHandler::class . '::missing_build_notice',
	NoticeHandler::class . '::maybe_render_no_engine_notice',
	NoticeHandler::class . '::maybe_render_no_engine_notice',
	'swift_image_optimizer_test_own_notice',
);
sort( $sio_expected );

foreach ( $sio_hooks as $sio_hook ) {
	$sio_survivors = sio_test_survivors( $sio_hook );

	Harness::same(
		$sio_expected,
		$sio_survivors,
		$sio_hook . ': the plugin\'s four callbacks survive and the six foreign ones do not'
	);
}

Harness::suite( 'What the suppression must never do' );

/*
 * The missing-build notice is the one that cannot be lost. When the bundle is
 * absent React renders nothing, so this notice is the only thing between the
 * user and a blank screen - which is the whole reason the handler removes by
 * callback instead of calling remove_all_actions().
 */
Harness::ok(
	false !== has_action( 'admin_notices', array( $sio_menu, 'missing_build_notice' ) ),
	'the missing-build notice survives, with no bundle it is the only voice left'
);

// A closure carries no name to attribute it by, so it counts as foreign.
Harness::same(
	array(),
	array_filter(
		sio_test_survivors( 'admin_notices' ),
		function ( $name ) {
			return 'Closure' === $name;
		}
	),
	'a closure is treated as foreign, since nothing identifies it as ours'
);

// Core screens belong to whoever put a notice there.
$GLOBALS['sio_test_screen_id'] = 'upload';
add_action( 'admin_notices', 'sio_test_other_plugin_notice' );
( new ForeignNoticeHandler() )->strip_foreign_notices();

Harness::ok(
	false !== has_action( 'admin_notices', 'sio_test_other_plugin_notice' ),
	'the Media Library is untouched: only the plugin\'s own screen is cleared'
);

$GLOBALS['sio_test_screen_id'] = 'plugins';
( new ForeignNoticeHandler() )->strip_foreign_notices();

Harness::ok(
	false !== has_action( 'admin_notices', 'sio_test_other_plugin_notice' ),
	'the plugin list is untouched too'
);

Harness::suite( 'The escape hatch' );

$GLOBALS['sio_test_screen_id'] = 'media_page_swift-image-optimizer';
add_action( 'admin_notices', array( $sio_foreign, 'license_mismatch' ) );
add_filter(
	'swift_image_optimizer_hide_foreign_notices',
	function () {
		return false;
	}
);

( new ForeignNoticeHandler() )->strip_foreign_notices();

Harness::ok(
	false !== has_action( 'admin_notices', array( $sio_foreign, 'license_mismatch' ) ),
	'swift_image_optimizer_hide_foreign_notices=false gives every notice back'
);

Harness::summary();
