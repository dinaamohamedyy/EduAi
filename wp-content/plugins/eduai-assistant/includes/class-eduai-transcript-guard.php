<?php
/**
 * Is a machine-heard transcript safe to teach from?
 *
 * Back-end owns the pipe — `EduAI_Transcript` posts the file to Groq's
 * `whisper-large-v3-turbo` and caches what comes back. This owns the question
 * of whether what comes back should reach the index at all, and what to feed
 * the transcriber so that it comes back right.
 *
 * The failure that matters is not a crash. Whisper does not return an error on
 * silence: it returns CONFIDENT TEXT, because low-signal audio in its training
 * data was subtitled with boilerplate. A silent video measured on this stack
 * came back as " ." and the better-known form is worse — "Thank you for
 * watching", "Subtitles by the Amara.org community". Every character valid,
 * nothing downstream suspicious, and the assistant then answers questions from
 * it in fluent prose.
 *
 * That is the same class as `EduAI_Lessons::looks_legible()`, on a new input.
 * The rule is the same one: refuse, and refuse with words the owner can act on.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Quality gate and vocabulary biasing for transcripts.
 */
class EduAI_Transcript_Guard {

	/**
	 * What Whisper writes when it hears nothing.
	 *
	 * Not a blocklist of banned words — a transcript may legitimately contain
	 * "thank you". These match only when such a phrase is essentially the WHOLE
	 * transcript, which is checked by the caller below.
	 */
	private const BOILERPLATE = array(
		'/thank(s| you)( (so|very) much)? for watching/i',
		'/subtitles? (by|created by|provided by)/i',
		'/amara\.org/i',
		'/please (like|subscribe|comment)/i',
		'/transcription by/i',
		'/\bcaptions? by\b/i',
		'/^\s*you\s*$/i',
		'/^\s*bye[.!]?\s*$/i',
	);

	/**
	 * Slowest plausible words per minute of MEDIA before this reads as a
	 * transcript of something other than the whole recording.
	 *
	 * Lecturers speak at roughly 120-160 wpm. This sits about five times below
	 * that, which is deliberate: it is set to catch the gross failure — a
	 * transcript covering a fraction of its recording — not to find the
	 * boundary between a talkative and a quiet lecture. A code walkthrough with
	 * long silences should pass; a fifty-minute lecture that stopped at minute
	 * four should not.
	 */
	private const MIN_WPM = 25;

	/**
	 * Shortest recording whose words-per-minute is a rate rather than a rounding
	 * artefact.
	 *
	 * This was 60, on the reasoning that anything shorter was not a lecture. That
	 * reasoning quietly assumed the word floor above was covering the short end,
	 * and once that floor dropped to catching degenerate output, nothing was: a
	 * forty-second clip transcribing to six words would have passed both.
	 *
	 * Five seconds against a 25 wpm threshold asks for two words, which any
	 * fragment of real speech clears. The noise this was raised against lives at
	 * durations where one word swings the rate, and that is below here.
	 */
	private const MIN_MEASURABLE = 5.0;

	/**
	 * Share of the recording the transcriber must have got through.
	 *
	 * A complete run reports the file's own length, so anything materially
	 * below 1.0 is a transcriber that stopped early rather than a quiet
	 * lecture. Set loose because the two durations are read off different
	 * things and are allowed to disagree slightly; it is set to catch a
	 * transcript that covers a fraction of its recording, which is what
	 * truncation looks like.
	 */
	/**
	 * Words per window when looking for a repeated phrase, and how far the
	 * window moves between looks.
	 *
	 * Two hundred words is long enough that ordinary speech is varied within it
	 * and short enough that a loop filling a couple of minutes cannot be
	 * averaged away by the good speech either side. The step is half the window
	 * so nothing hides across a boundary.
	 */
	private const LOOP_WINDOW = 200;
	private const LOOP_STEP   = 100;

	/**
	 * Share of distinct words below which a stretch reads as padding.
	 *
	 * Ordinary speech runs above 0.5 in a window this size. This sits four
	 * times below that, because the job is catching " the the the", not
	 * arbitrating between a repetitive lecturer and a varied one.
	 */
	private const MIN_VARIETY = 0.12;

	private const MIN_COVERAGE = 0.9;

	/**
	 * Seconds of shortfall to forgive before the ratio is believed.
	 *
	 * A container's declared length and its audio stream's differ by a second
	 * or two often enough, and on a six second clip that is a third of the
	 * ratio. Truncation does not hide inside thirty seconds.
	 */
	private const COVERAGE_SLACK = 30.0;

	/**
	 * Characters of vocabulary the decoding prompt may carry.
	 *
	 * Whisper reads about 224 tokens of prompt and drops the rest without
	 * complaint. 600 leaves room for the sentence prompt_for() wraps the terms
	 * in and still stays inside the window.
	 */
	private const PROMPT_BUDGET = 600;

	/**
	 * Is this transcript worth indexing?
	 *
	 * @param string   $text    What the transcriber returned.
	 * @param float|null $heard    Seconds of audio the transcriber processed (Groq's own figure).
	 * @param float|null $recorded Seconds the recording actually runs, read off the file.
	 * @return true|WP_Error
	 */
	public static function usable( string $text, ?float $heard = null, ?float $recorded = null ) {
		$clean = trim( wp_strip_all_tags( $text ) );

		// Words, not characters. " . " has length; it has no content.
		$words = preg_split( '/\s+/u', preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $clean ) ?? $clean, -1, PREG_SPLIT_NO_EMPTY );
		$count = count( $words );

		/*
		 * Degenerate output only. NOT a brevity test.
		 *
		 * This was 20, and it refused the Wikimedia clip TM proved the pipeline
		 * against: 14 words over 6 seconds, which is 140 words per minute —
		 * ordinary lecture speed and not sparse in the slightest. The floor was
		 * doing two jobs and only one of them is its own: catching " ." and
		 * " The", which it still does, and standing in for a completeness check
		 * it has no information to make.
		 *
		 * Completeness is words per minute of MEDIA, below, and that is the only
		 * measure that can tell a short clip from a truncated lecture. An
		 * absolute count cannot: 200 words is a healthy minute and a catastrophic
		 * hour, and nothing in the number says which.
		 */
		if ( $count < 5 ) {
			return new WP_Error(
				'eduai_transcript_empty',
				__( 'Nothing audible was transcribed from that recording — it came back with almost no words. Check the video actually has speech on its audio track.', 'eduai' ),
				array( 'words' => $count )
			);
		}

		// Boilerplate is only damning when it IS the transcript. A real lecture
		// that happens to end with "thank you for watching" is fine.
		$boiler = 0;
		foreach ( self::BOILERPLATE as $pattern ) {
			$boiler += preg_match_all( $pattern, $clean );
		}

		if ( $boiler > 0 && $count < 60 ) {
			return new WP_Error(
				'eduai_transcript_boilerplate',
				__( 'That recording transcribed to subtitle boilerplate rather than speech, which is what happens when the audio is silent or too quiet. Nothing was indexed.', 'eduai' ),
				array( 'words' => $count )
			);
		}

		/*
		 * Whisper loops on low signal, repeating one phrase to fill the
		 * duration, and a unique-word ratio catches that where length cannot.
		 *
		 * Measured over a WINDOW rather than the whole transcript, for two
		 * reasons that turned out to be one reason. A global ratio falls as a
		 * transcript grows - vocabulary does not keep up with length, so a
		 * three hour lecture scores lower than a three minute one purely for
		 * being long, and a fixed threshold refuses it for being thorough. And
		 * a loop that fills five minutes of a fifty minute recording is diluted
		 * to nothing by the forty-five good minutes around it, so the global
		 * ratio misses the very failure it is here for.
		 *
		 * The window makes the measure independent of length, which is the same
		 * correction the word floor needed: a threshold that means one thing at
		 * one size and another at another is not a threshold.
		 */
		if ( self::looping( $words ) ) {
			$unique = count( array_unique( array_map( 'mb_strtolower', $words ) ) );
			return new WP_Error(
				'eduai_transcript_repetitive',
				__( 'That transcript repeats the same few words for its whole length, which is what a transcriber produces from unintelligible audio. Nothing was indexed.', 'eduai' ),
				array( 'unique_ratio' => round( $unique / $count, 3 ) )
			);
		}

		/*
		 * COMPLETENESS. Did the transcriber hear the whole recording?
		 *
		 * This compares two durations that come from two different places, and
		 * that is the entire point of it. `$heard` is Groq's own `duration` -
		 * how much audio Whisper processed. `$recorded` is the file's, read off
		 * the media itself.
		 *
		 * The rate below CANNOT answer this question, however good a rate it
		 * is. If Whisper stops at minute four of a fifty minute lecture it
		 * reports a four minute duration: numerator and denominator shrink
		 * together, words per minute stays perfect, and the check passes on
		 * exactly the failure it was built to catch. A denominator produced by
		 * the same process as the numerator is not evidence about that process.
		 * That is the shape that once let a coverage figure read 112% on a
		 * half-indexed document.
		 *
		 * Two independent durations disagreeing is contiguity, not volume - the
		 * audio form of chunk indices running 0..n-1. And it says how much is
		 * missing, not merely that something is.
		 */
		if ( self::completeness_checked( $heard, $recorded ) ) {
			$missing = $recorded - $heard;

			/*
			 * Both conditions, because either alone misfires. A container's
			 * declared length and its audio stream's can differ by a second or
			 * two with nothing wrong, which the ratio alone would call
			 * truncation on a short clip; and a proportionally small shortfall
			 * on a long recording is still minutes of lost lecture, which the
			 * absolute gap alone would let through on a ratio that looks fine.
			 */
			if ( $heard < ( self::MIN_COVERAGE * $recorded ) && $missing > self::COVERAGE_SLACK ) {
				return new WP_Error(
					'eduai_transcript_truncated',
					sprintf(
						/* translators: 1: length transcribed, worded 2: length of the recording, worded */
						__( 'The transcriber only got through %1$s of a recording that runs %2$s, so the rest of the lecture is missing from the transcript. Nothing was indexed. This is a transcription failure rather than a problem with your video - it is worth trying again.', 'eduai' ),
						self::spoken_length( $heard ),
						self::spoken_length( $recorded )
					),
					array(
						'heard'    => round( $heard, 1 ),
						'recorded' => round( $recorded, 1 ),
						'coverage' => round( $heard / $recorded, 3 ),
					)
				);
			}
		}

		/*
		 * RATE. Is what it heard speech, or is it noise?
		 *
		 * A different question with a different denominator: the span actually
		 * decoded, because that is the audio these words are supposed to
		 * account for. Falls back to the file's length when Groq gave us none,
		 * which can only make this stricter.
		 *
		 * WHEN `$recorded` IS MISSING, THIS DOES NOT STAND IN FOR COMPLETENESS.
		 * It runs, on `$heard`, and answers its own question honestly - a span
		 * of audio holding almost no speech is a real finding about that span.
		 * What it must not do is speak as though the truncation check ran, and
		 * that is a property of the WORDING above, not of this condition: the
		 * two are one decision and separating them is how a message ends up
		 * asserting a check that never executed. Completeness stays unclaimed,
		 * and completeness_checked() is what says so out loud.
		 */
		$span = $heard ?? $recorded;

		if ( null !== $span && $span >= self::MIN_MEASURABLE ) {
			$wpm = $count / ( $span / 60 );

			if ( $wpm < self::MIN_WPM ) {
				return new WP_Error(
					'eduai_transcript_short',
					sprintf(
						/*
						 * Says the audio was quiet, NOT that the transcript is
						 * short of it. The old wording - "most of it is missing
						 * from the transcript" - was a truncation claim, and
						 * this fires on a span Whisper reported about itself,
						 * in a branch that runs whether or not the truncation
						 * check ran at all. Two different failures needing two
						 * different things from the owner: re-record, or
						 * transcribe again.
						 *
						 * translators: 1: number of words 2: length of the audio, already worded
						 */
						__( 'Only %1$d words came back from %2$s of audio. That is far less speech than a recording that long holds, so most of it was silent or too indistinct to transcribe. Nothing was indexed — check the video has usable audio on it.', 'eduai' ),
						$count,
						self::spoken_length( $span )
					),
					array( 'wpm' => round( $wpm, 1 ), 'words' => $count, 'seconds' => $span )
				);
			}
		}

		return true;
	}

	/**
	 * Does any stretch of this transcript repeat one phrase to fill time?
	 *
	 * Windowed, and overlapping, so a loop cannot hide by straddling a
	 * boundary. Short transcripts are judged whole - there is nothing to slide
	 * across and a genuinely short clip has no room to loop in.
	 *
	 * @param string[] $words Transcript words.
	 */
	private static function looping( array $words ): bool {
		$count = count( $words );

		if ( $count < self::LOOP_WINDOW ) {
			// Too short for a window, and too short to be padding, below 60.
			return $count >= 60 && self::variety( $words ) < self::MIN_VARIETY;
		}

		for ( $at = 0; $at + self::LOOP_WINDOW <= $count; $at += self::LOOP_STEP ) {
			if ( self::variety( array_slice( $words, $at, self::LOOP_WINDOW ) ) < self::MIN_VARIETY ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Share of a run of words that are distinct from one another.
	 *
	 * @param string[] $words Words.
	 */
	private static function variety( array $words ): float {
		$count = count( $words );

		if ( 0 === $count ) {
			return 1.0;
		}

		return count( array_unique( array_map( 'mb_strtolower', $words ) ) ) / $count;
	}

	/**
	 * Was completeness actually checkable?
	 *
	 * Duration is missing on some uploads, and then nothing can detect a
	 * truncated transcript. Same rule as page ranges on a deck whose blocks do
	 * not match its page count: make the claim you can support and say plainly
	 * where you cannot make one, rather than implying a check that did not run.
	 *
	 * @param float|null $heard    Seconds the transcriber processed.
	 * @param float|null $recorded Seconds the recording actually runs.
	 */
	public static function completeness_checked( ?float $heard, ?float $recorded ): bool {
		return null !== $heard && null !== $recorded && $recorded >= self::MIN_MEASURABLE;
	}

	/**
	 * A duration the owner reads without doing arithmetic.
	 *
	 * Rounding seconds to minutes told a forty-second clip it held "0 minutes of
	 * recording", which is not a sentence anyone can act on.
	 *
	 * @param float $seconds Media duration.
	 */
	private static function spoken_length( float $seconds ): string {
		if ( $seconds < 90 ) {
			/* translators: %d: seconds */
			return sprintf( _n( '%d second', '%d seconds', (int) round( $seconds ), 'eduai' ), (int) round( $seconds ) );
		}

		$minutes = (int) round( $seconds / 60 );

		/* translators: %d: minutes */
		return sprintf( _n( '%d minute', '%d minutes', $minutes, 'eduai' ), $minutes );
	}

	/**
	 * Words the transcriber should expect to hear.
	 *
	 * Whisper decodes acoustically, so a term it has no reason to expect comes
	 * back as the nearest thing it does expect: "Ogg Vorbis" was measured on
	 * this stack arriving as "org-forbus", with every other word in the
	 * sentence correct. On a machine-learning lecture that is "gradient
	 * descent" and "convolution" arriving mangled but plausible, indexed as
	 * fact, then summarised and quizzed — and neither the student nor the owner
	 * can notice, because the sentence around it reads perfectly.
	 *
	 * Groq's transcription endpoint takes a `prompt` that biases decoding
	 * toward supplied vocabulary. The material for these lessons is already
	 * indexed, so the terms are there for the taking.
	 *
	 * Frequent BIGRAMS, not capitalised words. The dangerous vocabulary in a
	 * technical lecture is lower-case and multi-word — "gradient descent",
	 * "least squares", "squared residuals" — so a capitalisation heuristic
	 * collects the proper nouns and misses exactly the terms that matter.
	 *
	 * @param int $post_id Lesson or material the recording belongs to.
	 * @param int $max     Characters. Whisper's prompt window is about 224
	 *                     tokens, so this stays well inside it. An over-long
	 *                     prompt is silently truncated, and a truncated bias
	 *                     list is worse than a short one because nothing says
	 *                     which half survived.
	 */
	public static function vocabulary_prompt( int $post_id, int $max = 850 ): string {
		$terms = array();

		$title = trim( wp_strip_all_tags( (string) get_the_title( $post_id ) ) );
		if ( '' !== $title ) {
			$terms[] = $title;
		}

		$source = (int) get_post_meta( $post_id, '_eduai_source_material', true );
		if ( $source && $source !== $post_id ) {
			$source_title = trim( wp_strip_all_tags( (string) get_the_title( $source ) ) );
			if ( '' !== $source_title ) {
				$terms[] = $source_title;
			}
		}

		// The post's OWN chunks first, then its source material.
		//
		// Measured, not assumed: lessons 157-159 carry _eduai_source_material=123,
		// and 123 is in the trash — so its chunks were deleted and reading only the
		// source returned nothing at all. The lesson itself is what is indexed now.
		// Both are read because they are different text: the lesson is generated
		// prose, the deck is the lecturer's own wording, and a transcriber wants the
		// lecturer's.
		$terms = array_merge( $terms, self::corpus_terms( $post_id ) );

		if ( $source && $source !== $post_id ) {
			$terms = array_merge( $terms, self::corpus_terms( $source ) );
		}

		$terms = array_values( array_unique( $terms ) );
		$out   = '';

		foreach ( $terms as $term ) {
			$next = '' === $out ? $term : $out . ', ' . $term;

			// Whole terms only. A bias list cut mid-phrase teaches the
			// transcriber half a word.
			if ( mb_strlen( $next ) > $max ) {
				break;
			}

			$out = $next;
		}

		return $out;
	}

	/**
	 * The distinctive multi-word terms this course actually uses.
	 *
	 * @param int $post_id Material whose indexed chunks to read.
	 * @return string[]
	 */
	private static function corpus_terms( int $post_id ): array {
		global $wpdb;

		$table = EduAI_Knowledge::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$text = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT GROUP_CONCAT(chunk_text SEPARATOR ' ') FROM {$table} WHERE post_id = %d", $post_id )
		);
		// phpcs:enable

		if ( '' === trim( $text ) ) {
			return array();
		}

		$stop = array(
			'the','and','for','with','that','this','which','are','was','has','its','from','into','than','then','they',
			'you','your','our','not','but','all','can','will','one','two','how','why','what','when','each','more','most',
			'some','such','only','also','over','under','between','using','used','use','see','let','get','set','way',
			'because','their','there','these','those','have','been','were','would','could','should','about','after',
			'before','above','below','same','other','both','make','made','does','doing','done','here','very','much',
		);

		$words = preg_split( '/[^a-z]+/', mb_strtolower( wp_strip_all_tags( $text ) ), -1, PREG_SPLIT_NO_EMPTY );
		$freq  = array();

		for ( $i = 0, $n = count( $words ) - 1; $i < $n; $i++ ) {
			$a = $words[ $i ];
			$b = $words[ $i + 1 ];

			if ( mb_strlen( $a ) < 4 || mb_strlen( $b ) < 4 ) {
				continue;
			}
			if ( in_array( $a, $stop, true ) || in_array( $b, $stop, true ) ) {
				continue;
			}

			$key          = $a . ' ' . $b;
			$freq[ $key ] = ( isset( $freq[ $key ] ) ? $freq[ $key ] : 0 ) + 1;
		}

		arsort( $freq );

		$out = array();
		foreach ( $freq as $term => $count ) {
			if ( $count < 2 ) {
				break;
			}
			$out[] = $term;
			if ( count( $out ) >= 40 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Plug the glossary into the seam back-end left for it.
	 *
	 * `EduAI_Transcript::prompt_for()` gathers the lesson and course titles and
	 * then exposes `eduai_transcript_prompt_terms` with the comment "where a
	 * glossary belongs". This is the glossary. Hooking rather than editing
	 * their file keeps one owner per file and means the bias list can be turned
	 * off by unhooking, without touching the transcriber.
	 */
	public static function init(): void {
		add_filter( 'eduai_transcript_prompt_terms', array( __CLASS__, 'add_glossary' ), 10, 3 );
	}

	/**
	 * Titles say what the lecture is called; these say what is said in it.
	 *
	 * A title alone biases decoding toward four or five words. The terms that
	 * actually get mangled are the ones repeated through the lecture and never
	 * in its title — "squared residuals", "partial derivative", "gradient
	 * vector" — and those are already sitting in the index for this course.
	 *
	 * @param string[] $terms         Titles gathered by prompt_for().
	 * @param int      $attachment_id Attachment being transcribed.
	 * @param int      $post_id       Post it belongs to.
	 * @return string[]
	 */
	public static function add_glossary( $terms, $attachment_id = 0, $post_id = 0 ) {
		$terms = is_array( $terms ) ? $terms : array();

		// The attachment's parent is the material when a post id was not
		// threaded through — the same fallback prompt_for() already uses.
		$subject = $post_id ? $post_id : (int) wp_get_post_parent_id( $attachment_id );

		if ( ! $subject ) {
			return $terms;
		}

		$glossary = self::corpus_terms( $subject );

		$source = (int) get_post_meta( $subject, '_eduai_source_material', true );
		if ( $source && $source !== $subject ) {
			$glossary = array_merge( $glossary, self::corpus_terms( $source ) );
		}

		// Titles first, then glossary, then a hard character budget.
		//
		// Whisper's prompt window is about 224 tokens and the provider TRUNCATES
		// silently past it — so an unbounded list does not fail, it quietly loses
		// its tail and nothing says which half survived. Trimming here, on whole
		// terms, keeps that decision ours: titles go in first because they are the
		// most reliable signal, and the least frequent bigrams fall off the end.
		$merged = array_values( array_unique( array_merge( $terms, $glossary ) ) );
		$kept   = array();
		$len    = 0;

		foreach ( $merged as $term ) {
			$len += mb_strlen( $term ) + 2;

			if ( $len > self::PROMPT_BUDGET ) {
				break;
			}

			$kept[] = $term;
		}

		return $kept;
	}

	/**
	 * Is this transcript just the decoding prompt coming back?
	 *
	 * A prompt cannot be made un-echoable. It is prior context for the decoder,
	 * which is exactly why it biases decoding at all — so on silence, with
	 * nothing acoustic to condition on, the likeliest continuation of that
	 * context is more of that context. There is no formulation that biases and
	 * cannot echo; asking for one is asking for a prior that is not a prior.
	 *
	 * What CAN change is whether the echo is recognisable, and that is where
	 * the fragility is. Matching a remembered string — "Expected topics and
	 * terms." — works until Whisper returns a different fragment, and it
	 * returned " The" on the same file for the Tech Manager. A guard calibrated
	 * on one observed output goes quiet on the next one and looks identical
	 * while doing so.
	 *
	 * So this does not look for the prompt. It asks what SHARE of the
	 * transcript's words came from the prompt at all — which is high for every
	 * fragment of an echo, whichever fragment it happens to be, and cannot be
	 * calibrated wrong by seeing only one of them.
	 *
	 * The control that makes it safe is the obvious objection: a real lecture
	 * about least squares says "least squares" constantly. It also says a
	 * hundred words that are not in the prompt — verbs, articles, the sentence
	 * around the term — so its share sits far below an echo's. That is asserted
	 * in the tests rather than assumed here.
	 *
	 * @param string $text   Candidate transcript.
	 * @param string $prompt Exact prompt that was sent with it.
	 */
	public static function is_prompt_echo( string $text, string $prompt ): bool {
		if ( '' === trim( $prompt ) ) {
			return false;
		}

		$words  = self::words( $text );
		$vocab  = array_flip( self::words( $prompt ) );

		if ( ! $words ) {
			return true;
		}

		$from_prompt = 0;
		foreach ( $words as $word ) {
			if ( isset( $vocab[ $word ] ) ) {
				++$from_prompt;
			}
		}

		$share = $from_prompt / count( $words );

		/*
		 * Two ways to be an echo, because a long one and a short one look
		 * different. A near-total overlap is an echo at any length; a shorter
		 * reply that is mostly prompt words is one too, and the length bound
		 * keeps a genuinely on-topic lecture from tripping it.
		 */
		return $share >= 0.9 || ( count( $words ) < 40 && $share >= 0.6 );
	}

	/**
	 * Lower-cased word tokens, any script.
	 *
	 * @param string $text Input.
	 * @return string[]
	 */
	private static function words( string $text ): array {
		$clean = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', wp_strip_all_tags( $text ) );

		return array_map(
			'mb_strtolower',
			preg_split( '/\s+/u', (string) $clean, -1, PREG_SPLIT_NO_EMPTY )
		);
	}
}
