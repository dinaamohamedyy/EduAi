<?php
/**
 * Template Name: Create account
 *
 * Posts to the native WordPress registration endpoint. The extra fields
 * (full name, password chosen at signup) are injected at the
 * `register_form` hook by inc/auth-flow.php, which also validates them,
 * signs the new student in and lands them on the dashboard. Without that
 * file the form still works — core falls back to e-mailing a link to set
 * the password. See docs/05-frontend-handoff.md.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="auth">
	<?php get_template_part( 'template-parts/auth', 'side' ); ?>

	<div class="auth__main">
		<div class="auth__card">
			<span class="eyebrow"><?php esc_html_e( 'Join your course', 'scholaris' ); ?></span>
			<h1><?php esc_html_e( 'Create an account', 'scholaris' ); ?></h1>
			<p class="text-muted"><?php esc_html_e( 'One account for the library, your quiz history and the study assistant.', 'scholaris' ); ?></p>

			<?php scholaris_auth_notices(); ?>

			<?php if ( get_option( 'users_can_register' ) ) : ?>
				<form class="auth__form" method="post" action="<?php echo esc_url( site_url( 'wp-login.php?action=register', 'login_post' ) ); ?>">
					<div class="field">
						<label for="user_login"><?php esc_html_e( 'Username', 'scholaris' ); ?></label>
						<input type="text" name="user_login" id="user_login" autocomplete="username" required
							autocapitalize="off" spellcheck="false"
							value="<?php echo esc_attr( scholaris_auth_old( 'username' ) ); ?>">
					</div>
					<div class="field">
						<label for="user_email"><?php esc_html_e( 'E-mail address', 'scholaris' ); ?></label>
						<input type="email" name="user_email" id="user_email" autocomplete="email" required
							value="<?php echo esc_attr( scholaris_auth_old( 'email' ) ); ?>">
					</div>

					<?php
					// Extra registration fields from plugins or the back end hook in here.
					do_action( 'register_form' );
					?>

					<input type="hidden" name="sl_from" value="register">
					<button class="btn btn--primary btn--lg btn--block" type="submit" name="wp-submit">
						<?php esc_html_e( 'Create account', 'scholaris' ); ?>
					</button>

					<p class="auth__fineprint">
						<?php
						// Copy kept in step with inc/auth-flow.php: the password is chosen above, so there is no set-password e-mail.
						esc_html_e( 'You can start studying straight away — we also send a confirmation to your e-mail address.', 'scholaris' );
						?>
					</p>
				</form>
			<?php else : ?>
				<p class="notice notice--info" role="status">
					<?php esc_html_e( 'Registration is currently closed. Ask your course administrator for an account.', 'scholaris' ); ?>
				</p>
			<?php endif; ?>

			<p class="auth__alt">
				<?php esc_html_e( 'Already have an account?', 'scholaris' ); ?>
				<a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Sign in', 'scholaris' ); ?></a>
			</p>
		</div>
	</div>
</div>

<?php
get_footer();
