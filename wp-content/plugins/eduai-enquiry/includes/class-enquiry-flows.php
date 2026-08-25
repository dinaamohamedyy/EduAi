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
		/*
		 * `requested` is what the visitor asked for; `language` is what the
		 * session was last answered in. They are different questions and were
		 * previously collapsed into one, which is how an explicit choice ended
		 * up losing to the alphabet somebody typed a course title in.
		 */
		$language = EduAI_Enquiry_NLU::language(
			$message,
			$session['language'] ?? 'en',
			(string) ( $session['requested'] ?? $state['requested'] ?? '' )
		);

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
			case 'schedule':
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
			self::chip( 'browse', 'chip_browse', $language ),
			self::chip( 'recommend', 'chip_recommend', $language ),
			self::chip( 'enrol', 'chip_enrol', $language ),
			self::chip( 'human', 'chip_human', $language ),
		);
	}

	/**
	 * One shortcut.
	 *
	 * `label` is what the visitor reads, `value` is what is sent back when they
	 * press it. They are the same phrase today, and they must stay separate
	 * fields anyway: the moment a label is reworded for the interface, a chip
	 * whose value IS its label starts sending different text to the classifier
	 * and the flow quietly changes with the copy.
	 */
	private static function chip( string $id, string $key, string $language ): array {
		$label = EduAI_Enquiry_I18n::t( $key, $language );

		return array(
			'id'    => $id,
			'label' => $label,
			'value' => $label,
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
				array( 'chips' => array( self::chip( 'human', 'chip_human', $language ) ) )
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
				'cards' => self::cards( $courses, $language ),
				'chips' => array(
					self::chip( 'enrol', 'chip_enrol', $language ),
					self::chip( 'human', 'chip_human', $language ),
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
		/*
		 * PHASE ONE: the cards, and a ticket for the sentence.
		 *
		 * The courses are found in ~250 ms and the model then takes 1.4-2.1 s.
		 * Waiting for it puts the whole reply outside the two-second promise,
		 * so the cards go now and the written recommendation follows.
		 *
		 * The ticket is a random id and nothing else. The chosen course ids
		 * live in the session on this server, so a client cannot edit a token
		 * into asking us to write prose about courses we never selected, and
		 * cannot replay it to burn tokens - it is spent on use.
		 */
		$ticket = substr( hash( 'sha1', wp_generate_password( 24, false ) . microtime( true ) ), 0, 32 );

		$state['follow_up'] = array(
			'ticket'  => $ticket,
			'courses' => wp_list_pluck( array_slice( $courses, 0, 3 ), 'id' ),
			'profile' => $profile,
			'expires' => time() + 60,
		);

		return self::envelope(
			'',
			array(
				'cards' => self::cards( array_slice( $courses, 0, 3 ), $language ),
				'chips' => array(
					self::chip( 'enrol', 'chip_enrol', $language ),
					self::chip( 'human', 'chip_human', $language ),
				),
				'meta'  => array(
					'intent'    => 'recommend',
					'follow_up' => array(
						'url'        => rest_url( 'eduai-enquiry/v1/recommend' ),
						'token'      => $ticket,
						'timeout_ms' => 6000,
					),
				),
			)
		);
	}

	/**
	 * PHASE TWO: the sentence, once the model has written it.
	 *
	 * Called by the widget after the cards are already on screen, so this is
	 * the one place in the plugin allowed to take its time. It still carries a
	 * deadline, because the client has one too and a reply arriving after the
	 * placeholder is gone is wasted work and wasted tokens.
	 *
	 * @param array  $state    Conversation state, by reference so the ticket can be spent.
	 * @param string $ticket   The ticket issued in phase one.
	 * @param string $language Visitor language.
	 * @return array|WP_Error
	 */
	public static function follow_up( array &$state, string $ticket, string $language ) {
		$pending = (array) ( $state['follow_up'] ?? array() );

		if ( empty( $pending['ticket'] ) || ! hash_equals( (string) $pending['ticket'], $ticket ) ) {
			return new WP_Error( 'eduai_eq_no_ticket', 'debug', array( 'status' => 404 ) );
		}

		// Spent BEFORE the model is called, not after: a slow model must not
		// leave a replayable ticket open behind it.
		unset( $state['follow_up'] );

		if ( (int) ( $pending['expires'] ?? 0 ) < time() ) {
			return new WP_Error( 'eduai_eq_expired', __( 'That request expired.', 'eduai-enquiry' ), array( 'status' => 410 ) );
		}

		$lines = array();
		$n     = 0;

		foreach ( (array) $pending['courses'] as $id ) {
			$course = EduAI_Enquiry_Catalog::get( (int) $id );

			if ( ! $course ) {
				continue;
			}

			++$n;

			$lines[] = sprintf(
				'%d. %s - %s',
				$n,
				$course['title'],
				$course['description_known'] ? mb_substr( $course['description'], 0, 180 ) : 'no description on file'
			);
		}

		if ( ! $lines ) {
			return new WP_Error( 'eduai_eq_gone', __( 'Those courses are no longer available.', 'eduai-enquiry' ), array( 'status' => 410 ) );
		}

		$profile = (array) ( $pending['profile'] ?? array() );

		$system = 'You help a visitor choose a course. You will be given a numbered list of real courses. '
			. 'Recommend at most two, by number, and say briefly why in one sentence each. '
			. 'NEVER state a price, duration, start date or format - you have not been given them and they are already shown beside your reply. '
			. 'If nothing fits, say so plainly. '
			. ( 'ar' === $language ? 'Reply in Arabic.' : 'Reply in English.' )
			. ' Keep the whole reply under 60 words.';

		$user = "Courses:\n" . implode( "\n", $lines )
			. "\n\nWhat the visitor has told me: " . ( $profile ? wp_json_encode( $profile ) : 'nothing specific yet' );

		/*
		 * 600 tokens for a sixty-word answer, measured rather than guessed.
		 *
		 * 220 failed with eduai_truncated: the model reasons before it writes
		 * and bills the thinking to the same budget. 500 answered, 900 answered
		 * no better. This is the same lesson as the intent classifier one size
		 * up — there, one word needed 200 — and it is worth stating as a rule:
		 * on a reasoning model, a budget sized to the VISIBLE answer produces
		 * an empty reply and an error, never a cheaper reply.
		 *
		 * The cost is real: against the 2,000-token minute this assistant is
		 * allowed, 600 buys about three written recommendations a minute
		 * site-wide. Everything else on the page is free, so that is the
		 * feature to watch if the ceiling ever starts biting.
		 */
		$out = EduAI_Enquiry_Model::ask( $system, $user, 600, 0.4, 5 );


		if ( is_wp_error( $out ) ) {
			return $out;
		}

		return array( 'text' => $out );
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
				'cards' => self::cards( $courses, $language ),
				'chips' => array(
					self::chip( 'human', 'chip_human', $language ),
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
			'id'      => 'enquiry',
			'title'   => EduAI_Enquiry_I18n::t( 'form_title', $language ),
			'consent' => EduAI_Enquiry_I18n::t( 'consent', $language ),
			'submit'  => EduAI_Enquiry_I18n::t( 'send', $language ),
			'fields'  => array(
				array(
					'name'         => 'name',
					'label'        => EduAI_Enquiry_I18n::t( 'ask_name', $language ),
					'type'         => 'text',
					'value'        => (string) ( $known['name'] ?? '' ),
					'required'     => false,
					'autocomplete' => 'name',
				),
				array(
					'name'         => 'email',
					'label'        => EduAI_Enquiry_I18n::t( 'f_email', $language ),
					'type'         => 'email',
					'value'        => (string) ( $known['email'] ?? '' ),
					'required'     => false,
					'autocomplete' => 'email',
				),
				array(
					'name'         => 'phone',
					'label'        => EduAI_Enquiry_I18n::t( 'f_phone', $language ),
					'type'         => 'tel',
					'value'        => (string) ( $known['phone'] ?? '' ),
					'required'     => false,
					'autocomplete' => 'tel',
				),
				array(
					'name'         => 'interest',
					'label'        => EduAI_Enquiry_I18n::t( 'ask_interest', $language ),
					'type'         => 'text',
					'value'        => implode( ', ', (array) ( $known['topics'] ?? array() ) ),
					'required'     => false,
					'autocomplete' => 'off',
				),
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
	private static function card( array $c, string $language = 'en' ): array {
		/*
		 * EVERY KEY IS ALWAYS PRESENT, and unknown is an explicit null.
		 *
		 * Front-end asked for this and the reason is exact: once JSON reaches
		 * JavaScript, an omitted key and a null are indistinguishable. Both
		 * would print "not listed" — one because we said so, the other because
		 * the engine had a bug. That is the difference between a card that is
		 * honest and a card that is lucky.
		 */
		$price = null;

		if ( $c['price_known'] ) {
			// Free and open are a controlled vocabulary, so the word is chosen
			// in the VISITOR's language rather than the site's.
			$price = '' !== $c['price_token']
				? EduAI_Enquiry_I18n::t( 'free' === $c['price_token'] ? 'free' : 'open', $language )
				: $c['price'];
		}

		return array(
			'id'          => $c['id'],
			'title'       => $c['title'],
			'url'         => $c['url'],
			'description' => $c['description_known'] ? $c['description'] : null,
			'duration'    => $c['duration_known'] ? $c['duration'] : null,
			'format'      => $c['format_known'] ? $c['format'] : null,
			'price'       => $price,
			'schedule'    => $c['schedule_known'] ? $c['schedule'] : null,
			'cta'         => null,
			'categories'  => $c['categories'],
		);
	}

	/**
	 * Cards for a list, in the visitor's language.
	 */
	private static function cards( array $courses, string $language ): array {
		return array_map( static fn( $c ) => self::card( $c, $language ), $courses );
	}
}
