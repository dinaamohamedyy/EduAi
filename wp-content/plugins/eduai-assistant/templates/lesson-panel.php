<?php
/**
 * A lesson, as the owner defined it: the material page's viewer, scoped to
 * this lesson's slides, with the tools beside it.
 *
 * His words, pointing at the material page: "inside the course it consists of
 * lessons, each lesson consists of pages like this." So the slides ARE the
 * lesson. The generated prose below them is the explanation of the slides,
 * not the lesson itself.
 *
 * VARIABLES, supplied by EduAI_Scope's caller on tutor_single_content_lesson:
 *
 *   $eduai_lesson_id       int    gated and resolved before we are called
 *   $eduai_lesson_title    string server-read and tag-stripped, never a URL
 *   $eduai_ask_url         string .../ask/?source=<lesson>
 *   $eduai_summarise_url   string .../summarise/?source=<lesson>
 *
 * This file renders only where Tutor has already granted content access: the
 * hook sits after learning-area/index.php's early return at :107, so an
 * unenrolled visitor never reaches it. That is why there is no capability
 * check here — adding one would be a second opinion that has to agree with
 * Tutor's, and the two-predicates-that-agree-today failure is the one this
 * project has spent the most time repairing.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

$eduai_src   = (int) get_post_meta( $eduai_lesson_id, '_eduai_source_material', true );
$eduai_from  = (int) get_post_meta( $eduai_lesson_id, '_eduai_page_from', true );
$eduai_to    = (int) get_post_meta( $eduai_lesson_id, '_eduai_page_to', true );
$eduai_total = $eduai_src ? (int) get_post_meta( $eduai_src, '_scholaris_pages', true ) : 0;

/*
 * ABSENT RANGE IS A REAL STATE, NOT A BUG. The segmenter writes a range only
 * when the extracted block count exactly matches the page count, because block
 * 18 is page 19 only if every page produced exactly one block — one image-only
 * slide shifts every number after it. Where that does not hold it stores
 * nothing rather than something approximate, and a re-upload actively
 * WITHDRAWS a range that has stopped being trustworthy.
 *
 * So an empty value means "we do not know which slides these are", and the
 * honest response is the whole document with no range label — not page 0, and
 * not a guess.
 */
$eduai_has_range = $eduai_from > 0 && $eduai_to >= $eduai_from;

/*
 * The viewer URL is the material's gated download route, the same one the
 * material page uses. can_download() is the material's OWN gate: being
 * enrolled in the course is not the same question as being allowed to open
 * the deck, and conflating them is how a student ends up with a viewer frame
 * that 403s.
 */
/*
 * can_download() answers "is this visitor allowed to open it", which is not
 * the same as "is it still there". A deck that has been trashed passes the
 * permission gate unchanged while the stream route 404s, and the panel then
 * printed "Slides 22-32 of 44" above a frame showing the cannot-display
 * fallback: a true claim over an empty box. The owner's screenshot is exactly
 * that, and nobody had flagged it.
 *
 * The header and the frame have to fail together, the same way a locked lesson
 * withholds its anchor rather than its title. Status is checked here rather
 * than in can_download() because it is a different question — this asks
 * whether the thing exists, not who may read it. Only the states where the
 * stream cannot serve are excluded; private and restricted materials still
 * render for whoever can_download() admits.
 */
$eduai_src_present = $eduai_src
	&& ! in_array( get_post_status( $eduai_src ), array( false, 'trash', 'auto-draft' ), true );

$eduai_can_view = $eduai_src_present
	&& class_exists( 'SL_Meta' )
	&& class_exists( 'SL_Library' )
	&& SL_Meta::can_download( $eduai_src );

$eduai_doc = $eduai_can_view ? SL_Library::download_url( $eduai_src ) : '';
?>
<div class="eduai-lesson">

	<?php if ( $eduai_doc ) : ?>
		<div class="eduai-lesson__viewer">
			<div class="eduai-lesson__bar">
				<strong class="eduai-lesson__name"><?php echo esc_html( $eduai_lesson_title ); ?></strong>

				<?php if ( $eduai_has_range ) : ?>
					<span class="eduai-lesson__range">
						<?php
						if ( $eduai_total > 0 ) {
							printf(
								/* translators: 1: first slide 2: last slide 3: slides in the whole deck */
								esc_html__( 'Slides %1$d–%2$d of %3$d', 'eduai' ),
								(int) $eduai_from,
								(int) $eduai_to,
								(int) $eduai_total
							);
						} else {
							printf(
								/* translators: 1: first slide 2: last slide */
								esc_html__( 'Slides %1$d–%2$d', 'eduai' ),
								(int) $eduai_from,
								(int) $eduai_to
							);
						}
						?>
					</span>
				<?php endif; ?>
			</div>

			<?php
			/*
			 * #page= OPENS at a slide; it cannot bound one. Restricting the
			 * viewer to the range would mean shipping a JS PDF viewer and
			 * owning paging, selection and print from then on — so the range is
			 * stated in words instead, and scrolling past the end lands in the
			 * next lesson's slides. That is a feature for someone finishing a
			 * section, and the whole deck is one gated click away on the
			 * material page regardless: nothing is exposed that was not.
			 */
			$eduai_frag = $eduai_has_range ? '#page=' . (int) $eduai_from . '&view=FitH' : '#view=FitH';
			?>
			<object class="eduai-lesson__frame"
				data="<?php echo esc_url( $eduai_doc . $eduai_frag ); ?>"
				type="application/pdf">
				<div class="eduai-lesson__fallback">
					<p><?php esc_html_e( 'Your browser cannot display these slides inline.', 'eduai' ); ?></p>
					<a class="eduai-btn eduai-btn--primary" href="<?php echo esc_url( $eduai_doc ); ?>">
						<?php esc_html_e( 'Open the slides', 'eduai' ); ?>
					</a>
				</div>
			</object>
		</div>
	<?php endif; ?>

	<?php
	/*
	 * The tools carry the LESSON id, not the deck's: a question about this
	 * lesson should be answered from this lesson's slides, which is the whole
	 * point of the scoping work. Labels name the lesson, so
	 * scoped-link-honesty examines them rather than skipping them.
	 */
	?>
	<?php
	/*
	 * The lesson is named ONCE, here, instead of inside all three labels.
	 *
	 * The old labels each read "<verb> this lesson: <full title>", which was
	 * right about the problem and wrong about the fix: the panel header naming
	 * the lesson is ~700px up, above the viewer, so it has scrolled away by the
	 * time the eye reaches these controls and the buttons genuinely did need to
	 * say what they act on. But restating a long title three times made each
	 * control 26rem wide, so the three stacked into three full-width bars that
	 * dominated the lesson — which is what the owner has been asking us to fix.
	 *
	 * Naming it once, immediately above the row, keeps the context adjacent
	 * (which was the point) and lets the buttons shrink to their verbs. The
	 * full sentence survives on aria-label, so a screen reader still hears
	 * "Summarise this lesson: Supervised Learning Round 2" and loses nothing.
	 */
	?>
	<div class="eduai-lesson__tools">
		<p class="eduai-lesson__toolsfor">
			<?php esc_html_e( 'Study tools for', 'eduai' ); ?>
			<strong><?php echo esc_html( $eduai_lesson_title ); ?></strong>
		</p>

		<div class="eduai-lesson__toolsrow">
			<a class="eduai-btn eduai-btn--primary" href="<?php echo esc_url( $eduai_summarise_url ); ?>"
				aria-label="<?php echo esc_attr( sprintf( /* translators: %s: lesson title */ __( 'Summarise this lesson: %s', 'eduai' ), $eduai_lesson_title ) ); ?>">
				<?php esc_html_e( 'Summarise', 'eduai' ); ?>
			</a>
			<a class="eduai-btn" href="<?php echo esc_url( $eduai_ask_url ); ?>"
				aria-label="<?php echo esc_attr( sprintf( /* translators: %s: lesson title */ __( 'Ask about this lesson: %s', 'eduai' ), $eduai_lesson_title ) ); ?>">
				<?php esc_html_e( 'Ask a question', 'eduai' ); ?>
			</a>
			<?php if ( ! empty( $eduai_prepare_url ) ) : ?>
				<a class="eduai-btn" href="<?php echo esc_url( $eduai_prepare_url ); ?>"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %s: lesson title */ __( 'Prepare me on this lesson: %s', 'eduai' ), $eduai_lesson_title ) ); ?>">
					<?php esc_html_e( 'Practice questions', 'eduai' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
</div>
