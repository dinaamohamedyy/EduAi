<?php
/**
 * Site footer.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;
?>
</main><!-- #main -->

<footer class="site-footer">
	<div class="wrap">
		<div class="site-footer__grid">
			<div>
				<a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
					<span class="brand__mark" aria-hidden="true"><?php echo esc_html( mb_substr( get_bloginfo( 'name' ), 0, 1 ) ); ?></span>
					<span class="brand__name"><?php bloginfo( 'name' ); ?></span>
				</a>
				<p class="text-muted mt-m" style="max-width:38ch">
					<?php echo wp_kses_post( scholaris_opt( 'scholaris_footer_about', __( 'A learning platform for students: lecture material, self-assessment quizzes and an always-on study assistant.', 'scholaris' ) ) ); ?>
				</p>
			</div>

			<div>
				<h4><?php esc_html_e( 'Study', 'scholaris' ); ?></h4>
				<?php
				wp_nav_menu( array(
					'theme_location' => 'footer',
					'container'      => false,
					'depth'          => 1,
					'fallback_cb'    => 'scholaris_fallback_menu',
				) );
				?>
			</div>

			<div>
				<h4><?php esc_html_e( 'Account', 'scholaris' ); ?></h4>
				<ul>
					<?php if ( is_user_logged_in() ) : ?>
						<li><a href="<?php echo esc_url( home_url( '/dashboard/' ) ); ?>"><?php esc_html_e( 'My progress', 'scholaris' ); ?></a></li>
						<li><a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Sign out', 'scholaris' ); ?></a></li>
					<?php else : ?>
						<li><a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Sign in', 'scholaris' ); ?></a></li>
						<li><a href="<?php echo esc_url( wp_registration_url() ); ?>"><?php esc_html_e( 'Create an account', 'scholaris' ); ?></a></li>
					<?php endif; ?>
				</ul>
			</div>

			<?php if ( is_active_sidebar( 'sidebar-1' ) ) : ?>
				<div><?php dynamic_sidebar( 'sidebar-1' ); ?></div>
			<?php endif; ?>
		</div>

		<div class="site-footer__bottom">
			<span>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>. <?php esc_html_e( 'All rights reserved.', 'scholaris' ); ?></span>
			<span><?php esc_html_e( 'Built with WordPress, Tutor LMS and the EduAI Assistant.', 'scholaris' ); ?></span>
		</div>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
