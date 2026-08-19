<?php
/**
 * Lecture video in, text out, so the assistant can read a recording.
 *
 * The owner's ask: Summarise, PrepareME and Q&A must work on video lessons.
 * None of those three are touched to achieve it — they all read the knowledge
 * index, so a transcript added to `EduAI_Knowledge::index_post()` reaches every
 * one of them at once. This class only produces the text.
 *
 * GROQ `whisper-large-v3-turbo`, on the key the chat already uses. No new
 * signup and no new secret.
 *
 * AUDIO IS EXTRACTED FIRST where ffmpeg is available. This said the
 * opposite for most of a day — that Groq takes an mp4 directly, so no
 * extraction was needed — and it was true and useless: the owner's
 * five-minute lecture is 26.7 MB of video against a 25 MB ceiling, and
 * 1.3 MB as mono 16 kHz audio. Sending the video spends the whole size
 * budget on the track the model throws away.
 *
 * Without ffmpeg the original file is sent and the size check still
 * refuses what genuinely will not fit, so the feature degrades rather
 * than breaking.
 *
 * TRANSCRIBED ONCE, NEVER ON A PAGE REQUEST. `fetch()` returns the cached
 * transcript or nothing; `schedule()` puts the actual call on cron. A lecture
 * is minutes of audio against a network call, so doing it inline would hang
 * whoever happened to save the post.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Speech-to-text for attached video.
 */
class EduAI_Transcript {

	const META       = '_eduai_transcript';
	const META_STATE = '_eduai_transcript_state';
	const HOOK       = 'eduai_transcribe_attachment';
	const ENDPOINT   = 'https://api.groq.com/openai/v1/audio/transcriptions';
	const MODEL      = 'whisper-large-v3-turbo';
	const META_HEARD    = '_eduai_transcript_heard';
	const META_ACTUAL   = '_eduai_transcript_actual';
	const META_LANGUAGE = '_eduai_transcript_language';
	const META_SEGMENTS = '_eduai_transcript_segments';

	/**
	 * Free-tier ceiling, in bytes.
	 *
	 * 25 MB is Groq's documented limit and six of the seven clips on this
	 * install are under it. Refusing above it with a message that NAMES the
	 * limit is deliberate: the alternative is a request failing somewhere
	 * inside the provider and reporting something less useful. Splitting the
	 * audio out of a large mp4 needs ffmpeg, which is a change to the
	 * container rather than to this file.
	 */
	const MAX_BYTES = 26214400;

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 2 );

		/*
		 * Queue when a video is attached to a material.
		 *
		 * On the meta VALUE rather than on the save path, for the reason the
		 * file placement learned the hard way: a material gets its video from
		 * the editor, from an import, from the segmenter and from WP-CLI, and
		 * hooking each of those is a list that goes stale. The id changing is
		 * the one event common to all of them.
		 */
		foreach ( array( 'added_post_meta', 'updated_post_meta' ) as $eduai_meta_hook ) {
			add_action( $eduai_meta_hook, array( __CLASS__, 'on_meta_change' ), 10, 4 );
		}
	}

	/**
	 * @param int    $meta_id    Ignored.
	 * @param int    $post_id    Material the video belongs to.
	 * @param string $meta_key   Key that changed.
	 * @param mixed  $meta_value New value.
	 */
	public static function on_meta_change( $meta_id, $post_id, $meta_key, $meta_value ): void {
		// LearnDash writes the whole settings array under one key, so the
		// lesson video URL arrives as a change to `_sfwd-lessons` rather than
		// to a field of its own.
		if ( '_sfwd-lessons' === (string) $meta_key ) {
			self::schedule_lesson( (int) $post_id );

			return;
		}

		if ( '_scholaris_video_id' !== (string) $meta_key ) {
			return;
		}

		$attachment_id = (int) $meta_value;

		if ( ! $attachment_id ) {
			return;
		}

		self::schedule( $attachment_id, (int) $post_id );
	}

	/**
	 * The stored transcript for an attachment, or ''.
	 *
	 * Never transcribes. A caller on a page request gets what exists.
	 *
	 * @param int $attachment_id Attachment.
	 */
	public static function fetch( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META, true );
	}

	/**
	 * What happened last time, for the editor and for the tester.
	 *
	 * @param int $attachment_id Attachment.
	 */
	public static function state( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META_STATE, true );
	}

	/**
	 * Is this attachment something we would try to transcribe?
	 *
	 * @param int $attachment_id Attachment.
	 */
	public static function is_video( int $attachment_id ): bool {
		$type = (string) get_post_mime_type( $attachment_id );

		return (bool) preg_match( '#^(video|audio)/#', $type );
	}

	/**
	 * Queue a transcription, unless one is already stored.
	 *
	 * @param int  $attachment_id Attachment.
	 * @param int  $post_id       Post the transcript is for, used for the prompt.
	 * @param bool $force         Re-transcribe even if one exists.
	 */
	public static function schedule( int $attachment_id, int $post_id = 0, bool $force = false ): void {
		if ( ! $attachment_id || ! self::is_video( $attachment_id ) ) {
			return;
		}

		if ( ! $force && '' !== self::fetch( $attachment_id ) ) {
			return;
		}

		/*
		 * Already queued — but only if it actually still is.
		 *
		 * The flag covers the window where cron has picked the job up and not
		 * yet finished, which wp_schedule_single_event's own dedupe cannot see.
		 * On its own though it is a latch with no release: if the event is ever
		 * lost — cron disabled, a crash mid-run, a probe that scheduled and
		 * exited — the attachment reads `queued` for ever and NOTHING will
		 * schedule it again without $force. Found exactly that way: an
		 * attachment stuck from an earlier run silently refused every later
		 * attempt, and the refusal looked identical to correct de-duplication.
		 *
		 * So the flag defers to the queue. No pending event means the claim is
		 * stale, whatever the meta says.
		 */
		if ( ! $force && 'queued' === self::state( $attachment_id )
			&& wp_next_scheduled( self::HOOK, array( $attachment_id, $post_id ) ) ) {
			return;
		}

		update_post_meta( $attachment_id, self::META_STATE, 'queued' );

		wp_schedule_single_event( time() + 5, self::HOOK, array( $attachment_id, $post_id ) );
	}

	/**
	 * Cron entry point.
	 *
	 * @param int $attachment_id Attachment.
	 * @param int $post_id       Post it belongs to.
	 */
	public static function run( $attachment_id, $post_id = 0 ): void {
		$attachment_id = (int) $attachment_id;
		$meta          = array();
		$text          = self::transcribe( $attachment_id, (int) $post_id, $meta );

		if ( is_wp_error( $text ) ) {
			update_post_meta( $attachment_id, self::META_STATE, 'error: ' . $text->get_error_message() );
			return;
		}

		/*
		 * TWO DURATIONS, PASSED SEPARATELY, because they answer different
		 * questions and summing them into one number destroys the only check
		 * that can catch the failure this feature will actually have.
		 *
		 *   heard  — Groq's `duration`: how much audio Whisper processed
		 *   actual — the file's own duration: how long the recording IS
		 *
		 * The rate question — is this speech or is it noise? — wants `heard`,
		 * because it is the duration of what was decoded. Fourteen words in
		 * six seconds is 137 words a minute, an ordinary speaking rate, and an
		 * absolute word floor calls that "almost no words" and refuses a
		 * perfectly good thirty-second intro clip.
		 *
		 * The completeness question — did we get the WHOLE lecture? — cannot
		 * use `heard` at all. If Whisper stops at minute four of fifty it
		 * reports a four-minute duration, so words-per-second stays healthy:
		 * both halves of the fraction shrink together and the check passes on
		 * precisely the case it exists to catch. Only `actual` disagreeing
		 * with `heard` reveals it, and it reveals how much is missing rather
		 * than merely that something is.
		 *
		 * That is a contiguity check rather than a volume one — the same shape
		 * as chunk indices running 0..n-1, and the reason a coverage
		 * percentage could once read 112% on a half-indexed document.
		 */
		$heard  = isset( $meta['duration'] ) ? (float) $meta['duration'] : null;
		$actual = self::file_duration( $attachment_id );

		if ( class_exists( 'EduAI_Transcript_Guard' ) && method_exists( 'EduAI_Transcript_Guard', 'usable' ) ) {
			$verdict = EduAI_Transcript_Guard::usable( $text, $heard, $actual );

			if ( is_wp_error( $verdict ) ) {
				update_post_meta( $attachment_id, self::META_STATE, 'rejected: ' . $verdict->get_error_message() );
				return;
			}
		}

		update_post_meta( $attachment_id, self::META, $text );
		update_post_meta( $attachment_id, self::META_STATE, 'ok' );

		// Kept for the guard, the editor and anyone diagnosing a short
		// transcript later — the numbers that produced the verdict, not just
		// the verdict.
		update_post_meta( $attachment_id, self::META_HEARD, $heard );
		update_post_meta( $attachment_id, self::META_ACTUAL, $actual );

		if ( ! empty( $meta['segments'] ) ) {
			update_post_meta( $attachment_id, self::META_SEGMENTS, wp_json_encode( $meta['segments'] ) );
		}

		if ( '' !== ( $meta['language'] ?? '' ) ) {
			update_post_meta( $attachment_id, self::META_LANGUAGE, (string) $meta['language'] );
		}

		// The transcript only reaches a student through the index, so writing
		// it without reindexing would store a correct answer nobody can read.
		if ( $post_id && class_exists( 'EduAI_Knowledge' ) ) {
			EduAI_Knowledge::index_post( (int) $post_id );
		}
	}

	/**
	 * How long the recording actually is, from the file rather than the model.
	 *
	 * Null when it cannot be determined — a synthetic fixture, an exotic
	 * container — and null must mean "no claim", never "zero". The same rule
	 * as a page range on a deck whose blocks do not match its page count: a
	 * completeness check that cannot measure says nothing rather than
	 * guessing.
	 *
	 * @param int $attachment_id Attachment.
	 * @return int|null Seconds.
	 */
	public static function file_duration( int $attachment_id ): ?int {
		$path = get_attached_file( $attachment_id );

		if ( ! $path || ! file_exists( $path ) ) {
			return null;
		}

		// Cheapest first: WordPress already stored it at upload time for a
		// media file it understood.
		$attached = wp_get_attachment_metadata( $attachment_id );

		if ( isset( $attached['length'] ) && (int) $attached['length'] > 0 ) {
			return (int) $attached['length'];
		}

		if ( ! function_exists( 'wp_read_video_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		if ( ! function_exists( 'wp_read_video_metadata' ) ) {
			return null;
		}

		$probe = wp_read_video_metadata( $path );

		if ( is_array( $probe ) && isset( $probe['length'] ) && (int) $probe['length'] > 0 ) {
			return (int) $probe['length'];
		}

		return null;
	}

	/**
	 * The video URL LearnDash keeps on a lesson.
	 *
	 * The owner's content model, and the one nothing here could read: his
	 * lessons have an EMPTY post_content and a YouTube URL in LearnDash's own
	 * settings array. Measuring the body reported both his live lessons as
	 * empty shells; they are full, and we were looking in the wrong place.
	 *
	 * Through `learndash_get_setting()` where it exists, because the meta is a
	 * serialised array whose key names are LearnDash's to change, and falling
	 * back to reading it directly only when the helper is absent.
	 *
	 * @param int $post_id Lesson.
	 */
	public static function lesson_video_url( int $post_id ): string {
		if ( function_exists( 'learndash_get_setting' ) ) {
			$url = (string) learndash_get_setting( $post_id, 'lesson_video_url' );

			if ( '' !== trim( $url ) ) {
				return trim( $url );
			}
		}

		$settings = get_post_meta( $post_id, '_sfwd-lessons', true );

		if ( is_array( $settings ) && ! empty( $settings['sfwd-lessons_lesson_video_url'] ) ) {
			return trim( (string) $settings['sfwd-lessons_lesson_video_url'] );
		}

		return '';
	}

	/**
	 * The attachment a lesson's video URL points at, when it is one of ours.
	 *
	 * THIS IS THE INLET THE PRODUCT WAS MISSING. The machinery has worked all
	 * day and had nowhere to be fed from: the meta box was bound to a post
	 * type that no longer exists, and the field the owner actually uses takes
	 * a URL rather than a file. Resolving that URL means he uploads a lecture
	 * to the media library, pastes its address into the LearnDash video field
	 * he is already using, and the existing Whisper path takes it from there —
	 * no new screen, no new concept.
	 *
	 * `attachment_url_to_postid()` handles the exact-URL case; the basename
	 * fallback covers a file that has since been MOVED, which on this install
	 * is routine — `SL_Private` relocates gated media into a denied directory
	 * and the URL a lecturer copied last week no longer matches the stored
	 * path. Same lesson as resolving a material by its meta rather than its
	 * parent: match on what identifies the thing, not on where it used to be.
	 *
	 * @param int $post_id Lesson.
	 */
	public static function lesson_video_attachment( int $post_id ): int {
		$url = self::lesson_video_url( $post_id );

		if ( '' === $url ) {
			return 0;
		}

		$attachment_id = (int) attachment_url_to_postid( $url );

		if ( $attachment_id ) {
			return $attachment_id;
		}

		// Only look locally: a remote host's basename colliding with one of
		// ours would otherwise transcribe the wrong file entirely.
		if ( ! self::is_local_url( $url ) ) {
			return 0;
		}
		/*
		 * A GATED attachment's URL is not a file URL.
		 *
		 * SL_Private hands back `?sl_stream=<material>&sl_att=<attachment>
		 * &_wpnonce=…` — a handler route, not media. attachment_url_to_postid()
		 * returns 0 for it and the basename fallback has nothing to match,
		 * because the path is `/`. So a lecturer who copies the URL from a
		 * gated recording lands on the remote branch, and yt-dlp is handed a
		 * webpage.
		 *
		 * Worse, it would fail even without the gate: `localhost:8080` is the
		 * HOST's port, and from inside the container that is a connection
		 * refused. Making an HTTP round trip to fetch a file this same process
		 * can open from disk is the bug underneath both symptoms — so the id
		 * is read straight out of the query string and the round trip never
		 * happens.
		 *
		 * The nonce is deliberately not checked: it is per-user and this runs
		 * on cron, so validating it would refuse every legitimate case. It is
		 * not a security shortcut either — nothing here is served to anyone,
		 * the id is verified to be an attachment, and access is decided by
		 * may_read() when the indexed text is retrieved.
		 */
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		if ( '' !== $query ) {
			$args = array();
			wp_parse_str( $query, $args );

			$named = isset( $args['sl_att'] ) ? (int) $args['sl_att'] : 0;

			if ( $named && 'attachment' === get_post_type( $named ) ) {
				return $named;
			}
		}


		global $wpdb;

		$basename = basename( wp_parse_url( $url, PHP_URL_PATH ) ?: '' );

		if ( '' === $basename ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s
				 ORDER BY post_id DESC LIMIT 1",
				'%' . $wpdb->esc_like( $basename )
			)
		);

		return $found;
	}

	/**
	 * Is this URL served by this site?
	 *
	 * @param string $url Candidate.
	 */
	public static function is_local_url( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host ) {
			// A relative URL is ours by definition.
			return true;
		}

		return strtolower( $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Queue whatever this lesson's video needs, and say why if it needs
	 * something we cannot do.
	 *
	 * @param int  $post_id Lesson.
	 * @param bool $force   Re-transcribe even if one exists.
	 * @return true|WP_Error
	 */
	public static function schedule_lesson( int $post_id, bool $force = false ) {
		$url = self::lesson_video_url( $post_id );

		if ( '' === $url ) {
			return new WP_Error( 'eduai_transcript_no_url', __( 'This lesson has no video on it.', 'eduai' ) );
		}

		$attachment_id = self::lesson_video_attachment( $post_id );

		if ( $attachment_id ) {
			self::schedule( $attachment_id, $post_id, $force );

			return true;
		}

		/*
		 * A remote video, and every route to one is closed from this server:
		 * captions return 200 with an empty body without a proof-of-origin
		 * token, and yt-dlp is refused 403 five different ways from a
		 * datacentre address. Measured against his own lesson video, not
		 * assumed.
		 *
		 * So this says what he can DO rather than reporting a failure. He has
		 * spent a day discovering the silence; a sentence naming the one route
		 * that works is worth more than another empty result.
		 */
		update_post_meta(
			$post_id,
			self::META_STATE,
			'rejected: ' . __( 'Videos hosted on YouTube cannot be read by the study tools. Upload the recording to the media library and paste its URL into this field instead.', 'eduai' )
		);

		return new WP_Error(
			'eduai_transcript_remote',
			__( 'Videos hosted on YouTube cannot be read by the study tools. Upload the recording to the media library and paste its URL into this field instead.', 'eduai' )
		);
	}

	/**
	 * Can this install pull audio from a video URL at all?
	 *
	 * A capability check rather than an assumption, because the answer is a
	 * property of the CONTAINER and not of this code. Shelling out to a binary
	 * that may not be there is a fatal on a lesson page; asking first turns it
	 * into a refusal that names what is missing.
	 */
	public static function can_fetch_remote(): bool {
		return '' !== self::binary( 'yt-dlp' );
	}

	/**
	 * Absolute path to a binary, or '' when it is not installed.
	 *
	 * @param string $name Binary name.
	 */
	private static function binary( string $name ): string {
		if ( ! function_exists( 'shell_exec' ) ) {
			return '';
		}

		$found = shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' );

		return is_string( $found ) ? trim( $found ) : '';
	}

	/**
	 * Pull the audio from a video URL and transcribe it.
	 *
	 * Audio only, never the video: a fifty-minute lecture is hundreds of
	 * megabytes as mp4 and a few as m4a, and Groq's ceiling is 25 MB. The
	 * extraction is also the reason this can never run on a page request —
	 * it is a download plus a transcode plus a network call.
	 *
	 * @param int   $post_id Lesson.
	 * @param array $meta    Filled with duration, language and segments.
	 * @return string|WP_Error
	 */
	public static function transcribe_url( int $post_id, array &$meta = array() ) {
		$meta = array( 'duration' => null, 'language' => '', 'segments' => array() );
		$url  = self::lesson_video_url( $post_id );

		if ( '' === $url ) {
			return new WP_Error( 'eduai_transcript_no_url', __( 'This lesson has no video URL on it.', 'eduai' ) );
		}

		$binary = self::binary( 'yt-dlp' );

		if ( '' === $binary ) {
			return new WP_Error(
				'eduai_transcript_no_tool',
				__( 'Video URLs cannot be processed on this server: yt-dlp is not installed. An uploaded file still works.', 'eduai' )
			);
		}

		$dir = self::scratch_dir( 'eduai-dl-' );

		if ( '' === $dir ) {
			return new WP_Error( 'eduai_transcript_tmp', __( 'A temporary directory for the audio could not be created.', 'eduai' ) );
		}

		/*
		 * `--max-filesize` is a guard on the DOWNLOAD, not a substitute for
		 * the check after it: yt-dlp applies it to the media it fetches, and
		 * the extracted audio is a different file. Both are needed — this one
		 * stops a two-hour recording being pulled at all, the other stops an
		 * oversize payload reaching Groq.
		 */
		$command = sprintf(
			'%s --no-warnings --no-playlist --extract-audio --audio-format m4a --audio-quality 5 ' .
			'--max-filesize 400M --output %s %s 2>&1',
			escapeshellcmd( $binary ),
			escapeshellarg( $dir . '/audio.%(ext)s' ),
			escapeshellarg( $url )
		);

		$output = shell_exec( $command );
		$files  = glob( $dir . '/audio.*' );
		$audio  = $files ? $files[0] : '';

		if ( ! $audio || ! file_exists( $audio ) ) {
			self::rmdir_r( $dir );

			/*
			 * Private, region-locked, removed, or simply refused — all of them
			 * arrive here, and the message names the video rather than
			 * reporting a generic failure, because the owner can only act on
			 * the first kind of message.
			 */
			return new WP_Error(
				'eduai_transcript_fetch',
				sprintf(
					/* translators: 1: video URL 2: tool output */
					__( 'The audio for %1$s could not be downloaded from this server. YouTube refuses media downloads from datacentre addresses even when the video is public, and it may also be private, region-locked or removed. Upload the recording as a file instead. The tool said: %2$s', 'eduai' ),
					$url,
					trim( mb_substr( (string) $output, -300 ) )
				)
			);
		}

		$size = (int) filesize( $audio );

		if ( $size > self::MAX_BYTES ) {
			self::rmdir_r( $dir );

			return new WP_Error(
				'eduai_transcript_big',
				sprintf(
					/* translators: 1: audio size 2: limit */
					__( 'The audio for this lesson is %1$s, over the %2$s transcription limit. A shorter recording, or one split into parts, will work.', 'eduai' ),
					size_format( $size ),
					size_format( self::MAX_BYTES )
				)
			);
		}

		$text = self::transcribe_path( $audio, self::prompt_for( 0, $post_id ), $meta );

		// The audio is a means, not an artefact. Keeping it would put a copy
		// of somebody else's video in the uploads directory.
		self::rmdir_r( $dir );

		return $text;
	}

	/**
	 * Strip a media file down to the audio Whisper actually needs.
	 *
	 * THE VIDEO TRACK IS THE PART WE THROW AWAY, and it was the part being
	 * measured. The owner uploaded a five-minute lecture and the pipeline
	 * refused it: 26.7 MB against a 25 MB ceiling. The same file as mono
	 * 16 kHz audio is 1.3 MB — twenty times smaller — and transcribes
	 * perfectly. Refusing a five-minute recording because its *picture* is
	 * large is a true measurement of the wrong thing.
	 *
	 * 16 kHz mono because that is what Whisper resamples to anyway: sending
	 * anything richer spends the size budget on detail the model discards.
	 *
	 * Returns '' when there is nothing to do — no ffmpeg, or the extraction
	 * failed — and the caller keeps the original file. Falling back rather
	 * than failing matters because the size check after this is still the
	 * honest refusal for a genuinely enormous recording; this step only
	 * removes a reason to refuse that was never about the audio.
	 *
	 * @param string $path Absolute path to the source media.
	 * @return string Absolute path to extracted audio, or ''.
	 */
	private static function extract_audio( string $path ): string {
		$ffmpeg = self::binary( 'ffmpeg' );

		if ( '' === $ffmpeg || ! file_exists( $path ) ) {
			return '';
		}

		$dir = self::scratch_dir( 'eduai-audio-' );

		if ( '' === $dir ) {
			return '';
		}

		$out = $dir . '/audio.mp3';

		$command = sprintf(
			'%s -loglevel error -y -i %s -vn -ac 1 -ar 16000 %s 2>&1',
			escapeshellcmd( $ffmpeg ),
			escapeshellarg( $path ),
			escapeshellarg( $out )
		);

		shell_exec( $command );

		if ( ! file_exists( $out ) || filesize( $out ) < 1 ) {
			self::rmdir_r( $dir );

			return '';
		}

		return $out;
	}

	/**
	 * A scratch directory inside the uploads tree.
	 *
	 * Not get_temp_dir(), which is /tmp — outside the document root. WordPress
	 * logged "Path is outside resolved document root" for every extraction,
	 * and on a host with open_basedir set that is not a notice, it is a
	 * refusal. Uploads is writable by definition on any install that can
	 * accept the video in the first place.
	 *
	 * @param string $prefix Directory name prefix.
	 * @return string Absolute path, or '' when it could not be created.
	 */
	private static function scratch_dir( string $prefix ): string {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$dir = trailingslashit( $uploads['basedir'] ) . $prefix . wp_generate_password( 12, false );

		return wp_mkdir_p( $dir ) ? $dir : '';
	}

	/**
	 * Remove a temporary directory and its contents.
	 *
	 * @param string $dir Directory.
	 */
	private static function rmdir_r( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.directory_rmdir
		@rmdir( $dir );
	}

	/**
	 * Post the file to Groq and return the text.
	 *
	 * @param int $attachment_id Attachment.
	 * @param int $post_id       Post it belongs to, for the decoding prompt.
	 * @return string|WP_Error
	 */
	public static function transcribe( int $attachment_id, int $post_id = 0, array &$meta = array() ) {
		$meta = array( 'duration' => null, 'language' => '', 'segments' => array() );

		$key = class_exists( 'EduAI_Settings' ) ? EduAI_Settings::api_key( 'groq' ) : '';

		if ( '' === $key ) {
			return new WP_Error( 'eduai_transcript_key', __( 'No Groq API key is configured, so video cannot be transcribed.', 'eduai' ) );
		}

		$path = get_attached_file( $attachment_id );

		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'eduai_transcript_missing', __( 'The video file is missing from the media library.', 'eduai' ) );
		}

		/*
		 * Audio first, THEN the size check.
		 *
		 * The URL path has always extracted audio — yt-dlp is invoked with
		 * --extract-audio — and this path never learned to, so an uploaded
		 * lecture was weighed as video and refused. Same split-brain shape as
		 * two assemblers: one route taught something the other was not.
		 *
		 * The check stays after it, because a two-hour recording still exceeds
		 * the cap as audio and that refusal is honest.
		 */
		$audio = self::extract_audio( $path );
		$upload = '' !== $audio ? $audio : $path;
		$size   = (int) filesize( $upload );

		if ( $size > self::MAX_BYTES ) {
			if ( '' !== $audio ) {
				self::rmdir_r( dirname( $audio ) );
			}

			return new WP_Error(
				'eduai_transcript_big',
				sprintf(
					/* translators: 1: file size 2: limit */
					__( 'This recording is %1$s, over the %2$s transcription limit. Upload a shorter clip, or the audio track on its own.', 'eduai' ),
					size_format( $size ),
					size_format( self::MAX_BYTES )
				)
			);
		}

		$text = self::transcribe_path( $upload, self::prompt_for( $attachment_id, $post_id ), $meta );

		// The extracted audio is a means, not an artefact.
		if ( '' !== $audio ) {
			self::rmdir_r( dirname( $audio ) );
		}

		return $text;
	}

	/**
	 * Transcribe a file already on disk, whatever put it there.
	 *
	 * Split out so the uploaded-attachment path and the LearnDash video-URL
	 * path share ONE response handler. A second copy would be a second place
	 * for the 200-carrying-an-error-body case to be forgotten, and that is the
	 * case that indexes a failure as a lecture.
	 *
	 *  string $path   Absolute path to audio or video.
	 *  string $prompt Decoding hint.
	 *  array  $meta   Filled with duration, language and segments.
	 *  string|WP_Error
	 */
	private static function transcribe_path( string $path, string $prompt, array &$meta ) {
		$meta = array( 'duration' => null, 'language' => '', 'segments' => array() );

		$key = class_exists( 'EduAI_Settings' ) ? EduAI_Settings::api_key( 'groq' ) : '';

		if ( '' === $key ) {
			return new WP_Error( 'eduai_transcript_key', __( 'No Groq API key is configured, so video cannot be transcribed.', 'eduai' ) );
		}

		$response = self::post_file( $path, $key, $prompt );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		/*
		 * A file Groq cannot decode comes back as a 200-SHAPED JSON error
		 * rather than an HTTP error, so checking the status code alone reads a
		 * failure as a success and stores its message as the lecture.
		 * Measured on attachment 112, which returns exactly this.
		 */
		if ( isset( $decoded['error']['message'] ) ) {
			return new WP_Error( 'eduai_transcript_api', (string) $decoded['error']['message'] );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code > 299 ) {
			return new WP_Error(
				'eduai_transcript_http',
				sprintf(
					/* translators: %d: HTTP status */
					__( 'The transcription service returned status %d.', 'eduai' ),
					$code
				)
			);
		}

		$meta['duration'] = isset( $decoded['duration'] ) ? (float) $decoded['duration'] : null;
		$meta['language'] = isset( $decoded['language'] ) ? (string) $decoded['language'] : '';

		/*
		 * Segment timings, kept because a RATE cannot see a HOLE.
		 *
		 * Twenty-five minutes of speech spread across a fifty-minute recording
		 * reads about sixty words a minute and passes every check we have — the
		 * words are all there, they are just not everywhere. The gaps between
		 * consecutive segments ARE the missing stretches, which makes this the
		 * contiguity check for audio, the analogue of chunk indices running
		 * 0..n-1.
		 *
		 * Stored now rather than when someone builds that check: it arrives free
		 * in this response and would otherwise cost a re-transcription of every
		 * lecture on the site to recover. Only start and end are kept — the
		 * per-segment text is already in $text and would double the row.
		 */
		if ( ! empty( $decoded['segments'] ) && is_array( $decoded['segments'] ) ) {
			foreach ( $decoded['segments'] as $segment ) {
				if ( ! isset( $segment['start'], $segment['end'] ) ) {
					continue;
				}

				$meta['segments'][] = array(
					'start' => round( (float) $segment['start'], 2 ),
					'end'   => round( (float) $segment['end'], 2 ),
				);
			}
		}

		$text = isset( $decoded['text'] ) ? trim( (string) $decoded['text'] ) : '';

		if ( ! self::is_speech( $text ) || self::is_prompt_echo( $text, $prompt ) ) {
			/*
			 * A SILENT VIDEO RETURNS " ." — verbatim, with a 200.
			 *
			 * Refused here rather than left to index_post()'s length floor,
			 * because the transcript is concatenated with the post body before
			 * that floor is applied: a punctuation-only transcript rides in on
			 * real content's coat-tails and is then indexed as though the
			 * lecture had said it.
			 */
			return new WP_Error(
				'eduai_transcript_silent',
				__( 'No speech was found in this recording, so there is nothing to index.', 'eduai' )
			);
		}

		return $text;
	}

	/**
	 * Did the model just hand the prompt back?
	 *
	 * THIS IS THE TRAP THE FIX FOR THE LAST TRAP CREATED, and it is worse
	 * than the one it came from. Whisper biases decoding toward the words in
	 * `prompt` — that is the point — but given a SILENT video it has nothing
	 * to bias and returns the hint itself. Measured on attachments 144 and
	 * 145, both silent stock footage: the transcript came back as "Expected
	 * topics and terms.", a fragment of the prompt this class had just sent.
	 *
	 * `is_speech()` waves that through, because it is real words. Indexed, it
	 * becomes a lecture that says the course title and nothing else — content
	 * with our fingerprints on it, presented to a student as what the lecturer
	 * said.
	 *
	 * Containment rather than similarity: a genuine transcript is never a
	 * substring of a one-sentence hint, and an exact-ish test cannot misfire
	 * on a real lecture that happens to mention its own title.
	 *
	 * @param string $text   Transcript returned.
	 * @param string $prompt Hint that was sent.
	 */
	public static function is_prompt_echo( string $text, string $prompt ): bool {
		if ( '' === $prompt ) {
			return false;
		}

		$normalise = static function ( string $s ): string {
			$s = strtolower( wp_strip_all_tags( $s ) );
			$s = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s );

			return trim( (string) preg_replace( '/\s+/', ' ', (string) $s ) );
		};

		$clean_text   = $normalise( $text );
		$clean_prompt = $normalise( $prompt );

		if ( '' === $clean_text ) {
			return true;
		}

		return false !== strpos( $clean_prompt, $clean_text );
	}

	/**
	 * Does this look like speech rather than an empty result?
	 *
	 * Letters and digits across any script, so a lecture in Arabic counts and
	 * `" ."` does not. The floor is deliberately low — a genuinely short clip
	 * is still a transcript — and what is being excluded is punctuation and
	 * whitespace, not brevity.
	 *
	 * @param string $text Candidate transcript.
	 */
	public static function is_speech( string $text ): bool {
		return (bool) preg_match( '/[\p{L}\p{N}]{2,}/u', $text );
	}

	/**
	 * A decoding hint, to stop Whisper inventing confident nonsense.
	 *
	 * It mangles technical vocabulary — "Ogg Vorbis" came back as "org-forbus"
	 * in the proving run. On a machine-learning lecture the same failure turns
	 * *gradient descent* into something plausible and wrong, which is then
	 * indexed as fact and taught to a student. Groq supports `prompt` and it
	 * biases decoding toward the words in it, so the course and lesson titles
	 * go in.
	 *
	 * @param int $attachment_id Attachment.
	 * @param int $post_id       Post it belongs to.
	 */
	public static function prompt_for( int $attachment_id, int $post_id = 0 ): string {
		$terms = array();

		if ( $post_id ) {
			$terms[] = get_the_title( $post_id );

			if ( class_exists( 'EduAI_LMS' ) && EduAI_LMS::active() ) {
				$course = EduAI_LMS::course_of( $post_id );

				if ( $course ) {
					$terms[] = get_the_title( $course );
				}
			}
		}

		$parent = (int) wp_get_post_parent_id( $attachment_id );

		if ( $parent ) {
			$terms[] = get_the_title( $parent );
		}

		$terms = array_values( array_filter( array_unique( array_map( 'trim', $terms ) ) ) );

		/**
		 * Filter the decoding hint — where a glossary belongs.
		 *
		 * @param string[] $terms         Title terms gathered so far.
		 * @param int      $attachment_id Attachment.
		 * @param int      $post_id       Post it belongs to.
		 */
		$terms = (array) apply_filters( 'eduai_transcript_prompt_terms', $terms, $attachment_id, $post_id );

		if ( ! $terms ) {
			return '';
		}

		/*
		 * A BARE TERM LIST, not a sentence.
		 *
		 * This used to read "This is a university lecture recording. Expected
		 * topics and terms: …", and that carrier is what made the echo
		 * dangerous. Given a silent video Whisper hands the prompt back — so a
		 * fluent English carrier produces a fluent English transcript, which is
		 * the form most likely to survive any guard, present or future.
		 *
		 * A term list biases decoding exactly as well and echoes as a term
		 * list, which is obviously not a lecture to a reader and to anything
		 * downstream. Same benefit, safer failure.
		 */
		return implode( ', ', $terms );
	}

	/**
	 * Multipart POST of the file itself.
	 *
	 * Built by hand because wp_remote_post() has no multipart file mode — an
	 * array `body` is url-encoded, which would send the path as a string
	 * rather than the bytes.
	 *
	 * @param string $path   Absolute file path.
	 * @param string $key    API key.
	 * @param string $prompt Decoding hint.
	 * @return array|WP_Error
	 */
	private static function post_file( string $path, string $key, string $prompt = '' ) {
		$boundary = wp_generate_password( 24, false );
		$eol      = "\r\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$bytes = file_get_contents( $path );

		if ( false === $bytes ) {
			return new WP_Error( 'eduai_transcript_read', __( 'The video file could not be read.', 'eduai' ) );
		}

		// verbose_json, not the default: it carries `duration` and `language`.
		// The duration is what makes a words-per-second floor possible at all —
		// see the note in transcribe() about why it answers only ONE of the two
		// questions worth asking about it.
		$fields = array(
			'model'           => self::MODEL,
			'response_format' => 'verbose_json',
		);

		if ( '' !== $prompt ) {
			$fields['prompt'] = $prompt;
		}

		$mime    = wp_check_filetype( $path );
		$mime    = $mime['type'] ? $mime['type'] : 'application/octet-stream';
		$payload = '';

		foreach ( $fields as $name => $value ) {
			$payload .= '--' . $boundary . $eol;
			$payload .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
			$payload .= $value . $eol;
		}

		$payload .= '--' . $boundary . $eol;
		$payload .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $path ) . '"' . $eol;
		$payload .= 'Content-Type: ' . $mime . $eol . $eol;
		$payload .= $bytes . $eol;
		$payload .= '--' . $boundary . '--' . $eol;

		return wp_remote_post( self::ENDPOINT, array(
			// Minutes of audio against a network call. This only ever runs on
			// cron, so a long timeout costs nobody a page load.
			'timeout' => 300,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			),
			'body'    => $payload,
		) );
	}
}
