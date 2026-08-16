<?php
/**
 * The four AI tool pages stopped being destinations. Send their URLs somewhere.
 *
 * Summarise, PrepareME and Q&A belong to a lesson now — you reach them by
 * opening one (docs/14 §1). AiCalc folded into the home-page assistant. Their
 * pages were nav tabs until 12 Aug 2026, so their URLs are in browser
 * histories, in the mock, and in anything the owner has sent anyone.
 *
 * REDIRECTING RATHER THAN DELETING, deliberately. Deleting the pages is the
 * tidier-looking option and it turns every one of those links into a 404 — and
 * a 404 on a URL a student used yesterday reads as "the site is broken", not
 * as "this moved". The pages also still hold their shortcodes, so a redirect is
 * reversible and a deletion is not.
 *
 * 302, not 301. A permanent redirect is cached by the browser and would survive
 * us changing our minds; these routes are one product decision old.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where each retired route goes, and why.
 *
 * The two lesson-scoped tools point at the Library because that is where a
 * lesson is chosen — sending them to a lesson would mean picking one for the
 * student, and there is no correct choice. The two general ones point home,
 * where the assistant now lives.
 */
function scholaris_retired_routes(): array {
	return array(
		'summarise' => 'library',
		'prepare'   => 'library',
		'ask'       => 'home',
		'calc'      => 'home',
	);
}

/**
 * Redirect a retired tool page to its replacement.
 *
 * Runs on template_redirect rather than init: is_page() needs the query to be
 * resolved, and a redirect fired before that would be guessing from the URL.
 */
function scholaris_redirect_retired_routes(): void {
	if ( is_admin() || ! is_page() ) {
		return;
	}

	$slug = get_post_field( 'post_name', get_queried_object_id() );

	if ( ! $slug ) {
		return;
	}

	$routes = scholaris_retired_routes();

	if ( ! isset( $routes[ $slug ] ) ) {
		return;
	}

	/*
	 * A LESSON-SCOPED LINK IS NOT A RETIRED ROUTE. Never redirect it.
	 *
	 * Opening a lesson and choosing PrepareME, Summarise or Ask sends you to
	 * ?source=<lesson id> — that is how a tool gets attached to the lesson you
	 * picked, and it is the whole feature. Redirecting the page redirected
	 * those links too, so choosing PrepareME on a lesson landed on the Library
	 * with the lesson thrown away. The feature did not degrade, it disappeared,
	 * and the redirect that retired the bare page is what did it.
	 *
	 * `exam` is here for the same reason: Retake links carry ?exam=<id>.
	 *
	 * Only the BARE page is retired — someone arriving with no lesson and no
	 * exam, which is the case the nav used to serve and no longer does.
	 */
	foreach ( array( 'source', 'exam' ) as $keeps_the_page ) {
		if ( ! empty( $_GET[ $keeps_the_page ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
	}

	$target = $routes[ $slug ];

	if ( 'home' === $target ) {
		$url = home_url( '/' );
	} else {
		$page = get_page_by_path( $target );
		// If the destination is missing, do nothing. Redirecting to a 404 is
		// worse than serving the old page, which still works — it is simply no
		// longer advertised.
		if ( ! $page || 'publish' !== $page->post_status ) {
			return;
		}
		$url = (string) get_permalink( $page );
	}

	wp_safe_redirect( $url, 302 );
	exit;
}

add_action( 'template_redirect', 'scholaris_redirect_retired_routes' );
