<?php
/**
 * Admin notice.
 *
 * Entirely the plugin's own markup. It previously carried core's bare `notice`
 * class as well, to borrow two behaviours: WordPress repositions elements with
 * that class below the page heading, and binds dismissal to `is-dismissible`.
 *
 * Borrowing them also meant inheriting core's notice styling, which is what put
 * a WordPress-looking notice on the plugin's own screen and left the plugin's
 * appearance dependent on admin CSS that changes between releases. Position is
 * ours now, via `.sio-notice--standalone`.
 *
 * There is deliberately no dismiss button. The two bootstrap notices - no
 * engine available, assets not built - describe conditions that are still true
 * after being dismissed, and the Media Library result notice is gone on the
 * next page load regardless. Adding one would mean either inline JavaScript or
 * a script that is not guaranteed to have loaded, to hide a message that
 * should not be hidden. Transient in-app feedback is a toast instead.
 *
 * @var string $type        One of error, warning, success, info.
 * @var string $message     Notice body. Pre-escaped by the caller.
 * @var string $heading     Optional bold lead-in.
 *
 * @package SwiftImageOptimizer
 */

if ( ! defined('ABSPATH')) {
    exit;
}

/*
 * These are template variables, extracted into the local scope of
 * View::render() and never global. PHPCS analyses a template as a standalone
 * file, so it reads them as globals and cannot see the method doing the
 * including.
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited

$type    = isset($type) ? $type : 'info';
$heading = isset($heading) ? $heading : '';

$classes = [
    'sio-notice',
    'sio-notice--' . sanitize_html_class($type),
    'sio-notice--standalone',
];

?>
<div
	class="<?php echo esc_attr(implode(' ', $classes)); ?>"
	role="<?php echo 'error' === $type ? 'alert' : 'status'; ?>"
>
	<p class="sio-notice__body">
		<?php if ('' !== $heading) : ?>
			<strong class="sio-notice__heading"><?php echo esc_html($heading); ?></strong>
		<?php endif; ?>
		<?php echo esc_html($message); ?>
	</p>
</div>
