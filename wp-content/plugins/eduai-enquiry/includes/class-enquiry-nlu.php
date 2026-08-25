<?php
/**
 * What did the visitor mean, and what did they name?
 *
 * TWO PASSES, AND THE ORDER MATTERS
 *
 * A model call costs tokens and roughly a second, and this stack's free tier
 * allows about 8,000 tokens a minute across the whole site. Classifying "hi" or
 * "how much is it?" with a language model spends that budget on questions a
 * pattern answers perfectly. So:
 *
 *   1. Deterministic. Patterns in English and Arabic. Free, instant, and
 *      auditable — you can read why it decided what it decided.
 *   2. The model, only when the first pass is genuinely unsure.
 *
 * The trade is real and worth stating: patterns are brittle at the edges and
 * will miss phrasings nobody anticipated. The escape hatch is that ambiguity
 * escalates rather than guessing, and `confidence` travels with the result so
 * the caller can choose to ask a clarifying question instead of acting.
 *
 * CONTACT DETAILS NEVER REACH THE MODEL
 *
 * Email addresses and phone numbers are extracted here, by regular expression,
 * on this server. They are not in the text sent for classification and they are
 * not in any prompt. A visitor typing their phone number into a chat box has
 * not consented to it being transmitted to a third-party inference provider,
 * and there is no feature here that needs it to be.
 *
 * @package EduAI_Enquiry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Intent recognition and entity extraction.
 */
class EduAI_Enquiry_NLU {

	/**
	 * Every intent this assistant can act on.
	 *
	 * `unknown` is a first-class member rather than a failure. A bot that
	 * always picks something is a bot that confidently answers questions it did
	 * not understand.
	 */
	/**
	 * Where per-language understanding rates are kept.
	 */
	private const STATS_OPTION = 'eduai_enquiry_nlu_stats';

	public const INTENTS = array(
		'greeting',
		'discover',
		'details',
		'recommend',
		'register',
		'price',
		'lead',
		'human',
		'unknown',
	);

	/**
	 * Patterns per intent, English and Arabic together.
	 *
	 * Arabic is matched without diacritics and with the alef/ya variants that
	 * real typing produces; `normalise()` folds those before matching, so these
	 * stay readable.
	 */
	private const PATTERNS = array(
		'human'     => array(
			'/\b(human|agent|person|representative|advisor|adviser|someone real|talk to (a|someone))\b/iu',
			'/\b(call me|contact me|speak to)\b/iu',
			'/(موظف|شخص|انسان|بشري|مستشار|احد|اتحدث مع|اكلم|خدمه العملاء|خدمة العملاء)/u',
		),
		'register'  => array(
			'/\b(register|enrol|enroll|sign ?up|join|apply|how do i (start|begin|get))\b/iu',
			'/(تسجيل|اسجل|سجلني|التحاق|انضمام|اشترك|اشتراك|كيف ابدا|كيف اسجل)/u',
		),
		'price'     => array(
			'/\b(price|cost|fee|fees|how much|tuition|payment|discount|free)\b/iu',
			'/(سعر|السعر|تكلفة|التكلفة|رسوم|كم يكلف|كم سعر|مجان|مجاني|مجانا|خصم)/u',
		),
		'recommend' => array(
			'/\b(recommend|suggest|advice|advise|which (course|one)|what should i|best (course|for me)|help me (choose|pick))\b/iu',
			'/(انصح|تنصح|اقترح|توصي|توصية|ايه افضل|اي دورة|ماذا اختار|ساعدني اختار)/u',
		),
		'discover'  => array(
			'/\b(course|courses|class|classes|programme|program|training|workshop|do you (have|offer)|looking for|learn)\b/iu',
			'/(دورة|دوره|دورات|كورس|كورسات|برنامج|تدريب|ورشة|عندكم|ابحث عن|اتعلم|تعليم)/u',
		),
		'greeting'  => array(
			'/^\s*(hi|hey|hello|good (morning|afternoon|evening)|salam|salaam)\b/iu',
			'/^\s*(مرحبا|اهلا|السلام عليكم|صباح الخير|مساء الخير|هلا)/u',
		),

		/*
		 * The buckets below were added after MEASURING the hit rate on a
		 * realistic set of sales questions rather than obvious ones. My first
		 * fifteen test phrases matched 14 times and told me almost nothing; a
		 * dozen questions a visitor would really ask matched 58% in English and
		 * 50% in Arabic, and every miss is a 200-token model call against a
		 * 2,000-token minute.
		 */
		'details'   => array(
			'/\b(tell me (more )?about|what (is|are)|explain|details|more (info|information)|syllabus|curriculum|certificate|certification|accredited|prerequisite)\b/iu',
			'/(حدثني|اخبرني|ما هي|ماهي|تفاصيل|معلومات|محتوي|منهج|شهاده|شهادات|معتمده|متطلبات)/u',
		),
		'schedule'  => array(
			'/\b(when|what time|times|timing|start date|starts|begins|next (intake|cohort|month|term)|schedule|timetable|duration|how long)\b/iu',
			'/(متي|موعد|مواعيد|توقيت|يبدا|تبدا|البدء|الجدول|المده|كم مده|الدفعه القادمه)/u',
		),
	);

	/**
	 * Order in which patterns are tried.
	 *
	 * Specific before general, deliberately. "How much does the Python course
	 * cost?" is a price question that also mentions a course; testing
	 * `discover` first would answer the wrong half.
	 */
	private const PRECEDENCE = array( 'human', 'register', 'price', 'schedule', 'recommend', 'details', 'discover', 'greeting' );

	/**
	 * Read a visitor's message.
	 *
	 * @param string $text     What they typed.
	 * @param string $language 'en' or 'ar'.
	 * @return array{intent:string,confidence:float,entities:array,by:string}
	 */
	public static function read( string $text, string $language = 'en' ): array {
		$result = self::classify( $text, $language );

		self::record( $language, $result['by'] );

		/**
		 * Replace or refine the classification.
		 *
		 * The seam that keeps this decision reversible. The deterministic
		 * matcher is one implementation; swapping in a model classifier, or a
		 * hosted NLU service, is a filter rather than surgery on the flows.
		 * Baking keyword patterns into the conversation logic itself is the
		 * version that could not be unpicked, and this exists so that never
		 * happens.
		 *
		 * @param array  $result   intent, confidence, entities, by.
		 * @param string $text     The visitor's message.
		 * @param string $language Detected language.
		 */
		return (array) apply_filters( 'eduai_enquiry_classify', $result, $text, $language );
	}

	/**
	 * Count how each language is being understood.
	 *
	 * ARABIC WILL NOT BEHAVE LIKE ENGLISH AND A BLENDED FIGURE HIDES IT.
	 *
	 * Pattern matching degrades badly in Arabic — clitics attach to words, the
	 * morphology is rich, dialect diverges from MSA, and there is no
	 * capitalisation to lean on. So the deterministic pass will hit less often
	 * there, escalation will be higher, replies slower and costlier, FOR ARABIC
	 * SPEAKERS ONLY. A single hit rate of 90% can be 98% English and 40%
	 * Arabic, and nothing on any screen would say so.
	 *
	 * Counts only. No message text, ever.
	 *
	 * @param string $language en or ar.
	 * @param string $by       pattern, model or none.
	 */
	private static function record( string $language, string $by ): void {
		$stats = (array) get_option( self::STATS_OPTION, array() );
		$key   = ( 'ar' === $language ? 'ar' : 'en' ) . '_' . $by;

		$stats[ $key ] = (int) ( $stats[ $key ] ?? 0 ) + 1;

		update_option( self::STATS_OPTION, $stats, false );
	}

	/**
	 * Understanding rates per language, for the admin screen.
	 */
	public static function stats(): array {
		$s   = (array) get_option( self::STATS_OPTION, array() );
		$out = array();

		foreach ( array( 'en', 'ar' ) as $lang ) {
			$pattern = (int) ( $s[ $lang . '_pattern' ] ?? 0 );
			$model   = (int) ( $s[ $lang . '_model' ] ?? 0 );
			$none    = (int) ( $s[ $lang . '_none' ] ?? 0 );
			$total   = $pattern + $model + $none;

			$out[ $lang ] = array(
				'total'     => $total,
				'pattern'   => $pattern,
				'escalated' => $model,
				'unknown'   => $none,
				'hit_rate'  => $total ? round( 100 * $pattern / $total ) : null,
			);
		}

		return $out;
	}

	/**
	 * The classification itself.
	 */
	private static function classify( string $text, string $language ): array {
		$entities = self::entities( $text, $language );

		// Contact details are the loudest signal there is: somebody who types
		// an email address is offering to be contacted, whatever else the
		// sentence says.
		if ( ! empty( $entities['email'] ) || ! empty( $entities['phone'] ) ) {
			return array(
				'intent'     => 'lead',
				'confidence' => 1.0,
				'entities'   => $entities,
				'by'         => 'pattern',
			);
		}

		$hit = self::match( $text );

		if ( '' !== $hit ) {
			return array(
				'intent'     => $hit,
				'confidence' => 0.9,
				'entities'   => $entities,
				'by'         => 'pattern',
			);
		}

		$guess = self::via_model( $text, $language );

		if ( $guess ) {
			return array(
				'intent'     => $guess,
				'confidence' => 0.6,
				'entities'   => $entities,
				'by'         => 'model',
			);
		}

		return array(
			'intent'     => 'unknown',
			'confidence' => 0.0,
			'entities'   => $entities,
			'by'         => 'none',
		);
	}

	/**
	 * First pattern to claim the message, in precedence order.
	 */
	private static function match( string $text ): string {
		$norm = self::normalise( $text );

		foreach ( self::PRECEDENCE as $intent ) {
			foreach ( self::PATTERNS[ $intent ] as $pattern ) {
				if ( preg_match( $pattern, $norm ) ) {
					return $intent;
				}
			}
		}

		return '';
	}

	/**
	 * Fold Arabic orthography so one pattern matches how people actually type.
	 *
	 * Arabic writing varies in ways that carry no meaning here: hamza on alef
	 * is frequently dropped, ta marbuta and ha are interchanged, and diacritics
	 * are almost never typed. Without this, `دورة` and `دوره` are different
	 * strings and half the visitors miss the pattern.
	 */
	public static function normalise( string $text ): string {
		$text = trim( $text );

		// Tashkeel and tatweel carry no lexical weight.
		$text = preg_replace( '/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text );

		return strtr(
			$text,
			array(
				'أ' => 'ا',
				'إ' => 'ا',
				'آ' => 'ا',
				'ٱ' => 'ا',
				'ى' => 'ي',
				'ئ' => 'ي',
				'ؤ' => 'و',
				'ة' => 'ه',
				'\u{06CC}' => 'ي',
			)
		);
	}

	/**
	 * Everything nameable in the message.
	 *
	 * @param string $text     Message.
	 * @param string $language Language hint.
	 */
	public static function entities( string $text, string $language = 'en' ): array {
		$norm = self::normalise( $text );

		$out = array(
			'email'   => self::email( $text ),
			'phone'   => self::phone( $text ),
			'name'    => self::name( $text, $language ),
			'level'   => '',
			'format'  => '',
			'free'    => null,
			'topics'  => self::topics( $norm ),
		);

		if ( preg_match( '/\b(beginner|basic|introduction|intro|starter|new to)\b/iu', $norm ) || preg_match( '/(مبتدي|مبتدئ|اساسي|بداية|مقدمة|مقدمه)/u', $norm ) ) {
			$out['level'] = 'beginner';
		} elseif ( preg_match( '/\b(advanced|expert|deep|professional)\b/iu', $norm ) || preg_match( '/(متقدم|احترافي|محترف)/u', $norm ) ) {
			$out['level'] = 'advanced';
		} elseif ( preg_match( '/\b(intermediate)\b/iu', $norm ) || preg_match( '/(متوسط)/u', $norm ) ) {
			$out['level'] = 'intermediate';
		}

		if ( preg_match( '/\b(online|remote|virtual|distance|self ?paced)\b/iu', $norm ) || preg_match( '/(اونلاين|عن بعد|انترنت|بعد|ذاتي)/u', $norm ) ) {
			$out['format'] = 'online';
		} elseif ( preg_match( '/\b(in ?person|on ?site|classroom|campus|face to face)\b/iu', $norm ) || preg_match( '/(حضوري|في المقر|وجها لوجه|بالفصل)/u', $norm ) ) {
			$out['format'] = 'in_person';
		}

		if ( preg_match( '/\bfree\b/iu', $norm ) || preg_match( '/(مجان|مجاني|مجانا)/u', $norm ) ) {
			$out['free'] = true;
		} elseif ( preg_match( '/\b(paid|premium)\b/iu', $norm ) || preg_match( '/(مدفوع|مدفوعة)/u', $norm ) ) {
			$out['free'] = false;
		}

		return $out;
	}

	/**
	 * An email address, or ''.
	 */
	private static function email( string $text ): string {
		if ( preg_match( '/[\w.+-]+@[\w-]+\.[\w.-]+/u', $text, $m ) ) {
			return is_email( $m[0] ) ? sanitize_email( $m[0] ) : '';
		}

		return '';
	}

	/**
	 * A phone number, or ''.
	 *
	 * Deliberately loose on shape and strict on length. International formats
	 * vary far more than any pattern worth maintaining, so this looks for a run
	 * of digits with the usual separators and then counts the digits — seven is
	 * the shortest real subscriber number, fifteen the E.164 maximum. Arabic-
	 * Indic digits are folded first, because a visitor typing in Arabic will
	 * often use them.
	 */
	private static function phone( string $text ): string {
		$folded = strtr(
			$text,
			array(
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			)
		);

		if ( ! preg_match( '/\+?[\d][\d\s().-]{6,20}\d/u', $folded, $m ) ) {
			return '';
		}

		$digits = preg_replace( '/\D+/', '', $m[0] );
		$len    = strlen( (string) $digits );

		if ( $len < 7 || $len > 15 ) {
			return '';
		}

		return trim( $m[0] );
	}

	/**
	 * A self-introduced name, or ''.
	 *
	 * Only when the visitor announces it. Guessing a name out of arbitrary
	 * prose produces a CRM full of the word "Interested".
	 */
	private static function name( string $text, string $language ): string {
		$patterns = array(
			'/\b(?:my name is|i am|i\'m|this is)\s+([\p{L}\'’\- ]{2,60})/iu',
			'/(?:اسمي|انا اسمي|معك|انا)\s+([\p{L}\'’\- ]{2,60})/u',
		);

		foreach ( $patterns as $p ) {
			if ( ! preg_match( $p, self::normalise( $text ), $m ) ) {
				continue;
			}

			$words = preg_split( '/\s+/u', trim( $m[1] ), -1, PREG_SPLIT_NO_EMPTY );
			$name  = array();

			foreach ( (array) $words as $w ) {
				/*
				 * Stop at the first word that cannot be part of a name.
				 *
				 * Found by running it: "my name is Sara and my email is..."
				 * produced "Sara and my", and the Arabic equivalent produced
				 * "احمد ورقمي" — Ahmed AND-MY-NUMBER. Capturing a fixed number
				 * of words assumes the name is the last thing in the sentence,
				 * and it usually is not.
				 */
				if ( self::not_a_name( $w ) ) {
					break;
				}

				$name[] = $w;

				if ( count( $name ) >= 3 ) {
					break;
				}
			}

			if ( ! $name ) {
				continue;
			}

			/*
			 * "انا مبتدئ" is "I am a beginner", not a person called Beginner.
			 * A bare "I am" introduces a description far more often than a
			 * name, in both languages, so the first word has to survive a
			 * second look.
			 */
			if ( self::describes_rather_than_names( $name[0] ) ) {
				continue;
			}

			return mb_substr( implode( ' ', $name ), 0, 80 );
		}

		return '';
	}

	/**
	 * Words that end a name rather than continue it.
	 */
	private static function not_a_name( string $word ): bool {
		static $stop = array(
			'and', 'my', 'the', 'a', 'an', 'is', 'are', 'from', 'at', 'on', 'in', 'with',
			'email', 'phone', 'number', 'mobile', 'address', 'here', 'there',
			'و', 'ورقمي', 'ورقم', 'وايميلي', 'وبريدي', 'وعمري', 'ايميلي', 'بريدي', 'رقمي', 'هاتفي', 'من', 'في', 'على',
		);

		return in_array( mb_strtolower( $word ), $stop, true );
	}

	/**
	 * A description someone gave of themselves, not what they are called.
	 */
	private static function describes_rather_than_names( string $word ): bool {
		static $adjectives = array(
			'looking', 'interested', 'trying', 'hoping', 'new', 'not', 'just', 'searching',
			'beginner', 'advanced', 'intermediate', 'student', 'teacher', 'here', 'sure', 'ready',
			'مبتدي', 'مبتدئ', 'متقدم', 'متوسط', 'مهتم', 'ابحث', 'اريد', 'طالب', 'جديد', 'هنا', 'اسال',
		);

		return in_array( mb_strtolower( $word ), $adjectives, true );
	}

	/**
	 * Content words worth searching the catalogue for.
	 *
	 * Stopwords in both languages are dropped, because "do you have a course
	 * about python" should search for `python` and not for `have`.
	 */
	private static function topics( string $norm ): array {
		$stop = array(
			'the','a','an','and','or','of','for','to','in','on','is','are','do','does','you','i','me','my','we','it','that','this','have','has','want','need','like','about','with','can','could','would','please','course','courses','class','classes','looking','learn','study','tell','show','give','any','some','what','which','how','much','there','your',
			'في','من','على','عن','الى','هل','ما','ماذا','كيف','هذا','هذه','اريد','ابحث','عندكم','لديكم','دورة','دوره','دورات','كورس','كورسات','ال','و','او','مع','التي','الذي','يوجد','ممكن','لو','سمحت',
		);

		$words = preg_split( '/[^\p{L}\p{N}+#]+/u', mb_strtolower( $norm ), -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();

		foreach ( (array) $words as $w ) {
			if ( mb_strlen( $w ) < 2 || in_array( $w, $stop, true ) ) {
				continue;
			}

			$out[] = $w;
		}

		return array_values( array_unique( array_slice( $out, 0, 8 ) ) );
	}

	/**
	 * Ask the model to classify, when patterns could not.
	 *
	 * Constrained hard: it may return one word from a closed list, and anything
	 * else is discarded. A classifier allowed to invent a label produces
	 * branches nothing handles.
	 *
	 * @return string Intent, or '' when unusable.
	 */
	private static function via_model( string $text, string $language ): string {
		if ( ! EduAI_Enquiry_Model::available() ) {
			return '';
		}

		$allowed = implode( ', ', array_diff( self::INTENTS, array( 'unknown', 'lead' ) ) );

		$system = 'You label a website visitor\'s message with exactly one intent. '
			. 'Reply with one word from this list and nothing else: ' . $allowed . '. '
			. 'If none fits, reply exactly: unknown. '
			. 'The visitor may write in English or Arabic.';

		/*
		 * 200 tokens for a ONE WORD answer, which looks absurd and is measured.
		 *
		 * gpt-oss reasons before it replies and bills the reasoning to the same
		 * budget. At 8 tokens it returned nothing at all; at 64, still nothing;
		 * at 200 it answered "discover" in 543 ms. A budget sized to the visible
		 * answer produces an empty reply and an error, not a cheap one.
		 *
		 * That is also the strongest argument for the pattern pass above: an
		 * escalated classification costs ~200 tokens against a site-wide
		 * ceiling of 8,000 a minute, so roughly forty ambiguous messages a
		 * minute would exhaust the whole platform BEFORE anyone is answered.
		 */
		$out = EduAI_Enquiry_Model::ask( $system, $text, 200, 0.0 );

		if ( is_wp_error( $out ) ) {
			return '';
		}

		$word = strtolower( trim( preg_replace( '/[^a-z_]/i', '', $out ) ) );

		return in_array( $word, self::INTENTS, true ) && 'lead' !== $word ? $word : '';
	}

	/**
	 * Which language should the reply be in?
	 *
	 * AN EXPLICIT CHOICE OUTRANKS THE ALPHABET, AND THAT IS THE WHOLE FIX.
	 *
	 * This used to detect the script and use the visitor's choice only as a
	 * fallback when the message held no letters at all. Front-end found what
	 * that costs, and it is worse here than it would be almost anywhere else:
	 * every course on this site has an ENGLISH title, so the single most likely
	 * thing an Arabic-speaking visitor types is the name of the course they
	 * want — and typing it silently threw them back into English.
	 *
	 * But detection is still right when nobody has chosen, and a visitor who
	 * opens in English and writes a paragraph of Arabic should be answered in
	 * Arabic without hunting for a toggle. So the rule is not "choice always
	 * wins"; it is:
	 *
	 *   no choice made      -> detect
	 *   choice matches      -> that
	 *   choice contradicted -> keep the choice, UNLESS the message is composed
	 *                          in the other language rather than merely
	 *                          containing words from it
	 *
	 * FUNCTION WORDS ARE THE TEST. Content words travel — a proper noun, a
	 * product name, a technical term — and their script says nothing about the
	 * language somebody is writing in. Function words do not travel: "how much
	 * is it" is composed in English, "Machine Learning" is a name that happens
	 * to be Latin. That distinction is the difference between honouring a
	 * choice and overriding it.
	 *
	 * @param string $text      What they typed.
	 * @param string $fallback  Session language, when nothing else decides.
	 * @param string $requested Explicit choice from the client, or ''.
	 */
	public static function language( string $text, string $fallback = 'en', string $requested = '' ): string {
		$detected = self::script( $text );
		$chosen   = in_array( $requested, array( 'en', 'ar' ), true ) ? $requested : '';

		if ( '' === $chosen ) {
			return '' !== $detected ? $detected : ( in_array( $fallback, array( 'en', 'ar' ), true ) ? $fallback : 'en' );
		}

		if ( '' === $detected || $detected === $chosen ) {
			return $chosen;
		}

		// The message is in the other script. Only a sentence composed in that
		// language overrides a deliberate choice; a borrowed name does not.
		return self::composed_in( $text, $detected ) ? $detected : $chosen;
	}

	/**
	 * Which script dominates, or '' when there are no letters to judge.
	 */
	private static function script( string $text ): string {
		$arabic = preg_match_all( '/[\x{0600}-\x{06FF}]/u', $text );
		$latin  = preg_match_all( '/[A-Za-z]/u', $text );

		if ( $arabic && $arabic >= $latin ) {
			return 'ar';
		}

		return $latin ? 'en' : '';
	}

	/**
	 * Is this written IN that language, rather than merely containing its words?
	 *
	 * Two ways to qualify, either alone sufficient: a function word, which is
	 * what people cannot avoid when composing a sentence; or enough words that
	 * it is a sentence whatever it contains. A one or two word proper noun
	 * clears neither.
	 *
	 * @param string $text     Message.
	 * @param string $language Candidate language.
	 */
	private static function composed_in( string $text, string $language ): bool {
		static $function_words = array(
			'en' => array( 'the', 'a', 'an', 'is', 'are', 'was', 'do', 'does', 'did', 'how', 'what', 'when',
				'where', 'which', 'who', 'why', 'can', 'could', 'would', 'should', 'i', 'you', 'we', 'me',
				'my', 'your', 'it', 'this', 'that', 'for', 'to', 'of', 'in', 'on', 'and', 'or', 'with',
				'have', 'has', 'want', 'need', 'please', 'thanks', 'there', 'any', 'much', 'many' ),
			'ar' => array( 'هل', 'ما', 'ماذا', 'كيف', 'متي', 'اين', 'من', 'في', 'علي', 'الي', 'عن', 'مع',
				'انا', 'انت', 'نحن', 'هذا', 'هذه', 'ذلك', 'التي', 'الذي', 'او', 'و', 'لو', 'عندكم',
				'لديكم', 'اريد', 'ابحث', 'يوجد', 'ممكن', 'كم', 'شكرا', 'الان' ),
		);

		$norm  = self::normalise( $text );
		$words = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $norm ), -1, PREG_SPLIT_NO_EMPTY );
		$words = (array) $words;

		if ( count( $words ) >= 4 ) {
			return true;
		}

		foreach ( $words as $w ) {
			if ( in_array( $w, $function_words[ $language ] ?? array(), true ) ) {
				return true;
			}
		}

		return false;
	}
}

