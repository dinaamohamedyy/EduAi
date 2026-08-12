<?php
/**
 * PrepareME: exams generated from lecture material, sat, and marked.
 *
 * The contract (docs/06-eduai-rebuild.md §2.4, endpoint shapes in
 * docs/05-frontend-handoff.md): a lecture goes in, a strict-JSON exam comes
 * out and is stored; MCQ marking is deterministic PHP by answer index; only
 * short answers go to the model, batched, at temperature 0. The model must
 * return strict JSON — one retry with the malformed output fed back, then an
 * honest error, never a half-built exam.
 *
 * Default prompts live in generate_*()/grade_*() below and are the AI
 * engineer's to refine — either in place or via the
 * `eduai_exam_generate_prompt` / `eduai_exam_grade_prompt` filters.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exam storage, generation and marking.
 */
class EduAI_Exams {

	private const BANDS = array( 'easy', 'medium', 'hard' );

	public static function exams_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'eduai_exams';
	}

	public static function attempts_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'eduai_exam_attempts';
	}

	public static function init(): void {
		add_action( 'delete_user', array( __CLASS__, 'delete_for_user' ) );
	}

	/**
	 * Both tables, dbDelta-style. Called from EduAI_Assistant::install().
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$exams   = self::exams_table();

		$sql = "CREATE TABLE {$exams} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source_label VARCHAR(190) NOT NULL DEFAULT '',
			source_hash CHAR(64) NOT NULL DEFAULT '',
			title VARCHAR(190) NOT NULL DEFAULT '',
			questions LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY user_hash (user_id, source_hash),
			KEY created_at (created_at)
		) ENGINE=InnoDB {$collate};";

		dbDelta( $sql );

		$attempts = self::attempts_table();

		$sql = "CREATE TABLE {$attempts} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			exam_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			answers LONGTEXT NOT NULL,
			results LONGTEXT NOT NULL,
			score DECIMAL(6,2) NOT NULL DEFAULT 0,
			total DECIMAL(6,2) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY exam_user (exam_id, user_id),
			KEY user_id (user_id)
		) ENGINE=InnoDB {$collate};";

		dbDelta( $sql );
	}

	/**
	 * A student's exams and attempts leave with the account.
	 *
	 * @param int $user_id Deleted user.
	 */
	public static function delete_for_user( int $user_id ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::attempts_table(), array( 'user_id' => $user_id ), array( '%d' ) );
		$wpdb->delete( self::exams_table(), array( 'user_id' => $user_id ), array( '%d' ) );
		// phpcs:enable
	}

	/* ---------------------------------------------------------------------
	 * Fetching
	 * ------------------------------------------------------------------ */

	/**
	 * One exam row with questions decoded, or null.
	 *
	 * @param int $exam_id Row id.
	 */
	public static function get( int $exam_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM ' . self::exams_table() . ' WHERE id = %d', $exam_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		// Stored as { schema_version, questions } since docs/06 §5; the bare
		// array shape predates that and is read for rows written before it.
		$decoded = json_decode( (string) $row['questions'], true );

		if ( is_array( $decoded ) && isset( $decoded['questions'] ) && is_array( $decoded['questions'] ) ) {
			$row['schema_version'] = (int) ( $decoded['schema_version'] ?? 1 );
			$row['questions']      = $decoded['questions'];
		} else {
			$row['schema_version'] = 1;
			$row['questions']      = $decoded;
		}

		return is_array( $row['questions'] ) ? $row : null;
	}

	/**
	 * The newest exam this user already generated from identical material.
	 *
	 * @param int    $user_id Owner.
	 * @param string $hash    sha256 of the extracted text (or file bytes).
	 */
	public static function find_by_hash( int $user_id, string $hash ): ?array {
		global $wpdb;

		if ( '' === $hash ) {
			return null;
		}

		$id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT id FROM ' . self::exams_table() . ' WHERE user_id = %d AND source_hash = %s ORDER BY id DESC LIMIT 1',
				$user_id,
				$hash
			)
		);

		return $id ? self::get( $id ) : null;
	}

	/**
	 * Attempts for one exam, newest first.
	 *
	 * @param int $exam_id Exam row id.
	 */
	public static function attempts_for( int $exam_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT id, score, total, results, created_at FROM ' . self::attempts_table() . ' WHERE exam_id = %d ORDER BY id DESC LIMIT 20',
				$exam_id
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return array(
					'id'         => (int) $row['id'],
					'score'      => (float) $row['score'],
					'total'      => (float) $row['total'],
					'results'    => json_decode( (string) $row['results'], true ) ?: array(),
					'created_at' => (string) $row['created_at'],
				);
			},
			$rows ?: array()
		);
	}

	/**
	 * A student's own attempts, newest first, with the exam they belong to.
	 *
	 * Filtered on the ATTEMPT's user_id, not the exam's: the person who sat the
	 * paper is who the mark belongs to. Attempts written against user 0 by the
	 * generate()/grade() composition bug are therefore invisible here, which is
	 * correct — nobody sat them.
	 *
	 * @param int $user_id Student.
	 * @param int $limit   Cap.
	 */
	public static function history_for_user( int $user_id, int $limit = 20 ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT a.id AS attempt_id, a.exam_id, a.score, a.total, a.created_at,
						e.title, e.source_label
				 FROM ' . self::attempts_table() . ' a
				 INNER JOIN ' . self::exams_table() . ' e ON e.id = a.exam_id
				 WHERE a.user_id = %d
				 ORDER BY a.id DESC
				 LIMIT %d',
				$user_id,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$score = (float) $row['score'];
				$total = (float) $row['total'];

				return array(
					'attempt_id'   => (int) $row['attempt_id'],
					'exam_id'      => (int) $row['exam_id'],
					'title'        => (string) $row['title'],
					'source_label' => (string) $row['source_label'],
					'score'        => $score,
					'total'        => $total,
					// Percent is derived here and nowhere else. A stored percent
					// is a second source of truth that drifts from the marks,
					// the same reason total() is computed rather than stored.
					'percent'      => $total > 0 ? (int) round( $score / $total * 100 ) : 0,
					'created_at'   => (string) $row['created_at'],
				);
			},
			$rows ?: array()
		);
	}

	/**
	 * Headline numbers for one student: how many papers, the average, the best,
	 * and when they last sat one.
	 *
	 * Averages the PERCENTAGES rather than dividing total score by total marks,
	 * because papers differ in length: a 20-mark paper should not outweigh a
	 * 10-mark one when the question is "how is this student doing".
	 *
	 * @param int $user_id Student.
	 */
	public static function stats_for_user( int $user_id ): array {
		global $wpdb;

		$empty = array( 'taken' => 0, 'average' => null, 'best' => null, 'last_at' => '' );

		if ( $user_id <= 0 ) {
			return $empty;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT COUNT(*) AS taken,
						AVG( CASE WHEN total > 0 THEN score / total * 100 END ) AS average,
						MAX( CASE WHEN total > 0 THEN score / total * 100 END ) AS best,
						MAX( created_at ) AS last_at
				 FROM ' . self::attempts_table() . '
				 WHERE user_id = %d',
				$user_id
			),
			ARRAY_A
		);

		if ( ! $row || 0 === (int) $row['taken'] ) {
			return $empty;
		}

		return array(
			'taken'   => (int) $row['taken'],
			'average' => null === $row['average'] ? null : (int) round( (float) $row['average'] ),
			'best'    => null === $row['best'] ? null : (int) round( (float) $row['best'] ),
			'last_at' => (string) $row['last_at'],
		);
	}

	/**
	 * The same aggregates for every student who has sat anything, keyed by user
	 * id, for the teacher's tracking screen.
	 *
	 * Deliberately returns ONLY students who have attempts. The caller starts
	 * from the user list and merges, so students who have sat nothing still
	 * appear — with zeroes. Those are the ones a teacher most needs to see, and
	 * a query driven from the attempts table can never surface them.
	 */
	public static function roster(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT user_id,
					COUNT(*) AS taken,
					AVG( CASE WHEN total > 0 THEN score / total * 100 END ) AS average,
					MAX( CASE WHEN total > 0 THEN score / total * 100 END ) AS best,
					MAX( created_at ) AS last_at
			 FROM ' . self::attempts_table() . '
			 WHERE user_id > 0
			 GROUP BY user_id',
			ARRAY_A
		);

		$out = array();

		foreach ( $rows ?: array() as $row ) {
			$out[ (int) $row['user_id'] ] = array(
				'taken'   => (int) $row['taken'],
				'average' => null === $row['average'] ? null : (int) round( (float) $row['average'] ),
				'best'    => null === $row['best'] ? null : (int) round( (float) $row['best'] ),
				'last_at' => (string) $row['last_at'],
			);
		}

		return $out;
	}

	/**
	 * The shape a student may see before sitting the exam: no answer_index,
	 * no expected answer, no explanation. `marks` stays so the form can show
	 * each question's weight (MCQ is always 1).
	 *
	 * @param array $questions Stored questions.
	 */
	public static function redact( array $questions ): array {
		return array_map(
			static function ( array $q ): array {
				$out = array(
					'id'       => $q['id'],
					'band'     => $q['band'],
					'type'     => $q['type'],
					'question' => $q['question'],
					'marks'    => (int) ( $q['marks'] ?? 1 ),
				);

				if ( 'mcq' === $q['type'] ) {
					$out['options'] = $q['options'];
				}

				return $out;
			},
			$questions
		);
	}

	/**
	 * Marks available — always computed from the questions, never stored:
	 * a declared total is a second source of truth that drifts (docs/06 §5.1).
	 *
	 * @param array $questions Stored questions.
	 */
	public static function total( array $questions ): float {
		return (float) array_sum( array_map( static fn( array $q ) => (float) ( $q['marks'] ?? 1 ), $questions ) );
	}

	/**
	 * The docs/07 §2 envelope — the ONLY projection of an exam row that may
	 * reach the browser before marking. Everything else would ship the answer
	 * key to the Network tab.
	 *
	 * @param array $exam   Exam row (get(), find_by_hash() or fixture()).
	 * @param bool  $reused Served from source_hash rather than generated.
	 */
	public static function for_client( array $exam, bool $reused = false ): array {
		return array(
			'exam_id'      => (int) $exam['id'],
			'title'        => (string) $exam['title'],
			'source_label' => (string) ( $exam['source_label'] ?? '' ),
			'reused'       => $reused,
			'total'        => self::total( $exam['questions'] ),
			'questions'    => self::redact( $exam['questions'] ),
		);
	}

	/**
	 * The docs/07 §3 envelope for a stored attempt, bands and percent rebuilt
	 * from the stored result rows.
	 *
	 * @param array $exam    Exam row.
	 * @param array $attempt Row from attempts_for().
	 */
	public static function attempt_for_client( array $exam, array $attempt ): array {
		$bands = array();

		foreach ( $attempt['results'] as $row ) {
			$band = (string) ( $row['band'] ?? '' );
			if ( '' === $band ) {
				continue;
			}
			if ( ! isset( $bands[ $band ] ) ) {
				$bands[ $band ] = array( 'awarded' => 0.0, 'of' => 0.0 );
			}
			$bands[ $band ]['awarded'] += (float) ( $row['awarded'] ?? 0 );
			$bands[ $band ]['of']      += (float) ( $row['of'] ?? 0 );
		}

		$score = (float) $attempt['score'];
		$total = (float) $attempt['total'];

		return array(
			'exam_id'    => (int) $exam['id'],
			'attempt_id' => (int) $attempt['id'],
			'score'      => $score,
			'total'      => $total,
			'percent'    => $total > 0 ? (int) round( $score / $total * 100 ) : 0,
			'results'    => $attempt['results'],
			'bands'      => $bands,
		);
	}

	/**
	 * The committed sample exam (fixtures/exam-sample.json) as a pseudo-row
	 * with id 0, so GET /exam/0 lets the front-end build against real shapes
	 * before any generation has ever run. Never stored, never gradeable.
	 */
	public static function fixture(): ?array {
		$path = EDUAI_DIR . 'fixtures/exam-sample.json';

		if ( ! is_readable( $path ) ) {
			return null;
		}

		$decoded = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! is_array( $decoded ) || empty( $decoded['questions'] ) || ! is_array( $decoded['questions'] ) ) {
			return null;
		}

		return array(
			'id'             => 0,
			'user_id'        => get_current_user_id(),
			'source_label'   => 'exam-sample.json',
			'title'          => (string) ( $decoded['title'] ?? 'Sample exam' ),
			'questions'      => $decoded['questions'],
			'schema_version' => (int) ( $decoded['schema_version'] ?? 1 ),
			'created_at'     => '',
		);
	}

	/* ---------------------------------------------------------------------
	 * Generation
	 * ------------------------------------------------------------------ */

	/**
	 * Band counts for an exam length: 40% easy, 40% medium, rest hard.
	 *
	 * @param int $count Total questions.
	 */
	public static function band_mix( int $count ): array {
		$easy   = (int) round( $count * 0.4 );
		$medium = (int) round( $count * 0.4 );

		return array(
			'easy'   => $easy,
			'medium' => $medium,
			'hard'   => max( 1, $count - $easy - $medium ),
		);
	}

	/**
	 * Generate an exam from lecture content and store it.
	 *
	 * @param int    $user_id Owner.
	 * @param array  $content Content blocks (source text or a document block).
	 * @param string $label   Human name of the source.
	 * @param string $hash    sha256 identifying the source for reuse.
	 * @param int    $count   Question count (validated against the allowlist by the caller).
	 * @param string $title   Optional student-supplied title; the model's when blank.
	 * @return array|WP_Error { id, title, source_label, questions } or an error.
	 */
	public static function generate( int $user_id, array $content, string $label, string $hash, int $count, string $title = '' ) {
		$bands = self::band_mix( $count );

		// Filled by the validator passed to json_from_model() below, which is
		// the only thing allowed to accept a generated exam.
		$exam = null;

		/**
		 * Filter the system prompt used to generate an exam.
		 *
		 * @param string $prompt Default prompt.
		 */
		$system = (string) apply_filters( 'eduai_exam_generate_prompt', self::generate_system() );

		$content[] = array(
			'type' => 'text',
			'text' => self::generate_instruction( $count, $bands ),
		);

		$out = self::json_from_model(
			array( array( 'role' => 'user', 'content' => $content ) ),
			$system,
			array(
				'model'       => 'strongest',
				// ~450 tokens per question covers an MCQ with options and
				// explanation, or a short with its mark scheme — plus slack
				// for reasoning tokens, which Groq bills to this budget.
				'max_tokens'  => max( 3000, $count * 450 ),
				'temperature' => 0.3,
			),
			// Validation runs inside the call so that a near-miss — right shape,
			// wrong band counts — gets the one repair retry with the actual
			// reason fed back, instead of failing outright and charging the
			// student's most expensive request for nothing. The accepted exam
			// is captured here rather than normalised a second time afterwards.
			static function ( $json ) use ( $count, &$exam ) {
				$candidate = self::normalize_exam( $json, $count );

				if ( is_wp_error( $candidate ) ) {
					return $candidate->get_error_message();
				}

				$exam = $candidate;
				return null;
			}
		);

		if ( is_wp_error( $out ) ) {
			return $out;
		}

		// json_from_model() only returns success once the validator above has
		// accepted the payload, so this is belt-and-braces against a future
		// caller wiring it up without one — never store an unvalidated exam.
		if ( ! is_array( $exam ) ) {
			return new WP_Error(
				'eduai_exam_invalid',
				__( 'The generated exam could not be validated. Please try again.', 'eduai' ),
				array( 'status' => 500 )
			);
		}

		// A student-supplied title beats the model's.
		if ( '' !== trim( $title ) ) {
			$exam['title'] = trim( $title );
		}

		$exam_id = self::store_prepared( $user_id, $label, $hash, $exam );

		EduAI_Conversation::add( $user_id, 'exam', 'user', sprintf( '[Generate exam: %s]', $label ?: __( 'pasted text', 'eduai' ) ) );
		EduAI_Conversation::add( $user_id, 'exam', 'assistant', $exam['title'], array(), $out['usage'] );

		// user_id rides along so generate() and grade() compose: grade()
		// logs against $exam['user_id'], and without this key a script
		// chaining the two silently wrote attempts against user 0.
		return array(
			'id'           => $exam_id,
			'user_id'      => $user_id,
			'title'        => $exam['title'],
			'source_label' => $label,
			'questions'    => $exam['questions'],
		);
	}

	/**
	 * Write a validated exam and return its id.
	 *
	 * Extracted from generate() so a second caller can materialise an exam
	 * that was authored rather than generated — a question bank on a study
	 * material, which has questions already and no model call to make. The
	 * point of sharing this rather than writing a second INSERT is that
	 * everything downstream keys off this row's shape: redaction, per-user
	 * ownership, marking, the roster. A parallel writer would inherit none
	 * of it and would drift the first time this one changed.
	 *
	 * DELIBERATELY NOT MOVED: the two EduAI_Conversation::add() calls that
	 * follow this in generate(). The second logs $out['usage'] — the token
	 * cost of the model call — which does not exist for an authored bank and
	 * cannot exist in here. Pulling them in would have been one line too far
	 * and a fatal for the caller that has no $out.
	 *
	 * No behaviour change: same table, same columns, same formats, same
	 * order.
	 *
	 * @param int    $user_id Owner.
	 * @param string $label   Human name of the source.
	 * @param string $hash    Dedupe key.
	 * @param array  $exam    Validated { title, questions }.
	 * @return int New exam id.
	 */
	public static function store_prepared( int $user_id, string $label, string $hash, array $exam ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::exams_table(),
			array(
				'user_id'      => $user_id,
				'source_label' => mb_substr( $label, 0, 190 ),
				'source_hash'  => $hash,
				'title'        => mb_substr( $exam['title'], 0, 190 ),
				'questions'    => wp_json_encode( array(
					'schema_version' => 1,
					'questions'      => $exam['questions'],
				) ),
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/* ---------------------------------------------------------------------
	 * Marking
	 * ------------------------------------------------------------------ */

	/**
	 * Mark a set of answers against a stored exam.
	 *
	 * MCQ is compared in PHP — deterministic, instant, free, and defensible
	 * when a mark is disputed. Short answers go to the model in one batched
	 * call at temperature 0; blank ones are marked 0 locally and never spend
	 * a token.
	 *
	 * @param array $exam    Row from get(), questions decoded.
	 * @param array $answers [ { id, choice } | { id, text } ] per docs/07 §3.
	 * @return array|WP_Error { score, total, percent, bands, results } or an error.
	 */
	public static function grade( array $exam, array $answers ) {
		$by_id = array();
		foreach ( $answers as $entry ) {
			if ( is_array( $entry ) && isset( $entry['id'] ) ) {
				// Ids not in this exam are ignored later, per docs/07 §3 —
				// a stale tab must not poison a mark.
				$by_id[ (int) $entry['id'] ] = $entry;
			}
		}

		$results = array();
		$pending = array();
		$score   = 0.0;
		$total   = 0.0;

		foreach ( $exam['questions'] as $q ) {
			$entry = $by_id[ $q['id'] ] ?? array();
			$marks = (int) ( $q['marks'] ?? 1 );

			if ( 'mcq' === $q['type'] ) {
				$total += $marks;

				$given   = $entry['choice'] ?? $entry['answer'] ?? null;
				$index   = is_numeric( $given ) ? (int) $given : null;
				$correct = ( null !== $index && $index === $q['answer_index'] );
				$score  += $correct ? $marks : 0;

				$results[ $q['id'] ] = array(
					'id'           => $q['id'],
					'type'         => 'mcq',
					'band'         => $q['band'],
					'awarded'      => $correct ? $marks : 0,
					'of'           => $marks,
					'correct'      => $correct,
					'your_choice'  => $index,
					'answer_index' => $q['answer_index'],
					'explanation'  => $q['explanation'],
				);
				continue;
			}

			$total += $marks;

			$text = $entry['text'] ?? $entry['answer'] ?? '';
			$text = is_string( $text ) ? trim( wp_strip_all_tags( $text ) ) : '';
			$text = mb_substr( $text, 0, 1200 );

			// Blank scores 0 locally — and so does punctuation. Real submissions
			// have included ",," and ",,,", which were batched to the model and
			// billed for like an answer.
			//
			// The test is a character class, not a length floor: a valid short
			// answer can be very short — "pH", "9%", "O(n log n)" — and a
			// minimum-characters rule would zero those without ever asking.
			// \p{L} and \p{N} with /u rather than [A-Za-z0-9], so "π", "√2",
			// "°C" and an answer written in Arabic all reach the marker.
			//
			// Symbols (\p{S}) count as content too, because "∞" is a complete
			// and correct answer to "what is lim(x→0) 1/x²". That does let a
			// lone "=" or "+" through to be marked, and the trade is not close:
			// a wrongly-zeroed correct answer costs a student marks and tells
			// them their answer contained no words or numbers, which is both
			// wrong and baffling; a junk symbol reaching the marker costs a
			// fraction of a token in a call that was being made anyway, and
			// comes back 0. Deciding whether a symbol answers the question is
			// the marker's job — this only skips what cannot answer anything.
			// Punctuation stays excluded: "!!" and "..." are \p{P}, not \p{S}.
			if ( '' === $text || ! preg_match( '/[\p{L}\p{N}\p{S}]/u', $text ) ) {
				$results[ $q['id'] ] = array(
					'id'        => $q['id'],
					'type'      => 'short',
					'band'      => $q['band'],
					'awarded'   => 0,
					'of'        => $marks,
					'comment'   => '' === $text
						? __( 'No answer given.', 'eduai' )
						: __( 'No answer given — that response was punctuation only.', 'eduai' ),
					'expected'  => $q['expected'],
					// Echo it back, don't blank it. Hardcoding '' was right when
					// this branch only caught genuinely empty answers; once it
					// also catches punctuation, a student who typed ",,," would
					// see an empty box beside "that response was punctuation
					// only" and no evidence of what the mark was given for.
					// docs/07 §3 promises your_text is what they wrote. $text is
					// already stripped, trimmed and length-capped, and is '' for
					// a truly blank answer, so that case is unchanged.
					'your_text' => $text,
				);
				continue;
			}

			$pending[ $q['id'] ] = array(
				'id'       => $q['id'],
				'question' => $q['question'],
				'expected' => $q['expected'],
				'marks'    => $marks,
				'student'  => $text,
			);
		}

		if ( $pending ) {
			$marked = self::grade_short_answers( array_values( $pending ) );

			if ( is_wp_error( $marked ) ) {
				return $marked;
			}

			foreach ( $pending as $id => $item ) {
				$q = null;
				foreach ( $exam['questions'] as $candidate ) {
					if ( $candidate['id'] === $id ) {
						$q = $candidate;
						break;
					}
				}

				if ( ! isset( $marked['results'][ $id ] ) ) {
					// docs/06 §5.2: a short the marker skipped scores 0 and is
					// shown as ungraded — it stays in the total and must not
					// fail the whole attempt.
					$results[ $id ] = array(
						'id'        => $id,
						'type'      => 'short',
						'band'      => $q['band'],
						'awarded'   => 0,
						'of'        => $q['marks'],
						'comment'   => __( 'Ungraded — the marker returned no verdict for this answer, so it counts as 0. Resubmitting will mark it again.', 'eduai' ),
						'expected'  => $q['expected'],
						'your_text' => $item['student'],
					);
					continue;
				}

				$verdict = $marked['results'][ $id ];

				// docs/06 §5.2: clamp to [0, marks] with at most one decimal;
				// the exam's marks value is the authority, never the model's "of".
				$awarded = min( (float) $q['marks'], max( 0.0, round( (float) $verdict['awarded'], 1 ) ) );
				$score  += $awarded;

				$results[ $id ] = array(
					'id'        => $id,
					'type'      => 'short',
					'band'      => $q['band'],
					'awarded'   => $awarded,
					'of'        => $q['marks'],
					'comment'   => $verdict['comment'],
					'expected'  => $q['expected'],
					'your_text' => $item['student'],
				);
			}

			EduAI_Conversation::add( (int) $exam['user_id'], 'exam', 'user', sprintf( '[Sit exam #%d]', (int) $exam['id'] ) );
			EduAI_Conversation::add( (int) $exam['user_id'], 'exam', 'assistant', sprintf( '%.1f / %.1f', $score, $total ), array(), $marked['usage'] );
		}

		// Report in question order, with every key present on every result.
		//
		// The three branches above each build the fields their own question type
		// has, which left `correct` absent on short answers and `comment` absent
		// on MCQs. A renderer written against that has to test for existence on
		// every field, and the one test it will actually write — `if
		// (r.correct)` — reads a missing key as false and marks a full-credit
		// short answer wrong. So the shape is normalised in one place here:
		// same keys, every result, whatever the type.
		//
		// `correct` stays tri-state on purpose. Short answers carry partial
		// credit and have no true/false reading, so they report null and the
		// client is told to render them from awarded vs of. Collapsing that to a
		// boolean would mean deciding a pass mark here, which is the lecturer's
		// call and not ours.
		$ordered = array();
		$bands   = array();

		foreach ( $exam['questions'] as $q ) {
			if ( ! isset( $results[ $q['id'] ] ) ) {
				continue;
			}

			$result = $results[ $q['id'] ] + array(
				'correct'      => null,
				'your_choice'  => null,
				'your_text'    => null,
				'answer_index' => null,
				'expected'     => '',
				'explanation'  => '',
				'comment'      => '',
			);

			$band = $result['band'];
			if ( ! isset( $bands[ $band ] ) ) {
				$bands[ $band ] = array( 'awarded' => 0.0, 'of' => 0.0 );
			}
			$bands[ $band ]['awarded'] += (float) $result['awarded'];
			$bands[ $band ]['of']      += (float) $result['of'];

			$ordered[] = $result;
		}

		return array(
			'score'   => round( $score, 1 ),
			'total'   => $total,
			'percent' => $total > 0 ? (int) round( $score / $total * 100 ) : 0,
			'bands'   => $bands,
			'results' => $ordered,
		);
	}

	/**
	 * Store one attempt, returning its id.
	 *
	 * @param array $exam    Exam row.
	 * @param array $answers As submitted.
	 * @param array $graded  From grade().
	 */
	public static function store_attempt( array $exam, array $answers, array $graded ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::attempts_table(),
			array(
				'exam_id'    => (int) $exam['id'],
				'user_id'    => (int) $exam['user_id'],
				'answers'    => wp_json_encode( $answers ),
				'results'    => wp_json_encode( $graded['results'] ),
				'score'      => $graded['score'],
				'total'      => $graded['total'],
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%f', '%f', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * One batched marking call for the short answers.
	 *
	 * @param array $items [ { id, question, expected, marks, student } ].
	 * @return array|WP_Error { results: id => { awarded, comment }, usage }.
	 */
	private static function grade_short_answers( array $items ) {
		/**
		 * Filter the system prompt used to mark short answers.
		 *
		 * @param string $prompt Default prompt.
		 */
		$system = (string) apply_filters( 'eduai_exam_grade_prompt', self::grade_system() );

		$out = self::json_from_model(
			array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => self::grade_instruction( $items ),
						),
					),
				),
			),
			$system,
			array(
				'model'       => 'strongest',
				'max_tokens'  => 1500,
				'temperature' => 0,
			)
		);

		if ( is_wp_error( $out ) ) {
			return $out;
		}

		$raw = $out['json']['results'] ?? null;

		if ( ! is_array( $raw ) ) {
			return new WP_Error(
				'eduai_exam_grading',
				__( 'Marking came back in an unexpected shape. Nothing was saved — please submit again.', 'eduai' ),
				array( 'status' => 500 )
			);
		}

		$results = array();
		foreach ( $raw as $entry ) {
			if ( is_array( $entry ) && isset( $entry['id'], $entry['awarded'] ) ) {
				$comment = mb_substr( trim( wp_strip_all_tags( (string) ( $entry['comment'] ?? '' ) ) ), 0, 300 );

				// The report renders `comment` unconditionally, so an empty one
				// is a blank space beside a mark the student is trying to
				// understand. The prompt asks for a comment on every item
				// including full marks; this is the floor under that, because a
				// prompt is a request and a contract needs a guarantee.
				if ( '' === $comment ) {
					$comment = __( 'Marked — no comment was returned for this answer.', 'eduai' );
				}

				$results[ (int) $entry['id'] ] = array(
					'awarded' => (float) $entry['awarded'],
					'comment' => $comment,
				);
			}
		}

		return array(
			'results' => $results,
			'usage'   => $out['usage'],
		);
	}

	/* ---------------------------------------------------------------------
	 * Strict-JSON plumbing
	 * ------------------------------------------------------------------ */

	/**
	 * Call the model expecting a JSON object; on a parse failure, retry once
	 * with the malformed output fed back, then give up honestly.
	 *
	 * @param array         $messages Message list for EduAI_Claude::message().
	 * @param string        $system   System prompt.
	 * @param array         $args     Model args (tier, max_tokens, temperature).
	 * @param callable|null $validate Given the decoded JSON, returns null when it
	 *                                is usable or a one-line reason when it is
	 *                                not. The reason is fed back on the retry.
	 * @return array|WP_Error { json, usage } or an error.
	 */
	private static function json_from_model( array $messages, string $system, array $args, ?callable $validate = null ) {
		$usage  = array();
		$result = self::call_and_track( $messages, $system, $args, $usage );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Truncation is not malformed JSON. Feeding a length-cut output back
		// with "return valid JSON" spends a second full generation and cuts
		// off at the same place — the expensive call burns double for the
		// same failure. One fresh try with more room instead. The gateway
		// normalises the key but not the value: Anthropic says 'max_tokens',
		// the OpenAI format says 'length'.
		if ( self::was_truncated( $result ) ) {
			$args['max_tokens'] = min( 16000, (int) ceil( (int) ( $args['max_tokens'] ?? 4000 ) * 1.5 ) );

			$result = self::call_and_track( $messages, $system, $args, $usage );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( self::was_truncated( $result ) ) {
				return new WP_Error(
					'eduai_exam_json',
					__( 'The output did not fit in the space the model was given, even after raising it. Try a shorter paper or less source material.', 'eduai' ),
					array( 'status' => 500 )
				);
			}
		}

		$json = self::decode_json_block( (string) $result['text'] );

		// Two ways the reply can be unusable, and they are not the same thing.
		// Malformed JSON is the obvious one. The likelier one from a strong
		// model — and the one docs/06 §5.2 exists for — is JSON that parses
		// perfectly and is still wrong: five easy questions where four were
		// asked for, an answer_index of 4, ids that do not run 1..n. That gets
		// the same single retry, because it is just as fixable and burning the
		// most expensive call in the product on an unrepaired near-miss is
		// worse than asking once more.
		$reason = null === $json ? 'That was not valid JSON.' : ( $validate ? $validate( $json ) : null );

		if ( null !== $reason ) {
			// The reason goes back verbatim. "That was not valid JSON" in
			// answer to a band-count error is a statement the model can see is
			// false, and it will resend the same thing; "there are 5 easy
			// questions, exactly 4 are required" is something it can act on.
			$messages[] = array( 'role' => 'assistant', 'content' => (string) $result['text'] );
			$messages[] = array(
				'role'    => 'user',
				'content' => $reason . ' Send the whole thing again, corrected, as ONLY one valid JSON object'
					. ' — no markdown fences, no commentary, nothing before or after it.',
			);

			$result = self::call_and_track( $messages, $system, $args, $usage );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$json = self::decode_json_block( (string) $result['text'] );

			$reason = null === $json ? 'invalid JSON' : ( $validate ? $validate( $json ) : null );

			if ( null !== $reason ) {
				// 500 per docs/07 §5 — 502 stays reserved for the provider
				// itself being unreachable (EduAI_Claude's own errors).
				return new WP_Error(
					'eduai_exam_json',
					sprintf(
						/* translators: %s: why the second attempt was unusable. */
						__( 'The model could not produce a usable exam this time (%s). Please try again.', 'eduai' ),
						$reason
					),
					array( 'status' => 500 )
				);
			}
		}

		return array(
			'json'  => $json,
			'usage' => $usage,
		);
	}

	/**
	 * The completion hit its token ceiling rather than finishing.
	 *
	 * @param array $result Return of EduAI_Claude::message().
	 */
	private static function was_truncated( array $result ): bool {
		return in_array( (string) ( $result['stop_reason'] ?? '' ), array( 'length', 'max_tokens' ), true );
	}

	/**
	 * One model call with the usage totals accumulated across retries, so the
	 * conversation log charges the whole generation, not just the last try.
	 *
	 * @param array  $messages Message list.
	 * @param string $system   System prompt.
	 * @param array  $args     Model args.
	 * @param array  $usage    Running totals, updated in place.
	 * @return array|WP_Error
	 */
	private static function call_and_track( array $messages, string $system, array $args, array &$usage ) {
		$result = EduAI_Claude::message( $messages, $system, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( array( 'input_tokens', 'output_tokens' ) as $k ) {
			$usage[ $k ] = (int) ( $usage[ $k ] ?? 0 ) + (int) ( $result['usage'][ $k ] ?? 0 );
		}

		return $result;
	}

	/**
	 * Best-effort JSON object out of model text: strip fences, take the
	 * outermost braces, decode.
	 *
	 * @param string $text Raw model output.
	 */
	private static function decode_json_block( string $text ): ?array {
		$text = trim( $text );
		$text = (string) preg_replace( '/^```[a-z]*\s*|\s*```$/i', '', $text );

		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );

		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}

		$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Validate and canonicalise a generated exam. Anything structurally wrong
	 * fails the whole exam — a half-built exam is worse than an error.
	 *
	 * @param array $raw   Decoded model output.
	 * @param int   $count Requested question count.
	 * @return array|WP_Error { title, questions } or an error.
	 */
	private static function normalize_exam( array $raw, int $count ) {
		$fail = static function ( string $reason ) {
			return new WP_Error(
				'eduai_exam_invalid',
				sprintf(
					/* translators: %s: what was wrong with the generated exam. */
					__( 'The generated exam was not usable (%s). Please try again.', 'eduai' ),
					$reason
				),
				array( 'status' => 500 )
			);
		};

		// docs/06 §5: exams are stored, so shape changes meet old rows — the
		// version is required at birth or migrations are impossible later.
		if ( 1 !== (int) ( $raw['schema_version'] ?? 0 ) ) {
			return $fail( 'missing or unknown schema_version' );
		}

		$title = trim( wp_strip_all_tags( (string) ( $raw['title'] ?? '' ) ) );
		if ( '' === $title ) {
			return $fail( 'missing title' );
		}

		if ( ! isset( $raw['questions'] ) || ! is_array( $raw['questions'] ) ) {
			return $fail( 'no questions' );
		}

		if ( count( $raw['questions'] ) !== $count ) {
			return $fail( sprintf( '%d questions instead of %d', count( $raw['questions'] ), $count ) );
		}

		$questions = array();
		$seen_ids  = array();

		foreach ( $raw['questions'] as $q ) {
			if ( ! is_array( $q ) ) {
				return $fail( 'malformed question' );
			}

			$id = (int) ( $q['id'] ?? 0 );
			if ( $id < 1 || $id > $count || isset( $seen_ids[ $id ] ) ) {
				return $fail( 'bad question ids' );
			}
			$seen_ids[ $id ] = true;

			$band = strtolower( trim( (string) ( $q['band'] ?? '' ) ) );
			if ( ! in_array( $band, self::BANDS, true ) ) {
				return $fail( 'bad band' );
			}

			$type     = strtolower( trim( (string) ( $q['type'] ?? '' ) ) );
			$question = trim( wp_strip_all_tags( (string) ( $q['question'] ?? '' ) ) );

			if ( '' === $question ) {
				return $fail( 'empty question text' );
			}

			if ( 'mcq' === $type ) {
				$options = array_values( array_map( static fn( $o ) => trim( wp_strip_all_tags( (string) $o ) ), (array) ( $q['options'] ?? array() ) ) );

				if ( 4 !== count( $options ) || in_array( '', $options, true ) ) {
					return $fail( 'an MCQ without exactly four options' );
				}

				$answer_index = $q['answer_index'] ?? null;
				if ( ! is_numeric( $answer_index ) || (int) $answer_index < 0 || (int) $answer_index > 3 ) {
					return $fail( 'an MCQ answer index outside 0–3' );
				}

				$explanation = trim( wp_strip_all_tags( (string) ( $q['explanation'] ?? '' ) ) );
				if ( '' === $explanation ) {
					return $fail( 'an MCQ without an explanation' );
				}

				$mcq_marks = $q['marks'] ?? 1;
				if ( ! is_numeric( $mcq_marks ) || (int) $mcq_marks < 1 ) {
					return $fail( 'non-positive marks on an MCQ' );
				}

				$questions[] = array(
					'id'           => $id,
					'band'         => $band,
					'type'         => 'mcq',
					'question'     => $question,
					'options'      => $options,
					'answer_index' => (int) $answer_index,
					'marks'        => min( 5, (int) $mcq_marks ),
					'explanation'  => $explanation,
				);
				continue;
			}

			if ( 'short' === $type ) {
				$expected = trim( wp_strip_all_tags( (string) ( $q['expected'] ?? '' ) ) );
				if ( '' === $expected ) {
					return $fail( 'a short question without an expected answer' );
				}

				$marks = $q['marks'] ?? 1;
				if ( ! is_numeric( $marks ) ) {
					return $fail( 'non-numeric marks' );
				}
				$marks = max( 1, min( 5, (int) $marks ) );

				// `expected` is a mark scheme: award points carry their marks
				// in brackets — "names the mechanism (1); gives the direction
				// (1)". When two or more award points are present they must
				// sum to `marks`, or the scheme and the allocation disagree
				// and every marking of this question starts from a lie. Two
				// required before enforcing, so a stray parenthesised number
				// in ordinary prose cannot fail a whole generation.
				if ( preg_match_all( '/\(([0-5](?:\.5)?)\)/', $expected, $scheme ) && count( $scheme[1] ) >= 2 ) {
					$scheme_sum = array_sum( array_map( 'floatval', $scheme[1] ) );
					if ( abs( $scheme_sum - $marks ) > 0.01 ) {
						return $fail( sprintf( 'a mark scheme summing to %s on a %d-mark question', rtrim( rtrim( number_format( $scheme_sum, 1 ), '0' ), '.' ), $marks ) );
					}
				}

				$questions[] = array(
					'id'       => $id,
					'band'     => $band,
					'type'     => 'short',
					'question' => $question,
					'expected' => $expected,
					'marks'    => $marks,
				);
				continue;
			}

			return $fail( 'unknown question type' );
		}

		// docs/06 §5.1: presentation order is normative — ids run 1..n in the
		// order given, bands grouped easy → medium → hard with the §4 split.
		// The client renders the array verbatim, so order is validated here
		// rather than repaired: a generator that shuffles is a generator that
		// got other things wrong too.
		$band_rank   = array( 'easy' => 0, 'medium' => 1, 'hard' => 2 );
		$band_counts = array( 'easy' => 0, 'medium' => 0, 'hard' => 0 );
		$expected_id = 0;
		$last_rank   = 0;

		foreach ( $questions as $q ) {
			if ( $q['id'] !== ++$expected_id ) {
				return $fail( 'ids not 1..n in presentation order' );
			}
			if ( $band_rank[ $q['band'] ] < $last_rank ) {
				return $fail( 'bands not ordered easy, medium, hard' );
			}
			$last_rank = $band_rank[ $q['band'] ];
			++$band_counts[ $q['band'] ];
		}

		$mix = self::band_mix( $count );
		if ( $band_counts !== $mix ) {
			return $fail( sprintf(
				'band split %d/%d/%d instead of %d/%d/%d',
				$band_counts['easy'],
				$band_counts['medium'],
				$band_counts['hard'],
				$mix['easy'],
				$mix['medium'],
				$mix['hard']
			) );
		}

		return array(
			'title'     => $title,
			'questions' => $questions,
		);
	}

	/* ---------------------------------------------------------------------
	 * Default prompts — the AI engineer's to refine
	 * ------------------------------------------------------------------ */

	private static function generate_system(): string {
		return 'You are an examiner producing a practice exam strictly from the lecture material supplied. '
			. 'Every question must be answerable from that material alone — no outside topics. '
			. 'You return ONLY one valid JSON object: no markdown, no fences, no commentary.'
			. "\n\n" . EduAI_Agents::house_rules_section( 'Notation' );
	}

	private static function generate_instruction( int $count, array $bands ): string {
		$mcq_target = (int) ceil( $count * 0.7 );

		return 'Produce a practice exam from the lecture material above.'
			. "\n\nReturn ONLY this JSON shape:\n"
			. '{ "schema_version": 1, "title": "…", "questions": [' . "\n"
			. '  { "id": 1, "band": "easy", "type": "mcq", "question": "…", "options": ["…","…","…","…"], "answer_index": 0, "marks": 1, "explanation": "…" },' . "\n"
			. '  { "id": 2, "band": "medium", "type": "short", "question": "…", "expected": "…", "marks": 2 } ] }'
			. "\n\nRules:"
			. "\n- \"schema_version\" is always exactly 1."
			. "\n- Exactly {$count} questions, ids 1..{$count} in that order: first {$bands['easy']} easy, then {$bands['medium']} medium, then {$bands['hard']} hard, with \"band\" set accordingly. Do not reorder."
			. "\n- Roughly {$mcq_target} MCQ and the rest short-answer, spread across all three bands."
			. "\n- \"title\": a short exam title taken from the material's topic."
			. "\n- Question text stands alone: no \"Q1:\" prefixes, no references to \"the slide above\", \"the passage\" or \"the material\". A student reading only the question must know what is being asked."

			// The bands are the point of the exercise. Left undefined, a model
			// makes "hard" mean "longer" — same recall question with more words
			// — and the paper stops discriminating between students who
			// understand the material and students who have memorised it.
			. "\n\nWhat the bands mean:"
			. "\n- easy: recall. A definition, a fact, a formula or a term stated directly in the material."
			. "\n- medium: application. One step of work — use the formula, compare two things, pick the right principle for a situation, read a consequence off a stated rule."
			. "\n- hard: reasoning. Combine two or more ideas from the material, explain WHY rather than WHAT, identify an edge case or a limit of validity, or work out what happens when a stated condition fails."

			// A generator left to itself puts the answer first far more often
			// than chance, makes the correct option the longest one, and reaches
			// for "all of the above" whenever it runs out of distractors. All
			// three make the paper guessable without knowing the subject, and the
			// first also hides an off-by-one in the scorer behind a passing test.
			. "\n\nMCQ rules:"
			. "\n- Exactly four options, exactly one of them correct."
			. "\n- \"answer_index\" is 0-BASED: 0 is the first option, 2 is the third, 3 is the last."
			. "\n- Vary which position is correct across the paper. Spread the answers roughly evenly over indices 0, 1, 2 and 3 — do not favour the first option."
			. "\n- The three wrong options must be plausible to someone who half-knows the topic: a common misconception, a neighbouring term, a right method with a wrong value. Obviously silly options make the question free."
			. "\n- Keep all four options about the same length and the same level of detail. A correct answer that is visibly longer or more qualified than the others can be picked without reading the question."
			. "\n- Never use \"all of the above\", \"none of the above\", \"both A and B\" or any option that refers to the other options."
			. "\n- \"marks\" is 1."
			. "\n- \"explanation\": one or two sentences on why that option is right, and where it is useful, why the most tempting wrong one is wrong. The student reads this after marking."

			// `expected` is both the grading key and something the student is
			// shown afterwards. Writing it as a mark scheme rather than a model
			// answer serves both: the grader gets explicit criteria to award
			// against instead of judging prose similarity at temperature 0, and
			// the student sees what each mark was actually for.
			. "\n\nShort-answer rules:"
			. "\n- Answerable in one or two sentences by a student who understood the material."
			. "\n- \"marks\": 1 for easy, 2 for medium, 2 or 3 for hard."
			. "\n- \"expected\" is a MARK SCHEME, not a model answer. For a 1-mark question, state the one thing the answer must contain. For 2 or more marks, list what each mark is for, separated by semicolons, in the form: \"names the mechanism (1); gives the direction of the effect (1)\"."
			. "\n- The marks in \"expected\" must add up to \"marks\" exactly."
			. "\n- Award-worthy points must be things the material actually supports — never require a detail the lecture never gave.";
	}

	private static function grade_system(): string {
		return 'You mark short-answer exam responses against a mark scheme. '
			. 'You are marking a real student whose grade depends on you being consistent: '
			. 'the same answer must earn the same mark every time, and two students who said the same thing '
			. 'in different words must get the same mark. '
			. 'Award for meaning, never for phrasing, length or confidence. '
			. 'You return ONLY one valid JSON object.'
			. "\n\n" . EduAI_Agents::house_rules_section( 'Notation' );
	}

	private static function grade_instruction( array $items ): string {
		return 'Mark these exam responses.'
			. "\n\nEach item gives you the question, a mark scheme in \"expected\", the marks available, and what the student wrote."
			. "\n\nITEMS:\n" . wp_json_encode( $items )

			// "expected" is written as semicolon-separated award points with
			// their marks in brackets, so marking is checking off criteria
			// rather than judging how similar two paragraphs are. That is the
			// difference between a mark that can be defended to a student and
			// one that moves because the model felt differently this time.
			. "\n\nHow to mark:"
			. "\n- \"expected\" lists what each mark is for. Go through those points one at a time and award the marks for the ones the student's answer actually makes."
			. "\n- Award in steps of 0.5. Never award more than the item's \"marks\", and never less than 0."
			. "\n- Full marks when every point is made, even if the wording is nothing like the mark scheme."
			. "\n- Ignore spelling, grammar, capitalisation, notation style and answer length. A correct answer written badly is a correct answer."
			. "\n- A student who says the right thing and then adds something wrong keeps the marks for the right part, unless the addition contradicts it."
			. "\n- Zero for an answer that is wrong, irrelevant, or restates the question. Confident, fluent nonsense earns nothing — fluency is not knowledge."
			. "\n- If the answer is genuinely ambiguous, mark the reading that is most favourable to the student, and say in the comment which reading you took."

			// The client renders `comment` unconditionally, so an empty one is a
			// blank space next to a mark the student is trying to understand.
			. "\n\nThe comment:"
			. "\n- Exactly one line, addressed to the student as \"you\", no preamble and no restating of their answer."
			. "\n- ALWAYS write one, including at full marks — say what they got right, not nothing."
			. "\n- Below full marks, name the specific point from the mark scheme that was missing. \"You did not mention the units\" is useful; \"partially correct\" is not."

			. "\n\nReturn ONLY: { \"results\": [ { \"id\": 1, \"awarded\": 1.5, \"of\": 2, \"comment\": \"…\" } ] }"
			. "\n- Exactly one entry per item above, using those same ids. Do not invent ids, do not omit any."
			. "\n- \"of\" is that item's \"marks\", copied unchanged.";
	}
}
