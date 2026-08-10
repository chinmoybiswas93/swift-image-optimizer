<?php
/**
 * Optimization column cell in the Media Library list table.
 *
 * Replaces inline `style="color:#008a20"` and WordPress's `.description`
 * class with plugin-owned classes, so the cell is styled from the plugin's
 * stylesheet rather than from core's admin CSS and hardcoded hex values.
 *
 * @var string $state   One of optimized, skipped, failed, restored, pending, none.
 * @var string $primary Main line of text.
 * @var string $detail  Secondary line, may be empty.
 *
 * @package SwiftImageOptimizer
 */

if (!defined('ABSPATH')) {
    exit;
}

$detail = isset($detail) ? $detail : '';

if ('none' === $state) {
    echo '<span class="sio-cell sio-cell--none" aria-hidden="true">&#8212;</span>';

    return;
}

?>
<span class="sio-cell sio-cell--<?php echo esc_attr(sanitize_html_class($state)); ?>">
	<span class="sio-cell__primary"><?php echo esc_html($primary); ?></span>
	<?php if ('' !== $detail) : ?>
		<span class="sio-cell__detail"><?php echo esc_html($detail); ?></span>
	<?php endif; ?>
</span>
