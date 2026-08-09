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
