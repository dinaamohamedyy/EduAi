<?php
/**
 * Library grid with filter bar.
 *
 * @var WP_Query $query   Results.
 * @var array    $atts    Shortcode attributes.
 * @var string   $subject Active subject slug.
 * @var string   $type    Active type slug.
 * @var string   $search  Active search term.
 *
 * @package ScholarisLibrary
 */

defined( 'ABSPATH' ) || exit;

/*
 * The library is courses first, then whatever has not been made into one.
 * The owner's words: "the library consists of courses and each course consists
 * of lessons." Materials were never meant to be the browsable unit — but they
 * are not hidden either, because a deck that has not been segmented yet is
 * still his, and making it vanish until someone builds a course around it is
 * how you lose work you just uploaded. He confirmed that reading directly.
 *
 * Lessons deliberately do NOT appear on a card. A card whose height varies
 * with content stops being scannable at four courses, and the course page has
 * to exist anyway — so the card links and the page lists.
 */
$sl_tree    = class_exists( 'SL_Catalog' ) ? SL_Catalog::tree() : array( 'courses' => array(), 'loose' => array() );
$sl_courses = $sl_tree['courses'] ?? array();

/*
 * Materials already taught by a course are dropped from the grid below rather
 * than the grid being replaced: $query still carries the search, the taxonomy
 * filters and the pagination the shortcode built, and throwing it away to read
 * $sl_tree['loose'] would silently break all three. Filtering the loop keeps
 * one query and one meaning.
 */
$sl_in_course = array();

foreach ( $sl_courses as $sl_course ) {
	foreach ( (array) ( $sl_course['materials'] ?? array() ) as $sl_mid ) {
		$sl_in_course[ (int) $sl_mid ] = true;
	}
}

/*
 * Does the loop below actually have anything left after the course-attached
 * decks are skipped? The heading was printed whenever courses existed, so on
 * this install it announced "Standalone material" above nothing at all —
 * invisible while loose material was the normal case, and about to be the
 * normal case itself once every segmentable deck becomes a course. Ask the
 * same query the loop will walk, so the heading cannot disagree with it.
 */
$sl_has_loose = false;

if ( isset( $query ) && $query instanceof WP_Query ) {
	foreach ( (array) $query->posts as $sl_p ) {
		$sl_pid = is_object( $sl_p ) ? (int) $sl_p->ID : (int) $sl_p;
		if ( $sl_pid && ! isset( $sl_in_course[ $sl_pid ] ) ) {
			$sl_has_loose = true;
			break;
		}
	}
}
?>
<div class="sl-library" data-sl-library>

	<?php if ( $sl_courses ) : ?>
		<section class="sl-courses">
			<h2 class="sl-courses__title"><?php esc_html_e( 'Courses', 'scholaris-library' ); ?></h2>

			<div class="sl-courses__grid">
				<?php foreach ( $sl_courses as $sl_course ) : ?>
					<article class="sl-course">
						<h3 class="sl-course__title">
							<a href="<?php echo esc_url( (string) $sl_course['url'] ); ?>">
								<?php echo esc_html( (string) $sl_course['title'] ); ?>
							</a>
						</h3>

						<p class="sl-course__meta">
							<?php
							$sl_n = (int) ( $sl_course['lessons'] ?? 0 );
							printf(
								esc_html(
									/* translators: %d: number of lessons */
									_n( '%d lesson', '%d lessons', $sl_n, 'scholaris-library' )
								),
								(int) $sl_n
							);
							?>
						</p>

						<?php
						/*
						 * LESSONS ON THE CARD — and this reverses my own earlier
						 * call, for a reason that only appeared by measuring.
						 *
						 * I argued lessons belonged on the course page, not the
						 * card, because a card whose height varies with content
						 * stops being scannable. That was sound while the course
						 * page was assumed to list them. It does not: Tutor
						 * renders each lesson as a NON-CLICKABLE <h5> inside a
						 * collapsed accordion — no anchor anywhere — verified on
						 * /courses/machine-learning/ as both anonymous and the
						 * owner, who sees exactly what a visitor sees. The
						 * titles are in the page; nothing links to them.
						 *
						 * So the choice was not "card or course page", it was
						 * "card or nowhere". The owner asked for the library to
						 * consist of courses and each course to consist of
						 * lessons; leaving them unreachable to protect the
						 * card's height is optimising the wrong thing.
						 *
						 * The scannability objection is answered by the cap
						 * rather than by omission: height is bounded at five
						 * whatever the course contains, and the remainder is a
						 * count rather than a scroll.
						 */
						$sl_lessons = array();

						foreach ( (array) ( $sl_course['topics'] ?? array() ) as $sl_topic ) {
							foreach ( (array) ( $sl_topic['lessons'] ?? array() ) as $sl_lesson ) {
								$sl_lessons[] = $sl_lesson;
							}
						}

						if ( $sl_lessons ) :
							$sl_shown = array_slice( $sl_lessons, 0, 5 );

							/*
							 * Enrolment is per-visitor, so it is computed HERE
							 * rather than inside SL_Catalog::tree(). The tree is
							 * cached and shared across everyone; folding a
							 * per-user answer into it would have made a shared
							 * cache per-visitor to serve one line of markup.
							 * Back-end offered the field and flagged that cost
							 * themselves — the fact belongs outside the cache,
							 * not inside a differently-keyed one.
							 */
							$sl_enrolled = function_exists( 'tutor_utils' )
								&& is_user_logged_in()
								&& tutor_utils()->is_enrolled( (int) $sl_course['id'], get_current_user_id() );
							?>
							<?php
							/*
							 * DIM THE AFFORDANCE, NOT THE INFORMATION.
							 *
							 * Enrolled: real anchors. Not enrolled: the same
							 * titles at full contrast, a muted lock, no link.
							 * Greying the titles would turn a table of contents
							 * into a wall — and the titles are the reason to
							 * enrol in the first place.
							 *
							 * This is what my own c4ba888 revert was reaching
							 * for and got wrong by deleting the list: these
							 * links go to Tutor's learning area, which emits
							 * THREE concatenated documents to anyone without
							 * access. A plain anchor promised a page the
							 * visitor would be bounced from. Withholding the
							 * anchor rather than the information fixes that
							 * without costing the owner the thing he approved.
							 *
							 * Same shape as the server deciding a button's
							 * label rather than only its href: the promise and
							 * the delivery are decided in one place.
							 */
							?>
							<ol class="sl-course__lessons<?php echo $sl_enrolled ? '' : ' sl-course__lessons--locked'; ?>">
								<?php foreach ( $sl_shown as $sl_lesson ) : ?>
									<li>
										<?php if ( $sl_enrolled ) : ?>
											<a href="<?php echo esc_url( (string) ( $sl_lesson['url'] ?? '' ) ); ?>">
												<?php echo esc_html( (string) ( $sl_lesson['title'] ?? '' ) ); ?>
											</a>
										<?php else : ?>
											<span class="sl-course__lesson">
												<?php echo esc_html( (string) ( $sl_lesson['title'] ?? '' ) ); ?>
											</span>
											<svg class="sl-course__lock" width="11" height="11" viewBox="0 0 24 24"
												fill="none" stroke="currentColor" stroke-width="2.4"
												stroke-linecap="round" aria-hidden="true">
												<rect x="4" y="11" width="16" height="10" rx="2"/>
												<path d="M8 11V7a4 4 0 0 1 8 0v4"/>
											</svg>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ol>
							<?php if ( count( $sl_lessons ) > count( $sl_shown ) ) : ?>
								<p class="sl-course__more">
									<a href="<?php echo esc_url( (string) $sl_course['url'] ); ?>">
										<?php
										printf(
											esc_html(
												/* translators: %d: how many further lessons the course has */
												_n( '%d more lesson', '%d more lessons', count( $sl_lessons ) - count( $sl_shown ), 'scholaris-library' )
											),
											(int) ( count( $sl_lessons ) - count( $sl_shown ) )
										);
										?>
									</a>
								</p>
							<?php endif; ?>
						<?php else : ?>
							<?php
							/*
							 * A course with no lessons rendered as a title, the
							 * words "0 lessons" and a large empty box — exactly
							 * the blank the standalone card exists to avoid,
							 * arrived at from the other direction. Seen on the
							 * live library the moment a second course appeared.
							 *
							 * It gets the same one-row treatment: the course
							 * page is a real place even before it has lessons,
							 * so the row is a genuine way in rather than a
							 * restatement of the count above it.
							 */
							?>
							<ul class="sl-course__lessons sl-course__lessons--whole">
								<li>
									<svg class="sl-course__doc" width="11" height="11" viewBox="0 0 24 24"
										fill="none" stroke="currentColor" stroke-width="2.2"
										stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
										<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/>
										<path d="M14 3v5h5"/>
									</svg>
									<a href="<?php echo esc_url( (string) $sl_course['url'] ); ?>">
										<?php esc_html_e( 'Open the course', 'scholaris-library' ); ?>
									</a>
								</li>
							</ul>
						<?php endif; ?>

						<?php
						/*
						 * The deck it was built from, because that is the thing
						 * the owner recognises — he uploaded it. Deliberately
						 * NOT a progress bar: a percentage on a browse screen
						 * invites comparison between students rather than
						 * orientation within a course.
						 */
						foreach ( (array) ( $sl_course['materials'] ?? array() ) as $sl_mid ) :
							$sl_mtitle = get_the_title( (int) $sl_mid );
							if ( '' === $sl_mtitle ) {
								continue;
							}
							?>
							<?php
							/*
							 * Link only if the target can actually be opened.
							 * Normally it can: the pipeline reads a deck, writes
							 * lessons and leaves the deck alone — which is why a
							 * lesson carries _eduai_source_material at all — so
							 * this anchor is a working route from a lesson back
							 * to its source and must stay one.
							 *
							 * It is a condition, not a constant. A deck that has
							 * been trashed or restricted 404s for visitors, and
							 * that is reached by someone deleting it, not by
							 * segmentation. I first wrote this comment claiming
							 * segmentation trashed the deck; it does not, and a
							 * comment asserting a cause is exactly as misleading
							 * as code doing the wrong thing — it made a working
							 * conditional read as a feature removal.
							 *
							 * Same rule as the locked lesson a few lines up:
							 * withhold the anchor, not the information. Verified
							 * both ways rather than on the state that happened to
							 * be present — trashed post: false, name only;
							 * published post: true, anchor.
							 */
							$sl_mlive = is_post_publicly_viewable( (int) $sl_mid )
								|| current_user_can( 'read_post', (int) $sl_mid );
							?>
							<p class="sl-course__from">
								<span class="sl-course__fromlabel"><?php esc_html_e( 'From', 'scholaris-library' ); ?></span>
								<?php if ( $sl_mlive ) : ?>
									<a href="<?php echo esc_url( (string) get_permalink( (int) $sl_mid ) ); ?>">
										<?php echo esc_html( $sl_mtitle ); ?>
									</a>
								<?php else : ?>
									<span class="sl-course__fromname"><?php echo esc_html( $sl_mtitle ); ?></span>
								<?php endif; ?>
							</p>
						<?php endforeach; ?>
					</article>
				<?php endforeach; ?>
			</div>
		</section>

		<?php
		/*
		 * "Material not yet in a course" defined these by what they lack, which
		 * was fair while loose material was the normal case. Once every deck
		 * that CAN be segmented becomes a course, the ones left are the ones
		 * that legitimately cannot be — and a heading naming an absence turns
		 * a correct refusal by has_markers() into a backlog the owner thinks
		 * he has to clear. Name the property instead: they stand alone.
		 */
		?>
		<?php if ( $sl_has_loose ) : ?>
			<h2 class="sl-courses__title sl-courses__title--loose">
				<?php esc_html_e( 'Standalone material', 'scholaris-library' ); ?>
			</h2>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( 'yes' === $atts['filters'] ) : ?>
		<form class="sl-filters" method="get" action="<?php echo esc_url( get_post_type_archive_link( 'study_material' ) ?: home_url( '/library/' ) ); ?>">
			<div class="sl-filters__search">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
					<circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/>
				</svg>
				<label class="screen-reader-text" for="sl-q"><?php esc_html_e( 'Search the library', 'scholaris-library' ); ?></label>
				<input type="search" id="sl-q" name="q" value="<?php echo esc_attr( $search ); ?>"
					placeholder="<?php esc_attr_e( 'Search titles and descriptions…', 'scholaris-library' ); ?>">
			</div>

			<label class="screen-reader-text" for="sl-subject"><?php esc_html_e( 'Subject', 'scholaris-library' ); ?></label>
			<select id="sl-subject" name="subject" data-sl-autosubmit>
				<option value=""><?php esc_html_e( 'All subjects', 'scholaris-library' ); ?></option>
				<?php
				foreach ( get_terms( array( 'taxonomy' => 'material_subject', 'hide_empty' => true ) ) as $sl_term ) :
					if ( is_wp_error( $sl_term ) ) {
						continue;
					}
					?>
					<option value="<?php echo esc_attr( $sl_term->slug ); ?>" <?php selected( $subject, $sl_term->slug ); ?>>
						<?php echo esc_html( $sl_term->name ); ?> (<?php echo esc_html( (string) $sl_term->count ); ?>)
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="sl-type"><?php esc_html_e( 'Type', 'scholaris-library' ); ?></label>
			<select id="sl-type" name="type" data-sl-autosubmit>
				<option value=""><?php esc_html_e( 'All types', 'scholaris-library' ); ?></option>
				<?php
				foreach ( get_terms( array( 'taxonomy' => 'material_type', 'hide_empty' => true ) ) as $sl_term ) :
					if ( is_wp_error( $sl_term ) ) {
						continue;
					}
					?>
					<option value="<?php echo esc_attr( $sl_term->slug ); ?>" <?php selected( $type, $sl_term->slug ); ?>>
						<?php echo esc_html( $sl_term->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<button type="submit" class="sl-btn sl-btn--primary"><?php esc_html_e( 'Filter', 'scholaris-library' ); ?></button>

			<?php if ( $search || $subject || $type ) : ?>
				<a class="sl-btn sl-btn--quiet" href="<?php echo esc_url( get_post_type_archive_link( 'study_material' ) ?: home_url( '/library/' ) ); ?>">
					<?php esc_html_e( 'Clear', 'scholaris-library' ); ?>
				</a>
			<?php endif; ?>
		</form>
	<?php endif; ?>

	<?php if ( $query->have_posts() ) : ?>
		<p class="sl-count">
			<?php
			printf(
				/* translators: %s: number of documents */
				esc_html( _n( '%s document', '%s documents', (int) $query->found_posts, 'scholaris-library' ) ),
				esc_html( number_format_i18n( (int) $query->found_posts ) )
			);
			?>
		</p>

		<div class="sl-grid" style="--sl-cols:<?php echo esc_attr( (string) (int) $atts['columns'] ); ?>">
			<?php
			while ( $query->have_posts() ) :
				$query->the_post();

				// A deck already taught by a course is shown as that course,
				// not twice. Skipped in the loop rather than excluded from the
				// query so search, filters and pagination keep working.
				if ( isset( $sl_in_course[ get_the_ID() ] ) ) {
					continue;
				}

				$sl_id       = get_the_ID();
				$sl_file_id  = (int) get_post_meta( $sl_id, '_scholaris_file_id', true );
				$sl_pages    = (int) get_post_meta( $sl_id, '_scholaris_pages', true );
				$sl_subjects = get_the_terms( $sl_id, 'material_subject' );
				$sl_types    = get_the_terms( $sl_id, 'material_type' );
				$sl_ext      = $sl_file_id ? strtoupper( (string) pathinfo( (string) get_attached_file( $sl_file_id ), PATHINFO_EXTENSION ) ) : '';

				// A video-only material has no attached file, so $sl_ext is ''
				// and the chip below fell back to 'DOC' — the listing labelled
				// every lecture a document.
				$sl_has_video = SL_Meta::has_video( $sl_id );
				?>
				<?php
				/*
				 * THE SAME CARD AS A COURSE, because the owner asked for exactly
				 * that — "I want every material I upload to look like this" — and
				 * because a deck that cannot be segmented is not a lesser kind of
				 * object, just one with a different unit inside it.
				 *
				 * Four zones, matching the course card one for one, each filled
				 * with what is true of THIS object rather than left blank:
				 *
				 *   title  -> title
				 *   meta   -> "44 pages"          where a course says "3 lessons"
				 *   body   -> one row, the way in  where a course lists lessons
				 *   foot   -> its subject          where a course names its deck
				 *
				 * The body row deliberately carries a document mark and NOT the
				 * number 1. has_markers() refuses to fake a sectionless deck into
				 * a single lesson containing everything; a numbered row of one
				 * would put that same lie back on the card, in the one place the
				 * owner actually looks.
				 */
				?>
				<article class="sl-course sl-course--single">
					<h3 class="sl-course__title">
						<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
					</h3>

					<?php
					// Mirrors "3 lessons": the same question — how much is in
					// here — answered in the unit this object actually has.
					$sl_count = implode( ' · ', array_filter( array(
						$sl_pages ? sprintf(
							/* translators: %d: pages */
							esc_html( _n( '%d page', '%d pages', $sl_pages, 'scholaris-library' ) ),
							$sl_pages
						) : '',
						$sl_has_video ? esc_html__( 'Video', 'scholaris-library' ) : '',
						$sl_ext,
					) ) );
					if ( '' === $sl_count && $sl_types && ! is_wp_error( $sl_types ) ) {
						$sl_count = $sl_types[0]->name;
					}
					?>
					<?php if ( '' !== $sl_count ) : ?>
						<p class="sl-course__meta"><?php echo esc_html( $sl_count ); ?></p>
					<?php endif; ?>

					<ul class="sl-course__lessons sl-course__lessons--whole">
						<li>
							<svg class="sl-course__doc" width="11" height="11" viewBox="0 0 24 24"
								fill="none" stroke="currentColor" stroke-width="2.2"
								stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
								<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/>
								<path d="M14 3v5h5"/>
							</svg>
							<a href="<?php the_permalink(); ?>">
								<?php esc_html_e( 'Open the document', 'scholaris-library' ); ?>
							</a>
						</li>
					</ul>

					<?php
					// The foot slot a course uses to name the deck it came from.
					// A standalone material has no parent to name, so it carries
					// the only provenance it has — leaving the slot empty would
					// make the card read as a course missing its source.
					$sl_prov = '';
					if ( $sl_subjects && ! is_wp_error( $sl_subjects ) ) {
						$sl_prov = $sl_subjects[0]->name;
					} elseif ( $sl_types && ! is_wp_error( $sl_types ) ) {
						$sl_prov = $sl_types[0]->name;
					}
					?>
					<?php if ( '' !== $sl_prov ) : ?>
						<p class="sl-course__from">
							<span class="sl-course__fromlabel"><?php esc_html_e( 'Subject', 'scholaris-library' ); ?></span>
							<span class="sl-course__subject"><?php echo esc_html( $sl_prov ); ?></span>
						</p>
					<?php endif; ?>
				</article>
			<?php endwhile; ?>
		</div>

		<?php
		$sl_pagination = paginate_links( array(
			'total'     => (int) $query->max_num_pages,
			'current'   => max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) ),
			'type'      => 'list',
			'prev_text' => esc_html__( '← Previous', 'scholaris-library' ),
			'next_text' => esc_html__( 'Next →', 'scholaris-library' ),
		) );

		if ( $sl_pagination ) {
			echo '<nav class="sl-pagination">' . wp_kses_post( $sl_pagination ) . '</nav>';
		}
		?>
	<?php else : ?>
		<?php
		/*
		 * Three empty states, not one. The single branch this replaces blamed
		 * filters that were not set: with everything filed into a course and
		 * nothing typed, the page read "No material matches those filters —
		 * try a broader search", and its "Show everything" button linked to
		 * the unfiltered archive, which is the page you are already on. So the
		 * remedy was a no-op and the message described a failure that had not
		 * happened — the ordinary outcome, reported as a fault.
		 *
		 * Only the first of these is an error. The second is the goal.
		 */
		$sl_filtered = ( '' !== $search || '' !== $subject || '' !== $type );
		?>
		<div class="sl-notice">
			<?php if ( $sl_filtered ) : ?>
				<h3 class="is-plain"><?php esc_html_e( 'No material matches those filters', 'scholaris-library' ); ?></h3>
				<p><?php esc_html_e( 'Try a broader search, or clear the filters to see everything.', 'scholaris-library' ); ?></p>
				<a class="sl-btn sl-btn--primary" href="<?php echo esc_url( get_post_type_archive_link( 'study_material' ) ?: home_url( '/library/' ) ); ?>">
					<?php esc_html_e( 'Show everything', 'scholaris-library' ); ?>
				</a>
			<?php elseif ( $sl_courses ) : ?>
				<h3 class="is-plain"><?php esc_html_e( 'Everything is filed into a course', 'scholaris-library' ); ?></h3>
				<p><?php esc_html_e( 'There is no loose material in the library right now — it is all in one of the courses above.', 'scholaris-library' ); ?></p>
			<?php else : ?>
				<h3 class="is-plain"><?php esc_html_e( 'Nothing in the library yet', 'scholaris-library' ); ?></h3>
				<p><?php esc_html_e( 'Lecture slides, notes and past papers will appear here once they are added.', 'scholaris-library' ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
