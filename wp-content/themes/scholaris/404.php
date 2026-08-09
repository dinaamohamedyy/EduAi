<?php
/**
 * 404 template.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="wrap section" style="min-height:52vh;display:grid;place-items:center">
	<div class="empty-state" style="max-width:56ch">
		<p class="eyebrow"><?php esc_html_e( 'Error 404', 'scholaris' ); ?></p>
		<h1><?php esc_html_e( 'That page has gone missing', 'scholaris' ); ?></h1>
		<p><?php esc_html_e( 'The link may be out of date. Search the library, or ask the assistant where to find what you need.', 'scholaris' ); ?></p>
		<div class="mt-m"><?php get_search_form(); ?></div>
		<p class="mt-m mb-0">
			<a class="btn btn--primary" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to home', 'scholaris' ); ?></a>
		</p>
	</div>
</div>

<?php
get_footer();
