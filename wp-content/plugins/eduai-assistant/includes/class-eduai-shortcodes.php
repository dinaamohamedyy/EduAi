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
		add_shortcode( 'eduai_prepare', array( __CLASS__, 'prepare' ) );
		add_shortcode( 'eduai_progress', array( __CLASS__, 'progress' ) );
	}

	/**
	 * PrepareME history: every paper this student has sat, with a retake link.
	 *
	 * Read-only, so it enqueues no script — the retake button is a link to
	 * PrepareME carrying the exam id, not a fetch. A page that only lists past
	 * results has no reason to ship the exam runtime.
	 *
	 * @param array $atts Shortcode attributes.
	 */
	public static function progress( $atts = array() ): string {
		$atts = shortcode_atts( array( 'limit' => 20 ), (array) $atts, 'eduai_progress' );

		if ( ! is_user_logged_in() ) {
			return self::login_card(
				__( 'Sign in to see your results', 'eduai' ),
				__( 'Every practice paper you sit is kept here, with its mark and the option to sit it again.', 'eduai' )
			);
		}

		wp_enqueue_style( 'eduai-chat', EDUAI_URL . 'assets/css/chat.css', array(), EDUAI_VERSION );

		$eduai_user_id = get_current_user_id();
		$eduai_stats   = EduAI_Exams::stats_for_user( $eduai_user_id );
		$eduai_history = EduAI_Exams::history_for_user( $eduai_user_id, (int) $atts['limit'] );

		ob_start();
		include EDUAI_DIR . 'templates/progress.php';
		return (string) ob_get_clean();
	}

	/**
	 * PrepareME.
	 *
	 * @param array $atts Shortcode attributes.
	 */
	public static function prepare( $atts = array() ): string {
		if ( EduAI_Settings::get( 'logged_in_only', true ) && ! is_user_logged_in() ) {
			return self::login_card(
				__( 'Sign in to use PrepareME', 'eduai' ),
				__( 'Upload a lecture, sit an exam generated from it, and get it marked with corrections.', 'eduai' )
			);
		}

		wp_enqueue_style( 'eduai-chat', EDUAI_URL . 'assets/css/chat.css', array(), EDUAI_VERSION );
		wp_enqueue_script( 'eduai-prepare', EDUAI_URL . 'assets/js/prepare.js', array(), EDUAI_VERSION, true );

		wp_localize_script( 'eduai-prepare', 'EduAIPrepConfig', array(
			'root'     => esc_url_raw( rest_url( EduAI_REST::NS ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'loggedIn' => is_user_logged_in(),
			// See the note on the same key in enqueue_chat_assets(): null, or
			// a server-resolved { id, title }.
			'scope'    => EduAI_Scope::for_script(),
			'i18n'     => array(
				'dropFile'        => __( 'Drop a lecture here, or click to choose', 'eduai' ),
				'needSource'      => __( 'Attach a lecture, or paste at least a paragraph of it.', 'eduai' ),
				/* translators: %d: number of questions */
				'generating'      => __( 'Reading the lecture and writing %d questions. This is the slowest step.', 'eduai' ),
				'marking'         => __( 'Marking. Multiple choice is scored here; short answers go to the marker.', 'eduai' ),
				'loadingPaper'    => __( 'Fetching that paper again. Same questions, answers cleared.', 'eduai' ),
				/* translators: 1: question count 2: source name */
				'paperMeta'       => __( '%1$d questions from %2$s. Nothing is marked until you submit.', 'eduai' ),
				'pastedText'      => __( 'your pasted text', 'eduai' ),
				/* translators: %d: marks available */
				'marks'           => __( '%d marks', 'eduai' ),
				/* translators: 1: answered 2: total */
				'answered'        => __( '%1$d of %2$d answered', 'eduai' ),
				/* translators: %d: number left blank */
				'someBlank'       => __( '%d still blank — they will score zero.', 'eduai' ),
				'mcq'             => __( 'multiple choice', 'eduai' ),
				'short'           => __( 'short answer', 'eduai' ),
				'notAnswered'     => __( 'not answered.', 'eduai' ),
				/* translators: %s: option letter */
				'youChose'        => __( 'you chose %s.', 'eduai' ),
				/* translators: 1: option letter 2: option text */
				'correctWas'      => __( 'Correct: %1$s. %2$s', 'eduai' ),
				/* translators: %s: option letter */
				'yourAnswerRight' => __( 'your answer %s is right.', 'eduai' ),
				'markScheme'      => __( 'Mark scheme:', 'eduai' ),
				'score'           => __( 'Score', 'eduai' ),
				'error'           => __( 'Something went wrong. Please try again.', 'eduai' ),
				'loginPrompt'     => __( 'Please sign in to use PrepareME.', 'eduai' ),
				'bands'           => array(
					'easy'   => __( 'Easy', 'eduai' ),
					'medium' => __( 'Medium', 'eduai' ),
					'hard'   => __( 'Hard', 'eduai' ),
				),
			),
		) );

		ob_start();
		include EDUAI_DIR . 'templates/prepare.php';
		return (string) ob_get_clean();
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
			return self::login_card(
				__( 'Sign in to use the calculator', 'eduai' ),
				__( 'Exact answers for arithmetic, computed in code — and worked, step-by-step solutions for everything else.', 'eduai' )
			);
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
			// page="1" sheds the widget shell: one scroll context, no head
			// bar (docs/09-ui-implementation-specs.md §4). Separate from tabs — different questions.
			'page'   => '',
		), (array) $atts, 'eduai_panel' );

		if ( EduAI_Settings::get( 'logged_in_only', true ) && ! is_user_logged_in() ) {
			return self::login_card(
				__( 'Sign in to ask the assistant', 'eduai' ),
				__( 'Ask anything about your course material — answers cite the documents they came from.', 'eduai' )
			);
		}

		// This used to work only because the floating widget enqueued chat.js on
		// every page. The widget is retired (docs/06 §3), so the panel asks for
		// its own assets like every other tab does.
		EduAI_Assistant::enqueue_chat_assets();

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
			return self::login_card(
				__( 'Sign in to use the summariser', 'eduai' ),
				__( 'Turn a lecture — PDF, slides or notes — into study notes in the style you need.', 'eduai' )
			);
		}

		wp_enqueue_style( 'eduai-chat', EDUAI_URL . 'assets/css/chat.css', array(), EDUAI_VERSION );
		wp_enqueue_script( 'eduai-summarise', EDUAI_URL . 'assets/js/summarise.js', array(), EDUAI_VERSION, true );

		// One call, both consumers. The banner below and the id the script
		// posts back come from this single resolution, so the label the page
		// promises and the scope the endpoint honours cannot disagree — they
		// are the same answer rendered twice, not two answers that match.
		$eduai_scope = EduAI_Scope::for_script();

		wp_localize_script( 'eduai-summarise', 'EduAISumConfig', array(
			'root'        => esc_url_raw( rest_url( EduAI_REST::NS ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'loggedIn'    => is_user_logged_in(),
			'maxUploadMb' => 20,
			'scope'       => $eduai_scope,
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
	 * Prompt anonymous visitors to sign in — each tool names itself and says
	 * what is behind the door, because for most visitors this card is the
	 * first thing they ever see of the product. A route to register rides
	 * along when registration is open: a first-time visitor has no account
	 * yet, so sign-in alone is the wrong single door.
	 *
	 * @param string $title Card heading, e.g. "Sign in to use PrepareME".
	 * @param string $lead  One sentence on what this tool does.
	 */
	private static function login_card( string $title, string $lead ): string {
		// The card is the whole page for a signed-out visitor, and .eduai-card
		// is styled in chat.css — which nothing else enqueues on this path.
		wp_enqueue_style( 'eduai-chat', EDUAI_URL . 'assets/css/chat.css', array(), EDUAI_VERSION );

		$register = '';

		if ( get_option( 'users_can_register' ) ) {
			$register = sprintf(
				' <a class="eduai-btn" href="%s">%s</a>',
				esc_url( wp_registration_url() ),
				esc_html__( 'Create an account', 'eduai' )
			);
		}

		return sprintf(
			'<div class="eduai-card eduai-login"><h3>%s</h3><p>%s</p><p class="eduai-login__actions"><a class="eduai-btn eduai-btn--primary" href="%s">%s</a>%s</p></div>',
			esc_html( $title ),
			esc_html( $lead ),
			esc_url( wp_login_url( get_permalink() ?: home_url( '/' ) ) ),
			esc_html__( 'Sign in', 'eduai' ),
			$register
		);
	}
}
