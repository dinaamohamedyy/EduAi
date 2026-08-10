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

check(
	sprintf( '§3: `correct` is null on a short answer, not false (q%d)', $full['id'] ),
	array_key_exists( 'correct', $r ) && null === $r['correct'],
	'correct came back as ' . var_export( $r['correct'] ?? '<absent>', true )
		. ' — false here is a full-credit answer rendered as wrong'
);

check(
	sprintf( '§3: `comment` is non-empty on a short even at full marks (q%d)', $full['id'] ),
	isset( $r['comment'] ) && '' !== trim( (string) $r['comment'] ),
	'comment came back as ' . var_export( $r['comment'] ?? null, true )
);

/* Every short, not just the sampled one. */
$bad_tristate = array();
foreach ( $shorts as $q ) {
	$row = $by_id[ $q['id'] ] ?? array();
	if ( ! array_key_exists( 'correct', $row ) || null !== $row['correct'] ) {
		$bad_tristate[] = 'q' . $q['id'];
	}
}
check(
	sprintf( '§3: `correct` is null on all %d short answers', count( $shorts ) ),
	! $bad_tristate,
	'not null on: ' . implode( ', ', $bad_tristate )
);

/* ---- every key on every result ------------------------------------------- */

$required = array(
	'id', 'type', 'band', 'awarded', 'of', 'correct',
	'your_choice', 'your_text', 'answer_index', 'expected',
	'explanation', 'comment',
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

printf( "\n%d passed, %d failed\n", $GLOBALS['sc_pass'], $GLOBALS['sc_fail'] );

if ( 0 === $GLOBALS['sc_pass'] + $GLOBALS['sc_fail'] ) {
	fwrite( STDERR, "no assertions ran — treating that as a failure\n" );
	exit( 1 );
}

exit( $GLOBALS['sc_fail'] > 0 ? 1 : 0 );
