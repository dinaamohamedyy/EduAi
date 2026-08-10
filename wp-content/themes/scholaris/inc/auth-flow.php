<?php
/**
 * Server side of the themed auth flow — the back-end half of inc/auth.php.
 *
 * The templates in page-templates/auth-*.php post to the native WordPress
 * endpoints and leave `register_form` / `lostpassword_form` as extension
 * points. This file plugs into those seams:
 *
 *  - registration collects a full name and a password (chosen at signup,
 *    so no "check your e-mail for a link" dead end on hosts without mail),
 *  - new students are signed in immediately and land on their progress page,
 *  - validation failures bounce back to the themed pages as ?register=…
 *    and ?lostpw=… flags, which scholaris_auth_notices() renders,
 *  - a honeypot and per-IP rate limits keep drive-by bots off the forms,
 *  - the auth pages are kept out of search indexes.
 *
 * The full seam contract is documented in docs/05-frontend-handoff.md.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

/* --------------------------------------------------------------------------
 * Small shared pieces
 * ------------------------------------------------------------------------ */

/**
 * True when the current POST came from one of our themed auth pages.
 *
 * The forms post a hidden sl_from marker and that is checked first: Referer is
 * missing often enough in the real world (privacy modes, a Referrer-Policy set
 * by a security plugin or CDN, some corporate proxies) that relying on the
 * header alone strands students on the naked wp-login.php screen and skips the
 * auto sign-in after signup. The header stays as the fallback so forms cached
 * from an older deploy keep working.
 *
 * Neither signal is trustworthy input — both are attacker-supplied. This only
 * decides which *page* renders the outcome, never whether the outcome is
 * allowed; the actual checks live in core and in the validators below.
 *
 * @param string $which Key in scholaris_auth_pages(): login|register|lostpassword.
 */
function scholaris_authflow_from_page( string $which ): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- native endpoint, routing hint only.
	$marker = isset( $_POST['sl_from'] ) ? sanitize_key( wp_unslash( $_POST['sl_from'] ) ) : '';

	if ( $marker ) {
		return $marker === $which;
	}

	$page = scholaris_auth_url( $which );
	$ref  = wp_get_referer();

	return $page && $ref && str_starts_with( $ref, strtok( $page, '?' ) );
}

/**
 * The client IP the rate limits key on.
 *
 * Behind Cloudflare, a load balancer or any reverse proxy, REMOTE_ADDR is the
 * proxy — every visitor would share one bucket and the per-IP limits would
 * throttle the whole site (5 signups/hour campus-wide). Forwarded headers fix
 * that but are client-controlled, so one is only honoured when the deployment
 * names it explicitly in wp-config.php:
 *
 *     define( 'SCHOLARIS_CLIENT_IP_HEADER', 'HTTP_CF_CONNECTING_IP' );  // Cloudflare
 *     define( 'SCHOLARIS_CLIENT_IP_HEADER', 'HTTP_X_FORWARDED_FOR' );   // one trusted proxy hop
 *
 * (or via the scholaris_client_ip_header filter). For list-valued headers the
 * right-most entry wins — that is the one your own proxy appended; the
 * left-most is whatever the client claimed. Anything that does not validate
 * as an IP falls back to REMOTE_ADDR. See docs/03-hosting-deployment.md.
 */
function scholaris_authflow_client_ip(): string {
	$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	$header = defined( 'SCHOLARIS_CLIENT_IP_HEADER' ) ? SCHOLARIS_CLIENT_IP_HEADER : '';

	/**
	 * Filters the $_SERVER key of the one forwarded header this host trusts.
	 *
	 * @param string $header e.g. 'HTTP_CF_CONNECTING_IP'; '' trusts none.
	 */
	$header = (string) apply_filters( 'scholaris_client_ip_header', $header );

	if ( '' === $header || empty( $_SERVER[ $header ] ) ) {
		return $remote;
	}

	$parts     = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
	$candidate = trim( end( $parts ) );

	return filter_var( $candidate, FILTER_VALIDATE_IP ) ? $candidate : $remote;
}

/**
 * Sliding per-IP counter. True while this IP is still under the limit;
 * counting a request is the caller's decision (bump).
 *
 * @param string $bucket Short bucket name, e.g. 'register'.
 * @param int    $limit  Allowed requests per window.
 * @param int    $window Window length in seconds.
 */
function scholaris_authflow_within_limit( string $bucket, int $limit, int $window ): bool {
	$key   = 'sl_rl_' . $bucket . '_' . md5( scholaris_authflow_client_ip() );
	$count = (int) get_transient( $key );

	if ( $count >= $limit ) {
		return false;
	}

	set_transient( $key, $count + 1, $window );
	return true;
}

/**
 * Stash what the student typed for one redirect, so a validation bounce does
 * not empty the form. Values live in a short server-side transient; only the
 * random token travels in the URL. scholaris_auth_old() (inc/auth.php) reads
 * and burns it.
 *
 * @param array $data Field => typed value.
 * @return string Token for the sl_form query arg.
 */
function scholaris_authflow_flash_set( array $data ): string {
	$token = wp_generate_password( 12, false, false );
	set_transient( 'sl_authflash_' . $token, $data, 5 * MINUTE_IN_SECONDS );
	return $token;
}

/**
 * Redirect back to a themed auth page with a notice flag, ending the request.
 *
 * @param string $which Auth page key.
 * @param array  $args  Query args carrying the notice flag(s).
 * @param array  $keep  Typed values to restore into the form after the bounce.
 */
function scholaris_authflow_bounce( string $which, array $args, array $keep = array() ): void {
	$url = scholaris_auth_url( $which );

	if ( ! $url ) {
		return; // Page missing: let the native screen handle it.
	}

	$keep = array_filter( $keep, static fn( $v ): bool => '' !== $v );

	if ( $keep ) {
		$args['sl_form'] = scholaris_authflow_flash_set( $keep );
	}

	// Keep the intended destination alive across the retry.
	$redirect_to = isset( $_POST['redirect_to'] ) ? wp_validate_redirect( wp_unslash( $_POST['redirect_to'] ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_validate_redirect is the sanitiser.
	if ( '' !== $redirect_to ) {
		$args['redirect_to'] = rawurlencode( $redirect_to );
	}

	wp_safe_redirect( add_query_arg( $args, $url ) );
	exit;
}

/**
 * Keep the auth pages out of search results.
 */
add_filter( 'wp_robots', function ( array $robots ): array {
	foreach ( scholaris_auth_pages() as $page ) {
		if ( is_page( $page['slug'] ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			break;
		}
	}
	return $robots;
} );

/* --------------------------------------------------------------------------
 * Sign in: failures come back saying what actually happened
 *
 * Deliberately more specific than the stock vague message: "no account with
 * that e-mail" and "wrong password" are different problems with different
 * fixes, and a student staring at the vague version usually retypes a
 * password that was never the issue. Register and reset already reveal
 * whether an account exists, so this hides nothing extra; the failed-attempt
 * throttle below plus Wordfence are the brute-force controls.
 * ------------------------------------------------------------------------ */

/**
 * Failed sign-in from the themed page: bounce back with a specific flag,
 * the typed identifier (flashed — it never enters the URL) and the original
 * redirect_to, instead of stranding the student on native wp-login.php.
 *
 * @param string        $username What was typed.
 * @param WP_Error|null $error    Why core rejected it (second arg since WP 5.4).
 */
function scholaris_authflow_login_failed( $username, $error = null ): void {
	if ( ! scholaris_authflow_from_page( 'login' ) ) {
		return; // Native or third-party sign-in: keep native behaviour.
	}

	if ( ! scholaris_authflow_within_limit( 'loginfail', 8, 10 * MINUTE_IN_SECONDS ) ) {
		scholaris_authflow_bounce( 'login', array( 'login' => 'throttled' ) );
	}

	$code = ( $error instanceof WP_Error ) ? (string) $error->get_error_code() : '';
	$flag = in_array( $code, array( 'invalid_username', 'invalid_email' ), true ) ? 'nouser' : 'failed';

	scholaris_authflow_bounce(
		'login',
		array( 'login' => $flag ),
		array( 'login' => sanitize_text_field( (string) $username ) )
	);
}
add_action( 'wp_login_failed', 'scholaris_authflow_login_failed', 10, 2 );

/**
 * Empty credentials never reach wp_login_failed — catch them on the
 * authenticate filter, after core has had its look.
 *
 * @param WP_User|WP_Error|null $user     Auth result so far.
 * @param string                $username Typed username.
 * @param string                $password Typed password.
 * @return WP_User|WP_Error|null
 */
function scholaris_authflow_login_empty( $user, $username = '', $password = '' ) {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ( $username && $password ) ) {
		return $user;
	}

	if ( isset( $_POST['log'] ) && scholaris_authflow_from_page( 'login' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- native endpoint.
		scholaris_authflow_bounce(
			'login',
			array( 'login' => 'empty' ),
			array( 'login' => sanitize_text_field( (string) $username ) )
		);
	}

	return $user;
}
add_filter( 'authenticate', 'scholaris_authflow_login_empty', 30, 3 );

/* --------------------------------------------------------------------------
 * Registration: extra fields
 *
 * Rendered into the themed form at its do_action( 'register_form' ) slot —
 * and, by the same hook, into the native fallback screen, so both stay
 * consistent. Markup mirrors the .field blocks the template uses.
 * ------------------------------------------------------------------------ */

/**
 * Full name, password + confirmation, and a honeypot.
 */
function scholaris_authflow_register_fields(): void {
	?>
	<?php
	// Refill after an error: POST survives a native re-render, the flash
	// survives a themed-page bounce (see scholaris_auth_old in inc/auth.php).
	$sl_name = isset( $_POST['sl_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sl_name'] ) ) : scholaris_auth_old( 'name' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- display-only refill.
	?>
	<div class="field">
		<label for="sl_name"><?php esc_html_e( 'Full name', 'scholaris' ); ?></label>
		<input type="text" name="sl_name" id="sl_name" autocomplete="name" required maxlength="90"
			value="<?php echo esc_attr( $sl_name ); ?>">
	</div>
	<div class="field">
		<label for="sl_pass1"><?php esc_html_e( 'Password', 'scholaris' ); ?></label>
		<input type="password" name="sl_pass1" id="sl_pass1" autocomplete="new-password" required
			minlength="8" aria-describedby="sl_pass_hint">
		<p class="auth__fineprint" id="sl_pass_hint"><?php esc_html_e( 'At least 8 characters.', 'scholaris' ); ?></p>
	</div>
	<div class="field">
		<label for="sl_pass2"><?php esc_html_e( 'Repeat password', 'scholaris' ); ?></label>
		<input type="password" name="sl_pass2" id="sl_pass2" autocomplete="new-password" required minlength="8">
	</div>
	<div style="position:absolute;left:-9999px;top:auto;height:1px;overflow:hidden" aria-hidden="true">
		<label for="sl_website"><?php esc_html_e( 'Leave this field empty', 'scholaris' ); ?></label>
		<input type="text" name="sl_website" id="sl_website" value="" tabindex="-1" autocomplete="off">
	</div>
	<?php
}
add_action( 'register_form', 'scholaris_authflow_register_fields' );

/* --------------------------------------------------------------------------
 * Registration: validation
 * ------------------------------------------------------------------------ */

/**
 * Validate our extra fields on top of core's username/e-mail checks.
 *
 * Core has no nonce on registration; the honeypot, the rate limit and the
 * referrer gate on the bounce below are the compensating controls.
 *
 * @param WP_Error $errors Core validation so far.
 */
function scholaris_authflow_register_validate( WP_Error $errors ): WP_Error {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- native endpoint, see above.
	if ( ! empty( $_POST['sl_website'] ) ) {
		$errors->add( 'scholaris_bot', __( 'Something went wrong — please try again.', 'scholaris' ) );
		return $errors; // No point validating a bot's input further.
	}

	if ( ! scholaris_authflow_within_limit( 'register', 5, HOUR_IN_SECONDS ) ) {
		$errors->add( 'scholaris_throttled', __( 'Too many signups from this connection. Please wait a while and try again.', 'scholaris' ) );
	}

	$name  = isset( $_POST['sl_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['sl_name'] ) ) ) : '';
	$pass1 = isset( $_POST['sl_pass1'] ) ? (string) wp_unslash( $_POST['sl_pass1'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords must not be altered.
	$pass2 = isset( $_POST['sl_pass2'] ) ? (string) wp_unslash( $_POST['sl_pass2'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords must not be altered.

	if ( '' === $name ) {
		$errors->add( 'scholaris_name', __( 'Please tell us your name.', 'scholaris' ) );
	}

	if ( '' === $pass1 ) {
		$errors->add( 'scholaris_password', __( 'Please choose a password.', 'scholaris' ) );
	} elseif ( strlen( $pass1 ) < 8 ) {
		$errors->add( 'scholaris_password_short', __( 'Passwords need at least 8 characters.', 'scholaris' ) );
	} elseif ( $pass1 !== $pass2 ) {
		$errors->add( 'scholaris_password_mismatch', __( 'The two passwords do not match.', 'scholaris' ) );
	}
	// phpcs:enable

	return $errors;
}
add_filter( 'registration_errors', 'scholaris_authflow_register_validate', 20 );

/**
 * When validation fails and the student came from the themed page, send them
 * back there with a flag instead of the native wp-login.php screen.
 * Runs last so every other plugin's checks have had their say.
 *
 * @param WP_Error $errors Everything collected above.
 */
function scholaris_authflow_register_bounce( WP_Error $errors ): WP_Error {
	if ( ! $errors->has_errors() || ! scholaris_authflow_from_page( 'register' ) ) {
		return $errors;
	}

	$code = (string) $errors->get_error_code();

	$flags = array(
		'username_exists'             => 'username_exists',
		'invalid_username'            => 'username',
		'empty_username'              => 'username',
		'email_exists'                => 'email_exists',
		'invalid_email'               => 'email',
		'empty_email'                 => 'email',
		'scholaris_name'              => 'name',
		'scholaris_password'          => 'password',
		'scholaris_password_short'    => 'password_short',
		'scholaris_password_mismatch' => 'password_mismatch',
		'scholaris_throttled'         => 'throttled',
	);

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- native endpoint, compensating controls above.
	$keep = array(
		'username' => isset( $_POST['user_login'] ) ? sanitize_user( wp_unslash( $_POST['user_login'] ) ) : '',
		'email'    => isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '',
		'name'     => isset( $_POST['sl_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sl_name'] ) ) : '',
	);
	// phpcs:enable

	scholaris_authflow_bounce( 'register', array( 'register' => $flags[ $code ] ?? 'generic' ), $keep );

	return $errors; // Unreachable when the bounce redirected; satisfies the filter contract otherwise.
}
add_filter( 'registration_errors', 'scholaris_authflow_register_bounce', PHP_INT_MAX );

/* --------------------------------------------------------------------------
 * Registration: after the account exists
 * ------------------------------------------------------------------------ */

/**
 * Store the profile details our form collected: real name, chosen password.
 * Fires inside wp_insert_user(), before notifications go out.
 *
 * @param int $user_id The new account.
 */
function scholaris_authflow_enrich_user( int $user_id ): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- native endpoint, compensating controls above.
	if ( ! isset( $_POST['sl_pass1'] ) && ! isset( $_POST['sl_name'] ) ) {
		return; // Some other registration path (wp-admin, WP-CLI, Tutor).
	}

	$name = isset( $_POST['sl_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['sl_name'] ) ) ) : '';

	if ( '' !== $name ) {
		$first = $name;
		$last  = '';
		$space = strrpos( $name, ' ' );

		if ( false !== $space ) {
			$first = substr( $name, 0, $space );
			$last  = substr( $name, $space + 1 );
		}

		wp_update_user( array(
			'ID'           => $user_id,
			'display_name' => $name,
			'first_name'   => $first,
			'last_name'    => $last,
		) );
	}

	$pass = isset( $_POST['sl_pass1'] ) ? (string) wp_unslash( $_POST['sl_pass1'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords must not be altered.

	if ( strlen( $pass ) >= 8 ) {
		wp_set_password( $pass, $user_id );
		update_user_meta( $user_id, 'sl_password_at_signup', 1 );
	}
	// phpcs:enable
}
add_action( 'user_register', 'scholaris_authflow_enrich_user' );

/**
 * Notifications: the admin always hears about a new student; the student only
 * gets the "set your password" e-mail when they did NOT choose one at signup.
 */
remove_action( 'register_new_user', 'wp_send_new_user_notifications' );
add_action( 'register_new_user', function ( int $user_id ): void {
	$notify = get_user_meta( $user_id, 'sl_password_at_signup', true ) ? 'admin' : 'both';
	wp_send_new_user_notifications( $user_id, $notify );
} );

/**
 * A student who just chose a password should not be told to go check e-mail.
 * Sign them in and put them on their progress page. Runs late on the success hook
 * and ends the request, so it works the same on every core version.
 *
 * @param int $user_id The new account.
 */
function scholaris_authflow_register_success( int $user_id ): void {
	if ( ! scholaris_authflow_from_page( 'register' ) ) {
		return; // Native or third-party flow: keep native behaviour.
	}

	if ( get_user_meta( $user_id, 'sl_password_at_signup', true ) ) {
		// Core sets this nag right before this hook, assuming the password was
		// generated for the student. Ours was typed by them, so it is wrong.
		delete_user_option( $user_id, 'default_password_nag', true );

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );

		/**
		 * Fires when a student finishes signup through the themed page.
		 *
		 * @param int $user_id The new account.
		 */
		do_action( 'scholaris_student_registered', $user_id );

		wp_safe_redirect( add_query_arg( 'welcome', '1', scholaris_progress_url() ) );
		exit;
	}

	// Password e-mail path (e.g. our fields were removed): themed confirmation.
	scholaris_authflow_bounce( 'login', array( 'checkemail' => 'registered' ) );
}
add_action( 'register_new_user', 'scholaris_authflow_register_success', 99 );

/* --------------------------------------------------------------------------
 * Lost password
 * ------------------------------------------------------------------------ */

/**
 * Throttle reset requests and bounce failures back to the themed page.
 *
 * @param WP_Error $errors Validation so far (populated by core before this).
 */
function scholaris_authflow_lostpassword( WP_Error $errors ): void {
	if ( ! scholaris_authflow_from_page( 'lostpassword' ) ) {
		return;
	}

	$keep = array(
		'login' => isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- native endpoint.
	);

	if ( ! scholaris_authflow_within_limit( 'lostpw', 5, HOUR_IN_SECONDS ) ) {
		scholaris_authflow_bounce( 'lostpassword', array( 'lostpw' => 'throttled' ), $keep );
	}

	if ( $errors->has_errors() ) {
		scholaris_authflow_bounce( 'lostpassword', array( 'lostpw' => 'invalid' ), $keep );
	}
}
add_action( 'lostpassword_post', 'scholaris_authflow_lostpassword', 20 );
