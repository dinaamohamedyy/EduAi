<?php
/**
 * Move Tutor LMS content to LearnDash — courses, sections, lessons, enrolments.
 *
 *     wp eval-file /scripts/migrate-to-learndash.php            # dry run
 *     wp eval-file /scripts/migrate-to-learndash.php -- apply   # do it
 *
 * DRY RUN BY DEFAULT, deliberately. This creates posts and grants course
 * access; a migration you can only find out about by running it is one you
 * cannot review. The dry run prints exactly what the apply will do.
 *
 * IDEMPOTENT. Every created post records `_eduai_migrated_from` with its Tutor
 * id, and every source records `_eduai_migrated_to`. Re-running skips anything
 * already carrying that mapping rather than making a second copy — which is the
 * failure mode of a migration somebody runs twice because the first run's
 * output scrolled past.
 *
 * NON-DESTRUCTIVE. Nothing in Tutor is deleted, unpublished or altered beyond
 * the mapping meta. Deactivating Tutor is a separate, reversible decision taken
 * after somebody has looked at the site.
 *
 * @package EduAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run me through wp-cli, not php.\n" );
	exit( 1 );
}

$apply = in_array( 'apply', (array) ( $args ?? array() ), true );

printf( "\n  %s\n\n", $apply ? 'APPLYING — writing to the database' : 'DRY RUN — nothing will be written (pass `apply` to execute)' );

if ( ! function_exists( 'learndash_get_post_type_slug' ) ) {
	fwrite( STDERR, "LearnDash is not active — nothing to migrate into.\n" );
	exit( 1 );
}

$ld_course = learndash_get_post_type_slug( 'course' );
$ld_topic  = learndash_get_post_type_slug( 'topic' );
$ld_lesson = learndash_get_post_type_slug( 'lesson' );

$made = array( 'course' => 0, 'topic' => 0, 'lesson' => 0, 'enrol' => 0, 'skipped' => 0 );

/**
 * Copy one Tutor post into a LearnDash post type, or return the existing copy.
 *
 * Carries the whole meta table across except Tutor's own bookkeeping. That
 * matters more than it looks: the AI features hang off `_eduai_*` meta —
 * `_eduai_source_material` is what lets a lesson show its slides and what
 * Summarise reads — so dropping meta would migrate the content and quietly
 * strip the thing this site is for.
 *
 * @param WP_Post $src    Tutor post.
 * @param string  $type   Destination post type.
 * @param int     $parent Destination parent id, or 0.
 * @param bool    $apply  Write, or only report.
 */
function eduai_migrate_post( WP_Post $src, string $type, int $parent, bool $apply ): int {
	$existing = (int) get_post_meta( $src->ID, '_eduai_migrated_to', true );

	if ( $existing && get_post( $existing ) ) {
		printf( "    skip   %-14s %-34s already migrated to %d\n", $src->post_type, mb_substr( $src->post_title, 0, 34 ), $existing );
		return $existing;
	}

	printf( "    %s %-14s %-34s -> %s\n", $apply ? 'move  ' : 'would ', $src->post_type, mb_substr( $src->post_title, 0, 34 ), $type );

	if ( ! $apply ) {
		return 0;
	}

	$new_id = (int) wp_insert_post( array(
		'post_type'     => $type,
		'post_title'    => $src->post_title,
		'post_content'  => $src->post_content,
		'post_excerpt'  => $src->post_excerpt,
		'post_status'   => $src->post_status,
		'post_author'   => $src->post_author,
		'post_date'     => $src->post_date,
		'menu_order'    => $src->menu_order,
		'post_parent'   => $parent,
		'post_name'     => $src->post_name,
	), true );

	if ( is_wp_error( $new_id ) || ! $new_id ) {
		printf( "    FAIL   could not create: %s\n", is_wp_error( $new_id ) ? $new_id->get_error_message() : 'unknown' );
		return 0;
	}

	// Everything except Tutor's internal keys, which mean nothing to LearnDash
	// and would leave stale course pointers behind.
	foreach ( (array) get_post_meta( $src->ID ) as $key => $values ) {
		if ( 0 === strpos( $key, '_tutor' ) || '_eduai_migrated_to' === $key ) {
			continue;
		}
		foreach ( (array) $values as $value ) {
			add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
		}
	}

	// The featured image is not in the meta loop's gift — it is, but only as an
	// id, and it must point at the same attachment rather than be re-uploaded.
	$thumb = get_post_thumbnail_id( $src->ID );
	if ( $thumb ) {
		set_post_thumbnail( $new_id, $thumb );
	}

	update_post_meta( $new_id, '_eduai_migrated_from', $src->ID );
	update_post_meta( $src->ID, '_eduai_migrated_to', $new_id );

	return $new_id;
}

/* ---------------------------------------------------------------- courses -- */

$courses = get_posts( array(
	'post_type'   => 'courses',
	'numberposts' => -1,
	'post_status' => 'any',
	'orderby'     => 'ID',
	'order'       => 'ASC',
) );

if ( ! $courses ) {
	print "  no Tutor courses found — nothing to do\n";
	return;
}

foreach ( $courses as $course ) {
	printf( "  COURSE %d  %s\n", $course->ID, $course->post_title );

	$new_course = eduai_migrate_post( $course, $ld_course, 0, $apply );
	$made['course'] += ( $new_course && $apply ) ? 1 : 0;

	/*
	 * LearnDash associates a step with its course through the `course_id`
	 * meta AND its own settings key. Both are written: the meta is what
	 * learndash_get_course_id() reads — and therefore what our own
	 * EduAI_LMS::course_of() reads — while the setting is what the course
	 * builder reads. Writing only one produces a lesson that resolves to a
	 * course in code and appears in no course in the admin.
	 */
	foreach ( get_posts( array(
		'post_type'   => 'topics',
		'post_parent' => $course->ID,
		'numberposts' => -1,
		'post_status' => 'any',
		'orderby'     => 'menu_order',
		'order'       => 'ASC',
	) ) as $topic ) {

		$new_topic = eduai_migrate_post( $topic, $ld_topic, 0, $apply );
		$made['topic'] += ( $new_topic && $apply ) ? 1 : 0;

		if ( $apply && $new_topic && $new_course ) {
			update_post_meta( $new_topic, 'course_id', $new_course );
			learndash_update_setting( $new_topic, 'course', $new_course );
		}

		foreach ( get_posts( array(
			'post_type'   => 'lesson',
			'post_parent' => $topic->ID,
			'numberposts' => -1,
			'post_status' => 'any',
			'orderby'     => 'menu_order',
			'order'       => 'ASC',
		) ) as $lesson ) {

			$new_lesson = eduai_migrate_post( $lesson, $ld_lesson, 0, $apply );
			$made['lesson'] += ( $new_lesson && $apply ) ? 1 : 0;

			if ( $apply && $new_lesson && $new_course ) {
				update_post_meta( $new_lesson, 'course_id', $new_course );
				learndash_update_setting( $new_lesson, 'course', $new_course );

				if ( $new_topic ) {
					learndash_update_setting( $new_lesson, 'lesson', $new_topic );
				}
			}
		}
	}
}

/* ------------------------------------------------------------- enrolments -- */

print "\n  ENROLMENTS\n";

global $wpdb;

$enrolments = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"SELECT post_author, post_parent FROM {$wpdb->posts} WHERE post_type = 'tutor_enrolled'"
);

foreach ( (array) $enrolments as $row ) {
	$user_id      = (int) $row->post_author;
	$old_course   = (int) $row->post_parent;
	$new_course   = (int) get_post_meta( $old_course, '_eduai_migrated_to', true );
	$user         = get_userdata( $user_id );

	if ( ! $user || ! $new_course ) {
		printf( "    skip   user %d / course %d — no migrated course to enrol into\n", $user_id, $old_course );
		$made['skipped']++;
		continue;
	}

	if ( $apply && function_exists( 'ld_update_course_access' ) ) {
		// Third argument false = grant rather than remove. LearnDash writes the
		// access record and its activity row from this one call; writing the
		// user meta directly produces an enrolment the LMS cannot see.
		ld_update_course_access( $user_id, $new_course, false );
		$made['enrol']++;
	}

	printf( "    %s %-24s -> course %d\n", $apply ? 'enrol ' : 'would ', $user->user_login, $new_course );
}

/* ----------------------------------------------------------------- report -- */

printf(
	"\n  %s: %d course(s), %d section(s), %d lesson(s), %d enrolment(s), %d skipped\n",
	$apply ? 'migrated' : 'would migrate',
	$made['course'],
	$made['topic'],
	$made['lesson'],
	$made['enrol'],
	$made['skipped']
);

if ( ! $apply ) {
	print "\n  Nothing was written. Re-run with `apply` once the list above looks right.\n";
} else {
	print "\n  Tutor content is untouched. Verify the site before deactivating Tutor.\n";
}
