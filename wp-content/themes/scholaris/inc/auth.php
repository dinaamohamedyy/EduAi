<?php
/**
 * Themed authentication: routes WordPress's login/register/lost-password URLs
 * to the theme's own pages, and brands the residual wp-login.php screens
 * (errors, e-mail confirmations, the reset form opened from an e-mail link).
 *
 * The forms in page-templates/auth-*.php post to the NATIVE WordPress
 * endpoints, so everything here works with no custom back end. Back-end work
 * (custom registration fields, password-on-signup) hooks into the actions
 * listed in docs/05-frontend-handoff.md.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

/**
 * The three auth pages, by slug (created by scripts/setup.sh) and template.
 */
function scholaris_auth_pages(): array {
	return array(
		'login'        => array( 'slug' => 'sign-in', 'template' => 'page-templates/auth-signin.php' ),
		'register'     => array( 'slug' => 'register', 'template' => 'page-templates/auth-register.php' ),
		'lostpassword' => array( 'slug' => 'reset-password', 'template' => 'page-templates/auth-reset.php' ),
	);
}

/**
 * Permalink of one of the themed auth pages, '' when the page does not exist.
 */
function scholaris_auth_url( string $which ): string {
	$pages = scholaris_auth_pages();
	if ( ! isset( $pages[ $which ] ) ) {
		return '';
	}
	$page = get_page_by_path( $pages[ $which ]['slug'] );
	return $page ? (string) get_permalink( $page ) : '';
}

/**
 * Point wp_login_url() at /sign-in/, keeping the redirect_to parameter.
 * Every "Sign in" link in the theme and in core flows now lands on the
 * themed page. Falls back to the native URL while the page is missing.
 */
add_filter( 'login_url', function ( string $login_url, string $redirect ) {
	$url = scholaris_auth_url( 'login' );
	if ( ! $url ) {
		return $login_url;
	}
	return $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url ) : $url;
}, 10, 2 );

add_filter( 'register_url', function ( string $register_url ) {
	return scholaris_auth_url( 'register' ) ?: $register_url;
} );

add_filter( 'lostpassword_url', function ( string $lostpassword_url ) {
	return scholaris_auth_url( 'lostpassword' ) ?: $lostpassword_url;
} );

/**
 * Where someone who can edit lands after signing in.
 *
 * Filterable so the plugin that owns the console supplies the destination
 * and the theme does not have to know the page exists — same
 * resolver-with-honest-fallback shape as scholaris_progress_url(). With the
 * library plugin off, this stays plain wp-admin: a worse landing, but a
 * working one.
 */
function scholaris_admin_home_url(): string {
	return (string) apply_filters( 'scholaris_admin_home_url', admin_url() );
}

/**
 * Send staff to their console instead of the student progress page.
 *
 * TWO THINGS HERE ARE EASY TO GET WRONG.
 *
 * 1. It keys on CAPABILITY, never on a username, and never on the requested
 *    URL being admin_url(). page-templates/auth-signin.php emits a hidden
 *    redirect_to on EVERY sign-in through /sign-in/, set to the progress
 *    page — so the "only override when the request is bare wp-admin" version
 *    of this filter never fires even once. Capability also works on every
 *    route into login, not just the themed form.
 *
 * 2. An explicitly requested destination WINS. `login_redirect` also fires
 *    when someone was sent to sign in from a specific page — a gated
 *    material emits wp_login_url( get_permalink() ) for exactly that — so
 *    keying on capability alone would mean an administrator clicking "Sign
 *    in to watch this lecture" silently landing on the console instead of
 *    the lecture. Only the three DEFAULT destinations are overridden.
 *
 * Students are untouched: `student` and `subscriber` lack edit_posts, while
 * `tutor_instructor` and `editor` hold it, so a second lecturer works on
 * their first day without anyone remembering this decision.
 *
 * Known edge, accepted and recorded so it is not later diagnosed as a bug:
 * home_url('/') is in the defaults, so a lecturer who explicitly asks for
 * the homepage gets the console. That is only reachable when the /progress/
 * page is missing, which is scholaris_progress_url()'s own fallback.
 *
 * @param string           $to        Where WordPress means to send them.
 * @param string           $requested What was asked for.
 * @param WP_User|WP_Error $user      The user, or an error.
 * @return string
 */
add_filter( 'login_redirect', function ( $to, $requested, $user ) {
	if ( ! $user instanceof WP_User || ! user_can( $user, 'edit_posts' ) ) {
		return $to;
	}

	$defaults = array_map( 'untrailingslashit', array(
		scholaris_progress_url(),
		admin_url(),
		home_url( '/' ),
	) );

	if ( ! in_array( untrailingslashit( (string) $to ), $defaults, true ) ) {
		return $to;
	}

	return scholaris_admin_home_url();
}, 10, 3 );

/**
 * Apply the auth templates by slug even when nobody assigned them in
 * the editor — the pages created by setup.sh work with zero configuration.
 */
add_filter( 'template_include', function ( string $template ) {
	foreach ( scholaris_auth_pages() as $page ) {
		if ( is_page( $page['slug'] ) ) {
			$located = locate_template( $page['template'] );
			// A template chosen explicitly in the editor wins.
			if ( $located && get_page_template_slug() === '' ) {
				return $located;
			}
		}
	}
	return $template;
} );

/**
 * Signed-in users have no business on the auth pages.
 */
add_action( 'template_redirect', function () {
	if ( ! is_user_logged_in() ) {
		return;
	}
	foreach ( scholaris_auth_pages() as $page ) {
		if ( is_page( $page['slug'] ) ) {
			wp_safe_redirect( scholaris_progress_url() );
			exit;
		}
	}
} );

/*
 * Failed and empty sign-ins are bounced back here by inc/auth-flow.php
 * (wp_login_failed + authenticate hooks), which distinguishes wrong-password
 * from no-such-account, throttles repeated failures, and flashes the typed
 * identifier so the field refills. This file only renders the outcome.
 */

/**
 * A typed value restored after a validation bounce, '' when there is none.
 *
 * The back end (inc/auth-flow.php) stores what the student typed in a short
 * transient and puts only the random ?sl_form= token in the URL. Read once
 * per request, then burned. Keys: login, username, email, name.
 */
function scholaris_auth_old( string $field ): string {
	static $flash = null;

	if ( null === $flash ) {
		$flash = array();
		$token = isset( $_GET['sl_form'] ) ? preg_replace( '/[^a-zA-Z0-9]/', '', (string) wp_unslash( $_GET['sl_form'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only refill.

		if ( '' !== $token ) {
			$data = get_transient( 'sl_authflash_' . $token );
			delete_transient( 'sl_authflash_' . $token );
			$flash = is_array( $data ) ? $data : array();
		}
	}

	return isset( $flash[ $field ] ) ? (string) $flash[ $field ] : '';
}

/**
 * Turn the query flags used across the auth flow into notices.
 * Templates call this once, above their form.
 */
function scholaris_auth_notices(): void {
	$notices = array();

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags.
	if ( isset( $_GET['login'] ) ) {
		$flag = sanitize_key( $_GET['login'] );
		if ( 'failed' === $flag ) {
			$notices[] = array( 'error', sprintf(
				/* translators: %s: password reset URL. */
				__( 'That password is not right for this account. Try again, or <a href="%s">reset it</a>.', 'scholaris' ),
				esc_url( wp_lostpassword_url() )
			) );
		} elseif ( 'nouser' === $flag ) {
			$notices[] = array( 'error', get_option( 'users_can_register' )
				? sprintf(
					/* translators: %s: registration URL. */
					__( 'We could not find an account with that e-mail or username. Check the spelling, or <a href="%s">create an account</a>.', 'scholaris' ),
					esc_url( wp_registration_url() )
				)
				: __( 'We could not find an account with that e-mail or username. Check the spelling, or ask your course administrator.', 'scholaris' ) );
		} elseif ( 'empty' === $flag ) {
			$notices[] = array( 'error', __( 'Please fill in both your username and your password.', 'scholaris' ) );
		} elseif ( 'throttled' === $flag ) {
			$notices[] = array( 'error', __( 'Too many sign-in attempts from this connection. Please wait ten minutes and try again.', 'scholaris' ) );
		}
	}
	if ( isset( $_GET['loggedout'] ) ) {
		$notices[] = array( 'success', __( 'You have been signed out. See you next study session.', 'scholaris' ) );
	}
	if ( isset( $_GET['registration'] ) && 'disabled' === sanitize_key( $_GET['registration'] ) ) {
		$notices[] = array( 'error', __( 'Registration is currently closed. Ask your course administrator for an account.', 'scholaris' ) );
	}
	if ( isset( $_GET['checkemail'] ) ) {
		$flag = sanitize_key( $_GET['checkemail'] );
		if ( 'confirm' === $flag ) {
			$notices[] = array( 'info', __( 'Check your e-mail for a link to reset your password.', 'scholaris' ) );
		} elseif ( 'registered' === $flag ) {
			$notices[] = array( 'success', __( 'Account created. Check your e-mail for a link to set your password.', 'scholaris' ) );
		}
	}
	if ( isset( $_GET['password'] ) && 'changed' === sanitize_key( $_GET['password'] ) ) {
		$notices[] = array( 'success', __( 'Your password has been changed. You can sign in with it now.', 'scholaris' ) );
	}

	// Registration flags bounced back by inc/auth-flow.php (back end).
	if ( isset( $_GET['register'] ) ) {
		$flag = sanitize_key( $_GET['register'] );

		$register_messages = array(
			'username_exists'   => sprintf(
				/* translators: %s: sign-in URL. */
				__( 'That username is taken — try another, or <a href="%s">sign in instead</a>.', 'scholaris' ),
				esc_url( wp_login_url() )
			),
			'email_exists'      => sprintf(
				/* translators: %s: sign-in URL. */
				__( 'An account with that e-mail address already exists — <a href="%s">sign in instead</a>.', 'scholaris' ),
				esc_url( wp_login_url() )
			),
			'username'          => __( 'Please choose a valid username using letters and numbers.', 'scholaris' ),
			'email'             => __( 'That e-mail address does not look valid.', 'scholaris' ),
			'name'              => __( 'Please tell us your name.', 'scholaris' ),
			'password'          => __( 'Please choose a password.', 'scholaris' ),
			'password_short'    => __( 'Passwords need at least 8 characters.', 'scholaris' ),
			'password_mismatch' => __( 'The two passwords do not match. Please type them again.', 'scholaris' ),
			'throttled'         => __( 'Too many signups from this connection. Please wait a while and try again.', 'scholaris' ),
			'generic'           => __( 'Something went wrong — please try again.', 'scholaris' ),
		);

		$notices[] = array( 'error', $register_messages[ $flag ] ?? $register_messages['generic'] );
	}

	// Lost-password flags bounced back by inc/auth-flow.php (back end).
	if ( isset( $_GET['lostpw'] ) ) {
		$flag = sanitize_key( $_GET['lostpw'] );

		if ( 'throttled' === $flag ) {
			$notices[] = array( 'error', __( 'Too many reset requests. Please wait a while and try again.', 'scholaris' ) );
		} else {
			$notices[] = array( 'error', get_option( 'users_can_register' )
				? sprintf(
					/* translators: %s: registration URL. */
					__( 'We could not find an account with those details. Check the spelling, or <a href="%s">create a new account</a>.', 'scholaris' ),
					esc_url( wp_registration_url() )
				)
				: __( 'We could not find an account with those details. Please check the spelling.', 'scholaris' ) );
		}
	}
	// phpcs:enable

	foreach ( $notices as $notice ) {
		printf(
			'<p class="notice notice--%1$s" role="status">%2$s</p>',
			esc_attr( $notice[0] ),
			wp_kses( $notice[1], array( 'a' => array( 'href' => array() ) ) )
		);
	}
}

/* --------------------------------------------------------------------------
 * Branding for the native wp-login.php screens.
 *
 * Interim core screens still exist (validation errors on register, the
 * password-reset form reached from the e-mail link, plugin 2FA pages), so
 * they get the same paper-and-laurel treatment. Self-contained on purpose:
 * wp-login.php does not load the theme stylesheet.
 * ------------------------------------------------------------------------ */

add_action( 'login_enqueue_scripts', function () {
	wp_enqueue_style(
		'scholaris-fonts',
		'https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,500..700;1,9..144,500..700&family=Inter:wght@400;500;600;700&family=Spline+Sans+Mono:wght@500;600&display=swap',
		array(),
		null
	);
	?>
	<style>
	:root {
		--brand: 161 45% 25%; --brand-strong: 162 50% 18%; --accent: 33 58% 42%;
		--bg: 45 38% 97%; --surface: 45 50% 99%; --border: 44 18% 85%;
		--border-strong: 44 13% 72%; --text: 210 26% 14%; --muted: 213 12% 38%;
		--on-brand: 43 38% 96%;
	}
	body.login {
		font-family: "Inter", system-ui, sans-serif;
		background: hsl(var(--bg));
		color: hsl(var(--text));
	}
	.login h1 a {
		background: none; text-indent: 0; width: auto; height: auto;
		font-family: "Fraunces", Georgia, serif; font-weight: 600;
		font-size: 2rem; letter-spacing: -0.01em; color: hsl(var(--text));
	}
	.login form {
		background: hsl(var(--surface));
		border: 1px solid hsl(var(--border));
		border-radius: 14px;
		box-shadow: 0 2px 4px hsl(30 22% 12% / 0.05), 0 8px 20px hsl(30 22% 12% / 0.08);
	}
	.login label { color: hsl(var(--text)); font-weight: 600; font-size: 0.85rem; }
	.login input[type="text"], .login input[type="password"], .login input[type="email"] {
		border: 1px solid hsl(var(--border-strong)); border-radius: 8px;
		background: hsl(var(--surface)); font-size: 0.95rem;
	}
	.login input[type="text"]:focus, .login input[type="password"]:focus, .login input[type="email"]:focus {
		border-color: hsl(var(--accent));
		box-shadow: 0 0 0 3px hsl(var(--accent) / 0.3);
	}
	.login .button-primary {
		background: hsl(var(--brand)); border: 0; border-radius: 8px;
		color: hsl(var(--on-brand)); font-weight: 600; text-shadow: none;
	}
	.login .button-primary:hover, .login .button-primary:focus { background: hsl(var(--brand-strong)); }
	.login #backtoblog a, .login #nav a { color: hsl(var(--muted)); }
	.login #backtoblog a:hover, .login #nav a:hover { color: hsl(var(--brand)); }
	.login .message, .login .success, .login #login_error {
		border-left-width: 3px; border-radius: 8px;
	}
	.login .message, .login .success { border-left-color: hsl(var(--accent)); }
	.login input[type="checkbox"] { accent-color: hsl(var(--brand)); }
	</style>
	<?php
} );

add_filter( 'login_headerurl', fn() => home_url( '/' ) );
add_filter( 'login_headertext', fn() => get_bloginfo( 'name' ) );
