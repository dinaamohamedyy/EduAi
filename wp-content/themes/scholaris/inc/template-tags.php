<?php
/**
 * Reusable template helpers.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render an inline SVG icon from a small curated set.
 *
 * @param string $name  Icon key.
 * @param int    $size  Pixel size.
 */
function scholaris_icon( string $name, int $size = 20 ): string {
	$paths = array(
		'book'     => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
		'sparkles' => '<path d="m12 3 1.9 4.6L18.5 9.5 13.9 11.4 12 16l-1.9-4.6L5.5 9.5l4.6-1.9L12 3z"/><path d="M18.5 15.5 19.4 18l2.5.9-2.5.9-.9 2.5-.9-2.5-2.5-.9 2.5-.9.9-2.5z"/>',
		'mic'      => '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3"/>',
		'chart'    => '<path d="M3 3v18h18"/><path d="M7 16V9"/><path d="M12 16V5"/><path d="M17 16v-4"/>',
		'file'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
		'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/>',
		'check'    => '<path d="M20 6 9 17l-5-5"/>',
		'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
		'moon'     => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
		'menu'     => '<path d="M4 6h16M4 12h16M4 18h16"/>',
		'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
		'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/>',
	);

	$path = $paths[ $name ] ?? $paths['sparkles'];

	return sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%2$s</svg>',
		$size,
		$path
	);
}

/**
 * Echo an icon.
 */
function scholaris_the_icon( string $name, int $size = 20 ): void {
	echo scholaris_icon( $name, $size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
}

/**
 * Return a customizer value with a fallback.
 */
function scholaris_opt( string $key, string $fallback = '' ): string {
	$value = get_theme_mod( $key, $fallback );
	return is_string( $value ) ? $value : $fallback;
}

/**
 * True when a shortcode's plugin is available.
 */
function scholaris_has_shortcode( string $tag ): bool {
	return shortcode_exists( $tag );
}

/**
 * Where "My Progress" lives.
 *
 * This exists because the URL was hard-coded in eight places, all of them
 * `/dashboard/` — and `/dashboard/` belongs to Tutor LMS, which creates that
 * page when it activates, long before setup.sh reaches its page section.
 * Create-only make_page() then skipped ours, so our progress page was never
 * created and every one of those eight links pointed at Tutor's empty page.
 * Registration and sign-in both landed students there. It returned 200
 * throughout, which is why it went unnoticed.
 *
 * One resolver, so the ninth caller cannot reintroduce it.
 *
 * The fallback is deliberately home rather than /dashboard/: sending students
 * back to Tutor's page is the bug being fixed, and it fails silently. Home is a
 * worse landing but an honest one, and scripts/page-drift.php reports the cause.
 */
function scholaris_progress_url(): string {
	$page = get_page_by_path( 'progress' );

	$url = ( $page && 'publish' === $page->post_status )
		? (string) get_permalink( $page )
		: home_url( '/' );

	/**
	 * Filter the My Progress destination.
	 *
	 * @param string $url Resolved URL.
	 */
	return (string) apply_filters( 'scholaris_progress_url', $url );
}

/**
 * Where the account screen lives.
 *
 * Same resolver shape and same honest fallback as scholaris_progress_url().
 * Never wp-admin/profile.php: sending a student to the WordPress admin is the
 * bug page-templates/profile.php exists to fix.
 */
function scholaris_profile_url(): string {
	$page = get_page_by_path( 'profile' );

	$url = ( $page && 'publish' === $page->post_status )
		? (string) get_permalink( $page )
		: home_url( '/' );

	/**
	 * Filter the profile destination.
	 *
	 * @param string $url Resolved URL.
	 */
	return (string) apply_filters( 'scholaris_profile_url', $url );
}

/**
 * Where "ask the assistant" sends a student: the Q&A tab.
 *
 * Needed because retiring the floating widget (docs/06 §3) removed the thing
 * that `data-eduai-open` used to open. Any button still carrying that attribute
 * on a page without the dock is inert — chat.js is not even enqueued there — so
 * a call to action has to be a link to a destination now, not a trigger.
 *
 * Same resolver shape and same honest fallback as scholaris_progress_url():
 * home is a worse landing than /ask/ but a visible one, whereas a dead button
 * looks identical to a working page.
 */
function scholaris_ask_url(): string {
	$page = get_page_by_path( 'ask' );

	$url = ( $page && 'publish' === $page->post_status )
		? (string) get_permalink( $page )
		: home_url( '/' );

	/**
	 * Filter the Q&A destination.
	 *
	 * @param string $url Resolved URL.
	 */
	return (string) apply_filters( 'scholaris_ask_url', $url );
}

/**
 * Does this request get the app shell (left rail)?
 *
 * The condition lived in two places — header.php printed the rail, and
 * body_class added has-rail — with a comment on the second saying that a third
 * caller should lift it to a helper rather than repeat the test again. This is
 * that third caller, so here it is.
 *
 * The rule changed as well as moving. The rail was excluded from the front page
 * because that surface is the marketing hero, and study-tools furniture beside
 * a sales pitch is an app the visitor has not entered yet. That is still true
 * for a visitor. For a signed-in student the front page IS the dashboard, so
 * the shell belongs there — and its absence was why the dashboard read as the
 * old site with panels added rather than as the reference it was built from.
 */
function scholaris_has_rail(): bool {
	return ! is_front_page() || is_user_logged_in();
}
