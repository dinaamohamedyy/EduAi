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
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );

		// See filter_media_query(). Deliberate policy, not a core bug fix.
		add_filter( 'rest_attachment_query', array( __CLASS__, 'filter_media_query' ), 10, 2 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_media_item' ), 10, 3 );
	}

	/**
	 * Attachment ids referenced by a members-only material.
	 *
	 * Materials reference their files by meta, not by post_parent — a file
	 * chosen from the media library keeps whatever parent it already had —
	 * so the meta is the only reliable link.
	 */
	public static function gated_attachment_ids(): array {
		static $ids = null;

		if ( null !== $ids ) {
			return $ids;
		}

		$ids       = array();
		$materials = get_posts( array(
			'post_type'      => 'study_material',
			'post_status'    => 'publish',
			'numberposts'    => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_scholaris_access',
					'value' => 'members',
				),
			),
		) );

		foreach ( $materials as $material_id ) {
			foreach ( array( '_scholaris_file_id', '_scholaris_video_id' ) as $key ) {
				$attachment = (int) get_post_meta( $material_id, $key, true );
				if ( $attachment ) {
					$ids[] = $attachment;
				}
			}
		}

		$ids = array_values( array_unique( $ids ) );

		return $ids;
	}

	/**
	 * Keep files belonging to members-only material out of the anonymous
	 * media index.
	 *
	 * `wp/v2/media` listing attachments of published posts to anonymous
	 * callers is **stock WordPress behaviour, not a regression** — which is
	 * why this is written as a deliberate policy filter rather than a patch.
	 * Do not "restore core defaults" here: without it, /wp-json/wp/v2/media
	 * publishes the direct source_url of every gated lecture, so a visitor
	 * does not even have to guess a path.
	 *
	 * Scope, stated so nobody mistakes it for more than it is: this removes
	 * the public *index*. It does NOT make the file unreachable — Apache
	 * still serves the direct URL, which is the separate denied-directory
	 * work. Defence in depth, not the gate.
	 *
	 * The rule mirrors can_download(): `members` means signed-in, so signed-in
	 * callers are unaffected and only anonymous ones are filtered.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function filter_media_query( $args ) {
		if ( is_user_logged_in() ) {
			return $args;
		}

		/*
		 * Anonymous callers get nothing from this index.
		 *
		 * The narrower version — exclude only gated attachments — still lets
		 * an anonymous caller enumerate every other file on the site, and
		 * "nothing legitimate consumes this" is a statement about today. An
		 * anonymous consumer of a media index is a search engine or someone
		 * mapping uploads/, and this site is published through a public
		 * tunnel with the owner's lecture PDF in exactly that listing.
		 *
		 * Signed-in callers are untouched, which is what makes this safe to
		 * be wrong about: an anonymous client breaks visibly and this is one
		 * line to revert, where leaving it open fails silently and the
		 * failure is a file listing. Verified by dispatching the route with a
		 * controlled user — dina 7 entries, a student 7, anonymous 0.
		 */
		$args['post__in'] = array( 0 );

		return $args;
	}

	/**
	 * Single-item route: refuse the record, do not redact it.
	 *
	 * The first version of this blanked `source_url` and `guid` and looked
	 * right. It was not: `description.rendered` embeds the file URL inside an
	 * anchor, and `filename` hands over the last segment of a path whose
	 * shape (`/uploads/YYYY/MM/`) is fixed. Redaction meant enumerating the
	 * fields that happen to carry the address today — and any field added by
	 * core or a plugin tomorrow reopens it silently. Withholding the whole
	 * record closes the class instead of its instances.
	 *
	 * Field surgery here is also hostile ground, which is the second reason
	 * not to go back to it: `media_details` is `new stdClass()` for a
	 * non-image attachment (so the JSON emits `{}` rather than `[]`), and in
	 * PHP 8 array-access on an object is fatal **even inside `isset()`** — a
	 * plain `.txt` returned HTTP 500 from this route while that was being
	 * attempted. Removing schema-declared keys such as `source_url` breaks
	 * the response separately. Neither problem exists if the record is
	 * simply not served.
	 *
	 * 404 rather than 403, matching core's own `rest_post_invalid_id`: a 403
	 * would confirm that the id exists, which is the thing being withheld.
	 *
	 * @param mixed           $result  Short-circuit value, null to continue.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function guard_media_item( $result, $server, $request ) {
		if ( null !== $result || is_user_logged_in() ) {
			return $result;
		}

		if ( ! preg_match( '#^/wp/v2/media/(\d+)$#', (string) $request->get_route(), $m ) ) {
			return $result;
		}

		if ( ! in_array( (int) $m[1], self::gated_attachment_ids(), true ) ) {
			return $result;
		}

		return new WP_Error(
			'rest_post_invalid_id',
			__( 'Invalid post ID.', 'scholaris-library' ),
			array( 'status' => 404 )
		);
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

		add_meta_box(
			'sl_material_video',
			__( 'Video', 'scholaris-library' ),
			array( __CLASS__, 'render_video' ),
			'study_material',
			'side',
			'default'
		);
	}

	/**
	 * Say what is actually true of this material's files, right now.
	 *
	 * This started as a single warning: "the file itself is not protected".
	 * That was true when it was written and became FALSE when SL_Private
	 * landed — a members-only file is moved into a denied directory and
	 * answers 403. A label that overstates the danger is not the safe
	 * direction to be wrong in: it steers the owner off a feature that
	 * works, and nothing ever fails on it, so nobody finds out. So the box
	 * reports measured state rather than a fixed sentence.
	 *
	 * @param int    $material_id Material.
	 * @param string $kind        'document' or 'video', for the remedy line.
	 * @param string $url         Public address, when one is worth offering.
	 */
	private static function print_file_state( int $material_id, string $kind, string $url = '' ): void {
		$access = (string) get_post_meta( $material_id, '_scholaris_access', true ) ?: 'members';

		if ( 'members' !== $access ) {
			return; // Public material is public. Nothing to promise or warn about.
		}

		// The honest question is not "is this restricted?" but "is the file
		// where being restricted requires it to be?"
		$secured = ! class_exists( 'SL_Private' ) || SL_Private::is_secured( $material_id );

		if ( $secured ) {
			?>
			<p class="description" style="margin-top:6px;padding:6px 8px;border-left:3px solid #00a32a;background:#f0f6f1">
				<strong><?php esc_html_e( 'This file is protected.', 'scholaris-library' ); ?></strong>
				<?php esc_html_e( 'It is stored outside the public folder and served only to signed-in students, through this page.', 'scholaris-library' ); ?>
			</p>
			<?php
			return;
		}

		self::print_reach_warning( $kind, $url );
	}

	private static function file_reach_warning( string $kind = 'document' ): string {
		// One job for the warning: the label no longer needs un-teaching,
		// because "Show the download button to" says what the setting does.
		$lead = __( '<strong>The file itself is not protected.</strong> Anyone with its address can open it, signed in or not — this setting only controls the button on the page.', 'scholaris-library' );

		// Only the remedy branches. "Paste an unlisted link instead" is not
		// advice you can follow about a PDF, and the leak we measured was a
		// document.
		$remedy = 'video' === $kind
			? __( 'To keep a recording inside the class, paste an unlisted YouTube or Vimeo link instead of uploading.', 'scholaris-library' )
			: __( 'Do not upload anything here that must stay inside the class.', 'scholaris-library' );

		return $lead . ' ' . $remedy;
	}

	/**
	 * The warning, wrapped. Kept in one place so the document and video boxes
	 * cannot drift apart in wording or in styling.
	 *
	 * @param string $kind  'document' or 'video'.
	 * @param string $url   Public address to offer for checking, if known.
	 */
	private static function print_reach_warning( string $kind, string $url = '' ): void {
		?>
		<p class="description" style="margin-top:6px;padding:6px 8px;border-left:3px solid #d63638;background:#fcf0f1">
			<?php
			echo wp_kses( self::file_reach_warning( $kind ), array( 'strong' => array() ) );

			// A claim the editor can check in ten seconds beats a claim they
			// have to believe — the method that has actually worked here.
			if ( $url ) :
				?>
				<br><br>
				<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $url ); ?></a>
				<br>
				<em><?php esc_html_e( 'Open this in a private window to see what a stranger sees.', 'scholaris-library' ); ?></em>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Hosts whose URLs we will embed. The allowlist is not decoration: it is
	 * also what stops core's embed_oembed_discover from making an outbound
	 * fetch to any host an admin happens to type (docs/07 §2.3 rule 5).
	 */
	public static function video_hosts(): array {
		return (array) apply_filters( 'scholaris_video_hosts', array(
			'youtube.com',
			'm.youtube.com',
			'youtu.be',
			'vimeo.com',
			'player.vimeo.com',
		) );
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
		// The meta-box rules live in the same stylesheet as the console's.
		wp_enqueue_style( 'sl-admin', SL_URL . 'assets/css/admin.css', array(), SL_VERSION );
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
			<?php // Names what the setting does. The old "Who can download" was a promise the system does not keep. ?>
			<label for="sl_access"><strong><?php esc_html_e( 'Show the download button to', 'scholaris-library' ); ?></strong></label><br>
			<select class="widefat" id="sl_access" name="sl_access">
				<option value="public" <?php selected( $access, 'public' ); ?>><?php esc_html_e( 'Anyone', 'scholaris-library' ); ?></option>
				<option value="members" <?php selected( $access, 'members' ); ?>><?php esc_html_e( 'Signed-in students', 'scholaris-library' ); ?></option>
			</select>
			<?php
			// Keyed on "a file is attached", never on "the file is a video" —
			// the measured leak was a .txt on a document.
			if ( $file_id ) {
				self::print_file_state( (int) $post->ID, "document", (string) $url );
			}
			?>
		</p>
		<?php
	}

	/**
	 * Video meta box: one radio decides the source, so a link and an upload
	 * can never both look active and silently disagree.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_video( $post ): void {
		// No second nonce: the Document box already emitted sl_material_nonce
		// for this same form, and one write path is the point (docs/07 §2.1).
		$source = (string) get_post_meta( $post->ID, '_scholaris_video_source', true );
		$source = in_array( $source, array( 'link', 'file' ), true ) ? $source : '';
		$url    = (string) get_post_meta( $post->ID, '_scholaris_video_url', true );
		$vid    = (int) get_post_meta( $post->ID, '_scholaris_video_id', true );
		$access = (string) get_post_meta( $post->ID, '_scholaris_access', true ) ?: 'members';
		$vurl   = $vid ? wp_get_attachment_url( $vid ) : '';
		$vname  = $vid ? basename( (string) get_attached_file( $vid ) ) : '';
		?>
		<p>
			<label><input type="radio" name="sl_video_source" value="" <?php checked( $source, '' ); ?>> <?php esc_html_e( 'None', 'scholaris-library' ); ?></label><br>
			<label><input type="radio" name="sl_video_source" value="link" <?php checked( $source, 'link' ); ?>> <?php esc_html_e( 'Link (recommended)', 'scholaris-library' ); ?></label><br>
			<label><input type="radio" name="sl_video_source" value="file" <?php checked( $source, 'file' ); ?>> <?php esc_html_e( 'Uploaded file', 'scholaris-library' ); ?></label>
		</p>

		<div data-sl-video-pane="link" <?php echo 'link' === $source ? '' : 'hidden'; ?>>
			<p>
				<label for="sl_video_url"><strong><?php esc_html_e( 'Video address', 'scholaris-library' ); ?></strong></label>
				<input type="url" class="widefat" id="sl_video_url" name="sl_video_url"
					value="<?php echo esc_attr( $url ); ?>" placeholder="https://www.youtube.com/watch?v=…">
				<span class="description">
					<?php
					printf(
						/* translators: %s: comma-separated host list. */
						esc_html__( 'Paste the address from your browser bar. Accepted: %s. Nothing is uploaded, the 64 MB limit never applies, and the provider handles seeking and bandwidth.', 'scholaris-library' ),
						esc_html( implode( ', ', self::video_hosts() ) )
					);
					?>
				</span>
			</p>
		</div>

		<div data-sl-video-pane="file" <?php echo 'file' === $source ? '' : 'hidden'; ?>>
			<p>
				<input type="hidden" id="sl_video_id" name="sl_video_id" value="<?php echo esc_attr( (string) $vid ); ?>">
				<span id="sl_video_label" style="display:block;margin-bottom:8px;word-break:break-all">
					<?php if ( $vurl ) : ?>
						<a href="<?php echo esc_url( $vurl ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $vname ); ?></a>
					<?php else : ?>
						<em><?php esc_html_e( 'No video attached yet.', 'scholaris-library' ); ?></em>
					<?php endif; ?>
				</span>
				<button type="button" class="button button-primary" id="sl_pick_video"><?php esc_html_e( 'Choose video', 'scholaris-library' ); ?></button>
				<button type="button" class="button" id="sl_clear_video"><?php esc_html_e( 'Remove', 'scholaris-library' ); ?></button>
			</p>
			<?php
			if ( $vid ) {
				self::print_file_state( (int) $post->ID, "video", (string) $vurl );
			}
			?>
		</div>
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

		// An id that is not an attachment was accepted here until now: absint()
		// alone will happily store a page id, or the id of a post the editor
		// cannot see (docs/07 §2.3).
		$file_id = isset( $_POST['sl_file_id'] ) ? absint( wp_unslash( $_POST['sl_file_id'] ) ) : 0;
		if ( $file_id && 'attachment' !== get_post_type( $file_id ) ) {
			$file_id = 0;
		}
		update_post_meta( $post_id, '_scholaris_file_id', $file_id );

		self::save_video( $post_id );

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
	 * Persist the video source, and refuse rather than blank.
	 *
	 * A rejected URL keeps the previous value and reports the host that was
	 * refused: silently emptying a field the editor just filled in is the
	 * worse failure, because it looks like the save worked (docs/07 §2.3).
	 *
	 * @param int $post_id Post ID.
	 */
	private static function save_video( int $post_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the caller.
		$source = isset( $_POST['sl_video_source'] ) ? sanitize_key( wp_unslash( $_POST['sl_video_source'] ) ) : '';
		$source = in_array( $source, array( 'link', 'file' ), true ) ? $source : '';

		// ---------------------------------------------------------- link ---
		$raw = isset( $_POST['sl_video_url'] ) ? trim( (string) wp_unslash( $_POST['sl_video_url'] ) ) : '';
		$url = $raw ? esc_url_raw( $raw, array( 'http', 'https' ) ) : '';

		if ( 'link' === $source && '' !== $raw ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$host = (string) preg_replace( '/^www\./', '', $host );
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );

			// The parsed host, never strpos() on the whole string:
			// https://evil.com/?x=youtube.com passes a substring test.
			if ( ! $url || ! in_array( $host, self::video_hosts(), true ) ) {
				self::flag( $post_id, sprintf(
					/* translators: %s: the rejected host, or the raw value when it has none. */
					__( 'That video address was not saved: %s is not on the accepted list. The previous value was kept.', 'scholaris-library' ),
					$host ?: wp_html_excerpt( $raw, 60, '…' )
				) );
				return;
			}

			// An /embed/ URL is not a registered oEmbed provider, so it would
			// render as a bare link with nothing explaining why.
			if ( preg_match( '#^/(embed|v)/#', $path ) ) {
				self::flag( $post_id, __( 'That video address was not saved: paste the address from your browser\'s address bar, not the embed address. The previous value was kept.', 'scholaris-library' ) );
				return;
			}

			update_post_meta( $post_id, '_scholaris_video_url', $url );
			update_post_meta( $post_id, '_scholaris_video_source', 'link' );
			return;
		}

		// ---------------------------------------------------------- file ---
		$video_id = isset( $_POST['sl_video_id'] ) ? absint( wp_unslash( $_POST['sl_video_id'] ) ) : 0;

		if ( $video_id && ( 'attachment' !== get_post_type( $video_id )
			|| ! str_starts_with( (string) get_post_mime_type( $video_id ), 'video/' ) ) ) {
			self::flag( $post_id, __( 'That attachment was not saved as the video: it is not a video file.', 'scholaris-library' ) );
			$video_id = 0;
		}

		// "file" with nothing attached is not a state worth storing: the
		// renderer would treat it as no video anyway, and a source that
		// claims a file it does not have is the disagreement this enum
		// exists to prevent.
		if ( 'file' === $source && ! $video_id ) {
			$source = '';
		}

		update_post_meta( $post_id, '_scholaris_video_id', $video_id );
		update_post_meta( $post_id, '_scholaris_video_source', $source );
		// phpcs:enable
	}

	/**
	 * Same as flag(), reachable from other classes — SL_Private needs to tell
	 * the editor when a file could not be moved out of the public folder,
	 * and that failure must not be silent just because it happened in a
	 * different file.
	 *
	 * @param int    $post_id Post the notice belongs to.
	 * @param string $message What to say.
	 */
	public static function flag_public( int $post_id, string $message ): void {
		self::flag( $post_id, $message );
	}

	/**
	 * Queue an editor-facing notice for the next admin screen.
	 *
	 * @param int    $post_id Post the notice belongs to.
	 * @param string $message What to say.
	 */
	private static function flag( int $post_id, string $message ): void {
		$notices   = (array) get_post_meta( $post_id, '_scholaris_admin_notices', true );
		$notices[] = $message;
		update_post_meta( $post_id, '_scholaris_admin_notices', array_slice( $notices, -5 ) );
	}

	/**
	 * Print and clear anything save() refused to store.
	 */
	public static function admin_notices(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'study_material' !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notices = $post_id ? (array) get_post_meta( $post_id, '_scholaris_admin_notices', true ) : array();

		if ( ! $notices ) {
			return;
		}

		delete_post_meta( $post_id, '_scholaris_admin_notices' );

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( (string) $notice )
			);
		}
	}

	/**
	 * Does this material have a video the front end should render?
	 *
	 * The one definition. It existed independently in three places —
	 * single-study_material.php, library-grid.php and SL_Console — all
	 * agreeing, which is the dangerous state: the rule is not obvious
	 * (`link` needs a URL, `file` needs an attachment id, `''` means none),
	 * so a fourth copy written from memory would be subtly wrong rather than
	 * obviously wrong. When they disagree the symptom is the blank material
	 * page the §2.4 restructure just fixed, or a lecture the console counts
	 * and the listing will not show.
	 *
	 * WHY IT DOES NOT FALL BACK TO `_scholaris_video_id`. Asked after a
	 * fixture wrote the id without the source and produced a material that
	 * streamed correctly and rendered nothing. The reason is not "one source
	 * of truth" — it is that an id with no source is a state this plugin
	 * creates DELIBERATELY: switching the radio to None keeps the attachment
	 * id, so an editor who changes their mind twice does not lose their pick.
	 * Verified — choose a video: source=file, id=112; switch to None:
	 * source='', id=112. A fallback would resurrect a video the editor had
	 * just switched off, on every material anyone had ever done that to,
	 * which is a worse bug than the one it fixes.
	 *
	 * So the source is the switch and the id is the remembered selection. A
	 * programmatic writer must set BOTH — and cannot be reported as faulty
	 * if it does not, because "id present, source empty" is
	 * indistinguishable from a deliberate None.
	 *
	 * @param int $material_id Material ID.
	 */
	public static function has_video( int $material_id ): bool {
		$source = (string) get_post_meta( $material_id, '_scholaris_video_source', true );

		if ( 'link' === $source ) {
			return '' !== (string) get_post_meta( $material_id, '_scholaris_video_url', true );
		}

		if ( 'file' === $source ) {
			return (bool) (int) get_post_meta( $material_id, '_scholaris_video_id', true );
		}

		return false;
	}

	/**
	 * Does this material have anything to show at all — document or video?
	 *
	 * The health question the console asks, and the gate the single template
	 * asks. Kept beside has_video() so the two cannot drift.
	 *
	 * @param int $material_id Material ID.
	 */
	public static function has_media( int $material_id ): bool {
		return (bool) (int) get_post_meta( $material_id, '_scholaris_file_id', true )
			|| self::has_video( $material_id );
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
