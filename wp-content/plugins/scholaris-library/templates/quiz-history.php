<?php
/**
 * Quiz history table + score trend.
 *
 * @var array $attempts Rows to display.
 * @var array $all      Every attempt (used for the chart and stats).
 * @var array $stats    Aggregates.
 * @var array $atts     Shortcode attributes.
 *
 * @package ScholarisLibrary
 */

defined( 'ABSPATH' ) || exit;

$sl_show_stats = 'yes' === $atts['show_stats'];
$sl_show_chart = 'yes' === $atts['show_chart'] && count( $all ) > 1;
?>
<div class="sl-history">

	<?php if ( $sl_show_stats ) : ?>
		<div class="sl-stats">
			<?php
			$sl_tiles = array(
				array(
					'label' => __( 'Average score', 'scholaris-library' ),
					'value' => $stats['average'] . '%',
					'note'  => 0.0 !== $stats['trend']
						? sprintf(
							/* translators: %s: signed percentage change */
							__( '%s vs earlier attempts', 'scholaris-library' ),
							( $stats['trend'] > 0 ? '+' : '' ) . $stats['trend'] . '%'
						)
						: __( 'across all attempts', 'scholaris-library' ),
					'tone'  => $stats['trend'] > 0 ? 'up' : ( $stats['trend'] < 0 ? 'down' : '' ),
				),
				array(
					'label' => __( 'Best score', 'scholaris-library' ),
					'value' => $stats['best'] . '%',
					'note'  => __( 'personal record', 'scholaris-library' ),
					'tone'  => '',
				),
				array(
					'label' => __( 'Attempts', 'scholaris-library' ),
					'value' => (string) $stats['count'],
					'note'  => sprintf(
						/* translators: %d: number of distinct quizzes */
						_n( 'on %d quiz', 'across %d quizzes', $stats['quizzes'], 'scholaris-library' ),
						$stats['quizzes']
					),
					'tone'  => '',
				),
				array(
					'label' => __( 'Passed', 'scholaris-library' ),
					'value' => $stats['passed'] . '/' . $stats['count'],
					'note'  => __( 'attempts at or above the pass mark', 'scholaris-library' ),
					'tone'  => '',
				),
			);

			foreach ( $sl_tiles as $sl_tile ) :
				?>
				<div class="sl-stat">
					<span class="sl-stat__label"><?php echo esc_html( $sl_tile['label'] ); ?></span>
					<span class="sl-stat__value"><?php echo esc_html( $sl_tile['value'] ); ?></span>
					<span class="sl-stat__note sl-tone-<?php echo esc_attr( $sl_tile['tone'] ); ?>">
						<?php echo esc_html( $sl_tile['note'] ); ?>
					</span>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( $sl_show_chart ) : ?>
		<?php
		// Oldest → newest, capped at the last 12 attempts.
		$sl_series = array_reverse( array_slice( $all, 0, 12 ) );
		$sl_w      = 100;
		$sl_h      = 34;
		$sl_step   = count( $sl_series ) > 1 ? $sl_w / ( count( $sl_series ) - 1 ) : 0;

		$sl_points = array();
		foreach ( $sl_series as $sl_i => $sl_row ) {
			$sl_points[] = round( $sl_i * $sl_step, 2 ) . ',' . round( $sl_h - ( $sl_row['percent'] / 100 * $sl_h ), 2 );
		}
		$sl_path = implode( ' ', $sl_points );
		?>
		<figure class="sl-chart">
			<figcaption><?php esc_html_e( 'Score trend — oldest to most recent', 'scholaris-library' ); ?></figcaption>
			<svg viewBox="0 0 <?php echo esc_attr( (string) $sl_w ); ?> <?php echo esc_attr( (string) $sl_h ); ?>"
				preserveAspectRatio="none" role="img"
				aria-label="<?php esc_attr_e( 'Line chart of quiz scores over time', 'scholaris-library' ); ?>">
				<line x1="0" y1="<?php echo esc_attr( (string) ( $sl_h * 0.4 ) ); ?>"
					x2="<?php echo esc_attr( (string) $sl_w ); ?>" y2="<?php echo esc_attr( (string) ( $sl_h * 0.4 ) ); ?>"
					class="sl-chart__pass"></line>
				<polyline points="<?php echo esc_attr( $sl_path ); ?>" class="sl-chart__line"></polyline>
				<?php foreach ( $sl_points as $sl_point ) : ?>
					<?php list( $sl_cx, $sl_cy ) = explode( ',', $sl_point ); ?>
					<?php /* Zero-length round-capped line: stays circular under non-uniform scaling. */ ?>
					<line x1="<?php echo esc_attr( $sl_cx ); ?>" y1="<?php echo esc_attr( $sl_cy ); ?>"
						x2="<?php echo esc_attr( $sl_cx ); ?>" y2="<?php echo esc_attr( $sl_cy ); ?>"
						class="sl-chart__dot"></line>
				<?php endforeach; ?>
			</svg>
			<div class="sl-chart__axis">
				<span><?php echo esc_html( $sl_series[0]['quiz_title'] ); ?></span>
				<span><?php echo esc_html( end( $sl_series )['quiz_title'] ); ?></span>
			</div>
		</figure>
	<?php endif; ?>

	<div class="sl-table-wrap">
		<table class="sl-table">
			<caption class="screen-reader-text"><?php esc_html_e( 'Your quiz attempts', 'scholaris-library' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Quiz', 'scholaris-library' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Score', 'scholaris-library' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'scholaris-library' ); ?></th>
					<th scope="col" class="sl-hide-sm"><?php esc_html_e( 'Answered', 'scholaris-library' ); ?></th>
					<th scope="col" class="sl-hide-sm"><?php esc_html_e( 'Time', 'scholaris-library' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'scholaris-library' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'scholaris-library' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $attempts as $sl_row ) : ?>
					<?php
					$sl_tone = $sl_row['passed'] ? 'pass' : ( $sl_row['percent'] >= $sl_row['passing'] * 0.75 ? 'mid' : 'fail' );
					?>
					<tr>
						<th scope="row">
							<span class="sl-quiz-title"><?php echo esc_html( $sl_row['quiz_title'] ); ?></span>
							<?php if ( $sl_row['course'] ) : ?>
								<span class="sl-quiz-course"><?php echo esc_html( $sl_row['course'] ); ?></span>
							<?php endif; ?>
						</th>
						<td class="sl-num">
							<div class="sl-score">
								<strong><?php echo esc_html( (string) $sl_row['percent'] ); ?>%</strong>
								<span class="sl-meter" data-tone="<?php echo esc_attr( $sl_tone ); ?>">
									<span style="width:<?php echo esc_attr( (string) min( 100, $sl_row['percent'] ) ); ?>%"></span>
								</span>
							</div>
							<span class="sl-marks">
								<?php
								printf(
									/* translators: 1: earned marks 2: total marks */
									esc_html__( '%1$s of %2$s marks', 'scholaris-library' ),
									esc_html( (string) round( $sl_row['earned'], 2 ) ),
									esc_html( (string) round( $sl_row['total'], 2 ) )
								);
								?>
							</span>
						</td>
						<td>
							<?php if ( $sl_row['review'] ) : ?>
								<span class="sl-badge sl-badge--warn"><?php esc_html_e( 'Awaiting review', 'scholaris-library' ); ?></span>
							<?php elseif ( $sl_row['passed'] ) : ?>
								<span class="sl-badge sl-badge--pass"><?php esc_html_e( 'Passed', 'scholaris-library' ); ?></span>
							<?php else : ?>
								<span class="sl-badge sl-badge--fail"><?php esc_html_e( 'Not passed', 'scholaris-library' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="sl-num sl-hide-sm">
							<?php echo esc_html( $sl_row['answered'] . '/' . $sl_row['questions'] ); ?>
						</td>
						<td class="sl-num sl-hide-sm"><?php echo esc_html( $sl_row['duration'] ); ?></td>
						<td class="sl-num">
							<?php
							echo esc_html(
								$sl_row['ended'] && ! str_starts_with( $sl_row['ended'], '0000' )
									? date_i18n( get_option( 'date_format' ), strtotime( $sl_row['ended'] ) )
									: '—'
							);
							?>
						</td>
						<td>
							<?php if ( $sl_row['url'] ) : ?>
								<a class="sl-btn sl-btn--quiet" href="<?php echo esc_url( $sl_row['url'] ); ?>">
									<?php esc_html_e( 'Retake', 'scholaris-library' ); ?>
								</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<?php if ( count( $all ) > count( $attempts ) ) : ?>
		<p class="sl-more">
			<?php
			printf(
				/* translators: 1: shown count 2: total count */
				esc_html__( 'Showing the %1$d most recent of %2$d attempts.', 'scholaris-library' ),
				count( $attempts ),
				count( $all )
			);
			?>
		</p>
	<?php endif; ?>
</div>
