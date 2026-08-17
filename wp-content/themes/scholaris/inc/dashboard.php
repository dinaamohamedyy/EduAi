<?php
/**
 * Dashboard data for the home and progress screens.
 *
 * The owner sent two reference dashboards and said, fairly, that recolouring
 * is not the work — anyone can do that. So the point of this file is that
 * every number on those screens is a real one. A stat ring showing an invented
 * percentage is a recolour with extra steps, and this project has spent the day
 * removing claims that were true of nothing: a slide range over an empty frame,
 * a section heading over no cards, a FROM link to a 404.
 *
 * Everything here is read defensively. Tutor lives only in the container and
 * the two plugins can be deactivated independently, so each source is checked
 * before it is asked, and an absent source produces an absent panel rather than
 * a zero — "0 lessons" and "we cannot see your lessons" are different claims.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything the dashboard needs, for one user, in one pass.
 *
 * @param int $user_id User to report on. 0 for the current user.
 * @return array<string,mixed>
 */
function scholaris_dashboard_data( int $user_id = 0 ): array {
	$user_id = $user_id ?: get_current_user_id();

	/*
	 * Tutor is asked for completion only when Tutor is the ACTIVE LMS, not
	 * merely when its functions are loaded.
	 *
	 * During the LearnDash conversion both plugins are installed at once, and
	 * EduAI_LMS::provider() answers LearnDash first on purpose so a half-migrated
	 * site reads as its destination. tutor_utils() keeps existing throughout, so
	 * a bare function_exists() check would have kept this dashboard reporting
	 * Tutor's enrolments and percentages after the site had moved on — a panel
	 * confidently showing the old system's numbers, which is the exact failure
	 * that class was written to prevent.
	 *
	 * There is no LMS-agnostic completion API to fall back on, and that is
	 * deliberate on their side: the seam answers naming and access, not
	 * progress. So under LearnDash this reports no source and the panel is
	 * absent, which is the honest state — a dashboard that cannot see your
	 * lessons should say nothing rather than say zero.
	 */
	$sc_lms = class_exists( 'EduAI_LMS' ) ? EduAI_LMS::provider() : ( function_exists( 'tutor_utils' ) ? 'tutor' : '' );

	/*
	 * Does the active LMS have any courses at all?
	 *
	 * "You are not enrolled on a course yet" blames the student for the empty
	 * panel. That is right when courses exist and they are on none of them, and
	 * wrong when the site itself has none — which is the state today, with
	 * LearnDash active and its content not yet migrated. Same rule as the rest
	 * of this dashboard: say what is actually true, and do not let one message
	 * cover two different facts.
	 */
	$sc_course_type = class_exists( 'EduAI_LMS' ) && EduAI_LMS::active() ? EduAI_LMS::course_type() : '';
	$sc_any_courses = false;

	if ( $sc_course_type ) {
		$sc_counts      = wp_count_posts( $sc_course_type );
		$sc_any_courses = isset( $sc_counts->publish ) && (int) $sc_counts->publish > 0;
	}

	$data = array(
		'lms'          => $sc_lms,
		'any_courses'  => $sc_any_courses,
		'has_tutor' => 'tutor' === $sc_lms && function_exists( 'tutor_utils' ),
		'lessons'   => null,
		'quizzes'   => null,
		'papers'    => null,
		'courses'   => array(),
		'resume'    => null,
		'activity'  => array(),
		'study_days' => array(),
	);

	if ( ! $user_id ) {
		return $data;
	}

	// ---------------------------------------------------------------- courses
	// Tutor's own completion figures rather than a second opinion computed
	// here. Two predicates that agree today is the failure this project has
	// spent the most time repairing.
	if ( $data['has_tutor'] ) {
		$enrolled = tutor_utils()->get_enrolled_courses_by_user( $user_id );
		$posts    = ( $enrolled && ! empty( $enrolled->posts ) ) ? $enrolled->posts : array();

		$lessons_done  = 0;
		$lessons_total = 0;

		foreach ( $posts as $course ) {
			$cid   = (int) $course->ID;
			$stats = tutor_utils()->get_course_completed_percent( $cid, $user_id, true );

			$done  = (int) ( $stats['completed_count'] ?? 0 );
			$total = (int) ( $stats['total_count'] ?? 0 );
			$pct   = $total > 0 ? (int) round( $done / $total * 100 ) : 0;

			$lessons_done  += $done;
			$lessons_total += $total;

			$data['courses'][] = array(
				'id'      => $cid,
				'title'   => get_the_title( $cid ),
				'url'     => get_permalink( $cid ),
				'done'    => $done,
				'total'   => $total,
				'percent' => $pct,
				'thumb'   => get_the_post_thumbnail_url( $cid, 'medium' ) ?: '',
			);
		}

		// Sort by "closest to finishing but not finished" — the course a
		// student can most usefully return to, rather than alphabetical.
		usort(
			$data['courses'],
			static function ( $a, $b ) {
				$a_live = $a['percent'] < 100 ? 0 : 1;
				$b_live = $b['percent'] < 100 ? 0 : 1;
				if ( $a_live !== $b_live ) {
					return $a_live <=> $b_live;
				}
				return $b['percent'] <=> $a['percent'];
			}
		);

		$data['lessons'] = array( 'done' => $lessons_done, 'total' => $lessons_total );
	}

	// ------------------------------------------------------- courses, LearnDash
	/*
	 * Built from course STEPS, not from learndash_course_progress().
	 *
	 * That function is the obvious one to reach for and it is wrong here: on
	 * this install it returns {"percentage":0,"completed":0,"total":0} for a
	 * course whose steps list holds three lessons. A real API returning a real
	 * structure with a total of zero — so a dashboard built on it would have
	 * shown "0 of 0 done" beside a course that plainly has lessons, and looked
	 * fine doing it.
	 *
	 * learndash_get_course_steps() reports the three, and completion is asked
	 * per step. Verified against the running site rather than the docs.
	 */
	if ( 'learndash' === $sc_lms
		&& function_exists( 'learndash_user_get_enrolled_courses' )
		&& function_exists( 'learndash_get_course_steps' ) ) {

		$ld_courses    = (array) learndash_user_get_enrolled_courses( $user_id );
		$lessons_done  = 0;
		$lessons_total = 0;

		foreach ( $ld_courses as $cid ) {
			$cid   = (int) $cid;
			$steps = (array) learndash_get_course_steps( $cid );
			$total = count( $steps );
			$done  = 0;

			if ( function_exists( 'learndash_is_lesson_complete' ) ) {
				foreach ( $steps as $step ) {
					if ( learndash_is_lesson_complete( $user_id, (int) $step, $cid ) ) {
						$done++;
					}
				}
			}

			$lessons_done  += $done;
			$lessons_total += $total;

			$data['courses'][] = array(
				'id'      => $cid,
				'title'   => get_the_title( $cid ),
				'url'     => get_permalink( $cid ),
				'done'    => $done,
				'total'   => $total,
				'percent' => scholaris_pct( $done, $total ),
				'thumb'   => get_the_post_thumbnail_url( $cid, 'medium' ) ?: '',
			);
		}

		$data['lessons'] = array( 'done' => $lessons_done, 'total' => $lessons_total );
	}

	// Sort by "closest to finishing but not finished" — the course a student
	// can most usefully return to, rather than alphabetical. Applies to both
	// providers, so it sits outside either branch.
	usort(
		$data['courses'],
		static function ( $a, $b ) {
			$a_live = $a['percent'] < 100 ? 0 : 1;
			$b_live = $b['percent'] < 100 ? 0 : 1;
			if ( $a_live !== $b_live ) {
				return $a_live <=> $b_live;
			}
			return $b['percent'] <=> $a['percent'];
		}
	);

	foreach ( $data['courses'] as $course ) {
		if ( $course['percent'] < 100 ) {
			$data['resume'] = $course;
			break;
		}
	}

	// ---------------------------------------------------------------- quizzes
	if ( class_exists( 'SL_Quiz_History' ) ) {
		$attempts = SL_Quiz_History::attempts( $user_id, 0 );
		$summary  = SL_Quiz_History::summarise( $attempts );

		$data['quizzes'] = array(
			'done'    => (int) $summary['count'],
			'passed'  => (int) $summary['passed'],
			'average' => (float) $summary['average'],
			'trend'   => (float) $summary['trend'],
		);

		foreach ( $attempts as $a ) {
			$when = isset( $a['date'] ) ? (string) $a['date'] : '';
			if ( $when ) {
				$data['study_days'][] = substr( $when, 0, 10 );
			}
			if ( count( $data['activity'] ) < 8 ) {
				$data['activity'][] = array(
					'kind'  => 'quiz',
					'label' => (string) ( $a['quiz_title'] ?? __( 'Quiz attempt', 'scholaris' ) ),
					'meta'  => isset( $a['percent'] ) ? round( (float) $a['percent'] ) . '%' : '',
					'date'  => $when,
				);
			}
		}
	}

	// ----------------------------------------------------------------- papers
	if ( class_exists( 'EduAI_Exams' ) ) {
		$stats = EduAI_Exams::stats_for_user( $user_id );

		$data['papers'] = array(
			'done'    => (int) ( $stats['taken'] ?? 0 ),
			'average' => isset( $stats['average'] ) && null !== $stats['average'] ? (int) $stats['average'] : null,
			'best'    => isset( $stats['best'] ) && null !== $stats['best'] ? (int) $stats['best'] : null,
		);

		$history = EduAI_Exams::history_for_user( $user_id, 8 );

		foreach ( (array) $history as $h ) {
			$when = (string) ( $h['created_at'] ?? $h['date'] ?? '' );
			if ( $when ) {
				$data['study_days'][] = substr( $when, 0, 10 );
			}
			if ( count( $data['activity'] ) < 12 ) {
				/*
				 * `percent`, not `score`. score is raw marks — 12 of a 20-mark
				 * paper — so rendering it with a % sign turns a 60% result into
				 * "12%". Both are real numbers off the same row; only one of
				 * them answers the question the badge is asking.
				 */
				$data['activity'][] = array(
					'kind'  => 'paper',
					'label' => (string) ( $h['title'] ?? __( 'Practice paper', 'scholaris' ) ),
					'meta'  => isset( $h['percent'] ) ? (int) round( (float) $h['percent'] ) . '%' : '',
					'date'  => $when,
				);
			}
		}
	}

	$data['study_days'] = array_values( array_unique( array_filter( $data['study_days'] ) ) );

	// Newest first, so the rail reads as a feed rather than an archive.
	usort(
		$data['activity'],
		static function ( $a, $b ) {
			return strcmp( (string) $b['date'], (string) $a['date'] );
		}
	);

	return $data;
}

/**
 * Percentage for a ring, guarding the divide-by-zero that would otherwise
 * render a full circle for a student who has nothing to complete.
 *
 * @param int $done  Completed.
 * @param int $total Available.
 */
function scholaris_pct( int $done, int $total ): int {
	return $total > 0 ? (int) round( min( $done, $total ) / $total * 100 ) : 0;
}

/**
 * Put the dashboard at the top of the My Progress page.
 *
 * The page already carries [scholaris_dashboard], which the library plugin
 * registers and which lists quiz history. That stays: this adds the overview
 * above it rather than replacing a shortcode another session owns, and rather
 * than editing the page's stored content, which setup.sh also writes and would
 * overwrite on the next bootstrap.
 *
 * Signed-out visitors are untouched — the existing shortcode already renders a
 * sign-in card, and a dashboard of nobody's progress above it would be noise.
 *
 * @param string $content Post content.
 * @return string
 */
function scholaris_progress_dashboard( string $content ): string {
	if ( ! is_user_logged_in() || ! is_main_query() || ! in_the_loop() ) {
		return $content;
	}

	$page = get_page_by_path( 'progress' );

	if ( ! $page || get_the_ID() !== (int) $page->ID ) {
		return $content;
	}

	$data = scholaris_dashboard_data();

	ob_start();
	include get_theme_file_path( 'template-parts/dashboard.php' );

	return (string) ob_get_clean() . $content;
}
add_filter( 'the_content', 'scholaris_progress_dashboard' );
