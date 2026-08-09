<?php
/**
 * Quiz history and progress reporting, backed by Tutor LMS attempt data.
 *
 * All database access is defensive: if Tutor LMS is not installed, or the
 * schema differs, the shortcode degrades to an explanatory notice rather than
 * throwing an error on the front end.
 *
 * @package ScholarisLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * [scholaris_quiz_history] and [scholaris_dashboard]
 */
class SL_Quiz_History {

	public static function init(): void {
		add_shortcode( 'scholaris_quiz_history', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'scholaris_dashboard', array( __CLASS__, 'dashboard' ) );
	}

	/**
	 * Tutor's attempts table, or an empty string when it does not exist.
	 */
	private static function table(): string {
		global $wpdb;

		static $checked = null;
		if ( null !== $checked ) {
			return $checked;
		}

		$table = $wpdb->prefix . 'tutor_quiz_attempts';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$checked = ( $found === $table ) ? $table : '';
		return $checked;
	}

	/**
	 * Fetch a student's attempts, newest first.
	 *
	 * @param int $user_id Student.
	 * @param int $limit   Max rows (0 = all).
	 * @return array<int,array<string,mixed>>
	 */
	public static function attempts( int $user_id, int $limit = 0 ): array {
		$table = self::table();

		if ( ! $table || ! $user_id ) {
			return array();
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql  = "SELECT attempt_id, quiz_id, course_id, total_questions, total_answered_questions,
					total_marks, earned_marks, attempt_status, attempt_started_at, attempt_ended_at
				 FROM {$table}
				 WHERE user_id = %d AND attempt_status != 'attempt_started'
				 ORDER BY attempt_id DESC";
		$args = array( $user_id );

		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d';
			$args[] = $limit;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		// phpcs:enable

		$out = array();

		foreach ( (array) $rows as $row ) {
			$total  = (float) $row['total_marks'];
			$earned = (float) $row['earned_marks'];
			$pct    = $total > 0 ? round( ( $earned / $total ) * 100, 1 ) : 0.0;

			$passing = self::passing_grade( (int) $row['quiz_id'] );

			$out[] = array(
				'attempt_id' => (int) $row['attempt_id'],
				'quiz_id'    => (int) $row['quiz_id'],
				'quiz_title' => get_the_title( (int) $row['quiz_id'] ) ?: __( 'Quiz', 'scholaris-library' ),
				'course_id'  => (int) $row['course_id'],
				'course'     => get_the_title( (int) $row['course_id'] ) ?: '',
				'questions'  => (int) $row['total_questions'],
				'answered'   => (int) $row['total_answered_questions'],
				'total'      => $total,
				'earned'     => $earned,
				'percent'    => $pct,
				'passed'     => $pct >= $passing,
				'passing'    => $passing,
				'review'     => 'review_required' === $row['attempt_status'],
				'started'    => (string) $row['attempt_started_at'],
				'ended'      => (string) $row['attempt_ended_at'],
				'duration'   => self::duration( (string) $row['attempt_started_at'], (string) $row['attempt_ended_at'] ),
				'url'        => get_permalink( (int) $row['quiz_id'] ) ?: '',
			);
		}

		return $out;
	}

	/**
	 * Read a quiz's passing grade from Tutor's option blob; default to 60%.
	 *
	 * @param int $quiz_id Quiz post ID.
	 */
	private static function passing_grade( int $quiz_id ): float {
		$options = get_post_meta( $quiz_id, 'tutor_quiz_option', true );

		if ( is_array( $options ) && isset( $options['passing_grade'] ) ) {
			return (float) $options['passing_grade'];
		}

		/**
		 * Filter the fallback passing percentage.
		 *
		 * @param float $grade   Percentage.
		 * @param int   $quiz_id Quiz ID.
		 */
		return (float) apply_filters( 'scholaris_default_passing_grade', 60.0, $quiz_id );
	}

	/**
	 * Human-readable attempt duration.
	 */
	private static function duration( string $start, string $end ): string {
		if ( ! $start || ! $end || str_starts_with( $end, '0000' ) ) {
			return '—';
		}

		$seconds = strtotime( $end ) - strtotime( $start );

		if ( $seconds < 0 ) {
			return '—';
		}
		if ( $seconds < 60 ) {
			/* translators: %d: seconds */
			return sprintf( __( '%ds', 'scholaris-library' ), $seconds );
		}

		$minutes = (int) floor( $seconds / 60 );

		if ( $minutes < 60 ) {
			/* translators: %d: minutes */
			return sprintf( __( '%dm', 'scholaris-library' ), $minutes );
		}

		/* translators: 1: hours 2: minutes */
		return sprintf( __( '%1$dh %2$dm', 'scholaris-library' ), (int) floor( $minutes / 60 ), $minutes % 60 );
	}

	/**
	 * Aggregate stats across all attempts.
	 *
	 * @param array $attempts Result of attempts().
	 */
	public static function summarise( array $attempts ): array {
		if ( ! $attempts ) {
			return array(
				'count' => 0, 'average' => 0.0, 'best' => 0.0,
				'passed' => 0, 'quizzes' => 0, 'trend' => 0.0,
			);
		}

		$percents = wp_list_pluck( $attempts, 'percent' );
		$passed   = count( array_filter( wp_list_pluck( $attempts, 'passed' ) ) );

		// Trend: newest three versus the three before them.
		$trend  = 0.0;
		$recent = array_slice( $percents, 0, 3 );
		$older  = array_slice( $percents, 3, 3 );

		if ( $recent && $older ) {
			$trend = round( ( array_sum( $recent ) / count( $recent ) ) - ( array_sum( $older ) / count( $older ) ), 1 );
		}

		return array(
			'count'   => count( $attempts ),
			'average' => round( array_sum( $percents ) / count( $percents ), 1 ),
			'best'    => round( max( $percents ), 1 ),
			'passed'  => $passed,
			'quizzes' => count( array_unique( wp_list_pluck( $attempts, 'quiz_id' ) ) ),
			'trend'   => $trend,
		);
	}

	/**
	 * [scholaris_quiz_history limit="10" show_chart="yes" user_id="0"]
	 *
	 * @param array $atts Attributes.
	 */
	public static function shortcode( $atts = array() ): string {
		$atts = shortcode_atts( array(
			'limit'      => 10,
			'show_chart' => 'yes',
			'show_stats' => 'yes',
			'user_id'    => 0,
		), (array) $atts, 'scholaris_quiz_history' );

		wp_enqueue_style( 'scholaris-library' );

		$user_id = (int) $atts['user_id'] ?: get_current_user_id();

		if ( ! $user_id ) {
			return self::notice(
				__( 'Sign in to see your quiz history', 'scholaris-library' ),
				__( 'Your scores, attempts and progress appear here once you are signed in.', 'scholaris-library' ),
				wp_login_url( get_permalink() ?: home_url( '/' ) ),
				__( 'Sign in', 'scholaris-library' )
			);
		}

		// Only administrators may inspect another student's record.
		if ( $user_id !== get_current_user_id() && ! current_user_can( 'edit_users' ) ) {
			return '';
		}

		if ( ! self::table() ) {
			return self::notice(
				__( 'Quiz data is not available yet', 'scholaris-library' ),
				__( 'Install and activate Tutor LMS, then take a quiz — attempts and scores will be reported here automatically.', 'scholaris-library' )
			);
		}

		$all      = self::attempts( $user_id, 0 );
		$stats    = self::summarise( $all );
		$attempts = (int) $atts['limit'] > 0 ? array_slice( $all, 0, (int) $atts['limit'] ) : $all;

		if ( ! $all ) {
			return self::notice(
				__( 'No quiz attempts yet', 'scholaris-library' ),
				__( 'Take your first quiz and every attempt will be recorded here with its score.', 'scholaris-library' )
			);
		}

		ob_start();
		include SL_DIR . 'templates/quiz-history.php';
		return (string) ob_get_clean();
	}

	/**
	 * [scholaris_dashboard] — stats, history and a library shortcut.
	 */
	public static function dashboard(): string {
		wp_enqueue_style( 'scholaris-library' );

		if ( ! is_user_logged_in() ) {
			return self::notice(
				__( 'Sign in to open your dashboard', 'scholaris-library' ),
				__( 'Track your quiz scores, revisit material and pick up where you left off.', 'scholaris-library' ),
				wp_login_url( get_permalink() ?: home_url( '/' ) ),
				__( 'Sign in', 'scholaris-library' )
			);
		}

		$user = wp_get_current_user();

		ob_start();
		include SL_DIR . 'templates/dashboard.php';
		return (string) ob_get_clean();
	}

	/**
	 * Reusable empty-state block.
	 */
	private static function notice( string $title, string $body, string $url = '', string $label = '' ): string {
		$html = '<div class="sl-notice"><h3>' . esc_html( $title ) . '</h3><p>' . esc_html( $body ) . '</p>';

		if ( $url && $label ) {
			$html .= '<a class="sl-btn sl-btn--primary" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}

		return $html . '</div>';
	}
}
