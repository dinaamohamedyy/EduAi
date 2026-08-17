<?php
/**
 * Knowledge base: indexing course material and retrieving relevant passages.
 *
 * Retrieval uses MySQL FULLTEXT (natural language mode) with a LIKE fallback,
 * so there is no external vector database to run or pay for. For a large
 * corpus you can swap in embeddings later — see the `eduai_retrieve` filter.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Chunk store + search.
 */
class EduAI_Knowledge {

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'eduai_chunks';
	}

	public static function init(): void {
		// Re-index a document whenever it is saved.
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_post' ) );
		add_action( 'eduai_reindex_event', array( __CLASS__, 'reindex_all' ) );

		// Gated content must not escape through the page's own metadata.
		foreach ( array( 'wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description' ) as $eduai_desc ) {
			add_filter( $eduai_desc, array( __CLASS__, 'protect_description' ), 20 );
		}
	}

	/**
	 * Keep gated content out of the description meta tags.
	 *
	 * A lesson withholds its body from anyone not enrolled — and then the SEO
	 * plugin builds `og:description` from `post_content` and prints it in the
	 * head of the same page, to everyone, logged in or not. Measured on a
	 * published lesson: the body reached an anonymous visitor twice, in full,
	 * from a page that was correctly refusing to render it.
	 *
	 * This is the same failure the retrieval filter exists to prevent, one
	 * surface over. The assistant was going to become the bypass for the file
	 * gating; the meta tag already was one. It matters more now than it did
	 * yesterday, because lesson bodies are about to be generated in bulk and
	 * every one of them would carry its own leak into the page head.
	 *
	 * Same authority as everything else — may_read(). A separate rule here
	 * would be a second definition of "who may see this" and the first
	 * divergence would be silent.
	 *
	 * The title is deliberately still served: a course listing its lesson
	 * titles to a visitor deciding whether to enrol is the product working.
	 * What must not ship is the teaching.
	 *
	 * @param string $description Description the SEO plugin assembled.
	 */
	public static function protect_description( $description ) {
		if ( ! is_singular() ) {
			return $description;
		}

		$post_id = (int) get_queried_object_id();

		if ( ! $post_id || ! in_array( get_post_type( $post_id ), self::indexed_post_types(), true ) ) {
			return $description;
		}

		if ( self::may_read( $post_id ) ) {
			return $description;
		}

		// Not an empty string: an empty og:description is a bug report waiting
		// to be filed, and the title says nothing the listing does not already.
		return wp_strip_all_tags( (string) get_the_title( $post_id ) );
	}

	/**
	 * Post types whose content feeds the assistant.
	 */
	public static function indexed_post_types(): array {
		$types = array( 'study_material', 'post', 'page' );

		/*
		 * Asked of the seam rather than named here.
		 *
		 * These were Tutor's slugs, so the LearnDash conversion made the
		 * assistant blind to every lesson on the site: `sfwd-lessons` was not
		 * in the list, nothing indexed it, and Ask answered "no course
		 * material matched" for content the student was reading on screen.
		 * Measured before the fix — 0 indexed sfwd-lessons documents against
		 * 3 left over from Tutor.
		 *
		 * Nothing failed to make that visible, which is why it needs the seam
		 * and not a corrected list: the next migration would do it again.
		 */
		if ( class_exists( 'EduAI_LMS' ) && EduAI_LMS::active() ) {
			$types = array_merge( $types, EduAI_LMS::content_types() );
		}

		/**
		 * Filter which post types are indexed.
		 *
		 * @param string[] $types Post type slugs.
		 */
		return array_values( array_unique( (array) apply_filters( 'eduai_indexed_post_types', $types ) ) );
	}

	/**
	 * Create the chunk table.
	 */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			chunk_index INT UNSIGNED NOT NULL DEFAULT 0,
			source_title VARCHAR(255) NOT NULL DEFAULT '',
			source_url VARCHAR(255) NOT NULL DEFAULT '',
			chunk_text LONGTEXT NOT NULL,
			word_count INT UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			FULLTEXT KEY chunk_ft (chunk_text)
		) ENGINE=InnoDB {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Index one post. Pulls both the post body and any attached document.
	 *
	 * @param int $post_id Post ID.
	 * @return int Number of chunks written.
	 */
	public static function index_post( int $post_id ): int {
		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			self::delete_for_post( $post_id );
			return 0;
		}

		if ( ! in_array( $post->post_type, self::indexed_post_types(), true ) ) {
			return 0;
		}

		$parts = array();

		$body = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		if ( '' !== trim( $body ) ) {
			$parts[] = $body;
		}

		// Attached study document, if the library plugin recorded one.
		$file_id = (int) get_post_meta( $post_id, '_scholaris_file_id', true );
		if ( $file_id ) {
			$path = get_attached_file( $file_id );
			if ( $path ) {
				$extracted = EduAI_PDF::extract( $path );
				if ( strlen( $extracted ) > 40 ) {
					$parts[] = $extracted;
				}
			}
		}

		$text = trim( implode( "\n\n", $parts ) );

		self::delete_for_post( $post_id );

		if ( strlen( $text ) < 40 ) {
			return 0;
		}

		$chunks = EduAI_PDF::chunk( $text );
		if ( ! $chunks ) {
			return 0;
		}

		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );
		$url   = (string) get_permalink( $post_id );
		$title = get_the_title( $post_id );

		$written = 0;
		$lost    = 0;

		foreach ( $chunks as $i => $chunk ) {
			/*
			 * PDF extraction produces byte sequences that are not valid UTF-8 —
			 * ligatures, embedded font subsets, maths glyphs that did not map.
			 * MySQL REJECTS such a row, $wpdb->insert returns false, and this
			 * loop used to ignore the return value entirely.
			 *
			 * The result was silent, partial indexing. Measured on material 143
			 * before this fix: chunk() produced 35 chunks, 18 of them carried
			 * invalid UTF-8, and the table held exactly the other 17 — with
			 * gaps at 0, 1, 5, 6, 7 and so on, matching the bad ones one for
			 * one. The document was in the index at 56% of its length and every
			 * grounded answer drawn from it was quietly partial. Nothing
			 * reported anything: the rows were newer than the file, so it did
			 * not even look like a stale index.
			 *
			 * wp_check_invalid_utf8( $s, true ) strips the offending bytes —
			 * WordPress's own function, rather than a second opinion about what
			 * valid UTF-8 is. A chunk that is nothing but bad bytes becomes
			 * empty and is skipped rather than stored blank.
			 */
			$clean = wp_check_invalid_utf8( $chunk, true );

			if ( '' === trim( $clean ) ) {
				++$lost;
				continue;
			}

			$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'post_id'       => $post_id,
					'attachment_id' => $file_id,
					'chunk_index'   => $i,
					'source_title'  => $title,
					'source_url'    => $url,
					'chunk_text'    => $clean,
					'word_count'    => str_word_count( $clean ),
					'updated_at'    => $now,
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
			);

			if ( false === $ok ) {
				++$lost;
				continue;
			}

			++$written;
		}

		// Report what landed, not what was attempted. The old return value was
		// count( $chunks ), so the settings screen and every caller believed
		// the optimistic number while the table held half of it.
		// What the document SHOULD hold, recorded at the moment we know it.
		//
		// Contiguity alone cannot answer this. It catches a hole in the middle —
		// which is what happened to material 143 — but a document whose LAST
		// chunks all fail comes back as 0..n-1 with no gap and reads as
		// complete. Storing the expected count turns "are there holes" into
		// "is anything missing", which is the question actually being asked.
		update_post_meta( $post_id, '_eduai_chunks_expected', count( $chunks ) );

		if ( $lost ) {
			/**
			 * Fires when part of a document could not be indexed.
			 *
			 * @param int $post_id Source material.
			 * @param int $lost    Chunks that did not reach the table.
			 * @param int $written Chunks that did.
			 */
			do_action( 'eduai_index_incomplete', $post_id, $lost, $written );
		}

		return $written;
	}

	/**
	 * Re-index on save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function on_save_post( int $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! $post || ! in_array( $post->post_type, self::indexed_post_types(), true ) ) {
			return;
		}

		self::index_post( $post_id );
	}

	/**
	 * Drop all chunks belonging to a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function delete_for_post( int $post_id ): void {
		global $wpdb;
		$table = self::table();
		$wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Rebuild the whole index. Safe to run repeatedly.
	 *
	 * @return array{docs:int,chunks:int}
	 */
	public static function reindex_all(): array {
		$ids = get_posts( array(
			'post_type'      => self::indexed_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );

		$docs   = 0;
		$chunks = 0;

		foreach ( $ids as $id ) {
			$written = self::index_post( (int) $id );
			if ( $written ) {
				++$docs;
				$chunks += $written;
			}
		}

		update_option( 'eduai_last_index', current_time( 'mysql' ), false );

		return array( 'docs' => $docs, 'chunks' => $chunks );
	}

	/**
	 * Index counts for the settings screen.
	 *
	 * @return array{docs:int,chunks:int}
	 */
	public static function stats(): array {
		global $wpdb;
		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return array( 'docs' => 0, 'chunks' => 0 );
		}

		$chunks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$docs   = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$table}" );
		// phpcs:enable

		return array( 'docs' => $docs, 'chunks' => $chunks );
	}

	/**
	 * Documents the index is not holding in full.
	 *
	 * This exists because the failure it looks for hid for weeks behind a
	 * number that could not express it. Coverage was reported as chunk
	 * characters over file characters, and the deliberate 200-character overlap
	 * pushes that above 100% — so a document missing a tenth of its chunks
	 * measured 112% and was read, and relayed to the owner, as complete. A
	 * metric that cannot fall below 100% when content is lost is not an
	 * instrument.
	 *
	 * Two questions are asked, because either alone has a blind spot:
	 *
	 *   - a HOLE: chunk_index does not run 0..n-1. This is what material 143
	 *     looked like — gaps at 0, 1, 5, 6, 7, matching the rejected chunks one
	 *     for one.
	 *   - a SHORTFALL: fewer rows than index_post() said it produced. A
	 *     document whose LAST chunks all failed is contiguous and still
	 *     incomplete, so contiguity would call it healthy.
	 *
	 * The expected count is only recorded from the fix onward, so a document
	 * indexed before it reports on holes alone until it is next rebuilt. That
	 * is stated rather than hidden: `expected` comes back 0 where it is unknown.
	 *
	 * @return array<int,array{post_id:int,title:string,have:int,expected:int,holes:bool}>
	 */
	public static function incomplete(): array {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return array();
		}

		$rows = $wpdb->get_results(
			"SELECT post_id, COUNT(*) AS have, MIN(chunk_index) AS lo, MAX(chunk_index) AS hi
			 FROM {$table}
			 GROUP BY post_id",
			ARRAY_A
		);
		// phpcs:enable

		$out = array();

		foreach ( (array) $rows as $row ) {
			$post_id  = (int) $row['post_id'];
			$have     = (int) $row['have'];
			$holes    = $have !== ( (int) $row['hi'] - (int) $row['lo'] + 1 ) || 0 !== (int) $row['lo'];
			$expected = (int) get_post_meta( $post_id, '_eduai_chunks_expected', true );

			if ( ! $holes && ( ! $expected || $have >= $expected ) ) {
				continue;
			}

			$out[] = array(
				'post_id'  => $post_id,
				'title'    => (string) get_the_title( $post_id ),
				'have'     => $have,
				'expected' => $expected,
				'holes'    => $holes,
			);
		}

		return $out;
	}

	/**
	 * May the current user read this material's contents?
	 *
	 * The assistant quotes indexed passages back to whoever asked, so retrieval
	 * is a read of the source document by another route. Until now nothing here
	 * checked that — indexing gated on `publish` and search gated on nothing,
	 * which was safe only because `can_use()` refuses anonymous visitors and
	 * `members` happens to mean exactly "logged in". Those two coinciding is a
	 * coincidence, not a design: make access per-course, per-enrolment, or turn
	 * `logged_in_only` off in Settings, and the assistant starts reading out
	 * material the asker cannot open — becoming the bypass for the file gating
	 * rather than a consumer of it.
	 *
	 * This calls the OWNING component's authority rather than restating its
	 * rule. `SL_Meta::can_download()` is where "who may have this document"
	 * lives; a second copy of that logic here would be one more place to update
	 * and one more place to forget, and the first divergence would be silent.
	 *
	 * Absent authority is a refusal, not a pass. If the library plugin is not
	 * loaded then `study_material` is not a registered type and its content is
	 * unreachable by every other route — serving it here would make the
	 * assistant the one door left open.
	 *
	 * @param int $post_id Source material.
	 */
	public static function may_read( int $post_id ): bool {
		static $cache = array();

		if ( $post_id <= 0 ) {
			return false;
		}

		// One question per material, not per chunk: nine chunks of one deck ask
		// the same thing nine times, and capability checks hit the database.
		$key = get_current_user_id() . ':' . $post_id;

		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return $cache[ $key ] = false;
		}

		if ( 'study_material' === $post->post_type ) {
			$allowed = class_exists( 'SL_Meta' ) && method_exists( 'SL_Meta', 'can_download' )
				? (bool) SL_Meta::can_download( $post_id )
				: false;
		} elseif ( 'lesson' === $post->post_type ) {
			/*
			 * Enrolment, or the capability to edit. `read_post` is NOT the
			 * enrolment check it looks like — measured on this install against
			 * one published lesson:
			 *
			 *                        is_enrolled  read_post  enrolled_access
			 *   anonymous            false        false      false
			 *   student, unenrolled  false        false      false
			 *   student, ENROLLED    true         FALSE      true
			 *   administrator        false        true       false
			 *
			 * So `read_post` denied the one person entitled to the lesson and
			 * admitted the one person Tutor's own check denies. Neither column
			 * is the rule on its own: enrolment is what entitles a student,
			 * and staff are never enrolled in their own course.
			 */
			$allowed = ( function_exists( 'tutor_utils' )
					&& (bool) tutor_utils()->has_enrolled_content_access( 'lesson', $post_id ) )
				|| current_user_can( 'edit_posts' );
		} else {
			// Everything else answers to WordPress, which already knows about
			// private posts, drafts and restricted types.
			$allowed = current_user_can( 'read_post', $post_id );
		}

		/**
		 * Filter whether the current user may be served passages from a source.
		 *
		 * The hook for per-enrolment access: return false and the assistant
		 * stops quoting that material without anything else changing.
		 *
		 * @param bool $allowed Decision so far.
		 * @param int  $post_id Source material.
		 */
		return $cache[ $key ] = (bool) apply_filters( 'eduai_may_read_source', $allowed, $post_id );
	}

	/**
	 * Retrieve the passages most relevant to a question, filtered by who asked.
	 *
	 * SCOPE IS THE DECK, NOT THE LESSON, AND THE NEXT REQUEST WILL BE TO TIGHTEN
	 * IT. Resist that, or make it a ranking change rather than a scope one.
	 *
	 * A lesson is a section of one lecture, and the terminology a student needs
	 * was defined in an earlier section: "what's a residual?" asked inside
	 * *Least Squares in d-Dimensions* is answered three sections earlier under
	 * *squared residuals*. Scope to the lesson and that definition is invisible,
	 * so the assistant answers confidently and incompletely — which is worse
	 * than answering from the whole deck, because the student cannot tell. What
	 * the complaint is actually about is passages from an unrelated module, and
	 * the deck boundary excludes those.
	 *
	 * If same-lesson passages should rank higher, that is a BOOST, not a filter.
	 * Ordering can be wrong without being harmful; a filter cannot — it removes
	 * the answer and leaves nothing to say so.
	 *
	 * @param string $query Student question.
	 * @param int    $limit Passages wanted.
	 * @param int    $scope Restrict to one material, or 0 for the whole library.
	 * @return array<int,array{post_id:int,title:string,url:string,text:string,score:float}>
	 */
	public static function retrieve( string $query, int $limit = 6, int $scope = 0 ): array {
		global $wpdb;

		$query = trim( wp_strip_all_tags( $query ) );
		if ( strlen( $query ) < 3 ) {
			return array();
		}

		// Over-fetch, because the access filter below removes rows AFTER the
		// database has ranked them. Asking for exactly $limit and then dropping
		// two would quietly hand the student four passages instead of six —
		// a worse answer, with nothing to indicate why.
		$fetch = max( $limit, min( 200, $limit * 5 ) );

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return array();
		}

		$scope_sql  = $scope > 0 ? ' AND post_id = %d' : '';
		$scope_args = $scope > 0 ? array( $scope ) : array();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, source_title, source_url, chunk_text,
					MATCH(chunk_text) AGAINST (%s IN NATURAL LANGUAGE MODE) AS score
				 FROM {$table}
				 WHERE MATCH(chunk_text) AGAINST (%s IN NATURAL LANGUAGE MODE)" . $scope_sql . '
				 ORDER BY score DESC
				 LIMIT %d',
				array_merge( array( $query, $query ), $scope_args, array( $fetch ) )
			),
			ARRAY_A
		);

		// FULLTEXT can come back empty for very short or stop-word-heavy queries.
		if ( empty( $rows ) ) {
			$terms = array_slice(
				array_filter(
					preg_split( '/[^\p{L}\p{N}]+/u', $query ) ?: array(),
					static fn( $t ) => mb_strlen( $t ) > 3
				),
				0,
				5
			);

			if ( $terms ) {
				$where = array();
				$args  = array();

				foreach ( $terms as $term ) {
					$where[] = 'chunk_text LIKE %s';
					$args[]  = '%' . $wpdb->esc_like( $term ) . '%';
				}

				$like = '(' . implode( ' OR ', $where ) . ')';

				if ( $scope > 0 ) {
					$like  .= ' AND post_id = %d';
					$args[] = $scope;
				}

				$args[] = $fetch;

				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT post_id, source_title, source_url, chunk_text, 0 AS score
						 FROM {$table}
						 WHERE " . $like . '
						 LIMIT %d',
						$args
					),
					ARRAY_A
				);
			}
		}
		// phpcs:enable

		$out = array();
		foreach ( (array) $rows as $row ) {
			// The gate. Ranked by the database, filtered by who is asking.
			if ( ! self::may_read( (int) $row['post_id'] ) ) {
				continue;
			}

			$out[] = array(
				'post_id' => (int) $row['post_id'],
				'title'   => (string) $row['source_title'],
				'url'     => (string) $row['source_url'],
				'text'    => (string) $row['chunk_text'],
				'score'   => (float) $row['score'],
			);

			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		/**
		 * Filter retrieved passages — the hook to plug in embeddings or a reranker.
		 *
		 * @param array  $out   Passages.
		 * @param string $query Student question.
		 * @param int    $limit Requested count.
		 */
		return (array) apply_filters( 'eduai_retrieve', $out, $query, $limit );
	}

	/**
	 * Format passages as a context block for the system prompt.
	 *
	 * @param array $passages Result of retrieve().
	 */
	public static function to_context( array $passages ): string {
		if ( ! $passages ) {
			return '';
		}

		$blocks = array();
		foreach ( $passages as $i => $p ) {
			$blocks[] = sprintf(
				"<document index=\"%d\" title=\"%s\" url=\"%s\">\n%s\n</document>",
				$i + 1,
				esc_attr( $p['title'] ),
				esc_url_raw( $p['url'] ),
				$p['text']
			);
		}

		return "COURSE MATERIAL (retrieved for this question):\n\n" . implode( "\n\n", $blocks );
	}
}
