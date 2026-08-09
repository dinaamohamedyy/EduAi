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
