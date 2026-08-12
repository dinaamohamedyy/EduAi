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

	private const PER_PAGE = 50;

	/**
	 * Rows per page.
	 *
	 * Filterable rather than constant for two reasons, and the second is the
	 * honest one: a site with thousands of accounts may want a different
	 * page, and a constant page size larger than any test fixture means the
	 * paging code never executes in a test. A branch that cannot be reached
	 * is not covered by a suite that passes.
	 */
	private static function per_page(): int {
		return max( 1, (int) apply_filters( 'eduai_students_per_page', self::PER_PAGE ) );
	}

	/**
	 * The roster is a teaching screen, so it opens on the people being
	 * taught. Administrators and instructors hold accounts too and used to
	 * appear among them, which makes "how many students do I have" a question
	 * the screen answers wrongly.
	 */
	private const DEFAULT_ROLE = 'student';

	/**
	 * Single-sourced so the roster query and the "have sat" count cannot end
	 * up searching different columns — that divergence would show up as a
	 * header disagreeing with the table under it, which reads as a data bug
	 * rather than a filter bug.
	 */
	private const SEARCH_COLUMNS = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );

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

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list controls.
		$role   = isset( $_GET['sl_role'] ) ? sanitize_key( wp_unslash( $_GET['sl_role'] ) ) : self::DEFAULT_ROLE;
		$search = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
		$paged  = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
		// phpcs:enable

		// Driven from the user list, not from the attempts table: a student who
		// has sat nothing has no row in attempts, and they are precisely who a
		// teacher is looking for. Merging the other way hides them.
		$args = array(
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => self::per_page(),
			'offset'  => ( $paged - 1 ) * self::per_page(),
		);

		if ( 'all' !== $role ) {
			$args['role'] = $role;
		}

		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = self::SEARCH_COLUMNS;
		}

		// WP_User_Query rather than get_users(), for get_total(): the old code
		// asked for 500 and printed count() of what came back, so on account
		// 501 students silently stopped appearing while the header went on
		// saying 500 — a number that is its own alibi.
		$query = new WP_User_Query( $args );
		$users = (array) $query->get_results();
		$total = (int) $query->get_total();
		$pages = (int) ceil( $total / self::per_page() );

		// Counted across everyone the filter matches, not just this page —
		// otherwise the sentence changes meaning as you page through it. The
		// roster is keyed by people who have actually sat something, so this
		// is bounded by that, not by the account count.
		$sat = 0;

		if ( $roster ) {
			$sat_args = array(
				'include' => array_keys( $roster ),
				'fields'  => 'ID',
				'number'  => -1,
			);

			if ( 'all' !== $role ) {
				$sat_args['role'] = $role;
			}

			// The search belongs here too, and leaving it out produced a
			// sentence that contradicted itself: a search matching two
			// accounts reported "2 accounts matching, 4 of whom have sat a
			// practice paper". Whatever narrows the list has to narrow both
			// halves of the count, or the second number is about a different
			// population than the first.
			if ( '' !== $search ) {
				$sat_args['search']         = '*' . $search . '*';
				$sat_args['search_columns'] = self::SEARCH_COLUMNS;
			}

			$sat = count( get_users( $sat_args ) );
		}
		// An empty `include` means "no restriction" to get_users, not "nobody",
		// so the guard above is load-bearing: without it, a site where nobody
		// has sat a paper would report every account as having sat one.

		printf( '<h1>%s</h1>', esc_html__( 'Student progress', 'eduai' ) );

		printf(
			'<p class="description">%s</p>',
			esc_html(
				'' !== $search
					? sprintf(
						/* translators: 1: number of matches 2: account/accounts 3: the search term 4: number who have sat a paper */
						__( '%1$d %2$s matching "%3$s", %4$d of whom have sat a practice paper.', 'eduai' ),
						$total,
						_n( 'account', 'accounts', $total, 'eduai' ),
						$search,
						$sat
					)
					: sprintf(
						/* translators: 1: number of accounts 2: number who have sat a paper */
						__( '%1$d registered %2$s, %3$d of whom have sat a practice paper.', 'eduai' ),
						$total,
						_n( 'account', 'accounts', $total, 'eduai' ),
						$sat
					)
			)
		);

		self::render_filters( $role, $search );

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

		if ( ! $users ) {
			printf(
				'<tr><td colspan="6">%s</td></tr>',
				esc_html(
					'' !== $search
						? __( 'No accounts match that search.', 'eduai' )
						: __( 'No accounts with that role yet.', 'eduai' )
				)
			);
		}

		echo '</tbody></table>';

		self::render_pager( $paged, $pages, $total );
	}

	/**
	 * Role filter and search.
	 *
	 * A GET form with no nonce, deliberately: it reads a list the viewer is
	 * already authorised to see and changes nothing, so a nonce would only
	 * make the URL unshareable between two people who both hold the
	 * capability.
	 *
	 * @param string $role   Current role filter.
	 * @param string $search Current search term.
	 */
	private static function render_filters( string $role, string $search ): void {
		echo '<form method="get" class="eduai-students__filters">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::SLUG ) );

		printf(
			'<label class="screen-reader-text" for="eduai-students-role">%s</label>',
			esc_html__( 'Filter by role', 'eduai' )
		);

		echo '<select name="sl_role" id="eduai-students-role">';

		printf(
			'<option value="all"%s>%s</option>',
			selected( $role, 'all', false ),
			esc_html__( 'All roles', 'eduai' )
		);

		foreach ( wp_roles()->get_names() as $slug => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $slug ),
				selected( $role, $slug, false ),
				esc_html( translate_user_role( $label ) )
			);
		}

		echo '</select> ';

		printf(
			'<label class="screen-reader-text" for="eduai-students-search">%s</label>',
			esc_html__( 'Search students', 'eduai' )
		);

		printf(
			'<input type="search" id="eduai-students-search" name="s" value="%s" placeholder="%s"> ',
			esc_attr( $search ),
			esc_attr__( 'Name or email', 'eduai' )
		);

		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Filter', 'eduai' ) );

		echo '</form>';
	}

	/**
	 * Page links, printed only when there is more than one page.
	 *
	 * @param int $paged Current page.
	 * @param int $pages Total pages.
	 * @param int $total Total accounts matched.
	 */
	private static function render_pager( int $paged, int $pages, int $total ): void {
		if ( $pages < 2 ) {
			return;
		}

		$links = paginate_links( array(
			// remove_query_arg keeps the role filter and the search term on
			// the URL: paging out of your own filter is the classic version
			// of this control being wrong.
			'base'      => remove_query_arg( 'paged' ) . '%_%',
			'format'    => '&paged=%#%',
			'current'   => $paged,
			'total'     => $pages,
			'prev_text' => __( '&laquo; Previous', 'eduai' ),
			'next_text' => __( 'Next &raquo;', 'eduai' ),
		) );

		if ( ! $links ) {
			return;
		}

		echo '<div class="tablenav bottom"><div class="tablenav-pages">';

		printf(
			'<span class="displaying-num">%s</span> ',
			esc_html(
				sprintf(
					/* translators: %s: number of accounts */
					_n( '%s account', '%s accounts', $total, 'eduai' ),
					number_format_i18n( $total )
				)
			)
		);

		echo wp_kses_post( $links );
		echo '</div></div>';
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
