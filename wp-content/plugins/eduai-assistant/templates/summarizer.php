<?php
/**
 * Summarise — the lecture summariser page, rendered by [eduai_summarizer].
 *
 * This used to render the whole chat panel forced onto its summary tab: a
 * feature living inside a widget that also carried a chat log, an agent picker
 * and a microphone it never used. docs/06 §2.1 calls the move to a page "a move,
 * not a rewrite" — the endpoint is untouched, only the surface changed.
 *
 * The shortcode name is unchanged so anything already embedding
 * [eduai_summarizer] keeps working.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

$eduai_sum_id = 'eduai-sum-' . wp_unique_id();
?>
<div class="eduai-sum" id="<?php echo esc_attr( $eduai_sum_id ); ?>" data-eduai-sum>

	<?php
	/*
	 * Scope banner — and the id that makes the request scoped travels WITH it.
	 *
	 * VARIABLE CONTRACT: $eduai_scope is an array of [ id, title ], supplied by
	 * the shortcode handler ONLY after it has resolved ?source= and applied the
	 * gate for that post type. Absent, empty, or ungated means this block does
	 * not render, and the page below is exactly today's page.
	 *
	 * This block is PRESENTATION ONLY and carries no id. The script reads the
	 * id from CFG.scope, the same key chat and prepare read, so one
	 * EduAI_Scope::for_script() call feeds both the banner and the request.
	 * An id in the markup as well would be a second copy of one answer that
	 * has to agree with the first — which is the failure this whole seam is
	 * shaped to avoid.
	 *
	 * Never parsed from location.search either: ?source= IS the authorisation
	 * decision, so re-deriving it client-side would let anyone type an id the
	 * server refused. The URL is input to the server; this is its output.
	 */
	if ( ! empty( $eduai_scope['title'] ) ) :
		?>
		<p class="eduai-scope">
			<span class="eduai-scope__label"><?php esc_html_e( 'Summarising', 'eduai' ); ?></span>
			<strong class="eduai-scope__name"><?php echo esc_html( $eduai_scope['title'] ); ?></strong>
			<?php
			/*
			 * Truncation, said at RENDER rather than in the response, because
			 * this is the one that changes a decision: a student who sees it
			 * before pressing can attach the section they actually need
			 * instead of waiting through the slowest call in the product to
			 * find out they got half a lecture.
			 *
			 * Coarse buckets rather than the character counts. 18,000 of
			 * 35,928 is exact and meaningless — a student does not think in
			 * characters, and a precise number invites the belief that the cut
			 * fell somewhere principled. "About the first half" is what they
			 * can act on, and below a third no fraction is honest enough to
			 * name.
			 */
			$eduai_trunc = $eduai_scope['truncated'] ?? null;

			if ( ! empty( $eduai_trunc['of'] ) ) :
				$eduai_ratio = (float) $eduai_trunc['used'] / (float) $eduai_trunc['of'];

				if ( $eduai_ratio >= 0.7 ) {
					$eduai_part = __( 'covers most of it', 'eduai' );
				} elseif ( $eduai_ratio >= 0.45 ) {
					$eduai_part = __( 'covers about the first half', 'eduai' );
				} elseif ( $eduai_ratio >= 0.28 ) {
					$eduai_part = __( 'covers about the first third', 'eduai' );
				} else {
					$eduai_part = __( 'covers the first part only', 'eduai' );
				}
				?>
				<span class="eduai-scope__part">
					<?php
					printf(
						/* translators: %s: how much of the lecture is covered, e.g. "covers about the first half" */
						esc_html__( 'Long lecture — this %s', 'eduai' ),
						esc_html( $eduai_part )
					);
					?>
				</span>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<form class="eduai-sum__form" data-eduai-sum-form>

		<div class="eduai-sum__drop" data-eduai-sum-drop tabindex="0" role="button"
			aria-label="<?php esc_attr_e( 'Choose a lecture file', 'eduai' ); ?>">
			<svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor"
				stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>
			</svg>
			<span data-eduai-sum-dropmsg><?php esc_html_e( 'Drop a lecture here, or click to choose', 'eduai' ); ?></span>
			<input type="file" data-eduai-sum-file hidden
				accept=".pdf,.pptx,.docx,.txt,.md,application/pdf,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain,text/markdown">
		</div>

		<p class="eduai-sum__hint">
			<?php
			printf(
				/* translators: %d: maximum upload size in megabytes */
				esc_html__( 'PDF, PPTX, DOCX, TXT or MD, up to %d MB. Slide decks are read slide by slide, with the speaker notes — usually where the lecturer wrote the sentence the bullet only hints at.', 'eduai' ),
				20
			);
			?>
		</p>

		<div class="eduai-sum__or"><span><?php esc_html_e( 'or paste the lecture', 'eduai' ); ?></span></div>

		<label class="screen-reader-text" for="<?php echo esc_attr( $eduai_sum_id ); ?>-text">
			<?php esc_html_e( 'Lecture text', 'eduai' ); ?>
		</label>
		<textarea id="<?php echo esc_attr( $eduai_sum_id ); ?>-text" data-eduai-sum-text rows="6"
			placeholder="<?php esc_attr_e( 'Paste lecture notes, a transcript or slide text…', 'eduai' ); ?>"></textarea>

		<div class="eduai-sum__row">
			<label class="screen-reader-text" for="<?php echo esc_attr( $eduai_sum_id ); ?>-style">
				<?php esc_html_e( 'What kind of summary?', 'eduai' ); ?>
			</label>
			<select id="<?php echo esc_attr( $eduai_sum_id ); ?>-style" data-eduai-sum-style>
				<option value="detailed"><?php esc_html_e( 'Full study notes', 'eduai' ); ?></option>
				<option value="brief"><?php esc_html_e( 'Quick summary', 'eduai' ); ?></option>
				<option value="exam"><?php esc_html_e( 'Exam preparation', 'eduai' ); ?></option>
				<option value="critical"><?php esc_html_e( 'Critical review', 'eduai' ); ?></option>
			</select>

			<button type="submit" class="eduai-btn eduai-btn--primary" data-eduai-sum-go>
				<?php esc_html_e( 'Summarise', 'eduai' ); ?>
			</button>
		</div>
	</form>

	<div class="eduai-sum__out" data-eduai-sum-out hidden></div>

	<p class="eduai-sum__foot">
		<?php esc_html_e( 'The summary is written from your document alone. Check anything you plan to rely on against the original — it can miss things, and it will say so when the source is unclear.', 'eduai' ); ?>
	</p>
</div>
