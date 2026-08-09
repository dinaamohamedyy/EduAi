<?php
/**
 * Search form.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

$scholaris_search_id = 'search-' . wp_unique_id();
?>
<form role="search" method="get" class="search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label class="screen-reader-text" for="<?php echo esc_attr( $scholaris_search_id ); ?>">
		<?php esc_html_e( 'Search', 'scholaris' ); ?>
	</label>
	<div style="display:flex;gap:.5rem">
		<input type="search" id="<?php echo esc_attr( $scholaris_search_id ); ?>" name="s"
			value="<?php echo esc_attr( get_search_query() ); ?>"
			placeholder="<?php esc_attr_e( 'Search courses, notes and PDFs…', 'scholaris' ); ?>">
		<button class="btn btn--primary" type="submit">
			<?php scholaris_the_icon( 'search', 18 ); ?>
			<span class="screen-reader-text"><?php esc_html_e( 'Search', 'scholaris' ); ?></span>
		</button>
	</div>
</form>
