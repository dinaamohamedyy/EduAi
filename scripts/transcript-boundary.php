<?php
/**
 * Where is the line between "a short clip" and "a truncated lecture"?
 *
 *   docker compose --profile tools run --rm cli \
 *     wp eval-file /scripts/transcript-boundary.php --allow-root
 *
 * TM ruled that the floor keys on duration where duration is known. This is the
 * evidence for that ruling, and the reason it is a file rather than a number in
 * a message: the ruling is only correct if the SAME transcript gets DIFFERENT
 * verdicts at different durations. If it does not, nothing has been keyed on
 * duration and a threshold was merely lowered.
 *
 * It also runs every case through the acceptance script's own gate, because
 * that is a second implementation of this judgement and the two disagreeing is
 * the actual defect the Wikimedia clip found.
 *
 * @package Scholaris
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run me through wp-cli, not php.\n" );
	exit( 1 );
}

// The acceptance script's private gate, copied verbatim from
// scripts/video-transcript-accept.php so the comparison is honest.
function tb_accept_gate( $t ) {
	$t     = trim( (string) $t );
	$words = preg_split( '/\W+/u', strtolower( $t ), -1, PREG_SPLIT_NO_EMPTY );
	return strlen( $t ) >= 200 && count( $words ) >= 40 && count( array_unique( $words ) ) >= 25;
}

$clip = 'In this short clip we introduce the idea of a residual in linear regression.';   // 14 words
$lect = 'Today we look at least squares regression. Given a design matrix X and a response '
	. 'vector y, the residual is defined as r equals y minus X w. Setting the gradient of '
	. 'the squared error to zero yields the normal equations, X transpose X w equals X '
	. 'transpose y. We then discuss why the Gram matrix must be invertible and what happens '
	. 'when features are collinear, and how ridge regularisation restores invertibility.';

$cases = array(
	// label,                                   text,                 seconds,  expect
	array( 'Wikimedia clip, 6.1s',              $clip,                6.104,    'pass' ),
	array( 'SAME 14 words, 50 minutes of audio', $clip,               3000.0,   'refuse' ),
	array( 'SAME 14 words, duration unknown',   $clip,                null,     'pass' ),
	array( 'owner 30s intro clip',              $clip,                30.0,     'pass' ),
	array( '40s clip, 6 words',                 'Welcome back to lecture three everyone.', 40.0, 'refuse' ),
	array( 'lecture, 40s of audio',             $lect,                40.0,     'pass' ),
	array( 'lecture truncated: 4 of 50 min',    $lect,                3000.0,   'refuse' ),
	array( 'silence artefact " ."',             ' .',                 6.0,      'refuse' ),
	array( 'thanks-for-watching',               'Thanks for watching. Subtitles by the Amara.org community.', 6.0, 'refuse' ),
	array( 'whisper loop',                      str_repeat( 'the ', 200 ), 120.0, 'refuse' ),
	array( 'empty',                             '',                   6.0,      'refuse' ),
);

$pass = 0;
$fail = 0;

printf( "%-36s %9s %8s  %-8s %-8s %s\n", 'case', 'duration', 'complete', 'guard', 'accept', 'code' );
printf( "%s\n", str_repeat( '-', 96 ) );

foreach ( $cases as $c ) {
	list( $label, $text, $seconds, $expect ) = $c;

	$verdict = EduAI_Transcript_Guard::usable( $text, $seconds );
	$got     = is_wp_error( $verdict ) ? 'refuse' : 'pass';
	$code    = is_wp_error( $verdict ) ? $verdict->get_error_code() : '';
	$checked = EduAI_Transcript_Guard::completeness_checked( $seconds ) ? 'checked' : 'unknown';
	$accept  = tb_accept_gate( $text ) ? 'pass' : 'refuse';

	$ok = ( $got === $expect );
	$ok ? $pass++ : $fail++;

	printf(
		"%-36s %9s %8s  %-8s %-8s %s%s\n",
		$label,
		null === $seconds ? '-' : sprintf( '%.1fs', $seconds ),
		$checked,
		$got,
		$accept,
		$code,
		$ok ? '' : sprintf( '   <-- EXPECTED %s', $expect )
	);
}

/*
 * The control on the control. Two rows above carry identical text and differ
 * only in the duration handed to the gate. If their verdicts match, the gate is
 * reading the transcript and ignoring the recording, and every claim made about
 * this change is unsupported however green the rest of the table is.
 */
printf( "\n" );
$short = EduAI_Transcript_Guard::usable( $clip, 6.104 );
$long  = EduAI_Transcript_Guard::usable( $clip, 3000.0 );

if ( is_wp_error( $short ) === is_wp_error( $long ) ) {
	$fail++;
	printf( "FAIL  identical text, 6s vs 50min, SAME verdict - duration is not being read\n" );
} else {
	$pass++;
	printf( "ok    identical text, 6s vs 50min, opposite verdicts - the gate reads the recording\n" );
	printf( "        50min: %s\n", $long->get_error_message() );
}

// Where the two implementations disagree, only one of them is what ships.
$diverge = 0;
foreach ( $cases as $c ) {
	$g = ! is_wp_error( EduAI_Transcript_Guard::usable( $c[1], $c[2] ) );
	if ( $g !== tb_accept_gate( $c[1] ) ) {
		$diverge++;
	}
}
printf( "\n%d of %d cases judged differently by the shipped gate and the acceptance script's own.\n", $diverge, count( $cases ) );
printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
