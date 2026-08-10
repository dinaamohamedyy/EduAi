<?php
/**
 * Library archive.
 *
 * @package ScholarisLibrary
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="page-hero">
	<div class="wrap">
		<?php if ( function_exists( 'scholaris_breadcrumbs' ) ) { scholaris_breadcrumbs(); } ?>
		<h1 class="mb-0">
			<?php
			if ( is_tax() ) {
				echo esc_html( wp_strip_all_tags( get_the_archive_title() ) );
			} else {
				esc_html_e( 'Study library', 'scholaris-library' );
			}
			?>
		</h1>
		<?php // .sl-intro, not .sl-single__meta: this paragraph sits outside every sl- wrapper, so it must be its own scope root (see the alias block in library.css). ?>
		<p class="sl-intro">
			<?php esc_html_e( 'Lecture slides, notes, past papers and reading — filter by subject or search the whole collection.', 'scholaris-library' ); ?>
		</p>
	</div>
</div>

<div class="wrap section">
	<?php
	// The shortcode renders the same grid, so the layout stays in one place.
	echo do_shortcode( '[scholaris_library per_page="12" filters="yes"]' );
	?>
</div>

<?php
get_footer();
