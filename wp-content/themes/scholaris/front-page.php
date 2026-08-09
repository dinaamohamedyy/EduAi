<?php
/**
 * Homepage: hero with the assistant, library preview, features, quiz snapshot.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

get_header();

$show_assistant = (bool) get_theme_mod( 'scholaris_show_assistant', true );
?>

<section class="hero">
	<div class="wrap hero__grid">
		<div>
			<span class="eyebrow"><?php scholaris_the_icon( 'sparkles', 14 ); ?> <?php echo esc_html( wp_strip_all_tags( scholaris_opt( 'scholaris_hero_eyebrow', __( 'AI-assisted learning', 'scholaris' ) ) ) ); ?></span>

			<h1 class="hero__title"><?php echo wp_kses_post( scholaris_opt( 'scholaris_hero_title', __( 'Everything you need to <em>study smarter</em>', 'scholaris' ) ) ); ?></h1>

			<p class="hero__lede"><?php echo wp_kses_post( scholaris_opt( 'scholaris_hero_lede', __( 'Course material, past quizzes with your scores, and an assistant that answers questions and summarises your lectures — by text or by voice.', 'scholaris' ) ) ); ?></p>

			<div class="hero__actions">
				<a class="btn btn--primary btn--lg" href="<?php echo esc_url( scholaris_opt( 'scholaris_hero_cta_url', home_url( '/library/' ) ) ); ?>">
					<?php scholaris_the_icon( 'book', 18 ); ?>
					<?php echo esc_html( wp_strip_all_tags( scholaris_opt( 'scholaris_hero_cta_text', __( 'Browse the library', 'scholaris' ) ) ) ); ?>
				</a>
				<a class="btn btn--ghost btn--lg" href="<?php echo esc_url( scholaris_opt( 'scholaris_hero_alt_url', home_url( '/dashboard/' ) ) ); ?>">
					<?php scholaris_the_icon( 'chart', 18 ); ?>
					<?php echo esc_html( wp_strip_all_tags( scholaris_opt( 'scholaris_hero_alt_text', __( 'View my progress', 'scholaris' ) ) ) ); ?>
				</a>
			</div>

			<div class="hero__proof">
				<?php
				$material_count = 0;
				if ( post_type_exists( 'study_material' ) ) {
					$counts         = wp_count_posts( 'study_material' );
					$material_count = isset( $counts->publish ) ? (int) $counts->publish : 0;
				}

				$course_count = 0;
				if ( post_type_exists( 'courses' ) ) {
					$counts       = wp_count_posts( 'courses' );
					$course_count = isset( $counts->publish ) ? (int) $counts->publish : 0;
				}

				$stats = array(
					array( $material_count, __( 'Study documents', 'scholaris' ) ),
					array( $course_count, __( 'Courses', 'scholaris' ) ),
					array( (int) count_users()['total_users'], __( 'Registered students', 'scholaris' ) ),
				);

				foreach ( $stats as $stat ) :
					?>
					<div>
						<strong data-countup="<?php echo esc_attr( (string) $stat[0] ); ?>"><?php echo esc_html( (string) $stat[0] ); ?></strong>
						<span><?php echo esc_html( $stat[1] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<?php if ( $show_assistant ) : ?>
			<div class="reveal">
				<?php
				if ( scholaris_has_shortcode( 'eduai_panel' ) ) {
					echo do_shortcode( '[eduai_panel]' );
				} else {
					get_template_part( 'template-parts/assistant', 'placeholder' );
				}
				?>
			</div>
		<?php endif; ?>
	</div>
</section>

<!-- ------------------------------------------------------------ features -->
<section class="section section--sunken">
	<div class="wrap">
		<div class="section-head reveal">
			<span class="eyebrow"><?php esc_html_e( 'What you get', 'scholaris' ); ?></span>
			<h2><?php esc_html_e( 'Built around how students actually revise', 'scholaris' ); ?></h2>
			<p><?php esc_html_e( 'Material in one place, quizzes that remember every attempt, and an assistant that has read the same documents you have.', 'scholaris' ); ?></p>
		</div>

		<div class="grid grid--3">
			<?php
			$features = array(
				array( 'book', __( 'A searchable library', 'scholaris' ), __( 'Lecture PDFs, slides and notes filed by subject and semester, with in-browser preview and download.', 'scholaris' ) ),
				array( 'chart', __( 'Quizzes with full history', 'scholaris' ), __( 'Every attempt is stored: score, percentage, pass or fail, time taken, and how you trend across attempts.', 'scholaris' ) ),
				array( 'sparkles', __( 'An assistant that knows the material', 'scholaris' ), __( 'Ask about anything in the library and get an answer grounded in the actual documents, with citations.', 'scholaris' ) ),
				array( 'file', __( 'Lecture summarising', 'scholaris' ), __( 'Upload or paste a lecture and get a structured summary, key terms and self-test questions in seconds.', 'scholaris' ) ),
				array( 'mic', __( 'Voice in and voice out', 'scholaris' ), __( 'Speak your question and have the answer read back — useful for revising while commuting.', 'scholaris' ) ),
				array( 'shield', __( 'Private by default', 'scholaris' ), __( 'Material stays on your own server. Only the passage the assistant needs is sent to the model.', 'scholaris' ) ),
			);

			foreach ( $features as $feature ) :
				?>
				<article class="card feature reveal">
					<span class="feature__icon"><?php scholaris_the_icon( $feature[0], 22 ); ?></span>
					<h3><?php echo esc_html( $feature[1] ); ?></h3>
					<p><?php echo esc_html( $feature[2] ); ?></p>
				</article>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<!-- --------------------------------------------------------- library peek -->
<?php if ( post_type_exists( 'study_material' ) ) : ?>
	<?php
	$recent = new WP_Query( array(
		'post_type'           => 'study_material',
		'posts_per_page'      => 6,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	) );
	?>
	<?php if ( $recent->have_posts() ) : ?>
		<section class="section">
			<div class="wrap">
				<div class="section-head reveal" style="display:flex;align-items:end;justify-content:space-between;gap:1rem;max-width:none">
					<div>
						<span class="eyebrow"><?php esc_html_e( 'Library', 'scholaris' ); ?></span>
						<h2 class="mb-0"><?php esc_html_e( 'Recently added material', 'scholaris' ); ?></h2>
					</div>
					<a class="btn btn--ghost" href="<?php echo esc_url( get_post_type_archive_link( 'study_material' ) ?: home_url( '/library/' ) ); ?>">
						<?php esc_html_e( 'Open the library', 'scholaris' ); ?>
					</a>
				</div>

				<div class="grid grid--3">
					<?php
					while ( $recent->have_posts() ) :
						$recent->the_post();
						get_template_part( 'template-parts/card', 'material' );
					endwhile;
					wp_reset_postdata();
					?>
				</div>
			</div>
		</section>
	<?php endif; ?>
<?php endif; ?>

<!-- ------------------------------------------------------- progress strip -->
<?php if ( is_user_logged_in() && scholaris_has_shortcode( 'scholaris_quiz_history' ) ) : ?>
	<section class="section section--sunken">
		<div class="wrap">
			<div class="section-head reveal">
				<span class="eyebrow"><?php esc_html_e( 'Your progress', 'scholaris' ); ?></span>
				<h2><?php esc_html_e( 'Where you stand right now', 'scholaris' ); ?></h2>
			</div>
			<?php echo do_shortcode( '[scholaris_quiz_history limit="5" show_chart="yes"]' ); ?>
		</div>
	</section>
<?php endif; ?>

<!-- ------------------------------------------------------------------ CTA -->
<section class="section">
	<div class="wrap">
		<div class="cta-band reveal">
			<h2 style="margin-bottom:.5rem"><?php esc_html_e( 'Stuck on something in tonight\'s reading?', 'scholaris' ); ?></h2>
			<p class="text-muted" style="max-width:52ch;margin-inline:auto">
				<?php esc_html_e( 'Open the assistant, ask in your own words, and it will point you to the exact document and page.', 'scholaris' ); ?>
			</p>
			<p class="mt-m mb-0">
				<button class="btn btn--primary btn--lg" type="button" data-eduai-open>
					<?php scholaris_the_icon( 'sparkles', 18 ); ?>
					<?php esc_html_e( 'Ask the assistant', 'scholaris' ); ?>
				</button>
			</p>
		</div>
	</div>
</section>

<?php
get_footer();
