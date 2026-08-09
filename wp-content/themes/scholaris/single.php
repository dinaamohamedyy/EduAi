<?php
/**
 * Single post template. Study materials get their own template
 * (single-study_material.php) supplied by the Scholaris Library plugin.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="page-hero">
	<div class="wrap wrap--narrow">
		<?php scholaris_breadcrumbs(); ?>
		<h1 class="mb-0"><?php the_title(); ?></h1>
		<p class="text-muted mt-m mb-0">
			<?php echo esc_html( get_the_date() ); ?> · <?php the_author(); ?>
		</p>
	</div>
</div>

<div class="wrap wrap--narrow section">
	<?php
	while ( have_posts() ) :
		the_post();

		if ( has_post_thumbnail() ) {
			echo '<figure style="margin:0 0 var(--space-l)">';
			the_post_thumbnail( 'large', array( 'style' => 'border-radius:var(--radius-l)' ) );
			echo '</figure>';
		}
		?>
		<article <?php post_class( 'entry-content' ); ?>>
			<?php the_content(); ?>
		</article>

		<?php
		the_post_navigation( array(
			'class'      => 'pagination mt-l',
			'prev_text'  => '← %title',
			'next_text'  => '%title →',
		) );

		if ( comments_open() || get_comments_number() ) {
			comments_template();
		}
	endwhile;
	?>
</div>

<?php
get_footer();
