<?php
/**
 * Acceptance test: "summarise, PrepareME and Q&A work on video lessons".
 *
 *   docker compose --profile tools run --rm cli \
 *     wp eval-file /scripts/video-transcript-accept.php <lesson_id> --allow-root
 *
 * The owner's criteria, tested AS A STUDENT. Administrators get access to every
 * LearnDash course automatically, so an admin run cannot distinguish "this works"
 * from "I am an admin" - which is exactly what made everyone believe dina was
 * enrolled when no enrolment record exists.
 *
 * THE THING THIS FILE EXISTS TO PREVENT
 *
 * A silent video transcribes to " ." and a language model will write fluent,
 * confident study notes from it. Every surface then looks like it works. So the
 * transcript is gated BEFORE anything downstream is believed, and the gate is a
 * control that must fail on known-bad input rather than a threshold someone
 * eyeballed.
 *
 * @package Scholaris
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run me through wp-cli, not php.\n" );
	exit( 1 );
}

$GLOBALS['vt_pass'] = 0;
$GLOBALS['vt_fail'] = 0;
$GLOBALS['vt_skip'] = 0;

function vt_ok( $rule, $detail = '' ) {
	$GLOBALS['vt_pass']++;
	printf( "ok    %s%s\n", $rule, $detail ? "  ($detail)" : '' );
}
function vt_bad( $rule, $detail ) {
	$GLOBALS['vt_fail']++;
	printf( "FAIL  %s\n        %s\n", $rule, $detail );
}
function vt_skip( $rule, $why ) {
	$GLOBALS['vt_skip']++;
	printf( "skip  %s\n        %s\n", $rule, $why );
}

/**
 * Is this string a real transcript, or the noise a silent clip produces?
 *
 * Whisper on silence emits " .", " you", "Thanks for watching." and similar -
 * short, punctuation-heavy, low-vocabulary. Judged on DISTINCT WORDS rather than
 * length, because a padded or repeated phrase passes a length check.
 */
function vt_is_real_transcript( $t ) {
	$t     = trim( (string) $t );
	$words = preg_split( '/\W+/u', strtolower( $t ), -1, PREG_SPLIT_NO_EMPTY );
	$uniq  = array_unique( $words );
	return array(
		'chars'    => strlen( $t ),
		'words'    => count( $words ),
		'distinct' => count( $uniq ),
		'real'     => strlen( $t ) >= 200 && count( $words ) >= 40 && count( $uniq ) >= 25,
	);
}

/* ---- control: the transcript detector must reject known-bad input --------- */

$known_bad = array(
	'silence (Whisper idle output)' => ' .',
	'thanks-for-watching artefact'  => 'Thanks for watching.',
	'single repeated word'          => str_repeat( 'the ', 200 ),
	'empty'                         => '',
);

$control_ok = true;
foreach ( $known_bad as $label => $sample ) {
	$r = vt_is_real_transcript( $sample );
	if ( $r['real'] ) {
		$control_ok = false;
		vt_bad( "control: transcript gate rejects $label", 'it ACCEPTED it - the gate cannot protect anything' );
	}
}

$good = vt_is_real_transcript(
	'Today we look at least squares regression. Given a design matrix X and a response vector y, '
	. 'the residual is defined as r equals y minus X w. Setting the gradient of the squared error '
	. 'to zero yields the normal equations, X transpose X w equals X transpose y. We then discuss '
	. 'why the Gram matrix must be invertible and what happens when features are collinear.'
);
if ( ! $good['real'] ) {
	$control_ok = false;
	vt_bad( 'control: transcript gate ACCEPTS a genuine transcript', 'it rejected real lecture text - the gate is too strict to be usable' );
}

if ( $control_ok ) {
	vt_ok( 'control: the transcript gate rejects silence and accepts real speech' );
} else {
	printf( "\nthe gate itself is wrong; every result below would be meaningless. stopping.\n" );
	printf( "\n%d passed, %d failed\n", $GLOBALS['vt_pass'], $GLOBALS['vt_fail'] );
	exit( 1 );
}

/* ---- the lesson under test ------------------------------------------------ */

$lesson_id = isset( $args[0] ) ? (int) $args[0] : 0;

if ( ! $lesson_id ) {
	fwrite( STDERR, "usage: wp eval-file /scripts/video-transcript-accept.php <lesson_id>\n" );
	exit( 1 );
}

$lesson = get_post( $lesson_id );

if ( ! $lesson ) {
	fwrite( STDERR, "no such post: $lesson_id\n" );
	exit( 1 );
}

printf( "\nlesson #%d  %s  (%s, %s)\n", $lesson_id, $lesson->post_title, $lesson->post_type, $lesson->post_status );

/* ---- is there a transcript at all, and is it real? ------------------------ */

$transcript = '';
foreach ( array( '_eduai_transcript', '_scholaris_transcript', 'eduai_transcript', '_transcript' ) as $key ) {
	$v = get_post_meta( $lesson_id, $key, true );
	if ( is_string( $v ) && '' !== trim( $v ) ) {
		$transcript = $v;
		printf( "transcript meta key: %s\n", $key );
		break;
	}
}

if ( '' === $transcript ) {
	vt_bad( 'the lesson has a transcript stored', 'no transcript found under any known meta key - the feature has not landed, or it did not run for this lesson' );
	printf( "\n%d passed, %d failed, %d skipped\n", $GLOBALS['vt_pass'], $GLOBALS['vt_fail'], $GLOBALS['vt_skip'] );
	exit( 1 );
}

$stat = vt_is_real_transcript( $transcript );
printf( "transcript: %d chars, %d words, %d distinct\n", $stat['chars'], $stat['words'], $stat['distinct'] );
printf( "  opening: %s\n", substr( preg_replace( '/\s+/', ' ', $transcript ), 0, 140 ) );

if ( $stat['real'] ) {
	vt_ok( 'the transcript is real speech, not silence noise', sprintf( '%d distinct words', $stat['distinct'] ) );
} else {
	vt_bad(
		'the transcript is real speech, not silence noise',
		sprintf(
			'%d chars / %d words / %d distinct - this is what a SILENT clip produces. Everything downstream would be fluent text generated from nothing, so the rest is not run.',
			$stat['chars'],
			$stat['words'],
			$stat['distinct']
		)
	);
	printf( "\n%d passed, %d failed, %d skipped\n", $GLOBALS['vt_pass'], $GLOBALS['vt_fail'], $GLOBALS['vt_skip'] );
	exit( 1 );
}

/* ---- did the transcript reach retrieval? ---------------------------------- */

global $wpdb;
$chunks = $wpdb->get_col( $wpdb->prepare( "SELECT chunk_text FROM {$wpdb->prefix}eduai_chunks WHERE post_id = %d", $lesson_id ) );
$indexed = implode( ' ', $chunks );

if ( ! $chunks ) {
	vt_bad( 'the transcript is indexed for retrieval', 'zero chunks for this lesson - Q&A and Summarise cannot see it however good the transcript is' );
} else {
	/* A phrase from the middle of the transcript, so a title-only index cannot satisfy it. */
	$words = preg_split( '/\s+/', trim( $transcript ), -1, PREG_SPLIT_NO_EMPTY );
	$probe = implode( ' ', array_slice( $words, (int) ( count( $words ) / 2 ), 6 ) );

	if ( false !== stripos( $indexed, $probe ) ) {
		vt_ok( 'the transcript body is in the retrieval index', sprintf( '%d chunks', count( $chunks ) ) );
	} else {
		vt_bad( 'the transcript body is in the retrieval index', sprintf( '%d chunks exist but a mid-transcript phrase ("%s") is absent - only the title or page text was indexed', count( $chunks ), $probe ) );
	}
}


/* ---- term fidelity: did the technical term survive transcription? --------- */

/*
 * THE HIGHEST-VALUE CHECK IN THIS FILE, AND THE ONE THAT CANNOT BE STUBBED.
 *
 * Whisper mishears technical vocabulary confidently - "Ogg Vorbis" came back as
 * "org-forbus" on this project. A missing feature announces itself; a WRONG term
 * is taught to a student as fact, appears in their summary, their exam questions
 * and their revision notes, and nothing anywhere flags it.
 *
 * It takes the expected term as a PARAMETER and refuses to run without one,
 * because a stub here is worse than an absence: it would report a green for the
 * one property standing between a mangled term and a student learning it.
 *
 * Exit code 2, not 1, when unset. A red that means "the product is broken" and a
 * red that means "this test could not run" must not be the same red - that is
 * how a guard becomes furniture people learn to ignore.
 */

$term = isset( $args[1] ) ? trim( (string) $args[1] ) : '';

if ( '' === $term ) {
	printf( "\n" );
	printf( "UNVERIFIED  term fidelity was not checked\n" );
	printf( "        No expected term was given, so nothing asserts that technical vocabulary\n" );
	printf( "        survived transcription. Whisper mishears terms CONFIDENTLY - a wrong term\n" );
	printf( "        reaches the student as fact and nothing else in this system will notice.\n" );
	printf( "        Run again with a word you know is spoken in the video:\n" );
	printf( "          wp eval-file /scripts/video-transcript-accept.php %d \"Ogg Vorbis\"\n", $lesson_id );
	printf( "\n%d passed, %d failed, %d skipped, term fidelity UNVERIFIED\n", $GLOBALS['vt_pass'], $GLOBALS['vt_fail'], $GLOBALS['vt_skip'] );
	exit( $GLOBALS['vt_fail'] > 0 ? 1 : 2 );
}

$haystacks = array(
	'transcript'      => $transcript,
	'retrieval index' => $indexed,
);

$missing_from = array();

foreach ( $haystacks as $where => $hay ) {
	if ( '' === trim( (string) $hay ) ) {
		continue;
	}
	if ( false !== stripos( $hay, $term ) ) {
		vt_ok( sprintf( '"%s" survived into the %s', $term, $where ) );
	} else {
		$missing_from[ $where ] = $hay;
	}
}

/*
 * When the term is absent, say what it BECAME. "not found" sends someone
 * hunting a missing feature; "closest match: org-forbus" hands them the defect.
 * Compared on the first word of the term, because a mangled multi-word phrase
 * rarely keeps its spacing.
 */
foreach ( $missing_from as $where => $hay ) {
	/*
	 * Compare against the WHOLE term with separators stripped, over sliding
	 * windows of one to three tokens - not against the term's first word.
	 *
	 * The first-word version was wrong in the way that matters: hunting "Ogg"
	 * it reported the closest word as "of" (distance 2) while the actual
	 * corruption "org-forbus" sat in the text, excluded by a length filter for
	 * being seven characters longer than "Ogg". A confident wrong lead is worse
	 * than none - it sends the reader after a common English word. Normalised:
	 * "oggvorbis" vs "orgforbus" is distance 3 of 9, and "of" is 7 of 9.
	 */
	$norm  = static fn( $s ) => strtolower( preg_replace( '/[^\p{L}\p{N}]+/u', '', (string) $s ) );
	$want  = $norm( $term );
	$toks  = preg_split( '/[^\p{L}\p{N}\-]+/u', (string) $hay, -1, PREG_SPLIT_NO_EMPTY );

	$best   = '';
	$best_d = PHP_INT_MAX;

	for ( $i = 0; $i < count( $toks ); $i++ ) {
		for ( $n = 1; $n <= 3 && $i + $n <= count( $toks ); $n++ ) {
			$window = array_slice( $toks, $i, $n );
			$cand   = $norm( implode( '', $window ) );

			if ( '' === $cand || abs( strlen( $cand ) - strlen( $want ) ) > max( 3, (int) ( strlen( $want ) / 2 ) ) ) {
				continue;
			}

			$d = levenshtein( $want, $cand );
			if ( $d < $best_d ) {
				$best_d = $d;
				$best   = implode( ' ', $window );
			}
		}
	}

	/* Only volunteer a suspect if it is genuinely close; otherwise say so. */
	$close = '' !== $best && $best_d <= (int) floor( strlen( $want ) * 0.5 );

	vt_bad(
		sprintf( '"%s" survived into the %s', $term, $where ),
		$close
			? sprintf( 'absent. Closest text present is "%s" (edit distance %d of %d) - that is most likely what the term became.', $best, $best_d, strlen( $want ) )
			: 'absent, and nothing in the text resembles it - the term may never have been spoken, or the audio never reached the transcriber.'
	);
}

printf( "\n%d passed, %d failed, %d skipped\n", $GLOBALS['vt_pass'], $GLOBALS['vt_fail'], $GLOBALS['vt_skip'] );
exit( $GLOBALS['vt_fail'] > 0 ? 1 : 0 );
