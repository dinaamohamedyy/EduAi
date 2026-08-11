<?php
/**
 * Single page template.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="page-hero">
	<div class="wrap">
		<?php scholaris_breadcrumbs(); ?>
		<h1 class="mb-0"><?php the_title(); ?></h1>
		<?php
		/*
		 * The design gives the four tool pages a lede under the heading; the
		 * hero could not render one at all, so summarise/calc/ask/prepare shipped
		 * as a bare word over a shortcode. It comes from the page excerpt —
		 * WordPress's own field for exactly this — so the owner can edit it in
		 * wp-admin without touching a template, and setup.sh seeds it.
		 *
		 * has_excerpt() is load-bearing, not defensive: get_the_excerpt() ALONE
		 * auto-generates from the content, and the content of these pages is a
		 * bare shortcode, so an unguarded call renders "[eduai_summarizer]" as
		 * the page's lede.
		 */
		if ( has_excerpt() ) :
			?>
			<p class="page-hero__lede"><?php echo esc_html( get_the_excerpt() ); ?></p>
			<?php
		endif;
		?>
	</div>
</div>

<div class="wrap section">
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article <?php post_class( 'entry-content' ); ?>>
			<?php
			the_content();

			wp_link_pages( array(
				'before' => '<nav class="pagination">',
				'after'  => '</nav>',
			) );
			?>
		</article>
		<?php
		if ( comments_open() || get_comments_number() ) {
			comments_template();
		}
	endwhile;
	?>
</div>

<?php
get_footer();
