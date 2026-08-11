<?php
/**
 * The assistant application markup. Included by both the inline shortcode and
 * the floating widget; every instance is initialised independently by chat.js.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

$eduai_id     = 'eduai-' . wp_unique_id();
$eduai_name   = EduAI_Settings::get( 'assistant_name', __( 'Study Assistant', 'eduai' ) );
$eduai_voice  = (bool) EduAI_Settings::get( 'enable_voice', true );
$eduai_tts    = (bool) EduAI_Settings::get( 'enable_tts', true );
$eduai_height = isset( $eduai_atts['height'] ) ? (int) $eduai_atts['height'] : 520;
$eduai_inline = isset( $eduai_inline ) ? (bool) $eduai_inline : false;

$eduai_agents        = EduAI_Agents::for_script();
$eduai_default_agent = EduAI_Agents::default_id();
$eduai_picker        = (bool) EduAI_Settings::get( 'agent_picker', true );

/*
 * Summarise now has its own top-level tab, so offering it again inside the chat
 * panel on /ask/ recreates the "assistant as a widget with sub-options" model
 * the product deliberately moved away from (docs/06 §1).
 *
 * The floating widget is the one place a combined panel still makes sense —
 * it is a corner of someone else's page, not a destination — so it keeps both
 * and this defaults to both. [eduai_panel tabs="chat"] is what /ask/ uses.
 */
$eduai_summary_tab = ! isset( $eduai_atts['tabs'] ) || 'chat' !== $eduai_atts['tabs'];

/*
 * page="1": the panel is a full page (/ask/), not a box on one. The shell
 * chrome goes — the page h1 and breadcrumb already say where you are — and
 * the head's controls move rather than vanish: "New chat" above the log,
 * the status line below the composer. chat.js binds by data- attribute, so
 * the attributes move with the elements and exactly one of each renders.
 */
$eduai_page = ! empty( $eduai_atts['page'] );
?>
<div class="eduai-app<?php echo $eduai_inline ? ' eduai-app--inline' : ''; ?><?php echo $eduai_page ? ' eduai-app--page' : ''; ?>"
	id="<?php echo esc_attr( $eduai_id ); ?>"
	data-eduai-app
	data-post-id="<?php echo esc_attr( (string) get_the_ID() ); ?>"
	<?php if ( $eduai_inline && ! $eduai_page ) : ?>style="--eduai-body-h:<?php echo esc_attr( (string) $eduai_height ); ?>px"<?php endif; ?>>

	<?php if ( $eduai_page ) : ?>
		<div class="eduai-pagebar">
			<button type="button" class="eduai-btn" data-eduai-new>
				<?php esc_html_e( 'New chat', 'eduai' ); ?>
			</button>
		</div>
	<?php else : ?>
	<header class="eduai-head">
		<span class="eduai-avatar" aria-hidden="true">
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
				<path d="m12 3 1.9 4.6L18.5 9.5 13.9 11.4 12 16l-1.9-4.6L5.5 9.5l4.6-1.9L12 3z"/>
				<path d="M18.5 15.5 19.4 18l2.5.9-2.5.9-.9 2.5-.9-2.5-2.5-.9 2.5-.9.9-2.5z"/>
			</svg>
		</span>
		<div class="eduai-head__text">
			<strong><?php echo esc_html( $eduai_name ); ?></strong>
			<span class="eduai-status" data-eduai-status><?php esc_html_e( 'Online · grounded in your course material', 'eduai' ); ?></span>
		</div>
		<div class="eduai-head__actions">
			<button type="button" class="eduai-icon" data-eduai-new title="<?php esc_attr_e( 'New chat', 'eduai' ); ?>" aria-label="<?php esc_attr_e( 'New chat', 'eduai' ); ?>">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
			</button>
			<?php if ( ! $eduai_inline ) : ?>
				<button type="button" class="eduai-icon" data-eduai-close aria-label="<?php esc_attr_e( 'Close assistant', 'eduai' ); ?>">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
				</button>
			<?php endif; ?>
		</div>
	</header>
	<?php endif; ?>

	<?php if ( $eduai_summary_tab ) : ?>
		<div class="eduai-tabs" role="tablist">
			<button type="button" class="eduai-tab is-active" role="tab" aria-selected="true" data-eduai-tab="chat">
				<?php esc_html_e( 'Ask', 'eduai' ); ?>
			</button>
			<button type="button" class="eduai-tab" role="tab" aria-selected="false" data-eduai-tab="summary">
				<?php esc_html_e( 'Summarise a lecture', 'eduai' ); ?>
			</button>
		</div>
	<?php endif; ?>

	<!-- ----------------------------------------------------------- chat tab -->
	<div class="eduai-pane is-active" data-eduai-pane="chat">
		<div class="eduai-log" data-eduai-log role="log" aria-live="polite" aria-atomic="false"></div>

		<div class="eduai-suggestions" data-eduai-suggestions></div>

		<?php if ( $eduai_agents && count( $eduai_agents ) > 1 && $eduai_picker ) : ?>
			<div class="eduai-agentbar" data-eduai-agentbar>
				<label for="<?php echo esc_attr( $eduai_id ); ?>-agent"><?php esc_html_e( 'Agent', 'eduai' ); ?></label>
				<select id="<?php echo esc_attr( $eduai_id ); ?>-agent" data-eduai-agent>
					<?php foreach ( $eduai_agents as $eduai_agent ) : ?>
						<option value="<?php echo esc_attr( $eduai_agent['id'] ); ?>"
							<?php selected( $eduai_default_agent, $eduai_agent['id'] ); ?>>
							<?php echo esc_html( $eduai_agent['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<span class="eduai-agentbar__hint" data-eduai-agenthint></span>
			</div>
		<?php endif; ?>

		<form class="eduai-composer" data-eduai-form>
			<label class="screen-reader-text" for="<?php echo esc_attr( $eduai_id ); ?>-input">
				<?php esc_html_e( 'Your question', 'eduai' ); ?>
			</label>
			<textarea id="<?php echo esc_attr( $eduai_id ); ?>-input" data-eduai-input rows="1"
				placeholder="<?php esc_attr_e( 'Ask about your material…', 'eduai' ); ?>"
				maxlength="6000"></textarea>

			<?php if ( $eduai_voice ) : ?>
				<button type="button" class="eduai-icon eduai-mic" data-eduai-mic
					title="<?php esc_attr_e( 'Ask by voice', 'eduai' ); ?>" aria-label="<?php esc_attr_e( 'Ask by voice', 'eduai' ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
						<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3"/>
					</svg>
				</button>
			<?php endif; ?>

			<button type="submit" class="eduai-send" data-eduai-send aria-label="<?php esc_attr_e( 'Send', 'eduai' ); ?>">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
					<path d="m22 2-7 20-4-9-9-4 20-7z"/>
				</svg>
			</button>
		</form>

		<?php if ( $eduai_page ) : ?>
			<span class="eduai-status eduai-status--foot" data-eduai-status><?php esc_html_e( 'Online · grounded in your course material', 'eduai' ); ?></span>
		<?php endif; ?>

		<p class="eduai-foot">
			<?php esc_html_e( 'AI can make mistakes — check important answers against the source document.', 'eduai' ); ?>
		</p>
	</div>

	<!-- -------------------------------------------------------- summary tab -->
	<?php if ( $eduai_summary_tab ) : ?>
	<div class="eduai-pane" data-eduai-pane="summary">
		<form class="eduai-summary" data-eduai-summary-form>
			<div class="eduai-drop" data-eduai-drop tabindex="0" role="button"
				aria-label="<?php esc_attr_e( 'Choose a lecture file', 'eduai' ); ?>">
				<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
					<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>
				</svg>
				<span data-eduai-dropmsg><?php esc_html_e( 'Drop a PDF, PowerPoint, Word or text file here, or click to choose', 'eduai' ); ?></span>
				<input type="file" data-eduai-file hidden
					accept=".pdf,.pptx,.docx,.txt,.md,application/pdf,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain,text/markdown">
			</div>

			<p class="eduai-drop__hint">
				<?php esc_html_e( 'PDF, PPTX, DOCX, TXT or MD. Slide decks are read slide by slide, speaker notes included.', 'eduai' ); ?>
			</p>

			<div class="eduai-or"><span><?php esc_html_e( 'or paste the lecture text', 'eduai' ); ?></span></div>

			<textarea data-eduai-summary-text rows="5"
				placeholder="<?php esc_attr_e( 'Paste lecture notes, a transcript or slide text…', 'eduai' ); ?>"></textarea>

			<div class="eduai-summary__row">
				<label class="screen-reader-text" for="<?php echo esc_attr( $eduai_id ); ?>-style">
					<?php esc_html_e( 'Summary style', 'eduai' ); ?>
				</label>
				<select id="<?php echo esc_attr( $eduai_id ); ?>-style" data-eduai-style>
					<option value="detailed"><?php esc_html_e( 'Full study notes', 'eduai' ); ?></option>
					<option value="brief"><?php esc_html_e( 'Quick summary', 'eduai' ); ?></option>
					<option value="exam"><?php esc_html_e( 'Exam preparation', 'eduai' ); ?></option>
					<option value="critical"><?php esc_html_e( 'Critical review', 'eduai' ); ?></option>
				</select>
				<button type="submit" class="eduai-btn eduai-btn--primary" data-eduai-summarize>
					<?php esc_html_e( 'Summarise', 'eduai' ); ?>
				</button>
			</div>
		</form>

		<div class="eduai-summary-result" data-eduai-summary-result hidden></div>
	</div>
	<?php endif; ?>
</div>
