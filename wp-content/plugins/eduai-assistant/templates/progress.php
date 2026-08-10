<?php
/**
 * PrepareME history for the signed-in student.
 *
 * @var array $eduai_stats   From EduAI_Exams::stats_for_user().
 * @var array $eduai_history From EduAI_Exams::history_for_user().
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="eduai-prog">

	<?php if ( ! $eduai_history ) : ?>

		<?php // Empty state carries the action. "No attempts yet" alone tells a student nothing about what to do next. ?>
		<div class="eduai-prog__empty">
			<h4><?php esc_html_e( 'No practice papers yet', 'eduai' ); ?></h4>
			<p><?php esc_html_e( 'Upload a lecture to PrepareME and it will write you an exam from it, then mark your answers with corrections.', 'eduai' ); ?></p>
			<a class="eduai-btn eduai-btn--primary" href="<?php echo esc_url( eduai_prepare_url() ); ?>">
				<?php esc_html_e( 'Sit your first paper', 'eduai' ); ?>
			</a>
		</div>

	<?php else : ?>

		<div class="eduai-prog__stats">
			<div class="eduai-prog__stat">
				<span class="eduai-prog__statnum"><?php echo (int) $eduai_stats['taken']; ?></span>
				<span class="eduai-prog__statlabel">
					<?php echo esc_html( _n( 'paper sat', 'papers sat', (int) $eduai_stats['taken'], 'eduai' ) ); ?>
				</span>
			</div>
			<div class="eduai-prog__stat">
				<span class="eduai-prog__statnum"><?php echo null === $eduai_stats['average'] ? '—' : (int) $eduai_stats['average'] . '%'; ?></span>
				<span class="eduai-prog__statlabel"><?php esc_html_e( 'average', 'eduai' ); ?></span>
			</div>
			<div class="eduai-prog__stat">
				<span class="eduai-prog__statnum"><?php echo null === $eduai_stats['best'] ? '—' : (int) $eduai_stats['best'] . '%'; ?></span>
				<span class="eduai-prog__statlabel"><?php esc_html_e( 'best', 'eduai' ); ?></span>
			</div>
		</div>

		<ul class="eduai-prog__list">
			<?php foreach ( $eduai_history as $eduai_row ) : ?>
				<?php
				// Bands match the marking tones already used on the PrepareME
				// results screen, so a score means the same thing on both pages.
				$eduai_tone = $eduai_row['percent'] >= 70 ? 'ok' : ( $eduai_row['percent'] >= 40 ? 'part' : 'no' );
				?>
				<li class="eduai-prog__item">
					<div class="eduai-prog__main">
						<h4 class="eduai-prog__title"><?php echo esc_html( $eduai_row['title'] ); ?></h4>
						<p class="eduai-prog__meta">
							<?php
							if ( '' !== $eduai_row['source_label'] ) {
								printf(
									/* translators: %s: lecture file or source name */
									esc_html__( 'from %s', 'eduai' ),
									'<span class="eduai-prog__source">' . esc_html( $eduai_row['source_label'] ) . '</span>'
								);
								echo ' · ';
							}

							echo esc_html(
								sprintf(
									/* translators: %s: human-readable time difference, e.g. "2 hours" */
									__( '%s ago', 'eduai' ),
									human_time_diff( (int) strtotime( $eduai_row['created_at'] ), current_time( 'timestamp' ) )
								)
							);
							?>
						</p>
					</div>

					<div class="eduai-prog__score eduai-prog__score--<?php echo esc_attr( $eduai_tone ); ?>">
						<span class="eduai-prog__pct"><?php echo (int) $eduai_row['percent']; ?>%</span>
						<?php // The raw marks stay visible: 17% of a 6-mark paper is one question, and a percentage alone hides that. ?>
						<span class="eduai-prog__marks">
							<?php
							printf(
								/* translators: 1: marks awarded 2: marks available */
								esc_html__( '%1$s of %2$s marks', 'eduai' ),
								esc_html( (string) round( $eduai_row['score'], 2 ) ),
								esc_html( (string) round( $eduai_row['total'], 2 ) )
							);
							?>
						</span>
					</div>

					<a class="eduai-btn eduai-prog__retake" href="<?php echo esc_url( eduai_prepare_url( $eduai_row['exam_id'] ) ); ?>">
						<?php esc_html_e( 'Retake', 'eduai' ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

		<p class="eduai-prog__foot">
			<?php esc_html_e( 'Retaking gives you the same questions with your answers cleared. Your previous marks are kept.', 'eduai' ); ?>
		</p>

	<?php endif; ?>

</div>
