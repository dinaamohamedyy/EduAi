<?php
/**
 * Ink side panel shared by the auth pages.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;
?>
<aside class="auth__side">
	<div class="auth__side-in">
		<a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
			<span class="brand__mark" aria-hidden="true"><?php echo esc_html( mb_substr( get_bloginfo( 'name' ), 0, 1 ) ); ?></span>
			<span>
				<span class="brand__name"><?php bloginfo( 'name' ); ?></span>
				<?php $desc = get_bloginfo( 'description', 'display' ); ?>
				<?php if ( $desc ) : ?>
					<span class="brand__tag"><?php echo esc_html( $desc ); ?></span>
				<?php endif; ?>
			</span>
		</a>

		<h2><?php echo wp_kses_post( __( 'Your course material, <em>remembered</em> for you.', 'scholaris' ) ); ?></h2>

		<ul class="auth__list">
			<li><?php esc_html_e( 'Every lecture, slide deck and past paper in one searchable library.', 'scholaris' ); ?></li>
			<li><?php esc_html_e( 'Quizzes that keep your full attempt history and show how you trend.', 'scholaris' ); ?></li>
			<li><?php esc_html_e( 'An assistant that has read your material and answers with citations.', 'scholaris' ); ?></li>
		</ul>

		<p class="auth__side-foot"><?php echo esc_html( get_bloginfo( 'name' ) ); ?> · <?php esc_html_e( 'Learning platform', 'scholaris' ); ?></p>
	</div>
</aside>
