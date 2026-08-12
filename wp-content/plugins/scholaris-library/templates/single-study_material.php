<?php
/**
 * Single study material: metadata, inline PDF viewer, download, assistant hook.
 *
 * @package ScholarisLibrary
 */

defined( 'ABSPATH' ) || exit;

get_header();

the_post();

$sl_id       = get_the_ID();
$sl_file_id  = (int) get_post_meta( $sl_id, '_scholaris_file_id', true );
$sl_path     = $sl_file_id ? (string) get_attached_file( $sl_file_id ) : '';
$sl_ext      = $sl_path ? strtolower( (string) pathinfo( $sl_path, PATHINFO_EXTENSION ) ) : '';
$sl_pages    = (int) get_post_meta( $sl_id, '_scholaris_pages', true );
$sl_lecturer = (string) get_post_meta( $sl_id, '_scholaris_lecturer', true );
$sl_size     = $sl_path && file_exists( $sl_path ) ? size_format( (int) filesize( $sl_path ) ) : '';
$sl_allowed  = SL_Meta::can_download( $sl_id );
$sl_subjects = get_the_terms( $sl_id, 'material_subject' );
$sl_types    = get_the_terms( $sl_id, 'material_type' );

/*
 * Video, and the reason the conditions below are structured rather than
 * spot-edited. A LINK material — the recommended default, because it puts no
 * bytes in uploads — has _scholaris_file_id = 0. The old `if ( $sl_file_id &&
 * $sl_allowed )` / `elseif ( $sl_file_id && ! $sl_allowed )` pair therefore
 * matched neither branch for it: title, description, and nothing else. Putting
 * the video inside the old `if` keeps that blank page; putting it outside loses
 * the access gate. So the question the template asks is "is there media" and
 * the question each block asks is "media of which kind".
 *
 * The same shape already bit us without any video involved: a material whose
 * file is missing renders the same silence today.
 */
$sl_video_src = (string) get_post_meta( $sl_id, '_scholaris_video_source', true );
$sl_video_url = (string) get_post_meta( $sl_id, '_scholaris_video_url', true );
$sl_video_id  = (int) get_post_meta( $sl_id, '_scholaris_video_id', true );
$sl_has_video = ( 'link' === $sl_video_src && '' !== $sl_video_url )
	|| ( 'file' === $sl_video_src && $sl_video_id );
$sl_has_media = $sl_file_id || $sl_has_video;

wp_enqueue_style( 'scholaris-library' );
wp_enqueue_script( 'scholaris-library' );
?>

<div class="page-hero">
	<div class="wrap">
		<?php if ( function_exists( 'scholaris_breadcrumbs' ) ) { scholaris_breadcrumbs(); } ?>

		<div class="sl-single__tags">
			<?php if ( $sl_subjects && ! is_wp_error( $sl_subjects ) ) : ?>
				<?php foreach ( $sl_subjects as $sl_term ) : ?>
					<a class="sl-badge sl-badge--brand" href="<?php echo esc_url( (string) get_term_link( $sl_term ) ); ?>">
						<?php echo esc_html( $sl_term->name ); ?>
					</a>
				<?php endforeach; ?>
			<?php endif; ?>
			<?php if ( $sl_types && ! is_wp_error( $sl_types ) ) : ?>
				<?php foreach ( $sl_types as $sl_term ) : ?>
					<span class="sl-badge"><?php echo esc_html( $sl_term->name ); ?></span>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<h1 class="mb-0"><?php the_title(); ?></h1>

		<p class="sl-single__meta">
			<?php
			echo esc_html( implode( ' · ', array_filter( array(
				$sl_lecturer,
				$sl_ext ? strtoupper( $sl_ext ) : '',
				$sl_pages ? sprintf(
					/* translators: %d: pages */
					_n( '%d page', '%d pages', $sl_pages, 'scholaris-library' ),
					$sl_pages
				) : '',
				$sl_size,
				get_the_date(),
			) ) ) );
			?>
		</p>
	</div>
</div>

<div class="wrap section">
	<div class="sl-single">

		<div class="sl-single__main">
			<?php if ( get_the_content() ) : ?>
				<div class="entry-content"><?php the_content(); ?></div>
			<?php endif; ?>

			<?php if ( $sl_has_media && $sl_allowed ) : ?>

				<?php if ( $sl_has_video ) : ?>
					<div class="sl-video">
						<?php
						if ( 'link' === $sl_video_src ) {
							/*
							 * WP_Embed::shortcode(), NOT wp_oembed_get(). The
							 * latter goes straight to WP_oEmbed::get_html()
							 * with no cache, so every page view would make a
							 * blocking request to the provider. This one caches
							 * the returned HTML in _oembed_* post meta, and
							 * takes its post id from get_post() — which
							 * resolves because we are inside the loop.
							 *
							 * It returns provider HTML, and falls back to a
							 * plain anchor when it cannot embed. Escaping it
							 * would print the markup instead of playing it,
							 * which is why this is deliberately not escaped.
							 */
							echo $GLOBALS['wp_embed']->shortcode( array(), $sl_video_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} else {
							/*
							 * SL_Private::stream_url(), not the raw uploads URL
							 * and not download_url(). download_url() resolves
							 * _scholaris_file_id, so on a video-only material
							 * it 404s and on a material carrying both it would
							 * feed the DOCUMENT to a <video> element.
							 * handle_stream() resolves _scholaris_video_id
							 * first, enforces the same access gate, and is the
							 * path with real Range support — which a player
							 * needs to seek at all.
							 */
							/*
							 * A plain <video>, NOT wp_video_shortcode(), and
							 * this is measured rather than preferred.
							 *
							 * wp_video_shortcode() decides what to emit by
							 * sniffing a file extension off the URL. The gated
							 * stream URL is `?sl_stream=<id>&_wpnonce=<n>` and
							 * has no extension anywhere in it, so:
							 *   passed as 'src'  → returns <a class="wp-embedded-video">,
							 *                      the lecture rendered as a link
							 *                      to a query string
							 *   passed as 'mp4'  → the same sniff runs on that
							 *                      key, $primary stays false, it
							 *                      falls through to attached
							 *                      media, finds none, returns ''
							 *                      — an empty block
							 * Both were observed on this page, in that order.
							 * The shortcode cannot render a player for any URL
							 * that does not look like a file, and the URL must
							 * stay nonced and extensionless for the gate to work.
							 *
							 * The alternative is a rewrite giving the stream a
							 * file-shaped path; that is back-end's call and is
							 * raised with them. Until then this element does the
							 * same job with less: no MediaElement, no inline
							 * width to override, and it sizes from the wrapper.
							 */
							$sl_stream = SL_Private::stream_url( $sl_id );
							$sl_mime   = (string) get_post_mime_type( $sl_video_id );
							?>
							<video controls preload="metadata"
								<?php echo $sl_file_id ? '' : 'playsinline'; ?>>
								<source src="<?php echo esc_url( $sl_stream ); ?>"
									<?php echo $sl_mime ? 'type="' . esc_attr( $sl_mime ) . '"' : ''; ?>>
								<?php
								// Reached when the browser can play none of the
								// sources. A link is still something.
								printf(
									/* translators: %s: link to the video file */
									esc_html__( 'Your browser cannot play this video. %s', 'scholaris-library' ),
									'<a href="' . esc_url( $sl_stream ) . '">' . esc_html__( 'Open it directly', 'scholaris-library' ) . '</a>'
								);
								?>
							</video>
							<?php
						}
						?>
					</div>
				<?php endif; ?>

				<?php if ( $sl_file_id ) : ?>
				<div class="sl-viewer">
					<div class="sl-viewer__bar">
						<strong><?php echo esc_html( basename( $sl_path ) ); ?></strong>
						<a class="sl-btn sl-btn--primary sl-btn--sm"
							href="<?php echo esc_url( SL_Library::download_url( $sl_id ) ); ?>" download>
							<?php esc_html_e( 'Download', 'scholaris-library' ); ?>
						</a>
					</div>

					<?php if ( 'pdf' === $sl_ext ) : ?>
						<object class="sl-viewer__frame"
							data="<?php echo esc_url( SL_Library::download_url( $sl_id ) ); ?>#view=FitH"
							type="application/pdf">
							<div class="sl-viewer__fallback">
								<p><?php esc_html_e( 'Your browser cannot display this PDF inline.', 'scholaris-library' ); ?></p>
								<a class="sl-btn sl-btn--primary" href="<?php echo esc_url( SL_Library::download_url( $sl_id ) ); ?>">
									<?php esc_html_e( 'Open the PDF', 'scholaris-library' ); ?>
								</a>
							</div>
						</object>
					<?php else : ?>
						<div class="sl-viewer__fallback">
							<p><?php esc_html_e( 'This document type opens in your usual application.', 'scholaris-library' ); ?></p>
							<a class="sl-btn sl-btn--primary" href="<?php echo esc_url( SL_Library::download_url( $sl_id ) ); ?>">
								<?php esc_html_e( 'Download the file', 'scholaris-library' ); ?>
							</a>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php
			/*
			 * Widened from $sl_file_id. A video-only material is exactly as
			 * restricted as a document-only one, and under the old condition it
			 * fell through to silence — no player, no notice, no explanation.
			 */
			?>
			<?php elseif ( $sl_has_media && ! $sl_allowed ) : ?>
				<div class="sl-notice">
					<h3>
						<?php
						// Follows the media, so the notice never offers to open
						// a document the material does not have.
						if ( $sl_has_video && ! $sl_file_id ) {
							esc_html_e( 'Sign in to watch this lecture', 'scholaris-library' );
						} else {
							esc_html_e( 'Sign in to open this document', 'scholaris-library' );
						}
						?>
						</h3>
					<p><?php esc_html_e( 'This material is available to registered students.', 'scholaris-library' ); ?></p>
					<a class="sl-btn sl-btn--primary" href="<?php echo esc_url( wp_login_url( (string) get_permalink() ) ); ?>">
						<?php esc_html_e( 'Sign in', 'scholaris-library' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>

		<aside class="sl-single__side">
			<?php if ( function_exists( 'eduai_ask_url' ) ) : ?>
			<?php
			// The whole panel is the assistant's: without the eduai-assistant
			// plugin there is no feature behind it, and a missing control is
			// honest where a link to somewhere unrelated is not. The resolvers
			// are the assistant's own (it owns /ask/ and /summarise/).
			//
			// data-eduai-open stays on the anchors: inert since the dock
			// retired, harmless because the link works without it, and a
			// future dock's preventDefault hijack gets these back free.
			// TODO: carry this document into the panel (?doc=<id>) once
			// /ask/ reads it — a plain link loses the "this document"
			// preselection; acceptable while RAG covers the whole library.
			?>
			<div class="sl-panel">
				<h3><?php esc_html_e( 'Study this document', 'scholaris-library' ); ?></h3>
				<p><?php esc_html_e( 'The assistant has already read this material — ask it anything about the content.', 'scholaris-library' ); ?></p>
				<a class="sl-btn sl-btn--primary sl-btn--block" data-eduai-open
					href="<?php echo esc_url( eduai_ask_url() ); ?>">
					<?php esc_html_e( 'Ask about this document', 'scholaris-library' ); ?>
				</a>
				<a class="sl-btn sl-btn--ghost sl-btn--block" data-eduai-open="summary"
					href="<?php echo esc_url( eduai_summarise_url() ); ?>">
					<?php esc_html_e( 'Summarise it for me', 'scholaris-library' ); ?>
				</a>
			</div>
			<?php endif; ?>

			<?php
			$sl_related = new WP_Query( array(
				'post_type'      => 'study_material',
				'posts_per_page' => 5,
				'post__not_in'   => array( $sl_id ),
				'no_found_rows'  => true,
				'tax_query'      => ( $sl_subjects && ! is_wp_error( $sl_subjects ) ) ? array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'material_subject',
						'field'    => 'term_id',
						'terms'    => wp_list_pluck( $sl_subjects, 'term_id' ),
					),
				) : array(),
			) );
			?>
			<?php if ( $sl_related->have_posts() ) : ?>
				<div class="sl-panel">
					<h3><?php esc_html_e( 'Related material', 'scholaris-library' ); ?></h3>
					<ul class="sl-recent">
						<?php
						while ( $sl_related->have_posts() ) :
							$sl_related->the_post();
							?>
							<li>
								<a href="<?php the_permalink(); ?>">
									<span class="sl-recent__title"><?php the_title(); ?></span>
									<span class="sl-recent__meta"><?php echo esc_html( get_the_date() ); ?></span>
								</a>
							</li>
						<?php endwhile; ?>
					</ul>
				</div>
				<?php wp_reset_postdata(); ?>
			<?php endif; ?>
		</aside>
	</div>
</div>

<?php
get_footer();
