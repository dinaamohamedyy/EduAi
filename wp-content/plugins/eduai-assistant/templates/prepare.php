<?php
/**
 * PrepareME — upload a lecture, sit an exam generated from it, get it marked.
 * Rendered by [eduai_prepare].
 *
 * Three stages in one container, shown one at a time: setup, the paper, the
 * marked report. They are separate elements rather than re-rendered markup so
 * the browser keeps scroll position and screen readers keep their place.
 *
 * The paper is rendered from the withheld projection only — answer_index,
 * expected and explanation are never in this DOM before marking. That is a ship
 * gate, not a detail (docs/06 §2.4): a student who opens dev-tools on a leaking
 * page has the answer key, and no static check in this repository can see it.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

$eduai_prep_id = 'eduai-prep-' . wp_unique_id();
?>
<div class="eduai-prep" id="<?php echo esc_attr( $eduai_prep_id ); ?>" data-eduai-prep>

	<!-- ------------------------------------------------------------ setup -->
	<div data-eduai-prep-stage="setup">

		<?php
		/*
		 * ONE PANEL, three bands: head, body, foot.
		 *
		 * The parts of this screen were right and the construction was missing —
		 * a scope banner, a picker, a disclosure, a length control and a button
		 * sat as five detached blocks on the page background with nothing
		 * holding them together, which is what made a finished feature look
		 * unfinished. Setting the exam is one task, so it is one surface: what
		 * it is at the top, the choices in the middle, and the commit on a
		 * divided footer where a form's action belongs.
		 */
		?>
		<div class="eduai-panel">

			<div class="eduai-panel__head">
				<h2 class="eduai-panel__title is-plain"><?php esc_html_e( 'Practice exam', 'eduai' ); ?></h2>
				<p class="eduai-panel__lede">
					<?php esc_html_e( 'Sit a paper generated from your own material, marked with corrections.', 'eduai' ); ?>
				</p>
			</div>

			<div class="eduai-panel__body">

		<?php
		/*
		 * Say which lesson this is about, before anything is pressed.
		 *
		 * A student arriving from "Prepare me on this lesson" was landing on
		 * a page whose first instruction is "Upload a lecture" — with the
		 * lesson already loaded and nothing on screen saying so. The scope
		 * was reaching the script and never reaching the reader, which is the
		 * promise-and-delivery gap the Summarise banner exists to close; same
		 * markup, same class, so it styles identically.
		 */
		if ( ! empty( $eduai_scope['title'] ) ) :
			?>
			<p class="eduai-scope">
				<span class="eduai-scope__label"><?php esc_html_e( 'Exam on', 'eduai' ); ?></span>
				<strong class="eduai-scope__name"><?php echo esc_html( $eduai_scope['title'] ); ?></strong>
			</p>
		<?php endif; ?>

		<?php
		/*
		 * PICK A LESSON — the primary path, and the one that was missing.
		 *
		 * Unscoped, this page's first instruction was "Upload a lecture", so a
		 * student whose lectures are already in the library was asked to find
		 * and re-upload a file the site is holding for them. The scoping
		 * machinery already existed — ?source=<lesson id>, the same parameter
		 * Ask and Summarise use — and nothing on this page ever offered it.
		 * Every one of these links is that parameter; none of it is new
		 * plumbing.
		 *
		 * So the hierarchy inverts. The common case is "examine me on something
		 * I already have", and it is now the page's content rather than a
		 * missing option. Uploading your own file is still here, one disclosure
		 * down, for the material that is not in the library yet.
		 */
		$eduai_tree    = class_exists( 'SL_Catalog' ) ? SL_Catalog::tree() : array( 'courses' => array() );
		$eduai_courses = (array) ( $eduai_tree['courses'] ?? array() );
		$eduai_scoped  = ! empty( $eduai_scope['title'] );
		?>

		<?php if ( ! $eduai_scoped && $eduai_courses ) : ?>
			<section class="eduai-pick">
				<h3 class="eduai-pick__title is-plain"><?php esc_html_e( 'What should the exam be on?', 'eduai' ); ?></h3>

				<?php foreach ( $eduai_courses as $eduai_course ) : ?>
					<?php
					$eduai_lessons = array();

					foreach ( (array) ( $eduai_course['topics'] ?? array() ) as $eduai_topic ) {
						foreach ( (array) ( $eduai_topic['lessons'] ?? array() ) as $eduai_lesson ) {
							$eduai_lessons[] = $eduai_lesson;
						}
					}

					if ( ! $eduai_lessons ) {
						continue;
					}
					?>
					<p class="eduai-pick__course"><?php echo esc_html( (string) $eduai_course['title'] ); ?></p>
					<ul class="eduai-pick__list">
						<?php foreach ( $eduai_lessons as $eduai_lesson ) : ?>
							<li>
								<a class="eduai-pick__item"
									href="<?php echo esc_url( eduai_prepare_url( 0, (int) ( $eduai_lesson['id'] ?? 0 ) ) ); ?>">
									<span class="eduai-pick__name"><?php echo esc_html( (string) ( $eduai_lesson['title'] ?? '' ) ); ?></span>
									<span class="eduai-pick__go" aria-hidden="true">&rarr;</span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endforeach; ?>
			</section>
		<?php endif; ?>

		<?php
		/*
		 * Own material: primary when nothing is scoped and the library is
		 * empty, secondary otherwise. <details> rather than a tab — one
		 * element, keyboard-operable for free, and it degrades open if the
		 * CSS never lands.
		 */
		/*
		 * Open ONLY when there is no other way to supply a source — an empty
		 * library and no lesson attached.
		 *
		 * It read `$eduai_scoped || ! $eduai_courses`, which opened the upload
		 * box precisely when the student already had a lesson loaded. The owner
		 * screenshotted the result: "Exam on Least Squares in d-Dimensions —
		 * already loaded, nothing to upload" directly above a wide-open "Drop a
		 * lecture here". The page contradicted itself in adjacent elements, and
		 * the collapsed state that makes it read correctly was one operator away.
		 */
		$eduai_own_open = ! $eduai_scoped && ! $eduai_courses;
		?>
		<details class="eduai-prep__own"<?php echo $eduai_own_open ? ' open' : ''; ?>>
			<summary>
				<?php
				echo $eduai_scoped
					? esc_html__( 'Use a different source instead', 'eduai' )
					: esc_html__( 'Or use your own file or notes', 'eduai' );
				?>
			</summary>

			<div class="eduai-prep__drop" data-eduai-prep-drop tabindex="0" role="button"
				aria-label="<?php esc_attr_e( 'Choose a lecture file', 'eduai' ); ?>">
				<svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor"
					stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>
				</svg>
				<span data-eduai-prep-dropmsg><?php esc_html_e( 'Drop a lecture here, or click to choose', 'eduai' ); ?></span>
				<input type="file" data-eduai-prep-file hidden accept=".pdf,.pptx,.docx,.txt,.md">
			</div>

			<label class="screen-reader-text" for="<?php echo esc_attr( $eduai_prep_id ); ?>-text">
				<?php esc_html_e( 'Lecture text', 'eduai' ); ?>
			</label>
			<textarea id="<?php echo esc_attr( $eduai_prep_id ); ?>-text" data-eduai-prep-text rows="4"
				placeholder="<?php esc_attr_e( '…or paste the lecture text', 'eduai' ); ?>"></textarea>
		</details>

			</div><!-- /.eduai-panel__body -->

			<div class="eduai-panel__foot">
				<div class="eduai-prep__len">
					<span class="eduai-prep__len-label"><?php esc_html_e( 'Questions', 'eduai' ); ?></span>
					<div class="eduai-prep__pick" data-eduai-prep-len role="group"
						aria-label="<?php esc_attr_e( 'Exam length', 'eduai' ); ?>">
						<button type="button" data-len="5">5</button>
						<button type="button" class="is-on" data-len="10">10</button>
						<button type="button" data-len="20">20</button>
					</div>
					<span class="eduai-prep__len-hint"><?php esc_html_e( '40% easy · 40% medium · the rest hard', 'eduai' ); ?></span>
				</div>

				<button type="button" class="eduai-btn eduai-btn--primary eduai-panel__cta" data-eduai-prep-generate>
					<?php esc_html_e( 'Generate my exam', 'eduai' ); ?>
				</button>
			</div>

			<div class="eduai-panel__out" data-eduai-prep-setup-out aria-live="polite"></div>
		</div><!-- /.eduai-panel -->
	</div>

	<!-- ------------------------------------------------------------ paper -->
	<div data-eduai-prep-stage="paper" hidden>
		<h2 class="eduai-prep__title" data-eduai-prep-title></h2>
		<p class="eduai-prep__meta" data-eduai-prep-meta></p>

		<div data-eduai-prep-questions></div>

		<div class="eduai-prep__bar">
			<span class="eduai-prep__count" data-eduai-prep-count></span>
			<span class="eduai-prep__warn" data-eduai-prep-warn></span>
			<button type="button" class="eduai-btn eduai-btn--primary" data-eduai-prep-submit>
				<?php esc_html_e( 'Submit for marking', 'eduai' ); ?>
			</button>
		</div>

		<div data-eduai-prep-paper-out aria-live="polite"></div>
	</div>

	<!-- ----------------------------------------------------------- report -->
	<div data-eduai-prep-stage="report" hidden>
		<div data-eduai-prep-report></div>

		<p class="eduai-prep__again">
			<button type="button" class="eduai-btn" data-eduai-prep-retake>
				<?php esc_html_e( 'Sit it again', 'eduai' ); ?>
			</button>
			<button type="button" class="eduai-btn" data-eduai-prep-new>
				<?php esc_html_e( 'Another lecture', 'eduai' ); ?>
			</button>
		</p>
	</div>
</div>
