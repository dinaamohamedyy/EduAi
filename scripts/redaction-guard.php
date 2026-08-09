<?php
/**
 * Asserts a student cannot read the answers out of the exam they are about to sit.
 *
 *     php scripts/redaction-guard.php
 *
 * PrepareME stores the correct option, the expected short answer and the
 * explanation alongside each question. Everything the student receives before
 * marking goes through EduAI_Exams::redact() first. If that ever stops stripping
 * one of those fields, the exam is still perfectly functional and every other
 * test still passes — the answers are simply sitting in the JSON the browser was
 * handed. Nobody reports that bug; they just score unusually well.
 *
 * The check is behavioural rather than a grep, because redact() is an allowlist:
 * it builds a fresh array of permitted keys instead of unsetting forbidden ones.
 * Grepping for `unset( $q['answer_index'] )` would assert an implementation that
 * does not exist and pass for the wrong reason. So this runs the real function
 * over the real fixture and reads what comes out.
 *
 * It also asserts the input *contained* the secrets in the first place. Redacting
 * a question set that never had an answer_index proves nothing, and a guard that
 * passes vacuously is worse than no guard because it reads as coverage.
 *
 * @package EduAI
 */

// Load-bearing, despite looking like a stray constant with no reader here.
// class-eduai-exams.php opens with `defined( 'ABSPATH' ) || exit;` — the
// standard WordPress direct-access guard — so without this define the require
// below exits silently and the whole check passes having tested nothing. The
// value is irrelevant; only its existence is. Deleting this as "unused" turns
// the guard vacuous rather than breaking it, which is the dangerous direction.
// (The one `ABSPATH .` use in that file sits inside a method, so nothing here
// ever touches a real WordPress path.)
define( 'ABSPATH', __DIR__ );

// Tripwire against this guard passing by not running. Verified by execution:
// with the define above removed, the require below hits `exit;` and terminates
// the whole script with status 0, printing nothing — CI reads only the exit
// code, so the step stays green forever while testing nothing at all. Nothing
// placed after the require can detect that, because nothing after it runs; the
// flag has to be armed first. Legitimate exits below all happen once $loaded is
// true, so they keep their own status codes.
$loaded = false;
register_shutdown_function(
	static function () use ( &$loaded ) {
		if ( ! $loaded ) {
			fwrite(
				STDERR,
				"redaction guard never loaded EduAI_Exams and exited early — almost certainly the\n"
					. "`defined( 'ABSPATH' ) || exit;` line in class-eduai-exams.php. Failing rather than\n"
					. "reporting success: a check that did not run must never look like one that passed.\n"
			);
			exit( 3 );
		}
	}
);

require_once __DIR__ . '/../wp-content/plugins/eduai-assistant/includes/class-eduai-exams.php';

$loaded = true;

/** Fields that must never reach the student before marking. */
const FORBIDDEN = array( 'answer_index', 'expected', 'explanation' );

/** Fields the answer form needs, so over-redacting fails too. */
const REQUIRED = array( 'id', 'band', 'type', 'question', 'marks' );

$fixture = __DIR__ . '/../wp-content/plugins/eduai-assistant/fixtures/exam-sample.json';

if ( ! is_readable( $fixture ) ) {
	fwrite( STDERR, "fixtures/exam-sample.json is missing\n" );
	exit( 2 );
}

$exam = json_decode( (string) file_get_contents( $fixture ), true );

if ( ! is_array( $exam ) || empty( $exam['questions'] ) ) {
	fwrite( STDERR, "fixtures/exam-sample.json has no questions to redact\n" );
	exit( 2 );
}

$questions = $exam['questions'];
$problems  = array();

// ---------------------------------------------------------------- anti-vacuity
//
// Every forbidden field has to be present somewhere in the input, or stripping
// it is not being tested at all.
foreach ( FORBIDDEN as $field ) {
	$present = false;
	foreach ( $questions as $q ) {
		if ( array_key_exists( $field, $q ) ) {
			$present = true;
			break;
		}
	}
	if ( ! $present ) {
		$problems[] = "fixture contains no '$field' — redacting it cannot be tested, so this guard would pass vacuously";
	}
}

// ------------------------------------------------------------------- behaviour

$redacted = EduAI_Exams::redact( $questions );

if ( count( $redacted ) !== count( $questions ) ) {
	$problems[] = sprintf(
		'redact() returned %d questions from %d — the student would sit a different exam',
		count( $redacted ),
		count( $questions )
	);
}

/**
 * Walk anything nested, so a secret hidden inside options or a sub-array is
 * still caught.
 *
 * @param mixed  $node  Value to inspect.
 * @param string $path  Where we are, for the message.
 * @param array  $found Collected hits, by reference.
 */
function scan( $node, string $path, array &$found ): void {
	if ( ! is_array( $node ) ) {
		return;
	}

	foreach ( $node as $key => $value ) {
		$here = '' === $path ? (string) $key : $path . '.' . $key;

		if ( is_string( $key ) && in_array( $key, FORBIDDEN, true ) ) {
			$found[] = $here;
		}

		scan( $value, $here, $found );
	}
}

$leaked = array();
scan( $redacted, '', $leaked );

foreach ( $leaked as $where ) {
	$problems[] = "redacted output still carries a secret at: $where";
}

// The form has to remain renderable — an over-zealous redaction that drops the
// question text is also a bug, just a louder one.
foreach ( $redacted as $i => $q ) {
	foreach ( REQUIRED as $field ) {
		if ( ! array_key_exists( $field, $q ) ) {
			$problems[] = sprintf( 'question %d lost required field %s', $i + 1, $field );
		}
	}

	if ( 'mcq' === ( $q['type'] ?? '' ) ) {
		if ( empty( $q['options'] ) || ! is_array( $q['options'] ) || 4 !== count( $q['options'] ) ) {
			$problems[] = sprintf( 'MCQ %d lost its four options — unanswerable', $i + 1 );
		}
	}
}

// ---------------------------------------------------------------------- report

if ( $problems ) {
	fwrite( STDERR, "redaction guard FAILED:\n" );
	foreach ( $problems as $p ) {
		fwrite( STDERR, "  - $p\n" );
	}
	exit( 1 );
}

printf(
	"redaction guard: %d questions redacted, no answer_index / expected / explanation reaches the student\n",
	count( $redacted )
);
exit( 0 );
