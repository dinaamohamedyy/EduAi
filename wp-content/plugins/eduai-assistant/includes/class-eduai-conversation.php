<?php
/**
 * Conversation persistence.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores chat turns so students keep their history and admins can review usage.
 */
class EduAI_Conversation {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'eduai_messages';
	}

	public static function init(): void {
		add_action( 'eduai_reindex_event', array( __CLASS__, 'purge_expired' ) );
		add_action( 'delete_user', array( __CLASS__, 'delete_for_user' ) );
	}

	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			thread_id VARCHAR(64) NOT NULL DEFAULT '',
			role VARCHAR(16) NOT NULL DEFAULT 'user',
			content LONGTEXT NOT NULL,
			sources LONGTEXT NULL,
			tokens_in INT UNSIGNED NOT NULL DEFAULT 0,
			tokens_out INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY user_thread (user_id, thread_id),
			KEY created_at (created_at)
		) ENGINE=InnoDB {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Persist one turn.
	 *
	 * @param int    $user_id   Author.
	 * @param string $thread_id Client-generated thread key.
	 * @param string $role      'user' or 'assistant'.
	 * @param string $content   Message body.
	 * @param array  $sources   Cited passages.
	 * @param array  $usage     Token usage from the API.
	 */
	public static function add( int $user_id, string $thread_id, string $role, string $content, array $sources = array(), array $usage = array() ): void {
		if ( ! EduAI_Settings::get( 'log_chats', true ) ) {
			return;
		}

		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'user_id'    => $user_id,
				'thread_id'  => substr( $thread_id, 0, 64 ),
				'role'       => 'assistant' === $role ? 'assistant' : 'user',
				'content'    => $content,
				'sources'    => $sources ? wp_json_encode( $sources ) : null,
				'tokens_in'  => (int) ( $usage['input_tokens'] ?? 0 ),
				'tokens_out' => (int) ( $usage['output_tokens'] ?? 0 ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * Recent turns in a thread, oldest first, for conversational memory.
	 *
	 * @param int    $user_id   User.
	 * @param string $thread_id Thread key.
	 * @param int    $limit     Max turns.
	 * @return array<int,array{role:string,content:string}>
	 */
	public static function history( int $user_id, string $thread_id, int $limit = 10 ): array {
		if ( ! $user_id || '' === $thread_id ) {
			return array();
		}

		global $wpdb;
		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content FROM {$table}
				 WHERE user_id = %d AND thread_id = %s
				 ORDER BY id DESC LIMIT %d",
				$user_id,
				$thread_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable

		return array_reverse( (array) $rows );
	}

	/**
	 * Delete rows older than the retention window.
	 */
	public static function purge_expired(): void {
		$days = (int) EduAI_Settings::get( 'retention_days', 180 );
		if ( $days <= 0 ) {
			return;
		}

		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
		// phpcs:enable
	}

	/**
	 * GDPR-friendly cleanup when a user is deleted.
	 *
	 * @param int $user_id User ID.
	 */
	public static function delete_for_user( int $user_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => $user_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Aggregate usage for the admin dashboard.
	 *
	 * @param int $days Look-back window.
	 */
	public static function usage_summary( int $days = 30 ): array {
		global $wpdb;
		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return array( 'messages' => 0, 'students' => 0, 'tokens_in' => 0, 'tokens_out' => 0 );
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS messages,
					COUNT(DISTINCT user_id) AS students,
					COALESCE(SUM(tokens_in),0) AS tokens_in,
					COALESCE(SUM(tokens_out),0) AS tokens_out
				 FROM {$table} WHERE created_at >= %s",
				$cutoff
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'messages'   => (int) ( $row['messages'] ?? 0 ),
			'students'   => (int) ( $row['students'] ?? 0 ),
			'tokens_in'  => (int) ( $row['tokens_in'] ?? 0 ),
			'tokens_out' => (int) ( $row['tokens_out'] ?? 0 ),
		);
	}
}
