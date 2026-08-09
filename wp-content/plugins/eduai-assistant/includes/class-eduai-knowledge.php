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
	}

	/**
	 * Post types whose content feeds the assistant.
	 */
	public static function indexed_post_types(): array {
		$types = array( 'study_material', 'post', 'page' );

		if ( post_type_exists( 'courses' ) ) {
			$types[] = 'courses';
			$types[] = 'lesson';
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

		foreach ( $chunks as $i => $chunk ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'post_id'       => $post_id,
					'attachment_id' => $file_id,
					'chunk_index'   => $i,
					'source_title'  => $title,
					'source_url'    => $url,
					'chunk_text'    => $chunk,
					'word_count'    => str_word_count( $chunk ),
					'updated_at'    => $now,
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
			);
		}

		return count( $chunks );
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
	 * Retrieve the passages most relevant to a question.
	 *
	 * @param string $query Student question.
	 * @param int    $limit Max passages.
	 * @return array<int,array{title:string,url:string,text:string,score:float}>
	 */
	public static function retrieve( string $query, int $limit = 6 ): array {
		global $wpdb;

		$query = trim( wp_strip_all_tags( $query ) );
		if ( strlen( $query ) < 3 ) {
			return array();
		}

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_title, source_url, chunk_text,
					MATCH(chunk_text) AGAINST (%s IN NATURAL LANGUAGE MODE) AS score
				 FROM {$table}
				 WHERE MATCH(chunk_text) AGAINST (%s IN NATURAL LANGUAGE MODE)
				 ORDER BY score DESC
				 LIMIT %d",
				$query,
				$query,
				$limit
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

				$args[] = $limit;

				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT source_title, source_url, chunk_text, 0 AS score
						 FROM {$table}
						 WHERE " . implode( ' OR ', $where ) . '
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
			$out[] = array(
				'title' => (string) $row['source_title'],
				'url'   => (string) $row['source_url'],
				'text'  => (string) $row['chunk_text'],
				'score' => (float) $row['score'],
			);
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
