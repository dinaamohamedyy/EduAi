<?php
/**
 * Does EduAI_LMS name things correctly for each LMS — including the one that
 * is not installed yet?
 *
 *     php scripts/lms-seam.php
 *
 * Needs no WordPress and no database. It loads the real class file with the
 * handful of WordPress functions it touches stubbed, and drives each provider
 * in its own process, because provider() memoises and one process can only ever
 * answer for one LMS.
 *
 * WHY THIS EXISTS. The Tutor → LearnDash conversion cannot be verified after
 * the fact — by then the origin is gone and there is nothing to compare
 * against. This asserts the LearnDash branch resolves correctly BEFORE the
 * plugin is installed, so the migration starts from a seam already known to be
 * right rather than one whose first test is the migration itself.
 *
 * It also pins the failure direction. With no LMS the getters return '' and
 * can_access() returns false. Both matter: '' passed to WP_Query is not "no
 * results", it is "the default post type", and an access check that cannot
 * determine access must refuse rather than allow.
 *
 * @package EduAI
 */

$provider = $argv[1] ?? 'all';

if ( 'all' === $provider ) {
	// Each provider in a clean process — see the memoisation note above.
	$fail = 0;
	foreach ( array( 'none', 'tutor', 'learndash', 'learndash-nofunc' ) as $p ) {
		passthru( sprintf( '%s %s %s', escapeshellarg( PHP_BINARY ), escapeshellarg( __FILE__ ), escapeshellarg( $p ) ), $rc );
		$fail += ( 0 === $rc ) ? 0 : 1;
	}
	printf( "\n%s\n", $fail ? "$fail provider(s) failed" : 'lms seam: every provider resolves correctly' );
	exit( $fail ? 1 : 0 );
}

/* ------------------------------------------------------------------ stubs -- */

define( 'ABSPATH', __DIR__ );

$GLOBALS['stub_registered'] = array();

function post_type_exists( $t ) {  // phpcs:ignore
	return in_array( $t, $GLOBALS['stub_registered'], true );
}
function get_post( $p = null ) { return $p; }            // phpcs:ignore
function get_current_user_id() { return 0; }             // phpcs:ignore
function get_post_meta( $id, $key, $single = false ) { return ''; }  // phpcs:ignore
function wp_get_post_parent_id( $id ) { return 0; }      // phpcs:ignore

switch ( $provider ) {
	case 'tutor':
		// Tutor exposes its post types as properties on tutor().
		function tutor() {  // phpcs:ignore
			return (object) array(
				'course_post_type' => 'courses',
				'topics_post_type' => 'topics',
				'lesson_post_type' => 'lesson',
				'quiz_post_type'   => 'tutor_quiz',
			);
		}
		$GLOBALS['stub_registered'] = array( 'courses', 'topics', 'lesson' );
		$expect = array( 'tutor', 'courses', 'topics', 'lesson', 'tutor_quiz' );
		break;

	case 'learndash':
		// The documented API. Deliberately returns LearnDash's real slugs so a
		// mismatch between our expectation and theirs shows up here rather than
		// during the migration.
		function learndash_get_post_type_slug( $key ) {  // phpcs:ignore
			$map = array(
				'course' => 'sfwd-courses',
				'topic'  => 'sfwd-topic',
				'lesson' => 'sfwd-lessons',
				'quiz'   => 'sfwd-quiz',
			);
			return $map[ $key ] ?? '';
		}
		$GLOBALS['stub_registered'] = array( 'sfwd-courses', 'sfwd-topic', 'sfwd-lessons' );
		$expect = array( 'learndash', 'sfwd-courses', 'sfwd-topic', 'sfwd-lessons', 'sfwd-quiz' );
		break;

	case 'learndash-nofunc':
		/*
		 * LearnDash present, but its slug function not available — the window
		 * during activation, and any load order where the class exists before
		 * the helpers do. This is the ONLY case that exercises the hardcoded
		 * fallback literals in EduAI_LMS::type().
		 *
		 * Added because mutation testing found the gap: breaking the fallback
		 * from 'sfwd-lessons' to 'sfwd-lesson' left every provider green, since
		 * the other LearnDash case always supplies the function and the
		 * function is preferred. A constant no test can reach is a constant
		 * nobody has checked.
		 */
		class SFWD_LMS {}  // phpcs:ignore
		$GLOBALS['stub_registered'] = array( 'sfwd-courses', 'sfwd-topic', 'sfwd-lessons' );
		$expect = array( 'learndash', 'sfwd-courses', 'sfwd-topic', 'sfwd-lessons', 'sfwd-quiz' );
		break;

	default:
		$GLOBALS['stub_registered'] = array();
		$expect = array( '', '', '', '', '' );
}

/*
 * class-eduai-lms.php opens with `defined( 'ABSPATH' ) || exit;`. ABSPATH is
 * defined above, so the require succeeds — but if that define is ever moved,
 * renamed or lost, the require would exit(0) silently and this whole harness
 * would report success having asserted nothing. CI reads exit codes.
 *
 * So arm the tripwire before the require, not after. Every legitimate exit
 * below happens once $loaded is true and keeps its own status.
 */
$loaded = false;
register_shutdown_function(
	static function () use ( &$loaded, $provider ) {
		if ( ! $loaded ) {
			fwrite(
				STDERR,
				"lms-seam [$provider] exited before loading EduAI_LMS — almost certainly the\n"
					. "`defined( 'ABSPATH' ) || exit;` guard in class-eduai-lms.php. Failing rather\n"
					. "than reporting success: a check that did not run must never look like one\n"
					. "that passed.\n"
			);
			exit( 3 );
		}
	}
);

require_once __DIR__ . '/../wp-content/plugins/eduai-assistant/includes/class-eduai-lms.php';

$loaded = true;

/* ------------------------------------------------------------------ checks -- */

$pass = 0;
$fail = 0;

function check( string $what, $got, $want ): void {
	global $pass, $fail;

	if ( $got === $want ) {
		$pass++;
		printf( "  ok    %-34s %s\n", $what, '' === $got ? "''" : $got );
		return;
	}

	$fail++;
	printf( "  FAIL  %-34s got %s, want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}

printf( "\n[%s]\n", $provider );

check( 'provider()', EduAI_LMS::provider(), $expect[0] );
check( 'course_type()', EduAI_LMS::course_type(), $expect[1] );
check( 'section_type()', EduAI_LMS::section_type(), $expect[2] );
check( 'lesson_type()', EduAI_LMS::lesson_type(), $expect[3] );
check( 'quiz_type()', EduAI_LMS::quiz_type(), $expect[4] );
check( 'active()', EduAI_LMS::active(), 'none' !== $provider );

// content_types() must be empty with no LMS, and must never contain '' — an
// empty post type reaching WP_Query silently queries blog posts.
$types = EduAI_LMS::content_types();
check( 'content_types() count', count( $types ), 'none' === $provider ? 0 : 3 );
check( 'content_types() has no empties', in_array( '', $types, true ), false );

// Fail closed. can_access() is the one method where a wrong answer is a
// security defect rather than a display bug, so it is asserted for every
// provider including the ones that cannot answer.
check( 'can_access() with no user', EduAI_LMS::can_access( 1, 0 ), false );
check( 'can_access() with no post', EduAI_LMS::can_access( 0, 1 ), false );

if ( 'none' === $provider ) {
	// With no LMS there is no access check to delegate to, so it must refuse
	// rather than fall through to a capability — the inversion that admits an
	// administrator and denies an enrolled student (docs/09-multi-agent-retrospective.md §2).
	check( 'can_access() refuses without an LMS', EduAI_LMS::can_access( 1, 1 ), false );
}

printf( "  %d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
