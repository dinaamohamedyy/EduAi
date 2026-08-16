<?php
/**
 * The one place that knows which LMS is installed.
 *
 * Every other file asks this class what a course, a section or a lesson is,
 * instead of naming a post type. Written for the Tutor → LearnDash conversion
 * (docs/14-learndash-conversion.md), and the reason is testability rather than
 * portability: with the seam in place the same assertions run against Tutor
 * today and against LearnDash the hour the plugin arrives. Without it, nothing
 * about the migration can be verified until after it has happened.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO. It does not translate content, migrate
 * enrolments, or paper over behavioural differences between the two LMSs. It
 * answers naming and access questions and nothing else. A shim that pretends
 * two different systems are the same system is how a migration ends up
 * "complete" while half the site quietly uses the old semantics.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * LMS-agnostic names and access checks.
 */
class EduAI_LMS {

	const TUTOR     = 'tutor';
	const LEARNDASH = 'learndash';

	/**
	 * Which LMS is actually active, or '' when none is.
	 *
	 * LearnDash wins when both are present. That is not a preference: during
	 * the conversion both plugins are installed at once, and a site half-way
	 * through must read as the destination rather than the origin, or the
	 * migration verifies against the system it is leaving.
	 */
	public static function provider(): string {
		static $provider = null;

		if ( null !== $provider ) {
			return $provider;
		}

		if ( function_exists( 'learndash_get_post_type_slug' ) || class_exists( 'SFWD_LMS' ) ) {
			$provider = self::LEARNDASH;
		} elseif ( function_exists( 'tutor' ) ) {
			$provider = self::TUTOR;
		} else {
			$provider = '';
		}

		return $provider;
	}

	/**
	 * True when some LMS is available to answer for.
	 *
	 * Callers must check this rather than relying on the type getters below to
	 * return something harmless. They return '' with no LMS, and '' passed to
	 * WP_Query is not "no results" — it is "the default post type", which is
	 * posts. A missing LMS silently querying blog posts is precisely the shape
	 * this project keeps paying for.
	 */
	public static function active(): bool {
		return '' !== self::provider();
	}

	/**
	 * Post type for a course.
	 */
	public static function course_type(): string {
		return self::type( 'course', 'courses', 'sfwd-courses' );
	}

	/**
	 * Post type for a section within a course — Tutor calls it a topic,
	 * LearnDash calls it a topic too but spells it differently.
	 */
	public static function section_type(): string {
		return self::type( 'topic', 'topics', 'sfwd-topic' );
	}

	/**
	 * Post type for a lesson.
	 */
	public static function lesson_type(): string {
		return self::type( 'lesson', 'lesson', 'sfwd-lessons' );
	}

	/**
	 * Post type for a quiz.
	 */
	public static function quiz_type(): string {
		return self::type( 'quiz', 'tutor_quiz', 'sfwd-quiz' );
	}

	/**
	 * Resolve one name.
	 *
	 * LearnDash's slugs are read from learndash_get_post_type_slug() rather
	 * than hardcoded, because that function is the documented API and the
	 * literals are not — a site with renamed slugs would otherwise get a
	 * plausible wrong answer instead of an error. The literal stays as a
	 * fallback for the window where the class exists but the function does not.
	 *
	 * @param string $key       LearnDash's own key for the type.
	 * @param string $tutor     Tutor's post type.
	 * @param string $learndash LearnDash's post type, as a fallback.
	 */
	private static function type( string $key, string $tutor, string $learndash ): string {
		switch ( self::provider() ) {
			case self::LEARNDASH:
				return function_exists( 'learndash_get_post_type_slug' )
					? (string) learndash_get_post_type_slug( $key )
					: $learndash;

			case self::TUTOR:
				// Ask Tutor rather than assume: it exposes these as properties
				// and a site that has filtered them would otherwise disagree
				// with us silently.
				if ( function_exists( 'tutor' ) ) {
					$t = tutor();
					if ( 'course' === $key && ! empty( $t->course_post_type ) ) {
						return (string) $t->course_post_type;
					}
					if ( 'topic' === $key && ! empty( $t->topics_post_type ) ) {
						return (string) $t->topics_post_type;
					}
					if ( 'lesson' === $key && ! empty( $t->lesson_post_type ) ) {
						return (string) $t->lesson_post_type;
					}
					if ( 'quiz' === $key && ! empty( $t->quiz_post_type ) ) {
						return (string) $t->quiz_post_type;
					}
				}
				return $tutor;
		}

		return '';
	}

	/**
	 * Every post type whose content should be indexed for the assistant.
	 *
	 * Filtered through post_type_exists() so a type the LMS names but has not
	 * registered never reaches WP_Query.
	 */
	public static function content_types(): array {
		$types = array();

		foreach ( array( self::course_type(), self::section_type(), self::lesson_type() ) as $type ) {
			if ( '' !== $type && post_type_exists( $type ) ) {
				$types[] = $type;
			}
		}

		return $types;
	}

	/**
	 * Is this post a lesson?
	 *
	 * @param WP_Post|int|null $post Post or id.
	 */
	public static function is_lesson( $post ): bool {
		$post = get_post( $post );
		return $post instanceof WP_Post && self::lesson_type() === $post->post_type && '' !== self::lesson_type();
	}

	/**
	 * Is this post a course?
	 *
	 * @param WP_Post|int|null $post Post or id.
	 */
	public static function is_course( $post ): bool {
		$post = get_post( $post );
		return $post instanceof WP_Post && self::course_type() === $post->post_type && '' !== self::course_type();
	}

	/**
	 * The course a lesson belongs to, or 0.
	 *
	 * The two LMSs disagree structurally here and the difference is not
	 * cosmetic. Tutor nests lesson → topic → course by post_parent. LearnDash
	 * stores the course id in meta on the lesson, so walking parents there
	 * returns 0 for a correctly-configured lesson. Asking each in its own terms
	 * is the whole point of this method existing.
	 *
	 * @param int $lesson_id Lesson post id.
	 */
	public static function course_of( int $lesson_id ): int {
		if ( $lesson_id <= 0 ) {
			return 0;
		}

		if ( self::LEARNDASH === self::provider() ) {
			if ( function_exists( 'learndash_get_course_id' ) ) {
				return (int) learndash_get_course_id( $lesson_id );
			}
			return (int) get_post_meta( $lesson_id, 'course_id', true );
		}

		if ( self::TUTOR === self::provider() ) {
			// Tutor records it directly; the parent walk is the fallback for
			// content created before that meta existed.
			$direct = (int) get_post_meta( $lesson_id, '_tutor_course_id_for_lesson', true );
			if ( $direct > 0 ) {
				return $direct;
			}

			$topic = (int) wp_get_post_parent_id( $lesson_id );
			return $topic > 0 ? (int) wp_get_post_parent_id( $topic ) : 0;
		}

		return 0;
	}

	/**
	 * May this user read this lesson's content?
	 *
	 * Deliberately NOT falling through to a capability check. `read_post` is
	 * true for an administrator and false for an enrolled student, which is
	 * exactly backwards for a gate, and it tests green from every session on
	 * this project because everyone here is an administrator (docs/09-multi-agent-retrospective.md §2).
	 * Unknown LMS means unknown access, and unknown must not mean "allow".
	 *
	 * @param int $post_id Lesson or topic id.
	 * @param int $user_id Defaults to the current user.
	 */
	public static function can_access( int $post_id, int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();

		if ( $post_id <= 0 || $user_id <= 0 ) {
			return false;
		}

		if ( self::LEARNDASH === self::provider() ) {
			$course_id = self::course_of( $post_id );

			if ( $course_id > 0 && function_exists( 'sfwd_lms_has_access' ) ) {
				return (bool) sfwd_lms_has_access( $course_id, $user_id );
			}

			return false;
		}

		if ( self::TUTOR === self::provider() && function_exists( 'tutor_utils' ) ) {
			return (bool) tutor_utils()->has_enrolled_content_access(
				self::lesson_type(),
				$post_id,
				$user_id
			);
		}

		return false;
	}
}
