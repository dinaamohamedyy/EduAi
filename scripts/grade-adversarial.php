<?php
/**
 * Adversarial tests for the four grading rules in docs/06 §5.2, run against
 * the real grading path on a live stack.
 *
 *   docker compose --profile tools run --rm cli \
 *     wp eval-file /scripts/grade-adversarial.php --allow-root
 *
 * Why this exists. Schema validation catches the shape of a grading response;
 * these four rules catch a plausible lie — well-formed JSON that is wrong.
 * They are the only thing standing between a model's hallucination and a
 * student's mark, and they are exactly the code that never runs in a happy
 * path test, because a cooperative model never trips them.
 *
 * How it works. The crafted grades are injected at `pre_http_request`, so no
 * network call leaves the container and no credit is spent, while every line
 * of the real path still runs: EduAI_Claude::message, the JSON extraction, the
 * schema validation, and EduAI_Exams::grade's normalisation. The only thing
 * faked is the model's reply, which is the component under suspicion.
 *
 * Assertions are written from docs/06 §5.2 directly, never from what the
 * implementation currently returns. A fixture generated from the code under
 * test only ever proves the code agrees with itself -- which is how
 * calc-cases.json came to pass 31/31 with the wrong -3^2 convention baked in.
 *
 * @package Scholaris
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run me through wp-cli, not php.\n" );
	exit( 1 );
}

/* -------------------------------------------------------------------------
 * Harness
 * ---------------------------------------------------------------------- */

/* Counters live in $GLOBALS deliberately. `wp eval-file` includes this file
 * inside a method, so file-scope variables here are locals, not globals --
 * `global $pass` in the function below would bind to a different, always-zero
 * variable. The first version of this harness did exactly that: it printed
 * twelve passes and then exited 0 off a $fail it had never incremented, which
 * would have made a genuine failure invisible to CI. */
$GLOBALS['eduai_pass'] = 0;
$GLOBALS['eduai_fail'] = 0;

/**
 * @param string $rule  The §5.2 clause being checked.
 * @param bool   $ok    Whether it held.
 * @param string $detail What was actually seen.
 */
function check( string $rule, bool $ok, string $detail ): void {
	if ( $ok ) {
		$GLOBALS['eduai_pass']++;
		printf( "ok    %s\n", $rule );
		return;
	}

	$GLOBALS['eduai_fail']++;
	printf( "FAIL  %s\n        %s\n", $rule, $detail );
}

/* A key has to resolve or EduAI_Claude::message bails before the filter below
 * ever sees the request. Process-local only: no option written, no file
 * touched, and pre_http_request means the value is never sent anywhere. */
putenv( 'ANTHROPIC_API_KEY=test-key-not-used' );
putenv( 'GROQ_API_KEY=test-key-not-used' );

$exam = EduAI_Exams::fixture();

if ( ! $exam ) {
	fwrite( STDERR, "Could not load fixtures/exam-sample.json\n" );
	exit( 1 );
}

/* Shorts are what the model grades; MCQ never appears in that payload. */
$shorts = array();
$mcqs   = array();

foreach ( $exam['questions'] as $q ) {
	if ( 'short' === $q['type'] ) {
		$shorts[] = $q;
	} else {
		$mcqs[] = $q;
	}
}

if ( count( $shorts ) < 4 ) {
	fwrite( STDERR, "Fixture needs at least four short questions; found " . count( $shorts ) . "\n" );
	exit( 1 );
}

/* One question per rule, so a single submission exercises all four. */
$clamp_high = $shorts[0];   // model awards far above the maximum
$clamp_low  = $shorts[1];   // model awards a negative
$of_wrong   = $shorts[2];   // model disagrees about what the question is worth
$skipped    = $shorts[3];   // model omits it from results entirely

/* Every short gets a real answer, so none of them can score 0 by being blank
 * -- a 0 in the report then means a rule fired, not that we forgot to answer. */
$answers = array();
foreach ( $shorts as $q ) {
	$answers[] = array( 'id' => $q['id'], 'text' => 'A deliberate answer for adversarial grading.' );
}
foreach ( $mcqs as $q ) {
	$answers[] = array( 'id' => $q['id'], 'choice' => $q['answer_index'] );
}

/* The lie. Note what is NOT here: $skipped's id. */
$crafted = array(
	'results' => array(
		array(
			'id'      => $clamp_high['id'],
			'awarded' => 99,
			'of'      => $clamp_high['marks'],
			'comment' => 'Model claims far more than the question is worth.',
		),
		array(
			'id'      => $clamp_low['id'],
			'awarded' => -5,
			'of'      => $clamp_low['marks'],
			'comment' => 'Model claims a negative mark.',
		),
		array(
			'id'      => $of_wrong['id'],
			'awarded' => 1,
			'of'      => 7,
			'comment' => 'Model disagrees with the exam about the maximum.',
		),
		/* A hallucinated id, and an MCQ id the model has no business grading.
		 * Neither may mint marks. */
		array(
			'id'      => 999,
			'awarded' => 50,
			'of'      => 50,
			'comment' => 'Hallucinated question.',
		),
		array(
			'id'      => $mcqs[0]['id'],
			'awarded' => 50,
			'of'      => 50,
			'comment' => 'MCQ smuggled into the grading payload.',
		),
	),
);

$format = EduAI_Claude::provider()['format'];
$text   = wp_json_encode( $crafted );

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( $text, $format ) {
		if ( false === strpos( $url, '/v1/' ) ) {
			return $pre;
		}

		$body = 'openai' === $format
			? wp_json_encode( array(
				'model'   => 'crafted',
				'choices' => array( array( 'message' => array( 'content' => $text ) ) ),
				'usage'   => array( 'prompt_tokens' => 0, 'completion_tokens' => 0 ),
			) )
			: wp_json_encode( array(
				'model'        => 'crafted',
				'content'      => array( array( 'type' => 'text', 'text' => $text ) ),
				'stop_reason'  => 'end_turn',
				'usage'        => array( 'input_tokens' => 0, 'output_tokens' => 0 ),
			) );

		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

$graded = EduAI_Exams::grade( $exam, $answers );

if ( is_wp_error( $graded ) ) {
	fwrite( STDERR, 'grade() returned WP_Error: ' . $graded->get_error_message() . "\n" );
	exit( 1 );
}

$by_id = array();
foreach ( $graded['results'] as $r ) {
	$by_id[ (int) $r['id'] ] = $r;
}

/* -------------------------------------------------------------------------
 * §5.2, clause by clause
 * ---------------------------------------------------------------------- */

printf( "docs/06 §5.2 — adversarial grading, %d questions\n\n", count( $exam['questions'] ) );

// "awarded is a number in [0, of] ... The grader clamps rather than trusting
// it: a model returning awarded: 3 on a 2-mark question must not produce 150%."
$r = $by_id[ $clamp_high['id'] ] ?? array();
check(
	sprintf( 'awarded clamped to the maximum (q%d, worth %d, model said 99)', $clamp_high['id'], $clamp_high['marks'] ),
	isset( $r['awarded'] ) && (float) $r['awarded'] === (float) $clamp_high['marks'],
	'awarded came back as ' . var_export( $r['awarded'] ?? null, true )
);

$r = $by_id[ $clamp_low['id'] ] ?? array();
check(
	sprintf( 'awarded floored at zero (q%d, model said -5)', $clamp_low['id'] ),
	isset( $r['awarded'] ) && 0.0 === (float) $r['awarded'],
	'awarded came back as ' . var_export( $r['awarded'] ?? null, true )
);

// "of must equal that question's marks. On mismatch, the question's marks
// wins -- the exam is the authority, not the grading call."
$r = $by_id[ $of_wrong['id'] ] ?? array();
check(
	sprintf( 'the exam wins the "of" disagreement (q%d is worth %d, model said 7)', $of_wrong['id'], $of_wrong['marks'] ),
	isset( $r['of'] ) && (float) $r['of'] === (float) $of_wrong['marks'],
	'of came back as ' . var_export( $r['of'] ?? null, true )
);

// "A short question missing from results scores 0 and is reported to the
// student as ungraded rather than silently dropped from the total."
$r = $by_id[ $skipped['id'] ] ?? null;
check(
	sprintf( 'a short the marker skipped is still reported (q%d)', $skipped['id'] ),
	null !== $r,
	'q' . $skipped['id'] . ' is absent from results entirely'
);
check(
	sprintf( 'a short the marker skipped scores 0 (q%d)', $skipped['id'] ),
	$r && 0.0 === (float) $r['awarded'],
	'awarded came back as ' . var_export( $r['awarded'] ?? null, true )
);
check(
	sprintf( 'a short the marker skipped says so to the student (q%d)', $skipped['id'] ),
	$r && '' !== trim( (string) $r['comment'] ),
	'comment was ' . var_export( $r['comment'] ?? null, true )
);

// "An id in results that is not a short question in this exam is ignored. A
// hallucinated id must never create marks out of nothing."
check(
	'a hallucinated id creates no result row',
	! isset( $by_id[999] ),
	'id 999 appears in the report'
);

$mcq_result = $by_id[ $mcqs[0]['id'] ] ?? array();
check(
	sprintf( 'an MCQ in the grading payload cannot overwrite its mark (q%d)', $mcqs[0]['id'] ),
	isset( $mcq_result['awarded'] ) && (float) $mcq_result['awarded'] <= (float) $mcqs[0]['marks'],
	'awarded came back as ' . var_export( $mcq_result['awarded'] ?? null, true )
);

/* ---- and the arithmetic those rules exist to protect --------------------- */

$sum_awarded = 0.0;
$sum_of      = 0.0;
foreach ( $graded['results'] as $r ) {
	$sum_awarded += (float) $r['awarded'];
	$sum_of      += (float) $r['of'];
}

$paper_total = 0.0;
foreach ( $exam['questions'] as $q ) {
	$paper_total += (float) $q['marks'];
}

check(
	'score is the sum of what was awarded',
	abs( (float) $graded['score'] - $sum_awarded ) < 0.05,
	sprintf( 'score %s, awarded sums to %s', $graded['score'], $sum_awarded )
);
check(
	'total is the sum of the paper, not of what the model claimed',
	abs( (float) $graded['total'] - $paper_total ) < 0.05,
	sprintf( 'total %s, paper is worth %s', $graded['total'], $paper_total )
);
check(
	'every question still has a row',
	count( $graded['results'] ) === count( $exam['questions'] ),
	sprintf( '%d rows for %d questions', count( $graded['results'] ), count( $exam['questions'] ) )
);
check(
	'percent cannot exceed 100',
	(int) $graded['percent'] <= 100,
	'percent came back as ' . var_export( $graded['percent'], true )
);

printf( "\n%d passed, %d failed\n", $GLOBALS['eduai_pass'], $GLOBALS['eduai_fail'] );

/* A harness that cannot report a failure is worse than no harness, so refuse
 * to exit 0 on a run that somehow asserted nothing at all. */
if ( 0 === $GLOBALS['eduai_pass'] + $GLOBALS['eduai_fail'] ) {
	fwrite( STDERR, "no assertions ran — treating that as a failure\n" );
	exit( 1 );
}

exit( $GLOBALS['eduai_fail'] > 0 ? 1 : 0 );
