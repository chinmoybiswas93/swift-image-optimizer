<?php
/**
 * Admin SPA mount point.
 *
 * The whole admin screen is one React app; this is the only markup PHP emits
 * for it. Deliberately free of WordPress admin classes beyond .wrap, which is
 * what positions the screen inside the admin body - everything inside is the
 * plugin's own design system.
 *
 * @var string $mountId Element ID the bundle mounts into.
 * @var string $title   Screen title, shown before the bundle boots.
 *
 * @package SwiftImageOptimizer
 */

if (!defined('ABSPATH')) {
    exit;
}

?>
<div class="wrap sio-wrap">
	<div id="<?php echo esc_attr($mountId); ?>" class="sio-app">
		<div class="sio-app__booting" role="status" aria-live="polite">
			<span class="sio-spinner" aria-hidden="true"></span>
			<span class="sio-app__booting-text"><?php echo esc_html($title); ?></span>
		</div>
	</div>
</div>
