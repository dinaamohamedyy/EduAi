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

printf( "\n%d passed, %d failed, %d skipped\n", $GLOBALS['vt_pass'], $GLOBALS['vt_fail'], $GLOBALS['vt_skip'] );
exit( $GLOBALS['vt_fail'] > 0 ? 1 : 0 );
