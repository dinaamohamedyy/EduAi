<?php
/**
 * Mint a real browser-equivalent session for a user, for harnesses that must
 * talk to wp-admin over HTTP.
 *
 *   docker compose --profile tools run --rm cli \
 *     wp eval-file /scripts/mint-cookies.php <user_login> --allow-root
 *
 * Prints shell-parseable KEY=VALUE lines and nothing else on stdout.
 *
 * WHY THIS EXISTS, AND WHY BOTH COOKIES.
 *
 * wp-cli has no session, so wp_create_nonce() and wp_generate_auth_cookie()
 * called without a token mint credentials that verify against nobody. The fix
 * is to create a session token explicitly and derive everything from it:
 *
 *   wordpress_logged_in_<hash>   path /          front end, is_user_logged_in()
 *   wordpress_<hash>             path /wp-admin  what admin resolves against
 *
 * A single logged-in cookie is enough for every front-end check, which is the
 * trap: front-end-only harnesses keep working with it and admin screens come
 * back as though they were unconditionally blocked, when they are auth-gated.
 * Front-end lost an hour to exactly that, and concluded /wp-admin/ was closed.
 *
 * The nonce is minted AFTER the token is bound into the session, because a
 * WordPress nonce mixes in the session token: a nonce created before, or
 * without, the token will not validate against the cookies printed here.
 *
 * @package Scholaris
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run me through wp-cli, not php.\n" );
	exit( 1 );
}

$login = isset( $args[0] ) ? (string) $args[0] : '';

if ( '' === $login ) {
	fwrite( STDERR, "usage: wp eval-file /scripts/mint-cookies.php <user_login>\n" );
	exit( 1 );
}

$user = get_user_by( 'login', $login );

if ( ! $user ) {
	fwrite( STDERR, "no such user: $login\n" );
	exit( 1 );
}

$uid = (int) $user->ID;

/*
 * One hour, and mint per run rather than reusing a saved file. When the token
 * expires the nonce stops verifying and the handler answers wp_die( 403,
 * 'That link has expired' ) — which is indistinguishable from the gate refusing
 * you, and sends the reader off to debug access control. Cost me a confusing
 * ten minutes with an env file an hour old.
 */
$exp = time() + 3600;

/* Bind a real session token first — everything below derives from it. */
$token = WP_Session_Tokens::get_instance( $uid )->create( $exp );

/*
 * wp_set_current_user() before wp_create_nonce(): the nonce mixes in the user
 * id as well as the token, and eval-file runs as nobody by default. Without
 * this the nonce is minted for user 0 and 403s as the wrong user, which looks
 * identical to a permissions failure.
 */
wp_set_current_user( $uid );
$GLOBALS['wp_session_token_for_nonce'] = $token;

/*
 * wp_get_session_token() reads the logged-in cookie, which does not exist in
 * this process, so the nonce would be minted against an EMPTY token while the
 * cookies carry a real one — the two would never agree. Filtering the cookie
 * value that wp_get_session_token() parses is what makes them agree.
 */
$logged_in = wp_generate_auth_cookie( $uid, $exp, 'logged_in', $token );

/*
 * BOTH auth schemes, emitted unconditionally. This is the one thing in this
 * file that reproduces, if got wrong, exactly the bug the file exists to stop.
 *
 * Over http WordPress resolves wp-admin against AUTH_COOKIE (wordpress_<hash>);
 * over https it uses SECURE_AUTH_COOKIE (wordpress_sec_<hash>), and the two are
 * signed with different salts, so one is not a re-spelling of the other. Mint
 * only the plain one and point a harness at https://…/wp-admin/ and you have
 * minted a cookie admin never reads. The symptom is "admin is unconditionally
 * blocked while the front end works" — the false conclusion this helper was
 * written to prevent, produced by the helper.
 *
 * is_ssl() cannot decide it here: wp-cli has no $_SERVER['HTTPS'], so it is
 * always false, and the site URL is http on this stack while the public tunnel
 * is https — the same install is reached both ways. Rather than guess from a
 * scheme that is right locally and wrong through the tunnel, emit both and let
 * the caller send both; WordPress reads whichever matches the request it got,
 * and the unused one is inert.
 */
$auth        = wp_generate_auth_cookie( $uid, $exp, 'auth', $token );
$secure_auth = wp_generate_auth_cookie( $uid, $exp, 'secure_auth', $token );

$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in;

printf( "UID=%d\n", $uid );
printf( "LOGGED_IN_COOKIE_NAME=%s\n", LOGGED_IN_COOKIE );
printf( "AUTH_COOKIE_NAME=%s\n", AUTH_COOKIE );
printf( "SECURE_AUTH_COOKIE_NAME=%s\n", SECURE_AUTH_COOKIE );
printf( "LOGGED_IN_COOKIE=%s\n", $logged_in );
printf( "AUTH_COOKIE=%s\n", $auth );
printf( "SECURE_AUTH_COOKIE=%s\n", $secure_auth );

/* Send all three over https, the first two over http. Emitted ready-made so a
 * caller does not have to rebuild it and get the https case wrong again. */
printf(
	"COOKIE_HEADER=%s\n",
	LOGGED_IN_COOKIE . '=' . $logged_in . '; ' .
	AUTH_COOKIE . '=' . $auth . '; ' .
	SECURE_AUTH_COOKIE . '=' . $secure_auth
);

printf( "ADMIN_URL=%s\n", untrailingslashit( admin_url() ) );
printf( "SITE=%s\n", untrailingslashit( home_url() ) );

/*
 * Any extra argument is a nonce action to mint for this same session. Emitted
 * as NONCE_<ACTION> with non-word characters folded to underscore, so
 * `sl_stream_111` becomes NONCE_SL_STREAM_111 and stays shell-parseable.
 *
 * Minted here rather than by the caller for one reason: a nonce is only valid
 * for the user and session token it was created under. A caller that mints its
 * own gets a 403 that is indistinguishable from a genuine permissions failure,
 * which is the failure mode this whole file exists to remove.
 */
$actions = array_slice( (array) $args, 1 );
$actions[] = 'wp_rest';

foreach ( $actions as $action ) {
	printf(
		"NONCE_%s=%s\n",
		strtoupper( preg_replace( '/[^A-Za-z0-9]+/', '_', (string) $action ) ),
		wp_create_nonce( (string) $action )
	);
}
