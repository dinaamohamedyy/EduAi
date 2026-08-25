<?php
/**
 * What the assistant remembers between one message and the next.
 *
 * WHY A TABLE AND NOT A PHP SESSION
 *
 * Visitors are logged out, so there is no user id to hang state on, and
 * `session_start()` on a WordPress front end is a well-known way to defeat page
 * caching for everybody. State lives in a table keyed by a random token in a
 * cookie, which survives a load balancer with more than one web node — the
 * deployment this is expected to end up on.
 *
 * WHAT IS KEPT, AND WHAT IS NOT
 *
 * Turns, language, and the entities gathered so far, because "how much is it?"
 * three messages later has no meaning without them. NOT the visitor's contact
 * details: those move straight to the leads table where retention and consent
 * are handled deliberately. A session is a scratchpad and scratchpads get
 * forgotten, which is the wrong place for the only copy of someone's email.
 *
 * @package EduAI_Enquiry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Conversation state.
 */
class EduAI_Enquiry_Session {

	public const COOKIE = 'eduai_enquiry_sid';

	/**
	 * Turns kept for context.
	 *
	 * Six is two or three exchanges — enough for "and how much is that one?" to
	 * resolve, short enough that the prompt stays inside the latency budget. A
	 * long history is not free: every turn is tokens, and tokens are the
	 * per-minute ceiling this site actually hits.
	 */
	private const HISTORY = 6;

	/**
	 * How long an idle conversation survives, in seconds.
	 */
	private const TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'eduai_enquiry_sessions';
	}

	/**
	 * Create storage.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				token CHAR(40) NOT NULL,
				language VARCHAR(5) NOT NULL DEFAULT 'en',
				state LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY token (token),
				KEY updated_at (updated_at)
			) {$collate};"
		);
	}

	/**
	 * The current conversation, creating one if needed.
	 *
	 * @param string $token Token supplied by the client, if any.
	 * @return array{token:string,language:string,state:array}
	 */
	public static function open( string $token = '' ): array {
		global $wpdb;

		$token = preg_replace( '/[^a-f0-9]/', '', strtolower( $token ) );

		if ( 40 === strlen( (string) $token ) ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s', $token ),
				ARRAY_A
			);

			if ( $row && strtotime( $row['updated_at'] . ' UTC' ) > time() - self::TTL ) {
				return array(
					'token'    => $row['token'],
					'language' => $row['language'],
					'state'    => (array) json_decode( (string) $row['state'], true ),
				);
			}
		}

		$fresh = wp_generate_password( 40, false, false );
		$fresh = substr( hash( 'sha1', $fresh . microtime( true ) ), 0, 40 );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$wpdb->insert(
			self::table(),
			array(
				'token'      => $fresh,
				'language'   => 'en',
				'state'      => wp_json_encode( array( 'turns' => array(), 'entities' => array() ) ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		return array(
			'token'    => $fresh,
			'language' => 'en',
			'state'    => array( 'turns' => array(), 'entities' => array() ),
		);
	}

	/**
	 * Persist a conversation.
	 *
	 * @param string $token    Session token.
	 * @param string $language Language in use.
	 * @param array  $state    Everything remembered.
	 */
	public static function save( string $token, string $language, array $state ): void {
		global $wpdb;

		// Trim before writing rather than before reading: an unbounded turn
		// list is a row that grows for as long as somebody keeps typing.
		if ( isset( $state['turns'] ) && is_array( $state['turns'] ) ) {
			$state['turns'] = array_slice( $state['turns'], -self::HISTORY );
		}

		$wpdb->update(
			self::table(),
			array(
				'language'   => in_array( $language, array( 'en', 'ar' ), true ) ? $language : 'en',
				'state'      => wp_json_encode( $state ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'token' => $token ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Add a turn to the history.
	 */
	public static function remember( array $state, string $role, string $text ): array {
		$state['turns']   = (array) ( $state['turns'] ?? array() );
		$state['turns'][] = array(
			'role' => 'user' === $role ? 'user' : 'bot',
			'text' => mb_substr( $text, 0, 600 ),
		);

		return $state;
	}

	/**
	 * Merge newly named things into what is already known.
	 *
	 * Later mentions win, empty ones never overwrite. A visitor who says
	 * "beginner" and then asks an unrelated question is still a beginner.
	 */
	public static function accumulate( array $state, array $entities ): array {
		$known = (array) ( $state['entities'] ?? array() );

		foreach ( $entities as $k => $v ) {
			if ( null === $v || '' === $v || array() === $v ) {
				continue;
			}

			if ( 'topics' === $k ) {
				$known['topics'] = array_values( array_unique( array_merge( (array) ( $known['topics'] ?? array() ), (array) $v ) ) );
				$known['topics'] = array_slice( $known['topics'], -8 );
				continue;
			}

			$known[ $k ] = $v;
		}

		$state['entities'] = $known;

		return $state;
	}

	/**
	 * The recent conversation as plain text, for the model prompt.
	 */
	public static function transcript( array $state ): string {
		$lines = array();

		foreach ( (array) ( $state['turns'] ?? array() ) as $t ) {
			$lines[] = ( 'user' === $t['role'] ? 'Visitor: ' : 'Assistant: ' ) . $t['text'];
		}

		return implode( "\n", array_slice( $lines, -self::HISTORY ) );
	}

	/**
	 * Delete conversations nobody is having any more.
	 */
	public static function prune(): int {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE updated_at < %s',
				gmdate( 'Y-m-d H:i:s', time() - self::TTL )
			)
		);
	}
}
