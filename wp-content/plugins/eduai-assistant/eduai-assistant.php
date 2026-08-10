<?php
/**
 * Plugin Name:       EduAI Assistant
 * Plugin URI:        https://example.com/eduai-assistant
 * Description:       A Claude-powered study assistant for WordPress: answers student questions grounded in your own course material, summarises lecture PDFs and PowerPoint decks, switches between selectable agents, and supports voice input and spoken replies.
 * Version:           1.1.0
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

define( 'EDUAI_VERSION', '1.1.0' );
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
require_once EDUAI_DIR . 'includes/class-eduai-exams.php';
require_once EDUAI_DIR . 'includes/class-eduai-rest.php';
require_once EDUAI_DIR . 'includes/class-eduai-shortcodes.php';
require_once EDUAI_DIR . 'includes/class-eduai-admin.php';

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
		}

		$this->maybe_upgrade();
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
