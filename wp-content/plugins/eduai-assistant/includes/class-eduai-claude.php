<?php
/**
 * Model gateway.
 *
 * Speaks to whichever provider the site is configured for. Anthropic uses its
 * own Messages API; Groq (and anything else OpenAI-compatible) uses the chat
 * completions shape. Both are normalised to the same return value, so nothing
 * upstream of this file knows or cares which is in use.
 *
 * Agents ask for a model by tier — strongest, balanced, fast — rather than by
 * id, which is what lets the same agent definitions run on either provider.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles HTTP transport, provider differences, error mapping and PDF blocks.
 */
class EduAI_Claude {

	private const API_VER = '2023-06-01';

	// Opus thinking through a long derivation, or summarising a whole lecture,
	// runs past 90s often enough to matter. PHP's own limit is 300 (php/uploads.ini).
	private const TIMEOUT = 180;

	/**
	 * Supported providers, their endpoints and their model tiers.
	 *
	 * Tier names are deliberately capability-shaped rather than vendor-shaped:
	 * an agent asking for `strongest` gets Opus on Anthropic and gpt-oss-120b on
	 * Groq, and neither the agent file nor the settings screen has to change
	 * when the provider does.
	 */
	public static function providers(): array {
		$providers = array(
			'anthropic' => array(
				'label'    => __( 'Anthropic — Claude', 'eduai' ),
				'endpoint' => 'https://api.anthropic.com/v1/messages',
				'format'   => 'anthropic',
				'constant' => 'EDUAI_ANTHROPIC_API_KEY',
				'prefix'   => 'sk-ant-',
				'console'  => 'https://console.anthropic.com',
				'models'   => array(
					'strongest' => 'claude-opus-5',
					'balanced'  => 'claude-sonnet-5',
					'fast'      => 'claude-haiku-4-5-20251001',
				),
			),
			'groq'      => array(
				'label'    => __( 'Groq — open models, free tier', 'eduai' ),
				'endpoint' => 'https://api.groq.com/openai/v1/chat/completions',
				'format'   => 'openai',
				'constant' => 'EDUAI_GROQ_API_KEY',
				'prefix'   => 'gsk_',
				'console'  => 'https://console.groq.com/keys',
				/*
				 * BOTH LLAMA ENTRIES WERE 404 ON THE LIVE KEY. Groq retired the
				 * whole Llama line; `GET /openai/v1/models` no longer lists
				 * llama-3.3-70b-versatile or llama-3.1-8b-instant. Nothing in
				 * this repo changed - the provider's catalogue did, and the
				 * chat tab had been asking for a model that no longer exists.
				 *
				 * gpt-oss-20b for both lower tiers, measured rather than
				 * assumed: it returns its thinking in a separate `reasoning`
				 * field and its answer in `content`, so a student sees the
				 * answer. qwen/qwen3.6-27b is the other survivor of that size
				 * and it is NOT usable here - it emits a <think> block inside
				 * `content`, so its internal monologue would be printed to the
				 * student as the reply.
				 *
				 * A pinned model id is a dependency on a catalogue nobody here
				 * controls, and no test that asks whether we agree with
				 * ourselves can see it change. scripts/model-catalogue.php asks
				 * the provider instead, reading these ids from this array
				 * rather than repeating them.
				 */
				'models'   => array(
					'strongest' => 'openai/gpt-oss-120b',
					'balanced'  => 'openai/gpt-oss-20b',
					'fast'      => 'openai/gpt-oss-20b',
				),
			),
			'zai'       => array(
				'label'    => __( 'Z.ai — GLM, free tier', 'eduai' ),
				'endpoint' => 'https://api.z.ai/api/paas/v4/chat/completions',
				'format'   => 'openai',
				'constant' => 'EDUAI_ZAI_API_KEY',
				// Keys are issued as <32 hex>.<secret>, with no fixed prefix.
				'prefix'   => '',
				'console'  => 'https://z.ai/manage-apikey/apikey-list',
				'models'   => array(
					'strongest' => 'glm-4.6',
					'balanced'  => 'glm-4.5-air',
					'fast'      => 'glm-4.5-flash',
				),
			),
		);

		/**
		 * Filter the provider registry — the hook for adding Gemini, OpenAI or
		 * a self-hosted OpenAI-compatible endpoint.
		 *
		 * @param array $providers Providers keyed by id.
		 */
		return (array) apply_filters( 'eduai_providers', $providers );
	}

	/**
	 * Tiers offered in the settings dropdowns.
	 */
	public static function tiers(): array {
		return array(
			'strongest' => __( 'Strongest — best reasoning, highest cost', 'eduai' ),
			'balanced'  => __( 'Balanced — the sensible default', 'eduai' ),
			'fast'      => __( 'Fastest — cheapest, shallowest', 'eduai' ),
		);
	}

	/**
	 * The configured provider id, falling back to whichever one has a key.
	 */
	public static function provider_id(): string {
		$all       = self::providers();
		$configured = (string) EduAI_Settings::get( 'provider', 'anthropic' );

		if ( isset( $all[ $configured ] ) && '' !== EduAI_Settings::api_key( $configured ) ) {
			return $configured;
		}

		// A key for exactly one provider is unambiguous — use it rather than
		// failing with "no key configured" for a provider nobody set up.
		foreach ( $all as $id => $meta ) {
			if ( '' !== EduAI_Settings::api_key( $id ) ) {
				return $id;
			}
		}

		return isset( $all[ $configured ] ) ? $configured : 'anthropic';
	}

	/**
	 * Metadata for the active provider.
	 */
	public static function provider(): array {
		$all = self::providers();
		$id  = self::provider_id();

		return $all[ $id ] ?? reset( $all );
	}

	/**
	 * Turn a tier name or a literal model id into the id to send.
	 *
	 * Anything that is not a known tier is passed through untouched, so an
	 * agent file may still pin an exact model when that is genuinely wanted.
	 *
	 * @param string $preference Tier name, model id, or empty for the site default.
	 */
	public static function resolve_model( string $preference = '' ): string {
		$provider = self::provider();
		$models   = $provider['models'];

		$preference = trim( $preference );

		if ( '' === $preference ) {
			$preference = (string) EduAI_Settings::get( 'model', 'balanced' );
		}

		if ( isset( $models[ $preference ] ) ) {
			return $models[ $preference ];
		}

		// Not a tier: either a literal id, or a stale setting from another
		// provider. Pass literals through; fall back for anything unusable.
		return '' !== $preference ? $preference : $models['balanced'];
	}

	/**
	 * Send a request and normalise the reply.
	 *
	 * @param array  $messages Conversation turns: [ ['role'=>'user','content'=>mixed], ... ].
	 * @param string $system   System prompt.
	 * @param array  $args     Optional overrides: model, max_tokens, temperature.
	 * @return array|WP_Error  ['text'=>string, 'usage'=>array, 'stop_reason'=>string, 'model'=>string]
	 */
	public static function message( array $messages, string $system = '', array $args = array() ) {
		$provider = self::provider();
		$key      = EduAI_Settings::api_key( self::provider_id() );

		if ( ! $key ) {
			return new WP_Error(
				'eduai_no_key',
				sprintf(
					/* translators: 1: provider name 2: console URL */
					__( 'No %1$s API key is configured. Add one under Settings → EduAI Assistant, or get one at %2$s.', 'eduai' ),
					$provider['label'],
					$provider['console']
				),
				array( 'status' => 500 )
			);
		}

		$model       = self::resolve_model( (string) ( $args['model'] ?? '' ) );
		$max_tokens  = (int) ( $args['max_tokens'] ?? EduAI_Settings::get( 'max_tokens', 2000 ) );
		$temperature = (float) ( $args['temperature'] ?? EduAI_Settings::get( 'temperature', 0.2 ) );

		$request = 'openai' === $provider['format']
			? self::build_openai( $messages, $system, $model, $max_tokens, $temperature, $key )
			: self::build_anthropic( $messages, $system, $model, $max_tokens, $temperature, $key );

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		/**
		 * Filter the outgoing request body — useful for adding tools later.
		 *
		 * @param array  $body     Request payload.
		 * @param string $provider Active provider id.
		 */
		$request['body'] = apply_filters( 'eduai_request_body', $request['body'], self::provider_id() );

		$response = wp_remote_post( $provider['endpoint'], array(
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => $request['headers'],
			'body'        => wp_json_encode( $request['body'] ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'eduai_http',
				__( 'Could not reach the model provider. Check the server\'s outbound connection.', 'eduai' ),
				array( 'status' => 502, 'detail' => $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return new WP_Error( 'eduai_api_' . $code, self::friendly_error( $code, $json ), array( 'status' => $code >= 500 ? 502 : 400 ) );
		}

		$result = 'openai' === $provider['format']
			? self::parse_openai( $json, $model )
			: self::parse_anthropic( $json, $model );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['provider'] = self::provider_id();

		return $result;
	}

	/**
	 * Anthropic Messages API payload.
	 */
	private static function build_anthropic( array $messages, string $system, string $model, int $max_tokens, float $temperature, string $key ): array {
		$body = array(
			'model'       => $model,
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
			'messages'    => self::normalise( $messages ),
		);

		if ( '' !== trim( $system ) ) {
			$body['system'] = $system;
		}

		return array(
			'body'    => $body,
			'headers' => array(
				'content-type'      => 'application/json',
				'x-api-key'         => $key,
				'anthropic-version' => self::API_VER,
			),
		);
	}

	/**
	 * OpenAI-compatible chat completions payload.
	 *
	 * Two differences from Anthropic worth knowing: the system prompt is the
	 * first message rather than its own field, and there is no document content
	 * block, so a PDF cannot be handed over for the model to read.
	 *
	 * @return array|WP_Error
	 */
	private static function build_openai( array $messages, string $system, string $model, int $max_tokens, float $temperature, string $key ) {
		$out = array();

		if ( '' !== trim( $system ) ) {
			$out[] = array( 'role' => 'system', 'content' => $system );
		}

		foreach ( self::normalise( $messages ) as $turn ) {
			$content = $turn['content'];

			if ( is_array( $content ) ) {
				$parts = array();

				foreach ( $content as $block ) {
					if ( isset( $block['type'] ) && 'document' === $block['type'] ) {
						return new WP_Error(
							'eduai_no_document_support',
							__( 'This provider cannot read PDFs directly. Switch to Anthropic for scanned documents, or upload a file with a text layer.', 'eduai' ),
							array( 'status' => 415 )
						);
					}
					if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
						$parts[] = $block['text'];
					}
				}

				$content = implode( "\n\n", $parts );
			}

			if ( '' === trim( (string) $content ) ) {
				continue;
			}

			$out[] = array( 'role' => $turn['role'], 'content' => $content );
		}

		// Groq rejects a literal 0 and silently rewrites it; being explicit here
		// keeps the request identical to what was intended.
		if ( $temperature <= 0 ) {
			$temperature = 0.00001;
		}

		return array(
			'body'    => array(
				'model'       => $model,
				'max_tokens'  => $max_tokens,
				'temperature' => $temperature,
				'messages'    => $out,
			),
			'headers' => array(
				'content-type'  => 'application/json',
				'authorization' => 'Bearer ' . $key,
			),
		);
	}

	/**
	 * @return array|WP_Error
	 */
	private static function parse_anthropic( $json, string $model ) {
		if ( ! is_array( $json ) || empty( $json['content'] ) ) {
			return new WP_Error( 'eduai_bad_payload', __( 'The API returned an unexpected response.', 'eduai' ), array( 'status' => 502 ) );
		}

		$text = '';
		foreach ( $json['content'] as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$text .= $block['text'];
			}
		}

		// Same rule as the OpenAI branch: an empty answer is never handed
		// upstream as a success. Here it means every content block was
		// something other than text — thinking blocks, most likely — and the
		// budget ran out before any prose was written.
		if ( '' === trim( $text ) ) {
			return new WP_Error(
				'eduai_truncated',
				'max_tokens' === ( $json['stop_reason'] ?? '' )
					? __( 'The model used its whole reply budget before writing an answer. Raise "Max tokens" under Settings → EduAI Assistant.', 'eduai' )
					: __( 'The model returned an empty answer. Try asking again.', 'eduai' ),
				array( 'status' => 502 )
			);
		}

		return array(
			'text'        => trim( $text ),
			'usage'       => $json['usage'] ?? array(),
			'stop_reason' => $json['stop_reason'] ?? '',
			'model'       => $json['model'] ?? $model,
		);
	}

	/**
	 * @return array|WP_Error
	 */
	private static function parse_openai( $json, string $model ) {
		if ( ! is_array( $json ) || empty( $json['choices'][0]['message'] ) ) {
			return new WP_Error( 'eduai_bad_payload', __( 'The API returned an unexpected response.', 'eduai' ), array( 'status' => 502 ) );
		}

		$usage  = $json['usage'] ?? array();
		$choice = $json['choices'][0];
		$text   = trim( (string) ( $choice['message']['content'] ?? '' ) );
		$finish = (string) ( $choice['finish_reason'] ?? '' );

		// Reasoning models spend the SAME max_tokens budget on thinking as on
		// the answer, and put the two in different fields: the thinking in
		// `reasoning`, the answer in `content`. Run the budget out mid-thought
		// and the provider returns HTTP 200 with `content: ""` — a success
		// carrying nothing.
		//
		// Passed through, that reaches the student as an empty reply from a
		// working assistant, which is indistinguishable from the model not
		// being connected at all and is the single most confusing failure this
		// gateway can produce. It is a truncation, so it is reported as one.
		if ( '' === $text ) {
			$reasoned = '' !== trim( (string) ( $choice['message']['reasoning'] ?? '' ) );

			if ( 'length' === $finish ) {
				return new WP_Error(
					'eduai_truncated',
					$reasoned
						// The specific, actionable case: all of the budget went
						// on thinking and none was left to answer with.
						? __( 'The model used its whole reply budget thinking and had none left to answer with. Raise "Max tokens" under Settings → EduAI Assistant — reasoning models need roughly double what the visible answer looks like it needs.', 'eduai' )
						: __( 'The reply was cut off before it began. Raise "Max tokens" under Settings → EduAI Assistant.', 'eduai' ),
					array( 'status' => 502 )
				);
			}

			return new WP_Error(
				'eduai_empty',
				__( 'The model returned an empty answer. Try asking again.', 'eduai' ),
				array( 'status' => 502 )
			);
		}

		return array(
			'text'        => $text,
			// Re-key to the Anthropic names so conversation logging stays uniform.
			'usage'       => array(
				'input_tokens'  => (int) ( $usage['prompt_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $usage['completion_tokens'] ?? 0 ),
			),
			'stop_reason' => $finish,
			'model'       => $json['model'] ?? $model,
		);
	}

	/**
	 * Turn plain-string content into the API's block format.
	 */
	private static function normalise( array $messages ): array {
		$out = array();

		foreach ( $messages as $m ) {
			if ( empty( $m['role'] ) || ! isset( $m['content'] ) ) {
				continue;
			}

			$role    = 'assistant' === $m['role'] ? 'assistant' : 'user';
			$content = $m['content'];

			if ( is_string( $content ) ) {
				$content = trim( $content );
				if ( '' === $content ) {
					continue;
				}
			}

			$out[] = array( 'role' => $role, 'content' => $content );
		}

		return $out;
	}

	/**
	 * Can the active provider read a PDF handed to it directly?
	 */
	public static function supports_documents(): bool {
		$provider = self::provider();
		return 'anthropic' === $provider['format'];
	}

	/**
	 * Build a PDF document content block from a local file.
	 *
	 * @param string $path Absolute path to a PDF.
	 * @return array|WP_Error
	 */
	public static function pdf_block( string $path ) {
		if ( ! self::supports_documents() ) {
			return new WP_Error(
				'eduai_no_document_support',
				__( 'That PDF has no text layer, and the current provider cannot read the pages itself. Switch to Anthropic under Settings → EduAI Assistant, or run the file through OCR first.', 'eduai' ),
				array( 'status' => 415 )
			);
		}

		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'eduai_file', __( 'The uploaded file could not be read.', 'eduai' ) );
		}

		$bytes = filesize( $path );
		if ( $bytes > 25 * MB_IN_BYTES ) {
			return new WP_Error( 'eduai_file_big', __( 'That PDF is larger than 25 MB. Please split it first.', 'eduai' ) );
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw ) {
			return new WP_Error( 'eduai_file', __( 'The uploaded file could not be read.', 'eduai' ) );
		}

		return array(
			'type'   => 'document',
			'source' => array(
				'type'       => 'base64',
				'media_type' => 'application/pdf',
				'data'       => base64_encode( $raw ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			),
		);
	}

	/**
	 * Map API failures to messages a site admin can act on.
	 *
	 * @param int   $code HTTP status.
	 * @param mixed $json Decoded body.
	 */
	private static function friendly_error( int $code, $json ): string {
		$detail = '';

		// Anthropic nests the message under error.message; so does Groq.
		if ( is_array( $json ) && isset( $json['error']['message'] ) ) {
			$detail = ' (' . wp_strip_all_tags( (string) $json['error']['message'] ) . ')';
		}

		$provider = self::provider();

		switch ( $code ) {
			case 401:
				return sprintf(
					/* translators: %s: provider name */
					__( 'The %s API rejected the key. Check it under Settings → EduAI Assistant.', 'eduai' ),
					$provider['label']
				) . $detail;
			case 403:
				return __( 'This API key is not allowed to use the selected model.', 'eduai' ) . $detail;
			case 404:
				return __( 'That model ID does not exist for this provider. Pick another tier in the settings.', 'eduai' ) . $detail;
			case 413:
				return __( 'That request was too large for this model\'s context window. Try a shorter document.', 'eduai' ) . $detail;
			case 429:
				return __( 'Rate limit reached at the provider. Wait a moment and try again.', 'eduai' ) . $detail;
			case 400:
				return __( 'The request was rejected as invalid.', 'eduai' ) . $detail;
			default:
				if ( $code >= 500 ) {
					return __( 'The provider is temporarily unavailable. Try again shortly.', 'eduai' ) . $detail;
				}
				return __( 'The assistant could not complete that request.', 'eduai' ) . $detail;
		}
	}

	/**
	 * Cheap connectivity probe used by the settings page.
	 *
	 * @return true|WP_Error
	 */
	public static function ping() {
		$result = self::message(
			array( array( 'role' => 'user', 'content' => 'Reply with the single word: ready' ) ),
			'You are a connectivity probe. Reply with one word.',
			// Not 16. A one-word answer needs one token, but a reasoning model
			// spends this same budget thinking first and would run out before
			// writing it — so the probe would report a broken connection on a
			// provider that is working perfectly. 512 is far more than the
			// answer needs and comfortably more than the thinking does.
			array( 'max_tokens' => 512, 'temperature' => 0 )
		);

		return is_wp_error( $result ) ? $result : true;
	}
}
