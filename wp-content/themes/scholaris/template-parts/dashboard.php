<?php
/**
 * The student dashboard: resume strip, stat rings, course progress, right rail.
 *
 * Modelled on the two references the owner sent, with one rule applied
 * throughout: every number is read from a real source, and a panel with no
 * source is absent rather than zeroed. The references show an "Upcoming" list
 * with due dates; nothing in this stack has a due date, so that slot carries
 * what is genuinely known — when the student actually worked — instead of
 * inventing deadlines to fill the shape.
 *
 * @var array $data From scholaris_dashboard_data().
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

$sc_lessons = $data['lessons'];
$sc_quizzes = $data['quizzes'];
$sc_papers  = $data['papers'];
$sc_user    = wp_get_current_user();

/**
 * A stat ring. The arc is drawn with stroke-dasharray on a circle, so the
 * percentage is geometry rather than a background image that would need one
 * file per value.
 *
 * @param string $tone  Palette key: lessons | quizzes | papers.
 * @param string $icon  Inline SVG path data.
 * @param int    $value Big number.
 * @param string $label Word under the number.
 * @param string $sub   Small line under the label.
 * @param int    $pct   0-100 for the ring.
 */
function scholaris_stat_card( string $tone, string $icon, int $value, string $label, string $sub, int $pct ): void {
	$circ = 2 * M_PI * 20; // r=20
	$dash = $circ * max( 0, min( 100, $pct ) ) / 100;
	?>
	<article class="sc-stat sc-stat--<?php echo esc_attr( $tone ); ?>">
		<div class="sc-stat__top">
			<span class="sc-stat__icon" aria-hidden="true">
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
					stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal path data from this file. ?>
				</svg>
			</span>
			<span class="sc-stat__ring">
				<svg width="52" height="52" viewBox="0 0 52 52" role="img"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %d: percent complete */ __( '%d%% complete', 'scholaris' ), $pct ) ); ?>">
					<circle class="sc-stat__track" cx="26" cy="26" r="20" fill="none" stroke-width="6"/>
					<circle class="sc-stat__arc" cx="26" cy="26" r="20" fill="none" stroke-width="6"
						stroke-linecap="round" transform="rotate(-90 26 26)"
						stroke-dasharray="<?php echo esc_attr( round( $dash, 2 ) . ' ' . round( $circ, 2 ) ); ?>"/>
				</svg>
				<span class="sc-stat__pct"><?php echo (int) $pct; ?>%</span>
			</span>
		</div>
		<p class="sc-stat__value"><?php echo esc_html( str_pad( (string) $value, 2, '0', STR_PAD_LEFT ) ); ?></p>
		<p class="sc-stat__label"><?php echo esc_html( $label ); ?></p>
		<p class="sc-stat__sub"><?php echo esc_html( $sub ); ?></p>
	</article>
	<?php
}
?>

<div class="sc-dash">

	<div class="sc-dash__main">

		<?php if ( $data['resume'] ) : ?>
			<?php // The reference puts "resume" above everything, and it is the one control a returning student always wants. ?>
			<section class="sc-resume">
				<span class="sc-resume__mark" aria-hidden="true"></span>
				<div class="sc-resume__body">
					<p class="sc-resume__label"><?php esc_html_e( 'Pick up where you left off', 'scholaris' ); ?></p>
					<p class="sc-resume__name"><?php echo esc_html( $data['resume']['title'] ); ?></p>
					<span class="sc-resume__bar" aria-hidden="true">
						<span style="width:<?php echo (int) $data['resume']['percent']; ?>%"></span>
					</span>
				</div>
				<p class="sc-resume__count">
					<?php
					printf(
						/* translators: 1: completed items 2: total items */
						esc_html__( '%1$d of %2$d done', 'scholaris' ),
						(int) $data['resume']['done'],
						(int) $data['resume']['total']
					);
					?>
				</p>
				<a class="btn btn--primary sc-resume__go" href="<?php echo esc_url( $data['resume']['url'] ); ?>">
					<?php esc_html_e( 'Resume', 'scholaris' ); ?>
				</a>
			</section>
		<?php endif; ?>

		<section class="sc-panel">
			<h2 class="sc-panel__title"><?php esc_html_e( 'Status', 'scholaris' ); ?></h2>

			<div class="sc-stats">
				<?php
				if ( null !== $sc_lessons ) {
					scholaris_stat_card(
						'lessons',
						'<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
						(int) $sc_lessons['done'],
						__( 'Lessons', 'scholaris' ),
						sprintf(
							/* translators: %d: total lessons */
							__( 'of %d in your courses', 'scholaris' ),
							(int) $sc_lessons['total']
						),
						scholaris_pct( (int) $sc_lessons['done'], (int) $sc_lessons['total'] )
					);
				}

				if ( null !== $sc_papers ) {
					scholaris_stat_card(
						'papers',
						'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="m9 15 2 2 4-4"/>',
						(int) $sc_papers['done'],
						__( 'Practice papers', 'scholaris' ),
						null === $sc_papers['average']
							? __( 'not marked yet', 'scholaris' )
							: sprintf(
								/* translators: %d: average percentage */
								__( '%d%% average', 'scholaris' ),
								(int) $sc_papers['average']
							),
						null === $sc_papers['average'] ? 0 : (int) $sc_papers['average']
					);
				}

				if ( null !== $sc_quizzes ) {
					scholaris_stat_card(
						'quizzes',
						'<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
						(int) $sc_quizzes['done'],
						__( 'Quizzes', 'scholaris' ),
						$sc_quizzes['done'] > 0
							? sprintf(
								/* translators: %d: how many were passed */
								__( '%d passed', 'scholaris' ),
								(int) $sc_quizzes['passed']
							)
							: __( 'none attempted', 'scholaris' ),
						$sc_quizzes['done'] > 0 ? (int) round( $sc_quizzes['average'] ) : 0
					);
				}
				?>
			</div>
		</section>

		<section class="sc-panel">
			<h2 class="sc-panel__title"><?php esc_html_e( 'My courses', 'scholaris' ); ?></h2>

			<?php if ( $data['courses'] ) : ?>
				<table class="sc-courses">
					<thead>
						<tr>
							<th scope="col" class="sc-courses__num">#</th>
							<th scope="col"><?php esc_html_e( 'Course', 'scholaris' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Completed', 'scholaris' ); ?></th>
							<th scope="col" class="sc-courses__stat"><?php esc_html_e( 'Progress', 'scholaris' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $data['courses'] as $i => $course ) : ?>
							<tr>
								<td class="sc-courses__num"><?php echo (int) ( $i + 1 ); ?></td>
								<td class="sc-courses__name">
									<a href="<?php echo esc_url( $course['url'] ); ?>"><?php echo esc_html( $course['title'] ); ?></a>
								</td>
								<td>
									<span class="sc-bar" aria-hidden="true">
										<span class="sc-bar__fill" style="width:<?php echo (int) $course['percent']; ?>%"></span>
									</span>
								</td>
								<td class="sc-courses__stat">
									<?php
									printf(
										/* translators: 1: completed 2: total */
										esc_html__( '%1$d/%2$d', 'scholaris' ),
										(int) $course['done'],
										(int) $course['total']
									);
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<?php // An empty state that carries the next action, not just the absence. ?>
				<div class="sc-empty">
					<p><?php esc_html_e( 'You are not enrolled on a course yet.', 'scholaris' ); ?></p>
					<a class="btn btn--primary" href="<?php echo esc_url( home_url( '/library/' ) ); ?>">
						<?php esc_html_e( 'Browse the library', 'scholaris' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</section>
	</div>

	<aside class="sc-dash__rail">

		<?php
		/*
		 * A calendar of days actually studied, not a month grid with invented
		 * deadlines. The references show "Upcoming" with due dates; this stack
		 * has none, and filling that shape with fabricated dates would be the
		 * decoration the owner is right to object to.
		 */
		$sc_today  = current_time( 'Y-m-d' );
		$sc_first  = gmdate( 'Y-m-01', strtotime( $sc_today ) );
		$sc_days   = (int) gmdate( 't', strtotime( $sc_first ) );
		$sc_offset = ( (int) gmdate( 'N', strtotime( $sc_first ) ) ) - 1; // Monday = 0.
		$sc_marked = array_flip( $data['study_days'] );
		?>
		<section class="sc-panel sc-cal">
			<h2 class="sc-panel__title"><?php echo esc_html( date_i18n( 'F Y', strtotime( $sc_today ) ) ); ?></h2>
			<div class="sc-cal__grid" role="presentation">
				<?php
				foreach ( array(
					__( 'Mo', 'scholaris' ),
					__( 'Tu', 'scholaris' ),
					__( 'We', 'scholaris' ),
					__( 'Th', 'scholaris' ),
					__( 'Fr', 'scholaris' ),
					__( 'Sa', 'scholaris' ),
					__( 'Su', 'scholaris' ),
				) as $sc_dow ) :
					?>
					<span class="sc-cal__dow"><?php echo esc_html( $sc_dow ); ?></span>
				<?php endforeach; ?>

				<?php for ( $sc_i = 0; $sc_i < $sc_offset; $sc_i++ ) : ?>
					<span class="sc-cal__pad"></span>
				<?php endfor; ?>

				<?php
				for ( $sc_d = 1; $sc_d <= $sc_days; $sc_d++ ) :
					$sc_date  = gmdate( 'Y-m-', strtotime( $sc_first ) ) . str_pad( (string) $sc_d, 2, '0', STR_PAD_LEFT );
					$sc_class = 'sc-cal__day';
					if ( isset( $sc_marked[ $sc_date ] ) ) {
						$sc_class .= ' is-studied';
					}
					if ( $sc_date === $sc_today ) {
						$sc_class .= ' is-today';
					}
					?>
					<span class="<?php echo esc_attr( $sc_class ); ?>"><?php echo (int) $sc_d; ?></span>
				<?php endfor; ?>
			</div>
			<p class="sc-cal__key">
				<span class="sc-cal__keydot" aria-hidden="true"></span>
				<?php esc_html_e( 'days you worked', 'scholaris' ); ?>
			</p>
		</section>

		<section class="sc-panel">
			<h2 class="sc-panel__title"><?php esc_html_e( 'Recent activity', 'scholaris' ); ?></h2>

			<?php if ( $data['activity'] ) : ?>
				<ul class="sc-feed">
					<?php foreach ( array_slice( $data['activity'], 0, 5 ) as $sc_item ) : ?>
						<li class="sc-feed__item">
							<span class="sc-feed__when">
								<?php echo esc_html( $sc_item['date'] ? date_i18n( 'j M', strtotime( $sc_item['date'] ) ) : '' ); ?>
							</span>
							<span class="sc-feed__body">
								<span class="sc-feed__label"><?php echo esc_html( $sc_item['label'] ); ?></span>
								<span class="sc-feed__kind sc-feed__kind--<?php echo esc_attr( $sc_item['kind'] ); ?>">
									<?php
									echo esc_html(
										'quiz' === $sc_item['kind']
											? __( 'Quiz', 'scholaris' )
											: __( 'Practice paper', 'scholaris' )
									);
									?>
									<?php if ( '' !== $sc_item['meta'] ) : ?>
										&middot; <?php echo esc_html( $sc_item['meta'] ); ?>
									<?php endif; ?>
								</span>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<div class="sc-empty">
					<p><?php esc_html_e( 'Nothing yet. Sit a practice paper and it will appear here.', 'scholaris' ); ?></p>
					<a class="btn" href="<?php echo esc_url( home_url( '/prepare/' ) ); ?>">
						<?php esc_html_e( 'Open PrepareME', 'scholaris' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</section>
	</aside>
</div>
