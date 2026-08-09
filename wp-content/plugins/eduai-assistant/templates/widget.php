<?php
/**
 * Floating launcher + docked assistant panel, printed once in the footer.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="eduai-dock" data-eduai-dock hidden>
	<?php
	$eduai_inline = false;
	$eduai_atts   = array( 'height' => 480 );
	include EDUAI_DIR . 'templates/panel.php';
	?>
</div>

<button type="button" class="eduai-launcher" data-eduai-launcher
	aria-expanded="false" aria-label="<?php esc_attr_e( 'Open the study assistant', 'eduai' ); ?>">
	<span class="eduai-launcher__icon">
		<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
			<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9 9 0 0 1-3.6-.7L3 21l1.9-5A8.3 8.3 0 0 1 4 11.5 8.4 8.4 0 0 1 12.5 3 8.4 8.4 0 0 1 21 11.5z"/>
			<path d="M8.5 11h.01M12 11h.01M15.5 11h.01"/>
		</svg>
	</span>
	<span class="eduai-launcher__label"><?php esc_html_e( 'Ask the assistant', 'eduai' ); ?></span>
</button>
