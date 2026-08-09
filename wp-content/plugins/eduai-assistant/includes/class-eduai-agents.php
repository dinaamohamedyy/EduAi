<?php
/**
 * Agent registry.
 *
 * An agent is a Markdown file in /agents with front matter — the same shape the
 * claude-code-templates catalogue ships, so a file pulled down with
 * `npx claude-code-templates@latest --agent <path>` can be dropped straight in.
 * Adding an agent means adding a file; nothing else has to change.
 *
 * Every agent's prompt is followed by a shared block of house rules, which is
 * what guarantees the assistant answers a maths or science question even when
 * the indexed material says nothing about it.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads, caches and composes the agent prompts.
 */
class EduAI_Agents {

	/**
	 * Agent used when the request names none.
	 *
	 * Also the agent that the Settings → System prompt box overrides, so
	 * changing this changes which agent an administrator edits from wp-admin.
	 */
	public const DEFAULT_ID = 'knowledge-synthesizer';

	/**
	 * Parsed agents, memoised for the request.
	 *
	 * @var array<string,array>|null
	 */
	private static ?array $cache = null;

	/**
	 * Directory holding the agent definitions.
	 */
	public static function dir(): string {
		/**
		 * Filter where agent Markdown files are read from.
		 *
		 * @param string $dir Absolute path, trailing slash included.
		 */
		return (string) apply_filters( 'eduai_agents_dir', EDUAI_DIR . 'agents/' );
	}

	/**
	 * Every agent, keyed by id, ordered by the `order` field then label.
	 *
	 * @return array<string,array{id:string,label:string,description:string,temperature:?float,order:int,prompt:string}>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$agents = array();
		$files  = glob( self::dir() . '*.md' );

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			// A leading underscore marks a shared fragment, not an agent.
			if ( 0 === strpos( basename( (string) $file ), '_' ) ) {
				continue;
			}

			$agent = self::parse( (string) $file );
			if ( $agent ) {
				$agents[ $agent['id'] ] = $agent;
			}
		}

		uasort(
			$agents,
			static fn( $a, $b ) => ( $a['order'] <=> $b['order'] ) ?: strcmp( $a['label'], $b['label'] )
		);

		/**
		 * Filter the agent registry — register one in code without shipping a file.
		 *
		 * @param array $agents Agents keyed by id.
		 */
		self::$cache = (array) apply_filters( 'eduai_agents', $agents );

		return self::$cache;
	}

	/**
	 * One agent, or null when the id is unknown.
	 *
	 * @param string $id Agent id.
	 */
	public static function get( string $id ): ?array {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/**
	 * Is this a usable agent id?
	 *
	 * @param string $id Agent id.
	 */
	public static function exists( string $id ): bool {
		return '' !== $id && null !== self::get( $id );
	}

	/**
	 * The configured default, falling back to the tutor and then to whatever
	 * happens to be first — so a site that deletes every file still works.
	 */
	public static function default_id(): string {
		$configured = sanitize_key( (string) EduAI_Settings::get( 'default_agent', self::DEFAULT_ID ) );

		if ( self::exists( $configured ) ) {
			return $configured;
		}

		if ( self::exists( self::DEFAULT_ID ) ) {
			return self::DEFAULT_ID;
		}

		$all = self::all();
		return $all ? (string) array_key_first( $all ) : self::DEFAULT_ID;
	}

	/**
	 * Resolve a requested id to one that exists.
	 *
	 * @param string $id Requested agent id, possibly empty or bogus.
	 */
	public static function resolve( string $id ): string {
		$id = sanitize_key( $id );
		return self::exists( $id ) ? $id : self::default_id();
	}

	/**
	 * The shape the front end needs: id, label and description only.
	 */
	public static function for_script(): array {
		$out = array();

		foreach ( self::all() as $agent ) {
			$out[] = array(
				'id'          => $agent['id'],
				'label'       => $agent['label'],
				'description' => $agent['description'],
			);
		}

		return $out;
	}

	/**
	 * Full system prompt for an agent: its own instructions plus the house rules.
	 *
	 * @param string $id Agent id.
	 */
	public static function system_prompt( string $id ): string {
		$agent  = self::get( $id );
		$prompt = $agent ? $agent['prompt'] : '';

		// The default agent stays editable from Settings → EduAI Assistant.
		if ( ! $agent || self::DEFAULT_ID === $agent['id'] ) {
			$custom = trim( (string) EduAI_Settings::get( 'system_prompt', '' ) );
			if ( '' !== $custom ) {
				$prompt = $custom;
			}
		}

		if ( '' === trim( $prompt ) ) {
			$prompt = EduAI_Settings::default_system_prompt();
		}

		return trim( $prompt ) . "\n\n" . self::house_rules();
	}

	/**
	 * Temperature this agent prefers, or null to use the site setting.
	 *
	 * @param string $id Agent id.
	 */
	public static function temperature( string $id ): ?float {
		$agent = self::get( $id );
		return $agent['temperature'] ?? null;
	}

	/**
	 * Model this agent asks for, or null to use the site setting.
	 *
	 * @param string $id Agent id.
	 */
	public static function model( string $id ): ?string {
		$agent = self::get( $id );
		return $agent['model'] ?? null;
	}

	/**
	 * Resolve a `model:` front-matter value to a capability tier.
	 *
	 * Catalogue files write a vendor family name — `model: opus` — which is read
	 * here as "the strongest model this site's provider offers" rather than as a
	 * literal Anthropic id. That is what lets one set of agent files run against
	 * Anthropic or Groq without editing any of them.
	 *
	 * A tier may also be named directly (`strongest`), and anything else is
	 * passed through untouched so an agent can pin an exact model id when that
	 * is genuinely wanted. EduAI_Claude::resolve_model() does the final mapping.
	 *
	 * @param string $value Raw front-matter value.
	 */
	private static function resolve_model( string $value ): ?string {
		$value = strtolower( trim( $value ) );

		if ( '' === $value || 'inherit' === $value || 'default' === $value ) {
			return null;
		}

		$tiers = array(
			'opus'   => 'strongest',
			'sonnet' => 'balanced',
			'haiku'  => 'fast',
		);

		return $tiers[ $value ] ?? $value;
	}

	/**
	 * Rules appended to every agent.
	 *
	 * This block is the reason a student can ask for the derivative of x^3 in a
	 * history course and still get an answer: no agent prompt, and no system
	 * prompt an administrator types into the settings box, can narrow the scope
	 * below what is written here.
	 */
	public static function house_rules(): string {
		if ( ! EduAI_Settings::get( 'allow_general_knowledge', true ) ) {
			$rules = "HOUSE RULES (these override anything above that conflicts with them)\n\n"
				. "- Answer only from the COURSE MATERIAL block below.\n"
				. "- If the material does not cover the question, say so plainly and suggest the student ask their lecturer. Do not answer from general knowledge — this site has that switched off.\n"
				. "- Never invent a citation, page number, formula, constant or statistic.";

			/** This filter documented below. */
			return (string) apply_filters( 'eduai_house_rules', $rules );
		}

		$rules = self::fragment( '_house-rules' );

		// Only reached if the file has been deleted. Keep the guarantee that
		// matters rather than a copy of the whole block.
		if ( '' === $rules ) {
			$rules = "HOUSE RULES (these override anything above that conflicts with them)\n\n"
				. "- Always give the student a usable answer. \"That is not in the course material\" is never a complete reply.\n"
				. "- Answer from the COURSE MATERIAL below where it applies, naming the document; otherwise say so in one line and answer under the heading \"Beyond the course material\".\n"
				. "- Maths and science questions are always in scope, on the syllabus or off it.\n"
				. "- Show every step of a calculation, and never invent a citation, formula or constant.";
		}

		/**
		 * Filter the shared house rules appended to every agent prompt.
		 *
		 * @param string $rules Rule block.
		 */
		return (string) apply_filters( 'eduai_house_rules', $rules );
	}

	/**
	 * One named section of the house rules, heading plus its bullet lines.
	 *
	 * For surfaces where the full rules do not fit: Scope and Calculations
	 * are written for answering a student's question, and appended to a
	 * summariser prompt they would tell a summary to behave like an answer.
	 * The formatting rules still bind every surface, so those sections are
	 * pulled from the same _house-rules.md — the rules keep one home.
	 *
	 * @param string $heading Section heading exactly as in the file, e.g. 'Notation'.
	 * @return string '' when the file lacks the section.
	 */
	public static function house_rules_section( string $heading ): string {
		$rules = self::fragment( '_house-rules' );

		if ( '' !== $rules && preg_match( '/^' . preg_quote( $heading, '/' ) . '\n(?:^-.*(?:\n|\z))+/m', $rules, $match ) ) {
			return trim( $match[0] );
		}

		// The file is gone or the heading was renamed. Notation is the one
		// section rendering depends on (to_html() has no LaTeX handling),
		// so keep that guarantee alive; anything else can be absent.
		if ( 'Notation' === $heading ) {
			return "Notation\n"
				. "- Write maths in plain text the browser can render: x^2, sqrt(x), (a+b)/c, 3.0 x 10^8 m/s.\n"
				. "- Use x or · for multiplication, never a bare asterisk — asterisks render as italics here.\n"
				. "- Put a multi-line derivation in a fenced code block so the alignment survives.";
		}

		return '';
	}

	/**
	 * Body of a shared underscore-prefixed file in the agents directory.
	 *
	 * @param string $basename File name without the .md extension.
	 * @return string Empty when the file is missing or unreadable.
	 */
	private static function fragment( string $basename ): string {
		$path = self::dir() . $basename . '.md';

		if ( ! is_readable( $path ) ) {
			return '';
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! is_string( $raw ) ) {
			return '';
		}

		$raw = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		$raw = (string) preg_replace( '/\A---\n.*?\n---\n?/s', '', $raw );
		$raw = (string) preg_replace( '/<!--.*?-->/s', '', $raw );

		return trim( $raw );
	}

	/**
	 * Read one agent file.
	 *
	 * Front matter is a deliberately small subset of YAML — `key: value` lines
	 * between `---` fences — because that is all the catalogue files use and it
	 * keeps the plugin dependency-free.
	 *
	 * @param string $file Absolute path.
	 * @return array|null Null when the file is unreadable or has no body.
	 */
	private static function parse( string $file ): ?array {
		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}

		$raw  = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		$meta = array();
		$body = $raw;

		if ( preg_match( '/\A---\n(.*?)\n---\n?(.*)\z/s', $raw, $m ) ) {
			$body = $m[2];

			foreach ( explode( "\n", $m[1] ) as $line ) {
				if ( ! preg_match( '/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/', trim( $line ), $kv ) ) {
					continue;
				}
				$meta[ strtolower( $kv[1] ) ] = trim( $kv[2], " \t\"'" );
			}
		}

		// Comments carry notes for whoever edits the file, not instructions for
		// the model, so they never reach the API.
		$body = trim( (string) preg_replace( '/<!--.*?-->/s', '', $body ) );

		if ( '' === $body ) {
			return null;
		}

		$id = sanitize_key( $meta['name'] ?? basename( $file, '.md' ) );
		if ( '' === $id ) {
			return null;
		}

		$temperature = null;
		if ( isset( $meta['temperature'] ) && is_numeric( $meta['temperature'] ) ) {
			$temperature = max( 0.0, min( 1.0, (float) $meta['temperature'] ) );
		}

		return array(
			'id'          => $id,
			'label'       => $meta['label'] ?? self::humanise( $id ),
			'description' => $meta['description'] ?? '',
			'temperature' => $temperature,
			'model'       => isset( $meta['model'] ) ? self::resolve_model( (string) $meta['model'] ) : null,
			'order'       => isset( $meta['order'] ) ? (int) $meta['order'] : 50,
			'prompt'      => $body,
		);
	}

	/**
	 * "critical-thinking" -> "Critical thinking", for files with no label.
	 *
	 * @param string $id Agent id.
	 */
	private static function humanise( string $id ): string {
		return ucfirst( trim( str_replace( array( '-', '_' ), ' ', $id ) ) );
	}
}
