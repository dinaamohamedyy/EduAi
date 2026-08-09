<?php
/**
 * Generic archive / blog fallback.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="page-hero">
	<div class="wrap">
		<?php scholaris_breadcrumbs(); ?>
		<h1 class="mb-0">
			<?php
			if ( is_home() && ! is_front_page() ) {
				single_post_title();
			} elseif ( is_search() ) {
				/* translators: %s: search query */
				printf( esc_html__( 'Results for “%s”', 'scholaris' ), esc_html( get_search_query() ) );
			} else {
				the_archive_title();
			}
			?>
		</h1>
		<?php the_archive_description( '<p class="text-muted mt-m mb-0">', '</p>' ); ?>
	</div>
</div>

<div class="wrap section">
	<?php if ( have_posts() ) : ?>
		<div class="grid grid--3">
			<?php
			while ( have_posts() ) :
				the_post();
				?>
				<article <?php post_class( 'card card--link card--flush reveal' ); ?>>
					<?php if ( has_post_thumbnail() ) : ?>
						<a class="card__media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
							<?php the_post_thumbnail( 'scholaris-card' ); ?>
						</a>
					<?php endif; ?>
					<div class="card__body">
						<p class="card__meta"><?php echo esc_html( get_the_date() ); ?></p>
						<h2 class="card__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
						<p class="card__meta"><?php echo esc_html( get_the_excerpt() ); ?></p>
					</div>
				</article>
			<?php endwhile; ?>
		</div>

		<?php
		the_posts_pagination( array(
			'class'     => 'pagination',
			'mid_size'  => 1,
			'prev_text' => esc_html__( '← Previous', 'scholaris' ),
			'next_text' => esc_html__( 'Next →', 'scholaris' ),
		) );
		?>
	<?php else : ?>
		<div class="empty-state">
			<h3><?php esc_html_e( 'Nothing here yet', 'scholaris' ); ?></h3>
			<p><?php esc_html_e( 'Try a different search, or head back to the library.', 'scholaris' ); ?></p>
			<?php get_search_form(); ?>
		</div>
	<?php endif; ?>
</div>

<?php
get_footer();
