<?php
/**
 * Shortcodes: inline assistant panel and standalone summariser.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * [eduai_panel]      Inline chat panel (used in the homepage hero).
 * [eduai_summarizer] Standalone lecture summariser.
 */
class EduAI_Shortcodes {

	public static function init(): void {
		add_shortcode( 'eduai_panel', array( __CLASS__, 'panel' ) );
		add_shortcode( 'eduai_summarizer', array( __CLASS__, 'summarizer' ) );
		add_shortcode( 'eduai_calc', array( __CLASS__, 'calc' ) );
	}

	/**
	 * AiCalc.
	 *
	 * Its assets are enqueued here rather than unconditionally on every page:
	 * the calculator is one destination, and the chat bundle it would otherwise
	 * ride along with is a different feature entirely.
	 *
	 * @param array $atts Shortcode attributes.
	 */
	public static function calc( $atts = array() ): string {
		if ( EduAI_Settings::get( 'logged_in_only', true ) && ! is_user_logged_in() ) {
			return self::login_card();
		}

		wp_enqueue_style( 'eduai-chat', EDUAI_URL . 'assets/css/chat.css', array(), EDUAI_VERSION );
		wp_enqueue_script( 'eduai-calc', EDUAI_URL . 'assets/js/calc.js', array(), EDUAI_VERSION, true );

		wp_localize_script( 'eduai-calc', 'EduAICalcConfig', array(
			'root'     => esc_url_raw( rest_url( EduAI_REST::NS ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'loggedIn' => is_user_logged_in(),
			'i18n'     => array(
				// Wording is taken verbatim from the AiCalc screen in
				// design/preview.html. Two surfaces describing the same split in
				// different words is how a student ends up unsure which kind of
				// answer they are looking at, which is the one thing this
				// feature exists to make obvious.
				'exact'       => __( 'Computed exactly — code, not a model', 'eduai' ),
				'viaModel'    => __( 'Model answer — temperature 0, house rules on', 'eduai' ),
				'exactNote'   => __( 'Worked out on the server, not by a language model, and no tokens spent.', 'eduai' ),
				'modelNote'   => __( 'This one needed the assistant rather than plain arithmetic, so check it against your notes.', 'eduai' ),
				'error'       => __( 'Something went wrong. Please try again.', 'eduai' ),
				'loginPrompt' => __( 'Please sign in to use the calculator.', 'eduai' ),
			),
		) );

		ob_start();
		include EDUAI_DIR . 'templates/calc.php';
		return (string) ob_get_clean();
	}

	/**
	 * Inline chat panel.
	 *
	 * @param array $atts Shortcode attributes.
	 */
	public static function panel( $atts = array() ): string {
		$atts = shortcode_atts( array(
			'height' => '520',
			'title'  => EduAI_Settings::get( 'assistant_name', __( 'Study Assistant', 'eduai' ) ),
			// 'chat' drops the Summarise tab, for pages where Summarise is its
			// own destination. Defaults to both so the widget and any existing
			// embed are unaffected.
			'tabs'   => 'all',
		), (array) $atts, 'eduai_panel' );

		if ( EduAI_Settings::get( 'logged_in_only', true ) && ! is_user_logged_in() ) {
			return self::login_card();
		}

		ob_start();
		$eduai_inline = true;
		$eduai_atts   = $atts;
		include EDUAI_DIR . 'templates/panel.php';
		return (string) ob_get_clean();
	}

	/**
	 * Standalone summariser block.
	 *
	 * @param array $atts Shortcode attributes.
	 */
	public static function summarizer( $atts = array() ): string {
		if ( EduAI_Settings::get( 'logged_in_only', true ) && ! is_user_logged_in() ) {
			return self::login_card();
		}

		wp_enqueue_style( 'eduai-chat', EDUAI_URL . 'assets/css/chat.css', array(), EDUAI_VERSION );
		wp_enqueue_script( 'eduai-summarise', EDUAI_URL . 'assets/js/summarise.js', array(), EDUAI_VERSION, true );

		wp_localize_script( 'eduai-summarise', 'EduAISumConfig', array(
			'root'        => esc_url_raw( rest_url( EduAI_REST::NS ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'loggedIn'    => is_user_logged_in(),
			'maxUploadMb' => 20,
			'i18n'        => array(
				'dropFile'         => __( 'Drop a lecture here, or click to choose', 'eduai' ),
				'reading'          => __( 'Reading…', 'eduai' ),
				'summarising'      => __( 'Summarising…', 'eduai' ),
				// Naming the stage matters: extraction of a long deck happens
				// before the model is called at all, and silence there reads
				// as a hang.
				'readingNote'      => __( 'Reading the document on the server — slides and speaker notes first.', 'eduai' ),
				'summarisingNote'  => __( 'Writing the notes. A long lecture takes a little while.', 'eduai' ),
				'needSource'       => __( 'Attach a file, or paste at least a paragraph of the lecture.', 'eduai' ),
				'tooBig'           => __( 'That file is larger than %d MB. Split it, or paste the part you need.', 'eduai' ),
				'error'            => __( 'Something went wrong. Please try again.', 'eduai' ),
				'loginPrompt'      => __( 'Please sign in to use the summariser.', 'eduai' ),
				'pastedText'       => __( 'Pasted lecture text', 'eduai' ),
				'copy'             => __( 'Copy', 'eduai' ),
				'copied'           => __( 'Copied', 'eduai' ),
				'styles'           => array(
					'detailed' => __( 'Full study notes', 'eduai' ),
					'brief'    => __( 'Quick summary', 'eduai' ),
					'exam'     => __( 'Exam preparation', 'eduai' ),
					'critical' => __( 'Critical review', 'eduai' ),
				),
			),
		) );

		ob_start();
		include EDUAI_DIR . 'templates/summarizer.php';
		return (string) ob_get_clean();
	}

	/**
	 * Prompt anonymous visitors to sign in.
	 */
	private static function login_card(): string {
		return sprintf(
			'<div class="eduai-card eduai-login"><h3>%s</h3><p>%s</p><a class="eduai-btn eduai-btn--primary" href="%s">%s</a></div>',
			esc_html__( 'Sign in to use the assistant', 'eduai' ),
			esc_html__( 'The study assistant is available to registered students.', 'eduai' ),
			esc_url( wp_login_url( get_permalink() ?: home_url( '/' ) ) ),
			esc_html__( 'Sign in', 'eduai' )
		);
	}
}
