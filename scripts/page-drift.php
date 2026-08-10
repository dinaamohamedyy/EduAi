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
preg_match_all(
	'/^make_page\s+"((?:[^"\\\\]|\\\\.)*)"\s+"((?:[^"\\\\]|\\\\.)*)"\s+"((?:[^"\\\\]|\\\\.)*)"/m',
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
	if ( false !== strpos( $content, '$' ) ) {
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
printf( "  content differs         : %d\n\n", count( $differs ) );

if ( $differs ) {
	print "content differs (warning only — make_page is create-only, so an edited page is expected):\n";
	foreach ( $differs as $d ) {
		printf( "  /%s/%s\n", $d['slug'], $d['owner'] ? '  — ' . $d['owner'] : '' );
		printf( "      setup.sh: %s\n", substr( $d['want'], 0, 90 ) );
		printf( "      site    : %s\n", '' === $d['has'] ? '(empty)' : substr( $d['has'], 0, 90 ) );

		if ( $d['absent'] ) {
			printf(
				"      LIKELY BROKEN: %s not present on the page, so that feature renders nowhere\n",
				implode( ', ', array_map( static fn( $t ) => "[$t]", $d['absent'] ) )
			);
		}
	}
	print "\n";
}

if ( $missing ) {
	print "MISSING PAGES — these exist in setup.sh but not on this site:\n";
	foreach ( $missing as $slug ) {
		print "  $slug\n";
	}
	print "\n  Fix: re-run scripts/setup.sh. make_page is create-only, so it will\n";
	print "  create what is absent and leave every existing page untouched.\n";
	exit( 1 );
}

print "no missing pages\n";
exit( 0 );
