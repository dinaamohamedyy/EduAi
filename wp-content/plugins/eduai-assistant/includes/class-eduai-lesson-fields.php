<?php
/**
 * Give a hand-made lesson the same slides a generated one gets.
 *
 * The owner's words: *"I couldn't add PDF… make it easy to add lessons and
 * insert PDFs or videos to be the main component of the lesson."* Tutor's
 * lesson form offers Name, Content, Featured Image and Video, and no document
 * slot — so a lesson the pipeline builds can carry a deck and a lesson he
 * builds by hand cannot. Two kinds of lesson with different capabilities, and
 * the weaker one is the one he can actually make.
 *
 * This writes THE SAME META the segmenter writes — `_eduai_source_material`
 * and the optional page range — so `templates/lesson-panel.php` renders both
 * identically. That is the whole design: the panel already keys on the meta
 * rather than on how the lesson came to exist, so nothing downstream needs to
 * know the difference and there is no second rendering path to keep in step.
 *
 * IT ASKS FOR A MATERIAL, NOT A FILE, and that is deliberate. A study_material
 * is what carries the access level, the placement into the denied directory
 * and the gated streaming route; a bare attachment carries none of those. A
 * PDF picker here would have quietly created a second class of lesson
 * document that no gate applies to — which is the exact hole the file work
 * spent a day closing.
 *
 * ON THE CLASSIC EDITOR, because Tutor's own lesson builder is a React SPA
 * over `wp_ajax_tutor_*` and adding a field to it means owning a fork of
 * their front end. `lesson` registers `show_ui => true` and supports
 * `editor`, so `post.php?post=<id>&action=edit` exists and works — it is
 * merely hidden from the menu by `show_in_menu => false`.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * The lesson's source material and page range.
 */
class EduAI_Lesson_Fields {

	const NONCE = 'eduai_lesson_source';

	public static function init(): void {
		/*
		 * The LMS's own lesson type, not Tutor's literal `lesson`.
		 *
		 * These two hooks were `add_meta_boxes_lesson` and `save_post_lesson`,
		 * so after the LearnDash conversion this box stopped rendering on every
		 * lesson on the site — silently, because a meta box that never fires
		 * looks exactly like one that was never written. It left the product
		 * with no way at all to put a readable recording on a lesson.
		 *
		 * Same defect as indexed_post_types() carrying Tutor's slugs, and the
		 * reason to ask the seam rather than correct the string: a corrected
		 * string goes stale at the next migration in exactly this way.
		 */
		$type = class_exists( 'EduAI_LMS' ) && EduAI_LMS::active() ? EduAI_LMS::lesson_type() : 'lesson';

		add_action( 'add_meta_boxes_' . $type, array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_' . $type, array( __CLASS__, 'save' ), 10, 2 );
	}

	public static function add_box(): void {
		add_meta_box(
			'eduai_lesson_source',
			__( 'Slides for this lesson', 'eduai' ),
			array( __CLASS__, 'render' ),
			class_exists( 'EduAI_LMS' ) && EduAI_LMS::active() ? EduAI_LMS::lesson_type() : 'lesson',
			'normal',
			'high'
		);
	}

	/**
	 * Published materials, newest first, for the picker.
	 *
	 * Fixtures excluded through the same flag the library uses — a test
	 * fixture in a lecturer's dropdown is the same category error as one on
	 * his shelf, and it is the list he chooses from rather than one he
	 * browses, so it matters more here.
	 */
	private static function materials(): array {
		return get_posts( array(
			'post_type'      => 'study_material',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'     => array(
				array(
					'key'     => '_scholaris_fixture',
					'compare' => 'NOT EXISTS',
				),
			),
		) );
	}

	/**
	 * @param WP_Post $post Lesson being edited.
	 */
	public static function render( $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );

		$current   = (int) get_post_meta( $post->ID, '_eduai_source_material', true );
		$from      = (int) get_post_meta( $post->ID, '_eduai_page_from', true );
		$to        = (int) get_post_meta( $post->ID, '_eduai_page_to', true );
		$materials = self::materials();

		if ( ! $materials ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'No study material has been published yet. Upload a lecture under Study material first, then come back and choose it here.', 'eduai' )
			);
			return;
		}

		echo '<p><label for="eduai-lesson-material"><strong>';
		esc_html_e( 'Lecture this lesson teaches from', 'eduai' );
		echo '</strong></label></p>';

		echo '<select name="eduai_source_material" id="eduai-lesson-material" class="widefat">';
		printf( '<option value="0">%s</option>', esc_html__( '— none —', 'eduai' ) );

		foreach ( $materials as $material ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $material->ID,
				selected( $current, (int) $material->ID, false ),
				esc_html( get_the_title( $material ) )
			);
		}

		echo '</select>';

		echo '<p class="description">';
		esc_html_e( 'The slides appear at the top of the lesson, and Ask and Summarise are scoped to it. Access follows the material, so a members-only lecture stays members-only here.', 'eduai' );
		echo '</p>';

		echo '<p><strong>';
		esc_html_e( 'Which slides? (optional)', 'eduai' );
		echo '</strong></p>';

		printf(
			'<label>%s <input type="number" name="eduai_page_from" value="%s" min="1" step="1" class="small-text"></label> ',
			esc_html__( 'From', 'eduai' ),
			$from ? esc_attr( (string) $from ) : ''
		);

		printf(
			'<label>%s <input type="number" name="eduai_page_to" value="%s" min="1" step="1" class="small-text"></label>',
			esc_html__( 'to', 'eduai' ),
			$to ? esc_attr( (string) $to ) : ''
		);

		echo '<p class="description">';
		esc_html_e( 'Leave both empty to show the whole document. The viewer opens at the first slide of the range.', 'eduai' );
		echo '</p>';
	}

	/**
	 * Persist the choice.
	 *
	 * Guarded on our own nonce FIELD being present, not merely valid — Tutor
	 * saves lessons through its own AJAX, and that request carries none of
	 * these inputs. Without this guard every SPA save would read "no material
	 * chosen" and wipe a range the lecturer set on the classic screen, which
	 * is the silent-data-loss shape that a save handler reaching for
	 * `$_POST['x'] ?? ''` always has.
	 *
	 * @param int     $post_id Lesson.
	 * @param WP_Post $post    Lesson.
	 */
	public static function save( $post_id, $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$material = isset( $_POST['eduai_source_material'] ) ? absint( wp_unslash( $_POST['eduai_source_material'] ) ) : 0;

		// An id that is not a published material is not a material. Same
		// check as the file field's: absint() will happily store a page id.
		if ( $material && ( 'study_material' !== get_post_type( $material ) || 'publish' !== get_post_status( $material ) ) ) {
			$material = 0;
		}

		$from = isset( $_POST['eduai_page_from'] ) ? absint( wp_unslash( $_POST['eduai_page_from'] ) ) : 0;
		$to   = isset( $_POST['eduai_page_to'] ) ? absint( wp_unslash( $_POST['eduai_page_to'] ) ) : 0;
		// phpcs:enable

		// A backwards range is a typo, not an instruction. Swapping is kinder
		// than refusing and cannot be wrong: there is no reading of "22 to 11"
		// that means anything else.
		if ( $from && $to && $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}

		// A range without a material describes nothing.
		if ( ! $material ) {
			$from = 0;
			$to   = 0;
		}

		foreach ( array(
			'_eduai_source_material' => $material,
			'_eduai_page_from'       => $from,
			'_eduai_page_to'         => $to,
		) as $key => $value ) {
			if ( $value ) {
				update_post_meta( $post_id, $key, $value );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
	}
}
