<?php
/**
 * Library browsing: filters, shortcode and gated downloads.
 *
 * @package ScholarisLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * [scholaris_library] plus the /?sl_download= handler.
 */
class SL_Library {

	public static function init(): void {
		add_shortcode( 'scholaris_library', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_download' ) );
		add_filter( 'pre_get_posts', array( __CLASS__, 'archive_query' ) );
	}

	/**
	 * Order the library archive newest-first and honour the filter form.
	 *
	 * @param WP_Query $query Main query.
	 */
	public static function archive_query( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return $query;
		}

		if ( ! $query->is_post_type_archive( 'study_material' ) ) {
			return $query;
		}

		$query->set( 'posts_per_page', 12 );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$tax_query = array();

		if ( ! empty( $_GET['subject'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'material_subject',
				'field'    => 'slug',
				'terms'    => sanitize_title( wp_unslash( $_GET['subject'] ) ),
			);
		}

		if ( ! empty( $_GET['type'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'material_type',
				'field'    => 'slug',
				'terms'    => sanitize_title( wp_unslash( $_GET['type'] ) ),
			);
		}

		if ( $tax_query ) {
			$tax_query['relation'] = 'AND';
			$query->set( 'tax_query', $tax_query );
		}

		if ( ! empty( $_GET['q'] ) ) {
			$query->set( 's', sanitize_text_field( wp_unslash( $_GET['q'] ) ) );
		}

		$sort = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : '';
		// phpcs:enable

		if ( 'title' === $sort ) {
			$query->set( 'orderby', 'title' );
			$query->set( 'order', 'ASC' );
		} elseif ( 'oldest' === $sort ) {
			$query->set( 'order', 'ASC' );
		}

		return $query;
	}

	/**
	 * [scholaris_library subject="" type="" per_page="12" filters="yes"]
	 *
	 * @param array $atts Attributes.
	 */
	public static function shortcode( $atts = array() ): string {
		$atts = shortcode_atts( array(
			'subject'  => '',
			'type'     => '',
			'per_page' => 12,
			'filters'  => 'yes',
			'columns'  => 3,
		), (array) $atts, 'scholaris_library' );

		wp_enqueue_style( 'scholaris-library' );
		wp_enqueue_script( 'scholaris-library' );

		$paged = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );

		$args = array(
			'post_type'      => 'study_material',
			'posts_per_page' => (int) $atts['per_page'],
			'paged'          => $paged,
		);

		$tax_query = array();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$subject = ! empty( $_GET['subject'] ) ? sanitize_title( wp_unslash( $_GET['subject'] ) ) : $atts['subject'];
		$type    = ! empty( $_GET['type'] ) ? sanitize_title( wp_unslash( $_GET['type'] ) ) : $atts['type'];
		$search  = ! empty( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		// phpcs:enable

		if ( $subject ) {
			$tax_query[] = array( 'taxonomy' => 'material_subject', 'field' => 'slug', 'terms' => $subject );
		}
		if ( $type ) {
			$tax_query[] = array( 'taxonomy' => 'material_type', 'field' => 'slug', 'terms' => $type );
		}
		if ( $tax_query ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		if ( $search ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );

		ob_start();
		include SL_DIR . 'templates/library-grid.php';
		wp_reset_postdata();

		return (string) ob_get_clean();
	}

	/**
	 * Signed download URL for a material's file.
	 *
	 * @param int $post_id Material ID.
	 */
	public static function download_url( int $post_id ): string {
		return add_query_arg(
			array(
				'sl_download' => $post_id,
				'_wpnonce'    => wp_create_nonce( 'sl_download_' . $post_id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Stream the file, enforcing the material's access level.
	 */
	public static function handle_download(): void {
		if ( empty( $_GET['sl_download'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$post_id = absint( wp_unslash( $_GET['sl_download'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'sl_download_' . $post_id ) ) {
			wp_die( esc_html__( 'That download link has expired. Reload the page and try again.', 'scholaris-library' ), '', array( 'response' => 403 ) );
		}

		if ( 'study_material' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			wp_die( esc_html__( 'Document not found.', 'scholaris-library' ), '', array( 'response' => 404 ) );
		}

		if ( ! SL_Meta::can_download( $post_id ) ) {
			wp_safe_redirect( wp_login_url( (string) get_permalink( $post_id ) ) );
			exit;
		}

		$file_id = (int) get_post_meta( $post_id, '_scholaris_file_id', true );
		$path    = $file_id ? get_attached_file( $file_id ) : '';

		if ( ! $path || ! file_exists( $path ) ) {
			wp_die( esc_html__( 'The file is missing from the media library.', 'scholaris-library' ), '', array( 'response' => 404 ) );
		}

		// Record the download for simple analytics.
		update_post_meta( $post_id, '_scholaris_downloads', (int) get_post_meta( $post_id, '_scholaris_downloads', true ) + 1 );

		$type = wp_check_filetype( $path );

		nocache_headers();
		header( 'Content-Type: ' . ( $type['type'] ?: 'application/octet-stream' ) );
		header( 'Content-Disposition: inline; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
