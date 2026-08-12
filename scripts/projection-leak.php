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

/**
 * Give one fixture user a clean hourly allowance.
 *
 * EVERY user this harness acts as needs this, not just the first. `/chat`,
 * `/summarize`, `/calc` and `/exam/<id>/submit` all share one bucket, and
 * `check_rate_limit()` runs BEFORE the permission callback — so a spent quota
 * answers 429 where the test is waiting for 403, and the assertion fails
 * describing an access-control problem that did not happen. That is not
 * hypothetical: the deployment engineer put the stack into the exhausted-quota
 * state deliberately and this harness accused the exam route of a live hole.
 *
 * The second student is the one that matters most and the one most easily
 * forgotten — the intruder is who makes the request being judged.
 *
 * Third harness to need this line, so it is a function rather than a fourth
 * copy of it. A shared include across scripts/ is the right home if a fifth
 * appears.
 *
 * @param int $user_id Fixture user.
 */
function leak_clear_quota( int $user_id ): void {
	foreach ( array( 'eduai_rl_u', 'eduai_rl_exam_u' ) as $bucket ) {
		delete_transient( $bucket . $user_id );
	}
}

/**
 * Render a payload to a string the content searches can actually match against.
 *
 * `wp_json_encode()` escapes non-ASCII to \uXXXX and `/` to `\/` by default,
 * while the probes below are raw UTF-8 taken straight from the fixture. That
 * mismatch is not cosmetic: it silently blinded the verbatim detector to every
 * answer containing a slash or a non-ASCII character — 3 of the 10 in this
 * fixture ("f(x)·sin(nx)", "situation — audio", "1/pi"). Those three could have
 * leaked in full and the harness would have reported "none of the 10 answer
 * texts appear verbatim", because the needle and the haystack were in different
 * encodings.
 *
 * Found by the control added above it, on its first run, which is the argument
 * for controls in one line.
 *
 * The mismatch can be closed from either end — escape every needle, or
 * un-escape the haystack — and this is the haystack end on purpose. A needle
 * pinned to one exact representation only ever matches that representation, so
 * a leak that reached the response through some other encoding walks straight
 * past it. For a leak detector, narrower is the wrong direction: the cost of
 * being broad is a false positive somebody investigates, and the cost of being
 * narrow is an answer key on the wire that nothing reports.
 *
 * @param mixed $data Response data or fixture.
 */
/**
 * The needle for one secret: the opening characters of an answer, long enough
 * to be unmistakable and short enough to survive reformatting.
 *
 * There is one of these because the CONTROL and the CHECKS have to agree by
 * construction. The control's whole job is proving these probes can match where
 * the answer is known to be present; if it derived its probe even slightly
 * differently from the checks — a different length, a different trim — it would
 * be validating a needle nobody searches with, and the checks would run
 * unvalidated behind a green control. That is the same shape as a test which
 * reimplements the logic it is testing: the assertion runs, is correct, and is
 * about the wrong object.
 *
 * It also makes the Tester's warning automatic rather than remembered. Adding a
 * secret to $secrets now reaches the control and the checks through the same
 * function, so a new probe cannot be dead in one place and live in the other.
 *
 * @param string $text Full answer text from the fixture.
 */
function leak_probe( string $text ): string {
	return trim( mb_substr( $text, 0, 40 ) );
}

function leak_searchable( $data ): string {
	return (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
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

/* This harness generates several exams per run, and generation is budgeted at
 * four units an hour. Run it twice in an hour and the second run reports the
 * generation route returning 429 — which reads as "the leak guard is failing"
 * when it means "the guard ran out of its own allowance".
 *
 * That is worse than a flaky test: a security guard that goes red for reasons
 * unrelated to security is one that gets re-run until it is green, or ignored.
 * So it resets its own budget before starting. It is testing the projection,
 * not the rate limiter — check_exam_rate_limit() has its own coverage — and a
 * test that cannot be run twice in a row is not a test anyone will keep. */
leak_clear_quota( $user->ID );

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
$raw_wire = leak_searchable( $fixture );
$control  = true;

foreach ( array( 'answer_index', 'expected', 'explanation' ) as $field ) {
	$control = $control && false !== strpos( $raw_wire, '"' . $field . '"' );
}

check(
	'control: the detector finds the answers in an unprojected exam',
	$control,
	'the searches did not fire on raw fixture data — every "absent" result below is meaningless'
);

/* The control above proves the KEY-NAME searches fire. It says nothing about
 * the verbatim-content search below, which is a separate detector with its own
 * way of silently seeing nothing: if $secrets came back empty — a fixture with
 * no explanations, a renamed field, a changed loop — then "none of the 0 answer
 * texts appear verbatim" passes on every payload ever, including one carrying
 * the entire answer key. The count is printed in that assertion's own label,
 * which makes an empty set look like a result rather than an absence.
 *
 * So: assert the set is non-empty, and prove those probes fire where the
 * answers definitely are. */
/* Needle and haystack have to be in the SAME encoding, and there is exactly one
 * mechanism for that: leak_searchable() renders every payload with literal
 * characters, so the probes stay raw UTF-8 straight from the fixture.
 *
 * The alternative — escaping each needle to match a default-encoded haystack —
 * works equally well and was written at the same time by another session. Two
 * correct fixes from opposite ends cancelled out and turned the control red
 * again, which is how the collision announced itself. One mechanism only, and
 * this is the one: un-escaping the haystack also catches a leak that arrived
 * through some other encoding, where a needle escaped to one exact form would
 * slip past. */
$probe_control = array();
foreach ( $secrets as $s ) {
	$probe = leak_probe( $s[1] );
	if ( '' === $probe || false === strpos( $raw_wire, $probe ) ) {
		$probe_control[] = $s[0];
	}
}

check(
	sprintf( 'control: %d answer texts were extracted from the fixture', count( $secrets ) ),
	count( $secrets ) >= 5,
	'fewer than 5 answer texts found — the verbatim check below would pass vacuously'
);
check(
	'control: every answer-text probe fires on the unprojected exam',
	! $probe_control,
	'these probes found nothing even in raw data, so their absence proves nothing: ' . implode( ', ', $probe_control )
);

/* ---- the projection, as the browser would receive it -------------------- */

$client = EduAI_Exams::for_client( $fixture );
$wire   = leak_searchable( $client );

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
	/* Same encoding as the control above — this is the search that would catch
	 * a real leak, and it was the one silently unable to match three answers. */
	$probe = leak_probe( $text );
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

/* The three wp_json_encode() calls below are deliberately NOT leak_searchable().
 * They build the stubbed provider response — a model reply standing in for
 * Groq or Anthropic — so they must encode exactly the way a real provider does,
 * escapes and all. leak_searchable() is for haystacks the probes are searched
 * against; this is an input to the code under test, not something inspected.
 * Anyone tidying "the last three inconsistent encode calls" would be making the
 * stub less faithful than the thing it impersonates. */
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
	$body     = leak_searchable( $response->get_data() );

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

/* ---- the OTHER route that hands a paper to a browser --------------------
 *
 * Everything above exercises GET /exam/<id>. The deployment engineer proved
 * what that leaves uncovered: strip the projection from the GENERATION route
 * and this harness reports 12 passed, 0 failed while the response ships
 * answer_index, expected and explanation to every student on every exam.
 *
 * That route matters more than the one already watched, because it is where
 * the browser FIRST receives the paper — a regression there leaks on every
 * generation, before anyone has opened a stored exam.
 *
 * Both of its returns are exercised. class-eduai-rest.php:332 is the reuse
 * path, taken when an exam with the same source hash already exists, and :346
 * is the fresh one. They are separate returns with separate projections, so a
 * fix applied to one and not the other is exactly the shape that survives a
 * single-path test. The pre_http_request stub above is still installed, so
 * neither costs a model call.
 *
 * Deliberately NOT asserted here: POST /exam/<id>/submit. After marking, the
 * explanation and the mark scheme are the product — a student who has just sat
 * the paper is entitled to them. Asserting their absence there would encode a
 * rule the product does not have, and would go red on correct behaviour.
 */
/* The route requires 200 characters of source (class-eduai-rest.php:282), so
 * the harness has to send real material. Identical text both times on purpose:
 * the first call generates and takes the fresh return, the second matches on
 * source_hash and takes the reuse return. One string, both paths. */
$eduai_source = str_repeat(
	'The Calvin cycle fixes carbon dioxide in the stroma using RuBisCO, consuming the ATP and NADPH produced by the light-dependent reactions in the thylakoid membrane. ',
	4
);

/* Unique per run, or the "fresh" iteration stops being fresh after the first
 * ever run: source_hash would match a stored exam and the route would take the
 * reuse return while the label still said fresh. The second iteration reuses
 * THIS run's text, so both paths are exercised on every run rather than
 * depending on what a previous run happened to leave behind. */
$eduai_source .= ' Run marker ' . wp_generate_password( 12, false ) . '.';

foreach ( array( 'fresh', 'reused' ) as $eduai_path ) {

	$eduai_req = new WP_REST_Request( 'POST', '/eduai/v1/exam' );
	$eduai_req->set_param( 'count', 10 );
	$eduai_req->set_param( 'title', 'projection-leak harness' );
	$eduai_req->set_body_params( array( 'text' => $eduai_source ) );

	$eduai_res  = rest_do_request( $eduai_req );
	$eduai_body = leak_searchable( $eduai_res->get_data() );

	check(
		sprintf( 'POST /eduai/v1/exam (%s) answers for a signed-in student', $eduai_path ),
		in_array( $eduai_res->get_status(), array( 200, 201 ), true ),
		'status was ' . $eduai_res->get_status() . ' — body: ' . substr( (string) $eduai_body, 0, 200 )
	);

	/* Control: prove this iteration took the path its name claims.
	 *
	 * The label is documentation and documentation drifts silently. With a
	 * constant source string, every run after the first matches on source_hash
	 * and takes the REUSE return — so an iteration labelled "fresh" exercises
	 * :332 and never :346, and a regression in the fresh projection would sit
	 * behind a green check called "fresh" forever. Exactly the defect the
	 * Tester found in grade-adversarial, where a label said "model said 99"
	 * about an input that had been made legal.
	 *
	 * for_client() reports which return produced it, so the claim is checkable
	 * rather than assumed. Asserted, not merely printed. */
	$eduai_reused = (bool) ( $eduai_res->get_data()['reused'] ?? false );

	check(
		sprintf( 'control: the "%s" call really took the %s path', $eduai_path, $eduai_path ),
		( 'fresh' === $eduai_path ) ? ! $eduai_reused : $eduai_reused,
		sprintf(
			'reused=%s on the "%s" iteration — it exercised the other return, so the checks below say nothing about %s generation',
			$eduai_reused ? 'true' : 'false',
			$eduai_path,
			$eduai_path
		)
	);

	// Only meaningful if the route actually produced a paper. A 4xx body
	// contains no answer key either, and would pass all three below while
	// proving nothing — the shape this file's own control section exists to
	// refuse.
	if ( ! in_array( $eduai_res->get_status(), array( 200, 201 ), true )
		|| false === strpos( (string) $eduai_body, '"questions"' ) ) {
		check(
			sprintf( 'POST /eduai/v1/exam (%s) returned a paper to inspect', $eduai_path ),
			false,
			'no "questions" in the response, so the absence checks below would be vacuous'
		);
		continue;
	}

	foreach ( array( 'answer_index', 'expected', 'explanation' ) as $eduai_field ) {
		check(
			sprintf( '§1 on the wire: "%s" absent from POST /exam (%s)', $eduai_field, $eduai_path ),
			false === strpos( (string) $eduai_body, '"' . $eduai_field . '"' ),
			'found "' . $eduai_field . '" in the generation response — the answer key reaches the browser'
		);
	}
}

/* ---- retake: the paper must go blank again after it has been marked -----
 *
 * The remaining hole. GET /exam/<id> after an attempt legitimately returns the
 * marked script — the student sat it and is entitled to the answers. But the
 * same route with ?retake=true re-serves the paper to sit again, and if that
 * ever returned the attempt instead of the blank projection, a student would
 * read the answer key and then take the exam with it. Sitting a paper once must
 * not become a way to obtain its answers for the retake.
 *
 * The control here is built in rather than mutated, and it is the submit
 * response itself. Submitting reveals the answer key by design, so the same
 * searches are run against it FIRST: if they can find the key where it is
 * supposed to be, then finding nothing in the retake response means something.
 * That re-proves the detector on this route on every run — a one-time mutation
 * would only have proved it on the day someone ran it.
 *
 * A separate exam from the one above, on its own source hash, so this flow
 * cannot disturb the no-attempts assertions already made against that one.
 */
$eduai_sit = EduAI_Exams::generate(
	$user->ID,
	array( array( 'type' => 'text', 'text' => 'projection-leak retake harness' ) ),
	'projection-leak retake harness',
	'leaktest-retake-' . $user->ID . '-' . time(),
	10
);

if ( is_wp_error( $eduai_sit ) ) {
	check( 'an exam could be stored for the retake check', false, 'generate() failed: ' . $eduai_sit->get_error_message() );
} else {
	$eduai_exam_id = (int) $eduai_sit['id'];

	/* Punctuation for every short answer on purpose: those are scored 0 locally
	 * without reaching the marker, so sitting the paper here costs no model call
	 * and no credit, while the response still carries the full mark scheme and
	 * every explanation — which is exactly what this needs to search. */
	$eduai_answers = array();
	foreach ( $eduai_sit['questions'] as $eduai_q ) {
		$eduai_answers[] = 'mcq' === $eduai_q['type']
			? array( 'id' => $eduai_q['id'], 'choice' => 0 )
			: array( 'id' => $eduai_q['id'], 'text' => ',,' );
	}

	$eduai_sub = new WP_REST_Request( 'POST', '/eduai/v1/exam/' . $eduai_exam_id . '/submit' );
	$eduai_sub->set_body_params( array( 'answers' => $eduai_answers ) );

	$eduai_sub_res  = rest_do_request( $eduai_sub );
	$eduai_sub_body = leak_searchable( $eduai_sub_res->get_data() );

	check(
		'POST /exam/<id>/submit marks the paper for a signed-in student',
		in_array( $eduai_sub_res->get_status(), array( 200, 201 ), true ),
		'status was ' . $eduai_sub_res->get_status() . ' — body: ' . substr( (string) $eduai_sub_body, 0, 200 )
	);

	/* The built-in control. Submit SHOULD contain these; if it does not, the
	 * searches below are looking for something that is not there to find and
	 * their silence would be meaningless. */
	$eduai_revealed = true;
	foreach ( array( 'answer_index', 'expected', 'explanation' ) as $eduai_field ) {
		$eduai_revealed = $eduai_revealed && false !== strpos( (string) $eduai_sub_body, '"' . $eduai_field . '"' );
	}

	/* If you have come here to "finish the coverage" by asserting that
	 * answer_index/expected/explanation are ABSENT from submit: don't. They are
	 * supposed to be there. A student who has just sat the paper is entitled to
	 * the mark scheme and the explanations — that is the feature, not a leak,
	 * and the assertion would go red on correct behaviour. The check below is
	 * the opposite one on purpose: submit MUST carry them, which is what makes
	 * the retake assertions that follow mean anything. */
	check(
		'control: the marked script DOES carry the answer key',
		$eduai_revealed,
		'submit returned no answer_index/expected/explanation — either marking failed or the searches are broken, '
			. 'so the retake assertions below would pass without testing anything'
	);

	/* The discriminating control, and the reason this is not just another
	 * "absent" assertion: the SAME route on the SAME exam for the SAME user,
	 * with only the retake flag off, must still hand back the marked script.
	 *
	 * That is what makes the three assertions below a test of the flag rather
	 * than a test of the route. Without it they would pass identically if
	 * `retake` were ignored, misspelled, dropped from the args array, or if the
	 * exam simply had no attempt — every one of which is a way for the retake
	 * feature to be broken while the guard stays green. Built in rather than
	 * mutated once, so it re-proves itself on every run. */
	$eduai_marked = new WP_REST_Request( 'GET', '/eduai/v1/exam/' . $eduai_exam_id );
	$eduai_mk_body = leak_searchable( rest_do_request( $eduai_marked )->get_data() );

	$eduai_marked_has = true;
	foreach ( array( 'answer_index', 'expected', 'explanation' ) as $eduai_field ) {
		$eduai_marked_has = $eduai_marked_has && false !== strpos( $eduai_mk_body, '"' . $eduai_field . '"' );
	}

	check(
		'control: the same route WITHOUT retake does return the marked script',
		$eduai_marked_has,
		'GET /exam/<id> after an attempt carried no answer key, so the retake checks below '
			. 'would pass whether or not the retake flag does anything at all'
	);

	$eduai_retake = new WP_REST_Request( 'GET', '/eduai/v1/exam/' . $eduai_exam_id );
	$eduai_retake->set_param( 'retake', true );

	$eduai_rt_res  = rest_do_request( $eduai_retake );
	$eduai_rt_body = leak_searchable( $eduai_rt_res->get_data() );

	check(
		'GET /exam/<id>?retake=1 returns a paper after the exam has been sat',
		in_array( $eduai_rt_res->get_status(), array( 200, 201 ), true )
			&& false !== strpos( (string) $eduai_rt_body, '"questions"' ),
		'status ' . $eduai_rt_res->get_status() . ' with no "questions" — the absence checks below would be vacuous'
	);

	foreach ( array( 'answer_index', 'expected', 'explanation' ) as $eduai_field ) {
		check(
			sprintf( '§1 on the wire: "%s" absent from the RETAKE paper', $eduai_field ),
			false === strpos( (string) $eduai_rt_body, '"' . $eduai_field . '"' ),
			'found "' . $eduai_field . '" — retake re-serves the marked script, so a student can read the key and re-sit'
		);
	}

	/* Renaming the field or inlining the text would pass every check above. */
	$eduai_rt_leaked = array();
	foreach ( $secrets as $eduai_s ) {
		$eduai_probe = leak_probe( $eduai_s[1] );
		if ( '' !== $eduai_probe && false !== strpos( (string) $eduai_rt_body, $eduai_probe ) ) {
			$eduai_rt_leaked[] = $eduai_s[0];
		}
	}

	check(
		'§1 on the wire: no answer text appears verbatim in the RETAKE paper',
		! $eduai_rt_leaked,
		'leaked: ' . implode( ', ', $eduai_rt_leaked )
	);

	/* ---- /history: the exam thread must log outcomes, never answers -------
	 *
	 * generate() and grade() both write to the conversation log, and /history
	 * serves that log straight to the browser. Today they write a title and a
	 * score, so there is nothing to leak — this exists so that the day someone
	 * logs the exam JSON to debug a generation, the guard says so. The control
	 * is the same one used throughout: the thread has to contain something
	 * first, or "no answers here" is a statement about an empty list. */
	$eduai_hist = new WP_REST_Request( 'GET', '/eduai/v1/history' );
	$eduai_hist->set_param( 'thread_id', 'exam' );

	$eduai_hist_res  = rest_do_request( $eduai_hist );
	$eduai_hist_body = leak_searchable( $eduai_hist_res->get_data() );

	check(
		'control: the exam thread in /history has messages to inspect',
		200 === $eduai_hist_res->get_status()
			&& false !== strpos( (string) $eduai_hist_body, '"content"' ),
		'status ' . $eduai_hist_res->get_status() . ' with no messages — the check below would be vacuous'
	);

	foreach ( array( 'answer_index', 'expected', 'explanation' ) as $eduai_field ) {
		check(
			sprintf( '§1 on the wire: "%s" absent from GET /history', $eduai_field ),
			false === strpos( (string) $eduai_hist_body, '"' . $eduai_field . '"' ),
			'found "' . $eduai_field . '" — the conversation log is serving exam answers to the browser'
		);
	}
}

/* ---- a SECOND student: who may obtain the paper that is allowed to
 *      contain everything ------------------------------------------------
 *
 * Everything above asks what is IN a payload. On the marked-paper route that
 * is deliberately the wrong question — after an attempt, the mark scheme and
 * the explanations are the product, and :360 says so. Which means the only
 * thing between one student and another student's complete answer key is
 * exam_owned(), and until now this file read exactly two states: the owner,
 * and signed out. The realistic attacker is neither. Exam ids are sequential,
 * so the attack is a for-loop by anyone with a valid student session.
 *
 * `download-gate` has asserted "a link copied from student A is refused for
 * student B" since it was written, for a route that serves a PDF. This route
 * serves the answer key, legitimately, and had no equivalent. Found by the
 * Tester's cold pass, who measured all three states and left the file alone.
 */
$eduai_other = get_user_by( 'login', 'leaktest-student-b' );

if ( ! $eduai_other ) {
	$eduai_other_id = wp_insert_user( array(
		'user_login' => 'leaktest-student-b',
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => 'leaktest-student-b@example.invalid',
		'role'       => get_role( 'student' ) ? 'student' : 'subscriber',
	) );

	if ( is_wp_error( $eduai_other_id ) ) {
		fwrite( STDERR, 'could not create the second test student: ' . $eduai_other_id->get_error_message() . "\n" );
		exit( 1 );
	}

	$eduai_other = get_user_by( 'id', $eduai_other_id );
}

// The intruder makes real requests below, and submit is rate limited before it
// is permission-checked. Without this, a spent quota answers 429 where the
// assertion waits for 403.
leak_clear_quota( $eduai_other->ID );

if ( isset( $eduai_exam_id ) ) {

	/* Control 1 — the payload really does contain the key right now.
	 * Without this, "student B got no answer key" is satisfied by an exam that
	 * never had one, or by a route that has stopped returning anything. */
	wp_set_current_user( $user->ID );
	$eduai_owner_body = leak_searchable( rest_do_request(
		new WP_REST_Request( 'GET', '/eduai/v1/exam/' . $eduai_exam_id )
	)->get_data() );

	check(
		'control: the owner\'s marked paper carries the answer key to be protected',
		false !== strpos( $eduai_owner_body, '"answer_index"' ),
		'the owner got no answer key, so refusing the second student proves nothing about ownership'
	);

	wp_set_current_user( $eduai_other->ID );

	/* Control 2 — and the second student is genuinely SIGNED IN.
	 * This is the one that decides whether the block below means anything. A
	 * failed wp_set_current_user, a role without read, a session that never
	 * established: every one of those makes each request 401 and every
	 * assertion below pass for a reason that has nothing to do with ownership.
	 * /history is the cheapest route that any signed-in student may use. */
	$eduai_b_hist = rest_do_request( new WP_REST_Request( 'GET', '/eduai/v1/history' ) );

	check(
		'control: the second student has a working session of their own',
		200 === $eduai_b_hist->get_status(),
		'status ' . $eduai_b_hist->get_status() . ' on a route any signed-in student may use — '
			. 'the refusals below would be about being logged out, not about ownership'
	);

	/* The three ways to reach another student's paper. Each asserts the STATUS,
	 * because that is the load-bearing half: an error body contains no answer
	 * key either, so "no key in the response" is satisfied by any failure at
	 * all. The key check rides along to catch a 200 that leaks. */
	$eduai_cross = array(
		'GET /exam/<id>'          => new WP_REST_Request( 'GET', '/eduai/v1/exam/' . $eduai_exam_id ),
		'GET /exam/<id>?retake=1' => new WP_REST_Request( 'GET', '/eduai/v1/exam/' . $eduai_exam_id ),
		// The sharpest of the three: submit legitimately returns the full key,
		// so a student able to submit against someone else's exam is handed it.
		'POST /exam/<id>/submit'  => new WP_REST_Request( 'POST', '/eduai/v1/exam/' . $eduai_exam_id . '/submit' ),
	);
	$eduai_cross['GET /exam/<id>?retake=1']->set_param( 'retake', true );
	$eduai_cross['POST /exam/<id>/submit']->set_body_params( array( 'answers' => array() ) );

	foreach ( $eduai_cross as $eduai_what => $eduai_req_b ) {
		$eduai_res_b  = rest_do_request( $eduai_req_b );
		$eduai_body_b = leak_searchable( $eduai_res_b->get_data() );

		$eduai_status_b = $eduai_res_b->get_status();

		/* Report the difference between what was expected and what came back —
		 * not the worst reading of a mismatch.
		 *
		 * This said "a signed-in student reached another student's exam" for
		 * ANY status that was not a refusal, which is an access-control
		 * accusation inferred from "not 403". It is wrong for every other way a
		 * request can fail: 429 when the hourly quota is spent, 500, a network
		 * error, a WAF block. In all of those the student reached nothing.
		 *
		 * The deployment engineer hit exactly that — exhausted quotas produced
		 * 429 and this line announced a live hole in the exam route. On a
		 * project where everyone has been taught to treat a red guard as real,
		 * a false alarm costs twice: once for the hunt, and once for the
		 * credibility of the next true one.
		 *
		 * So the claim is now scaled to the evidence. Only a 200 supports
		 * "reached"; every other non-refusal says the check could not be made
		 * and why. */
		if ( 200 === $eduai_status_b ) {
			$eduai_detail = 'status 200 — a signed-in student REACHED another student\'s exam';
		} elseif ( 429 === $eduai_status_b ) {
			$eduai_detail = 'expected 401/403/404, got 429: the hourly quota was spent before the '
				. 'permission callback ran, so this proves nothing either way. Clear the eduai_rl_* '
				. 'transients for both fixture users and re-run.';
		} else {
			$eduai_detail = sprintf(
				'expected a refusal (401/403/404), got %d — the route did not answer the way ownership requires, '
					. 'but nothing here shows the exam was disclosed',
				$eduai_status_b
			);
		}

		check(
			sprintf( 'another student is refused: %s', $eduai_what ),
			in_array( $eduai_status_b, array( 401, 403, 404 ), true ),
			$eduai_detail
		);

		check(
			sprintf( 'and gets no answer key from: %s', $eduai_what ),
			false === strpos( $eduai_body_b, '"answer_index"' )
				&& false === strpos( $eduai_body_b, '"expected"' ),
			'the response carried the answer key to a student who does not own the exam'
		);
	}

	wp_set_current_user( $user->ID );
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
