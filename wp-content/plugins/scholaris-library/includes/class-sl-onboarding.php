<?php
/**
 * Keep another product's onboarding off the owner's lessons.
 *
 * Tutor 4.x shows a full-screen tour the first time a user reaches the
 * learning area: it blurs the page behind it and advertises a generic LMS
 * with a fictional student's course list. On this site that lands on top of
 * the owner's lecture, and it is the first thing every student he enrols will
 * ever see of his product. The images come from Tutor's S3 bucket, so it is
 * also a third-party request from a student's browser.
 *
 * WE SET TUTOR'S OWN FLAG RATHER THAN UNHOOKING TUTOR'S TEMPLATE, and the
 * choice is about what survives an update. `_tutor_tour_completed` is the
 * state Tutor itself writes when somebody clicks Skip — using it means we are
 * inside its supported behaviour, and a plugin update can rename a template,
 * move a hook or change a class without breaking us. Filtering the template
 * out would work today and fail silently the first time they reorganise, with
 * the symptom appearing on students rather than on us.
 *
 * ON REGISTRATION AND NOT ONLY AS A SWEEP, because a sweep is the instance
 * and registration is the class: dismissing it for the students who exist
 * today leaves the next one to meet it. Both are here — the sweep for the
 * accounts that predate this file, the hook for every account after it.
 *
 * @package ScholarisLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Suppresses Tutor's first-run tour.
 */
class SL_Onboarding {

	const TOUR_META    = '_tutor_tour_completed';
	const SWEEP_OPTION = 'sl_tour_swept';

	public static function init(): void {
		add_action( 'user_register', array( __CLASS__, 'skip_tour' ) );
		add_action( 'init', array( __CLASS__, 'maybe_sweep' ) );
	}

	/**
	 * Should the tour be suppressed at all?
	 *
	 * Filterable because suppressing a third-party plugin's onboarding is a
	 * product decision rather than a technical one, and the person who
	 * disagrees should not have to edit this file to say so.
	 */
	private static function enabled(): bool {
		return (bool) apply_filters( 'scholaris_skip_tutor_tour', true );
	}

	/**
	 * Mark the tour done for a newly registered user.
	 *
	 * @param int $user_id New user.
	 */
	public static function skip_tour( $user_id ): void {
		$user_id = (int) $user_id;

		if ( ! $user_id || ! self::enabled() ) {
			return;
		}

		// metadata_exists rather than a truthiness check: a user who has
		// genuinely taken the tour has this set, and rewriting it would be
		// harmless but dishonest about what the value means.
		if ( ! metadata_exists( 'user', $user_id, self::TOUR_META ) ) {
			update_user_meta( $user_id, self::TOUR_META, true );
		}
	}

	/**
	 * One-off pass over accounts that predate this file.
	 *
	 * Guarded by an option rather than by checking every user on every
	 * request — this runs on `init`, and a query across all users per page
	 * load to fix a thing that only changes at registration would cost more
	 * than the problem.
	 */
	public static function maybe_sweep(): void {
		if ( ! self::enabled() || get_option( self::SWEEP_OPTION ) ) {
			return;
		}

		update_option( self::SWEEP_OPTION, 1, false );

		self::sweep();
	}

	/**
	 * Set the flag on every user that lacks it.
	 *
	 * @return int Users updated.
	 */
	public static function sweep(): int {
		$users = get_users( array(
			'fields'     => 'ID',
			'number'     => -1,
			'meta_query' => array(
				array(
					'key'     => self::TOUR_META,
					'compare' => 'NOT EXISTS',
				),
			),
		) );

		foreach ( $users as $user_id ) {
			update_user_meta( (int) $user_id, self::TOUR_META, true );
		}

		return count( $users );
	}
}
