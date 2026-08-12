<?php
/**
 * Settings store and options screen.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes the eduai_settings option and renders the settings page.
 */
class EduAI_Settings {

	public const OPTION = 'eduai_settings';

	/**
	 * Sensible defaults so the plugin works the moment a key is pasted in.
	 */
	public static function defaults(): array {
		return array(
			// Groq first: its free tier maps `strongest` to openai/gpt-oss-120b,
			// which is the strongest model this project can reach without a
			// bill. provider_id() falls through to whichever provider actually
			// has a key, so this is a preference and not a hard requirement.
			'provider'         => 'groq',
			'api_key'          => '',
			'groq_api_key'     => '',
			'zai_api_key'      => '',
			// Tiers, not model ids — see EduAI_Claude::providers().
			'model'            => 'strongest',
			'summary_model'    => 'strongest',
			// Room for a full derivation. The house rules ask for every step and
			// a check line, which 1200 truncated mid-working.
			'max_tokens'       => 2000,
			'temperature'      => 0.2,
			'assistant_name'   => __( 'Study Assistant', 'eduai' ),
			'greeting'         => __( 'Hi! Ask me anything about your course material, or paste a lecture and I will summarise it.', 'eduai' ),
			// Blank means "use the selected agent's own prompt"; anything typed
			// into the settings box overrides the default agent only.
			'system_prompt'    => '',
			'suggestions'      => "Explain this week's key concept simply\nGive me 5 practice questions\nSummarise the last lecture I opened",
			'default_agent'    => EduAI_Agents::DEFAULT_ID,
			'agent_picker'     => true,
			'allow_general_knowledge' => true,
			// Retired: the four AI tabs replaced it (docs/06 §3). Still a
			// setting, so a site that genuinely wants a launcher can have one.
			'enable_floating'  => false,
			'enable_voice'     => true,
			'enable_tts'       => true,
			'voice_lang'       => 'en-US',
			'logged_in_only'   => true,
			'rate_limit'       => 20,
			'context_chunks'   => 6,
			'enable_rag'       => true,
			'log_chats'        => true,
			'retention_days'   => 180,
		);
	}

	public static function default_system_prompt(): string {
		return "You are a patient, encouraging study assistant for university students.\n\n"
			. "Rules:\n"
			. "- Answer using the COURSE MATERIAL provided in the context block whenever it is relevant. Quote or paraphrase it and cite the document title.\n"
			. "- If the context does not cover the question, say so plainly, then answer from general knowledge and label that part clearly as outside the course material.\n"
			. "- Never invent a citation, a page number, a formula or a statistic.\n"
			. "- Explain step by step. Prefer short paragraphs and bullet lists over walls of text.\n"
			. "- When a student is clearly revising, end with one short check-your-understanding question.\n"
			. "- Do not complete graded assignments or exams on the student's behalf; guide them to the answer instead.\n"
			. "- Keep replies under roughly 300 words unless the student asks for more depth.";
	}

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	/**
	 * Full settings array with defaults merged in.
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Option name holding a provider's key.
	 *
	 * Anthropic came first and got the unprefixed name; everything since is
	 * `<provider>_api_key`.
	 *
	 * @param string $provider Provider id.
	 */
	public static function key_option( string $provider ): string {
		return 'anthropic' === $provider ? 'api_key' : $provider . '_api_key';
	}

	/**
	 * API key for a provider.
	 *
	 * Resolution order, most trusted first:
	 *
	 *   1. A wp-config constant — where a production key belongs, because it
	 *      never touches the database and never reaches a browser.
	 *   2. The matching environment variable, so a container or a managed host
	 *      can inject the key without a writable wp-config.
	 *   3. The stored option, kept only so sites configured before the key
	 *      fields were removed from the settings screen keep working.
	 *
	 * Nothing here reads anything the visitor supplied: students are never
	 * asked for a key and cannot set one.
	 *
	 * @param string $provider Provider id. Defaults to the active one.
	 */
	public static function api_key( string $provider = '' ): string {
		$providers = EduAI_Claude::providers();

		if ( '' === $provider ) {
			$provider = EduAI_Claude::provider_id();
		}

		$meta = $providers[ $provider ] ?? null;
		if ( ! $meta ) {
			return '';
		}

		if ( defined( $meta['constant'] ) && constant( $meta['constant'] ) ) {
			return (string) constant( $meta['constant'] );
		}

		// EDUAI_ZAI_API_KEY as a constant, ZAI_API_KEY in the environment —
		// the same name docker-compose.yml and .env.example already use.
		$env = getenv( preg_replace( '/^EDUAI_/', '', $meta['constant'] ) );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			return trim( $env );
		}

		return (string) self::get( self::key_option( $provider ), '' );
	}

	/**
	 * Where the active key came from, for the settings screen.
	 *
	 * @param string $provider Provider id.
	 * @return string One of: constant, environment, database, none.
	 */
	public static function key_source( string $provider ): string {
		$meta = EduAI_Claude::providers()[ $provider ] ?? null;
		if ( ! $meta ) {
			return 'none';
		}

		if ( defined( $meta['constant'] ) && constant( $meta['constant'] ) ) {
			return 'constant';
		}

		$env = getenv( preg_replace( '/^EDUAI_/', '', $meta['constant'] ) );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			return 'environment';
		}

		return '' !== (string) self::get( self::key_option( $provider ), '' ) ? 'database' : 'none';
	}

	/**
	 * Is any provider usable at all?
	 */
	public static function has_any_key(): bool {
		foreach ( array_keys( EduAI_Claude::providers() ) as $id ) {
			if ( '' !== self::api_key( $id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Should the widget render for the current request?
	 */
	public static function widget_visible(): bool {
		if ( is_admin() ) {
			return false;
		}
		if ( self::get( 'logged_in_only', true ) && ! is_user_logged_in() ) {
			return false;
		}
		return (bool) apply_filters( 'eduai_widget_visible', true );
	}

	/**
	 * Should the floating launcher be printed at all?
	 *
	 * Separate from widget_visible(), which answers "may this user see the
	 * assistant" and still governs the shortcodes. This answers the narrower
	 * question the retired launcher asks, and it is the single place the
	 * default lives — the old code passed its own `true` fallback at the call
	 * site, so flipping the default in defaults() would have changed nothing.
	 */
	public static function launcher_visible(): bool {
		return self::widget_visible() && (bool) self::get( 'enable_floating', false );
	}

	/**
	 * Starter prompts shown as chips in an empty chat.
	 */
	public static function suggestions(): array {
		$raw = (string) self::get( 'suggestions', '' );
		$out = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ?: array() ) );
		return array_values( array_slice( $out, 0, 4 ) );
	}

	public static function register(): void {
		register_setting( 'eduai_group', self::OPTION, array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			'default'           => self::defaults(),
		) );
	}

	/**
	 * Sanitise the posted settings array.
	 *
	 * @param mixed $input Raw input.
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$existing = self::all();
		$clean    = array();

		// The settings screen no longer posts keys — they come from wp-config or
		// the environment. Carry any historic stored key across untouched so an
		// upgrade from an older version does not silently disconnect the site.
		foreach ( array_keys( EduAI_Claude::providers() ) as $provider_id ) {
			$field           = self::key_option( $provider_id );
			$clean[ $field ] = (string) ( $existing[ $field ] ?? '' );
		}

		$provider           = sanitize_key( (string) ( $input['provider'] ?? '' ) );
		$clean['provider']  = isset( EduAI_Claude::providers()[ $provider ] ) ? $provider : $defaults['provider'];

		$tiers                  = array_keys( EduAI_Claude::tiers() );
		$clean['model']         = in_array( $input['model'] ?? '', $tiers, true ) ? $input['model'] : $defaults['model'];
		$clean['summary_model'] = in_array( $input['summary_model'] ?? '', $tiers, true ) ? $input['summary_model'] : $defaults['summary_model'];

		$agent                  = sanitize_key( (string) ( $input['default_agent'] ?? '' ) );
		$clean['default_agent'] = EduAI_Agents::exists( $agent ) ? $agent : $defaults['default_agent'];

		$clean['max_tokens']     = max( 256, min( 8000, (int) ( $input['max_tokens'] ?? $defaults['max_tokens'] ) ) );
		$clean['temperature']    = max( 0, min( 1, (float) ( $input['temperature'] ?? $defaults['temperature'] ) ) );
		$clean['rate_limit']     = max( 1, min( 200, (int) ( $input['rate_limit'] ?? $defaults['rate_limit'] ) ) );
		$clean['context_chunks'] = max( 1, min( 20, (int) ( $input['context_chunks'] ?? $defaults['context_chunks'] ) ) );
		$clean['retention_days'] = max( 0, min( 3650, (int) ( $input['retention_days'] ?? $defaults['retention_days'] ) ) );

		$clean['assistant_name'] = sanitize_text_field( $input['assistant_name'] ?? $defaults['assistant_name'] );
		$clean['greeting']       = sanitize_textarea_field( $input['greeting'] ?? $defaults['greeting'] );
		$clean['system_prompt']  = sanitize_textarea_field( $input['system_prompt'] ?? $defaults['system_prompt'] );
		$clean['suggestions']    = sanitize_textarea_field( $input['suggestions'] ?? $defaults['suggestions'] );
		$clean['voice_lang']     = sanitize_text_field( $input['voice_lang'] ?? $defaults['voice_lang'] );

		foreach ( array( 'enable_floating', 'enable_voice', 'enable_tts', 'logged_in_only', 'enable_rag', 'log_chats', 'agent_picker', 'allow_general_knowledge' ) as $flag ) {
			$clean[ $flag ] = ! empty( $input[ $flag ] );
		}

		return $clean;
	}

	/**
	 * Capability tiers offered in the settings dropdowns.
	 *
	 * Kept as a thin alias so older code and templates referring to models()
	 * keep working; EduAI_Claude::tiers() is the definition.
	 */
	public static function models(): array {
		return EduAI_Claude::tiers();
	}

	public static function menu(): void {
		add_options_page(
			__( 'EduAI Assistant', 'eduai' ),
			__( 'EduAI Assistant', 'eduai' ),
			'manage_options',
			'eduai',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s       = self::all();
		$indexed = EduAI_Knowledge::stats();

		// Computed here rather than at the notice below, because the "every
		// document is complete" line above it reads this too — and an undefined
		// variable there is falsy, which would have printed the reassurance
		// unconditionally, including for a broken index. The healthy claim and
		// the warning have to come from one evaluation of one detector.
		$eduai_partial = EduAI_Knowledge::incomplete();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'EduAI Assistant', 'eduai' ); ?></h1>
			<p class="description" style="max-width:70ch">
				<?php esc_html_e( 'The assistant answers from your own study material. The model key is supplied by the server, so once the library is indexed the chat widget is live on the front end with nothing for anyone to configure.', 'eduai' ); ?>
			</p>

			<div class="notice notice-info inline" style="margin:16px 0;padding:10px 12px">
				<p style="margin:0">
					<strong><?php esc_html_e( 'Knowledge base:', 'eduai' ); ?></strong>
					<?php
					printf(
						/* translators: 1: chunk count 2: document count */
						esc_html__( '%1$s passages indexed from %2$s documents.', 'eduai' ),
						'<code>' . esc_html( number_format_i18n( $indexed['chunks'] ) ) . '</code>',
						'<code>' . esc_html( number_format_i18n( $indexed['docs'] ) ) . '</code>'
					);
					?>
					<?php
					/*
					 * Say so when everything is whole, not only when it is not.
					 *
					 * A health report nobody has ever seen succeed is one nobody
					 * trusts the first time it fires: with no positive state,
					 * silence means both "checked, fine" and "never ran", and
					 * the reader cannot tell which. The warning below is the
					 * same detector — this line is the evidence it is awake.
					 */
					if ( $indexed['docs'] && ! $eduai_partial ) :
						?>
						<span style="color:#00844a">
							<?php esc_html_e( 'Every document is complete.', 'eduai' ); ?>
						</span>
						<?php
					endif;
					?>
					<a class="button button-secondary" style="margin-left:8px"
						href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=eduai&eduai_action=reindex' ), 'eduai_reindex' ) ); ?>">
						<?php esc_html_e( 'Rebuild index', 'eduai' ); ?>
					</a>
					<a class="button button-secondary"
						href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=eduai&eduai_action=test' ), 'eduai_test' ) ); ?>">
						<?php esc_html_e( 'Test API connection', 'eduai' ); ?>
					</a>
				</p>
			</div>

			<?php
			/*
			 * Partial indexing used to be invisible. A document could sit in the
			 * table missing a tenth of its chunks while the only number on this
			 * screen — passages indexed — counted the ones that made it and
			 * reported a healthy total. Every grounded answer from that document
			 * was quietly partial and nothing said so for weeks.
			 *
			 * So the screen now says when a document is short, in the place the
			 * person who uploaded it is already looking.
			 */
			?>
			<?php if ( $eduai_partial ) : ?>
				<div class="notice notice-warning inline" style="margin:16px 0;padding:10px 12px">
					<p style="margin:0 0 6px">
						<strong><?php esc_html_e( 'Some documents are only partly indexed.', 'eduai' ); ?></strong>
						<?php esc_html_e( 'The assistant answers from what it has, so questions about these will be answered from part of the document without saying so. Rebuilding the index usually fixes it.', 'eduai' ); ?>
					</p>
					<ul style="margin:0;padding-left:18px;list-style:disc">
						<?php foreach ( $eduai_partial as $eduai_doc ) : ?>
							<li>
								<strong><?php echo esc_html( $eduai_doc['title'] ?: '#' . $eduai_doc['post_id'] ); ?></strong>
								—
								<?php
								if ( $eduai_doc['expected'] ) {
									printf(
										/* translators: 1: passages held 2: passages expected */
										esc_html__( 'holding %1$s of %2$s passages.', 'eduai' ),
										'<code>' . esc_html( (string) $eduai_doc['have'] ) . '</code>',
										'<code>' . esc_html( (string) $eduai_doc['expected'] ) . '</code>'
									);
								} else {
									printf(
										/* translators: %s: passages held */
										esc_html__( 'holding %s passages with gaps between them.', 'eduai' ),
										'<code>' . esc_html( (string) $eduai_doc['have'] ) . '</code>'
									);
								}
								?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'eduai_group' ); ?>

				<h2 class="title"><?php esc_html_e( 'Connection', 'eduai' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="eduai_provider"><?php esc_html_e( 'Provider', 'eduai' ); ?></label></th>
						<td>
							<select id="eduai_provider" name="<?php echo esc_attr( self::OPTION ); ?>[provider]">
								<?php foreach ( EduAI_Claude::providers() as $eduai_pid => $eduai_p ) : ?>
									<option value="<?php echo esc_attr( $eduai_pid ); ?>" <?php selected( $s['provider'], $eduai_pid ); ?>>
										<?php echo esc_html( $eduai_p['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Agents ask for a capability tier, not a model name, so switching provider needs no other change. Only Anthropic can read a PDF that has no text layer.', 'eduai' ); ?>
								<br>
								<strong><?php esc_html_e( 'Currently active:', 'eduai' ); ?></strong>
								<code><?php echo esc_html( EduAI_Claude::provider()['label'] ); ?></code>
								<?php if ( ! self::has_any_key() ) : ?>
									— <span style="color:#b32d2e"><?php esc_html_e( 'the server has supplied no key for any provider, so the assistant cannot answer yet.', 'eduai' ); ?></span>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'API key', 'eduai' ); ?></th>
						<td>
							<?php
							$eduai_sources = array(
								'constant'    => __( 'from wp-config.php', 'eduai' ),
								'environment' => __( 'from the server environment', 'eduai' ),
								'database'    => __( 'stored in the database by an earlier version', 'eduai' ),
							);
							?>
							<ul style="margin:0;padding-left:18px;list-style:disc;max-width:74ch">
								<?php foreach ( EduAI_Claude::providers() as $eduai_pid => $eduai_p ) : ?>
									<?php $eduai_src = self::key_source( $eduai_pid ); ?>
									<li>
										<strong><?php echo esc_html( $eduai_p['label'] ); ?></strong> —
										<?php if ( 'none' === $eduai_src ) : ?>
											<span style="color:#8a8a8a"><?php esc_html_e( 'no key', 'eduai' ); ?></span>
										<?php else : ?>
											<span style="color:#00844a">
												<?php esc_html_e( 'connected', 'eduai' ); ?>
												(<?php echo esc_html( $eduai_sources[ $eduai_src ] ); ?>)
											</span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
							<p class="description" style="max-width:74ch">
								<?php
								echo wp_kses_post(
									__( 'Keys are supplied by the server, not typed in here — define the provider\'s constant in <code>wp-config.php</code>, or set the matching environment variable (<code>ZAI_API_KEY</code>, <code>GROQ_API_KEY</code>, <code>ANTHROPIC_API_KEY</code>). There is deliberately no field for one: a key entered through a browser ends up in the database and in page requests, and students are never asked for one.', 'eduai' )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="eduai_model"><?php esc_html_e( 'Chat model', 'eduai' ); ?></label></th>
						<td>
							<select id="eduai_model" name="<?php echo esc_attr( self::OPTION ); ?>[model]">
								<?php foreach ( self::models() as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="eduai_summary_model"><?php esc_html_e( 'Summarising model', 'eduai' ); ?></label></th>
						<td>
							<select id="eduai_summary_model" name="<?php echo esc_attr( self::OPTION ); ?>[summary_model]">
								<?php foreach ( self::models() as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['summary_model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Long lectures benefit from a stronger model here.', 'eduai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Generation', 'eduai' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Max tokens', 'eduai' ); ?>
								<input type="number" min="256" max="8000" step="64" name="<?php echo esc_attr( self::OPTION ); ?>[max_tokens]" value="<?php echo esc_attr( (string) $s['max_tokens'] ); ?>">
							</label>
							&nbsp;&nbsp;
							<label><?php esc_html_e( 'Temperature', 'eduai' ); ?>
								<input type="number" min="0" max="1" step="0.05" name="<?php echo esc_attr( self::OPTION ); ?>[temperature]" value="<?php echo esc_attr( (string) $s['temperature'] ); ?>">
							</label>
							<p class="description"><?php esc_html_e( 'Keep temperature low (0.1–0.3) for factual answers about course material.', 'eduai' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Behaviour', 'eduai' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="eduai_name"><?php esc_html_e( 'Assistant name', 'eduai' ); ?></label></th>
						<td><input type="text" class="regular-text" id="eduai_name" name="<?php echo esc_attr( self::OPTION ); ?>[assistant_name]" value="<?php echo esc_attr( $s['assistant_name'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="eduai_greeting"><?php esc_html_e( 'Greeting', 'eduai' ); ?></label></th>
						<td><textarea id="eduai_greeting" class="large-text" rows="2" name="<?php echo esc_attr( self::OPTION ); ?>[greeting]"><?php echo esc_textarea( $s['greeting'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="eduai_suggestions"><?php esc_html_e( 'Starter prompts', 'eduai' ); ?></label></th>
						<td>
							<textarea id="eduai_suggestions" class="large-text" rows="4" name="<?php echo esc_attr( self::OPTION ); ?>[suggestions]"><?php echo esc_textarea( $s['suggestions'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line, up to four. Shown as clickable chips in an empty chat.', 'eduai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="eduai_system"><?php esc_html_e( 'System prompt', 'eduai' ); ?></label></th>
						<td>
							<textarea id="eduai_system" class="large-text code" rows="12" name="<?php echo esc_attr( self::OPTION ); ?>[system_prompt]"
								placeholder="<?php esc_attr_e( 'Leave blank to use the default agent\'s own prompt.', 'eduai' ); ?>"><?php echo esc_textarea( $s['system_prompt'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Overrides the persona of the default agent only. Leave it blank and that agent\'s Markdown file is used instead. The retrieved course material and the shared house rules are appended either way — so nothing typed here can stop the assistant answering a maths or science question.', 'eduai' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Agents', 'eduai' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="eduai_agent"><?php esc_html_e( 'Default agent', 'eduai' ); ?></label></th>
						<td>
							<select id="eduai_agent" name="<?php echo esc_attr( self::OPTION ); ?>[default_agent]">
								<?php foreach ( EduAI_Agents::all() as $eduai_agent ) : ?>
									<option value="<?php echo esc_attr( $eduai_agent['id'] ); ?>" <?php selected( $s['default_agent'], $eduai_agent['id'] ); ?>>
										<?php echo esc_html( $eduai_agent['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php
								echo wp_kses_post(
									sprintf(
										/* translators: %s: path to the agents folder */
										__( 'Each agent is a Markdown file in %s, in the same format the claude-code-templates catalogue publishes. Add a file and it appears here and in the chat window; no code changes needed.', 'eduai' ),
										'<code>wp-content/plugins/eduai-assistant/agents/</code>'
									)
								);
								?>
							</p>
							<?php if ( EduAI_Agents::all() ) : ?>
								<ul style="margin:10px 0 0;padding-left:18px;list-style:disc;max-width:74ch">
									<?php foreach ( EduAI_Agents::all() as $eduai_agent ) : ?>
										<li>
											<strong><?php echo esc_html( $eduai_agent['label'] ); ?></strong>
											<?php if ( $eduai_agent['description'] ) : ?>
												— <?php echo esc_html( $eduai_agent['description'] ); ?>
											<?php endif; ?>
											<br>
											<span class="description">
												<?php
												printf(
													/* translators: 1: model id 2: temperature */
													esc_html__( 'model: %1$s · temperature: %2$s', 'eduai' ),
													esc_html( $eduai_agent['model'] ? $eduai_agent['model'] : __( 'inherited', 'eduai' ) ),
													esc_html( null === $eduai_agent['temperature'] ? __( 'inherited', 'eduai' ) : (string) $eduai_agent['temperature'] )
												);
												?>
											</span>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php else : ?>
								<p class="description" style="color:#b32d2e">
									<?php esc_html_e( 'No agent files were found. The assistant falls back to its built-in prompt until one is restored.', 'eduai' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Features', 'eduai' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$toggles = array(
						'enable_floating' => array(
							__( 'Floating chat button on every page', 'eduai' ),
							__( 'Retired — Summarise, AiCalc, Q&A and PrepareME are their own tabs now, so the launcher offered a second route to features that already have one. Off by default; turn it on if you want a launcher as well.', 'eduai' ),
						),
						'agent_picker'    => array( __( 'Let students choose the agent', 'eduai' ), __( 'Shows a small selector above the message box. Turn off to pin everyone to the default agent.', 'eduai' ) ),
						'allow_general_knowledge' => array(
							__( 'Answer beyond the course material', 'eduai' ),
							__( 'On: an off-syllabus maths, science or general question is answered in full and labelled "Beyond the course material". Off: the assistant declines and points the student at their lecturer. Leave this on unless your institution requires otherwise.', 'eduai' ),
						),
						'enable_rag'      => array( __( 'Answer from indexed course material (RAG)', 'eduai' ), __( 'Turn off to run the assistant on general knowledge only.', 'eduai' ) ),
						'enable_voice'    => array( __( 'Voice input (speech to text)', 'eduai' ), __( 'Uses the browser Web Speech API — no extra API cost. Best support in Chrome and Edge.', 'eduai' ) ),
						'enable_tts'      => array( __( 'Spoken replies (text to speech)', 'eduai' ), __( 'Reads answers aloud using the browser speech synthesiser.', 'eduai' ) ),
						'logged_in_only'  => array( __( 'Restrict the assistant to signed-in users', 'eduai' ), __( 'Strongly recommended — it prevents anonymous visitors from spending your API credit.', 'eduai' ) ),
						'log_chats'       => array( __( 'Store conversations', 'eduai' ), __( 'Lets students see their history and lets you review what is being asked.', 'eduai' ) ),
					);

					foreach ( $toggles as $key => $meta ) :
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $meta[0] ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?>>
									<?php esc_html_e( 'Enabled', 'eduai' ); ?>
								</label>
								<?php if ( $meta[1] ) : ?>
									<p class="description"><?php echo esc_html( $meta[1] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="eduai_lang"><?php esc_html_e( 'Voice language', 'eduai' ); ?></label></th>
						<td>
							<input type="text" id="eduai_lang" class="small-text" name="<?php echo esc_attr( self::OPTION ); ?>[voice_lang]" value="<?php echo esc_attr( $s['voice_lang'] ); ?>">
							<p class="description"><?php esc_html_e( 'BCP-47 tag, e.g. en-US, en-GB, ar-EG.', 'eduai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Limits', 'eduai' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Messages per user per hour', 'eduai' ); ?>
								<input type="number" min="1" max="200" name="<?php echo esc_attr( self::OPTION ); ?>[rate_limit]" value="<?php echo esc_attr( (string) $s['rate_limit'] ); ?>">
							</label>
							&nbsp;&nbsp;
							<label><?php esc_html_e( 'Passages sent as context', 'eduai' ); ?>
								<input type="number" min="1" max="20" name="<?php echo esc_attr( self::OPTION ); ?>[context_chunks]" value="<?php echo esc_attr( (string) $s['context_chunks'] ); ?>">
							</label>
							&nbsp;&nbsp;
							<label><?php esc_html_e( 'Delete chat logs after (days, 0 = keep)', 'eduai' ); ?>
								<input type="number" min="0" max="3650" name="<?php echo esc_attr( self::OPTION ); ?>[retention_days]" value="<?php echo esc_attr( (string) $s['retention_days'] ); ?>">
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
