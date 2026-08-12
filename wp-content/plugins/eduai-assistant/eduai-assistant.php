<?php
/**
 * Plugin Name:       EduAI Assistant
 * Plugin URI:        https://example.com/eduai-assistant
 * Description:       A Claude-powered study assistant for WordPress: answers student questions grounded in your own course material, summarises lecture PDFs and PowerPoint decks, switches between selectable agents, and supports voice input and spoken replies.
 * Version:           1.1.2
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            YZH Solution
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       eduai
 * Domain Path:       /languages
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

// Bump on every chat.css change: assets are cache-keyed on ?ver=, and a fix
// the server has but the browser does not is indistinguishable from no fix.
// 1.1.1 alias-scope fix · 1.1.2 item-3 token migration.
define( 'EDUAI_VERSION', '1.1.6' );
define( 'EDUAI_FILE', __FILE__ );
define( 'EDUAI_DIR', plugin_dir_path( __FILE__ ) );
define( 'EDUAI_URL', plugin_dir_url( __FILE__ ) );
define( 'EDUAI_DB_VERSION', '1.1.0' );

require_once EDUAI_DIR . 'includes/class-eduai-agents.php';
require_once EDUAI_DIR . 'includes/class-eduai-settings.php';
require_once EDUAI_DIR . 'includes/class-eduai-claude.php';
require_once EDUAI_DIR . 'includes/class-eduai-pdf.php';
require_once EDUAI_DIR . 'includes/class-eduai-knowledge.php';
require_once EDUAI_DIR . 'includes/class-eduai-conversation.php';
require_once EDUAI_DIR . 'includes/class-eduai-calc.php';
require_once EDUAI_DIR . 'includes/class-eduai-scope.php';
require_once EDUAI_DIR . 'includes/class-eduai-exams.php';
require_once EDUAI_DIR . 'includes/class-eduai-rest.php';
require_once EDUAI_DIR . 'includes/class-eduai-shortcodes.php';
require_once EDUAI_DIR . 'includes/class-eduai-admin.php';
require_once EDUAI_DIR . 'includes/class-eduai-students.php';

/**
 * Plugin bootstrap.
 */
final class EduAI_Assistant {

	private static ?EduAI_Assistant $instance = null;

	public static function instance(): EduAI_Assistant {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'boot' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'template_redirect', array( $this, 'redirect_superseded_pages' ) );
		add_action( 'wp_footer', array( $this, 'render_launcher' ), 20 );

		/*
		 * The assistant panel on a Tutor lesson — the owner's sentence was
		 * "when I'm inside the course I selected, when I click summarise,
		 * summarise the lesson I'm opening directly."
		 *
		 * This hook and not wp_footer, and the reason is structural rather
		 * than tidiness. Tutor's learning area emits its OWN document, and on
		 * the no-access path it returns early leaving two complete documents
		 * on the response — so anything on wp_footer fires twice for a
		 * visitor who may not read the lesson. `tutor_single_content_lesson`
		 * fires after that early return, inside the content column, so the
		 * panel is gated by construction and cannot appear on the doubled
		 * document at all.
		 */
		add_action( 'tutor_single_content_lesson', array( $this, 'render_lesson_panel' ) );
		add_action( 'admin_notices', array( $this, 'setup_notice' ) );
	}

	public function boot(): void {
		EduAI_Settings::init();
		EduAI_Knowledge::init();
		EduAI_Conversation::init();
		EduAI_Exams::init();
		EduAI_REST::init();
		EduAI_Shortcodes::init();

		$this->retire_floating_widget();

		if ( is_admin() ) {
			EduAI_Admin::init();
			EduAI_Students::init();
		}

		$this->maybe_upgrade();
	}

	/**
	 * Ask and Summarise, scoped to the lesson being read.
	 *
	 * Re-gated through EduAI_Scope even though Tutor's early return already
	 * decided it. That is one predicate asked twice rather than two that
	 * agree today — and it costs a cached capability check, against the risk
	 * that this hook is ever reached from somewhere with a different idea of
	 * who may read a lesson.
	 *
	 * @param WP_Post|null $lesson Post Tutor passes to the hook.
	 */
	public function render_lesson_panel( $lesson = null ): void {
		$lesson = $lesson instanceof WP_Post ? $lesson : get_post();

		if ( ! $lesson instanceof WP_Post || 'lesson' !== $lesson->post_type ) {
			return;
		}

		$scope = EduAI_Scope::resolve( (int) $lesson->ID );

		if ( ! $scope ) {
			return;
		}

		$eduai_lesson_id    = $scope['id'];
		$eduai_lesson_title = $scope['title'];
		$eduai_ask_url      = eduai_ask_url( $scope['id'] );
		$eduai_summarise_url = eduai_summarise_url( $scope['id'] );

		$template = EDUAI_DIR . 'templates/lesson-panel.php';

		// The markup is front-end's file. If it is ever absent the two links
		// are still the entire point, so degrade to them rather than to a
		// fatal — same arrangement as the console screen.
		if ( is_readable( $template ) ) {
			include $template;
			return;
		}

		printf(
			'<p class="eduai-lesson-panel"><a href="%s">%s</a> &middot; <a href="%s">%s</a></p>',
			esc_url( $eduai_summarise_url ),
			esc_html(
				sprintf(
					/* translators: %s: lesson title */
					__( 'Summarise “%s”', 'eduai' ),
					$eduai_lesson_title
				)
			),
			esc_url( $eduai_ask_url ),
			esc_html(
				sprintf(
					/* translators: %s: lesson title */
					__( 'Ask about “%s”', 'eduai' ),
					$eduai_lesson_title
				)
			)
		);
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'eduai', false, dirname( plugin_basename( EDUAI_FILE ) ) . '/languages' );
	}

	/**
	 * Create/refresh tables when the DB version changes.
	 */
	private function maybe_upgrade(): void {
		if ( get_option( 'eduai_db_version' ) === EDUAI_DB_VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Activation hook — create the knowledge and conversation tables.
	 */
	public static function install(): void {
		EduAI_Knowledge::create_table();
		EduAI_Conversation::create_table();
		EduAI_Exams::create_tables();
		update_option( 'eduai_db_version', EDUAI_DB_VERSION );

		if ( ! wp_next_scheduled( 'eduai_reindex_event' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'eduai_reindex_event' );
		}
	}

	/**
	 * Deactivation hook.
	 */
	public static function uninstall_hooks(): void {
		$timestamp = wp_next_scheduled( 'eduai_reindex_event' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'eduai_reindex_event' );
		}
	}

	/**
	 * Front-end assets. Only loaded when the widget can actually be shown.
	 */
	public function assets(): void {
		/*
		 * The lesson panel has no shortcode to enqueue its stylesheet, so it
		 * has to be done here or the markup renders unstyled inside Tutor's
		 * document. Front-end measured chat.css × 0 on a lesson page — and
		 * the more useful half of that report is what it did to their
		 * measurements: the panel's frame read 150px at every viewport width,
		 * which is the UA default for an <object>, not the rule they wrote.
		 * A geometry pass over unstyled markup produces numbers that look
		 * like results.
		 *
		 * Gated on the same predicate that decides whether the panel renders
		 * at all, so a visitor who will not see it does not fetch a
		 * stylesheet for it. One predicate, asked in a third place, still
		 * beats a fourth opinion about who may read a lesson.
		 *
		 * Style only, no script: the panel is two links and a viewer.
		 */
		if ( is_singular( 'lesson' ) && EduAI_Scope::resolve( (int) get_queried_object_id() ) ) {
			wp_enqueue_style( 'eduai-chat', EDUAI_URL . 'assets/css/chat.css', array(), EDUAI_VERSION );
		}

		// Only for the floating launcher now. [eduai_panel] enqueues these
		// itself, because it used to rely on this running on every page — which
		// stopped being true the moment the widget was retired.
		if ( ! EduAI_Settings::launcher_visible() ) {
			return;
		}

		self::enqueue_chat_assets();
	}

	/**
	 * The chat bundle and its config.
	 *
	 * Public and idempotent so [eduai_panel] can ask for it directly rather
	 * than depending on the launcher having already loaded it.
	 */
	public static function enqueue_chat_assets(): void {
		static $done = false;

		if ( $done ) {
			return;
		}
		$done = true;

		wp_enqueue_style( 'eduai-chat', EDUAI_URL . 'assets/css/chat.css', array(), EDUAI_VERSION );
		wp_enqueue_script( 'eduai-chat', EDUAI_URL . 'assets/js/chat.js', array(), EDUAI_VERSION, true );

		wp_localize_script( 'eduai-chat', 'EduAIConfig', array(
			'root'         => esc_url_raw( rest_url( 'eduai/v1' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'loggedIn'     => is_user_logged_in(),
			'loginUrl'     => wp_login_url( get_permalink() ?: home_url( '/' ) ),
			'voiceEnabled' => EduAI_Settings::get( 'enable_voice', true ),
			'ttsEnabled'   => EduAI_Settings::get( 'enable_tts', true ),
			'ttsLang'      => EduAI_Settings::get( 'voice_lang', 'en-US' ),
			'greeting'     => EduAI_Settings::get( 'greeting', __( 'Hi! Ask me anything about your course material, or paste a lecture and I will summarise it.', 'eduai' ) ),
			'assistantName'=> EduAI_Settings::get( 'assistant_name', __( 'Study Assistant', 'eduai' ) ),
			'suggestions'  => EduAI_Settings::suggestions(),
			'agents'       => EduAI_Agents::for_script(),
			'defaultAgent' => EduAI_Agents::default_id(),
			'agentPicker'  => (bool) EduAI_Settings::get( 'agent_picker', true ),
			'maxUploadMb'  => 20,
			// null, or { id, title } — resolved and gated server-side. A
			// refused scope arrives identical to no scope, so the browser has
			// nothing to distinguish and nothing to leak.
			'scope'        => EduAI_Scope::for_script(),
			'i18n'         => array(
				'placeholder'   => __( 'Ask about your material…', 'eduai' ),
				'send'          => __( 'Send', 'eduai' ),
				'thinking'      => __( 'Thinking…', 'eduai' ),
				'listening'     => __( 'Listening…', 'eduai' ),
				'stopListening' => __( 'Stop listening', 'eduai' ),
				'speak'         => __( 'Read answer aloud', 'eduai' ),
				'stopSpeaking'  => __( 'Stop reading', 'eduai' ),
				'error'         => __( 'Something went wrong. Please try again.', 'eduai' ),
				'loginPrompt'   => __( 'Please sign in to use the assistant.', 'eduai' ),
				'sources'       => __( 'Sources', 'eduai' ),
				'newChat'       => __( 'New chat', 'eduai' ),
				'summarise'     => __( 'Summarise a lecture', 'eduai' ),
				'chatTab'       => __( 'Ask', 'eduai' ),
				'summaryTab'    => __( 'Summarise', 'eduai' ),
				'dropFile'      => __( 'Drop a PDF, PowerPoint, Word or text file here, or click to choose', 'eduai' ),
				'agentLabel'    => __( 'Agent', 'eduai' ),
				'chooseFile'    => __( 'Choose file', 'eduai' ),
				'summariseBtn'  => __( 'Summarise', 'eduai' ),
				'noVoice'       => __( 'Your browser does not support speech recognition. Chrome or Edge is recommended.', 'eduai' ),
				'rateLimited'   => __( 'You are sending messages too quickly. Give it a few seconds.', 'eduai' ),
				'copy'          => __( 'Copy', 'eduai' ),
				'copied'        => __( 'Copied', 'eduai' ),
			),
		) );
	}

	/**
	 * Send superseded routes to the tab that replaced them.
	 *
	 * /assistant/ and /ask/ render the same shortcode, and only /ask/ is in the
	 * nav — but /assistant/ predates the restructure, so links and bookmarks
	 * point at it. Deleting the page would break those silently; a 301 keeps
	 * them working and tells search engines which one is real.
	 *
	 * Only redirects when the destination actually exists, so a site that never
	 * created /ask/ keeps serving /assistant/ rather than looping or 404ing.
	 */
	public function redirect_superseded_pages(): void {
		if ( is_admin() || ! is_page() ) {
			return;
		}

		/**
		 * Filter the superseded-route map: old slug => new slug.
		 *
		 * @param array $map Slug pairs.
		 */
		$map = (array) apply_filters( 'eduai_superseded_pages', array( 'assistant' => 'ask' ) );

		$slug = get_post_field( 'post_name', get_queried_object_id() );

		if ( ! isset( $map[ $slug ] ) ) {
			return;
		}

		$target = get_page_by_path( $map[ $slug ] );

		if ( ! $target || 'publish' !== $target->post_status ) {
			return;
		}

		wp_safe_redirect( get_permalink( $target ), 301 );
		exit;
	}

	/**
	 * Floating launcher + panel markup, printed once in the footer.
	 */
	public function render_launcher(): void {
		if ( ! EduAI_Settings::launcher_visible() ) {
			return;
		}
		include EDUAI_DIR . 'templates/widget.php';
	}

	/**
	 * Turn the floating widget off on a site that already had it on.
	 *
	 * Flipping the default only helps a fresh install: an existing site has
	 * `enable_floating` stored as true in its options and would keep showing a
	 * launcher that now duplicates four top-level tabs — precisely the shape
	 * the restructure removed.
	 *
	 * Migrating rather than leaving it is a deliberate choice. On every install
	 * that exists today the stored `true` came from the default at save time,
	 * not from an administrator deciding they wanted a launcher. Runs once, and
	 * the setting stays in the UI, so anyone who genuinely wants it back is one
	 * checkbox away.
	 */
	private function retire_floating_widget(): void {
		if ( get_option( 'eduai_widget_retired' ) ) {
			return;
		}

		update_option( 'eduai_widget_retired', 1, false );

		$stored = get_option( EduAI_Settings::OPTION );

		if ( is_array( $stored ) && ! empty( $stored['enable_floating'] ) ) {
			$stored['enable_floating'] = false;
			update_option( EduAI_Settings::OPTION, $stored );
		}
	}

	/**
	 * Tell the admin the server has not supplied a key.
	 *
	 * Nobody is asked to type one — this points at where the key is meant to
	 * come from instead.
	 */
	public function setup_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || EduAI_Settings::api_key() ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen && 'settings_page_eduai' === $screen->id ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'EduAI Assistant:', 'eduai' ),
			esc_html__( 'no model key reached the server, so the assistant is off. Set GROQ_API_KEY (or ZAI_API_KEY / ANTHROPIC_API_KEY) in the environment, or define the matching EDUAI_* constant in wp-config.php.', 'eduai' ),
			esc_url( admin_url( 'options-general.php?page=eduai' ) ),
			esc_html__( 'Open settings', 'eduai' )
		);
	}
}

EduAI_Assistant::instance();

register_activation_hook( __FILE__, array( 'EduAI_Assistant', 'install' ) );
register_deactivation_hook( __FILE__, array( 'EduAI_Assistant', 'uninstall_hooks' ) );

/**
 * Where the Q&A page lives. This plugin owns /ask/ (setup.sh creates it with
 * [eduai_panel]), so the resolver lives here — not in the theme, not in the
 * library plugin. Consumers guard with function_exists() and OMIT their
 * control when this plugin is absent: a missing button is honest, a button
 * that navigates somewhere unrelated is not.
 */
/**
 * Attach a scope to a tool URL — the ONE place that knows the parameter is
 * called `source`.
 *
 * It was spelled out by hand at the only call site that had it, and absent
 * everywhere else. A parameter name written at each caller is a contract
 * enforced by whoever remembers it: the resolver, the gate, the banner and
 * the reader were all built and verified in isolation, and the string joining
 * them existed in exactly one template. Nothing failed, because a link
 * without it is a perfectly good unscoped link.
 *
 * Not gated here, deliberately. This says which material a link is ABOUT;
 * EduAI_Scope::resolve() decides whether the visitor may have it, on arrival
 * and again at the endpoint. Gating the link too would be a third opinion
 * that has to agree with the other two — and a link that silently drops its
 * scope teaches the reader nothing, where a page that ignores an unauthorised
 * one behaves identically to no parameter by design.
 *
 * @param string $url    Destination.
 * @param int    $source Post the tool should open against, 0 for none.
 */
function eduai_with_source( string $url, int $source ): string {
	return $source > 0 ? add_query_arg( 'source', $source, $url ) : $url;
}

function eduai_ask_url( int $source = 0 ): string {
	$page = get_page_by_path( 'ask' );

	$url = ( $page && 'publish' === $page->post_status )
		? (string) get_permalink( $page )
		: home_url( '/' );

	$url = eduai_with_source( $url, $source );

	/**
	 * Filter the Q&A page destination.
	 *
	 * @param string $url Resolved URL.
	 */
	return (string) apply_filters( 'eduai_ask_url', $url );
}

/**
 * Where the Summarise page lives. Same contract as eduai_ask_url().
 */
function eduai_summarise_url( int $source = 0 ): string {
	$page = get_page_by_path( 'summarise' );

	$url = ( $page && 'publish' === $page->post_status )
		? (string) get_permalink( $page )
		: home_url( '/' );

	$url = eduai_with_source( $url, $source );

	/**
	 * Filter the Summarise page destination.
	 *
	 * @param string $url Resolved URL.
	 */
	return (string) apply_filters( 'eduai_summarise_url', $url );
}

/**
 * Where PrepareME lives. Same contract as eduai_ask_url().
 *
 * @param int $exam_id Sit this stored exam again instead of generating a new
 *                     one. The id is a hint to the page, never an authorisation:
 *                     GET /exam/{id} still checks ownership, so a guessed id in
 *                     the query string yields a 403, not someone else's paper.
 */
function eduai_prepare_url( int $exam_id = 0 ): string {
	$page = get_page_by_path( 'prepare' );

	$url = ( $page && 'publish' === $page->post_status )
		? (string) get_permalink( $page )
		: home_url( '/' );

	if ( $exam_id > 0 ) {
		$url = add_query_arg( 'exam', $exam_id, $url );
	}

	/**
	 * Filter the PrepareME page destination.
	 *
	 * @param string $url     Resolved URL.
	 * @param int    $exam_id Exam to retake, or 0.
	 */
	return (string) apply_filters( 'eduai_prepare_url', $url, $exam_id );
}
