<?php
/**
 * Study-material card used in the library grid and homepage preview.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

$subjects = get_the_terms( get_the_ID(), 'material_subject' );
$types    = get_the_terms( get_the_ID(), 'material_type' );
$file_id  = (int) get_post_meta( get_the_ID(), '_scholaris_file_id', true );
$pages    = (int) get_post_meta( get_the_ID(), '_scholaris_pages', true );
$size     = $file_id ? size_format( (int) filesize( get_attached_file( $file_id ) ?: '' ) ?: 0 ) : '';
?>
<article <?php post_class( 'card card--link card--flush reveal' ); ?>>
	<?php if ( has_post_thumbnail() ) : ?>
		<a class="card__media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
			<?php the_post_thumbnail( 'scholaris-card' ); ?>
		</a>
	<?php else : ?>
		<?php
		$ext  = $file_id ? strtoupper( (string) pathinfo( (string) get_attached_file( $file_id ), PATHINFO_EXTENSION ) ) : '';
		$ext  = $ext ?: 'PDF';
		$meta = $pages ? sprintf( ' · %d pp', $pages ) : '';
		?>
		<div class="card__media card__media--doc" aria-hidden="true">
			<span class="doc-stamp"><?php echo esc_html( $ext . $meta ); ?></span>
		</div>
	<?php endif; ?>

	<div class="card__body">
		<div class="cluster" style="margin-bottom:.5rem">
			<?php if ( $subjects && ! is_wp_error( $subjects ) ) : ?>
				<span class="badge badge--brand"><?php echo esc_html( $subjects[0]->name ); ?></span>
			<?php endif; ?>
			<?php if ( $types && ! is_wp_error( $types ) ) : ?>
				<span class="badge"><?php echo esc_html( $types[0]->name ); ?></span>
			<?php endif; ?>
		</div>

		<h3 class="card__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
		<p class="card__meta"><?php echo esc_html( get_the_excerpt() ); ?></p>
	</div>

	<div class="card__foot">
		<span class="card__meta">
			<?php
			$bits = array_filter( array(
				$pages ? sprintf( /* translators: %d: page count */ _n( '%d page', '%d pages', $pages, 'scholaris' ), $pages ) : '',
				$size,
				get_the_date(),
			) );
			echo esc_html( implode( ' · ', $bits ) );
			?>
		</span>
		<a class="btn btn--quiet btn--sm" href="<?php the_permalink(); ?>"><?php esc_html_e( 'Open', 'scholaris' ); ?> →</a>
	</div>
</article>
