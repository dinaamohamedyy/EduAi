<?php
/**
 * Template Name: Reset password
 *
 * Posts to the native lost-password endpoint. WordPress e-mails the reset
 * link; the form the link opens is the branded wp-login.php screen
 * (see inc/auth.php). The confirmation flag comes back here as
 * ?checkemail=confirm.
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
			<span class="eyebrow"><?php esc_html_e( 'Account recovery', 'scholaris' ); ?></span>
			<h1><?php esc_html_e( 'Reset your password', 'scholaris' ); ?></h1>
			<p class="text-muted"><?php esc_html_e( 'Tell us which account, and we will e-mail you a link to choose a new password.', 'scholaris' ); ?></p>

			<?php scholaris_auth_notices(); ?>

			<form class="auth__form" method="post" action="<?php echo esc_url( site_url( 'wp-login.php?action=lostpassword', 'login_post' ) ); ?>">
				<div class="field">
					<label for="user_login"><?php esc_html_e( 'Username or e-mail', 'scholaris' ); ?></label>
					<input type="text" name="user_login" id="user_login" autocomplete="username" required
						autocapitalize="off" spellcheck="false"
						value="<?php echo esc_attr( scholaris_auth_old( 'login' ) ); ?>">
				</div>

				<?php do_action( 'lostpassword_form' ); ?>

				<input type="hidden" name="redirect_to" value="<?php echo esc_url( add_query_arg( 'checkemail', 'confirm', get_permalink() ) ); ?>">
				<input type="hidden" name="sl_from" value="lostpassword">
				<button class="btn btn--primary btn--lg btn--block" type="submit" name="wp-submit">
					<?php esc_html_e( 'E-mail me a reset link', 'scholaris' ); ?>
				</button>
			</form>

			<p class="auth__alt">
				<?php esc_html_e( 'Remembered it after all?', 'scholaris' ); ?>
				<a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Back to sign in', 'scholaris' ); ?></a>
			</p>
		</div>
	</div>
</div>

<?php
get_footer();
