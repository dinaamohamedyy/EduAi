<?php
/**
 * Teacher-facing view of who has signed up and how they are doing.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Users → Student progress.
 *
 * Lives under Users rather than the assistant's own settings because the
 * question it answers is "how are my students doing", not "how is the plugin
 * configured" — and because that is where a teacher already goes to see who has
 * registered.
 */
class EduAI_Students {

	private const CAP  = 'list_users';
	private const SLUG = 'eduai-students';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	public static function menu(): void {
		add_users_page(
			__( 'Student progress', 'eduai' ),
			__( 'Student progress', 'eduai' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Where a student's detail view lives.
	 *
	 * @param int $user_id Student.
	 */
	public static function url( int $user_id = 0 ): string {
		$url = admin_url( 'users.php?page=' . self::SLUG );

		return $user_id > 0 ? add_query_arg( 'student', $user_id, $url ) : $url;
	}

	public static function render(): void {
		// Belt and braces: add_users_page() already gates on CAP, but a
		// callback that trusts its menu registration is one refactor away from
		// being reachable directly. Student marks are personal data.
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to view student progress.', 'eduai' ) );
		}

		$student = isset( $_GET['student'] ) ? (int) $_GET['student'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.

		echo '<div class="wrap">';

		if ( $student > 0 ) {
			self::render_student( $student );
		} else {
			self::render_roster();
		}

		echo '</div>';
	}

	/**
	 * Everyone who has registered, with their headline numbers.
	 */
	private static function render_roster(): void {
		$roster = EduAI_Exams::roster();

		// Driven from the user list, not from the attempts table: a student who
		// has sat nothing has no row in attempts, and they are precisely who a
		// teacher is looking for. Merging the other way hides them.
		$users = get_users( array(
			'orderby' => 'display_name',
			'number'  => 500,
		) );

		$sat = count( array_filter( $roster, static fn( array $r ) => $r['taken'] > 0 ) );

		printf( '<h1>%s</h1>', esc_html__( 'Student progress', 'eduai' ) );

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: number of accounts 2: number who have sat a paper */
					__( '%1$d registered %2$s, %3$d of whom have sat a practice paper.', 'eduai' ),
					count( $users ),
					_n( 'account', 'accounts', count( $users ), 'eduai' ),
					$sat
				)
			)
		);

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Student', 'eduai' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Role', 'eduai' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Papers sat', 'eduai' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Average', 'eduai' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Best', 'eduai' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Last active', 'eduai' ) );
		echo '</tr></thead><tbody>';

		foreach ( $users as $user ) {
			$row = $roster[ $user->ID ] ?? array( 'taken' => 0, 'average' => null, 'best' => null, 'last_at' => '' );

			echo '<tr>';

			printf(
				'<td><strong><a href="%s">%s</a></strong><br><span class="description">%s</span></td>',
				esc_url( self::url( $user->ID ) ),
				esc_html( $user->display_name ?: $user->user_login ),
				esc_html( $user->user_email )
			);

			printf( '<td>%s</td>', esc_html( implode( ', ', $user->roles ) ) );
			printf( '<td>%d</td>', (int) $row['taken'] );
			printf( '<td>%s</td>', esc_html( self::percent( $row['average'] ) ) );
			printf( '<td>%s</td>', esc_html( self::percent( $row['best'] ) ) );
			printf( '<td>%s</td>', esc_html( self::when( $row['last_at'] ) ) );

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * One student's papers.
	 *
	 * @param int $user_id Student.
	 */
	private static function render_student( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			printf( '<h1>%s</h1>', esc_html__( 'No such student', 'eduai' ) );
			printf( '<p><a href="%s">%s</a></p>', esc_url( self::url() ), esc_html__( 'Back to all students', 'eduai' ) );
			return;
		}

		$stats   = EduAI_Exams::stats_for_user( $user_id );
		$history = EduAI_Exams::history_for_user( $user_id, 100 );

		printf( '<h1>%s</h1>', esc_html( $user->display_name ?: $user->user_login ) );
		printf(
			'<p class="description">%s &middot; <a href="%s">%s</a></p>',
			esc_html( $user->user_email ),
			esc_url( self::url() ),
			esc_html__( 'back to all students', 'eduai' )
		);

		if ( ! $history ) {
			printf( '<p>%s</p>', esc_html__( 'This student has not sat a practice paper yet.', 'eduai' ) );
			return;
		}

		printf(
			'<p><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: 1: papers sat 2: average percent 3: best percent */
					__( '%1$d sat · %2$s average · %3$s best', 'eduai' ),
					$stats['taken'],
					self::percent( $stats['average'] ),
					self::percent( $stats['best'] )
				)
			)
		);

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Paper', 'eduai' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Source', 'eduai' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Marks', 'eduai' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Score', 'eduai' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Sat', 'eduai' ) );
		echo '</tr></thead><tbody>';

		foreach ( $history as $row ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $row['title'] ) );
			printf( '<td>%s</td>', esc_html( $row['source_label'] ?: __( 'pasted text', 'eduai' ) ) );
			printf(
				'<td>%s / %s</td>',
				esc_html( (string) round( $row['score'], 2 ) ),
				esc_html( (string) round( $row['total'], 2 ) )
			);
			printf( '<td>%d%%</td>', (int) $row['percent'] );
			printf( '<td>%s</td>', esc_html( self::when( $row['created_at'] ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';

		// The answers themselves are deliberately not shown. A teacher needs to
		// know how a student is doing; reading their submitted text is a
		// different permission, and this screen does not grant it.
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Marks only. Submitted answers are not shown here.', 'eduai' )
		);
	}

	/**
	 * A percentage, or an em dash when there is nothing to average.
	 *
	 * @param int|null $value Percent.
	 */
	private static function percent( ?int $value ): string {
		return null === $value ? '—' : $value . '%';
	}

	/**
	 * A stored datetime as "3 hours ago", or an em dash.
	 *
	 * @param string $sql Datetime.
	 */
	private static function when( string $sql ): string {
		if ( '' === $sql ) {
			return '—';
		}

		$stamp = strtotime( $sql );

		if ( ! $stamp ) {
			return '—';
		}

		return sprintf(
			/* translators: %s: human-readable time difference */
			__( '%s ago', 'eduai' ),
			human_time_diff( $stamp, current_time( 'timestamp' ) )
		);
	}
}
