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
	 * Where the last retention run is recorded.
	 */
	public const STATUS_OPTION = 'eduai_enquiry_prune_last';

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
				consent_text TEXT NULL,
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

		/*
		 * WordPress already has subject-access and erasure tooling, under
		 * Tools > Export/Erase Personal Data, and every other store on this
		 * site is registered with it — WordPress's own, and eleven from
		 * LearnDash. A PII table that does not register is INVISIBLE to it:
		 * an administrator runs an erasure request, the screen reports
		 * success, and the enquiry row survives untouched. That is worse than
		 * having no tooling, because it answers the question wrongly.
		 */
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );

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

		/*
		 * WHAT THEY AGREED TO, not merely that they agreed.
		 *
		 * `consent = 1` records our own assertion and nothing a person can be
		 * held to. The question anybody asks afterwards — a regulator, the
		 * visitor, a buyer of this plugin — is always *what were they shown*,
		 * and a boolean cannot answer it. The wording is also translated and
		 * will be edited, so a row captured today cannot be explained by
		 * reading today's copy of the string.
		 *
		 * Stored verbatim, at capture time, in the language it was displayed.
		 */
		$consent_text = trim( wp_strip_all_tags( (string) ( $lead['consent_text'] ?? '' ) ) );

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
				// Null rather than '' when nothing was supplied: it must be
				// possible to tell "we did not record the wording" from
				// "the wording was empty", and the exporter says so out loud.
				'consent_text' => ( $consent && '' !== $consent_text ) ? mb_substr( $consent_text, 0, 2000 ) : null,
				'source'     => mb_substr( (string) ( $lead['source'] ?? 'chat' ), 0, 40 ),
				'crm_state'  => 'pending',
				'created_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
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
				'course'     => $row['course_id'] ? EduAI_Enquiry_Catalog::plain_title( (int) $row['course_id'] ) : '',
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
	 *
	 * EVERY STATE, INCLUDING THE ONES WE FAILED TO DELIVER
	 *
	 * This deleted only `sent` and `no_endpoint`, so a lead whose CRM call
	 * failed was kept for ever. That reads as caution and is the opposite of
	 * it: a broken webhook silently turns a retention policy into indefinite
	 * storage of somebody's name, email and phone number, and the worse our
	 * delivery is, the longer we hold their data. Being unable to deliver it
	 * is not a lawful basis for keeping it, and it is not a reason the person
	 * would accept.
	 *
	 * So retention runs on the age of the row, whatever became of it — and the
	 * per-state counts are recorded so that discarding undelivered enquiries
	 * is VISIBLE rather than silent. A large `failed` number here is an
	 * instruction to fix the endpoint, not to keep the rows longer.
	 */
	public static function prune(): int {
		global $wpdb;

		$days = (int) ( get_option( 'eduai_enquiry_settings', array() )['retention_days'] ?? 365 );

		if ( $days <= 0 ) {
			self::record_prune( array( 'skipped' => 'retention disabled' ) );

			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// Counted BEFORE deletion, because afterwards there is nothing left to
		// count and "0 failed enquiries discarded" would be indistinguishable
		// from "this never ran".
		$by_state = array();

		foreach ( (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT crm_state, COUNT(*) n FROM ' . self::table() . ' WHERE created_at < %s GROUP BY crm_state',
				$cutoff
			)
		) as $row ) {
			$by_state[ (string) $row->crm_state ] = (int) $row->n;
		}

		$deleted  = (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', $cutoff )
		);
		$sessions = (int) EduAI_Enquiry_Session::prune();

		self::record_prune(
			array(
				'deleted'        => $deleted,
				'by_state'       => $by_state,
				'sessions'       => $sessions,
				'retention_days' => $days,
			)
		);

		return $deleted;
	}

	/**
	 * Write down that retention ran, and what it did.
	 *
	 * A purge nobody can see is a failure this platform already has once:
	 * backup software installed, enabled, reading as protection, and never
	 * executed. Nothing distinguishes "ran and found nothing" from "never ran"
	 * unless the run leaves a mark, so this records the ATTEMPT and not only
	 * the outcome.
	 *
	 * Counts only. No lead ever passes through here.
	 *
	 * @param array $result What happened.
	 */
	private static function record_prune( array $result ): void {
		update_option(
			self::STATUS_OPTION,
			array_merge( array( 'at' => gmdate( 'Y-m-d H:i:s' ) ), $result ),
			false
		);
	}

	/**
	 * When retention last ran, what it removed, and when it runs next.
	 *
	 * For the admin screen. Counts only, never lead data.
	 */
	public static function prune_status(): array {
		$last = get_option( self::STATUS_OPTION, array() );
		$next = wp_next_scheduled( self::RETENTION_HOOK );

		return array(
			'last'      => is_array( $last ) ? $last : array(),
			'next'      => $next ? (int) $next : 0,
			'scheduled' => (bool) $next,
		);
	}

	/**
	 * Tell WordPress how to export an enquiry.
	 *
	 * @param array $exporters Registered exporters.
	 */
	public static function register_exporter( $exporters ) {
		$exporters['eduai-enquiry-leads'] = array(
			'exporter_friendly_name' => __( 'Course enquiries', 'eduai-enquiry' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);

		return $exporters;
	}

	/**
	 * Tell WordPress how to erase an enquiry.
	 *
	 * @param array $erasers Registered erasers.
	 */
	public static function register_eraser( $erasers ) {
		$erasers['eduai-enquiry-leads'] = array(
			'eraser_friendly_name' => __( 'Course enquiries', 'eduai-enquiry' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * Everything held about one email address.
	 *
	 * WordPress keys privacy requests by email, so an enquiry captured with a
	 * phone number and no email cannot be found by this. That is a limit of
	 * the platform's model rather than of this table, and it is worth knowing
	 * before anybody reports an export as complete.
	 *
	 * @param string $email_address Subject.
	 * @param int    $page          1-based page.
	 */
	public static function export_personal_data( $email_address, $page = 1 ): array {
		global $wpdb;

		$email = sanitize_email( (string) $email_address );
		$page  = max( 1, (int) $page );
		$per   = 100;

		if ( '' === $email ) {
			return array( 'data' => array(), 'done' => true );
		}

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d',
				$email,
				$per,
				( $page - 1 ) * $per
			),
			ARRAY_A
		);

		$export = array();

		foreach ( $rows as $row ) {
			$export[] = array(
				'group_id'    => 'eduai-enquiry-leads',
				'group_label' => __( 'Course enquiries', 'eduai-enquiry' ),
				'item_id'     => 'enquiry-' . (int) $row['id'],
				'data'        => array(
					array( 'name' => __( 'Name', 'eduai-enquiry' ), 'value' => $row['name'] ),
					array( 'name' => __( 'Email', 'eduai-enquiry' ), 'value' => $row['email'] ),
					array( 'name' => __( 'Phone', 'eduai-enquiry' ), 'value' => $row['phone'] ),
					array( 'name' => __( 'Enquiry', 'eduai-enquiry' ), 'value' => $row['interest'] ),
					array(
						'name'  => __( 'Course', 'eduai-enquiry' ),
						'value' => $row['course_id'] ? EduAI_Enquiry_Catalog::plain_title( (int) $row['course_id'] ) : '',
					),
					array( 'name' => __( 'Language', 'eduai-enquiry' ), 'value' => $row['language'] ),
					array( 'name' => __( 'Received', 'eduai-enquiry' ), 'value' => $row['created_at'] ),
					array(
						'name'  => __( 'Consent given', 'eduai-enquiry' ),
						'value' => $row['consent_at'] ? $row['consent_at'] : __( 'no', 'eduai-enquiry' ),
					),
					array(
						'name'  => __( 'What they agreed to', 'eduai-enquiry' ),
						// Said out loud rather than left blank. An export that
						// silently omits the wording implies none was shown.
						'value' => ( null === $row['consent_text'] || '' === $row['consent_text'] )
							? __( 'The exact wording shown was not recorded for this enquiry.', 'eduai-enquiry' )
							: $row['consent_text'],
					),
					array( 'name' => __( 'Sent to CRM', 'eduai-enquiry' ), 'value' => $row['crm_state'] ),
				),
			);
		}

		return array( 'data' => $export, 'done' => count( $rows ) < $per );
	}

	/**
	 * Erase enquiries for one email address.
	 *
	 * DELETED, NOT ANONYMISED. An enquiry stripped of its contact details is
	 * not a lead, and keeping the shell so a funnel count stays tidy is
	 * precisely the retention that a person asking to be forgotten is asking
	 * us not to do.
	 *
	 * A still-undelivered enquiry goes too, and that loses the enquiry. It is
	 * the correct trade: the request to be forgotten outranks our wish to sell
	 * to them.
	 *
	 * @param string $email_address Subject.
	 * @param int    $page          1-based page.
	 */
	public static function erase_personal_data( $email_address, $page = 1 ): array {
		global $wpdb;

		$email = sanitize_email( (string) $email_address );

		if ( '' === $email ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$removed  = (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE email = %s', $email )
		);
		$messages = array();

		if ( $removed > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of enquiries deleted. */
				_n(
					'%d course enquiry was deleted. Any copy already sent to the CRM has to be removed there too.',
					'%d course enquiries were deleted. Any copies already sent to the CRM have to be removed there too.',
					$removed,
					'eduai-enquiry'
				),
				$removed
			);
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => true,
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
