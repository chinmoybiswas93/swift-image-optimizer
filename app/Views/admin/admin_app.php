<?php
/**
 * Admin SPA mount point.
 *
 * The whole admin screen is one React app; this is the only markup PHP emits
 * for it. Deliberately free of WordPress admin classes beyond .wrap, which is
 * what positions the screen inside the admin body - everything inside is the
 * plugin's own design system.
 *
 * `.wp-header-end` is the exception, and it is load-bearing. WordPress moves
 * every admin notice on the page to just after that marker; with no marker it
 * falls back to the first heading inside `.wrap`, which here is the React
 * masthead's own <h1>, and a notice ends up rendered *inside* this plugin's
 * header, between the title and the tagline.
 *
 * What the marker positions is now this plugin's notices only. Everyone else's
 * are removed on this screen by ForeignNoticeHandler: this is a single-purpose
 * application, and it says what it has to say inside the card that caused it or
 * in a toast. The marker still earns its place, because the plugin's own two
 * bootstrap notices - no engine available, assets not built - travel the same
 * admin_notices hook and would otherwise land in the middle of the masthead.
 *
 * @var string $mountId Element ID the bundle mounts into.
 * @var string $title   Screen title, shown before the bundle boots.
 *
 * @package SwiftImageOptimizer
 */

if ( ! defined('ABSPATH')) {
    exit;
}

?>
<div class="wrap sio-wrap">
	<hr class="wp-header-end">
	<div id="<?php echo esc_attr($mountId); ?>" class="sio-app">
		<div class="sio-app__booting" role="status" aria-live="polite">
			<span class="sio-spinner" aria-hidden="true"></span>
			<span class="sio-app__booting-text"><?php echo esc_html($title); ?></span>
		</div>
	</div>
</div>
