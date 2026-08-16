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

		<ol class="eduai-prep__flow" aria-label="<?php esc_attr_e( 'How PrepareME works', 'eduai' ); ?>">
			<li>
				<?php if ( ! empty( $eduai_scope['title'] ) ) : ?>
					<b><?php esc_html_e( '1 · This lesson', 'eduai' ); ?></b>
					<span><?php esc_html_e( 'Already loaded — nothing to upload. Attach a file only if you want a different source.', 'eduai' ); ?></span>
				<?php else : ?>
					<b><?php esc_html_e( '1 · Upload', 'eduai' ); ?></b>
					<span><?php esc_html_e( 'A lecture PDF, slide deck, document or pasted text.', 'eduai' ); ?></span>
				<?php endif; ?>
			</li>
			<li>
				<b><?php esc_html_e( '2 · Sit the exam', 'eduai' ); ?></b>
				<span><?php esc_html_e( 'Your choice of 5, 10 or 20 questions across easy, medium and hard.', 'eduai' ); ?></span>
			</li>
			<li>
				<b><?php esc_html_e( '3 · Marked report', 'eduai' ); ?></b>
				<span><?php esc_html_e( 'Multiple choice marked in code; short answers by the model, with corrections.', 'eduai' ); ?></span>
			</li>
		</ol>

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

		<p class="eduai-prep__go">
			<button type="button" class="eduai-btn eduai-btn--primary" data-eduai-prep-generate>
				<?php esc_html_e( 'Generate my exam', 'eduai' ); ?>
			</button>
		</p>

		<div data-eduai-prep-setup-out aria-live="polite"></div>
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
