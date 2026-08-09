<?php
/**
 * Student dashboard: greeting, quiz history, recently added material.
 *
 * @var WP_User $user Current user.
 *
 * @package ScholarisLibrary
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="sl-dashboard">

	<header class="sl-dashboard__head">
		<?php echo get_avatar( $user->ID, 56, '', '', array( 'class' => 'sl-avatar' ) ); ?>
		<div>
			<h2>
				<?php
				printf(
					/* translators: %s: student's first name */
					esc_html__( 'Welcome back, %s', 'scholaris-library' ),
					esc_html( $user->first_name ?: $user->display_name )
				);
				?>
			</h2>
			<p><?php esc_html_e( 'Here is where you stand, and what is new in the library.', 'scholaris-library' ); ?></p>
		</div>
		<div class="sl-dashboard__actions">
			<button type="button" class="sl-btn sl-btn--primary" data-eduai-open>
				<?php esc_html_e( 'Ask the assistant', 'scholaris-library' ); ?>
			</button>
			<button type="button" class="sl-btn sl-btn--ghost" data-eduai-open="summary">
				<?php esc_html_e( 'Summarise a lecture', 'scholaris-library' ); ?>
			</button>
		</div>
	</header>

	<section class="sl-dashboard__section">
		<h3><?php esc_html_e( 'Quiz history', 'scholaris-library' ); ?></h3>
		<?php echo do_shortcode( '[scholaris_quiz_history limit="12" show_chart="yes"]' ); ?>
	</section>

	<?php
	$sl_recent = new WP_Query( array(
		'post_type'      => 'study_material',
		'posts_per_page' => 4,
		'no_found_rows'  => true,
	) );
	?>
	<?php if ( $sl_recent->have_posts() ) : ?>
		<section class="sl-dashboard__section">
			<h3><?php esc_html_e( 'New in the library', 'scholaris-library' ); ?></h3>
			<ul class="sl-recent">
				<?php
				while ( $sl_recent->have_posts() ) :
					$sl_recent->the_post();
					$sl_subject = get_the_terms( get_the_ID(), 'material_subject' );
					?>
					<li>
						<a href="<?php the_permalink(); ?>">
							<span class="sl-recent__title"><?php the_title(); ?></span>
							<span class="sl-recent__meta">
								<?php
								echo esc_html(
									( $sl_subject && ! is_wp_error( $sl_subject ) ? $sl_subject[0]->name . ' · ' : '' )
									. get_the_date()
								);
								?>
							</span>
						</a>
					</li>
					<?php
				endwhile;
				wp_reset_postdata();
				?>
			</ul>
			<p>
				<a class="sl-btn sl-btn--ghost" href="<?php echo esc_url( get_post_type_archive_link( 'study_material' ) ?: home_url( '/library/' ) ); ?>">
					<?php esc_html_e( 'Open the library', 'scholaris-library' ); ?>
				</a>
			</p>
		</section>
	<?php endif; ?>
</div>
