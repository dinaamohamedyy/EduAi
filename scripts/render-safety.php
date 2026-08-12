<?php
/**
 * Does model output reach the page as markup? — EduAI_REST::to_html(), tested
 * against the real function.
 *
 *   docker compose --profile tools run --rm cli \
 *     wp eval-file /scripts/render-safety.php --allow-root
 *
 * `chat.js` assigns the `html` field of a chat response straight into
 * `innerHTML`, with escaped plain text only as a fallback. That is a deliberate
 * choice — the assistant's answers need headings, lists and code blocks — and it
 * means the entire safety of the most-rendered surface in the product rests on
 * what `to_html()` produces. The front end cannot check it; there is nothing on
 * that side left to escape.
 *
 * `to_html()` does three things, in this order:
 *
 *   1. esc_html() the whole string, so nothing arriving from the model or from
 *      a student is markup any more — it is text that looks like markup.
 *   2. transform the escaped text into a small set of tags this file emits.
 *   3. wp_kses() against an explicit allowlist, so anything that got through
 *      steps 1 and 2 still has to be on the list.
 *
 * WHAT MUTATION TESTING ACTUALLY SHOWED, because the obvious story was wrong.
 *
 * This file was written believing the ORDER was load-bearing and that the
 * safety rested on escaping first. Removing each step in turn says otherwise:
 *
 *   - esc_html() removed, wp_kses() left:  17/18. Every one of the eleven
 *     hostile payloads is still neutralised — by the allowlist.
 *   - wp_kses() removed, esc_html() left:  18/18. Nothing dangerous survives —
 *     escaping already handled it.
 *
 * So steps 1 and 3 are REDUNDANT, not sequential. Either alone stops the XSS,
 * and no output-based test can see one of them go missing, because the other
 * quietly covers for it. That is worth knowing before someone deletes one as
 * duplication: the redundancy is the design, and this file cannot defend it.
 * What it can do is fail if BOTH go, which is the state that actually ships a
 * vulnerability.
 *
 * The single assertion that DID move — "text that looks like markup is shown,
 * not dropped" — is the one property esc_html() uniquely provides. Without it,
 * a student asking "why does 5 < 6 && 6 > 2 matter" has the comparison eaten by
 * the allowlist and reads their own question back mangled. Not a security
 * failure; a correctness one, and the only thing distinguishing the two steps.
 *
 * Reordering — transform first, escape second — IS caught, by the "still
 * renders" assertions rather than the hostile ones: escaping after the
 * transforms escapes this file's own tags, so <strong> arrives as &lt;strong&gt;
 * and every rendering check fails at once.
 *
 * @package Scholaris
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run me through wp-cli, not php.\n" );
	exit( 1 );
}

$GLOBALS['rs_pass'] = 0;
$GLOBALS['rs_fail'] = 0;

function rs_check( string $rule, bool $ok, string $detail ): void {
	if ( $ok ) {
		$GLOBALS['rs_pass']++;
		printf( "ok    %s\n", $rule );
		return;
	}
	$GLOBALS['rs_fail']++;
	printf( "FAIL  %s\n        %s\n", $rule, $detail );
}

/* The tags to_html() is allowed to emit. Anything else in its output came from
 * the input, which is the only way markup gets there. */
const RS_ALLOWED = array( 'p', 'br', 'strong', 'em', 'ul', 'ol', 'li', 'h3', 'h4', 'code', 'pre', 'a' );

/**
 * Every real tag in a string.
 *
 * The whole test rests on this being able to tell markup from text that looks
 * like markup, and the distinction is exactly one character: a live tag opens
 * with `<`, escaped text opens with `&lt;`. So this matches `<` and nothing
 * else, and correctly escaped output yields an empty list no matter what words
 * it contains.
 *
 * The first version of this file searched for `onerror=` and `javascript:`
 * instead, and reported FIVE failures against output that was perfectly safe —
 * `<p>&lt;img src=x onerror=&quot;alert(1)&quot;&gt;</p>` contains the string
 * `onerror=` as visible text and cannot execute anything. The file even warned
 * about that trap for `<script>` and then walked into it for attributes. A
 * detector that cannot distinguish the dangerous case from the safe one is not
 * a strict test, it is a broken one, and it fails in the direction that gets it
 * deleted rather than fixed.
 *
 * @param string $html Rendered output.
 * @return string[] Lower-cased tag names, in order.
 */
function rs_tags( string $html ): array {
	preg_match_all( '/<\s*\/?\s*([a-zA-Z][a-zA-Z0-9]*)/', $html, $m );
	return array_map( 'strtolower', $m[1] );
}

/* Payloads that must come back inert. There is no per-case token any more: the
 * assertion is that the rendered output contains no tag outside the allowlist,
 * which is true of escaped text by construction and false the moment any of
 * these survives as markup. */
$rs_payloads = array(
	array( 'script tag',            '<script>alert(1)</script>' ),
	array( 'img onerror',           '<img src=x onerror="alert(1)">' ),
	array( 'iframe',                '<iframe src="//evil.test"></iframe>' ),
	array( 'inline handler on div', '<div onclick="alert(1)">text</div>' ),
	// NOT here: <a href="javascript:…">. `a` is on the allowlist, so the tag
	// sweep has nothing to object to and its pass would be vacuous — the
	// control below says so out loud rather than letting it sit green. That
	// payload is a different property and has its own assertion further down.
	array( 'svg onload',            '<svg onload="alert(1)"></svg>' ),
	array( 'style block',           '<style>body{display:none}</style>' ),
	// The interesting ones: markup that only becomes markup AFTER the markdown
	// transforms run. If escaping happened second, these would be assembled by
	// this file's own regexes out of attacker text.
	array( 'tag inside bold',       '**<script>alert(1)</script>**' ),
	array( 'tag inside a fence',    "```\n<script>alert(1)</script>\n```" ),
	array( 'tag inside a heading',  '## <img src=x onerror="alert(1)">' ),
	array( 'tag inside a list',     '- <iframe src="//evil.test"></iframe>' ),
);

/* ---- control: the detector has to be able to SEE an unsafe render ---------
 *
 * Every assertion below is "this token is absent from the output". That shape
 * passes when the render is safe, and equally when the token is misspelt, the
 * output is empty, or the search is broken. So run the identical searches over
 * the RAW payloads first, where the dangerous token is definitely present. A
 * detector that cannot find danger it is looking straight at proves nothing by
 * staying quiet afterwards. */
$rs_blind = array();
foreach ( $rs_payloads as $rs_case ) {
	list( $rs_label, $rs_input ) = $rs_case;

	// Every payload must contain a tag the allowlist forbids, or "no forbidden
	// tag in the output" is a statement about a payload that never carried one.
	$rs_forbidden = array_diff( rs_tags( $rs_input ), RS_ALLOWED );

	if ( ! $rs_forbidden ) {
		$rs_blind[] = $rs_label;
	}
}

rs_check(
	sprintf( 'control: all %d payloads carry a tag the allowlist forbids', count( $rs_payloads ) ),
	! $rs_blind && count( $rs_payloads ) >= 8,
	$rs_blind
		? 'these payloads contain nothing the detector would object to, so a clean render proves nothing: ' . implode( ', ', $rs_blind )
		: 'fewer than 8 payloads — the sweep below would be thin enough to miss a class'
);

/* ---- the real function ---------------------------------------------------- */

foreach ( $rs_payloads as $rs_case ) {
	list( $rs_label, $rs_input ) = $rs_case;

	$rs_out   = EduAI_REST::to_html( $rs_input );
	$rs_survived = array_values( array_unique( array_diff( rs_tags( $rs_out ), RS_ALLOWED ) ) );

	rs_check(
		sprintf( 'to_html() neutralises: %s', $rs_label ),
		! $rs_survived,
		sprintf( 'these tags survived as markup: <%s> — output: %s', implode( '>, <', $rs_survived ), substr( $rs_out, 0, 160 ) )
	);
}

/* An `a` IS on the allowlist, so the tag check above cannot speak for the one
 * attribute that carries a payload without a tag of its own. wp_kses filters
 * protocols on href, and this is what says so out loud. */
$rs_link = EduAI_REST::to_html( '<a href="javascript:alert(1)">click</a>' );

rs_check(
	'no javascript: URL survives inside a live href',
	! preg_match( '/<a\b[^>]*href\s*=\s*["\']?\s*javascript:/i', $rs_link ),
	'a live anchor kept a javascript: href: ' . substr( $rs_link, 0, 160 )
);

/* ---- and it still has to render, or "safe" is just "broken" ---------------
 *
 * A to_html() that returned the empty string would pass every assertion above.
 * These are the counterweight: the formatting the assistant actually depends on
 * has to survive, so that neutralising markup and destroying the answer cannot
 * be confused for each other. */
$rs_rendered = EduAI_REST::to_html(
	"## Heading\n\nSome **bold** and `code` text.\n\n- first\n- second\n\n```\nx = 1\n```"
);

foreach ( array(
	'heading'    => '<h3>',
	'bold'       => '<strong>',
	'inline code'=> '<code>',
	'list'       => '<ul>',
	'code block' => '<pre>',
) as $rs_what => $rs_tag ) {
	rs_check(
		sprintf( 'to_html() still renders %s', $rs_what ),
		false !== strpos( $rs_rendered, $rs_tag ),
		sprintf( 'no %s in the output — the renderer strips too much: %s', $rs_tag, substr( $rs_rendered, 0, 160 ) )
	);
}

/* The student's own words come back through the same renderer in the chat log,
 * so text that merely LOOKS like markup has to survive readably rather than
 * vanish. "Why does 5 < 6 && 6 > 2 matter" is a question, not an attack. */
$rs_textish = EduAI_REST::to_html( 'Why does 5 < 6 && 6 > 2 matter?' );

rs_check(
	'text that looks like markup is shown, not dropped',
	false !== strpos( $rs_textish, '5 &lt; 6' ) && false !== strpos( $rs_textish, '6 &gt; 2' ),
	'the comparison signs did not survive as visible text: ' . $rs_textish
);

printf( "\n%d passed, %d failed\n", $GLOBALS['rs_pass'], $GLOBALS['rs_fail'] );

if ( 0 === $GLOBALS['rs_pass'] + $GLOBALS['rs_fail'] ) {
	fwrite( STDERR, "no assertions ran — treating that as a failure\n" );
	exit( 1 );
}

exit( $GLOBALS['rs_fail'] > 0 ? 1 : 0 );
