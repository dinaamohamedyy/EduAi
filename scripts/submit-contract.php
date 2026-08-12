<?php
/**
 * The submit response shape — docs/07 §3, against the real marking path.
 *
 *   docker compose --profile tools run --rm cli \
 *     wp eval-file /scripts/submit-contract.php --allow-root
 *
 * The headline is the tri-state trap. §3 is explicit that `correct` is
 * `true`/`false` for MCQ and **`null` for every short answer**, and that every
 * key appears on every result whatever the type, `null` or `""` where it does
 * not apply, never absent. Those two rules exist together for one reason: a
 * renderer written against per-type keys has to test for existence on every
 * field, and the one test anybody actually writes — `if (r.correct)` — reads
 * both a missing key and `null` as false and marks a full-credit short answer
 * wrong.
 *
 * That is the worst failure this product can have. A revision tool telling a
 * student they got something wrong when they got it right does not just
 * mis-report a number; it teaches them the correct answer was incorrect, and
 * they will revise away from it.
 *
 * The grade is injected at `pre_http_request` so a short can be awarded full
 * marks deterministically — no network call, no credit, and every line of the
 * real marking and normalisation path still runs.
 *
 * Assertions come from §3 itself, not from what the code returns today.
 *
 * @package Scholaris
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run me through wp-cli, not php.\n" );
	exit( 1 );
}

$GLOBALS['sc_pass'] = 0;
$GLOBALS['sc_fail'] = 0;

function check( string $rule, bool $ok, string $detail ): void {
	if ( $ok ) {
		$GLOBALS['sc_pass']++;
		printf( "ok    %s\n", $rule );
		return;
	}
	$GLOBALS['sc_fail']++;
	printf( "FAIL  %s\n        %s\n", $rule, $detail );
}

putenv( 'ANTHROPIC_API_KEY=test-key-not-used' );
putenv( 'GROQ_API_KEY=test-key-not-used' );

$exam = EduAI_Exams::fixture();

if ( ! $exam ) {
	fwrite( STDERR, "fixtures/exam-sample.json is unreadable\n" );
	exit( 1 );
}

$shorts = array();
$mcqs   = array();
foreach ( $exam['questions'] as $q ) {
	if ( 'short' === $q['type'] ) {
		$shorts[] = $q;
	} else {
		$mcqs[] = $q;
	}
}

/* The case under test: a short answer the marker gave full marks to. */
$full = $shorts[0];

/* One MCQ answered right and one answered wrong, so `correct` is observed in
 * both boolean states. Without the wrong one, a bug that hard-coded `correct`
 * to true would pass every assertion here. */
$mcq_right = $mcqs[0];
$mcq_wrong = $mcqs[1];

$answers = array();
foreach ( $shorts as $q ) {
	$answers[] = array( 'id' => $q['id'], 'text' => 'A complete answer worth full marks.' );
}
$answers[] = array( 'id' => $mcq_right['id'], 'choice' => (int) $mcq_right['answer_index'] );
$answers[] = array(
	'id'     => $mcq_wrong['id'],
	'choice' => 0 === (int) $mcq_wrong['answer_index'] ? 1 : 0,
);
foreach ( array_slice( $mcqs, 2 ) as $q ) {
	$answers[] = array( 'id' => $q['id'], 'choice' => (int) $q['answer_index'] );
}

$grades = array();
foreach ( $shorts as $q ) {
	$grades[] = array(
		'id'      => $q['id'],
		'awarded' => $q['marks'],          // full marks on every short
		'of'      => $q['marks'],
		'comment' => 'Full credit: every award point is present.',
	);
}

$crafted = wp_json_encode( array( 'results' => $grades ) );
$format  = EduAI_Claude::provider()['format'];

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( $crafted, $format ) {
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
					'choices' => array( array( 'message' => array( 'content' => $crafted ) ) ),
					'usage'   => array( 'prompt_tokens' => 0, 'completion_tokens' => 0 ),
				) )
				: wp_json_encode( array(
					'model'       => 'crafted',
					'content'     => array( array( 'type' => 'text', 'text' => $crafted ) ),
					'stop_reason' => 'end_turn',
					'usage'       => array( 'input_tokens' => 0, 'output_tokens' => 0 ),
				) ),
		);
	},
	10,
	3
);

$graded = EduAI_Exams::grade( $exam, $answers );

if ( is_wp_error( $graded ) ) {
	fwrite( STDERR, 'grade() failed: ' . $graded->get_error_message() . "\n" );
	exit( 1 );
}

$by_id = array();
foreach ( $graded['results'] as $r ) {
	$by_id[ (int) $r['id'] ] = $r;
}

printf( "docs/07 §3 — submit response, %d questions\n\n", count( $graded['results'] ) );

/* ---- control ------------------------------------------------------------
 * Every tri-state assertion below is about `correct` holding the right value.
 * If `correct` were hard-coded, or the wrong MCQ silently marked right, most
 * of them would still pass. So first prove the field moves. */
$wrong = $by_id[ $mcq_wrong['id'] ] ?? array();
$right = $by_id[ $mcq_right['id'] ] ?? array();

check(
	'control: `correct` is observed in both boolean states',
	( ( $right['correct'] ?? null ) === true ) && ( ( $wrong['correct'] ?? null ) === false ),
	sprintf(
		'right-answer MCQ gave %s, wrong-answer MCQ gave %s',
		var_export( $right['correct'] ?? null, true ),
		var_export( $wrong['correct'] ?? null, true )
	)
);

/* ---- the tri-state trap -------------------------------------------------- */

$r = $by_id[ $full['id'] ] ?? array();

check(
	sprintf( 'a full-marks short answer is awarded its full marks (q%d)', $full['id'] ),
	isset( $r['awarded'], $r['of'] ) && (float) $r['awarded'] === (float) $full['marks'],
	'awarded came back as ' . var_export( $r['awarded'] ?? null, true )
);

/*
 * Two different defects live behind this one assertion, and the failure line
 * has to say which. A missing `correct` key and a `correct` that came back
 * false are not the same bug: the first is a response that never carried the
 * field, the second is a full-credit answer rendered to the student as wrong.
 * Reported as "not false", a MISSING key sends the reader looking for a false
 * that is not there — the same wrong direction as a fixture that cannot detect
 * its own absence. Name the case, then describe it.
 */
$correct_present = array_key_exists( 'correct', $r );

check(
	sprintf( '§3: `correct` is present and null on a short answer (q%d)', $full['id'] ),
	$correct_present && null === $r['correct'],
	$correct_present
		? 'correct came back as ' . var_export( $r['correct'], true )
			. ' — false here is a full-credit answer rendered as wrong'
		: 'the `correct` key is ABSENT from the result, not merely wrong — '
			. 'nothing set it, so there is no value to inspect'
);

check(
	sprintf( '§3: `comment` is non-empty on a short even at full marks (q%d)', $full['id'] ),
	isset( $r['comment'] ) && '' !== trim( (string) $r['comment'] ),
	'comment came back as ' . var_export( $r['comment'] ?? null, true )
);

/* Every short, not just the sampled one. */
$absent_correct = array();
$wrong_correct  = array();
foreach ( $shorts as $q ) {
	$row = $by_id[ $q['id'] ] ?? array();
	if ( ! array_key_exists( 'correct', $row ) ) {
		$absent_correct[] = 'q' . $q['id'];
	} elseif ( null !== $row['correct'] ) {
		$wrong_correct[] = 'q' . $q['id'] . '=' . var_export( $row['correct'], true );
	}
}

/* Same split as the sampled case above: a list of ids reported as "not null"
 * is actively misleading when the key was never there to be null. */
$tristate_detail = array();
if ( $absent_correct ) {
	$tristate_detail[] = '`correct` ABSENT on: ' . implode( ', ', $absent_correct );
}
if ( $wrong_correct ) {
	$tristate_detail[] = 'present but not null on: ' . implode( ', ', $wrong_correct );
}

check(
	sprintf( '§3: `correct` is present and null on all %d short answers', count( $shorts ) ),
	! $absent_correct && ! $wrong_correct,
	implode( '; ', $tristate_detail )
);

/* ---- every key on every result ------------------------------------------- */

$required = array(
	'id', 'type', 'band', 'awarded', 'of', 'correct',
	'your_choice', 'your_text', 'answer_index', 'expected',
	'explanation', 'comment',
);

/* ---- control: the key list is test data, and test data can be weakened ----
 *
 * The assertion below reports "all %d keys present" using count( $required ),
 * so deleting a key from that list makes it say "all 11 keys present" and pass
 * — covering less while reading identically. Nothing else here would notice.
 * Same shape as a crafted response whose planted lie has been made legal: the
 * check still runs, against a target that has quietly shrunk.
 *
 * §3 names twelve keys and says every one appears on every result whatever the
 * type. Pin that number, and name the four whose absence the rest of this file
 * is specifically about, so a deletion fails here rather than passing there. */
check(
	'control: the required-key list still covers all twelve keys §3 names',
	12 === count( $required ),
	sprintf( 'the list holds %d keys — a shortened list makes the assertion below vacuous', count( $required ) )
);

$critical = array_diff( array( 'correct', 'expected', 'explanation', 'comment' ), $required );
check(
	'control: the keys carrying §3\'s guarantees are in the list',
	empty( $critical ),
	'missing from the list: ' . implode( ', ', $critical )
);

/* array_key_exists, never isset. `isset` returns false for a key that is
 * present and null — which is precisely the case §3 exists to guarantee, since
 * `correct` is null on every short answer. Writing the natural-looking
 * `isset( $row[$key] )` here would invert the assertion: it would demand the
 * keys be non-null and fail on a correctly-shaped response. Do not "tidy" it. */
$missing = array();
foreach ( $graded['results'] as $row ) {
	foreach ( $required as $key ) {
		if ( ! array_key_exists( $key, $row ) ) {
			$missing[] = 'q' . $row['id'] . '.' . $key;
		}
	}
}
check(
	sprintf( '§3: all %d keys present on every result, whatever the type', count( $required ) ),
	! $missing,
	'absent: ' . implode( ', ', array_slice( $missing, 0, 12 ) )
);

/* ---- which key carries the correction ------------------------------------ */

check(
	'§3: MCQ carries an explanation and an empty comment',
	'' !== trim( (string) ( $right['explanation'] ?? '' ) ) && '' === trim( (string) ( $right['comment'] ?? 'x' ) ),
	sprintf(
		'explanation %s, comment %s',
		var_export( $right['explanation'] ?? null, true ),
		var_export( $right['comment'] ?? null, true )
	)
);

check(
	'§3: a short carries a mark scheme and an empty explanation',
	'' !== trim( (string) ( $r['expected'] ?? '' ) ) && '' === trim( (string) ( $r['explanation'] ?? 'x' ) ),
	sprintf(
		'expected %s, explanation %s',
		var_export( mb_substr( (string) ( $r['expected'] ?? '' ), 0, 30 ), true ),
		var_export( $r['explanation'] ?? null, true )
	)
);

/* ---- order and arithmetic ------------------------------------------------ */

$want_order = array_map( static fn( array $q ): int => (int) $q['id'], $exam['questions'] );
$got_order  = array_map( static fn( array $x ): int => (int) $x['id'], $graded['results'] );

check(
	'§3: results are in question order, not submission order',
	$want_order === $got_order,
	'got ' . implode( ',', $got_order )
);

$sum = 0.0;
foreach ( $graded['results'] as $row ) {
	$sum += (float) $row['awarded'];
}
check(
	'§3: score is the sum of awarded, to one decimal',
	abs( (float) $graded['score'] - round( $sum, 1 ) ) < 0.05,
	sprintf( 'score %s, awarded sums to %s', $graded['score'], $sum )
);

$expect_pct = (int) round( ( (float) $graded['score'] / (float) $graded['total'] ) * 100 );
check(
	'§3: percent is the rounded integer of score over total',
	(int) $graded['percent'] === $expect_pct,
	sprintf( 'percent %s, expected %d', $graded['percent'], $expect_pct )
);

$band_awarded = 0.0;
$band_of      = 0.0;
foreach ( $graded['bands'] as $band ) {
	$band_awarded += (float) $band['awarded'];
	$band_of      += (float) $band['of'];
}
check(
	'§3: the bands sum to the score and the total',
	abs( $band_awarded - (float) $graded['score'] ) < 0.05
		&& abs( $band_of - (float) $graded['total'] ) < 0.05,
	sprintf(
		'bands %s/%s against score %s/%s',
		$band_awarded, $band_of, $graded['score'], $graded['total']
	)
);

/* ---- the route's own bound, which nothing has ever exercised -------------
 *
 * `exam_submit` rejects more than 100 answers with a 400 before grade() is
 * reached. Everything above tests grade(); this is the only assertion here
 * about the *route*. A bound that has never been exercised is a bound nobody
 * knows is there, and it is exactly the kind removed as dead code during a
 * refactor.
 *
 * Needs a stored, owned exam — the route checks ownership before the count —
 * so generation is stubbed the same way the grade call is.
 */
/* Everything above this point runs against the fixture with no user at all,
 * because grade() does not need one. The route does: exam_submit checks
 * ownership before it checks the count, so the bound is unreachable without a
 * student who owns a stored exam. */
$login = 'sc-student';
$user  = get_user_by( 'login', $login );

if ( ! $user ) {
	$new  = wp_insert_user( array(
		'user_login' => $login,
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => $login . '@example.invalid',
		'role'       => get_role( 'student' ) ? 'student' : 'subscriber',
	) );
	$user = is_wp_error( $new ) ? null : get_user_by( 'id', $new );
}

/*
 * Start this user from zero quota, or a previous run decides the result.
 *
 * exam_submit() passes through check_rate_limit() before it reaches the route's
 * own answer-count bound, so on a stack these harnesses have been run against
 * all day the fixture user is long past 20-per-hour and every submission comes
 * back 429. The 100-answer case then "refuses" — with the wrong code, for the
 * wrong reason — and reads as the bound being enforced when it was never
 * reached. The control below is what caught it, by saying the refusal proved
 * nothing rather than merely failing.
 *
 * Note the direction: this harness fails on a LONG-LIVED stack and passes on a
 * fresh one, which is the exact opposite of download-gate. "Which stack did
 * this run on" is therefore a property of the suite, not a caveat belonging to
 * one file — and the nightly, which boots fresh, would never have shown it.
 *
 * Found by the deployment engineer, whose own mutation left one of the
 * transients behind: restoring a file does not restore the database.
 */
if ( $user ) {
	delete_transient( 'eduai_rl_u' . $user->ID );
}

$fixture_exam = wp_json_encode( array(
	'schema_version' => 1,
	'title'          => $exam['title'],
	'questions'      => $exam['questions'],
) );

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( $fixture_exam, $format ) {
		if ( false === strpos( $url, '/v1/' ) || false !== strpos( (string) ( $args['body'] ?? '' ), 'award' ) ) {
			return $pre;   // leave the grading stub alone
		}
		return array(
			'headers'  => array(),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
			'body'     => 'openai' === $format
				? wp_json_encode( array( 'model' => 'stub', 'choices' => array( array( 'message' => array( 'content' => $fixture_exam ) ) ), 'usage' => array() ) )
				: wp_json_encode( array( 'model' => 'stub', 'content' => array( array( 'type' => 'text', 'text' => $fixture_exam ) ), 'stop_reason' => 'end_turn', 'usage' => array() ) ),
		);
	},
	20,
	3
);

$stored = $user
	? EduAI_Exams::generate(
		(int) $user->ID,
		array( array( 'type' => 'text', 'text' => 'Bound probe.' ) ),
		'bound-probe',
		'bound-' . wp_generate_password( 8, false, false ),
		10
	)
	: new WP_Error( 'sc_no_user', 'could not create a student to submit as' );

if ( is_wp_error( $stored ) ) {
	check( 'route bound: could not store an exam to submit against', false, $stored->get_error_message() );
} else {
	$submit = static function ( array $answer_list ) use ( $stored, $user ) {
		wp_set_current_user( $user->ID );
		$r = new WP_REST_Request( 'POST', '/eduai/v1/exam/' . (int) $stored['id'] . '/submit' );
		$r->set_param( 'answers', $answer_list );
		$resp = rest_do_request( $r );
		$d    = $resp->get_data();
		return array( $resp->get_status(), is_array( $d ) && isset( $d['code'] ) ? (string) $d['code'] : '' );
	};

	/* Control first: a legal submission must NOT be refused, or "101 is
	 * refused" would be true of every request and prove nothing about the
	 * bound. */
	list( $ok_status ) = $submit( array( array( 'id' => (int) $exam['questions'][0]['id'], 'choice' => 0 ) ) );
	check(
		'control: a submission inside the bound is accepted',
		200 === $ok_status,
		sprintf( 'a one-answer submission returned %d — the refusal below would prove nothing', $ok_status )
	);

	$over = array();
	for ( $i = 0; $i < 101; $i++ ) {
		$over[] = array( 'id' => 1, 'choice' => 0 );
	}
	list( $over_status, $over_code ) = $submit( $over );

	check(
		'§3: more than 100 answers is refused with 400',
		400 === $over_status,
		sprintf( 'returned %d (%s) — the route bound is not enforced', $over_status, $over_code )
	);
}

printf( "\n%d passed, %d failed\n", $GLOBALS['sc_pass'], $GLOBALS['sc_fail'] );

if ( 0 === $GLOBALS['sc_pass'] + $GLOBALS['sc_fail'] ) {
	fwrite( STDERR, "no assertions ran — treating that as a failure\n" );
	exit( 1 );
}

exit( $GLOBALS['sc_fail'] > 0 ? 1 : 0 );
