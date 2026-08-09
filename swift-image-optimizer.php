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
 * PSR-4 style autoloader for the plugin's own classes.
 *
 * Maps SwiftImageOptimizer\Foo\Bar to src/Foo/Bar.php, with one exception:
 * SwiftImageOptimizer\Database\Foo maps to database/Foo.php instead - a
 * sibling root to src/, mirroring the app/ vs database/ split used by larger
 * sibling plugins. Deliberately dependency free so the plugin ships without
 * a Composer autoloader.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function swift_image_optimizer_autoload( $class_name ) {
	$prefix = 'SwiftImageOptimizer\\';

	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}

	$database_prefix = 'SwiftImageOptimizer\\Database\\';

	if ( 0 === strpos( $class_name, $database_prefix ) ) {
		$relative = substr( $class_name, strlen( $database_prefix ) );
		$path     = SWIFT_IMAGE_OPTIMIZER_DIR . 'database/' . str_replace( '\\', '/', $relative ) . '.php';
	} else {
		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = SWIFT_IMAGE_OPTIMIZER_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
	}

	if ( is_readable( $path ) ) {
		require_once $path;
	}
}
spl_autoload_register( 'swift_image_optimizer_autoload' );

/**
 * Boot the plugin.
 *
 * @return \SwiftImageOptimizer\Plugin
 */
function swift_image_optimizer() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new \SwiftImageOptimizer\Plugin();
	}

	return $instance;
}

add_action( 'plugins_loaded', 'swift_image_optimizer' );

/**
 * Activation: create the log table and schedule the retention cron.
 *
 * @return void
 */
function swift_image_optimizer_activate() {
	\SwiftImageOptimizer\Database\Database::install();
	\SwiftImageOptimizer\Hooks\Scheduler\RetentionCron::schedule();
}
register_activation_hook( __FILE__, 'swift_image_optimizer_activate' );

/**
 * Deactivation: clear scheduled events. Data and files are left intact.
 *
 * @return void
 */
function swift_image_optimizer_deactivate() {
	\SwiftImageOptimizer\Hooks\Scheduler\RetentionCron::unschedule();
}
register_deactivation_hook( __FILE__, 'swift_image_optimizer_deactivate' );
