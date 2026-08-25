<?php
/**
 * The two endpoints the widget talks to.
 *
 * The request and response shapes are the front-end's, not mine — their script
 * was committed first and this matches it rather than making them adapt:
 *
 *   POST /chat  { text, lang, session }
 *   POST /lead  { form, fields: {...}, lang }
 *   reply       { type, lang, text, cards[], chips[], form, meta, session }
 *
 * WHY THESE ARE PUBLIC
 *
 * The visitors this serves are logged out, and their pages are cached. A
 * required nonce on a cached page is a nonce that expired before anybody read
 * it — the well-known failure where a contact form works for administrators and
 * silently does not for the public. Their script sends `X-WP-Nonce` and it is
 * accepted, but it is not required, because requiring it would break the only
 * audience this feature has.
 *
 * The protection is what actually works for anonymous traffic: a per-address
 * rate limit, a length cap, and a honeypot on the lead form. Neither endpoint
 * reads private data or reflects input into a page; the write endpoint is
 * limited harder than the read one.
 *
 * SESSION CONTINUITY IS A COOKIE
 *
 * Their client sends `CFG.session` but does not yet store what comes back, so a
 * body-only token would lose the thread at turn two. The token is issued as a
 * cookie on the first reply and read from there afterwards, which needs no
 * client change; it is also returned in the envelope so the client can carry it
 * explicitly whenever that is wired.
 *
 * @package EduAI_Enquiry
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST surface.
 */
class EduAI_Enquiry_Rest {

	private const NS = 'eduai-enquiry/v1';

	public const COOKIE = 'eduai_eq_session';

	/**
	 * Messages allowed per address, per window.
	 */
	private const CHAT_LIMIT = 20;

	/**
	 * Leads allowed per address, per window.
	 */
	private const LEAD_LIMIT = 5;

	private const WINDOW = 5 * MINUTE_IN_SECONDS;

	/**
	 * Longest message accepted.
	 */
	private const MAX_CHARS = 1000;

	/**
	 * Register routes.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Route definitions.
	 */
	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'chat' ),
			)
		);

		register_rest_route(
			self::NS,
			'/lead',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'lead' ),
			)
		);
	}

	/**
	 * One conversational turn.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function chat( WP_REST_Request $request ) {
		$started = microtime( true );

		if ( ! self::allowed( 'chat', self::CHAT_LIMIT ) ) {
			return new WP_Error(
				'eduai_eq_rate_limited',
				__( 'Too many messages. Please wait a moment.', 'eduai-enquiry' ),
				array( 'status' => 429 )
			);
		}

		// `text` is theirs; `message` is accepted so a direct caller or an
		// older client is not silently ignored.
		$text = trim( (string) ( $request->get_param( 'text' ) ?? $request->get_param( 'message' ) ?? '' ) );

		if ( '' === $text ) {
			return new WP_Error( 'eduai_eq_empty', __( 'Say something first.', 'eduai-enquiry' ), array( 'status' => 400 ) );
		}

		$text    = mb_substr( wp_strip_all_tags( $text ), 0, self::MAX_CHARS );
		$session = EduAI_Enquiry_Session::open( self::token( $request ) );

		$forced = (string) ( $request->get_param( 'lang' ) ?? $request->get_param( 'language' ) ?? '' );

		if ( in_array( $forced, array( 'en', 'ar' ), true ) ) {
			$session['language'] = $forced;
		}

		$result = EduAI_Enquiry_Flows::handle( $text, $session );

		EduAI_Enquiry_Session::save( $session['token'], $result['language'], $result['state'] );
		self::remember( $session['token'] );

		$reply = $result['reply'];

		// `lang` is what their renderer reads for per-message direction.
		$reply['lang']     = $result['language'];
		$reply['language'] = $result['language'];
		$reply['dir']      = EduAI_Enquiry_I18n::dir( $result['language'] );
		$reply['session']  = $session['token'];
		$reply['ms']       = (int) round( ( microtime( true ) - $started ) * 1000 );

		return rest_ensure_response( $reply );
	}

	/**
	 * A submitted enquiry.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function lead( WP_REST_Request $request ) {
		if ( ! self::allowed( 'lead', self::LEAD_LIMIT ) ) {
			return new WP_Error(
				'eduai_eq_rate_limited',
				__( 'Too many submissions. Please wait a moment.', 'eduai-enquiry' ),
				array( 'status' => 429 )
			);
		}

		// Their client nests the values under `fields`; a flat body is accepted
		// too so the endpoint is usable without their script.
		$fields = (array) ( $request->get_param( 'fields' ) ?? array() );

		$value = static function ( string $key ) use ( $fields, $request ) {
			return (string) ( $fields[ $key ] ?? $request->get_param( $key ) ?? '' );
		};

		// A field no human can see and no human fills in.
		if ( '' !== trim( $value( 'website' ) ) ) {
			// Answer as though it worked. Telling a bot it was detected only
			// teaches whoever wrote it to try again differently.
			return rest_ensure_response( array( 'ok' => true ) );
		}

		$session  = EduAI_Enquiry_Session::open( self::token( $request ) );
		$language = in_array( $request->get_param( 'lang' ), array( 'en', 'ar' ), true )
			? (string) $request->get_param( 'lang' )
			: $session['language'];

		$consent = $fields['consent'] ?? $request->get_param( 'consent' );

		if ( ! $consent || 'false' === $consent || '0' === $consent ) {
			return new WP_Error(
				'eduai_eq_no_consent',
				EduAI_Enquiry_I18n::t( 'consent_required', $language ),
				array( 'status' => 400 )
			);
		}

		$id = EduAI_Enquiry_Leads::capture(
			array(
				'name'      => $value( 'name' ),
				'email'     => $value( 'email' ),
				'phone'     => $value( 'phone' ),
				'interest'  => $value( 'interest' ),
				'course_id' => (int) $value( 'course_id' ),
				'language'  => $language,
				'consent'   => true,
				'source'    => 'chat',
			)
		);

		if ( is_wp_error( $id ) ) {
			return new WP_Error( $id->get_error_code(), $id->get_error_message(), array( 'status' => 400 ) );
		}

		$state = $session['state'];
		unset( $state['awaiting'] );
		EduAI_Enquiry_Session::save( $session['token'], $language, $state );

		return rest_ensure_response(
			array(
				'ok'   => true,
				'lang' => $language,
				'text' => EduAI_Enquiry_I18n::t( 'lead_thanks', $language ),
			)
		);
	}

	/**
	 * The conversation token: body first, then cookie.
	 */
	private static function token( WP_REST_Request $request ): string {
		$body = (string) ( $request->get_param( 'session' ) ?? $request->get_param( 'token' ) ?? '' );

		if ( '' !== $body ) {
			return $body;
		}

		return isset( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Issue the session cookie.
	 *
	 * HttpOnly, because no script needs to read it, and SameSite=Lax so it
	 * survives ordinary navigation without riding along on cross-site requests.
	 */
	private static function remember( string $token ): void {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::COOKIE,
			$token,
			array(
				'expires'  => time() + 6 * HOUR_IN_SECONDS,
				'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Is this address within its allowance?
	 *
	 * A transient per address per bucket. Crude, and adequate: it exists to
	 * stop one script exhausting a model budget the whole site draws on, not to
	 * defeat a determined attacker.
	 */
	private static function allowed( string $bucket, int $limit ): bool {
		$key   = 'eduai_eq_' . $bucket . '_' . md5( self::address() );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, self::WINDOW );

		return true;
	}

	/**
	 * The caller's address, used only as a rate-limit key.
	 *
	 * Never stored with a lead and never logged. An address is personal data
	 * and this feature does not need to keep one to do its job.
	 */
	private static function address(): string {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		return $ip . '|' . wp_salt( 'nonce' );
	}
}
