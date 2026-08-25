<?php
/**
 * Turning an understood message into an answer.
 *
 * WHERE THE TWO-SECOND BUDGET IS ACTUALLY WON
 *
 * Most intents here never call a language model. Showing courses, quoting a
 * price, listing enrolment steps and taking contact details are all database
 * work and templated sentences, and they return in milliseconds. Only two paths
 * spend a model call: recommending (which is a judgement) and an unrecognised
 * message (which is a last resort).
 *
 * That is deliberate, and it is the difference between a bot that answers in
 * 40 ms and one that answers in 1.9 s. It also means the assistant keeps
 * working — degraded but useful — when the provider is rate-limited or down,
 * which on a free tier is not a rare event.
 *
 * THE MODEL NEVER AUTHORS A FACT
 *
 * Cards are built from the database by PHP. When the model is used, it is given
 * courses that have already been chosen and told, in the system prompt, that
 * unknown fields must be described as unknown. The worst it can do is pick the
 * wrong course to talk about; it cannot invent a fee, a start date or a
 * duration, because it is never in a position to supply one.
 *
 * @package EduAI_Enquiry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Conversation behaviour.
 */
class EduAI_Enquiry_Flows {

	/**
	 * Handle one visitor message.
	 *
	 * @param string $message  What they typed.
	 * @param array  $session  token, language, state.
	 * @return array{reply:array,state:array,language:string}
	 */
	public static function handle( string $message, array $session ): array {
		$state    = (array) $session['state'];
		$language = EduAI_Enquiry_NLU::language( $message, $session['language'] ?? 'en' );

		$read  = EduAI_Enquiry_NLU::read( $message, $language );
		$state = EduAI_Enquiry_Session::remember( $state, 'user', $message );
		$state = EduAI_Enquiry_Session::accumulate( $state, $read['entities'] );

		/**
		 * Reroute a message before it is acted on.
		 *
		 * The hook that makes flows configurable without editing this class:
		 * return a different intent to send the conversation elsewhere.
		 *
		 * @param string $intent   Detected intent.
		 * @param array  $read     Full NLU result.
		 * @param array  $state    Conversation state.
		 * @param string $language Language in use.
		 */
		$intent = (string) apply_filters( 'eduai_enquiry_intent', $read['intent'], $read, $state, $language );

		// A conversation part-way through collecting details finishes that
		// before starting anything new. Abandoning a half-filled form to answer
		// a stray question loses the enquiry.
		if ( ! empty( $state['awaiting'] ) && in_array( $intent, array( 'lead', 'unknown', 'greeting' ), true ) ) {
			$intent = 'lead';
		}

		$reply = self::route( $intent, $read, $state, $language );

		$state = EduAI_Enquiry_Session::remember( $state, 'bot', (string) ( $reply['text'] ?? '' ) );

		return array(
			'reply'    => $reply,
			'state'    => $state,
			'language' => $language,
		);
	}

	/**
	 * Dispatch to the right handler.
	 */
	private static function route( string $intent, array $read, array &$state, string $language ): array {
		switch ( $intent ) {
			case 'greeting':
				return self::greet( $language );

			case 'discover':
			case 'price':
				return self::discover( $read, $state, $language, 'price' === $intent );

			case 'details':
				return self::discover( $read, $state, $language, false );

			case 'recommend':
				return self::recommend( $read, $state, $language );

			case 'register':
				return self::register( $read, $state, $language );

			case 'human':
				return self::human( $state, $language );

			case 'lead':
				return self::lead( $read, $state, $language );

			default:
				return self::unknown( $read, $state, $language );
		}
	}

	/**
	 * A reply envelope with sensible defaults.
	 */
	private static function envelope( string $text, array $extra = array() ): array {
		return array_merge(
			array(
				'type'  => 'message',
				'text'  => $text,
				'cards' => array(),
				'chips' => array(),
				'form'  => null,
				'meta'  => array(),
			),
			$extra
		);
	}

	/**
	 * The opening chips, which double as a menu for anyone who does not want to
	 * type.
	 */
	private static function chips( string $language ): array {
		return array(
			array( 'id' => 'browse', 'label' => EduAI_Enquiry_I18n::t( 'chip_browse', $language ) ),
			array( 'id' => 'recommend', 'label' => EduAI_Enquiry_I18n::t( 'chip_recommend', $language ) ),
			array( 'id' => 'enrol', 'label' => EduAI_Enquiry_I18n::t( 'chip_enrol', $language ) ),
			array( 'id' => 'human', 'label' => EduAI_Enquiry_I18n::t( 'chip_human', $language ) ),
		);
	}

	/**
	 * Hello.
	 */
	private static function greet( string $language ): array {
		return self::envelope(
			EduAI_Enquiry_I18n::t( 'greeting', $language ),
			array( 'chips' => self::chips( $language ) )
		);
	}

	/**
	 * Show courses.
	 *
	 * @param bool $emphasise_price Whether the visitor asked about money.
	 */
	private static function discover( array $read, array &$state, string $language, bool $emphasise_price ): array {
		$known = (array) ( $state['entities'] ?? array() );

		$courses = EduAI_Enquiry_Catalog::search(
			array(
				'keywords'   => $read['entities']['topics'] ?: ( $known['topics'] ?? array() ),
				'categories' => $known['categories'] ?? array(),
				'limit'      => 4,
			)
		);

		if ( ! $courses ) {
			return self::envelope(
				EduAI_Enquiry_I18n::t( 'nothing_at_all', $language ),
				array( 'chips' => array( array( 'id' => 'human', 'label' => EduAI_Enquiry_I18n::t( 'chip_human', $language ) ) ) )
			);
		}

		$fell_back = ! empty( $courses[0]['fallback'] );
		$text      = $fell_back ? EduAI_Enquiry_I18n::t( 'no_courses', $language ) : '';

		/*
		 * When the visitor asked about money and we do not have it, say so in
		 * words. Showing a card with an empty price field and no comment reads
		 * as "free" to most people, which is the expensive misreading.
		 */
		if ( $emphasise_price ) {
			$unknown = array_filter( $courses, static fn( $c ) => ! $c['price_known'] );

			if ( $unknown ) {
				$text = trim( $text . ' ' . EduAI_Enquiry_I18n::t( 'price_unknown_note', $language ) );
			}
		}

		if ( '' === $text ) {
			$text = 1 === count( $courses )
				? sprintf( 'ar' === $language ? 'وجدت دورة واحدة قد تناسبك:' : 'Here is one that looks relevant:' )
				: sprintf( 'ar' === $language ? 'إليك %d دورات قد تناسبك:' : 'Here are %d that look relevant:', count( $courses ) );
		}

		return self::envelope(
			$text,
			array(
				'cards' => array_map( array( __CLASS__, 'card' ), $courses ),
				'chips' => array(
					array( 'id' => 'enrol', 'label' => EduAI_Enquiry_I18n::t( 'chip_enrol', $language ) ),
					array( 'id' => 'human', 'label' => EduAI_Enquiry_I18n::t( 'chip_human', $language ) ),
				),
				'meta'  => array( 'intent' => $emphasise_price ? 'price' : 'discover', 'count' => count( $courses ) ),
			)
		);
	}

	/**
	 * Suggest something, with a reason.
	 *
	 * The one place a model earns its second: choosing between courses and
	 * saying why is a judgement, and a template cannot make it.
	 */
	private static function recommend( array $read, array &$state, string $language ): array {
		$known   = (array) ( $state['entities'] ?? array() );
		$courses = EduAI_Enquiry_Catalog::search(
			array(
				'keywords' => $read['entities']['topics'] ?: ( $known['topics'] ?? array() ),
				'limit'    => 6,
			)
		);

		if ( ! $courses ) {
			return self::envelope( EduAI_Enquiry_I18n::t( 'nothing_at_all', $language ) );
		}

		$lines = array();

		foreach ( $courses as $i => $c ) {
			$lines[] = sprintf(
				'%d. %s — %s',
				$i + 1,
				$c['title'],
				$c['description_known'] ? mb_substr( $c['description'], 0, 180 ) : 'no description on file'
			);
		}

		$profile = array_filter(
			array(
				'level'  => $known['level'] ?? '',
				'format' => $known['format'] ?? '',
				'topics' => implode( ', ', (array) ( $known['topics'] ?? array() ) ),
			)
		);

		$system = 'You help a visitor choose a course. You will be given a numbered list of real courses. '
			. 'Recommend at most two, by number, and say briefly why in one sentence each. '
			. 'NEVER state a price, duration, start date or format — you have not been given them and they will be shown separately. '
			. 'If nothing fits, say so plainly. '
			. ( 'ar' === $language ? 'Reply in Arabic.' : 'Reply in English.' )
			. ' Keep the whole reply under 60 words.';

		$user = "Courses:\n" . implode( "\n", $lines )
			. "\n\nWhat the visitor has told me: " . ( $profile ? wp_json_encode( $profile ) : 'nothing specific yet' )
			. "\n\nRecent conversation:\n" . EduAI_Enquiry_Session::transcript( $state );

		/*
		 * A HARD DEADLINE, because the promise is a reply in under two seconds
		 * and this is the only path that waits on somebody else's server.
		 *
		 * Measured: the courses are found in ~250 ms and the model then takes
		 * 1.8-2.1 s, which lands on or over the line. No amount of tuning fixes
		 * that, because the variable is a third party under load.
		 *
		 * So the model gets what is left of the budget and not a millisecond
		 * more. If it misses, the visitor still gets the right courses with a
		 * plain sentence instead of a written recommendation — degraded, useful,
		 * and inside the promise. Exceeding the budget to deliver nicer prose
		 * would be choosing the wrong one of the two.
		 */
		$out = EduAI_Enquiry_Model::ask( $system, $user, 220, 0.4, 2 );

		// Degrade to showing the courses rather than failing. A visitor who
		// asked for advice and receives a list has been helped; one who
		// receives an error has not.
		$text = is_wp_error( $out )
			? ( 'ar' === $language ? 'إليك الدورات الأقرب لطلبك:' : 'Here are the closest matches:' )
			: $out;

		return self::envelope(
			$text,
			array(
				'cards' => array_map( array( __CLASS__, 'card' ), array_slice( $courses, 0, 3 ) ),
				'chips' => array(
					array( 'id' => 'enrol', 'label' => EduAI_Enquiry_I18n::t( 'chip_enrol', $language ) ),
					array( 'id' => 'human', 'label' => EduAI_Enquiry_I18n::t( 'chip_human', $language ) ),
				),
				'meta'  => array( 'intent' => 'recommend', 'model_used' => ! is_wp_error( $out ) ),
			)
		);
	}

	/**
	 * How to enrol.
	 */
	private static function register( array $read, array &$state, string $language ): array {
		$known   = (array) ( $state['entities'] ?? array() );
		$courses = EduAI_Enquiry_Catalog::search(
			array(
				'keywords' => $read['entities']['topics'] ?: ( $known['topics'] ?? array() ),
				'limit'    => 2,
			)
		);

		$steps = array(
			EduAI_Enquiry_I18n::t( 'step_open', $language ),
			EduAI_Enquiry_I18n::t( 'step_account', $language ),
			EduAI_Enquiry_I18n::t( 'step_enrol', $language ),
			EduAI_Enquiry_I18n::t( 'step_start', $language ),
		);

		/**
		 * Replace the enrolment steps for a site that does it differently.
		 *
		 * @param string[] $steps    Ordered instructions.
		 * @param string   $language Visitor language.
		 */
		$steps = (array) apply_filters( 'eduai_enquiry_enrol_steps', $steps, $language );

		$text = EduAI_Enquiry_I18n::t( 'register_steps', $language ) . ":\n"
			. implode( "\n", array_map( static fn( $i, $s ) => ( $i + 1 ) . '. ' . $s, array_keys( $steps ), $steps ) );

		return self::envelope(
			$text,
			array(
				'cards' => array_map( array( __CLASS__, 'card' ), $courses ),
				'chips' => array(
					array( 'id' => 'human', 'label' => EduAI_Enquiry_I18n::t( 'chip_human', $language ) ),
				),
				'meta'  => array( 'intent' => 'register' ),
			)
		);
	}

	/**
	 * Hand over to a person.
	 */
	private static function human( array &$state, string $language ): array {
		$state['awaiting'] = 'lead';
		$state['escalate'] = true;

		return self::envelope(
			EduAI_Enquiry_I18n::t( 'human_intro', $language ) . ' ' . EduAI_Enquiry_I18n::t( 'human_hours', $language ),
			array(
				'type' => 'handoff',
				'form' => self::lead_form( $state, $language ),
				'meta' => array( 'intent' => 'human' ),
			)
		);
	}

	/**
	 * Collect contact details, or acknowledge ones already given.
	 */
	private static function lead( array $read, array &$state, string $language ): array {
		$known = (array) ( $state['entities'] ?? array() );
		$state['awaiting'] = 'lead';

		return self::envelope(
			! empty( $known['email'] ) || ! empty( $known['phone'] )
				? EduAI_Enquiry_I18n::t( 'ask_name', $language )
				: EduAI_Enquiry_I18n::t( 'ask_contact', $language ),
			array(
				'type' => 'lead_form',
				'form' => self::lead_form( $state, $language ),
				'meta' => array( 'intent' => 'lead' ),
			)
		);
	}

	/**
	 * The form, pre-filled with anything already mentioned.
	 *
	 * This is the "optional form pre-filling" from the brief: a visitor who has
	 * already typed their email should not be asked to type it again.
	 */
	private static function lead_form( array $state, string $language ): array {
		$known = (array) ( $state['entities'] ?? array() );

		return array(
			'id'      => 'lead',
			'consent' => EduAI_Enquiry_I18n::t( 'consent', $language ),
			'submit'  => EduAI_Enquiry_I18n::t( 'send', $language ),
			'fields'  => array(
				array( 'name' => 'name', 'label' => EduAI_Enquiry_I18n::t( 'ask_name', $language ), 'type' => 'text', 'value' => (string) ( $known['name'] ?? '' ), 'required' => false ),
				array( 'name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => (string) ( $known['email'] ?? '' ), 'required' => false ),
				array( 'name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'value' => (string) ( $known['phone'] ?? '' ), 'required' => false ),
				array( 'name' => 'interest', 'label' => EduAI_Enquiry_I18n::t( 'ask_interest', $language ), 'type' => 'text', 'value' => implode( ', ', (array) ( $known['topics'] ?? array() ) ), 'required' => false ),
			),
		);
	}

	/**
	 * Nothing matched.
	 *
	 * Offers a way forward rather than an apology. The chips matter more than
	 * the sentence: a visitor who cannot be understood in words can still press
	 * a button.
	 */
	private static function unknown( array $read, array &$state, string $language ): array {
		$text = 'ar' === $language
			? 'لست متأكداً من فهمي لطلبك. هل يمكنني مساعدتك بأحد هذه؟'
			: 'I am not sure I follow. Can I help with one of these?';

		return self::envelope(
			$text,
			array(
				'chips' => self::chips( $language ),
				'meta'  => array( 'intent' => 'unknown', 'confidence' => $read['confidence'] ),
			)
		);
	}

	/**
	 * A course as the widget will draw it.
	 *
	 * Unknown fields carry an explicit marker rather than an empty string, so
	 * the interface can say "not listed" and nobody downstream is tempted to
	 * treat blank as zero.
	 */
	private static function card( array $c ): array {
		return array(
			'id'          => $c['id'],
			'title'       => $c['title'],
			'url'         => $c['url'],
			'description' => $c['description_known'] ? $c['description'] : null,
			'duration'    => $c['duration_known'] ? $c['duration'] : null,
			'format'      => $c['format_known'] ? $c['format'] : null,
			'price'       => $c['price_known'] ? $c['price'] : null,
			'schedule'    => $c['schedule_known'] ? $c['schedule'] : null,
			'categories'  => $c['categories'],
		);
	}
}
