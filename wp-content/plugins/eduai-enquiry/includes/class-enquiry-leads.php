<?php
/**
 * Captured enquiries, and getting them to a CRM without losing any.
 *
 * STORE FIRST, DISPATCH AFTER
 *
 * A lead that reaches this site and not the CRM must still exist. So the row is
 * written synchronously and the CRM call is queued — the reverse order loses
 * enquiries every time the endpoint has a bad minute, and the visitor is long
 * gone by then. Action Scheduler runs the dispatch when it is present, which it
 * is on any LearnDash site, and WP-Cron otherwise.
 *
 * PII RULES, WHICH ARE NOT NEGOTIABLE HERE
 *
 * - Contact details are never written to a log or an error message. This site
 *   already serves its uploads directory to the open internet; a debug log with
 *   email addresses in it is the same mistake with a different file extension.
 * - Consent is stored with the row, as a fact with a timestamp, not assumed
 *   from the act of typing.
 * - Retention is set from the first day rather than added later. Every other
 *   store on this platform grew without one and is now accumulating about 12 GB
 *   a year that nobody has decided the fate of.
 *
 * @package EduAI_Enquiry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lead capture and CRM delivery.
 */
class EduAI_Enquiry_Leads {

	public const DISPATCH_HOOK  = 'eduai_enquiry_dispatch_lead';
	public const RETENTION_HOOK = 'eduai_enquiry_prune';

	/**
	 * Attempts before a lead is left for a human to collect.
	 */
	private const MAX_ATTEMPTS = 5;

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'eduai_enquiry_leads';
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
				name VARCHAR(120) NULL,
				email VARCHAR(190) NULL,
				phone VARCHAR(40) NULL,
				interest TEXT NULL,
				course_id BIGINT UNSIGNED NULL,
				language VARCHAR(5) NOT NULL DEFAULT 'en',
				consent TINYINT(1) NOT NULL DEFAULT 0,
				consent_at DATETIME NULL,
				source VARCHAR(40) NOT NULL DEFAULT 'chat',
				crm_state VARCHAR(20) NOT NULL DEFAULT 'pending',
				crm_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				crm_error VARCHAR(255) NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY email (email),
				KEY crm_state (crm_state),
				KEY created_at (created_at)
			) {$collate};"
		);
	}

	/**
	 * Hook up dispatch and retention.
	 */
	public static function init(): void {
		add_action( self::DISPATCH_HOOK, array( __CLASS__, 'dispatch' ), 10, 1 );
		add_action( self::RETENTION_HOOK, array( __CLASS__, 'prune' ) );

		if ( ! wp_next_scheduled( self::RETENTION_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::RETENTION_HOOK );
		}
	}

	/**
	 * Record an enquiry.
	 *
	 * @param array $lead name, email, phone, interest, course_id, language, consent.
	 * @return int|WP_Error Row id.
	 */
	public static function capture( array $lead ) {
		global $wpdb;

		$email = isset( $lead['email'] ) ? sanitize_email( (string) $lead['email'] ) : '';
		$phone = isset( $lead['phone'] ) ? trim( wp_strip_all_tags( (string) $lead['phone'] ) ) : '';

		// A lead with no way to reach the person is not a lead.
		if ( '' === $email && '' === $phone ) {
			return new WP_Error( 'concierge_no_contact', __( 'An email address or phone number is needed.', 'eduai-enquiry' ) );
		}

		if ( '' !== $email && ! is_email( $email ) ) {
			return new WP_Error( 'concierge_bad_email', __( 'That email address does not look right.', 'eduai-enquiry' ) );
		}

		$consent = ! empty( $lead['consent'] );
		$now     = gmdate( 'Y-m-d H:i:s' );

		$ok = $wpdb->insert(
			self::table(),
			array(
				'name'       => mb_substr( trim( wp_strip_all_tags( (string) ( $lead['name'] ?? '' ) ) ), 0, 120 ),
				'email'      => $email,
				'phone'      => mb_substr( $phone, 0, 40 ),
				'interest'   => mb_substr( trim( wp_strip_all_tags( (string) ( $lead['interest'] ?? '' ) ) ), 0, 1000 ),
				'course_id'  => (int) ( $lead['course_id'] ?? 0 ) ?: null,
				'language'   => in_array( $lead['language'] ?? 'en', array( 'en', 'ar' ), true ) ? $lead['language'] : 'en',
				'consent'    => $consent ? 1 : 0,
				'consent_at' => $consent ? $now : null,
				'source'     => mb_substr( (string) ( $lead['source'] ?? 'chat' ), 0, 40 ),
				'crm_state'  => 'pending',
				'created_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( ! $ok ) {
			// $wpdb->last_error can echo the row back, contact details included.
			return new WP_Error( 'concierge_store_failed', __( 'The enquiry could not be saved.', 'eduai-enquiry' ) );
		}

		$id = (int) $wpdb->insert_id;

		/**
		 * Fires once an enquiry is safely stored.
		 *
		 * @param int   $id   Lead id.
		 * @param array $lead Submitted values.
		 */
		do_action( 'eduai_enquiry_lead_captured', $id, $lead );

		self::queue( $id );

		return $id;
	}

	/**
	 * Ask for the CRM call to happen shortly, out of the visitor's way.
	 */
	private static function queue( int $id ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 5, self::DISPATCH_HOOK, array( $id ), 'eduai-enquiry' );

			return;
		}

		wp_schedule_single_event( time() + 5, self::DISPATCH_HOOK, array( $id ) );
	}

	/**
	 * Send one lead to the CRM.
	 *
	 * @param int $id Lead id.
	 */
	public static function dispatch( $id ): void {
		global $wpdb;

		$id  = (int) $id;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );

		if ( ! $row || 'sent' === $row['crm_state'] ) {
			return;
		}

		$settings = get_option( 'eduai_enquiry_settings', array() );
		$endpoint = trim( (string) ( $settings['crm_webhook'] ?? '' ) );

		if ( '' === $endpoint ) {
			// Nowhere to send it is not a failure of this lead. It stays
			// pending and visible in the admin list, which is where somebody
			// will find it.
			$wpdb->update( self::table(), array( 'crm_state' => 'no_endpoint' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );

			return;
		}

		/**
		 * The payload sent to the CRM.
		 *
		 * Filterable so an adapter for a specific CRM can reshape it without
		 * this class growing a case statement per vendor.
		 *
		 * @param array $payload Outgoing fields.
		 * @param array $row     Stored lead.
		 */
		$payload = apply_filters(
			'eduai_enquiry_crm_payload',
			array(
				'name'       => $row['name'],
				'email'      => $row['email'],
				'phone'      => $row['phone'],
				'interest'   => $row['interest'],
				'course_id'  => (int) $row['course_id'],
				'course'     => $row['course_id'] ? get_the_title( (int) $row['course_id'] ) : '',
				'language'   => $row['language'],
				'consent'    => (bool) $row['consent'],
				'consent_at' => $row['consent_at'],
				'source'     => $row['source'],
				'site'       => home_url(),
				'captured'   => $row['created_at'],
			),
			$row
		);

		$headers = array( 'Content-Type' => 'application/json' );
		$secret  = trim( (string) ( $settings['crm_secret'] ?? '' ) );
		$body    = wp_json_encode( $payload );

		if ( '' !== $secret ) {
			// Lets the receiver verify this came from us and was not altered.
			$headers['X-EduAI-Signature'] = hash_hmac( 'sha256', (string) $body, $secret );
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 15,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		$attempts = (int) $row['crm_attempts'] + 1;

		if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 300 ) {
			$wpdb->update(
				self::table(),
				array( 'crm_state' => 'sent', 'crm_attempts' => $attempts, 'crm_error' => null ),
				array( 'id' => $id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);

			return;
		}

		$why = is_wp_error( $response )
			? $response->get_error_message()
			: 'HTTP ' . wp_remote_retrieve_response_code( $response );

		$give_up = $attempts >= self::MAX_ATTEMPTS;

		$wpdb->update(
			self::table(),
			array(
				'crm_state'    => $give_up ? 'failed' : 'retrying',
				'crm_attempts' => $attempts,
				'crm_error'    => mb_substr( $why, 0, 255 ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( ! $give_up ) {
			// Back off, so a CRM having a bad ten minutes is not hammered.
			$delay = min( 30 * MINUTE_IN_SECONDS, 60 * ( 2 ** ( $attempts - 1 ) ) );

			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + $delay, self::DISPATCH_HOOK, array( $id ), 'eduai-enquiry' );
			} else {
				wp_schedule_single_event( time() + $delay, self::DISPATCH_HOOK, array( $id ) );
			}
		}
	}

	/**
	 * Forget leads older than the retention period.
	 *
	 * Zero means keep for ever, and that is an explicit choice an administrator
	 * has to make rather than the default.
	 */
	public static function prune(): int {
		global $wpdb;

		$days = (int) ( get_option( 'eduai_enquiry_settings', array() )['retention_days'] ?? 365 );

		if ( $days <= 0 ) {
			return 0;
		}

		EduAI_Enquiry_Session::prune();

		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE created_at < %s AND crm_state IN ( %s, %s )',
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
				'sent',
				'no_endpoint'
			)
		);
	}

	/**
	 * Recent enquiries, for the admin screen.
	 */
	public static function recent( int $limit = 50 ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', max( 1, min( 200, $limit ) ) ),
			ARRAY_A
		);
	}
}
