<?php
/**
 * Dependency-free text extraction for indexing and summarising.
 *
 * PDF handles the common case: a digitally generated file whose page streams are
 * Flate-compressed. Scanned/image-only PDFs return little or nothing — those
 * are forwarded to Claude as a document block instead (see EduAI_Claude::pdf_block).
 *
 * PPTX and DOCX are ZIP packages of XML, so they are read exactly, notes and
 * slide order included. A deck of photographed slides has no text to find; the
 * caller is expected to tell the student to export it to PDF.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Minimal PDF / PPTX / DOCX / plain-text reader.
 */
class EduAI_PDF {

	/**
	 * Extract text from any supported file type.
	 *
	 * Pass $ext whenever the path does not carry a meaningful extension. An
	 * uploaded file arrives as something like /tmp/phpA1B2.tmp, so relying on
	 * the path alone silently yields an empty string.
	 *
	 * @param string $path Absolute path.
	 * @param string $ext  Format hint, e.g. 'pptx'. Defaults to the path's extension.
	 * @return string Plain text (may be empty).
	 */
	public static function extract( string $path, string $ext = '' ): string {
		if ( ! is_readable( $path ) ) {
			return '';
		}

		$ext = strtolower( ltrim( trim( $ext ), '.' ) );
		if ( '' === $ext ) {
			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		}

		switch ( $ext ) {
			case 'pdf':
				return self::from_pdf( $path );
			case 'pptx':
				return self::from_pptx( $path );
			case 'docx':
				return self::from_docx( $path );
			case 'txt':
			case 'md':
			case 'csv':
			case 'rtf':
				$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				return self::tidy( is_string( $raw ) ? $raw : '' );
			default:
				return '';
		}
	}

	/**
	 * Number of pages in a PDF, or 0 when it cannot be determined.
	 */
	public static function page_count( string $path ): int {
		if ( ! is_readable( $path ) ) {
			return 0;
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $raw ) ) {
			return 0;
		}

		if ( preg_match( '/\/Count\s+(\d+)/', $raw, $m ) ) {
			return (int) $m[1];
		}

		return preg_match_all( '/\/Type\s*\/Page[^s]/', $raw );
	}

	/**
	 * Pull text out of a PDF's content streams.
	 */
	private static function from_pdf( string $path ): string {
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		// Encrypted PDFs cannot be read without the key.
		if ( false !== strpos( $raw, '/Encrypt' ) ) {
			return '';
		}

		$text = '';

		// Each `stream ... endstream` pair may hold page content.
		if ( ! preg_match_all( '/stream\r?\n?(.*?)endstream/s', $raw, $matches ) ) {
			return '';
		}

		foreach ( $matches[1] as $stream ) {
			$data = trim( $stream, "\r\n" );

			// Try Flate first, then treat the stream as already-plain.
			$plain = @gzuncompress( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $plain ) {
				$plain = @gzinflate( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			if ( false === $plain ) {
				$plain = $data;
			}

			if ( false === strpos( $plain, 'Tj' ) && false === strpos( $plain, 'TJ' ) ) {
				continue;
			}

			$text .= self::from_content_stream( $plain ) . "\n";

			// Guard against pathological files.
			if ( strlen( $text ) > 4 * MB_IN_BYTES ) {
				break;
			}
		}

		return self::tidy( $text );
	}

	/**
	 * Read the text-showing operators out of one decoded content stream.
	 */
	private static function from_content_stream( string $stream ): string {
		$out = '';

		// TJ arrays: [(He)-20(llo)] TJ  — and Tj strings: (Hello) Tj
		if ( preg_match_all( '/\[(.*?)\]\s*TJ|\((?:\\\\.|[^\\\\()])*\)\s*Tj/s', $stream, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$segment = $m[0];

				if ( preg_match_all( '/\((?:\\\\.|[^\\\\()])*\)/s', $segment, $strings ) ) {
					foreach ( $strings[0] as $s ) {
						$out .= self::unescape( substr( $s, 1, -1 ) );
					}
				}

				// A large negative kern in a TJ array usually means a word space.
				if ( preg_match( '/-\d{3,}/', $segment ) ) {
					$out .= ' ';
				}

				$out .= ' ';
			}
		}

		// Line breaks from text-positioning operators.
		$out = preg_replace( '/\s*(?:T\*|TD|Td)\s*/', "\n", $out ) ?? $out;

		return $out;
	}

	/**
	 * Resolve PDF string escapes.
	 */
	private static function unescape( string $s ): string {
		$map = array(
			'\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\b' => "\x08",
			'\\f' => "\x0C", '\\(' => '(', '\\)' => ')', '\\\\' => '\\',
		);

		$s = strtr( $s, $map );

		// Octal escapes: \053
		return preg_replace_callback(
			'/\\\\([0-7]{1,3})/',
			static fn( $m ) => chr( octdec( $m[1] ) ),
			$s
		) ?? $s;
	}

	/**
	 * Does an extraction look like real prose, or like a failed decode?
	 *
	 * This reader carries no font-encoding tables, so a PDF built on CID-keyed
	 * or subset fonts comes back as a long string of plausible-looking nonsense.
	 * Length alone therefore cannot decide whether the text is good enough to
	 * use in place of sending the pages to Claude.
	 *
	 * It is deliberately conservative, and it is meant to be: a false negative
	 * costs tokens, a false positive costs the student a summary of garbage.
	 * Material in a non-Latin script fails the letter test and is sent to
	 * Claude, which is the right outcome for it too.
	 *
	 * @param string $text Extracted text.
	 */
	public static function looks_like_prose( string $text ): bool {
		$text   = trim( $text );
		$length = strlen( $text );

		if ( $length < 400 ) {
			return false;
		}

		// Sample the middle, so a cleanly extracted title page cannot vouch for
		// a body the reader mangled.
		$sample = $length > 20000 ? substr( $text, (int) ( $length / 2 ), 8000 ) : $text;
		$total  = strlen( $sample );

		if ( $total < 200 ) {
			return false;
		}

		$letters = (int) preg_match_all( '/[A-Za-z]/', $sample );
		$spacing = (int) preg_match_all( '/[ \n\t]/', $sample );

		// Ordinary English prose runs around 75% letters and 17% spacing.
		if ( $letters / $total < 0.5 || $spacing / $total < 0.08 ) {
			return false;
		}

		// Real text contains function words; a broken decode almost never does.
		return (bool) preg_match( '/\b(?:the|and|that|with|this|from|which|for|are|was)\b/i', $sample );
	}

	/**
	 * Number of slides in a .pptx, or 0 when it cannot be determined.
	 */
	public static function slide_count( string $path ): int {
		if ( ! is_readable( $path ) || ! class_exists( 'ZipArchive' ) ) {
			return 0;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return 0;
		}

		$count = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			if ( preg_match( '#^ppt/slides/slide\d+\.xml$#', (string) $zip->getNameIndex( $i ) ) ) {
				++$count;
			}
		}

		$zip->close();

		return $count;
	}

	/**
	 * Read a .pptx deck, slide by slide.
	 *
	 * Two details matter for study notes. Slide parts are sorted numerically,
	 * because the order inside the package is whatever the authoring tool wrote
	 * and slide10 must not land between slide1 and slide2. And speaker notes
	 * are pulled in alongside each slide — that is usually where the lecturer
	 * wrote the sentence the bullet only hints at.
	 */
	private static function from_pptx( string $path ): string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return '';
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return '';
		}

		$slides = array();

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( preg_match( '#^ppt/slides/slide(\d+)\.xml$#', $name, $m ) ) {
				$slides[ (int) $m[1] ] = $name;
			}
		}

		ksort( $slides, SORT_NUMERIC );

		// Resolve every slide's notes part before reading any of them. If the
		// deck carries relationship files at all they are authoritative, and the
		// numeric guess is used only for decks that have none — mixing the two
		// lets a slide without rels borrow notes belonging to another slide.
		$notes     = array();
		$saw_rels  = false;

		foreach ( array_keys( $slides ) as $index ) {
			$rels = (string) $zip->getFromName( 'ppt/slides/_rels/slide' . $index . '.xml.rels' );

			if ( '' !== $rels && preg_match( '#Target="([^"]*notesSlide\d+\.xml)"#', $rels, $m ) ) {
				$saw_rels        = true;
				$notes[ $index ] = self::resolve_part( 'ppt/slides/' . ltrim( $m[1], '/' ) );
			}
		}

		$out    = array();
		$number = 0;

		foreach ( $slides as $index => $part ) {
			++$number;

			$notes_part = $notes[ $index ] ?? ( $saw_rels ? '' : 'ppt/notesSlides/notesSlide' . $index . '.xml' );

			$body = self::from_drawingml( (string) $zip->getFromName( $part ) );
			$note = '' !== $notes_part ? self::from_drawingml( (string) $zip->getFromName( $notes_part ) ) : '';

			if ( '' === $body && '' === $note ) {
				continue;
			}

			$block = sprintf( '--- Slide %d ---', $number );

			if ( '' !== $body ) {
				$block .= "\n" . $body;
			}
			if ( '' !== $note ) {
				$block .= "\n\nSpeaker notes: " . $note;
			}

			$out[] = $block;
		}

		$zip->close();

		return self::tidy( implode( "\n\n", $out ) );
	}

	/**
	 * Collapse the ../ that OOXML relationship targets are written with.
	 *
	 * @param string $target Package path, possibly relative.
	 */
	private static function resolve_part( string $target ): string {
		while ( false !== strpos( $target, '../' ) ) {
			$resolved = preg_replace( '#[^/]+/\.\./#', '', $target, 1 );
			if ( ! is_string( $resolved ) || $resolved === $target ) {
				break;
			}
			$target = $resolved;
		}

		return $target;
	}

	/**
	 * Pull the visible text out of a DrawingML part (a slide or a notes page).
	 *
	 * Text sits in <a:t> runs, but the line structure lives in the elements
	 * around them. Paragraph ends and explicit breaks are rewritten as runs of
	 * their own first, so one pass over <a:t> keeps a bulleted slide as several
	 * lines instead of flattening it into one.
	 *
	 * @param string $xml Part contents.
	 */
	private static function from_drawingml( string $xml ): string {
		if ( '' === trim( $xml ) ) {
			return '';
		}

		$break = '{{eduai-br}}';

		$xml = (string) preg_replace( '#<a:br\s*/?>#', '<a:t>' . $break . '</a:t>', $xml );
		$xml = str_replace( '</a:p>', '<a:t>' . $break . '</a:t></a:p>', $xml );

		if ( ! preg_match_all( '#<a:t(?:\s[^>]*)?>(.*?)</a:t>#s', $xml, $matches ) ) {
			return '';
		}

		$text = implode( '', $matches[1] );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		$text = str_replace( $break, "\n", $text );

		return self::tidy( $text );
	}

	/**
	 * Read word/document.xml out of a .docx package.
	 */
	private static function from_docx( string $path ): string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return '';
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return '';
		}

		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		if ( ! is_string( $xml ) || '' === $xml ) {
			return '';
		}

		$xml = preg_replace( '/<w:p[ >]/', "\n<w:p ", $xml ) ?? $xml;
		$xml = preg_replace( '/<w:br\s*\/?>/', "\n", $xml ) ?? $xml;

		return self::tidy( wp_strip_all_tags( $xml ) );
	}

	/**
	 * Normalise whitespace and drop control characters.
	 */
	private static function tidy( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '/[^\P{C}\n\t]+/u', '', $text ) ?? $text;
		$text = preg_replace( '/[ \t]{2,}/', ' ', $text ) ?? $text;
		$text = preg_replace( '/\n{3,}/', "\n\n", $text ) ?? $text;

		return trim( $text );
	}

	/**
	 * Split text into overlapping chunks that respect sentence boundaries.
	 *
	 * @param string $text    Source text.
	 * @param int    $size    Target chunk size in characters.
	 * @param int    $overlap Characters of overlap between neighbours.
	 * @return string[]
	 */
	public static function chunk( string $text, int $size = 1400, int $overlap = 200 ): array {
		$text = trim( $text );
		if ( '' === $text ) {
			return array();
		}

		if ( strlen( $text ) <= $size ) {
			return array( $text );
		}

		$chunks = array();
		$start  = 0;
		$len    = strlen( $text );

		while ( $start < $len ) {
			$end = min( $start + $size, $len );

			if ( $end < $len ) {
				// Prefer to break on a paragraph, then a sentence, then a space.
				$window = substr( $text, $start, $size );
				$break  = max(
					strrpos( $window, "\n\n" ) ?: 0,
					strrpos( $window, '. ' ) ?: 0,
					strrpos( $window, "\n" ) ?: 0
				);

				if ( $break > $size * 0.5 ) {
					$end = $start + $break + 1;
				}
			}

			$piece = trim( substr( $text, $start, $end - $start ) );
			if ( '' !== $piece ) {
				$chunks[] = $piece;
			}

			if ( $end >= $len ) {
				break;
			}

			$start = max( $end - $overlap, $start + 1 );
		}

		return $chunks;
	}
}
