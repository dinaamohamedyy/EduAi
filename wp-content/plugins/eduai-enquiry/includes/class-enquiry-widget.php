<?php
/**
 * Putting the enquiry assistant on the page.
 *
 * The interface is the front-end developer's — `assets/css/enquiry.css` and
 * `assets/js/enquiry.js`, committed in a577832. This class does not draw
 * anything. It enqueues those, and hands the browser the one configuration
 * object their script reads.
 *
 * WHY THIS CLASS IS SO SHORT
 *
 * I had written a second widget before I found theirs. Theirs is better in a
 * way that matters and that I had got wrong: direction is applied PER MESSAGE,
 * so an Arabic reply above an English one keeps the direction it was written
 * in. Mine flipped the whole transcript on a language switch, which silently
 * misreports which language each turn actually happened in.
 *
 * Two widgets doing one job is the defect this codebase has spent the week
 * removing. Mine is deleted.
 *
 * @package EduAI_Enquiry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end mounting.
 */
class EduAI_Enquiry_Widget {

	/**
	 * Hook up rendering.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_shortcode( 'eduai_enquiry', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Should the widget appear on this request?
	 */
	private static function enabled(): bool {
		$s = get_option( 'eduai_enquiry_settings', array() );

		if ( empty( $s['enabled'] ) || is_admin() || is_feed() || is_embed() ) {
			return false;
		}

		/**
		 * Suppress the widget on particular views.
		 *
		 * @param bool $show Whether to render.
		 */
		return (bool) apply_filters( 'eduai_enquiry_show', true );
	}

	/**
	 * Styles, script, and the configuration their script expects.
	 */
	public static function assets(): void {
		if ( ! self::enabled() ) {
			return;
		}

		wp_enqueue_style(
			'eduai-enquiry',
			EDUAI_ENQUIRY_URL . 'assets/css/enquiry.css',
			array(),
			EDUAI_ENQUIRY_VERSION
		);

		wp_enqueue_script(
			'eduai-enquiry',
			EDUAI_ENQUIRY_URL . 'assets/js/enquiry.js',
			array(),
			EDUAI_ENQUIRY_VERSION,
			true
		);

		$settings = get_option( 'eduai_enquiry_settings', array() );
		$language = in_array( $settings['language'] ?? 'en', array( 'en', 'ar' ), true ) ? $settings['language'] : 'en';

		// A site already running in Arabic should open in Arabic.
		if ( 'en' === $language && 0 === strpos( (string) get_locale(), 'ar' ) ) {
			$language = 'ar';
		}

		/*
		 * The session token is minted on the first reply rather than here.
		 * Issuing one on every page view would create a row for every visitor
		 * who never opens the widget, which on a busy site is most of them.
		 */
		wp_localize_script(
			'eduai-enquiry',
			'EQConfig',
			array(
				'root'    => esc_url_raw( rest_url( 'eduai-enquiry/v1/' ) ),
				'chatUrl' => esc_url_raw( rest_url( 'eduai-enquiry/v1/chat' ) ),
				'leadUrl' => esc_url_raw( rest_url( 'eduai-enquiry/v1/lead' ) ),
				'lang'    => $language,
				'session' => null,

				/*
				 * A nonce is sent when there is a logged-in user to mint one
				 * for, and the endpoints do not require it. Visitors are logged
				 * out and their pages are cached, so a required nonce is one
				 * that expired before anybody read it — the classic failure
				 * where a form works for administrators and silently does not
				 * for the public.
				 */
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'stub'    => false,
			)
		);
	}

	/**
	 * `[eduai_enquiry]` — for a contact page.
	 *
	 * The script mounts itself to the body, so this only guarantees the assets
	 * are present on a page that might not otherwise load them.
	 */
	public static function shortcode( $atts ): string {
		self::assets();

		return '';
	}
}
