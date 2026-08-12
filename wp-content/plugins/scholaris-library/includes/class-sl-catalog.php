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

	/**
	 * The whole catalogue.
	 *
	 * @return array{courses:array,loose:array}
	 */
	public static function tree(): array {
		$courses = self::courses();

		return array(
			'courses' => $courses,
			// Everything not reachable through a course. Named `loose` rather
			// than `orphans` because it is the normal state of a deck nobody
			// has segmented yet, not a fault.
			'loose'   => self::loose( $courses ),
		);
	}

	/**
	 * Published courses, each with its topics and lessons.
	 */
	public static function courses(): array {
		if ( ! post_type_exists( 'courses' ) || ! function_exists( 'tutor_utils' ) ) {
			return array();
		}

		$out = array();

		foreach ( get_posts( array(
			'post_type'      => 'courses',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		) ) as $course ) {

			$topics    = array();
			$materials = array();
			$lessons   = 0;

			// One call for the whole course rather than one per topic: this
			// runs on the library page, which is the most-visited screen in
			// the product.
			$contents = (array) tutor_utils()->get_course_contents_by_id( $course->ID );

			foreach ( (array) tutor_utils()->get_topics( $course->ID )->posts as $topic ) {
				$rows = array();

				foreach ( $contents as $item ) {
					if ( (int) $item->post_parent !== (int) $topic->ID || 'lesson' !== $item->post_type ) {
						continue;
					}

					$source = (int) get_post_meta( $item->ID, '_eduai_source_material', true );

					if ( $source ) {
						$materials[ $source ] = $source;
					}

					// Titles only. Listing what a course teaches is how
					// somebody decides to take it; the lesson BODY stays
					// behind Tutor's gate, which is the same split the course
					// page itself makes.
					$rows[] = array(
						'id'     => (int) $item->ID,
						'title'  => get_the_title( $item->ID ),
						'url'    => (string) get_permalink( $item->ID ),
						'source' => $source,
					);
				}

				$lessons += count( $rows );

				// A topic with nothing in it is scaffolding the lecturer has
				// not filled yet, and rendering it as an empty heading makes
				// the course look broken rather than unfinished.
				if ( $rows ) {
					$topics[] = array(
						'id'      => (int) $topic->ID,
						'title'   => get_the_title( $topic->ID ),
						'lessons' => $rows,
					);
				}
			}

			$out[] = array(
				'id'        => (int) $course->ID,
				'title'     => get_the_title( $course->ID ),
				'url'       => (string) get_permalink( $course->ID ),
				'topics'    => $topics,
				'lessons'   => $lessons,
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
