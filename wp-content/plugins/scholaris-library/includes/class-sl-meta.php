<?php
/**
 * Meta box for attaching the document file.
 *
 * @package ScholarisLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the file picker, page count and access level.
 */
class SL_Meta {

	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_study_material', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
	}

	public static function add_box(): void {
		add_meta_box(
			'sl_material_file',
			__( 'Document', 'scholaris-library' ),
			array( __CLASS__, 'render' ),
			'study_material',
			'side',
			'high'
		);
	}

	/**
	 * Load the media picker on the study-material editor only.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function admin_assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		if ( 'study_material' !== get_post_type() ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script( 'sl-admin', SL_URL . 'assets/js/admin.js', array( 'jquery' ), SL_VERSION, true );
		wp_localize_script( 'sl-admin', 'SLAdmin', array(
			'title'  => __( 'Choose the document', 'scholaris-library' ),
			'button' => __( 'Use this file', 'scholaris-library' ),
		) );
	}

	/**
	 * Meta box markup.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render( $post ): void {
		wp_nonce_field( 'sl_save_material', 'sl_material_nonce' );

		$file_id = (int) get_post_meta( $post->ID, '_scholaris_file_id', true );
		$pages   = (int) get_post_meta( $post->ID, '_scholaris_pages', true );
		$access  = (string) get_post_meta( $post->ID, '_scholaris_access', true ) ?: 'members';
		$lecturer = (string) get_post_meta( $post->ID, '_scholaris_lecturer', true );
		$url     = $file_id ? wp_get_attachment_url( $file_id ) : '';
		$name    = $file_id ? basename( (string) get_attached_file( $file_id ) ) : '';
		?>
		<p>
			<input type="hidden" id="sl_file_id" name="sl_file_id" value="<?php echo esc_attr( (string) $file_id ); ?>">
			<span id="sl_file_label" style="display:block;margin-bottom:8px;word-break:break-all">
				<?php if ( $url ) : ?>
					<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $name ); ?></a>
				<?php else : ?>
					<em><?php esc_html_e( 'No file attached yet.', 'scholaris-library' ); ?></em>
				<?php endif; ?>
			</span>
			<button type="button" class="button button-primary" id="sl_pick_file"><?php esc_html_e( 'Choose file', 'scholaris-library' ); ?></button>
			<button type="button" class="button" id="sl_clear_file"><?php esc_html_e( 'Remove', 'scholaris-library' ); ?></button>
		</p>

		<p>
			<label for="sl_pages"><strong><?php esc_html_e( 'Pages', 'scholaris-library' ); ?></strong></label><br>
			<input type="number" min="0" class="widefat" id="sl_pages" name="sl_pages" value="<?php echo esc_attr( (string) $pages ); ?>">
			<span class="description"><?php esc_html_e( 'Left blank, this is detected automatically for PDFs.', 'scholaris-library' ); ?></span>
		</p>

		<p>
			<label for="sl_lecturer"><strong><?php esc_html_e( 'Lecturer', 'scholaris-library' ); ?></strong></label><br>
			<input type="text" class="widefat" id="sl_lecturer" name="sl_lecturer" value="<?php echo esc_attr( $lecturer ); ?>">
		</p>

		<p>
			<label for="sl_access"><strong><?php esc_html_e( 'Who can download', 'scholaris-library' ); ?></strong></label><br>
			<select class="widefat" id="sl_access" name="sl_access">
				<option value="public" <?php selected( $access, 'public' ); ?>><?php esc_html_e( 'Anyone', 'scholaris-library' ); ?></option>
				<option value="members" <?php selected( $access, 'members' ); ?>><?php esc_html_e( 'Signed-in students', 'scholaris-library' ); ?></option>
			</select>
		</p>
		<?php
	}

	/**
	 * Persist the meta box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save( int $post_id, $post ): void {
		if ( ! isset( $_POST['sl_material_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sl_material_nonce'] ) ), 'sl_save_material' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$file_id = isset( $_POST['sl_file_id'] ) ? absint( wp_unslash( $_POST['sl_file_id'] ) ) : 0;
		update_post_meta( $post_id, '_scholaris_file_id', $file_id );

		$pages = isset( $_POST['sl_pages'] ) ? absint( wp_unslash( $_POST['sl_pages'] ) ) : 0;

		// Detect the page count when the editor left it blank.
		if ( ! $pages && $file_id && class_exists( 'EduAI_PDF' ) ) {
			$path = get_attached_file( $file_id );
			if ( $path && 'pdf' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
				$pages = EduAI_PDF::page_count( $path );
			}
		}

		update_post_meta( $post_id, '_scholaris_pages', $pages );

		update_post_meta(
			$post_id,
			'_scholaris_lecturer',
			isset( $_POST['sl_lecturer'] ) ? sanitize_text_field( wp_unslash( $_POST['sl_lecturer'] ) ) : ''
		);

		$access = isset( $_POST['sl_access'] ) ? sanitize_key( wp_unslash( $_POST['sl_access'] ) ) : 'members';
		update_post_meta( $post_id, '_scholaris_access', in_array( $access, array( 'public', 'members' ), true ) ? $access : 'members' );
	}

	/**
	 * Can the current visitor download this document?
	 *
	 * @param int $post_id Material ID.
	 */
	public static function can_download( int $post_id ): bool {
		$access = (string) get_post_meta( $post_id, '_scholaris_access', true ) ?: 'members';

		if ( 'public' === $access ) {
			return true;
		}

		return is_user_logged_in();
	}
}
