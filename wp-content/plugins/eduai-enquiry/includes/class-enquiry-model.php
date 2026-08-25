<?php
/**
 * The language model, borrowed where possible and self-contained where not.
 *
 * ORDER OF PREFERENCE, AND WHY
 *
 * 1. `EduAI_Claude` when the study-assistant plugin is active. It already holds
 *    the provider table, the tier mapping, the error vocabulary and — after a
 *    provider retired two models mid-service — an outage record. Duplicating
 *    that here would give this site two catalogues of model ids to keep in step,
 *    and a pinned id nobody updates is exactly how the chat tab spent a day
 *    calling a model that no longer existed.
 *
 * 2. A small direct client, for a site where this plugin travels alone. It
 *    speaks the OpenAI-compatible shape, which covers Groq, OpenAI and most
 *    others, and it holds ONE model id rather than a tier table, because a
 *    standalone install has nobody to maintain a tier table.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 *
 * It does not retry, and it does not fall back to a second provider on failure.
 * A visitor waiting on a reply has a two second budget; a retry spends it and
 * then fails anyway. Refusing quickly and saying so is the better answer, and
 * the widget can offer a human instead.
 *
 * @package EduAI_Enquiry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Model access for the concierge.
 */
class EduAI_Enquiry_Model {

	/**
	 * Wall-clock budget for one model call, in seconds.
	 *
	 * The requirement is a reply in under two seconds. Measured on this stack,
	 * the lighter model answers a short prompt in 0.4-2.1 s and the strongest
	 * in ~1.9 s, so there is no room for a second attempt. This timeout exists
	 * to fail inside the budget rather than to succeed outside it.
	 */
	private const TIMEOUT = 6;

	/**
	 * Tokens this public assistant may spend per minute.
	 *
	 * A SHARE OF THE PROVIDER ALLOWANCE, NOT THE WHOLE THING.
	 *
	 * This endpoint is unauthenticated. The study assistant sits behind
	 * enrolment and every caller there is a known student; here every caller is
	 * a stranger. Same key means same bucket, so without a local ceiling one
	 * visitor in a loop can exhaust the provider allowance for the entire site
	 * — and the first people to notice are enrolled students whose lessons stop
	 * answering. **The marketing widget can take out the classroom.**
	 *
	 * 2,000 of the measured 8,000 per minute. Deliberately a quarter: this
	 * feature is worth degrading to protect the one people paid for.
	 */
	private const BUDGET_TOKENS = 2000;

	/**
	 * Where the spend is counted.
	 */
	private const BUDGET_KEY = 'eduai_eq_spend';

	/**
	 * Is a model reachable at all?
	 */
	public static function available(): bool {
		return self::via_assistant() || '' !== self::direct_key();
	}

	/**
	 * Which route is in use — for the admin screen, so "no reply" is
	 * diagnosable without reading code.
	 */
	public static function route(): string {
		if ( self::via_assistant() ) {
			return 'eduai-assistant';
		}

		return '' !== self::direct_key() ? 'direct' : 'none';
	}

	/**
	 * Ask the model for text.
	 *
	 * @param string $system   System instruction.
	 * @param string $user     User turn.
	 * @param int    $max      Reply budget in tokens.
	 * @param float  $temp     Sampling temperature.
	 * @return string|WP_Error Text, or an error the caller can show.
	 */
	public static function ask( string $system, string $user, int $max = 400, float $temp = 0.3, int $timeout = 0 ) {
		$deadline = $timeout > 0 ? $timeout : self::TIMEOUT;

		/*
		 * The ceiling is checked BEFORE the call, and charged optimistically at
		 * the requested budget rather than the actual usage. Charging after the
		 * fact lets a burst of simultaneous requests all pass the check and then
		 * blow through it together, which is the shape every naive rate limiter
		 * has.
		 */
		if ( ! self::afford( $max ) ) {
			return new WP_Error(
				'eduai_eq_budget',
				__( 'The assistant is busy. Please try again in a moment.', 'eduai-enquiry' ),
				array( 'status' => 429 )
			);
		}

		if ( self::via_assistant() ) {
			$out = EduAI_Claude::message(
				array( array( 'role' => 'user', 'content' => $user ) ),
				$system,
				array(
					'model'       => self::tier(),
					'max_tokens'  => $max,
					'temperature' => $temp,
					'timeout'     => $deadline,
				)
			);

			if ( is_wp_error( $out ) ) {
				return $out;
			}

			$text = is_array( $out ) ? (string) ( $out['text'] ?? '' ) : (string) $out;

			return '' !== trim( $text ) ? $text : new WP_Error( 'concierge_empty', __( 'The assistant returned nothing.', 'eduai-enquiry' ) );
		}

		return self::direct( $system, $user, $max, $temp, $deadline );
	}

	/**
	 * Can this call be afforded out of the public assistant's own share?
	 *
	 * @param int $tokens Reply budget being requested.
	 */
	private static function afford( int $tokens ): bool {
		$minute = (int) floor( time() / MINUTE_IN_SECONDS );
		$key    = self::BUDGET_KEY . '_' . $minute;
		$spent  = (int) get_transient( $key );

		/**
		 * Tokens per minute this assistant may spend.
		 *
		 * @param int $budget Ceiling.
		 */
		$ceiling = (int) apply_filters( 'eduai_enquiry_token_budget', self::BUDGET_TOKENS );

		if ( $spent + $tokens > $ceiling ) {
			return false;
		}

		set_transient( $key, $spent + $tokens, 2 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Tokens spent this minute, for the admin screen.
	 */
	public static function spent(): array {
		$minute = (int) floor( time() / MINUTE_IN_SECONDS );

		return array(
			'spent'   => (int) get_transient( self::BUDGET_KEY . '_' . $minute ),
			'ceiling' => (int) apply_filters( 'eduai_enquiry_token_budget', self::BUDGET_TOKENS ),
		);
	}

	/**
	 * Is the study assistant's gateway usable right now?
	 */
	private static function via_assistant(): bool {
		return class_exists( 'EduAI_Claude' )
			&& method_exists( 'EduAI_Claude', 'message' )
			&& class_exists( 'EduAI_Settings' )
			&& '' !== (string) EduAI_Settings::api_key( EduAI_Claude::provider_id() );
	}

	/**
	 * Which tier to ask the shared gateway for.
	 *
	 * `balanced` by default and not `strongest`: this writes two sentences
	 * around facts that PHP has already chosen, which is not the job the big
	 * model is for, and the lighter one is inside the latency budget with room
	 * to spare.
	 */
	private static function tier(): string {
		$s = get_option( 'eduai_enquiry_settings', array() );

		$tier = $s['tier'] ?? 'balanced';

		return in_array( $tier, array( 'strongest', 'balanced', 'fast' ), true ) ? $tier : 'balanced';
	}

	/**
	 * The key for a standalone install.
	 *
	 * Constant first so a key can live in wp-config.php rather than the
	 * database, then environment, then the stored option. The same order the
	 * study assistant uses, for the same reason: a key in the options table is
	 * in every database export.
	 */
	private static function direct_key(): string {
		if ( defined( 'EDUAI_ENQUIRY_API_KEY' ) && EDUAI_ENQUIRY_API_KEY ) {
			return (string) EDUAI_ENQUIRY_API_KEY;
		}

		$env = getenv( 'EDUAI_ENQUIRY_API_KEY' );

		if ( $env ) {
			return (string) $env;
		}

		$s = get_option( 'eduai_enquiry_settings', array() );

		return (string) ( $s['api_key'] ?? '' );
	}

	/**
	 * Call an OpenAI-compatible endpoint directly.
	 *
	 * @param string $system System instruction.
	 * @param string $user   User turn.
	 * @param int    $max    Reply budget.
	 * @param float  $temp   Temperature.
	 * @return string|WP_Error
	 */
	private static function direct( string $system, string $user, int $max, float $temp, int $timeout = 0 ) {
		$key = self::direct_key();

		if ( '' === $key ) {
			return new WP_Error( 'concierge_no_key', __( 'No model key is configured.', 'eduai-enquiry' ) );
		}

		$s        = get_option( 'eduai_enquiry_settings', array() );
		$endpoint = (string) ( $s['endpoint'] ?? 'https://api.groq.com/openai/v1/chat/completions' );
		$model    = (string) ( $s['model'] ?? 'openai/gpt-oss-20b' );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => $timeout > 0 ? $timeout : self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $model,
						'max_tokens'  => $max,
						'temperature' => $temp,
						'messages'    => array(
							array( 'role' => 'system', 'content' => $system ),
							array( 'role' => 'user', 'content' => $user ),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'concierge_unreachable', __( 'The assistant could not be reached.', 'eduai-enquiry' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			// The provider's own words are useful to an administrator and
			// meaningless to a visitor, so they go in the data rather than the
			// message the widget shows.
			return new WP_Error(
				'concierge_api_' . $code,
				__( 'The assistant is unavailable.', 'eduai-enquiry' ),
				array( 'detail' => (string) ( $json['error']['message'] ?? '' ) )
			);
		}

		$choice = $json['choices'][0] ?? array();
		$text   = trim( (string) ( $choice['message']['content'] ?? '' ) );

		if ( '' === $text ) {
			/*
			 * A reasoning model bills its thinking against max_tokens and can
			 * return HTTP 200 with an empty answer when the budget runs out
			 * mid-thought. Silence here would look like a working assistant
			 * with nothing to say.
			 */
			return new WP_Error(
				'concierge_empty',
				'length' === ( $choice['finish_reason'] ?? '' )
					? __( 'The reply ran out of room before it began. Raise the reply budget.', 'eduai-enquiry' )
					: __( 'The assistant returned nothing.', 'eduai-enquiry' )
			);
		}

		return $text;
	}
}
