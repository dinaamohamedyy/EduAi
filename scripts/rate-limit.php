<?php
/**
 * The assistant's rate limit — the README's "per user per hour, configurable,
 * on by default" — executed against the real REST route.
 *
 *   docker compose --profile tools run --rm cli \
 *     wp eval-file /scripts/rate-limit.php --allow-root
 *
 * Two things are under test, and the second is the one that matters. That the
 * limit refuses at N+1 is the obvious half. That the bucket is **per user**
 * rather than shared is the half that has already bitten this project once:
 * the auth throttles keyed on REMOTE_ADDR alone, which collapses to a single
 * site-wide bucket behind any proxy and would have locked out a whole campus.
 * If the assistant's counter is keyed on anything coarser than the user id,
 * one busy student silences everyone else and the failure looks like "the
 * assistant is down" rather than like a quota.
 *
 * The model call is stubbed at `pre_http_request`, so the route runs for real
 * — permission callback, rate limiter, retrieval, response shape — while
 * nothing leaves the container and no credit is spent.
 *
 * Assertions come from the README's claim and from EduAI_REST's documented
 * behaviour, not from whatever the counter happens to do today.
 *
 * @package Scholaris
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run me through wp-cli, not php.\n" );
	exit( 1 );
}

$GLOBALS['rl_pass']  = 0;
$GLOBALS['rl_fail']  = 0;
$GLOBALS['rl_calls'] = 0;

function check( string $rule, bool $ok, string $detail ): void {
	if ( $ok ) {
		$GLOBALS['rl_pass']++;
		printf( "ok    %s\n", $rule );
		return;
	}
	$GLOBALS['rl_fail']++;
	printf( "FAIL  %s\n        %s\n", $rule, $detail );
}

putenv( 'ANTHROPIC_API_KEY=test-key-not-used' );
putenv( 'GROQ_API_KEY=test-key-not-used' );

/* Stub the model so the route is exercised without a network call. */
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false === strpos( $url, '/v1/' ) ) {
			return $pre;
		}
		/* Counted, so a refused request that still paid the provider is
		 * visible. The stub is free, which is exactly why nothing would
		 * otherwise notice. */
		$GLOBALS['rl_calls']++;

		$format = EduAI_Claude::provider()['format'];
		$text   = 'A stubbed reply; this test is about the counter, not the answer.';

		return array(
			'headers'  => array(),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
			'body'     => 'openai' === $format
				? wp_json_encode( array(
					'model'   => 'stub',
					'choices' => array( array( 'message' => array( 'content' => $text ) ) ),
					'usage'   => array( 'prompt_tokens' => 1, 'completion_tokens' => 1 ),
				) )
				: wp_json_encode( array(
					'model'       => 'stub',
					'content'     => array( array( 'type' => 'text', 'text' => $text ) ),
					'stop_reason' => 'end_turn',
					'usage'       => array( 'input_tokens' => 1, 'output_tokens' => 1 ),
				) ),
		);
	},
	10,
	3
);

/**
 * @param string $login Username.
 */
function rl_user( string $login ): WP_User {
	$user = get_user_by( 'login', $login );

	if ( ! $user ) {
		$id = wp_insert_user( array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => $login . '@example.invalid',
			'role'       => get_role( 'student' ) ? 'student' : 'subscriber',
		) );

		if ( is_wp_error( $id ) ) {
			fwrite( STDERR, "could not create $login: " . $id->get_error_message() . "\n" );
			exit( 1 );
		}

		$user = get_user_by( 'id', $id );
	}

	return $user;
}

$a = rl_user( 'rl-student-a' );
$b = rl_user( 'rl-student-b' );

/* Start both from zero, or a previous run decides the result. */
delete_transient( 'eduai_rl_u' . $a->ID );
delete_transient( 'eduai_rl_u' . $b->ID );

$limit = (int) EduAI_Settings::get( 'rate_limit', 20 );

if ( $limit <= 0 ) {
	printf( "rate limiting is switched off on this site (rate_limit=%d) — nothing to test\n", $limit );
	printf( "set it above zero and re-run; the README says it is on by default\n" );
	exit( 1 );
}

printf( "limit is %d per hour, per user\n\n", $limit );

/**
 * One real chat request as the given user. Returns the HTTP status.
 *
 * @param int    $user_id Who is asking.
 * @param string $message What they asked.
 */
function rl_ask( int $user_id, string $message ): array {
	wp_set_current_user( $user_id );

	$request = new WP_REST_Request( 'POST', '/eduai/v1/chat' );
	$request->set_param( 'message', $message );

	$response = rest_do_request( $request );
	$data     = $response->get_data();

	return array(
		'status' => $response->get_status(),
		'code'   => is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '',
	);
}

/* ---- control: a request has to succeed before a refusal means anything --- */

$first = rl_ask( $a->ID, 'Control request: does the route answer at all?' );

check(
	'control: the first request is answered',
	200 === $first['status'],
	sprintf( 'status %d (%s) — if the route refuses everything, every limit below is fake', $first['status'], $first['code'] )
);

if ( 200 !== $first['status'] ) {
	printf( "\n%d passed, %d failed\n", $GLOBALS['rl_pass'], $GLOBALS['rl_fail'] );
	exit( 1 );
}

/* ---- burn the rest of A's quota ----------------------------------------- */

$refused_early = null;

for ( $i = 2; $i <= $limit; $i++ ) {
	$r = rl_ask( $a->ID, 'Request number ' . $i );
	if ( 200 !== $r['status'] ) {
		$refused_early = array( $i, $r );
		break;
	}
}

check(
	sprintf( 'all %d requests inside the quota are answered', $limit ),
	null === $refused_early,
	$refused_early
		? sprintf( 'request %d was refused with %d (%s)', $refused_early[0], $refused_early[1]['status'], $refused_early[1]['code'] )
		: ''
);

/* ---- and the one over it ------------------------------------------------- */

$calls_before = (int) $GLOBALS['rl_calls'];
$over         = rl_ask( $a->ID, 'One past the limit' );
$calls_after  = (int) $GLOBALS['rl_calls'];

check(
	sprintf( 'request %d is refused', $limit + 1 ),
	429 === $over['status'],
	sprintf( 'status %d (%s) — expected 429', $over['status'], $over['code'] )
);

check(
	'the refusal is the rate limiter, not something else',
	'eduai_rate' === $over['code'],
	'error code was ' . var_export( $over['code'], true )
);

/* ---- three claims this file used to make and never test ------------------ */

/*
 * THE COST. The stub is free, so nothing here would otherwise notice whether a
 * REFUSED request called the provider first. A limiter that spends and then
 * says no does not protect the budget — which, on an install running a free
 * Groq tier because the owner's paid key 401s, is most of what the limiter is
 * for. Cheap to assert and invisible without counting.
 */
check(
	'the refused request did not call the provider',
	$calls_after === $calls_before,
	sprintf(
		'the provider was called %d time(s) for a request that was then refused — the limiter runs after the spend, so the quota protects nothing but the reply',
		$calls_after - $calls_before
	)
);

/*
 * THE WINDOW. The header of this file says it tests the README's "per user per
 * HOUR". It proved per-user and it proved refusal at N+1; nothing in it ever
 * observed time, so a limiter with no expiry — one that locks a student out
 * permanently after their twentieth question — passed every assertion here.
 * "Per hour" was the word doing the most work in the claim and the one word
 * untested.
 *
 * Read from the option WordPress stores the expiry in, rather than inferred
 * from the set_transient() call site, and without waiting an hour to find out.
 */
$rl_timeout = get_option( '_transient_timeout_eduai_rl_u' . $a->ID );
$rl_secs    = false === $rl_timeout ? -1 : (int) $rl_timeout - time();

check(
	'the counter expires in about an hour, rather than never',
	$rl_secs > 3000 && $rl_secs <= 3700,
	false === $rl_timeout
		? 'no expiry is stored at all — the counter never resets, so twenty questions locks a student out for good'
		: sprintf( 'expires in %d seconds (%.2f hours), not the hour the README promises', $rl_secs, $rl_secs / 3600 )
);

/*
 * RETRYING MUST NOT EXTEND THE LOCKOUT. If a refused request still writes the
 * counter it writes a fresh hour with it, and a student who keeps clicking is
 * never let back in — the window slides ahead of them for as long as they keep
 * asking. That is the failure that looks like "the assistant is broken for me
 * specifically" and is unreproducible for anyone who waits quietly.
 *
 * Observable without waiting: if the counter climbs past the limit on refused
 * requests, the transient is being rewritten and its expiry with it.
 */
$rl_at_limit = (int) get_transient( 'eduai_rl_u' . $a->ID );

for ( $rl_i = 0; $rl_i < 3; $rl_i++ ) {
	rl_ask( $a->ID, 'retrying while locked out' );
}

$rl_after_retries = (int) get_transient( 'eduai_rl_u' . $a->ID );

check(
	'retrying while locked out does not push the window further away',
	$rl_after_retries === $rl_at_limit,
	sprintf(
		'the counter went %d -> %d across three refused retries, so each retry rewrites the transient and buys another full hour of lockout',
		$rl_at_limit,
		$rl_after_retries
	)
);

/* ---- the half that matters: is the bucket shared? ------------------------ */

$other = rl_ask( $b->ID, 'A different student, whose own quota is untouched' );

check(
	'a second student is unaffected by the first exhausting their quota',
	200 === $other['status'],
	sprintf(
		'status %d (%s) — the counter is shared, so one student silences everyone',
		$other['status'],
		$other['code']
	)
);

/* And prove that was not luck: B should still have almost a full quota. */
$b_count = (int) get_transient( 'eduai_rl_u' . $b->ID );

check(
	'the two students have separate counters',
	1 === $b_count && (int) get_transient( 'eduai_rl_u' . $a->ID ) >= $limit,
	sprintf(
		'A is at %d, B is at %d — expected A at %d or more and B at 1',
		(int) get_transient( 'eduai_rl_u' . $a->ID ),
		$b_count,
		$limit
	)
);

/* Leave nothing behind: a stuck counter would throttle these accounts for an
 * hour and make the next run's control fail for an unrelated reason. */
delete_transient( 'eduai_rl_u' . $a->ID );
delete_transient( 'eduai_rl_u' . $b->ID );

printf( "\n%d passed, %d failed\n", $GLOBALS['rl_pass'], $GLOBALS['rl_fail'] );

if ( 0 === $GLOBALS['rl_pass'] + $GLOBALS['rl_fail'] ) {
	fwrite( STDERR, "no assertions ran — treating that as a failure\n" );
	exit( 1 );
}

exit( $GLOBALS['rl_fail'] > 0 ? 1 : 0 );
