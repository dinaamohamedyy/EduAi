<?php
/**
 * Files that must not be fetchable by address, and a handler that can serve
 * them with seeking.
 *
 * The library's access level has always gated the *page*, never the *file*:
 * `RewriteCond %{REQUEST_FILENAME} !-f` in the WordPress .htaccess means a
 * request that resolves to a real file never reaches index.php, so a
 * members-only PDF sitting in uploads/ answered 200 to anyone with its
 * address. This class is the other half of that promise.
 *
 * WHY RECONCILE ON SAVE RATHER THAN FILTER THE UPLOAD
 * ---------------------------------------------------
 * The plan of record routed uploads with an `upload_dir` filter scoped to one
 * media_handle_upload() call. That cannot work in the flow we actually have:
 * the media modal uploads through async-upload.php as its own request, before
 * the material is saved and therefore before `_scholaris_access` is known —
 * on a new material the post does not exist yet. Deciding at upload time
 * means guessing.
 *
 * So placement is reconciled when the material is saved, against the only
 * thing that actually varies: `_scholaris_access`. Two consequences worth
 * having — flipping a material from members to public (or back) moves its
 * files to match, and the migration for existing content is not a separate
 * script but the same function over every material, which is what makes it
 * idempotent and re-runnable rather than a one-shot.
 *
 * @package ScholarisLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Private storage and the range-capable streamer.
 */
class SL_Private {

	/** Directory under uploads/ that Apache is told to refuse. */
	const DIR = 'scholaris-private';

	/** Read size. Large enough not to syscall per kilobyte, small enough not to buffer a lecture. */
	const CHUNK = 262144;

	public static function init(): void {
		// After SL_Meta::save() at 10, so the access level and attachment ids
		// are already written when placement is decided.
		add_action( 'save_post_study_material', array( __CLASS__, 'reconcile' ), 20 );

		// save_post is not enough, and assuming it was shipped a hole.
		//
		// Anything that creates a material programmatically — wp-cli, an
		// importer, the download-gate fixtures, a future REST route — inserts
		// the post FIRST and sets `_scholaris_access` and the attachment ids
		// AFTER. At save_post time there is no file to place, and no later
		// save ever comes, so the file stays public while the label says
		// members. That is not a missing call site to add; it is the wrong
		// trigger. Placement depends on exactly three values, so watch those
		// three values instead of guessing which code path writes them.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_meta_change' ), 20, 3 );
		}

		// Close the upload window.
		//
		// The media modal uploads through async-upload.php as its own request
		// and the file lands in the public tree; placement then waits for the
		// meta write. Measured: between those two moments the file answers 200
		// at a real URL — and if the editor never saves, or abandons the draft,
		// "until the post is saved" means forever.
		//
		// It is closeable because the modal passes post_id, so the attachment
		// knows its material the instant it exists (post_parent, verified).
		// Note this cannot go through reconcile(): at this point the material
		// does not reference the attachment yet — _scholaris_video_id is
		// written on save — so the material's own meta would say there is
		// nothing to move. The rule is about the attachment: a file uploaded
		// into restricted material is restricted from the moment it exists.
		add_action( 'add_attachment', array( __CLASS__, 'on_attachment_added' ) );

		add_action( 'template_redirect', array( __CLASS__, 'handle_stream' ) );
	}

	/**
	 * Place a freshly uploaded file immediately, by its parent's access level.
	 *
	 * @param int $attachment_id New attachment.
	 */
	public static function on_attachment_added( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		$parent        = (int) wp_get_post_parent_id( $attachment_id );

		if ( ! $parent || 'study_material' !== get_post_type( $parent ) ) {
			return;
		}

		if ( 'members' !== ( (string) get_post_meta( $parent, '_scholaris_access', true ) ?: 'members' ) ) {
			return;
		}

		$moved = self::relocate( $attachment_id, true );

		if ( is_wp_error( $moved ) && method_exists( 'SL_Meta', 'flag_public' ) ) {
			SL_Meta::flag_public( $parent, sprintf(
				/* translators: %s: reason the upload could not be secured. */
				__( 'An uploaded file could not be moved out of the public folder: %s. It is reachable by address until this is resolved.', 'scholaris-library' ),
				$moved->get_error_message()
			) );
		}
	}

	/**
	 * Reconcile when one of the three inputs to placement changes, whoever
	 * changed it and however.
	 *
	 * @param int    $meta_id  Unused.
	 * @param int    $post_id  Post the meta belongs to.
	 * @param string $meta_key Key that changed.
	 */
	public static function on_meta_change( $meta_id, $post_id, $meta_key ): void {
		static $busy = false;

		if ( $busy ) {
			return;
		}

		if ( ! in_array( $meta_key, array( '_scholaris_access', '_scholaris_file_id', '_scholaris_video_id' ), true ) ) {
			return;
		}

		if ( 'study_material' !== get_post_type( (int) $post_id ) ) {
			return;
		}

		$busy = true;
		self::reconcile( (int) $post_id );
		$busy = false;
	}

	/* ---------------------------------------------------------------- paths */

	public static function base_dir(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::DIR;
	}

	/**
	 * Is this absolute path inside the private tree?
	 *
	 * @param string $path Absolute path.
	 */
	public static function is_private( string $path ): bool {
		return $path && str_starts_with( wp_normalize_path( $path ), wp_normalize_path( self::base_dir() ) . '/' );
	}

	/**
	 * Is this material's media actually where its access level requires?
	 *
	 * The question the rest of the plugin should ask before it claims a file
	 * is protected. Cheap: two meta reads and a string comparison.
	 *
	 * @param int $material_id Material.
	 */
	public static function is_secured( int $material_id ): bool {
		if ( 'members' !== ( (string) get_post_meta( $material_id, '_scholaris_access', true ) ?: 'members' ) ) {
			return true; // Public material has nothing to secure.
		}

		foreach ( array( '_scholaris_file_id', '_scholaris_video_id' ) as $key ) {
			$attachment_id = (int) get_post_meta( $material_id, $key, true );

			if ( ! $attachment_id ) {
				continue;
			}

			$path = get_attached_file( $attachment_id );

			if ( $path && ! self::is_private( $path ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Last line: make sure a gated material's files are placed, and say so if
	 * they are not.
	 *
	 * Called from the serve paths. It cannot stop Apache answering a file that
	 * is already in the public tree — nothing in PHP can, which is why
	 * placement is guarded at every write above — but it does mean the moment
	 * anyone uses a legitimate route, a misplaced file is corrected rather
	 * than left for the next person to discover from outside.
	 *
	 * @param int $material_id Material.
	 * @return true|WP_Error
	 */
	public static function assert_secured( int $material_id ) {
		if ( self::is_secured( $material_id ) ) {
			return true;
		}

		self::reconcile( $material_id );

		if ( self::is_secured( $material_id ) ) {
			return true;
		}

		return new WP_Error(
			'sl_private_unsecured',
			__( 'This material is restricted but its file is not in protected storage.', 'scholaris-library' )
		);
	}

	/**
	 * Create the private directory and the files that make it private.
	 *
	 * Returns WP_Error rather than false so the caller can say *what* failed.
	 * A directory that exists but is not denied is worse than no directory:
	 * it looks organised and protects nothing.
	 *
	 * @return true|WP_Error
	 */
	public static function ensure_denied() {
		$dir = self::base_dir();

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'sl_private_mkdir', sprintf( 'Could not create %s', $dir ) );
		}

		$guards = array(
			// Require all denied is 2.4; deny from all is kept for 2.2 hosts,
			// and the two are harmless together.
			'.htaccess' => "# Written by Scholaris Library. Files here are served only through\n"
				. "# ?sl_stream= / ?sl_download=, which check the material's access level.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'index.php' => "<?php\n// Silence is golden.\n",
		);

		foreach ( $guards as $name => $contents ) {
			$file = trailingslashit( $dir ) . $name;

			if ( is_readable( $file ) && (string) file_get_contents( $file ) === $contents ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				continue;
			}

			if ( false === file_put_contents( $file, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return new WP_Error( 'sl_private_guard', sprintf( 'Could not write %s', $file ) );
			}
		}

		return true;
	}

	/* ------------------------------------------------------------- movement */

	/**
	 * Move one attachment into or out of the private tree.
	 *
	 * @param int  $attachment_id Attachment.
	 * @param bool $private       Target state.
	 * @return string|WP_Error|null New path, null when already correct.
	 */
	public static function relocate( int $attachment_id, bool $private ) {
		$path = get_attached_file( $attachment_id );

		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'sl_private_missing', sprintf( 'Attachment %d has no file on disk', $attachment_id ) );
		}

		if ( self::is_private( $path ) === $private ) {
			return null;
		}

		// An image's metadata carries relative paths for every generated size,
		// so moving one silently breaks its thumbnails. Gated material is
		// documents and video; refuse rather than corrupt.
		if ( wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error( 'sl_private_image', sprintf( 'Attachment %d is an image; moving it would break its generated sizes', $attachment_id ) );
		}

		if ( $private ) {
			$guard = self::ensure_denied();

			// Refuse the move rather than place the file somewhere that only
			// looks protected.
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}

			$target_dir = self::base_dir();
		} else {
			$uploads    = wp_upload_dir();
			$target_dir = $uploads['path'];

			if ( ! wp_mkdir_p( $target_dir ) ) {
				return new WP_Error( 'sl_private_mkdir', sprintf( 'Could not create %s', $target_dir ) );
			}
		}

		$target = trailingslashit( $target_dir ) . wp_unique_filename( $target_dir, basename( $path ) );

		if ( ! @rename( $path, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'sl_private_rename', sprintf( 'Could not move %s', basename( $path ) ) );
		}

		update_attached_file( $attachment_id, $target );

		// Keep the metadata's own copy of the path in step, where there is one.
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && isset( $meta['file'] ) ) {
			$uploads      = wp_upload_dir();
			$meta['file'] = ltrim( str_replace( wp_normalize_path( trailingslashit( $uploads['basedir'] ) ), '', wp_normalize_path( $target ) ), '/' );
			wp_update_attachment_metadata( $attachment_id, $meta );
		}

		return $target;
	}

	/**
	 * Put a material's files where its access level says they belong.
	 *
	 * @param int $material_id Material.
	 * @return array Report: moved, failed, unchanged.
	 */
	public static function reconcile( $material_id ): array {
		$material_id = (int) $material_id;
		$report      = array( 'moved' => array(), 'failed' => array(), 'unchanged' => array() );

		if ( 'study_material' !== get_post_type( $material_id ) ) {
			return $report;
		}

		$private = 'members' === ( (string) get_post_meta( $material_id, '_scholaris_access', true ) ?: 'members' );

		foreach ( array( '_scholaris_file_id', '_scholaris_video_id' ) as $key ) {
			$attachment_id = (int) get_post_meta( $material_id, $key, true );

			if ( ! $attachment_id ) {
				continue;
			}

			$result = self::relocate( $attachment_id, $private );

			if ( is_wp_error( $result ) ) {
				$report['failed'][ $attachment_id ] = $result->get_error_message();

				// The editor must hear this: the material is saved and its
				// file is not where the label claims.
				if ( method_exists( 'SL_Meta', 'flag_public' ) ) {
					SL_Meta::flag_public( $material_id, sprintf(
						/* translators: %s: reason the file could not be secured. */
						__( 'This material is set to signed-in students, but its file could not be moved out of the public folder: %s. The file is still reachable by address.', 'scholaris-library' ),
						$result->get_error_message()
					) );
				}
				continue;
			}

			if ( null === $result ) {
				$report['unchanged'][] = $attachment_id;
				continue;
			}

			$report['moved'][ $attachment_id ] = $result;
		}

		return $report;
	}

	/**
	 * Reconcile every material. This is the migration — deliberately the same
	 * code path as a save, so it is idempotent and can be re-run on any clone
	 * or after the owner uploads.
	 *
	 * Keys on `_scholaris_access`, never on "all attachments": a public
	 * material's file must stay public, and download-gate.sh's control
	 * assertion depends on exactly that.
	 *
	 * @return array Totals plus per-material detail.
	 */
	public static function migrate(): array {
		$materials = get_posts( array(
			'post_type'     => 'study_material',
			'post_status'   => 'any',
			'numberposts'   => -1,
			'fields'        => 'ids',
			'no_found_rows' => true,
		) );

		$out = array( 'materials' => count( $materials ), 'moved' => 0, 'failed' => 0, 'unchanged' => 0, 'detail' => array() );

		foreach ( $materials as $material_id ) {
			$report = self::reconcile( $material_id );

			$out['moved']     += count( $report['moved'] );
			$out['failed']    += count( $report['failed'] );
			$out['unchanged'] += count( $report['unchanged'] );

			if ( $report['moved'] || $report['failed'] ) {
				$out['detail'][ $material_id ] = $report;
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------ streaming */

	/**
	 * Nonced URL for streaming a material's media.
	 *
	 * READS `_scholaris_video_id` FIRST, falling back to
	 * `_scholaris_file_id`. This is the video route.
	 *
	 * Not interchangeable with SL_Library::download_url(), which reads
	 * `_scholaris_file_id` only — point a <video> at that one and a material
	 * carrying both a document and a video feeds the PDF to the player.
	 * Unplayable, on exactly the materials most likely to exist, and
	 * invisible in review. The two are one character apart at the call site,
	 * hence this note.
	 *
	 * Also note the URL has no file extension, deliberately: it is a query
	 * route, not a path. wp_video_shortcode() sniffs an extension and will
	 * not emit a player for it — use a plain <video> element. Giving the
	 * stream a file-shaped path to satisfy the shortcode would mean a second
	 * URL form reaching the same gated bytes, which is the thing this class
	 * exists to avoid.
	 *
	 * @param int $material_id Material.
	 */
	public static function stream_url( int $material_id ): string {
		return add_query_arg(
			array(
				'sl_stream' => $material_id,
				'_wpnonce'  => wp_create_nonce( 'sl_stream_' . $material_id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Serve a gated file, honouring Range.
	 *
	 * Explicit range handling is the feature, not portability polish.
	 * Measured on this stack: Apache's byterange filter only slices responses
	 * it has fully buffered, so a 1 KB file returns 206 and a 2 MB file
	 * returns 200 with the whole body from byte zero — the seek is ignored,
	 * not merely unadvertised. A player asking for minute forty of a lecture
	 * gets the beginning, again. Anything tested below ~64 KB will "prove"
	 * ranges work; test at 1 MB or more.
	 */
	public static function handle_stream(): void {
		if ( empty( $_GET['sl_stream'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$material_id = absint( wp_unslash( $_GET['sl_stream'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce       = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'sl_stream_' . $material_id ) ) {
			wp_die( esc_html__( 'That link has expired. Reload the page and try again.', 'scholaris-library' ), '', array( 'response' => 403 ) );
		}

		if ( 'study_material' !== get_post_type( $material_id ) || 'publish' !== get_post_status( $material_id ) ) {
			wp_die( esc_html__( 'Not found.', 'scholaris-library' ), '', array( 'response' => 404 ) );
		}

		// The same gate as the download handler. One access rule, two ways out.
		if ( ! SL_Meta::can_download( $material_id ) ) {
			wp_die( esc_html__( 'This material is available to signed-in students.', 'scholaris-library' ), '', array( 'response' => 403 ) );
		}

		// Fail closed: if this material claims to be restricted and its file
		// is sitting in the public tree, serving it here would be the one
		// route that quietly worked while the file was reachable anyway.
		$secured = self::assert_secured( $material_id );

		if ( is_wp_error( $secured ) ) {
			wp_die( esc_html( $secured->get_error_message() ), '', array( 'response' => 500 ) );
		}

		$attachment_id = (int) get_post_meta( $material_id, '_scholaris_video_id', true );

		if ( ! $attachment_id ) {
			$attachment_id = (int) get_post_meta( $material_id, '_scholaris_file_id', true );
		}

		$path = $attachment_id ? get_attached_file( $attachment_id ) : '';

		if ( ! $path || ! file_exists( $path ) ) {
			wp_die( esc_html__( 'The file is missing from the media library.', 'scholaris-library' ), '', array( 'response' => 404 ) );
		}

		self::send( $path );
	}

	/**
	 * Write the file to the client, with 206 when a range was asked for.
	 *
	 * @param string $path Absolute path.
	 */
	private static function send( string $path ): void {
		$size = (int) filesize( $path );
		$type = wp_check_filetype( $path );
		$type = $type['type'] ?: 'application/octet-stream';

		$start = 0;
		$end   = $size - 1;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$range   = isset( $_SERVER['HTTP_RANGE'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		$partial = false;

		if ( $range && preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $m ) ) {
			$has_start = '' !== $m[1];
			$has_end   = '' !== $m[2];

			if ( ! $has_start && $has_end ) {
				// "bytes=-500" — the last 500 bytes.
				$start = max( 0, $size - (int) $m[2] );
			} else {
				$start = (int) $m[1];
				if ( $has_end ) {
					$end = min( (int) $m[2], $size - 1 );
				}
			}

			if ( $start > $end || $start >= $size ) {
				header( 'Content-Range: bytes */' . $size );
				wp_die( '', '', array( 'response' => 416 ) );
			}

			$partial = true;
		}

		$length = $end - $start + 1;

		// Anything already buffered would be prepended to the bytes, and on a
		// range request that corrupts the offset the player asked for.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: inline; filename="' . basename( $path ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . $length );

		if ( $partial ) {
			status_header( 206 );
			header( sprintf( 'Content-Range: bytes %d-%d/%d', $start, $end, $size ) );
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $handle ) {
			wp_die( esc_html__( 'The file could not be opened.', 'scholaris-library' ), '', array( 'response' => 500 ) );
		}

		fseek( $handle, $start );

		$remaining = $length;

		while ( $remaining > 0 && ! feof( $handle ) ) {
			$buffer = fread( $handle, (int) min( self::CHUNK, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( false === $buffer ) {
				break;
			}

			echo $buffer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file body.
			flush();

			$remaining -= strlen( $buffer );

			// A student who closes the tab mid-lecture should not leave a
			// worker reading to the end of a 64 MB file.
			if ( connection_aborted() ) {
				break;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
