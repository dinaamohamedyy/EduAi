<?php
/**
 * What the library contains, as a shape a template can loop over.
 *
 * The owner asked for "the library consists of courses and each course
 * consists of lessons". The pieces for that already existed and were never
 * joined: Tutor holds courses, topics and lessons; the assistant's segmenter
 * writes `_eduai_source_material` on each lesson naming the deck it came
 * from; and the library listed the decks, flat, with no idea any of it was
 * there.
 *
 * THE COURSE↔MATERIAL LINK IS DERIVED, NOT STORED. There is no inverse meta
 * on the material saying which course it became, and adding one would be a
 * second source of truth for a fact the lessons already carry — the first
 * import, re-segmentation or manual delete would leave the two disagreeing,
 * silently and in the direction that looks fine. A lesson naming its source
 * is the authority; everything here reads it.
 *
 * DEGRADES RATHER THAN EMPTIES. With Tutor absent, or with no courses built
 * yet, every material comes back under `loose` — which is the honest answer
 * and also today's screen, so the template cannot render a blank page while
 * the owner's material sits in the database.
 *
 * @package ScholarisLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Courses, their lessons, and the material that belongs to neither.
 */
class SL_Catalog {

	const VERSION_OPTION = 'sl_catalog_version';

	public static function init(): void {
		// Anything that could change the shape bumps the version, which
		// changes the cache key — so a stale entry becomes unreachable rather
		// than needing to be found and deleted. Cheaper to be wrong about
		// than a delete that misses one key.
		add_action( 'save_post', array( __CLASS__, 'invalidate' ) );
		add_action( 'deleted_post', array( __CLASS__, 'invalidate' ) );
		add_action( 'added_post_meta', array( __CLASS__, 'invalidate_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'invalidate_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'invalidate_meta' ), 10, 3 );
	}

	/**
	 * @param int $post_id Post that changed.
	 */
	public static function invalidate( $post_id = 0 ): void {
		$type = $post_id ? get_post_type( (int) $post_id ) : '';

		// Ignore the noise. A comment, a page edit or an attachment does not
		// change what the library contains, and bumping on every save would
		// make the cache a rounding error rather than a cache.
		// Asked of the seam, not listed here. These were Tutor's names, so
		// after the LearnDash conversion a course save stopped bumping the
		// version and the library served a cached tree of a plugin that was
		// no longer installed — the cache outliving the thing it caches.
		$watched = array( 'study_material' );

		if ( class_exists( 'EduAI_LMS' ) && EduAI_LMS::active() ) {
			$watched = array_merge( $watched, EduAI_LMS::content_types() );
		}

		if ( $type && ! in_array( $type, $watched, true ) ) {
			return;
		}

		update_option( self::VERSION_OPTION, (int) get_option( self::VERSION_OPTION, 0 ) + 1, false );
	}

	/**
	 * The meta that carries the course↔material link is written by the
	 * segmenter without a post save, so meta changes have to bump it too —
	 * otherwise a freshly segmented deck stays "not yet in a course" until
	 * something unrelated is edited.
	 *
	 * @param int    $meta_id  Ignored.
	 * @param int    $post_id  Post the meta belongs to.
	 * @param string $meta_key Key that changed.
	 */
	public static function invalidate_meta( $meta_id, $post_id, $meta_key ): void {
		if ( in_array( (string) $meta_key, array( '_eduai_source_material', '_scholaris_fixture' ), true ) ) {
			self::invalidate( (int) $post_id );
		}
	}

	/**
	 * The whole catalogue.
	 *
	 * CACHED, because the cost scales with COURSES rather than lessons and
	 * this runs on a browse page. Measured on one course of three lessons:
	 * 16 queries, cut to 12 by priming the post caches Tutor's own SQL
	 * bypasses — after which the per-lesson reads are all cache hits and the
	 * remaining cost is a fixed handful per course. At fifty courses that is
	 * still a few hundred queries to render a list, which is what the cache
	 * is for.
	 *
	 * Note what this does NOT do: store the course↔material link as meta on
	 * the material. That was the other way to make browsing cheap and it
	 * would have bought speed with a second copy of a fact, which drifts. A
	 * cache can be wrong for a moment; a duplicated fact is wrong until
	 * somebody notices.
	 *
	 * @return array{courses:array,loose:array}
	 */
	public static function tree(): array {
		$key    = 'sl_catalog_' . (int) get_option( self::VERSION_OPTION, 0 );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$courses = self::courses();

		$tree = array(
			'courses' => $courses,
			// Everything not reachable through a course. Named `loose` rather
			// than `orphans` because it is the normal state of a deck nobody
			// has segmented yet, not a fault.
			'loose'   => self::loose( $courses ),
		);

		set_transient( $key, $tree, HOUR_IN_SECONDS );

		return $tree;
	}

	/**
	 * Published courses, each with its topics and lessons.
	 */
	public static function courses(): array {
		if ( ! class_exists( 'EduAI_LMS' ) || ! EduAI_LMS::active() ) {
			return array();
		}

		$course_type = EduAI_LMS::course_type();
		$lesson_type = EduAI_LMS::lesson_type();

		$out = array();

		foreach ( get_posts( array(
			'post_type'      => $course_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		) ) as $course ) {

			$materials = array();

			// Lessons belonging to this course, in the order the LMS shows
			// them. LearnDash joins them by `course_id` meta rather than by
			// post_parent, which is the same shape of mistake as reading a
			// study material's attachment off its parent: the link is the
			// meta, and the parent is incidental.
			$lesson_posts = get_posts( array(
				'post_type'      => $lesson_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'   => 'course_id',
						'value' => (int) $course->ID,
					),
				),
			) );

			$rows = array();

			foreach ( $lesson_posts as $item ) {
				$source = (int) get_post_meta( $item->ID, '_eduai_source_material', true );

				if ( $source ) {
					$materials[ $source ] = $source;
				}

				// Titles only. Listing what a course teaches is how somebody
				// decides to take it; the lesson BODY stays behind the LMS's
				// gate, which is the same split the course page makes.
				$rows[] = array(
					'id'     => (int) $item->ID,
					'title'  => get_the_title( $item->ID ),
					'url'    => (string) get_permalink( $item->ID ),
					'source' => $source,
				);
			}

			/*
			 * One group, named for the course.
			 *
			 * The shape keeps a `topics` list because that is what the
			 * template loops over and changing it would break front-end's
			 * markup for a structural detail. LearnDash does have topics
			 * (`sfwd-topic`), but a lesson reaches its course through
			 * `course_id` whether or not a topic sits between them — so
			 * grouping by course is correct for every install, and a topic
			 * layer can be added later without moving the lessons.
			 */
			$topics = $rows
				? array(
					array(
						'id'      => (int) $course->ID,
						'title'   => get_the_title( $course->ID ),
						'lessons' => $rows,
					),
				)
				: array();

			$out[] = array(
				'id'        => (int) $course->ID,
				'title'     => get_the_title( $course->ID ),
				'url'       => (string) get_permalink( $course->ID ),
				'topics'    => $topics,
				'lessons'   => count( $rows ),
				// The decks this course was built from, so a template can
				// offer the original PDF beside the lessons made from it.
				'materials' => array_values( $materials ),
			);
		}

		return $out;

	}

	/**
	 * Published material that no course lesson points at.
	 *
	 * @param array $courses Result of courses(), to avoid resolving twice.
	 */
	public static function loose( array $courses ): array {
		$claimed = array();

		foreach ( $courses as $course ) {
			foreach ( $course['materials'] as $material_id ) {
				$claimed[ (int) $material_id ] = true;
			}
		}

		$out = array();

		foreach ( self::materials() as $material ) {
			if ( isset( $claimed[ (int) $material->ID ] ) ) {
				continue;
			}

			$out[] = $material;
		}

		return $out;
	}

	/**
	 * Published material, fixtures excluded.
	 *
	 * Goes through WP_Query rather than get_posts() so SL_Post_Types'
	 * `pre_get_posts` fixture filter applies — one exclusion rule, and no
	 * chance of the catalogue showing a fixture the archive hides.
	 */
	public static function materials(): array {
		$query = new WP_Query( array(
			'post_type'      => 'study_material',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		) );

		return $query->posts;
	}
}
