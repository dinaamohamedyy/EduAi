<?php
/**
 * PrepareME end to end against a real model — upload → generate → answer → mark.
 *
 *   docker compose --profile tools run --rm cli \
 *     wp eval-file /scripts/roundtrip.php --allow-root
 *
 * Everything else in scripts/ stubs the model at `pre_http_request`, which is
 * right for testing our own code and useless for the one question left: does a
 * real model, given a real deck, return something this pipeline can use? The
 * renderer is proven against fixtures in both directions. Generation never has
 * been.
 *
 * **This one spends credit.** One deck, one generation, one marking pass, no
 * loops. Token counts and wall-clock are reported because a per-exam figure is
 * something the owner needs before students use it.
 *
 * The deck: put a .pptx at scripts/roundtrip-deck.pptx (mounted at
 * /scripts inside the container). Without one the run falls back to pasted
 * text and says so — a fallback that announces itself, rather than one that
 * quietly tests something easier than the real thing.
 *
 * What is being established, in the manager's order:
 *   1. schema-valid JSON first time, or how often the one repair retry fires
 *   2. band mix matches the requested length
 *   3. answer_index is genuinely 0-based against the rendered options
 *   4. a real marking pass respects the docs/06 §5.2 rules
 *   5. wall-clock and tokens
 *
 * (3) is the one to watch. Everything else in the chain is enforced by code;
 * 0-based indexing is a convention the model has to infer from the prompt, and
 * a 0-vs-1 slip mis-marks every MCQ while every "a mark came back" assertion
 * still passes.
 *
 * @package Scholaris
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run me through wp-cli, not php.\n" );
	exit( 1 );
}

$GLOBALS['rt_calls']  = 0;
$GLOBALS['rt_in']     = 0;
$GLOBALS['rt_out']    = 0;
$GLOBALS['rt_bodies'] = array();

/* Count real calls and tally tokens without interfering: returning $pre
 * unchanged lets the request proceed normally. The call count is how we learn
 * whether the repair retry fired — one call means the model got it right
 * first time. */
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false !== strpos( $url, '/v1/' ) ) {
			$GLOBALS['rt_calls']++;
		}
		return $pre;
	},
	1,
	3
);

/* Tokens come from http_api_debug, which core fires for real requests but
 * NOT for ones pre-empted at pre_http_request — verified both ways on this
 * stack. So a dry run always reports 0 tokens and that is an artefact of the
 * stub, not a finding. The real path was confirmed separately with a bogus
 * key: the action fires, and a 401 body simply carries no usage key. */
add_action(
	'http_api_debug',
	static function ( $response, $context, $class, $args, $url ) {
		if ( false === strpos( (string) $url, '/v1/' ) || is_wp_error( $response ) ) {
			return;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return;
		}
		$u = $body['usage'] ?? array();
		$GLOBALS['rt_in']  += (int) ( $u['prompt_tokens'] ?? $u['input_tokens'] ?? 0 );
		$GLOBALS['rt_out'] += (int) ( $u['completion_tokens'] ?? $u['output_tokens'] ?? 0 );
	},
	10,
	5
);

/* A dry run, so the paid run is never the first execution. RT_STUB=1 answers
 * every model call from the pinned fixture: the plumbing below — timing, call
 * counting, token tallying, band comparison, the answer_index dump and the
 * §5.2 spot checks — all execute, and a typo in any of them shows up for free
 * rather than after spending. It proves nothing about the model, which is why
 * it announces itself loudly. */
$stub = '1' === (string) getenv( 'RT_STUB' );

if ( $stub ) {
	putenv( 'GROQ_API_KEY=stub-not-used' );
	putenv( 'ANTHROPIC_API_KEY=stub-not-used' );

	add_filter(
		'pre_http_request',
		static function ( $pre, $args, $url ) {
			if ( false === strpos( $url, '/v1/' ) ) {
				return $pre;
			}

			$fixture = EduAI_Exams::fixture();
			$body    = false !== strpos( (string) ( $args['body'] ?? '' ), 'award' )
				? wp_json_encode( array(
					'results' => array_values( array_map(
						static fn( $q ) => array(
							'id'      => $q['id'],
							'awarded' => $q['marks'],
							'of'      => $q['marks'],
							'comment' => 'Stubbed full credit.',
						),
						array_filter( $fixture['questions'], static fn( $q ) => 'short' === $q['type'] )
					) ),
				) )
				: wp_json_encode( array(
					'schema_version' => 1,
					'title'          => $fixture['title'],
					'questions'      => $fixture['questions'],
				) );

			$format = EduAI_Claude::provider()['format'];

			return array(
				'headers'  => array(),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
				'body'     => 'openai' === $format
					? wp_json_encode( array(
						'model'   => 'stub',
						'choices' => array( array( 'message' => array( 'content' => $body ) ) ),
						'usage'   => array( 'prompt_tokens' => 1234, 'completion_tokens' => 567 ),
					) )
					: wp_json_encode( array(
						'model'       => 'stub',
						'content'     => array( array( 'type' => 'text', 'text' => $body ) ),
						'stop_reason' => 'end_turn',
						'usage'       => array( 'input_tokens' => 1234, 'output_tokens' => 567 ),
					) ),
			);
		},
		5,
		3
	);
}

$provider = EduAI_Claude::provider();
$key      = EduAI_Settings::api_key( EduAI_Claude::provider_id() );

if ( ! $key ) {
	fwrite( STDERR, "No API key configured for {$provider['label']} — this test needs a real one.\n" );
	fwrite( STDERR, "For a free dry run of the harness itself: RT_STUB=1\n" );
	exit( 1 );
}

printf( "provider: %s%s\n", $provider['label'], $stub ? '  *** STUBBED — proves nothing about the model ***' : '' );

/* ---- the source material ------------------------------------------------- */

$deck    = '/scripts/roundtrip-deck.pptx';
$content = array();
$label   = '';

/* Refuse rather than downgrade. `./scripts` is mounted into the compose `cli`
 * service and NOT into `wordpress`, so running this through
 * `docker exec scholaris-wp` finds no /scripts at all — and the fallback below
 * would then quietly test pasted text with a deck sitting on disk two
 * directories away. That is the exact failure the fallback announcement exists
 * to prevent, so the missing-mount case has to be a hard stop: a deck that is
 * absent is a choice, a deck that is unreachable is a mistake, and the two must
 * not look alike. */
if ( ! is_dir( '/scripts' ) ) {
	fwrite( STDERR, "/scripts is not mounted here, so the deck cannot be found even if it exists.\n" );
	fwrite( STDERR, "Run me through the compose cli service, which mounts ./scripts:\n" );
	fwrite( STDERR, "  docker compose --profile tools run --rm cli wp eval-file /scripts/roundtrip.php --allow-root\n" );
	exit( 1 );
}

if ( is_readable( $deck ) ) {
	/* Second argument is the extension, not the filename — passing the latter
	 * returns an empty string rather than erroring. */
	$text = EduAI_PDF::extract( $deck, 'pptx' );

	if ( is_wp_error( $text ) || '' === trim( (string) $text ) ) {
		fwrite( STDERR, 'could not read the deck: ' . ( is_wp_error( $text ) ? $text->get_error_message() : 'empty' ) . "\n" );
		exit( 1 );
	}

	$label   = 'roundtrip-deck.pptx';
	$content = array( array( 'type' => 'text', 'text' => $text ) );
	printf( "source:   %s, %d characters extracted\n", $label, strlen( $text ) );

	/* scripts/make-lecture-fixture.php files slide 2's speaker note as
	 * notesSlide1.xml — which is what PowerPoint actually writes when only
	 * slide 2 carries a note. Pairing slideN with notesSlideN attaches it to
	 * slide 1 or drops it; only the rels lookup gets it right.
	 *
	 * The note asserts a fact that appears on no slide — RuBisCO's oxygenase
	 * activity — so it is a tracer. "Notes are present" is checked here;
	 * whether the model actually *used* them is checked after generation,
	 * because an exam built from the slides alone would still look fine. */
	$tracer = 'oxygenase';
	printf(
		"notes tracer (%s) in extraction: %s\n",
		$tracer,
		false !== stripos( $text, $tracer ) ? 'present' : 'ABSENT — the notesSlide rels mapping is wrong'
	);
	$GLOBALS['rt_tracer'] = $tracer;
} else {
	$label = 'pasted text (no deck at scripts/roundtrip-deck.pptx)';
	$text  = 'Fourier series decompose a periodic function into a sum of sines and cosines. '
		. 'The coefficient a0 is the mean value over one period; an and bn describe the variation '
		. 'about it. For an even function on a symmetric interval every bn vanishes. The Dirichlet '
		. 'conditions must hold for convergence: absolutely integrable over a period, finitely many '
		. 'extrema, finitely many finite discontinuities. At a jump discontinuity the series converges '
		. 'to the midpoint, and the overshoot near the jump is the Gibbs phenomenon, which does not '
		. 'diminish as more terms are added. The Fast Fourier Transform reduces the cost of a discrete '
		. 'transform from O(n^2) to O(n log n).';
	$content = array( array( 'type' => 'text', 'text' => $text ) );
	printf( "source:   %s\n", $label );
	printf( "NOTE: falling back to pasted text. The .pptx extraction path is NOT covered by this run.\n" );
}

/* ---- generate ------------------------------------------------------------ */

$count = 10;
$user  = get_user_by( 'login', 'rt-student' );

if ( ! $user ) {
	$id   = wp_insert_user( array(
		'user_login' => 'rt-student',
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => 'rt-student@example.invalid',
		'role'       => get_role( 'student' ) ? 'student' : 'subscriber',
	) );
	$user = get_user_by( 'id', $id );
}

wp_set_current_user( $user->ID );

printf( "\ngenerating %d questions...\n", $count );

$t0   = microtime( true );
$exam = EduAI_Exams::generate( $user->ID, $content, $label, 'roundtrip-' . time(), $count );
$gen  = microtime( true ) - $t0;

if ( is_wp_error( $exam ) ) {
	printf( "\nGENERATION FAILED after %.1fs and %d call(s): %s\n", $gen, $GLOBALS['rt_calls'], $exam->get_error_message() );
	printf( "tokens: %d in, %d out\n", $GLOBALS['rt_in'], $GLOBALS['rt_out'] );
	exit( 1 );
}

$gen_calls = $GLOBALS['rt_calls'];

printf( "generated in %.1fs, %d model call(s), %d in / %d out tokens\n\n", $gen, $gen_calls, $GLOBALS['rt_in'], $GLOBALS['rt_out'] );

printf( "1. schema-valid first time: %s\n", 1 === $gen_calls ? 'YES' : 'NO — the repair retry fired' );

/* ---- band mix ------------------------------------------------------------ */

$want = EduAI_Exams::band_mix( $count );
$got  = array( 'easy' => 0, 'medium' => 0, 'hard' => 0 );

foreach ( $exam['questions'] as $q ) {
	if ( isset( $got[ $q['band'] ] ) ) {
		$got[ $q['band'] ]++;
	}
}

printf(
	"2. band mix: asked %d/%d/%d, got %d/%d/%d — %s\n",
	$want['easy'], $want['medium'], $want['hard'],
	$got['easy'], $got['medium'], $got['hard'],
	$want === $got ? 'match' : 'MISMATCH'
);

/* ---- were the speaker notes actually used? ------------------------------- */

if ( ! empty( $GLOBALS['rt_tracer'] ) ) {
	$paper = wp_json_encode( $exam['questions'] );
	printf(
		"2b. speaker notes reached the exam: %s\n",
		false !== stripos( $paper, $GLOBALS['rt_tracer'] )
			? 'YES — a question or explanation uses a fact stated only in the notes'
			: 'not evidenced — the paper cites nothing that appears only in the notes'
	);
	printf( "    (extraction carrying the tracer is proven above; this is the stronger claim)\n" );
}

/* ---- the dangerous number ------------------------------------------------ */

printf( "\n3. answer_index, 0-based against the rendered options:\n" );

$mcqs = array_values( array_filter( $exam['questions'], static fn( $q ) => 'mcq' === $q['type'] ) );

foreach ( $mcqs as $q ) {
	$idx     = (int) $q['answer_index'];
	$options = $q['options'];
	$chosen  = $options[ $idx ] ?? '<OUT OF RANGE>';

	printf( "\n   q%d  answer_index=%d of %d options\n", $q['id'], $idx, count( $options ) );
	foreach ( $options as $i => $opt ) {
		printf( "     [%d]%s %s\n", $i, $i === $idx ? ' <-- marked correct' : '    ', $opt );
	}
	printf( "     explanation: %s\n", $q['explanation'] );
}

/* ---- mark it, for real --------------------------------------------------- */

/* Groq's free tier caps the whole organisation at 8,000 tokens per minute.
 * Generating a 10-question paper costs ~4.3k and marking it ~2.3k, so running
 * them back to back inside one minute trips the limit — reproducibly. A real
 * student spends minutes sitting the paper between the two, so RT_PAUSE models
 * that rather than pretending the calls are simultaneous. Default 0: the
 * back-to-back case is worth being able to reproduce deliberately, because it
 * is what two concurrent students look like. */
$pause = (int) getenv( 'RT_PAUSE' );

if ( $pause > 0 ) {
	printf( "\n   pausing %ds before marking (free-tier TPM window)...\n", $pause );
	sleep( $pause );
}

printf( "\n4. marking a real attempt...\n" );

$answers = array();
foreach ( $exam['questions'] as $q ) {
	if ( 'mcq' === $q['type'] ) {
		$answers[] = array( 'id' => $q['id'], 'choice' => (int) $q['answer_index'] );  // every MCQ "right"
	} else {
		$answers[] = array( 'id' => $q['id'], 'text' => 'A full answer covering each award point in the mark scheme.' );
	}
}

$before = $GLOBALS['rt_calls'];
$t1     = microtime( true );
$graded = EduAI_Exams::grade( $exam, $answers );
$mark   = microtime( true ) - $t1;

if ( is_wp_error( $graded ) ) {
	printf( "MARKING FAILED after %.1fs: %s\n", $mark, $graded->get_error_message() );
	exit( 1 );
}

printf(
	"   marked in %.1fs, %d model call(s), score %s/%s (%s%%)\n",
	$mark,
	$GLOBALS['rt_calls'] - $before,
	$graded['score'],
	$graded['total'],
	$graded['percent']
);

/* Answering every MCQ with its own answer_index must score full MCQ marks. If
 * the model returned 1-based indices this is where it shows: the paper marks
 * its own key wrong. */
$mcq_awarded = 0.0;
$mcq_of      = 0.0;
foreach ( $graded['results'] as $r ) {
	if ( 'mcq' === $r['type'] ) {
		$mcq_awarded += (float) $r['awarded'];
		$mcq_of      += (float) $r['of'];
	}
}

printf(
	"   MCQ, answered with the paper's own key: %s/%s — %s\n",
	$mcq_awarded,
	$mcq_of,
	$mcq_awarded === $mcq_of ? 'self-consistent' : 'INCONSISTENT: the paper disagrees with its own answer key'
);

/* §5.2 spot checks against a real grading response rather than an injected one. */
$bad = array();
foreach ( $graded['results'] as $r ) {
	if ( (float) $r['awarded'] < 0 || (float) $r['awarded'] > (float) $r['of'] ) {
		$bad[] = 'q' . $r['id'] . ' awarded ' . $r['awarded'] . '/' . $r['of'];
	}
	if ( 'short' === $r['type'] && null !== $r['correct'] ) {
		$bad[] = 'q' . $r['id'] . ' correct=' . var_export( $r['correct'], true );
	}
	if ( 'short' === $r['type'] && '' === trim( (string) $r['comment'] ) ) {
		$bad[] = 'q' . $r['id'] . ' empty comment';
	}
}

printf( "   §5.2 against a real grade: %s\n", $bad ? 'VIOLATIONS — ' . implode( '; ', $bad ) : 'all rules hold' );

/* ---- the bill ------------------------------------------------------------ */

printf(
	"\n5. cost of one exam: %.1fs generate + %.1fs mark = %.1fs, %d calls, %d in / %d out tokens\n",
	$gen,
	$mark,
	$gen + $mark,
	$GLOBALS['rt_calls'],
	$GLOBALS['rt_in'],
	$GLOBALS['rt_out']
);

/* The generated paper, so it can be studied without spending again. */
printf( "\n----- GENERATED EXAM (JSON) -----\n%s\n----- END -----\n", wp_json_encode( $exam['questions'] ) );
