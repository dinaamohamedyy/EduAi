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

		/*
		 * WHY these matched changes what the sentence can honestly say.
		 *
		 * A course whose LESSONS mention regression is not "a course called
		 * regression". Back-end's `matched` carries the difference, and only
		 * this layer can turn it into words.
		 */
		$matched   = $courses[0]['matched'] ?? 'course';
		$fell_back = 'catalogue' === $matched || ! empty( $courses[0]['fallback'] );
		$text      = $fell_back ? EduAI_Enquiry_I18n::t( 'no_courses', $language ) : '';

		if ( ! $fell_back && 'lessons' === $matched ) {
			$text = EduAI_Enquiry_I18n::t( 'covered_inside', $language );
		}

		/*
		 * When the visitor asked about money and we do not have it, say so in
		 * words. Showing a card with an empty price field and no comment reads
		 * as "free" to most people, which is the expensive misreading.
		 */
		if ( $emphasise_price ) {
			$unknown = array_filter(
				$courses,
				static fn( $c ) => 'present' !== EduAI_Enquiry_Catalog::field( $c, 'price' )['status']
			);

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

		/*
		 * Phase one builds NO prompt and calls no model.
		 *
		 * It used to do both. When the written half moved to follow_up(), the
		 * prompt-building left here became dead - and then DRIFTED, still
		 * telling the model to answer "by number" long after the live copy had
		 * stopped. I edited this one by mistake and measured no change, which is
		 * how a second copy of a prompt costs more than the lines it occupies.
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

		$lines  = array();
		$titles = array();
		$n      = 0;

		foreach ( (array) $pending['courses'] as $id ) {
			$course = EduAI_Enquiry_Catalog::get( (int) $id );

			if ( ! $course ) {
				continue;
			}

			++$n;
			$titles[] = $course['title'];

			/*
			 * Deliberately UNNUMBERED. A numbered list invites the model to
			 * answer in numbers, and front-end proved an ordinal cannot be
			 * resolved on screen: the transcript accumulates cards across turns,
			 * so "course 3" indexes a list that exists nowhere as a single
			 * visible thing. Titles are the only reference that survives a
			 * scrolling conversation.
			 */
			$lines[] = sprintf(
				'- %s - %s',
				$course['title'],
				$course['description_known'] ? mb_substr( $course['description'], 0, 180 ) : 'no description on file'
			);
		}

		if ( ! $lines ) {
			return new WP_Error( 'eduai_eq_gone', __( 'Those courses are no longer available.', 'eduai-enquiry' ), array( 'status' => 410 ) );
		}

		$profile = (array) ( $pending['profile'] ?? array() );

		$system = 'You help a visitor choose a course. You will be given a list of real courses. '
			. 'Recommend at most two. Refer to each ONLY by its exact title, written exactly as given, '
			. 'in its original script even when the rest of your reply is in Arabic. '
			. 'NEVER use a number or a position: the reader sees titled cards, not a numbered list, '
			. 'so "course 3" points at nothing they can see. '
			. 'Say briefly why in one sentence each. '
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

		$clean = self::tidy( $out, $titles );

		/*
		 * A refused reply is not an error. The cards are already on the
		 * visitor's screen and they are right - they came from the database,
		 * not from a model. Losing the sentence loses a nicety; showing a
		 * reference the reader cannot resolve would cost their trust in the
		 * cards beside it.
		 */
		return array(
			'text'     => '' !== $clean ? $clean : EduAI_Enquiry_I18n::t( 'closest_matches', $language ),
			'verified' => '' !== $clean,
		);
	}

	/**
	 * Clean a model reply before it becomes a chat bubble.
	 *
	 * Models pad. A trailing newline and a doubled space are invisible in JSON
	 * and visible in a bubble, and front-end saw both in the Arabic reply -
	 * which is exactly the kind of thing only rendering finds.
	 *
	 * @param string $text Raw reply.
	 */
	/**
	 * Is this reply fit to show, and tidied if so?
	 *
	 * VERIFY AND REFUSE, RATHER THAN EDIT.
	 *
	 * The first attempt at this rewrote the model's prose: strip a leading
	 * ordinal, strip stray digits, protect the titles while doing it. It
	 * worked on fixtures and corrupted real replies — two of four live runs
	 * came back with a title missing and a stray colon where it had been. A
	 * cleaner that damages the thing it cleans is worse than no cleaner, and
	 * the damage is invisible in JSON.
	 *
	 * So this does not edit. It ASKS TWO QUESTIONS and, if either answer is
	 * wrong, discards the prose entirely — the caller then shows the cards with
	 * a plain templated sentence. That is the rule the rest of this plugin runs
	 * on: a model may choose which courses to talk about, and everything it
	 * says about them is checked rather than trusted.
	 *
	 *   1. Does it reference a course by NUMBER? An ordinal indexes a list the
	 *      visitor cannot see — the transcript accumulates cards across turns,
	 *      so "course 3" points at nothing on screen.
	 *   2. Does it name at least one course actually offered? A recommendation
	 *      that names none is either about something we did not send or about
	 *      nothing at all.
	 *
	 * Losing the sentence costs a nicety. Showing a confident reference the
	 * reader cannot resolve costs their trust in the cards beside it, which
	 * ARE right.
	 *
	 * @param string   $text   Raw reply from the model.
	 * @param string[] $titles Titles the model was given.
	 * @return string Tidied reply, or '' when it should not be shown.
	 */
	private static function tidy( string $text, array $titles = array() ): string {
		// Models pad. A trailing newline and a doubled space are invisible in
		// JSON and visible in a bubble; front-end saw both in Arabic.
		$text = trim( $text );
		$text = preg_replace( '/[ \t]{2,}/u', ' ', $text );
		$text = preg_replace( '/[ \t]+\n/u', "\n", (string) $text );
		$text = trim( (string) preg_replace( '/\n{3,}/u', "\n\n", (string) $text ) );

		if ( '' === $text ) {
			return '';
		}

		/*
		 * Numbers, in any of the shapes seen live. Enumerating phrasings would
		 * be whack-a-mole if this were a CLEANER — as a detector it is fine,
		 * because a shape nobody listed simply passes, and the title check
		 * below still has to hold.
		 */
		$ordinal = '/(?:^|\n)\s*\d+\s*[.):\-]|\b(?:course|option|number|item|no)\s*#?\s*\d+\b|(?:المقرر|المساق|المسار|الدوره|الاختيار|الخيار|البند)\s*(?:رقم\s*)?\d+/iu';

		if ( preg_match( $ordinal, $text ) ) {
			return '';
		}

		foreach ( $titles as $title ) {
			$title = trim( (string) $title );

			if ( '' !== $title && false !== mb_stripos( $text, $title ) ) {
				return $text;
			}
		}

		return '';
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
		 * Front-end's one hard ask, and the reason is exact: once JSON reaches
		 * JavaScript an omitted key and a null render identically, so "we do not
		 * know the fee" and "the engine forgot to send the fee" become the same
		 * card. Honest versus lucky.
		 *
		 * FOUR STATUSES, NOT A BOOLEAN. Back-end's contract distinguishes a
		 * field the source HAS and nobody filled in (`not_set` — say "not
		 * listed") from one the source has no concept of (`unsupported` — say
		 * nothing at all). My old `*_known` flag collapsed those, so a
		 * WooCommerce site with no notion of a schedule would have been told a
		 * schedule was "not listed", which is a claim about a field that does
		 * not exist.
		 *
		 * The statuses travel ALONGSIDE the values rather than replacing them,
		 * so the front end's existing renderer keeps working unchanged and can
		 * adopt them when it wants to.
		 */
		$status = array();
		$value  = array();

		foreach ( array( 'description', 'duration', 'format', 'price', 'schedule' ) as $key ) {
			$field          = EduAI_Enquiry_Catalog::field( $c, $key );
			$status[ $key ] = $field['status'];

			$value[ $key ] = in_array( $field['status'], array( 'present', 'derived' ), true )
				? $field['value']
				: null;
		}

		// Free and open are a controlled vocabulary, so the word is chosen in
		// the VISITOR's language rather than the site's. A real amount is not.
		if ( null !== $value['price'] && '' !== (string) ( $c['price_token'] ?? '' ) ) {
			$value['price'] = EduAI_Enquiry_I18n::t( 'free' === $c['price_token'] ? 'free' : 'open', $language );
		}

		return array(
			'id'          => $c['id'],
			'title'       => $c['title'],
			'url'         => $c['url'],
			'description' => $value['description'],
			'duration'    => $value['duration'],
			'format'      => $value['format'],
			'price'       => $value['price'],
			'schedule'    => $value['schedule'],
			'cta'         => null,
			'categories'  => $c['categories'] ?? array(),
			'status'      => $status,
			'matched'     => $c['matched'] ?? 'course',
		);
	}

	/**
	 * Cards for a list, in the visitor's language.
	 */
	private static function cards( array $courses, string $language ): array {
		return array_map( static fn( $c ) => self::card( $c, $language ), $courses );
	}
}
