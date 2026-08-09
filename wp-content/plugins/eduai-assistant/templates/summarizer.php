<?php
/**
 * Standalone summariser, rendered by [eduai_summarizer].
 * Reuses the app shell but opens straight on the summary tab.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

$eduai_inline = true;
$eduai_atts   = array( 'height' => 460 );
?>
<div class="eduai-standalone" data-eduai-default-tab="summary">
	<?php include EDUAI_DIR . 'templates/panel.php'; ?>
</div>
