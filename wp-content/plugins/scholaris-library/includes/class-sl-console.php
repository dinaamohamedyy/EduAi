<?php
/**
 * The admin landing page: one screen gathering the four things the owner
 * actually asked for.
 *
 * It is a ROUTER, not a re-implementation. Tutor LMS already owns course CRUD
 * and the quiz builder, students are wp_users, and the media library already
 * handles uploads — rebuilding any of that would mean rebuilding its
 * capability checks too, which is the surface this project has produced its
 * auth bugs on. What was missing was never the functionality; it was a place
 * to find it. Tutor calls remove_menu_page( 'edit.php?post_type=courses' ),
 * so there is no "Courses" item where a WordPress user looks.
 *
 * @package ScholarisLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menu registration, the console screen, and the dashboard widget.
 */
class SL_Console {

	const SLUG = 'eduai-console';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );

		// Supplies the destination for the theme's login_redirect filter.
		// Same resolver-with-honest-fallback shape as scholaris_progress_url():
		// with this plugin off, the theme sends the owner to plain wp-admin —
		// a worse landing, but a working one.
		add_filter( 'scholaris_admin_home_url', array( __CLASS__, 'home_url' ) );
	}

	public static function home_url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'EduAi', 'scholaris-library' ),
			__( 'EduAi', 'scholaris-library' ),
			'edit_posts',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-welcome-learn-more',
			// A STRING, deliberately. Tutor's own top-level menu sits at
			// position 2, and WordPress resolves an integer collision with a
			// hash offset — so passing 2 does not reliably mean "under
			// Dashboard", it means "somewhere near there, depending on load
			// order".
			'3.7'
		);
	}

	/**
	 * One link, or nothing at all when the capability is absent.
	 *
	 * Per-link rather than per-card is load-bearing: `tutor_instructor` holds
	 * `manage_tutor_instructor` but not `manage_tutor`, so gating the card
	 * would hand a future lecturer two buttons that 403. A reduced console is
	 * honest; a console of links that refuse you is not.
	 *
	 * @param string $url   Admin-relative URL.
	 * @param string $label Link text.
	 * @param string $cap   Capability required.
	 */
	public static function link( string $url, string $label, string $cap ): string {
		if ( ! current_user_can( $cap ) ) {
			return '';
		}

		return sprintf(
			'<li><a href="%s">%s</a></li>',
			esc_url( admin_url( $url ) ),
			esc_html( $label )
		);
	}

	/**
	 * The four cards, as label => [ url, cap ] lists.
	 *
	 * Kept as data so the dashboard widget and the full screen cannot drift
	 * into offering different things.
	 */
	private static function cards(): array {
		$cards = array(
			'library' => array(
				'title' => __( 'Study material', 'scholaris-library' ),
				'lead'  => __( 'Documents and video. A link is the recommended way to add a lecture recording.', 'scholaris-library' ),
				'links' => array(
					array( 'post-new.php?post_type=study_material', __( 'Add new material', 'scholaris-library' ), 'edit_posts' ),
					array( 'edit.php?post_type=study_material', __( 'All material', 'scholaris-library' ), 'edit_posts' ),
					array( 'edit-tags.php?taxonomy=material_subject&post_type=study_material', __( 'Subjects', 'scholaris-library' ), 'manage_categories' ),
					array( 'edit-tags.php?taxonomy=material_type&post_type=study_material', __( 'Material types', 'scholaris-library' ), 'manage_categories' ),
				),
			),
			'courses' => array(
				'title' => __( 'Courses', 'scholaris-library' ),
				'lead'  => __( 'Courses, lessons and quizzes are built inside Tutor LMS.', 'scholaris-library' ),
				'links' => array(
					array( 'admin.php?page=create-course', __( 'New course', 'scholaris-library' ), 'manage_tutor_instructor' ),
					array( 'admin.php?page=tutor', __( 'All courses', 'scholaris-library' ), 'manage_tutor_instructor' ),
					array( 'edit-tags.php?taxonomy=course-category&post_type=courses', __( 'Course categories', 'scholaris-library' ), 'manage_tutor' ),
					// The escape hatch: `courses` is still registered
					// show_ui => true, so this list stays reachable by direct
					// URL if the React builder misbehaves.
					array( 'edit.php?post_type=courses', __( 'Classic course list', 'scholaris-library' ), 'edit_posts' ),
				),
			),
			'students' => array(
				'title' => __( 'Students', 'scholaris-library' ),
				'lead'  => __( 'Who is registered, and how they are doing.', 'scholaris-library' ),
				'links' => array(
					array( 'users.php?page=eduai-students', __( 'Results and progress', 'scholaris-library' ), 'list_users' ),
					array( 'admin.php?page=tutor-students', __( 'Enrolled students', 'scholaris-library' ), 'manage_tutor' ),
					array( 'users.php', __( 'All accounts', 'scholaris-library' ), 'list_users' ),
				),
			),
		);

		return $cards;
	}

	/**
	 * Counts for the status strip.
	 */
	private static function status(): array {
		$materials = wp_count_posts( 'study_material' );
		$published = isset( $materials->publish ) ? (int) $materials->publish : 0;

		// Media-aware on purpose. Counting "no document" would report every
		// correctly-configured link-video material as broken, and a health
		// metric that cries wolf is one nobody reads.
		$empty = get_posts( array(
			'post_type'      => 'study_material',
			'post_status'    => 'publish',
			'numberposts'    => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );

		$without_media = 0;

		foreach ( $empty as $material_id ) {
			// SL_Meta::has_media(), not a local copy of the rule: this
			// predicate lived in three files and the console held one of
			// them. See the note on SL_Meta::has_video().
			if ( ! SL_Meta::has_media( $material_id ) ) {
				++$without_media;
			}
		}

		$courses = post_type_exists( 'courses' ) ? (int) wp_count_posts( 'courses' )->publish : null;

		return array(
			'materials'     => $published,
			'without_media' => $without_media,
			'courses'       => $courses,
			'accounts'      => (int) count_users()['total_users'],
			// Rendered in the web container, so this is the real ceiling —
			// pre-answering "why did my upload fail".
			'max_upload'    => size_format( wp_max_upload_size() ),
		);
	}

	/**
	 * The console screen.
	 */
	public static function render(): void {
		// Re-checked here and not merely at registration: a callback that
		// trusts its menu registration is one refactor away from being
		// reachable directly.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'scholaris-library' ) );
		}

		$sl_cards  = self::cards();
		$sl_status = self::status();
		$template  = SL_DIR . 'templates/admin/console.php';

		// The markup is the front-end's file. If it is ever absent the links
		// are still the point, so degrade to them rather than to a fatal.
		if ( ! is_readable( $template ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'EduAi', 'scholaris-library' ) . '</h1>';
			self::render_widget();
			echo '</div>';
			return;
		}

		include $template;
	}

	/**
	 * The same links on the dashboard, so the console is one click away even
	 * if the sign-in redirect is ever switched off.
	 */
	public static function dashboard_widget(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'sl_console_widget',
			__( 'EduAi', 'scholaris-library' ),
			array( __CLASS__, 'render_widget' )
		);
	}

	public static function render_widget(): void {
		echo '<ul class="sl-console__widget">';

		foreach ( self::cards() as $card ) {
			foreach ( $card['links'] as $link ) {
				echo wp_kses_post( self::link( $link[0], $link[1], $link[2] ) );
			}
		}

		printf(
			'<li><a href="%s"><strong>%s</strong></a></li>',
			esc_url( self::home_url() ),
			esc_html__( 'Open the EduAi console', 'scholaris-library' )
		);

		echo '</ul>';
	}
}
