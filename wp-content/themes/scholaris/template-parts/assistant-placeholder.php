<?php
/**
 * Shown in the hero when the EduAI Assistant plugin is not active.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="card" style="padding:var(--space-l)">
	<span class="badge badge--warning"><?php esc_html_e( 'Assistant not active', 'scholaris' ); ?></span>
	<h3 class="mt-m"><?php esc_html_e( 'EduAI Assistant is not enabled yet', 'scholaris' ); ?></h3>
	<p class="text-muted">
		<?php esc_html_e( 'Activate the EduAI Assistant plugin to switch this panel on. The model key comes from the server, so there is nothing to enter.', 'scholaris' ); ?>
	</p>
	<?php if ( current_user_can( 'activate_plugins' ) ) : ?>
		<a class="btn btn--primary" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">
			<?php esc_html_e( 'Go to plugins', 'scholaris' ); ?>
		</a>
	<?php endif; ?>
</div>
