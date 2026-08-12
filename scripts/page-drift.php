<?php
/**
 * Does the running site have the pages setup.sh says it should?
 *
 *     docker compose --profile tools run --rm cli \
 *         wp eval-file /scripts/page-drift.php --allow-root
 *
 * Needs a live WordPress and a database, so it cannot join the lint job. It
 * belongs on the pre-release checklist in docs/03, next to grade-adversarial.php
 * and projection-leak.php.
 *
 * WHY THIS EXISTS. setup.sh describes a *fresh* install, not this install. A
 * page added to the script after a site was bootstrapped never appears on it:
 * /ask/ and /prepare/ both returned 404 here for days while being present in
 * the script, and a later change to /ask/'s content had the same gap. Three
 * times, and nothing in the repository compared the two.
 *
 * THE TWO HALVES ARE TREATED DIFFERENTLY, ON PURPOSE.
 *
 * make_page() in setup.sh is **create-only**: it tests whether the slug exists
 * and skips entirely if it does. That single property decides everything here.
 *
 *   - A MISSING SLUG IS A FAILURE. The remedy is `bash scripts/setup.sh` and it
 *     is safe precisely because make_page never modifies an existing page — the
 *     re-run creates what is absent and leaves everything else alone.
 *
 *   - A CONTENT MISMATCH IS A WARNING, NEVER A FAILURE. Because make_page skips
 *     existing pages, content legitimately diverges the moment somebody edits
 *     page copy in wp-admin, which is expected rather than wrong. A check that
 *     turned red because the Home page was reworded would train everyone to
 *     ignore it, and then it is worth less than nothing when a real gap appears.
 *     Editor normalisation and wpautop add false positives on top of that.
 *
 * @package EduAI
 */

$setup = dirname( __DIR__ ) . '/scripts/setup.sh';

// Running through `wp eval-file` from the container, /scripts is the mount.
if ( ! is_readable( $setup ) ) {
	$setup = '/scripts/setup.sh';
}

if ( ! is_readable( $setup ) ) {
	fwrite( STDERR, "cannot find setup.sh — pass the repo or mount it at /scripts\n" );
	exit( 2 );
}

$script = (string) file_get_contents( $setup );

// make_page "Title" "slug" "content", with \" allowed inside any of the three.
//
// The gaps are [ \t]+ and not \s+, which matters more than it looks. \s matches
// a newline, so with an unbounded gap this pattern will happily take its second
// or third quoted string from a LATER LINE and report it as part of this call —
// `^` anchors where the match starts and bounds nothing after it. Demonstrated:
// against `make_page "Q&A" "ask"` followed by a quoted string on the next line,
// the \s+ form matched and reported that string as /ask/'s expected content.
// The report would have been confidently, quotably wrong, which is the failure
// that survives review because the check looks like it is working.
//
// (Found by applying the lesson from lede-copy-parity's path four, where
// anchoring on a screen id and then scanning onward ran past the end of the
// screen into the next one. Same shape, different file: an anchor is not a
// bound.)
preg_match_all(
	'/^make_page[ \t]+"((?:[^"\\\\]|\\\\.)*)"[ \t]+"((?:[^"\\\\]|\\\\.)*)"[ \t]+"((?:[^"\\\\]|\\\\.)*)"/m',
	$script,
	$matches,
	PREG_SET_ORDER
);

if ( ! $matches ) {
	fwrite( STDERR, "no make_page calls found in setup.sh — this check examined nothing\n" );
	exit( 2 );
}

$unescape = static fn( string $s ): string => str_replace( array( '\\"', '\\$' ), array( '"', '$' ), $s );

$missing = array();
$uncomparable = array();
$differs = array();
$ok      = 0;

foreach ( $matches as $m ) {
	$title   = $unescape( $m[1] );
	$slug    = $unescape( $m[2] );
	$content = $unescape( $m[3] );

	$page = get_page_by_path( $slug );

	if ( ! $page || 'publish' !== $page->post_status ) {
		$missing[] = sprintf( '/%s/ (%s)', $slug, $title );
		continue;
	}

	++$ok;

	// A shell variable in the expected content cannot be compared without
	// re-implementing setup.sh's environment, so say so rather than guess.
	//
	// It said neither: the skip was silent, so `content differs: 0` was a
	// statement about 12 of 13 pages and the reader had no way to know it was
	// not all of them. Counted and reported below — the number is unbounded in
	// the wrong direction, since adding a `$` to two more page bodies would
	// shrink the coverage with no change to the report.
	if ( false !== strpos( $content, '$' ) ) {
		$uncomparable[] = $slug;
		continue;
	}

	if ( trim( $page->post_content ) !== trim( $content ) ) {
		// Reworded copy and a missing shortcode are both "content differs", but
		// only one of them means the feature does not render. Separate them:
		// the first is expected, the second is how /dashboard/ sat pointing at
		// Tutor LMS's login form for days with [scholaris_dashboard] registered
		// and displayed nowhere.
		$expected_shortcodes = array();
		if ( preg_match_all( '/\[([a-z0-9_]+)/i', $content, $sc ) ) {
			$expected_shortcodes = $sc[1];
		}

		$absent = array_values( array_filter(
			$expected_shortcodes,
			static fn( $tag ) => ! has_shortcode( $page->post_content, $tag )
		) );

		$differs[] = array(
			'slug'   => $slug,
			'want'   => $content,
			'has'    => trim( $page->post_content ),
			'absent' => $absent,
			'owner'  => page_owner_hint( $page ),
		);
	}
}

/*
 * THE REVERSE DIRECTION: pages this site has that a fresh install would not.
 *
 * Everything above asks "does this site have what setup.sh declares?". It never
 * asked the opposite, and the opposite is the one that matters at launch —
 * this file's own header says setup.sh describes a FRESH install rather than
 * this one, and the host the product ships to is a fresh install.
 *
 * A page created in wp-admin carrying one of our shortcodes exists here and
 * not in the script. Nothing above notices, because a check that starts from
 * the declaration can only ever find things missing FROM the site, never
 * things missing from the declaration. On the new host that feature renders
 * nowhere and nothing fails — the same shape as SL_Private::ensure_denied()
 * firing only from reconcile(): correct on a used site, absent on a new one,
 * invisible to any instrument that starts from the wrong end.
 *
 * ADVISORY, not fatal, and for the same reason content drift is: creating a
 * page in wp-admin is a legitimate thing for an owner to do, and a check that
 * reddens for it gets ignored. But it must be SAID, because the consequence
 * lands at migration and not before.
 */
$declared_slugs = array();
foreach ( $matches as $m ) {
	$declared_slugs[] = $unescape( $m[2] );
}

$undeclared = array();

foreach ( get_posts( array(
	'post_type'      => 'page',
	'post_status'    => 'publish',
	'posts_per_page' => -1,
) ) as $page ) {
	if ( in_array( $page->post_name, $declared_slugs, true ) ) {
		continue;
	}
	if ( preg_match_all( '/\[((?:scholaris|eduai)_[a-z0-9_]+)/i', (string) $page->post_content, $sc ) ) {
		$undeclared[] = array( 'slug' => $page->post_name, 'tags' => array_unique( $sc[1] ) );
	}
}

/*
 * The ledes, held to a STRICTER rule than page content, because setup.sh
 * treats them differently.
 *
 * make_page is create-only, so a page body that has drifted is somebody
 * editing copy in wp-admin — expected, and only ever a warning here. set_lede
 * is the opposite: it converges on every run, the way retitle_page does. The
 * script asserts ownership of this field, so a disagreement is not a matter of
 * taste, it means the script has not been run since the lede was declared.
 *
 * That distinction is why this check was worth widening. It reported 0 drift
 * while four pages were shipping a bare word over a shortcode, because it
 * compared headings and page bodies and a lede is neither. The code was right
 * and the criterion was narrow, which is the failure that survives longest:
 * it produces a clean report, and a clean report reads as evidence.
 *
 * MISSING is a failure and DIFFERENT is a warning, mirroring the split above.
 * A page with no lede at all renders bare, which is the bug. A page with a
 * different one is readable — but the next run of setup.sh will overwrite it,
 * so the warning says so rather than leaving the owner to discover it.
 */
// [ \t]+ rather than \s+, for the reason given on the make_page pattern above:
// a newline-crossing gap lets the lede be taken from a different line.
preg_match_all(
	'/^set_lede[ \t]+"((?:[^"\\\\]|\\\\.)*)"[ \t]+"((?:[^"\\\\]|\\\\.)*)"/m',
	$script,
	$lede_matches,
	PREG_SET_ORDER
);

$lede_bare    = array();
$lede_differs = array();

foreach ( $lede_matches as $m ) {
	$slug = $unescape( $m[1] );
	$want = $unescape( $m[2] );

	$page = get_page_by_path( $slug );

	// Already reported as missing by the loop above; do not say it twice.
	if ( ! $page || 'publish' !== $page->post_status ) {
		continue;
	}

	$has = trim( (string) $page->post_excerpt );

	if ( '' === $has ) {
		$lede_bare[] = array( 'slug' => $slug, 'want' => $want );
		continue;
	}

	if ( $has !== trim( $want ) ) {
		$lede_differs[] = array( 'slug' => $slug, 'want' => $want, 'has' => $has );
	}
}

/**
 * A one-line guess at who really owns a page we expected to own.
 *
 * A third-party plugin creating a page on activation takes the slug before
 * setup.sh runs, and create-only make_page then skips it silently. Naming the
 * suspect turns a puzzling diff into an obvious cause.
 *
 * @param WP_Post $page Page found at the declared slug.
 */
function page_owner_hint( WP_Post $page ): string {
	$tutor = get_option( 'tutor_option' );

	if ( is_array( $tutor ) ) {
		foreach ( $tutor as $key => $value ) {
			if ( is_scalar( $value ) && (int) $value === $page->ID && false !== strpos( $key, 'page_id' ) ) {
				return sprintf( 'claimed by Tutor LMS as %s', $key );
			}
		}
	}

	return '';
}

printf( "pages declared in setup.sh: %d\n", count( $matches ) );
printf( "  present on this site    : %d\n", $ok );
printf( "  missing                 : %d\n", count( $missing ) );
printf( "  content differs         : %d\n", count( $differs ) );
printf(
	"  content not comparable  : %d%s\n",
	count( $uncomparable ),
	$uncomparable ? ' (' . implode( ', ', $uncomparable ) . ' — expected body contains a shell variable)' : ''
);
printf( "ledes declared in setup.sh: %d\n", count( $lede_matches ) );
printf( "  missing from the site   : %d\n", count( $lede_bare ) );
printf( "  differs                 : %d\n\n", count( $lede_differs ) );

if ( $lede_differs ) {
	print "lede differs (warning — set_lede converges, so the NEXT setup.sh run overwrites the site's):\n";
	foreach ( $lede_differs as $d ) {
		printf( "  /%s/\n", $d['slug'] );
		printf( "      setup.sh: %s\n", substr( $d['want'], 0, 90 ) );
		printf( "      site    : %s\n", substr( $d['has'], 0, 90 ) );
	}
	print "\n";
}

if ( $undeclared ) {
	print "ON THIS SITE BUT NOT IN setup.sh (warning — a fresh install would not have these):\n";
	foreach ( $undeclared as $u ) {
		printf(
			"  /%s/  carries %s\n",
			$u['slug'],
			implode( ', ', array_map( static fn( $t ) => "[$t]", $u['tags'] ) )
		);
	}
	print "\n  These render here and would render nowhere on a new host, with nothing\n";
	print "  failing. Add a make_page call to setup.sh if the page is meant to ship.\n\n";
}

if ( $differs ) {
	print "content differs (warning only — make_page is create-only, so an edited page is expected):\n";
	foreach ( $differs as $d ) {
		printf( "  /%s/%s\n", $d['slug'], $d['owner'] ? '  — ' . $d['owner'] : '' );
		printf( "      setup.sh: %s\n", substr( $d['want'], 0, 90 ) );
		printf( "      site    : %s\n", '' === $d['has'] ? '(empty)' : substr( $d['has'], 0, 90 ) );

		if ( $d['absent'] ) {
			printf(
				"      BROKEN: %s not present on the page, so that feature renders nowhere\n"
					. "              — reported as a failure below, not as this warning\n",
				implode( ', ', array_map( static fn( $t ) => "[$t]", $d['absent'] ) )
			);
		}
	}
	print "\n";
}

/*
 * WHICH SIGNALS DECIDE THE EXIT CODE, AND WHY — read this before adding one.
 *
 * This file computes seven things. Three are fatal, four are advisory, and the
 * difference is a judgement about make_page/set_lede's semantics, not about
 * severity of language:
 *
 *   FATAL     $missing       the slug is not on the site at all
 *   FATAL     $lede_bare     set_lede declares a lede, the page has none
 *   FATAL     $broken        an expected shortcode is absent, so the feature
 *                            renders nowhere
 *   ADVISORY  $differs       the copy was reworded. make_page is create-only,
 *                            so an editor changing wording is EXPECTED, and a
 *                            check that reddened for it would be ignored.
 *   ADVISORY  $lede_differs  set_lede converges, so the next setup.sh run
 *                            overwrites the site's anyway.
 *   ADVISORY  $undeclared    a page here carries one of our shortcodes and is
 *                            not in setup.sh, so a fresh install would not
 *                            have it. Creating a page in wp-admin is
 *                            legitimate; the consequence lands at migration.
 *   ADVISORY  $uncomparable  the expected body holds a shell variable and
 *                            cannot be compared without re-implementing
 *                            setup.sh's environment. Reported as a COUNT
 *                            because it bounds what "content differs: 0"
 *                            covers — it was 12 of 13 pages while claiming
 *                            nothing, and adding a `$` to two more bodies
 *                            would shrink that with no change to the report.
 *
 * Both advisories state that reasoning in their own output. That is the
 * convention: an advisory signal must say why it is advisory where the reader
 * sees it. A signal that is computed, printed, and silently absent from this
 * list is a bug, and was one — `absent` printed "LIKELY BROKEN: [tag] not
 * present on the page, so that feature renders nowhere" and then exited 0.
 * A nightly reads exit codes, so that sentence was written to nobody, and the
 * guard whose docblock cites /dashboard/ sitting on Tutor's login form for
 * days would not have caught /dashboard/ sitting on Tutor's login form.
 *
 * Reporting a failure and returning success is not a smaller version of
 * passing. It is worse, because it looks examined.
 */

$broken = array_values( array_filter( $differs, static fn( $d ) => ! empty( $d['absent'] ) ) );

// Accumulate, then exit once. Each of these used to exit where it printed, so
// a site with missing pages AND missing ledes reported only the first and hid
// the rest until the next run — the same shape as discarding a signal, one
// level up: the information existed and did not reach the reader.
$fatal = 0;

if ( $missing ) {
	$fatal++;
	print "MISSING PAGES — these exist in setup.sh but not on this site:\n";
	foreach ( $missing as $slug ) {
		print "  $slug\n";
	}
	print "\n  Fix: re-run scripts/setup.sh. make_page is create-only, so it will\n";
	print "  create what is absent and leave every existing page untouched.\n\n";
}

if ( $broken ) {
	$fatal++;
	print "SHORTCODE MISSING — the page exists and the feature renders nowhere:\n";
	foreach ( $broken as $d ) {
		printf(
			"  /%s/%s\n      absent: %s\n",
			$d['slug'],
			$d['owner'] ? '  — ' . $d['owner'] : '',
			implode( ', ', array_map( static fn( $t ) => "[$t]", $d['absent'] ) )
		);
	}
	print "\n  This is NOT the reworded-copy case above. The wording of a page is\n";
	print "  the editor's; the shortcode is what makes it a feature rather than a\n";
	print "  paragraph. Re-running setup.sh will NOT repair it — make_page skips\n";
	print "  pages that already exist — so restore the shortcode in wp-admin.\n\n";
}

if ( $lede_bare ) {
	$fatal++;
	print "PAGES WITH NO LEDE — set_lede declares one, the site has none:\n";
	foreach ( $lede_bare as $d ) {
		printf( "  /%s/\n", $d['slug'] );
		printf( "      should read: %s\n", substr( $d['want'], 0, 90 ) );
	}
	print "\n  These render as a bare heading over a shortcode: page.php only emits\n";
	print "  the lede when has_excerpt() is true.\n";
	print "\n  Fix: re-run scripts/setup.sh. set_lede converges on every run, so it\n";
	print "  fills these in without touching anything else.\n\n";
}

if ( $fatal > 0 ) {
	printf( "%d kind(s) of drift that break the site. See above.\n", $fatal );
	exit( 1 );
}

print "no missing pages, no missing shortcodes, no missing ledes\n";
exit( 0 );
