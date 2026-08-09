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
