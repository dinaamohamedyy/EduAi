<?php
/**
 * Turning a lecture deck into lessons.
 *
 * A lecturer's PDF is already a structured thing — it has sections, and the
 * lecturer marked them. This finds those sections and prepares one lesson per
 * section, so a course reads as a course instead of as a file to download.
 *
 * Three decisions here were made against the real deck (material 123, CPSC 340
 * Linear Regression, 44 pages) rather than from the shape of the problem, and
 * each one contradicts the obvious approach:
 *
 * 1. THE SOURCE IS THE PDF, NOT THE CHUNK INDEX. `wp_eduai_chunks` looks like
 *    the natural input — the text is already extracted and sitting in rows. But
 *    chunking overlaps by design (200 characters, so a passage is never split
 *    mid-idea for retrieval), and reassembling the chunks therefore reproduces
 *    every overlap twice. Measured: joining the nine chunks yields 55 blocks
 *    including duplicates and fragments — `ic: Least`, `otation: Why?`,
 *    `o w j , keeping all others variables fixed` — where re-extracting the
 *    file yields 44, exactly the page count in `_scholaris_pages`.
 *
 * 2. SLIDES ARE NEWLINE-DELIMITED, AND THAT IS CHECKABLE. The extractor emits
 *    one line per page and the page opens with its own title. 44 blocks against
 *    44 recorded pages is not a coincidence, and it is asserted below rather
 *    than assumed, because the day this stops being true the segmentation
 *    silently becomes nonsense rather than failing.
 *
 * 3. SECTIONS COME FROM THE LECTURER'S OWN MARKERS. This deck says
 *    `Next Topic: Least Squares in d-Dimensions` and `Next Topic: Matrix
 *    Notation`. A boundary the author declared beats one a model infers. But
 *    there are only two of them in 44 pages, so a marker-only design produces
 *    one lesson for a deck that has none — hence has_markers(), so the caller
 *    can tell "segmented" from "could not segment" instead of receiving a
 *    single lesson containing everything and believing it.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Segments a lecture into sections that can become lessons.
 */
class EduAI_Lessons {

	/**
	 * The lecturer's own "we are moving on" slide.
	 */
	private const MARKER = '/^next\s+topic\s*:?\s*(.+)$/i';

	/**
	 * Slides that are about running the course rather than about the subject.
	 *
	 * Dropped deterministically rather than left for the model to ignore. A deck
	 * opens with a title slide and an admin slide — assignment deadlines, a
	 * Piazza thread, a late-day policy — and none of that belongs in a lesson
	 * about linear regression. Asking a model to skip it costs tokens on every
	 * generation and works most of the time; a regex costs nothing and works
	 * every time.
	 */
	private const ADMIN = array(
		'/^admin\b/i',
		'/\bassignment\s*\d+\s*:/i',
		'/\blate\s+day/i',
		'/\bpiazza\b/i',
		'/\boffice\s+hours\b/i',
		'/\bmidterm\s+(is|will|date)/i',
		'/\bdue\s+(date|on|октября)/i',
	);

	/**
	 * The lecture's own text, one entry per slide.
	 *
	 * @param int $post_id Study material.
	 * @return string[] Slide texts, in order. Empty if the file cannot be read.
	 */
	public static function slides( int $post_id ): array {
		// The same authority EduAI_Knowledge indexes from, rather than the first
		// attached child — material 123 has an unrelated mp4 attached, and
		// get_children() hands that back first.
		$file_id = (int) get_post_meta( $post_id, '_scholaris_file_id', true );

		if ( ! $file_id ) {
			return array();
		}

		$path = get_attached_file( $file_id );

		if ( ! $path || ! is_readable( $path ) ) {
			return array();
		}

		$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		$text = EduAI_PDF::extract( $path, $ext );

		if ( '' === trim( $text ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'trim', preg_split( '/\R+/', $text ) ?: array() ),
				static fn( $s ) => '' !== $s
			)
		);
	}

	/**
	 * Does the extraction look like one block per page?
	 *
	 * The whole segmentation rests on slides() returning pages. If a future
	 * extractor emits one block per paragraph, or one for the entire document,
	 * every boundary below is drawn in the wrong place — and it would still
	 * return lessons, which is the failure worth catching. `_scholaris_pages` is
	 * recorded at upload from the PDF itself, so it is an independent count.
	 *
	 * @param int   $post_id Study material.
	 * @param array $slides  Result of slides().
	 */
	public static function looks_paginated( int $post_id, array $slides ): bool {
		$pages = (int) get_post_meta( $post_id, '_scholaris_pages', true );

		if ( $pages < 2 || ! $slides ) {
			return false;
		}

		// Generous: a page that is only an image contributes nothing and drops
		// out, so exact equality would be too strict to be useful.
		return count( $slides ) >= (int) floor( $pages * 0.6 )
			&& count( $slides ) <= (int) ceil( $pages * 1.5 );
	}

	/**
	 * Is this slide about the course rather than the subject?
	 *
	 * @param string $slide Slide text.
	 */
	public static function is_admin_slide( string $slide ): bool {
		foreach ( self::ADMIN as $pattern ) {
			if ( preg_match( $pattern, $slide ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Split a lecture into sections on the lecturer's own topic markers.
	 *
	 * @param int $post_id Study material.
	 * @return array{sections:array,slides:int,dropped:int,paginated:bool,markers:int}
	 */
	public static function segment( int $post_id ): array {
		$slides = self::slides( $post_id );

		$result = array(
			'sections'  => array(),
			'slides'    => count( $slides ),
			'dropped'   => 0,
			'paginated' => self::looks_paginated( $post_id, $slides ),
			'markers'   => 0,
		);

		if ( ! $slides ) {
			return $result;
		}

		// The title slide is the deck's own name, not a lesson.
		if ( isset( $slides[0] ) && self::title_slide( $post_id, $slides[0] ) ) {
			array_shift( $slides );
			$result['dropped']++;
		}

		$sections = array();
		$current  = array(
			'title'  => '',
			'slides' => array(),
		);

		foreach ( $slides as $slide ) {
			if ( preg_match( self::MARKER, $slide, $m ) ) {
				$result['markers']++;

				// A marker closes the section before it and names the one after.
				if ( $current['slides'] ) {
					$sections[] = $current;
				}

				$current = array(
					'title'  => self::tidy_title( $m[1] ),
					'slides' => array(),
				);
				continue;
			}

			if ( self::is_admin_slide( $slide ) ) {
				$result['dropped']++;
				continue;
			}

			$current['slides'][] = $slide;
		}

		if ( $current['slides'] ) {
			$sections[] = $current;
		}

		// The first section has no marker in front of it, so it has no declared
		// name. Its first slide's title is the lecturer's own heading for it.
		foreach ( $sections as $i => &$section ) {
			if ( '' === $section['title'] ) {
				$section['title'] = self::tidy_title( self::slide_title( $section['slides'][0] ?? '' ) );
			}
			$section['order'] = $i + 1;
			$section['text']  = implode( "\n\n", $section['slides'] );
			$section['chars'] = strlen( $section['text'] );
		}
		unset( $section );

		$result['sections'] = $sections;

		return $result;
	}

	/**
	 * Were there enough declared boundaries to trust the split?
	 *
	 * Two markers make three sections; none makes one section containing the
	 * whole lecture, which is not a segmentation and must not be presented as
	 * one.
	 *
	 * @param array $segmented Result of segment().
	 */
	public static function has_markers( array $segmented ): bool {
		return ( $segmented['markers'] ?? 0 ) >= 1 && count( $segmented['sections'] ?? array() ) >= 2;
	}

	/**
	 * Is this the deck's cover slide?
	 *
	 * @param int    $post_id Study material.
	 * @param string $slide   First slide.
	 */
	private static function title_slide( int $post_id, string $slide ): bool {
		// Short, and echoes the material's own title — "CPSC 340: Machine
		// Learning and Data Mining Linear Regression Fall 2022".
		if ( strlen( $slide ) > 160 ) {
			return false;
		}

		$title = trim( wp_strip_all_tags( (string) get_the_title( $post_id ) ) );

		if ( '' !== $title && false !== stripos( $slide, $title ) ) {
			return true;
		}

		// Or it carries a term and year and little else.
		return 1 === preg_match( '/\b(fall|spring|summer|winter)\s+20\d\d\b/i', $slide );
	}

	/**
	 * The heading a slide opens with.
	 *
	 * Slide text runs the title straight into the body, so the break is where
	 * the title's capitalisation stops — "Residuals and Sum of Squared Residuals
	 * The residual is the difference…".
	 *
	 * @param string $slide Slide text.
	 */
	private static function slide_title( string $slide ): string {
		$slide = trim( preg_replace( '/\s+/', ' ', $slide ) ?? $slide );

		if ( preg_match( '/^(.{3,70}?)\s+(?:The|We|This|In|A|An|Assume|Different|One|Setting|Note|Linear regression)\b/u', $slide, $m ) ) {
			return $m[1];
		}

		// Otherwise take the opening clause.
		$first = preg_split( '/[.:]\s/', $slide )[0] ?? $slide;

		return mb_substr( $first, 0, 70 );
	}

	/**
	 * Tidy a heading for use as a lesson title.
	 *
	 * @param string $raw Raw heading.
	 */
	private static function tidy_title( string $raw ): string {
		$raw = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) ?? $raw );

		// The extractor spaces out hyphenated words: "d - Dimensions".
		$raw = preg_replace( '/\s+-\s+/', '-', $raw ) ?? $raw;
		$raw = trim( $raw, " \t\n\r\0\x0B:-" );

		return mb_substr( $raw, 0, 120 );
	}
}
