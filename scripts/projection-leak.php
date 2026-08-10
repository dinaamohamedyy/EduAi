<?php
/**
 * Does the answer key ever reach the browser? — docs/07 §1, tested against the
 * real REST route on a live stack.
 *
 *   docker compose --profile tools run --rm cli \
 *     wp eval-file /scripts/projection-leak.php --allow-root
 *
 * §1 is absolute: `answer_index` and `explanation` on every MCQ, and `expected`
 * on every short, may not appear in any response to the browser before the
 * attempt is submitted. Not a hardening nicety — anything the browser receives
 * is one devtools Network tab away from the student, so a leak here does not
 * degrade the feature, it removes the point of it.
 *
 * This tests the **server** half. The request goes through `rest_do_request`,
 * so the real route, the real permission callback and the real
 * `EduAI_Exams::for_client()` projection all run, and the assertion is made
 * against the JSON that would go on the wire — not against the projection
 * function's return value, which would only prove the function agrees with
 * itself.
 *
 * The client half (`stripForClient`, and the rendered form DOM) is not covered
 * here and cannot be: there is no exam template in the plugin yet. Until the
 * PrepareME tab ships, that layer exists only in design/preview.html and has to
 * be checked in a browser.
 *
 * @package Scholaris
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run me through wp-cli, not php.\n" );
	exit( 1 );
}

$GLOBALS['leak_pass'] = 0;
$GLOBALS['leak_fail'] = 0;

function check( string $rule, bool $ok, string $detail ): void {
	if ( $ok ) {
		$GLOBALS['leak_pass']++;
		printf( "ok    %s\n", $rule );
		return;
	}
	$GLOBALS['leak_fail']++;
	printf( "FAIL  %s\n        %s\n", $rule, $detail );
}

/* A real student, not an administrator: the projection must hold for the role
 * that actually sits the exam, and capability differences are exactly the sort
 * of thing that turns a safe response unsafe. */
$user = get_user_by( 'login', 'leaktest-student' );

if ( ! $user ) {
	$id = wp_insert_user( array(
		'user_login' => 'leaktest-student',
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => 'leaktest-student@example.invalid',
		'role'       => get_role( 'student' ) ? 'student' : 'subscriber',
	) );

	if ( is_wp_error( $id ) ) {
		fwrite( STDERR, 'could not create the test student: ' . $id->get_error_message() . "\n" );
		exit( 1 );
	}

	$user = get_user_by( 'id', $id );
}

wp_set_current_user( $user->ID );
printf( "signed in as %s (%s)\n\n", $user->user_login, implode( ',', $user->roles ) );

/* The fixture is the exam whose answers we know, which is what makes a leak
 * detectable by content and not only by key name: if "DC term" appears in a
 * response, it came from an explanation no matter what it is labelled. */
$fixture = EduAI_Exams::fixture();

if ( ! $fixture ) {
	fwrite( STDERR, "fixtures/exam-sample.json is unreadable\n" );
	exit( 1 );
}

$secrets = array();
foreach ( $fixture['questions'] as $q ) {
	if ( isset( $q['explanation'] ) && '' !== $q['explanation'] ) {
		$secrets[] = array( 'q' . $q['id'] . ' explanation', $q['explanation'] );
	}
	if ( isset( $q['expected'] ) && '' !== $q['expected'] ) {
		$secrets[] = array( 'q' . $q['id'] . ' mark scheme', $q['expected'] );
	}
}

/* ---- control: prove the detector can see a leak before trusting a pass ---
 *
 * Every assertion below is of the form "this string is absent". That shape
 * passes just as happily when the search is broken, the payload is empty, or
 * the probe text is wrong -- so run the same searches against the raw,
 * unprojected exam first, where the answers are definitely present. If the
 * detector cannot find a leak it is looking straight at, nothing after this
 * point means anything. */
$raw_wire = wp_json_encode( $fixture );
$control  = true;

foreach ( array( 'answer_index', 'expected', 'explanation' ) as $field ) {
	$control = $control && false !== strpos( $raw_wire, '"' . $field . '"' );
}

check(
	'control: the detector finds the answers in an unprojected exam',
	$control,
	'the searches did not fire on raw fixture data — every "absent" result below is meaningless'
);

/* ---- the projection, as the browser would receive it -------------------- */

$client = EduAI_Exams::for_client( $fixture );
$wire   = wp_json_encode( $client );

printf( "projection is %d bytes for %d questions\n\n", strlen( $wire ), count( $fixture['questions'] ) );

foreach ( array( 'answer_index', 'expected', 'explanation' ) as $field ) {
	check(
		sprintf( '§1: "%s" is not a key anywhere in the projection', $field ),
		false === strpos( $wire, '"' . $field . '"' ),
		'found "' . $field . '" in the payload the browser would receive'
	);
}

/* Key names are the easy half. A leak that renamed the field, or inlined the
 * text into the question, would pass the check above and still hand over the
 * answers -- so look for the content itself. */
$leaked = array();
foreach ( $secrets as $s ) {
	list( $label, $text ) = $s;
	$probe = trim( mb_substr( $text, 0, 40 ) );
	if ( '' !== $probe && false !== strpos( $wire, $probe ) ) {
		$leaked[] = $label;
	}
}

check(
	sprintf( '§1: none of the %d answer texts appear verbatim', count( $secrets ) ),
	! $leaked,
	'leaked: ' . implode( ', ', $leaked )
);

/* An MCQ still needs its options, or the exam is unusable -- so prove the
 * projection strips the answer without stripping the question. A test that
 * only checks "the secret is gone" passes on an empty response. */
$first_mcq = null;
foreach ( $fixture['questions'] as $q ) {
	if ( 'mcq' === $q['type'] ) {
		$first_mcq = $q;
		break;
	}
}

check(
	'the projection still carries the question text',
	false !== strpos( $wire, mb_substr( $first_mcq['question'], 0, 30 ) ),
	'question text is missing — the projection removed too much'
);
check(
	'the projection still carries the options',
	false !== strpos( $wire, mb_substr( $first_mcq['options'][0], 0, 20 ) ),
	'options are missing — the projection removed too much'
);

/* ---- and the same thing over the real route ----------------------------- */

/* Generation needs a model, and the point here is the route rather than the
 * generator — so the fixture is fed back as the model's own output at
 * pre_http_request. Nothing leaves the container, no credit is spent, and the
 * exam that gets stored carries real answers, which is what makes a leak in
 * the GET below detectable. The key only has to resolve; it is never sent. */
putenv( 'ANTHROPIC_API_KEY=test-key-not-used' );
putenv( 'GROQ_API_KEY=test-key-not-used' );

$generated = wp_json_encode( array(
	'schema_version' => 1,
	'title'          => $fixture['title'],
	'questions'      => $fixture['questions'],
) );

$format = EduAI_Claude::provider()['format'];

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( $generated, $format ) {
		if ( false === strpos( $url, '/v1/' ) ) {
			return $pre;
		}

		return array(
			'headers'  => array(),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
			'body'     => 'openai' === $format
				? wp_json_encode( array(
					'model'   => 'crafted',
					'choices' => array( array( 'message' => array( 'content' => $generated ) ) ),
					'usage'   => array( 'prompt_tokens' => 0, 'completion_tokens' => 0 ),
				) )
				: wp_json_encode( array(
					'model'       => 'crafted',
					'content'     => array( array( 'type' => 'text', 'text' => $generated ) ),
					'stop_reason' => 'end_turn',
					'usage'       => array( 'input_tokens' => 0, 'output_tokens' => 0 ),
				) ),
		);
	},
	10,
	3
);

$stored = EduAI_Exams::generate(
	$user->ID,
	array( array( 'type' => 'text', 'text' => 'projection-leak harness' ) ),
	'projection-leak harness',
	'leaktest-' . $user->ID . '-' . time(),
	10
);

if ( is_wp_error( $stored ) ) {
	check(
		'an exam could be stored for the over-the-wire check',
		false,
		'generate() failed: ' . $stored->get_error_message()
	);
} else {
	$request  = new WP_REST_Request( 'GET', '/eduai/v1/exam/' . (int) $stored['id'] );
	$response = rest_do_request( $request );
	$body     = wp_json_encode( $response->get_data() );

	check(
		'GET /eduai/v1/exam/<id> returns 200 for a signed-in student',
		200 === $response->get_status(),
		'status was ' . $response->get_status()
	);

	foreach ( array( 'answer_index', 'expected', 'explanation' ) as $field ) {
		check(
			sprintf( '§1 on the wire: "%s" absent from GET /exam/<id>', $field ),
			false === strpos( $body, '"' . $field . '"' ),
			'found "' . $field . '" in the HTTP response body'
		);
	}
}

/* ---- signed out, the route must not answer at all ----------------------- */

wp_set_current_user( 0 );
$anon = rest_do_request( new WP_REST_Request( 'GET', '/eduai/v1/exam/1' ) );

check(
	'signed out, the exam route refuses',
	401 === $anon->get_status() || 403 === $anon->get_status() || 404 === $anon->get_status(),
	'status was ' . $anon->get_status() . ' — an anonymous visitor got a reply'
);

printf( "\n%d passed, %d failed\n", $GLOBALS['leak_pass'], $GLOBALS['leak_fail'] );

if ( 0 === $GLOBALS['leak_pass'] + $GLOBALS['leak_fail'] ) {
	fwrite( STDERR, "no assertions ran — treating that as a failure\n" );
	exit( 1 );
}

exit( $GLOBALS['leak_fail'] > 0 ? 1 : 0 );
