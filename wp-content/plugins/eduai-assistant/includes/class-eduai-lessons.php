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
	 * How many times to wait out a per-minute token ceiling, and for how long.
	 *
	 * 25 seconds against a 60-second window: long enough that two lessons of
	 * ~3,300 tokens each stop competing inside one window, short enough that a
	 * three-lesson deck finishes in under two minutes.
	 */
	private const RATE_RETRIES = 3;
	private const RATE_WAIT    = 25;

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

		// Whether a block index can be called a page number.
		//
		// looks_paginated() is deliberately generous — a page that is only an
		// image contributes no text and drops out, and a 40% shortfall still
		// segments correctly because the MARKERS decide the boundaries, not the
		// count. Page NUMBERS are a stricter claim: block 18 is page 19 only if
		// every page produced exactly one block. One missing block shifts every
		// number after it, and a lesson pointing at the wrong pages looks
		// exactly like one pointing at the right ones.
		//
		// So this is exact equality against the count recorded from the PDF at
		// upload, and where it does not hold no range is offered at all.
		$result['pages_exact'] = count( $slides ) > 0
			&& count( $slides ) === (int) get_post_meta( $post_id, '_scholaris_pages', true );

		// The title slide is the deck's own name, not a lesson. Skipped by
		// index rather than shifted off, because every position after it is a
		// page number now and array_shift would silently renumber them all.
		$skip_first = isset( $slides[0] ) && self::title_slide( $post_id, $slides[0] );

		if ( $skip_first ) {
			$result['dropped']++;
		}

		$sections = array();
		$current  = array(
			'title'  => '',
			'slides' => array(),
			'first'  => null,
			'last'   => null,
		);

		foreach ( $slides as $i => $slide ) {
			if ( 0 === $i && $skip_first ) {
				continue;
			}

			if ( preg_match( self::MARKER, $slide, $m ) ) {
				$result['markers']++;

				// A marker closes the section before it and names the one after.
				if ( $current['slides'] ) {
					$sections[] = $current;
				}

				$current = array(
					'title'  => self::tidy_title( $m[1] ),
					'slides' => array(),
					'first'  => null,
					'last'   => null,
				);
				continue;
			}

			if ( self::is_admin_slide( $slide ) ) {
				$result['dropped']++;
				continue;
			}

			$current['slides'][] = $slide;

			// Original block position, not position within the section: the
			// whole point is that dropped slides do not renumber what follows.
			if ( null === $current['first'] ) {
				$current['first'] = $i;
			}
			$current['last'] = $i;
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

			// Pages are 1-based and blocks are 0-based, and the range is only
			// offered where a block index IS a page number. Null rather than a
			// guess: a lesson that opens the viewer on the wrong slide is
			// indistinguishable from one that opens it on the right one, so a
			// range nobody can vouch for is worse than none.
			$section['page_from'] = ( $result['pages_exact'] && null !== $section['first'] )
				? $section['first'] + 1
				: null;
			$section['page_to']   = ( $result['pages_exact'] && null !== $section['last'] )
				? $section['last'] + 1
				: null;
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
	 * Create a topic and its lessons under a course.
	 *
	 * One deck becomes one topic; each of its sections becomes a lesson beneath
	 * it. Tutor's own post types and parentage are used rather than restated —
	 * `tutor()->topics_post_type` and `->lesson_post_type` — because guessing
	 * `topic` when the plugin says `topics` produces posts that exist, publish
	 * and 404, which is the failure mode that looks like success.
	 *
	 * Refuses rather than half-builds. A deck with no declared sections must not
	 * arrive as one lesson containing the lecture, and a partially written topic
	 * is worse than none: it looks finished to whoever opens the course next.
	 *
	 * @param int    $course_id  Tutor course.
	 * @param int    $post_id    Source material.
	 * @param string $topic_name Topic title, or blank to use the material's.
	 * @param bool   $with_bodies Generate lesson prose (a model call per lesson).
	 * @return array|WP_Error { topic_id, lessons: [ {id,title,url} ], skipped }
	 */
	public static function write( int $course_id, int $post_id, string $topic_name = '', bool $with_bodies = true ) {
		if ( ! function_exists( 'tutor' ) ) {
			return new WP_Error( 'eduai_no_tutor', __( 'Tutor LMS is not active, so there is nowhere to put lessons.', 'eduai' ) );
		}

		$course = get_post( $course_id );

		if ( ! $course || tutor()->course_post_type !== $course->post_type ) {
			return new WP_Error( 'eduai_no_course', __( 'That course does not exist.', 'eduai' ) );
		}

		$segmented = self::segment( $post_id );

		// The guard that stops a non-segmentation being published as one. A
		// deck the lecturer never divided comes back as a single section
		// containing everything, which would become one lesson called "the
		// whole lecture" and read as though it had worked.
		if ( ! self::has_markers( $segmented ) ) {
			return new WP_Error(
				'eduai_no_sections',
				__( 'That lecture does not announce its own sections, so it cannot be split into lessons yet. Nothing was created.', 'eduai' ),
				array( 'slides' => $segmented['slides'], 'markers' => $segmented['markers'] )
			);
		}

		if ( ! $segmented['paginated'] ) {
			return new WP_Error(
				'eduai_not_paginated',
				__( 'The text of that lecture did not come out one block per page, so the section boundaries cannot be trusted. Nothing was created.', 'eduai' )
			);
		}

		$topic_name = '' !== trim( $topic_name )
			? trim( $topic_name )
			: trim( wp_strip_all_tags( (string) get_the_title( $post_id ) ) );

		$topic_id = wp_insert_post( array(
			'post_type'    => tutor()->topics_post_type,
			'post_status'  => 'publish',
			'post_title'   => $topic_name,
			'post_content' => '',
			'post_parent'  => $course_id,
			'menu_order'   => self::next_order( $course_id, tutor()->topics_post_type ),
		), true );

		if ( is_wp_error( $topic_id ) ) {
			return $topic_id;
		}

		$lessons = array();
		$skipped = array();

		foreach ( $segmented['sections'] as $section ) {
			$body = '';

			if ( $with_bodies ) {
				$written = self::body_with_retry( $section, $post_id, $topic_name );

				if ( is_wp_error( $written ) ) {
					// One section failing must not abandon a half-written topic
					// silently — record it and carry on, then report which.
					$skipped[] = array(
						'title'  => $section['title'],
						'reason' => $written->get_error_message(),
					);
					continue;
				}

				$body = $written;
			}

			$lesson_id = wp_insert_post( array(
				'post_type'    => tutor()->lesson_post_type,
				'post_status'  => 'publish',
				'post_title'   => $section['title'],
				/*
				 * wp_slash() because wp_insert_post() UNSLASHES what it is
				 * given — it expects superglobal-shaped input, and content
				 * assembled in PHP arrives already unslashed. Every backslash
				 * in the body was therefore being eaten on the way to the
				 * database.
				 *
				 * That is worse than it sounds for this content. Maths the
				 * renderer could not convert survives as LaTeX, and losing its
				 * backslash turns `\hat{y}` into `hat{y}` and `\dots` into
				 * `dots` — un-rendered LaTeX reads as "a formula that failed",
				 * while the stripped form reads as a typo, or as an English
				 * word in the middle of an equation. 55 occurrences reached
				 * students across three lessons before front-end read the page
				 * and found it.
				 */
				'post_content' => wp_slash( $body ),
				'post_parent'  => $topic_id,
				'menu_order'   => (int) $section['order'],
			), true );

			if ( is_wp_error( $lesson_id ) ) {
				$skipped[] = array( 'title' => $section['title'], 'reason' => $lesson_id->get_error_message() );
				continue;
			}

			// Tutor resolves a lesson's course through this meta as well as
			// through parentage; both are set because its own utils read both
			// depending on the call path.
			update_post_meta( $lesson_id, '_tutor_course_id_for_lesson', $course_id );

			// Where this lesson's teaching came from, for the tools that scope
			// to a source rather than to a lesson.
			update_post_meta( $lesson_id, '_eduai_source_material', $post_id );

			// And WHICH PAGES of it. A lesson is the material page scoped to
			// its own slides, so without this the viewer has a document and no
			// idea where the lesson starts. Written only when the block-to-page
			// mapping is exact; absent meta means "unknown", which a renderer
			// can fall back on, where a wrong number it cannot detect.
			self::store_range( $lesson_id, $section );

			$lessons[] = array(
				'id'    => (int) $lesson_id,
				'title' => $section['title'],
				'url'   => (string) get_permalink( $lesson_id ),
			);
		}

		if ( ! $lessons ) {
			// Nothing was written, so leave nothing behind.
			wp_delete_post( $topic_id, true );

			return new WP_Error(
				'eduai_no_lessons',
				__( 'No lesson could be written from that lecture, so the topic was removed rather than left empty.', 'eduai' ),
				array( 'skipped' => $skipped )
			);
		}

		return array(
			'topic_id' => (int) $topic_id,
			'topic'    => $topic_name,
			'lessons'  => $lessons,
			'skipped'  => $skipped,
		);
	}

	/**
	 * lesson_body(), waiting out the per-minute token ceiling.
	 *
	 * Writing a whole deck is the one operation in this product that trips a
	 * limit a single chat message never comes near. Groq's free tier allows
	 * 8,000 tokens per MINUTE, and a lesson costs roughly 3,300 — so the first
	 * lesson succeeds, the second is borderline, and the third is refused.
	 * Measured exactly that: one lesson written, two skipped with "Limit 8000,
	 * Used 5068, Requested 3304".
	 *
	 * This is not the gateway's problem to solve. A single request never hits
	 * TPM, and adding a sleep there would slow every chat message to fix a
	 * bulk-write defect. The caller doing the bulk write is what knows it is
	 * about to make N requests in a row, so the pacing lives here.
	 *
	 * The provider tells us how long to wait — "Please try again in 2.79s" —
	 * but that figure is the moment the window slides enough for THIS request,
	 * measured before our own next request adds to it. Waiting the stated time
	 * lands exactly on the edge; the window is a minute wide, so waiting a
	 * sensible fraction of it is what actually clears.
	 *
	 * @param array  $section Section to write.
	 * @param int    $post_id Source material.
	 * @param string $subject Topic name.
	 * @return string|WP_Error
	 */
	private static function body_with_retry( array $section, int $post_id, string $subject ) {
		$attempts = 0;

		while ( true ) {
			$body = self::lesson_body( $section, $post_id, $subject );

			if ( ! is_wp_error( $body ) ) {
				return $body;
			}

			// Only a rate limit is worth waiting out. A bad key, an unreadable
			// section or a truncated reply will fail identically next time, and
			// sleeping on them turns a fast error into a slow one.
			if ( 'eduai_api_429' !== $body->get_error_code() || $attempts >= self::RATE_RETRIES ) {
				return $body;
			}

			++$attempts;
			sleep( self::RATE_WAIT );
		}
	}

	/**
	 * Record which pages of the source a lesson covers.
	 *
	 * Absent meta means "not known", and that is a state a renderer can handle
	 * — show the whole document, or no viewer. A wrong page number is not: it
	 * opens the viewer confidently on the wrong slide, and looks exactly like
	 * opening it on the right one.
	 *
	 * @param int   $lesson_id Lesson post.
	 * @param array $section   One section from segment().
	 */
	private static function store_range( int $lesson_id, array $section ): void {
		if ( null === ( $section['page_from'] ?? null ) || null === ( $section['page_to'] ?? null ) ) {
			delete_post_meta( $lesson_id, '_eduai_page_from' );
			delete_post_meta( $lesson_id, '_eduai_page_to' );
			return;
		}

		update_post_meta( $lesson_id, '_eduai_page_from', (int) $section['page_from'] );
		update_post_meta( $lesson_id, '_eduai_page_to', (int) $section['page_to'] );
	}

	/**
	 * Give existing lessons their page ranges without rewriting them.
	 *
	 * Segmentation is deterministic — same file, same markers, same boundaries
	 * — so re-running it recovers exactly the ranges the original run computed
	 * and threw away. What must NOT be re-run is the prose: that costs a model
	 * call per lesson against a per-minute token ceiling this deck has already
	 * hit, and would replace text somebody may have edited.
	 *
	 * Matched by title, because that is what the original run wrote and the
	 * only thing tying a lesson to its section. A lesson whose title has been
	 * edited since is reported as unmatched rather than guessed at.
	 *
	 * @param int $course_id Course holding the lessons.
	 * @param int $post_id   Source material they were written from.
	 * @return array{updated:array,unmatched:array,exact:bool}
	 */
	public static function backfill_ranges( int $course_id, int $post_id ): array {
		$segmented = self::segment( $post_id );
		$out       = array(
			'updated'   => array(),
			'cleared'   => array(),
			'unmatched' => array(),
			'exact'     => (bool) $segmented['pages_exact'],
		);

		// Deliberately NOT an early return when the mapping is untrustworthy.
		//
		// Returning here leaves whatever ranges are already stored in place —
		// and those were vouched for by a segmentation that no longer holds. A
		// re-uploaded PDF with different pagination is exactly how that
		// happens, and the result is a lesson opening the viewer confidently on
		// the wrong slide, which is indistinguishable from the right one.
		//
		// So a run that cannot vouch for ranges REMOVES them. The invariant
		// worth having is that a stored range is always one the current
		// segmentation stands behind, and absent meta is a state the renderer
		// can handle.

		$lessons = get_posts( array(
			'post_type'      => function_exists( 'tutor' ) ? tutor()->lesson_post_type : 'lesson',
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'meta_key'       => '_eduai_source_material', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => $post_id,                 // phpcs:ignore WordPress.DB.SlowDBQuery
		) );

		$by_title = array();
		foreach ( $segmented['sections'] as $section ) {
			$by_title[ $section['title'] ] = $section;
		}

		foreach ( $lessons as $lesson ) {
			$title = trim( wp_strip_all_tags( $lesson->post_title ) );

			if ( ! isset( $by_title[ $title ] ) ) {
				$out['unmatched'][] = array( 'id' => $lesson->ID, 'title' => $title );
				continue;
			}

			$section = $by_title[ $title ];
			$had     = (int) get_post_meta( $lesson->ID, '_eduai_page_from', true );

			self::store_range( $lesson->ID, $section );

			if ( null === $section['page_from'] ) {
				// Only worth reporting if something was actually withdrawn.
				if ( $had ) {
					$out['cleared'][] = array( 'id' => $lesson->ID, 'title' => $title );
				}
				continue;
			}

			$out['updated'][] = array(
				'id'   => $lesson->ID,
				'title'=> $title,
				'from' => $section['page_from'],
				'to'   => $section['page_to'],
			);
		}

		return $out;
	}

	/**
	 * Next menu_order under a course, so a second deck lands after the first.
	 *
	 * @param int    $course_id Course.
	 * @param string $type      Post type.
	 */
	private static function next_order( int $course_id, string $type ): int {
		$siblings = get_posts( array(
			'post_type'      => $type,
			'post_parent'    => $course_id,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'post_status'    => 'any',
			'no_found_rows'  => true,
		) );

		return count( $siblings ) + 1;
	}

	/**
	 * Write one lesson's body from its section.
	 *
	 * WHAT A LESSON CONTAINS, and why it is not the extract.
	 *
	 * The extracted text is slide fragments: `y i = 10.34cm`, bare URLs, a
	 * formula whose symbols were images and did not survive. Dropped into a
	 * lesson body verbatim it produces something strictly worse than opening the
	 * PDF — the student loses the layout that made the fragments legible and
	 * gains nothing. So the lesson carries prose written from those slides.
	 *
	 * But generated prose alone would quietly replace the lecturer's material
	 * with a model's paraphrase of it, with no way to tell and nothing to check
	 * against. So every lesson also carries a pointer back to the deck it came
	 * from, and says which slides. That pointer is what makes "generated"
	 * honest rather than hidden, and it is the part a student needs when the
	 * prose and the lecture disagree.
	 *
	 * The section is the ONLY source. No retrieval, no other lessons, no general
	 * knowledge — a lesson that quietly imports facts the lecturer did not teach
	 * is worse than a thin one, because it will be revised from and examined
	 * against.
	 *
	 * @param array  $section  One entry from segment()['sections'].
	 * @param int    $post_id  Source material, for the pointer.
	 * @param string $subject  Topic name, for context.
	 * @return string|WP_Error HTML body.
	 */
	public static function lesson_body( array $section, int $post_id, string $subject = '' ) {
		$text = trim( (string) ( $section['text'] ?? '' ) );

		if ( '' === $text ) {
			return new WP_Error( 'eduai_lesson_empty', __( 'That section has no readable text.', 'eduai' ) );
		}

		$system = 'You turn a lecturer\'s slide fragments into readable lesson notes for a student revising. '
			. 'You are not writing a summary and not adding to the lecture: you are setting out what these slides '
			. 'teach, in prose a student can follow without the slides in front of them.'
			. "\n\n" . EduAI_Agents::house_rules_section( 'Notation' );

		$instruction = 'These are the slides of one section of a lecture'
			. ( '' !== $subject ? ' on ' . $subject : '' ) . ".\n\n"
			. "Write the lesson.\n\n"
			. "Rules:\n"
			. "- Use ONLY what is in these slides. Do not add examples, definitions, history or applications from your own knowledge, however helpful they would be — a student will revise from this and be examined against the lecture, not against you.\n"
			// This text is displayed BESIDE a viewer showing the slides. "The
			// slide shows…" then reads as pointing at whatever happens to be on
			// screen, which is usually not the one the sentence is about. The
			// first version of this prompt used that register itself and the
			// model followed it about forty times per lesson.
			// Two uses of "the slide" and only one is a problem. As a NARRATOR
			// it is deictic — the reader may be looking at a different slide
			// than the sentence describes. As a POINTER to something the
			// extraction lost it is the most useful sentence on the page,
			// because the viewer beside it is open on exactly that slide. A
			// blanket ban suppressed the good use along with the bad; measured
			// on the first attempt, 9 of the 12 survivors were pointers.
			. "- Do not narrate the slides. Never write \"the slide explains\", \"the slide indicates\", \"the slide provides\", \"the slides note\" or \"the lecture above\": this text sits beside the slides, so a sentence ABOUT a slide reads as being about whichever one the student is looking at. State the material directly — \"least squares minimises the sum of squared residuals\", never \"the slide explains least squares\".\n"
			. "- Do keep referring to the slides when sending the reader TO them for something you cannot reproduce — \"the exact expression is on the slide\" is exactly right, because the viewer beside this text is open on it.\n"
			// Two abstract rounds of this moved the count from 9 to 10, i.e.
			// nothing. These are real sentences from real output, supplied by
			// the person who read them beside the viewer. Concrete
			// before/after is a different instrument from a rule, and the
			// doubled reference is one neither of us saw as a category until
			// the sentences were read in context rather than counted.
			. "\nExamples, from real output on this deck:\n"
			. "  NO   \"The slide indicates that these equations can be solved by Gaussian elimination.\"\n"
			. "  YES  \"These equations can be solved by Gaussian elimination.\"\n"
			. "  NO   \"The slide makes the claim that the solutions obtained this way are minimisers.\"\n"
			. "  YES  \"The solutions obtained this way are minimisers.\"\n"
			. "       (\"makes the claim\" is worse than the deixis: it distances you from the\n"
			. "        mathematics, so a student cannot tell whether the result is established or\n"
			. "        merely being reported. The lecture states it; state it.)\n"
			. "  NO   \"The slide indicates that the exact steps and final expression for w are shown\n"
			. "        on the slide; consult it for the derivation.\"\n"
			. "  YES  \"The exact steps and final expression for w are on the slide.\"\n"
			. "       (Never narrate a slide AND point at it in one sentence — that names the same\n"
			. "        slide twice and says nothing with the first mention.)\n"
			. "  YES  \"The exact expression is on the slide; copy it verbatim when reviewing.\"\n"
			. "       (Keep these. Beside the viewer the reader can act on it immediately.)\n"
			. "- Where the extraction has lost something — a formula that was an image, a symbol that came through as stray letters — say that the original carries a formula at this point and should be consulted, rather than reconstructing it. A guessed formula is the worst thing this can produce.\n"
			. "- Ignore anything administrative: assignment deadlines, office hours, forum threads, reading links.\n"
			. "- Open with one short paragraph on what this section is about. Then the substance, under `##` headings that follow the lecture's own order.\n"
			. "- Define each term the first time the lecture uses it.\n"
			. "- Keep the lecturer's own terminology, including where they note that different fields use different words for the same thing.\n"
			. "- Markdown only. No preamble, no sign-off, no \"in this lesson we will\".\n"
			// The renderer converts a known set of LaTeX commands to plain text
			// and passes the rest through untouched, so anything unmapped
			// reaches the student as source. That map is a safety net and this
			// is the source: every command not emitted is one nobody has to
			// add. Concrete strings because two rounds of abstract rules moved
			// nothing on the last register problem and four real sentences
			// moved it immediately — these are the ones this deck produced.
			. "- Plain text for every symbol. NO LaTeX, and that includes the forms that look harmless:\n"
			. "    \\hat y_i or \\hat{y}   ->  y-hat\n"
			. "    \\mathsf T, \\top      ->  T          (as in x_i^T)\n"
			. "    \\| y - Xw \\|         ->  ||y - Xw||\n"
			. "    \\min_w, \\sum_{j=1}   ->  min over w, sum from j=1\n"
			. "    \\begin{bmatrix} … \\end{bmatrix}  ->  describe the matrix in words, or put the\n"
			. "                                          rows in a fenced code block\n"
			. "  A matrix is two-dimensional and this page is a line of text, so an environment cannot\n"
			. "  survive the conversion — it reaches the student as source either way.\n\n"
			. "SLIDES:\n" . $text;

		$result = EduAI_Claude::message(
			array( array( 'role' => 'user', 'content' => $instruction ) ),
			$system,
			array(
				'model'       => 'strongest',
				// Low, not zero: this is prose, and zero makes it list-shaped.
				'temperature' => 0.2,
				'max_tokens'  => 2400,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$html = EduAI_REST::to_html( self::breakable( (string) $result['text'] ) );

		return $html . self::provenance( $section, $post_id );
	}

	/**
	 * Let the browser break lines where it needs to.
	 *
	 * The model reaches for typographically "correct" characters that a text
	 * generator has no way to know the consequences of: NARROW NO-BREAK SPACE
	 * and NON-BREAKING HYPHEN. Both are invisible in the output and both weld a
	 * phrase into a single token no browser may ever wrap.
	 *
	 * Measured across three lesson bodies: 51 narrow no-break spaces and 35
	 * non-breaking hyphens, making `Multi‑Variable` and `linear‑regression`
	 * unbreakable. Nothing overflows today — the widest is 166px in a 317px
	 * column — but that margin is a typographic accident rather than a
	 * decision, and a compound like `maximum‑likelihood‑estimation` is twice
	 * the length of anything currently there.
	 *
	 * A substitution, not a judgement, which is why it lives in code rather
	 * than in the prompt: one character in, one character out, no meaning
	 * either way. The characters that DO carry meaning are deliberately left
	 * alone — subscripts, the partial-differential sign, Greek letters, the en
	 * dash. They render correctly and they say something.
	 *
	 * @param string $text Model output.
	 */
	private static function breakable( string $text ): string {
		return strtr(
			$text,
			array(
				"\u{2011}" => '-',  // NON-BREAKING HYPHEN
				"\u{202F}" => ' ',  // NARROW NO-BREAK SPACE
				"\u{00A0}" => ' ',  // NO-BREAK SPACE — same class, not yet observed
				"\u{2060}" => '',   // WORD JOINER — invisible and purely welding
			)
		);
	}

	/**
	 * The line that says where this lesson came from.
	 *
	 * Not decoration. A generated lesson that does not name its source is a
	 * paraphrase presented as the lecture, and the student has no way to check
	 * it against the thing they will actually be examined on.
	 *
	 * @param array $section One section.
	 * @param int   $post_id Source material.
	 */
	private static function provenance( array $section, int $post_id ): string {
		$title = trim( wp_strip_all_tags( (string) get_the_title( $post_id ) ) );
		$url   = (string) get_permalink( $post_id );
		$count = count( $section['slides'] ?? array() );

		return "\n" . '<p class="eduai-lesson__source"><em>' . sprintf(
			/* translators: 1: number of slides 2: linked lecture title */
			esc_html__( 'Written from %1$s slides of %2$s. Check the original for anything that matters.', 'eduai' ),
			esc_html( (string) $count ),
			$url
				? '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>'
				: esc_html( $title )
		) . '</em></p>';
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
