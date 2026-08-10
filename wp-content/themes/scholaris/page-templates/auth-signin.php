<?php
/**
 * Template Name: Sign in
 *
 * Posts to the native WordPress login endpoint, so it works with no custom
 * back end — including with wps-hide-login, which filters site_url() for
 * wp-login.php. Failed attempts come back here with
 * ?login=failed|nouser|empty|throttled (bounced by inc/auth-flow.php,
 * rendered by scholaris_auth_notices in inc/auth.php) plus an ?sl_form=
 * refill token that scholaris_auth_old() turns back into the typed value.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

get_header();

// Where to land after signing in: an explicit ?redirect_to= wins, else the
// student's progress page.
$scholaris_redirect = scholaris_progress_url();
if ( isset( $_GET['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$requested = esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $requested && str_starts_with( $requested, home_url() ) ) {
		$scholaris_redirect = $requested;
	}
}
?>

<div class="auth">
	<?php get_template_part( 'template-parts/auth', 'side' ); ?>

	<div class="auth__main">
		<div class="auth__card">
			<span class="eyebrow"><?php esc_html_e( 'Welcome back', 'scholaris' ); ?></span>
			<h1><?php esc_html_e( 'Sign in', 'scholaris' ); ?></h1>
			<p class="text-muted"><?php esc_html_e( 'Pick up where you left off — your library, quiz history and assistant are waiting.', 'scholaris' ); ?></p>

			<?php scholaris_auth_notices(); ?>

			<form class="auth__form" method="post" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>">
				<div class="field">
					<label for="user_login"><?php esc_html_e( 'Username or e-mail', 'scholaris' ); ?></label>
					<input type="text" name="log" id="user_login" autocomplete="username" required
						autocapitalize="off" spellcheck="false"
						value="<?php echo esc_attr( scholaris_auth_old( 'login' ) ); ?>">
				</div>
				<div class="field">
					<label for="user_pass"><?php esc_html_e( 'Password', 'scholaris' ); ?></label>
					<input type="password" name="pwd" id="user_pass" autocomplete="current-password" required>
				</div>

				<div class="auth__row">
					<label class="check">
						<input type="checkbox" name="rememberme" value="forever">
						<?php esc_html_e( 'Remember me', 'scholaris' ); ?>
					</label>
					<a class="auth__link" href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Forgot password?', 'scholaris' ); ?></a>
				</div>

				<?php
				// Security and 2FA plugins (Wordfence among them) inject their fields here.
				do_action( 'login_form' );
				?>

				<input type="hidden" name="redirect_to" value="<?php echo esc_url( $scholaris_redirect ); ?>">
				<input type="hidden" name="sl_from" value="login">
				<button class="btn btn--primary btn--lg btn--block" type="submit" name="wp-submit">
					<?php esc_html_e( 'Sign in', 'scholaris' ); ?>
				</button>
			</form>

			<?php if ( get_option( 'users_can_register' ) ) : ?>
				<p class="auth__alt">
					<?php esc_html_e( 'New here?', 'scholaris' ); ?>
					<a href="<?php echo esc_url( wp_registration_url() ); ?>"><?php esc_html_e( 'Create an account', 'scholaris' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php
get_footer();
