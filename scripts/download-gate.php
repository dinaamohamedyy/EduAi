<?php
/**
 * Fixtures for the gated-download test. Run by scripts/download-gate.sh;
 * prints shell-parseable KEY=VALUE lines and nothing else on stdout.
 *
 *   docker compose --profile tools run --rm cli \
 *     wp eval-file /scripts/download-gate.php --allow-root
 *
 * Creates two students, a members-only document and a public one, each with a
 * real file attached, and mints the download nonces **as each user** — which
 * is the whole point: a WordPress nonce is bound to the user id and session,
 * so minting it as somebody else is what makes the "URLs cannot be shared"
 * claim testable rather than assumed.
 *
 * Idempotent: re-running reuses the users and posts it already made.
 *
 * @package Scholaris
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run me through wp-cli, not php.\n" );
	exit( 1 );
}

/**
 * @param string $login Username.
 * @param string $pass  Known password so curl can sign in as them.
 */
function gate_user( string $login, string $pass ): WP_User {
	$user = get_user_by( 'login', $login );

	if ( ! $user ) {
		$id = wp_insert_user( array(
			'user_login' => $login,
			'user_pass'  => $pass,
			'user_email' => $login . '@example.invalid',
			'role'       => get_role( 'student' ) ? 'student' : 'subscriber',
		) );

		if ( is_wp_error( $id ) ) {
			fwrite( STDERR, "could not create $login: " . $id->get_error_message() . "\n" );
			exit( 1 );
		}

		$user = get_user_by( 'id', $id );
	} else {
		wp_set_password( $pass, $user->ID );   // keep the known password valid
		$user = get_user_by( 'id', $user->ID );
	}

	return $user;
}

/**
 * A study_material with a real file behind it, so a success is a real stream
 * of bytes rather than an empty 200.
 *
 * @param string $slug   Post slug, reused on re-runs.
 * @param string $access 'members' or 'public'.
 */
function gate_material( string $slug, string $access ): int {
	$existing = get_posts( array(
		'post_type'      => 'study_material',
		'name'           => $slug,
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );

	$post_id = $existing ? (int) $existing[0] : (int) wp_insert_post( array(
		'post_type'   => 'study_material',
		'post_status' => 'publish',
		'post_title'  => 'Gate test — ' . $access,
		'post_name'   => $slug,
	) );

	update_post_meta( $post_id, '_scholaris_access', $access );

	$file_id = (int) get_post_meta( $post_id, '_scholaris_file_id', true );

	if ( ! $file_id || ! get_attached_file( $file_id ) ) {
		$uploads = wp_upload_dir();
		$path    = trailingslashit( $uploads['path'] ) . $slug . '.txt';
		$body    = "GATE-TEST-PAYLOAD for $slug — if you can read this line, the file was served.\n";

		file_put_contents( $path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$file_id = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'text/plain',
				'post_title'     => $slug,
				'post_status'    => 'inherit',
			),
			$path,
			$post_id
		);

		update_post_meta( $post_id, '_scholaris_file_id', $file_id );
	}

	return $post_id;
}

$pass_a = 'GateTestA-' . wp_hash( 'a' );
$pass_b = 'GateTestB-' . wp_hash( 'b' );

$a = gate_user( 'gate-student-a', $pass_a );
$b = gate_user( 'gate-student-b', $pass_b );

$members = gate_material( 'gate-members', 'members' );
$public  = gate_material( 'gate-public', 'public' );

/* Deliberately no nonces here. wp_create_nonce() mixes in the session token,
 * and wp-cli has no session — so a nonce minted in this process verifies
 * against nobody and every download would 403 for the wrong reason. The shell
 * script scrapes each link off the material page while signed in as that
 * student, which is both correct and how a student actually gets one. */

printf( "SITE=%s\n", untrailingslashit( home_url() ) );

/* Asked for rather than assumed, and note it is not wp_login_url(). Two
 * different URLs are in play on this stack and confusing them silently breaks
 * a harness:
 *
 *   wp_login_url()                          -> /sign-in/   (the theme filters
 *                                              it, so a *person* is sent to the
 *                                              themed page)
 *   site_url( 'wp-login.php', 'login_post' ) -> /login/     (wps-hide-login
 *                                              filters it, and this is where
 *                                              the themed form actually POSTs)
 *
 * Posting credentials to the first just re-renders the page and never signs
 * anyone in. The theme uses the second for its form action, which is why the
 * seam survives wps-hide-login at all. */
printf( "LOGIN_URL=%s\n", site_url( 'wp-login.php', 'login_post' ) );
printf( "USER_A=%s\n", $a->user_login );
printf( "PASS_A=%s\n", $pass_a );
printf( "USER_B=%s\n", $b->user_login );
printf( "PASS_B=%s\n", $pass_b );
printf( "POST_MEMBERS=%d\n", $members );
printf( "POST_PUBLIC=%d\n", $public );
printf( "PERMALINK_MEMBERS=%s\n", get_permalink( $members ) );
printf( "PERMALINK_PUBLIC=%s\n", get_permalink( $public ) );
