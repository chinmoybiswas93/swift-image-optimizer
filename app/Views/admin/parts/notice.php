<?php
/**
 * Admin notice.
 *
 * Rendered with the plugin's own classes rather than WordPress's
 * notice notice-error markup, so notices match the rest of the plugin's UI
 * instead of inheriting core admin styling that can change under us.
 *
 * The .notice class is kept alongside ours for one reason only: WordPress
 * moves elements carrying it into the correct position below the page heading,
 * and dismissible behaviour is bound to it.
 *
 * @var string $type        One of error, warning, success, info.
 * @var string $message     Notice body. Pre-escaped by the caller.
 * @var string $heading     Optional bold lead-in.
 * @var bool   $dismissible Whether to render the core dismiss button.
 *
 * @package SwiftImageOptimizer
 */

if (!defined('ABSPATH')) {
    exit;
}

$type        = isset($type) ? $type : 'info';
$heading     = isset($heading) ? $heading : '';
$dismissible = !empty($dismissible);

$classes = [
    'notice',
    'sio-notice',
    'sio-notice--' . sanitize_html_class($type),
];

if ($dismissible) {
    $classes[] = 'is-dismissible';
}

?>
<div class="<?php echo esc_attr(implode(' ', $classes)); ?>">
	<p class="sio-notice__body">
		<?php if ('' !== $heading) : ?>
			<strong class="sio-notice__heading"><?php echo esc_html($heading); ?></strong>
		<?php endif; ?>
		<?php echo esc_html($message); ?>
	</p>
</div>
