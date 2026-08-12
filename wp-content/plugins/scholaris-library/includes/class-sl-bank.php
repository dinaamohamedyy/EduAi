<?php
/**
 * Question banks authored on a study material.
 *
 * The bank lives on the material as meta and becomes a real per-student exam
 * row the moment somebody sits it. That is the whole design, and it exists
 * because Tutor cannot host it (docs/11 §4.1) — but the reason it is worth
 * doing this way is that the exam row is a shape the assistant plugin already
 * knows end to end: PrepareME renders it, `exam_owned()` stops cross-student
 * reads, `redact()` keeps the answer key server-side, `grade()` marks MCQ in
 * PHP for free, and the attempt lands on the student progress screen. None of
 * that is rebuilt here. This class writes one row and redirects.
 *
 * V1 IS MCQ ONLY, and the cause is specific rather than cautious: one short
 * answer couples the entire attempt to a live model call, because
 * `EduAI_Exams::grade()` returns early on a marking failure and discards the
 * MCQ marks it had already computed. On this install the owner's key 401s and
 * a free tier answers, so a teacher-authored paper must not have that
 * dependency. Lifting the limit means storing the MCQ half first — not
 * relaxing anything here.
 *
 * @package ScholarisLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storage, validation and the sit handler.
 */
class SL_Bank {

	const META     = '_scholaris_bank';
	const META_REV = '_scholaris_bank_rev';

	/**
	 * The bands a question may carry, and the one it falls back to.
	 *
	 * `EduAI_Exams::grade()` and `redact()` both read `band` with no
	 * coalescing, and `attempt_for_client()` groups on it — so an absent band
	 * is not a cosmetic omission, it is a PHP warning inside a student's
	 * marked paper.
	 */
	const BANDS   = array( 'easy', 'medium', 'hard' );
	const BAND_IN = 'medium';

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle_sit' ) );
	}

	/**
	 * The stored questions for a material, or an empty array.
	 *
	 * @param int $post_id Material ID.
	 */
	public static function questions( int $post_id ): array {
		$raw = (string) get_post_meta( $post_id, self::META, true );

		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || empty( $decoded['questions'] ) || ! is_array( $decoded['questions'] ) ) {
			return array();
		}

		return $decoded['questions'];
	}

	/**
	 * Revision of the stored bank. Part of the exam hash, so editing the bank
	 * gives students the new paper while attempts already sat keep pointing at
	 * the one they actually answered.
	 *
	 * @param int $post_id Material ID.
	 */
	public static function rev( int $post_id ): int {
		return max( 1, (int) get_post_meta( $post_id, self::META_REV, true ) );
	}

	public static function has_bank( int $post_id ): bool {
		return (bool) self::questions( $post_id );
	}

	/**
	 * Validate and store a bank, bumping the revision only on a real change.
	 *
	 * @param int   $post_id Material ID.
	 * @param array $rows    Raw rows, as posted.
	 * @return true|WP_Error
	 */
	public static function save( int $post_id, array $rows ) {
		if ( ! $rows ) {
			delete_post_meta( $post_id, self::META );
			return true;
		}

		$questions = self::validate( $rows );

		if ( is_wp_error( $questions ) ) {
			return $questions;
		}

		// The envelope EduAI_Exams::store_prepared() expects, stored verbatim
		// so the sit handler hands it straight over with no second shape to
		// keep in step.
		$json = wp_json_encode( array(
			'schema_version' => 1,
			'questions'      => $questions,
		) );

		if ( (string) get_post_meta( $post_id, self::META, true ) === $json ) {
			// Saving the post without touching the bank must not invalidate
			// every student's in-progress paper.
			return true;
		}

		update_post_meta( $post_id, self::META, wp_slash( $json ) );
		update_post_meta( $post_id, self::META_REV, self::rev( $post_id ) + 1 );

		return true;
	}

	/**
	 * Normalise and check a posted bank.
	 *
	 * DELIBERATELY NOT `EduAI_Exams::normalize_exam()`. That validator exists
	 * to catch a model that miscounted: it demands exactly N questions, ids in
	 * presentation order, bands ordered easy→medium→hard and a fixed band
	 * split. A lecturer writing seven medium questions is not a defect, and
	 * every one of those rules would refuse a perfectly good paper.
	 *
	 * What survives is only what downstream code actually reads without
	 * coalescing — verified in class-eduai-exams.php rather than assumed:
	 * `redact()` emits id, band, type, question, marks and options; the MCQ
	 * branch of `grade()` reads answer_index, band and explanation. A missing
	 * one of those is a warning printed inside a student's marked paper.
	 *
	 * Errors name the row and the field, because "invalid bank" tells a
	 * lecturer nothing about which of forty rows to look at.
	 *
	 * @param array $rows Raw rows.
	 * @return array|WP_Error Normalised questions, ids renumbered 1..n.
	 */
	public static function validate( array $rows ) {
		$out = array();
		$n   = 0;

		foreach ( array_values( $rows ) as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$human = $i + 1;

			$question = trim( wp_strip_all_tags( (string) ( $row['question'] ?? '' ) ) );
			$options  = array_values( (array) ( $row['options'] ?? array() ) );

			// A row left entirely blank is the empty last row of an editor,
			// not a mistake — drop it rather than refusing the whole save.
			if ( '' === $question && '' === trim( implode( '', array_map( 'strval', $options ) ) ) ) {
				continue;
			}

			if ( '' === $question ) {
				return self::row_error( $human, __( 'the question text is empty.', 'scholaris-library' ) );
			}

			$options = array_map(
				static fn( $o ) => trim( wp_strip_all_tags( (string) $o ) ),
				$options
			);

			if ( 4 !== count( $options ) ) {
				return self::row_error(
					$human,
					sprintf(
						/* translators: %d: number of options supplied */
						__( 'multiple choice needs exactly four options; this row has %d.', 'scholaris-library' ),
						count( $options )
					)
				);
			}

			foreach ( $options as $slot => $option ) {
				if ( '' === $option ) {
					return self::row_error(
						$human,
						sprintf(
							/* translators: %s: option letter, A to D */
							__( 'option %s is empty.', 'scholaris-library' ),
							chr( 65 + (int) $slot )
						)
					);
				}
			}

			$answer = $row['answer_index'] ?? null;

			if ( ! is_numeric( $answer ) || (int) $answer < 0 || (int) $answer > 3 ) {
				return self::row_error( $human, __( 'no correct answer is marked.', 'scholaris-library' ) );
			}

			$explanation = trim( wp_strip_all_tags( (string) ( $row['explanation'] ?? '' ) ) );

			if ( '' === $explanation ) {
				// Not pedantry: grade() echoes this into the marked paper with
				// no fallback, so a blank one is a gap where the student was
				// promised the reason they got it wrong.
				return self::row_error( $human, __( 'the explanation is empty — it is what the student reads after marking.', 'scholaris-library' ) );
			}

			$band = strtolower( trim( (string) ( $row['band'] ?? '' ) ) );
			$band = in_array( $band, self::BANDS, true ) ? $band : self::BAND_IN;

			$marks = (int) ( $row['marks'] ?? 1 );
			$marks = max( 1, min( 5, $marks ) );

			++$n;

			$out[] = array(
				// Renumbered in array order, contiguous from 1: grade() and
				// redact() both key on this, and a gap or a duplicate silently
				// drops or overwrites a result.
				'id'           => $n,
				'type'         => 'mcq',
				'band'         => $band,
				'question'     => $question,
				'options'      => $options,
				'answer_index' => (int) $answer,
				'explanation'  => $explanation,
				'marks'        => $marks,
			);
		}

		if ( ! $out ) {
			return new WP_Error( 'sl_bank_empty', __( 'The question bank has no complete questions in it.', 'scholaris-library' ) );
		}

		return $out;
	}

	/**
	 * @param int    $row     1-based row number as the lecturer sees it.
	 * @param string $problem Sentence describing the fault.
	 */
	private static function row_error( int $row, string $problem ): WP_Error {
		return new WP_Error(
			'sl_bank_invalid',
			sprintf(
				/* translators: 1: row number 2: what is wrong with it */
				__( 'Question %1$d: %2$s', 'scholaris-library' ),
				$row,
				$problem
			)
		);
	}

	/**
	 * The link that starts a paper. Mirrors SL_Library::download_url().
	 *
	 * @param int $post_id Material ID.
	 */
	public static function sit_url( int $post_id ): string {
		return add_query_arg(
			array(
				'sl_sit'   => $post_id,
				'_wpnonce' => wp_create_nonce( 'sl_sit_' . $post_id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Materialise the bank as this student's exam row and send them to it.
	 *
	 * Gated exactly as the document is — `SL_Meta::can_download()`, the same
	 * predicate, not a second rule that agrees today. A bank sitting on a
	 * members-only material is that material's content.
	 */
	public static function handle_sit(): void {
		if ( empty( $_GET['sl_sit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$post_id = absint( wp_unslash( $_GET['sl_sit'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'sl_sit_' . $post_id ) ) {
			wp_die( esc_html__( 'That practice-paper link has expired. Reload the page and try again.', 'scholaris-library' ), '', array( 'response' => 403 ) );
		}

		if ( 'study_material' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			wp_die( esc_html__( 'Practice paper not found.', 'scholaris-library' ), '', array( 'response' => 404 ) );
		}

		if ( ! is_user_logged_in() || ! SL_Meta::can_download( $post_id ) ) {
			// One destination for both, deliberately: signing in is the answer
			// to the first, and the only useful answer to the second.
			wp_safe_redirect( wp_login_url( (string) get_permalink( $post_id ) ) );
			exit;
		}

		if ( ! class_exists( 'EduAI_Exams' ) || ! function_exists( 'eduai_prepare_url' ) ) {
			wp_die(
				esc_html__( 'Practice papers need the EduAI Assistant plugin, which is not active.', 'scholaris-library' ),
				'',
				array( 'response' => 501 )
			);
		}

		$questions = self::questions( $post_id );

		if ( ! $questions ) {
			wp_die( esc_html__( 'There is no practice paper on this material yet.', 'scholaris-library' ), '', array( 'response' => 404 ) );
		}

		$user_id = get_current_user_id();
		$title   = (string) get_the_title( $post_id );

		// Keyed on the revision, so re-sitting reuses one row per version
		// rather than accumulating an exam per click — and editing the bank
		// hands out a new paper without disturbing attempts already sat.
		$hash     = hash( 'sha256', 'sl_bank:' . $post_id . ':' . self::rev( $post_id ) );
		$existing = EduAI_Exams::find_by_hash( $user_id, $hash );

		$exam_id = $existing
			? (int) $existing['id']
			: EduAI_Exams::store_prepared(
				$user_id,
				$title,
				$hash,
				array( 'title' => $title, 'questions' => $questions )
			);

		if ( ! $exam_id ) {
			wp_die( esc_html__( 'The practice paper could not be started. Try again.', 'scholaris-library' ), '', array( 'response' => 500 ) );
		}

		// No model call anywhere above, so this correctly never touches the
		// exam generation budget.
		wp_safe_redirect( eduai_prepare_url( $exam_id ) );
		exit;
	}
}
