<?php
/**
 * Plugin Name:       Swift Image Optimizer
 * Plugin URI:        https://wpswift.com/swift-image-optimizer
 * Description:       Automatically convert and compress images to WebP on upload, plus individual and bulk optimization for your existing Media Library.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            crocodevs
 * Author URI:        https://crocodevs.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       swift-image-optimizer
 * Domain Path:       /languages
 *
 * @package SwiftImageOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SWIFT_IMAGE_OPTIMIZER_VERSION', '1.1.0' );
define( 'SWIFT_IMAGE_OPTIMIZER_FILE', __FILE__ );
define( 'SWIFT_IMAGE_OPTIMIZER_DIR', plugin_dir_path( __FILE__ ) );
define( 'SWIFT_IMAGE_OPTIMIZER_URL', plugin_dir_url( __FILE__ ) );
define( 'SWIFT_IMAGE_OPTIMIZER_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Boot the plugin and return the Application.
 *
 * The second argument is evaluated only for its side effect: it registers
 * Composer's autoloader before boot/app.php's closure runs, so the closure can
 * reference plugin classes. SwiftImageOptimizer\App\Foo maps PSR-4 to app/Foo,
 * and the Database namespace is a classmapped sibling root.
 *
 * The plugin has no Composer dependencies - vendor/ holds nothing but the
 * generated autoloader, committed so the plugin ships without needing a build
 * step on the destination server.
 *
 * @return \SwiftImageOptimizer\App\Foundation\Application
 */
return ( function ( $boot ) {
	return $boot( __FILE__ );
} )(
	require __DIR__ . '/boot/app.php',
	require __DIR__ . '/vendor/autoload.php'
);
