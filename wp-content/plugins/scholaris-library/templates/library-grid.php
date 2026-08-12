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
							?>
							<ul class="sl-course__lessons">
								<?php foreach ( $sl_shown as $sl_lesson ) : ?>
									<li>
										<a href="<?php echo esc_url( (string) ( $sl_lesson['url'] ?? '' ) ); ?>">
											<?php echo esc_html( (string) ( $sl_lesson['title'] ?? '' ) ); ?>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
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
							<p class="sl-course__from">
								<span class="sl-course__fromlabel"><?php esc_html_e( 'From', 'scholaris-library' ); ?></span>
								<a href="<?php echo esc_url( (string) get_permalink( (int) $sl_mid ) ); ?>">
									<?php echo esc_html( $sl_mtitle ); ?>
								</a>
							</p>
						<?php endforeach; ?>
					</article>
				<?php endforeach; ?>
			</div>
		</section>

		<h2 class="sl-courses__title sl-courses__title--loose">
			<?php esc_html_e( 'Material not yet in a course', 'scholaris-library' ); ?>
		</h2>
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
				<article class="sl-card">
					<a class="sl-card__thumb" href="<?php the_permalink(); ?>" aria-hidden="true" tabindex="-1">
						<?php if ( has_post_thumbnail() ) : ?>
							<?php the_post_thumbnail( 'medium' ); ?>
						<?php else : ?>
							<?php
							// The document's type when there is one, VIDEO when
							// the material is a lecture recording, and only then
							// the old DOC fallback. A student should not have to
							// open a card to find out which it is.
							$sl_chip = $sl_ext ?: ( $sl_has_video ? __( 'VIDEO', 'scholaris-library' ) : 'DOC' );
							?>
							<span class="sl-card__ext"><?php echo esc_html( $sl_chip ); ?></span>
						<?php endif; ?>
					</a>

					<div class="sl-card__body">
						<div class="sl-card__tags">
							<?php if ( $sl_subjects && ! is_wp_error( $sl_subjects ) ) : ?>
								<span class="sl-badge sl-badge--brand"><?php echo esc_html( $sl_subjects[0]->name ); ?></span>
							<?php endif; ?>
							<?php if ( $sl_types && ! is_wp_error( $sl_types ) ) : ?>
								<span class="sl-badge"><?php echo esc_html( $sl_types[0]->name ); ?></span>
							<?php endif; ?>
						</div>

						<h3 class="sl-card__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
						<p class="sl-card__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 20 ) ); ?></p>
					</div>

					<div class="sl-card__foot">
						<span class="sl-card__meta">
							<?php
							$sl_bits = array_filter( array(
								$sl_ext,
								// Listed alongside the document type rather than
								// instead of it: a material can carry both, and
								// the thumb chip only has room for one of them.
								$sl_has_video ? __( 'Video', 'scholaris-library' ) : '',
								$sl_pages ? sprintf(
									/* translators: %d: pages */
									_n( '%d page', '%d pages', $sl_pages, 'scholaris-library' ),
									$sl_pages
								) : '',
								get_the_date(),
							) );
							echo esc_html( implode( ' · ', $sl_bits ) );
							?>
						</span>
						<a class="sl-btn sl-btn--quiet" href="<?php the_permalink(); ?>">
							<?php esc_html_e( 'Open', 'scholaris-library' ); ?> →
						</a>
					</div>
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
		<div class="sl-notice">
			<h3><?php esc_html_e( 'No material matches those filters', 'scholaris-library' ); ?></h3>
			<p><?php esc_html_e( 'Try a broader search, or clear the filters to see everything.', 'scholaris-library' ); ?></p>
			<a class="sl-btn sl-btn--primary" href="<?php echo esc_url( get_post_type_archive_link( 'study_material' ) ?: home_url( '/library/' ) ); ?>">
				<?php esc_html_e( 'Show everything', 'scholaris-library' ); ?>
			</a>
		</div>
	<?php endif; ?>
</div>
