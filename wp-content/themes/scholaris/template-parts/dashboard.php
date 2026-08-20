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
function scholaris_stat_card( string $kind, string $label, string $figure, string $sub, int $pct = -1 ): void {
	/*
	 * THREE CARDS, THREE DIFFERENT QUANTITIES — and the ring that used to sit
	 * on all of them said they were the same one.
	 *
	 * Lessons is a ratio: 2 of 46, and 4% is progress through a course.
	 * Practice papers is a MARK: 12% is the average score over five papers,
	 * not 12% of anything completed. Quizzes is neither — nothing has been
	 * attempted. Drawing one arc for all three invited the student to compare
	 * them, and "I am doing better at papers (12%) than lessons (4%)" is a
	 * sentence the data cannot support.
	 *
	 * So the shape now follows the quantity. Only a ratio gets a meter,
	 * because only a ratio has a full. A mark is shown as the figure it is. An
	 * empty state says so in words and shows an em dash rather than 00 above a
	 * 0% ring, which was three ways of announcing that nothing had happened.
	 *
	 * The figure also leads with what matters: "2 / 46" rather than "02". The
	 * zero-padding was decoration — it made a small number look like a code,
	 * and it hid the total that gives it meaning.
	 */
	?>
	<article class="sc-stat sc-stat--<?php echo esc_attr( $kind ); ?>">
		<p class="sc-stat__label"><?php echo esc_html( $label ); ?></p>
		<p class="sc-stat__value"><?php echo esc_html( $figure ); ?></p>

		<?php if ( 'ratio' === $kind && $pct >= 0 ) : ?>
			<div class="sc-stat__meter">
				<span class="meter" data-tone="<?php echo esc_attr( $pct >= 67 ? 'pass' : ( $pct >= 34 ? 'mid' : '' ) ); ?>"
					role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: percent complete */ __( '%d%% complete', 'scholaris' ), $pct ) ); ?>">
					<span style="--fill:<?php echo esc_attr( (string) max( 0, min( 100, $pct ) ) ); ?>%"></span>
				</span>
				<span class="sc-stat__pct"><?php echo (int) $pct; ?>%</span>
			</div>
		<?php endif; ?>

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
					// A ratio: done of total. The only one of the three with a
					// full, so the only one that gets a meter.
					scholaris_stat_card(
						'ratio',
						__( 'Lessons', 'scholaris' ),
						sprintf( '%d / %d', (int) $sc_lessons['done'], (int) $sc_lessons['total'] ),
						__( 'completed in your courses', 'scholaris' ),
						scholaris_pct( (int) $sc_lessons['done'], (int) $sc_lessons['total'] )
					);
				}

				if ( null !== $sc_papers ) {
					// A MARK, not progress. The average is the headline figure;
					// how many were sat is the supporting line. Nothing here is
					// a proportion of a whole, so nothing here gets a meter.
					$sc_sat = sprintf(
						/* translators: %d: how many papers were sat */
						_n( 'from %d paper sat', 'from %d papers sat', (int) $sc_papers['done'], 'scholaris' ),
						(int) $sc_papers['done']
					);

					scholaris_stat_card(
						null === $sc_papers['average'] ? 'empty' : 'score',
						__( 'Practice papers', 'scholaris' ),
						null === $sc_papers['average'] ? '—' : (int) $sc_papers['average'] . '%',
						null === $sc_papers['average'] ? __( 'sat, but not marked yet', 'scholaris' ) : $sc_sat
					);
				}

				if ( null !== $sc_quizzes ) {
					// Nothing attempted is a state, not a zero. An em dash says
					// "no value" where 00 above a 0% ring said it three times.
					scholaris_stat_card(
						$sc_quizzes['done'] > 0 ? 'score' : 'empty',
						__( 'Quizzes', 'scholaris' ),
						$sc_quizzes['done'] > 0 ? (int) round( $sc_quizzes['average'] ) . '%' : '—',
						$sc_quizzes['done'] > 0
							? sprintf(
								/* translators: 1: how many passed 2: how many taken */
								__( '%1$d of %2$d passed', 'scholaris' ),
								(int) $sc_quizzes['passed'],
								(int) $sc_quizzes['done']
							)
							: __( 'none attempted yet', 'scholaris' )
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
									<a href="<?php echo esc_url( $course['url'] ); ?>">
										<?php
										/*
										 * The reference puts a coloured tile beside every course
										 * name, and it is doing real work: at a glance the rows
										 * are told apart by shape rather than read one by one.
										 *
										 * Most courses here have no featured image, so the
										 * fallback is not a placeholder graphic — a grey box
										 * repeated down the column is worse than no column. It
										 * is the course's initial on a hue derived from its id,
										 * which is stable across reloads and different per
										 * course, so it distinguishes without pretending to be
										 * a picture someone chose.
										 */
										if ( $course['thumb'] ) :
											?>
											<img class="sc-courses__thumb" src="<?php echo esc_url( $course['thumb'] ); ?>"
												alt="" width="34" height="34" loading="lazy" decoding="async">
										<?php else : ?>
											<span class="sc-courses__thumb sc-courses__thumb--letter"
												style="--hue:<?php echo (int) ( ( (int) $course['id'] * 47 ) % 360 ); ?>"
												aria-hidden="true"><?php
												echo esc_html( mb_strtoupper( mb_substr( (string) $course['title'], 0, 1 ) ) );
											?></span>
										<?php endif; ?>
										<span class="sc-courses__label"><?php echo esc_html( $course['title'] ); ?></span>
									</a>
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
				<?php
				/*
				 * Two different facts, two different sentences. "You are not
				 * enrolled" attributes the empty panel to the student, which is
				 * only fair when there is something to be enrolled on. With no
				 * courses published on the active LMS the student has done
				 * nothing wrong and browsing will not help either.
				 */
				?>
				<div class="sc-empty">
					<?php if ( empty( $data['any_courses'] ) ) : ?>
						<p><?php esc_html_e( 'No courses have been published yet. When one is, it will appear here with your progress.', 'scholaris' ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'You are not enrolled on a course yet.', 'scholaris' ); ?></p>
						<a class="btn btn--primary" href="<?php echo esc_url( home_url( '/library/' ) ); ?>">
							<?php esc_html_e( 'Browse the library', 'scholaris' ); ?>
						</a>
					<?php endif; ?>
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
