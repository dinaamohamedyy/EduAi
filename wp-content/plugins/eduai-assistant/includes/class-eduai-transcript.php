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
 * signup, no new secret, no new container, and no audio-extraction step —
 * Groq accepts an mp4 directly, which is what makes this shippable without
 * ffmpeg (there is none in the container, and none is being added today).
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

		// Already queued: wp_schedule_single_event dedupes identical args, but
		// only while the event is pending, so the state flag covers the window
		// where cron has picked it up and not yet finished.
		if ( ! $force && 'queued' === self::state( $attachment_id ) ) {
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
		$text          = self::transcribe( $attachment_id, (int) $post_id );

		if ( is_wp_error( $text ) ) {
			update_post_meta( $attachment_id, self::META_STATE, 'error: ' . $text->get_error_message() );
			return;
		}

		/*
		 * The quality layer gets the last word, if it is deployed.
		 *
		 * This class refuses what is not speech; that one judges whether what
		 * IS speech is usable — Whisper's hallucinated "thanks for watching"
		 * on silence, a words-per-minute floor, completeness. Different
		 * questions, so both run, and a refusal from either stores the reason
		 * rather than the text.
		 */
		if ( class_exists( 'EduAI_Transcript_Guard' ) && method_exists( 'EduAI_Transcript_Guard', 'usable' ) ) {
			$verdict = EduAI_Transcript_Guard::usable( $text );

			if ( is_wp_error( $verdict ) ) {
				update_post_meta( $attachment_id, self::META_STATE, 'rejected: ' . $verdict->get_error_message() );
				return;
			}
		}

		update_post_meta( $attachment_id, self::META, $text );
		update_post_meta( $attachment_id, self::META_STATE, 'ok' );

		// The transcript only reaches a student through the index, so writing
		// it without reindexing would store a correct answer nobody can read.
		if ( $post_id && class_exists( 'EduAI_Knowledge' ) ) {
			EduAI_Knowledge::index_post( (int) $post_id );
		}
	}

	/**
	 * Post the file to Groq and return the text.
	 *
	 * @param int $attachment_id Attachment.
	 * @param int $post_id       Post it belongs to, for the decoding prompt.
	 * @return string|WP_Error
	 */
	public static function transcribe( int $attachment_id, int $post_id = 0 ) {
		$key = class_exists( 'EduAI_Settings' ) ? EduAI_Settings::api_key( 'groq' ) : '';

		if ( '' === $key ) {
			return new WP_Error( 'eduai_transcript_key', __( 'No Groq API key is configured, so video cannot be transcribed.', 'eduai' ) );
		}

		$path = get_attached_file( $attachment_id );

		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'eduai_transcript_missing', __( 'The video file is missing from the media library.', 'eduai' ) );
		}

		$size = (int) filesize( $path );

		if ( $size > self::MAX_BYTES ) {
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

		$prompt   = self::prompt_for( $attachment_id, $post_id );
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

		return sprintf(
			/* translators: %s: comma-separated lecture and course titles */
			__( 'This is a university lecture recording. Expected topics and terms: %s.', 'eduai' ),
			implode( ', ', $terms )
		);
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

		$fields = array( 'model' => self::MODEL );

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
