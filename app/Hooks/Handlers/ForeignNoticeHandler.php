<?php
/**
 * Notice policy for the plugin's own admin screen.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Hooks\Handlers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clears everyone else's admin notices from this plugin's screen.
 *
 * The Bulk Optimize screen is a single-purpose application, not a dashboard,
 * and it has its own vocabulary for saying things: notices inside the card that
 * caused them, and toasts for transient feedback. A stack of unrelated licence
 * warnings and upsells above all of that pushes the actual interface below the
 * fold, which is what the user reported.
 *
 * Scope is deliberately one screen. Stripping notices from screens the plugin
 * does not own - the Media Library, the plugin list - would hide messages the
 * user went to those screens to read, and is the behaviour this plugin objects
 * to when other plugins do it.
 */
class ForeignNoticeHandler {

	/**
	 * The four hooks WordPress renders admin notices through.
	 *
	 * @var string[]
	 */
	const NOTICE_HOOKS = array(
		'admin_notices',
		'all_admin_notices',
		'user_admin_notices',
		'network_admin_notices',
	);

	/**
	 * Namespace prefix identifying this plugin's own callbacks.
	 */
	const OWN_NAMESPACE = 'SwiftImageOptimizer\\';

	/**
	 * Register hooks.
	 *
	 * `in_admin_header` is the last moment before the notice hooks fire and the
	 * first at which everything is registered: wp-admin/admin-header.php runs
	 * admin_enqueue_scripts, then in_admin_header, then the four notice hooks.
	 * Anything earlier would run before MenuHandler::enqueue() has had the
	 * chance to add its missing-build notice.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'in_admin_header', array( $this, 'strip_foreign_notices' ) );
	}

	/**
	 * Remove every notice callback that is not this plugin's own.
	 *
	 * @return void
	 */
	public function strip_foreign_notices() {
		if ( ! $this->is_plugin_screen() ) {
			return;
		}

		/**
		 * Filters whether other plugins' notices are hidden on this screen.
		 *
		 * The escape hatch for the case this feature cannot anticipate: a host
		 * or security notice that a user genuinely needs to see, on the one
		 * screen where it would otherwise be removed.
		 *
		 * @param bool $hide Whether to hide foreign notices. Default true.
		 */
		if ( ! apply_filters( 'swift_image_optimizer_hide_foreign_notices', true ) ) {
			return;
		}

		foreach ( self::NOTICE_HOOKS as $hook ) {
			$this->strip_hook( $hook );
		}
	}

	/**
	 * Whether the current screen is the plugin's own dashboard.
	 *
	 * @return bool
	 */
	private function is_plugin_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen && 'media_page_' . MenuHandler::SLUG === $screen->id;
	}

	/**
	 * Remove foreign callbacks from one notice hook.
	 *
	 * Enumerating what is registered on a hook means reading $wp_filter; there
	 * is no API for it. That global is WordPress's own hook registry, not the
	 * database - the plugin's "never touch a global directly" rule is about
	 * $wpdb, and does not apply here.
	 *
	 * Removing by callback rather than remove_all_actions() is the point: the
	 * plugin's own two notices - no engine available, assets not built - have
	 * to survive. The second especially, since when the bundle is missing it is
	 * the only thing that can report anything at all.
	 *
	 * @param string $hook Hook name.
	 * @return void
	 */
	private function strip_hook( $hook ) {
		if ( empty( $GLOBALS['wp_filter'][ $hook ] ) ) {
			return;
		}

		$registered = $GLOBALS['wp_filter'][ $hook ]->callbacks;

		foreach ( $registered as $priority => $callbacks ) {
			// Iterating a copy: remove_action() mutates the array above.
			foreach ( $callbacks as $callback ) {
				if ( $this->is_own_callback( $callback['function'] ) ) {
					continue;
				}

				remove_action( $hook, $callback['function'], $priority );
			}
		}
	}

	/**
	 * Whether a registered callback belongs to this plugin.
	 *
	 * Anything unrecognised - a closure above all, which carries no name to
	 * attribute it by - counts as foreign. That is the safe direction: the
	 * plugin registers its notices as methods on its own classes, so a closure
	 * here is someone else's.
	 *
	 * @param mixed $function Registered callback.
	 * @return bool
	 */
	private function is_own_callback( $function ) {
		if ( is_string( $function ) ) {
			if ( false !== strpos( $function, '::' ) ) {
				return $this->is_own_class( strstr( $function, '::', true ) );
			}

			return 0 === strpos( $function, 'swift_image_optimizer' );
		}

		if ( is_array( $function ) && isset( $function[0] ) ) {
			$class = is_object( $function[0] ) ? get_class( $function[0] ) : $function[0];

			return is_string( $class ) && $this->is_own_class( $class );
		}

		return false;
	}

	/**
	 * Whether a class name sits in this plugin's namespace.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return bool
	 */
	private function is_own_class( $class_name ) {
		return 0 === strpos( ltrim( $class_name, '\\' ), self::OWN_NAMESPACE );
	}
}
