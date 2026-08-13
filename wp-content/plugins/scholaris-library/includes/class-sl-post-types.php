<?php
/**
 * Study-material post type and taxonomies.
 *
 * @package ScholarisLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers study_material, material_subject and material_type.
 */
class SL_Post_Types {

	/**
	 * Meta marking a post as a test fixture rather than teaching material.
	 */
	const FIXTURE_META = '_scholaris_fixture';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'manage_study_material_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_study_material_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'hide_fixtures' ) );
		add_filter( 'wp_count_posts', array( __CLASS__, 'discount_fixtures' ), 10, 2 );
	}

	/**
	 * Keep test fixtures out of the library a student and the owner see.
	 *
	 * `Gate test — members`, `Gate test — public` and `Gate test — members
	 * video` are created by `download-gate.php` and `video-gate-probe.php`
	 * and are load-bearing for those harnesses, so they cannot be deleted —
	 * but they were sitting in the owner's library beside his real lectures.
	 * A fixture visible on the product IS in production, whatever it was
	 * created for.
	 *
	 * HIDDEN, NOT DELETED, and only on the front end: wp-admin still lists
	 * them, because a fixture nobody can find is one somebody recreates. The
	 * harnesses keep working untouched.
	 *
	 * Flag-driven rather than title-matched. Matching "Gate test" would break
	 * the day a harness renames its fixture, and would silently swallow a
	 * real material a lecturer called "Gate test" — a rule keyed on wording
	 * fails in both directions at once.
	 *
	 * @param WP_Query $query Query about to run.
	 */
	/**
	 * Take the hidden fixtures out of the counts as well as the rows.
	 *
	 * `wp_count_posts()` counts by status in SQL and knows nothing about
	 * meta, so hiding rows alone would print "All (6)" above three lines.
	 * That is the count-disagrees-with-the-list bug from the console strip,
	 * and on this screen it would read as the thing he is already angry
	 * about: material that is there one moment and gone the next.
	 *
	 * One subtraction, from one flag, so the number and the list cannot say
	 * different things.
	 *
	 * @param object $counts Counts by status.
	 * @param string $type   Post type.
	 */
	public static function discount_fixtures( $counts, $type ) {
		if ( 'study_material' !== $type || ! is_admin() ) {
			return $counts;
		}

		global $wpdb, $pagenow;

		if ( 'edit.php' !== $pagenow ) {
			return $counts;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.post_status, COUNT(*) AS n
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				 WHERE p.post_type = 'study_material'
				 GROUP BY p.post_status",
				self::FIXTURE_META
			)
		);

		foreach ( (array) $rows as $row ) {
			$status = $row->post_status;

			if ( isset( $counts->{$status} ) ) {
				$counts->{$status} = max( 0, (int) $counts->{$status} - (int) $row->n );
			}
		}

		return $counts;
	}

	public static function hide_fixtures( $query ): void {
		if ( ! $query instanceof WP_Query ) {
			return;
		}

		/*
		 * In wp-admin this applies to ONE screen: the study_material list.
		 *
		 * The reason is not tidiness. Our own harnesses create these fixtures
		 * on the owner's live stack — `download-gate.sh` recreated all three
		 * twenty minutes after he emptied his library — and three "Gate test"
		 * rows reappearing in his list looks exactly like something undoing
		 * his work. He has already said as much about his courses.
		 *
		 * Scoped by $pagenow rather than get_current_screen(), which is not
		 * reliably set when pre_get_posts runs, and to the MAIN query only —
		 * a metabox, an autocomplete or a harness query on this screen must
		 * still be able to find a fixture by asking for it. Hidden from the
		 * list he reads, not from the code that needs them.
		 */
		if ( is_admin() ) {
			global $pagenow;

			if ( 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
				return;
			}
		}

		$types = (array) $query->get( 'post_type' );

		if ( ! in_array( 'study_material', $types, true ) && ! $query->is_post_type_archive( 'study_material' ) ) {
			return;
		}

		// A single material requested by name is left alone: a direct link to
		// a fixture should still resolve, for whoever is debugging one.
		if ( $query->is_singular() ) {
			return;
		}

		$existing = (array) $query->get( 'meta_query' );

		/*
		 * NESTED, not appended, and the difference is not stylistic.
		 * Appending pushed this clause into whatever relation the caller had
		 * already set — so a query written as
		 *
		 *   relation OR, ( file_id = 83 ), ( video_id = 83 )
		 *
		 * silently became "...OR fixture NOT EXISTS", which is a different
		 * question and returned the wrong rows. Measured: it broke the
		 * attachment-to-material lookup on the owner's PDF, and it would
		 * break any OR meta_query anyone writes against this post type,
		 * from anywhere, with no error.
		 *
		 * Wrapping keeps the caller's group intact and ANDs the exclusion
		 * beside it, which is what "also, not a fixture" actually means.
		 */
		$query->set(
			'meta_query',
			$existing
				? array(
					'relation' => 'AND',
					$existing,
					array(
						'key'     => self::FIXTURE_META,
						'compare' => 'NOT EXISTS',
					),
				)
				: array(
					array(
						'key'     => self::FIXTURE_META,
						'compare' => 'NOT EXISTS',
					),
				)
		);
	}

	public static function register(): void {
		register_post_type( 'study_material', array(
			'labels'              => array(
				'name'               => __( 'Study Material', 'scholaris-library' ),
				'singular_name'      => __( 'Study Material', 'scholaris-library' ),
				'add_new_item'       => __( 'Add study material', 'scholaris-library' ),
				'edit_item'          => __( 'Edit study material', 'scholaris-library' ),
				'search_items'       => __( 'Search material', 'scholaris-library' ),
				'not_found'          => __( 'No material found.', 'scholaris-library' ),
				'menu_name'          => __( 'Library', 'scholaris-library' ),
				'all_items'          => __( 'All material', 'scholaris-library' ),
			),
			'public'              => true,
			'show_in_rest'        => true,
			'has_archive'         => 'library',
			'rewrite'             => array( 'slug' => 'material', 'with_front' => false ),
			'menu_icon'           => 'dashicons-media-document',
			'menu_position'       => 22,
			'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'custom-fields' ),
			'taxonomies'          => array( 'material_subject', 'material_type' ),
			'exclude_from_search' => false,
		) );

		register_taxonomy( 'material_subject', array( 'study_material' ), array(
			'labels'            => array(
				'name'          => __( 'Subjects', 'scholaris-library' ),
				'singular_name' => __( 'Subject', 'scholaris-library' ),
				'add_new_item'  => __( 'Add subject', 'scholaris-library' ),
			),
			'public'            => true,
			'hierarchical'      => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => 'subject' ),
		) );

		register_taxonomy( 'material_type', array( 'study_material' ), array(
			'labels'            => array(
				'name'          => __( 'Material types', 'scholaris-library' ),
				'singular_name' => __( 'Material type', 'scholaris-library' ),
				'add_new_item'  => __( 'Add material type', 'scholaris-library' ),
			),
			'public'            => true,
			'hierarchical'      => false,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => 'type' ),
		) );

		self::seed_terms();
	}

	/**
	 * Create a starter set of material types once.
	 */
	private static function seed_terms(): void {
		if ( get_option( 'sl_terms_seeded' ) ) {
			return;
		}

		foreach ( array(
			__( 'Lecture slides', 'scholaris-library' ),
			__( 'Lecture notes', 'scholaris-library' ),
			__( 'Textbook chapter', 'scholaris-library' ),
			__( 'Past paper', 'scholaris-library' ),
			__( 'Lab sheet', 'scholaris-library' ),
			__( 'Reading list', 'scholaris-library' ),
		) as $name ) {
			if ( ! term_exists( $name, 'material_type' ) ) {
				wp_insert_term( $name, 'material_type' );
			}
		}

		update_option( 'sl_terms_seeded', 1, false );
	}

	/**
	 * Admin list columns.
	 *
	 * @param array $columns Existing columns.
	 */
	public static function columns( array $columns ): array {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['sl_file']  = __( 'File', 'scholaris-library' );
				$new['sl_pages'] = __( 'Pages', 'scholaris-library' );
			}
		}

		return $new;
	}

	/**
	 * Admin column output.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function column_content( string $column, int $post_id ): void {
		if ( 'sl_file' === $column ) {
			$file_id = (int) get_post_meta( $post_id, '_scholaris_file_id', true );

			if ( ! $file_id ) {
				echo '<span style="color:#b32d2e">' . esc_html__( 'none', 'scholaris-library' ) . '</span>';
				return;
			}

			$url  = wp_get_attachment_url( $file_id );
			$path = get_attached_file( $file_id );
			$size = $path && file_exists( $path ) ? size_format( (int) filesize( $path ) ) : '';

			printf(
				'<a href="%s" target="_blank" rel="noopener">%s</a> <span style="color:#646970">%s</span>',
				esc_url( (string) $url ),
				esc_html( strtoupper( pathinfo( (string) $url, PATHINFO_EXTENSION ) ) ),
				esc_html( $size )
			);
		}

		if ( 'sl_pages' === $column ) {
			echo esc_html( (string) ( (int) get_post_meta( $post_id, '_scholaris_pages', true ) ?: '—' ) );
		}
	}
}
