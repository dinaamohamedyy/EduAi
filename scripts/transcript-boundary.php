<?php
/**
 * Where is the line between "a short clip" and "a truncated lecture"?
 *
 *   docker compose --profile tools run --rm cli \
 *     wp eval-file /scripts/transcript-boundary.php --allow-root
 *
 * The gate takes TWO durations and they must stay separate:
 *
 *   heard    - Groq's own `duration`: how much audio Whisper processed
 *   recorded - the file's duration:   how long the recording actually is
 *
 * Rate (speech or noise?) is words over `heard`. Completeness (did we get the
 * whole thing?) is `heard` against `recorded`, and it CANNOT be a rate: a
 * transcriber that stops at minute four of fifty reports four minutes, so both
 * halves of the fraction shrink together and the rate stays perfect. Control 1
 * below is that case, judged with and without `recorded`, and it is the only
 * thing in this file that justifies the second argument existing.
 *
 * @package Scholaris
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run me through wp-cli, not php.\n" );
	exit( 1 );
}

/*
 * There used to be a copy of the acceptance script's private gate here, and a
 * tally of how often the two disagreed - 5 of 13 at its worst, including a
 * truncated lecture that it accepted and this refused.
 *
 * Back-end pointed that script at the shipped gate in 9163f48, so the tally has
 * nothing left to measure. Keeping the copy would have been the same defect it
 * was written to expose: a verbatim duplicate of a function that has since
 * moved, reporting confidently about a version of it that no longer exists.
 */

/**
 * Lecture prose long enough to hit a target word count, built from distinct
 * sentences rather than one repeated paragraph - a repeated paragraph trips the
 * unique-word check and would refuse for the wrong reason, which looks exactly
 * like the check under test working.
 */
function tb_lecture( $target, $offset = 0 ) {
	$pool = array(
		'Today we look at least squares regression and where it comes from.',
		'Given a design matrix X and a response vector y, the residual is r equals y minus X w.',
		'Setting the gradient of the squared error to zero yields the normal equations.',
		'Those are X transpose X w equals X transpose y, and we solve them directly.',
		'The Gram matrix must be invertible, which fails exactly when two features are collinear.',
		'Ridge regularisation adds lambda times the identity and restores invertibility.',
		'Geometrically the fitted values are an orthogonal projection onto the column space.',
		'That projection explains why residuals are perpendicular to every predictor.',
		'Maximum likelihood under Gaussian noise recovers precisely the same estimator.',
		'Next week we relax the Gaussian assumption and arrive at generalised linear models.',
		'Notice the estimator is unbiased whenever errors have zero conditional mean.',
		'Its variance is sigma squared times the inverse Gram matrix, which bounds precision.',
		'Collinear predictors inflate that variance without changing any single prediction.',
		'Cross validation gives an honest estimate of error on unseen observations.',
		'A held out split is the cheapest defence against fitting noise instead of signal.',
	);

	/*
	 * Each sentence carries terms nothing else uses. Without them this cycles
	 * fifteen sentences and a six thousand word "lecture" holds a hundred and
	 * fifty distinct words - a unique-word ratio of 0.02, which is what a
	 * Whisper LOOP looks like. Every ratio measured against such a fixture
	 * describes the fixture, and a control built on it reports the truth about
	 * nothing. Real lecture prose runs near 0.2 and this is built to match.
	 */
	$out   = array();
	$words = 0;
	$i     = 0;
	while ( $words < $target ) {
		$s      = sprintf( '%s We return to lemma%d, corollary%d and figure%d shortly.', $pool[ $i % count( $pool ) ], $offset + $i, $offset + $i, $offset + $i );
		$out[]  = $s;
		$words += count( preg_split( '/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY ) );
		$i++;
	}
	return implode( ' ', $out );
}

/**
 * Share of distinct words across a whole string - the measure the repetition
 * check used before it was windowed.
 */
function tb_global_variety( $text ) {
	$w = preg_split( '/\s+/u', preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text ), -1, PREG_SPLIT_NO_EMPTY );
	return count( array_unique( array_map( 'mb_strtolower', $w ) ) ) / max( 1, count( $w ) );
}

$clip     = 'In this short clip we introduce the idea of a residual in linear regression.';   // 14 words.
$four_min = tb_lecture( 500 );    // ~4 minutes of real speech at 125 wpm.
$full     = tb_lecture( 6000 );   // ~50 minutes of real speech.

$cases = array(
	// label, text, heard, recorded, expect.
	array( 'Wikimedia clip 6.1s', $clip, 6.104, 6.0, 'pass' ),
	array( 'owner 30s intro clip', $clip, 30.0, 30.0, 'pass' ),
	array( 'TRUNCATED: 4 min heard, 50 min file', $four_min, 240.0, 3000.0, 'refuse' ),
	array( '   ...same, recorded UNKNOWN', $four_min, 240.0, null, 'pass' ),
	array( 'complete 50 min lecture', $full, 3000.0, 3000.0, 'pass' ),
	array( 'clip, container 2s longer than audio', $clip, 6.104, 8.0, 'pass' ),
	array( '40s clip, 6 words', 'Welcome back to lecture three everyone.', 40.0, 40.0, 'refuse' ),
	array( 'silence: 19 words over 50 min', $clip . ' And that is all.', 3000.0, 3000.0, 'refuse' ),
	array( 'silence artefact " ."', ' .', 6.0, 6.0, 'refuse' ),
	array( 'thanks-for-watching', 'Thanks for watching. Subtitles by the Amara.org community.', 6.0, 6.0, 'refuse' ),
	array( 'whisper loop', str_repeat( 'the ', 200 ), 120.0, 120.0, 'refuse' ),
	array( 'empty', '', 6.0, 6.0, 'refuse' ),
	array( 'clip, no durations at all', $clip, null, null, 'pass' ),
);

$pass = 0;
$fail = 0;

printf( "%-38s %8s %9s %9s  %-7s %s\n", 'case', 'heard', 'recorded', 'complete', 'guard', 'code' );
printf( "%s\n", str_repeat( '-', 110 ) );

foreach ( $cases as $c ) {
	list( $label, $text, $heard, $recorded, $expect ) = $c;

	$verdict = EduAI_Transcript_Guard::usable( $text, $heard, $recorded );
	$got     = is_wp_error( $verdict ) ? 'refuse' : 'pass';
	$code    = is_wp_error( $verdict ) ? $verdict->get_error_code() : '';
	$checked = EduAI_Transcript_Guard::completeness_checked( $heard, $recorded ) ? 'checked' : 'unknown';

	$ok = ( $got === $expect );
	$ok ? $pass++ : $fail++;

	printf(
		"%-38s %8s %9s %9s  %-7s %s%s\n",
		$label,
		null === $heard ? '-' : sprintf( '%.1fs', $heard ),
		null === $recorded ? '-' : sprintf( '%.1fs', $recorded ),
		$checked,
		$got,
		$code,
		$ok ? '' : sprintf( '   <-- EXPECTED %s', $expect )
	);
}

printf( "\n" );

/*
 * CONTROL 1 - the second duration earns its place.
 *
 * The same four minutes of genuine lecture, from the same fifty minute file,
 * judged with and without the file's own duration. Words per minute over what
 * Whisper heard is 125: an ordinary speaking rate that no rate check, however
 * well tuned, will ever call suspicious. If both verdicts agree then `recorded`
 * is decorative and the argument should be removed rather than defended.
 */
$blind   = EduAI_Transcript_Guard::usable( $four_min, 240.0, null );
$sighted = EduAI_Transcript_Guard::usable( $four_min, 240.0, 3000.0 );

if ( is_wp_error( $blind ) === is_wp_error( $sighted ) ) {
	$fail++;
	printf( "FAIL  truncation judged the same with and without the file duration - the second argument does nothing\n" );
} else {
	$pass++;
	printf( "ok    truncation is INVISIBLE without the file duration and caught with it\n" );
	printf( "        heard alone : %s\n", is_wp_error( $blind ) ? $blind->get_error_code() : 'pass  (125 wpm, a perfectly healthy rate)' );
	printf( "        with file   : %s\n", is_wp_error( $sighted ) ? $sighted->get_error_message() : 'pass' );
}

/*
 * CONTROL 2 - the gate reads the recording and not only the text. Identical
 * words, two durations, opposite verdicts.
 */
$short = EduAI_Transcript_Guard::usable( $clip, 6.104, 6.0 );
$long  = EduAI_Transcript_Guard::usable( $clip, 3000.0, 3000.0 );

if ( is_wp_error( $short ) === is_wp_error( $long ) ) {
	$fail++;
	printf( "FAIL  identical text at 6s and 50min, same verdict - duration is not being read\n" );
} else {
	$pass++;
	printf( "ok    identical text at 6s and 50min, opposite verdicts\n" );
}

/*
 * CONTROL 3 - completeness_checked() must not claim a check that did not run.
 */
if ( EduAI_Transcript_Guard::completeness_checked( 240.0, null ) ) {
	$fail++;
	printf( "FAIL  completeness_checked() says checked with no file duration to check against\n" );
} else {
	$pass++;
	printf( "ok    completeness_checked() reports unknown when the file duration is missing\n" );
}

/*
 * CONTROL 4 - the repetition window earns its place.
 *
 * Two minutes of Whisper looping buried inside forty-eight good minutes. This
 * is what a partial loop looks like, and it is the common form: the transcriber
 * loses the signal for a stretch and recovers. The GLOBAL unique-word ratio for
 * this exact text is printed below, and it is comfortably healthy - the good
 * speech either side averages the loop away completely. If the windowed check
 * does not catch this, the window is decoration and the old ratio was fine.
 */
$loop_words = str_repeat( 'the signal is lost ', 250 );
$buried     = tb_lecture( 3000 ) . ' ' . $loop_words . ' ' . tb_lecture( 3000, 100000 );   // Distinct terms either side: two halves sharing a numbering share a vocabulary.
$global     = tb_global_variety( $buried );
$verdict    = EduAI_Transcript_Guard::usable( $buried, 3000.0, 3000.0 );
$caught     = is_wp_error( $verdict ) && 'eduai_transcript_repetitive' === $verdict->get_error_code();

/*
 * The premise, asserted rather than assumed. If the global ratio for this text
 * is already under the old threshold then a whole-transcript check catches it
 * too, the window has proved nothing, and the only thing this control has
 * really measured is that the fixture's vocabulary is too thin. That is how it
 * read on first run: 0.019, from a lecture that recycled fifteen sentences.
 */
if ( $global < 0.12 ) {
	$fail++;
	printf( "FAIL  control 4 is void: the fixture's own global ratio is %.3f, so a whole-transcript check\n", $global );
	printf( "        would refuse it with no loop present. The fixture is repetitive, not the loop.\n" );
} elseif ( ! $caught ) {
	$fail++;
	printf( "FAIL  a 1000-word loop buried in a real lecture was NOT caught (global ratio %.3f)\n", $global );
} else {
	$pass++;
	printf( "ok    a loop buried inside a good lecture is caught by the window\n" );
	printf( "        global ratio %.3f, above the 0.12 a whole-transcript check uses - it would have passed this\n", $global );
	printf( "        worst window is what refused it: %s\n", $verdict->get_error_code() );
}

/*
 * CONTROL 5 - no refusal may assert a check that did not run.
 *
 * The truncation code is a claim about two independent durations. If it can
 * ever be returned when only one of them was available, the code is lying
 * about its own evidence. Checked as an invariant over every case above rather
 * than on the one input that happens to exercise it.
 */
$liars = array();
foreach ( $cases as $c ) {
	$v = EduAI_Transcript_Guard::usable( $c[1], $c[2], $c[3] );
	if ( is_wp_error( $v ) && 'eduai_transcript_truncated' === $v->get_error_code()
		&& ! EduAI_Transcript_Guard::completeness_checked( $c[2], $c[3] ) ) {
		$liars[] = $c[0];
	}
}

if ( $liars ) {
	$fail++;
	printf( "FAIL  truncation claimed where completeness was never checked: %s\n", implode( ', ', $liars ) );
} else {
	$pass++;
	printf( "ok    the truncation verdict is only ever reached with both durations in hand\n" );
}

/*
 * And the wording, because the code being right does not make the sentence
 * right. A sparse-audio refusal used to read "most of it is missing from the
 * transcript" - a truncation claim, in a branch that runs on Whisper's own
 * self-reported span whether or not the truncation check ran at all. The owner
 * reads the sentence, not the code.
 */
$sparse = EduAI_Transcript_Guard::usable( 'One two three four five six seven eight.', 600.0, null );

if ( ! is_wp_error( $sparse ) ) {
	$fail++;
	printf( "FAIL  eight words over ten minutes of audio was accepted\n" );
} elseif ( false !== stripos( $sparse->get_error_message(), 'missing' ) ) {
	$fail++;
	printf( "FAIL  a sparse-audio refusal with no file duration says something is MISSING: %s\n", $sparse->get_error_message() );
} else {
	$pass++;
	printf( "ok    sparse audio with no file duration reports quiet audio, not a missing transcript\n" );
	printf( "        %s\n", $sparse->get_error_message() );
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
