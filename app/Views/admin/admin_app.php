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
 * masthead's own <h1>. That is how a third-party notice - Elementor's licence
 * warning, in the reported case - ended up rendered *inside* this plugin's
 * header, between the title and the tagline.
 *
 * The marker does not hide anyone's notices, which would be its own kind of
 * rude. It just says where they go: above the app, not through the middle of
 * it.
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
