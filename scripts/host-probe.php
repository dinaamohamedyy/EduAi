<?php
/**
 * Will EduAi actually run on this host? Upload this one file, open it, read it.
 *
 * Written because the expensive way to answer that question is to migrate the
 * whole site and find out afterwards. Free hosts advertise "PHP 8.3 + MySQL"
 * truthfully and then block the outbound network, which leaves a site that
 * looks completely fine while every AI feature fails and no password reset ever
 * arrives. That failure is invisible until a student hits it.
 *
 * Upload to the host's web root and open it in a browser. It takes no input,
 * writes nothing, and needs no database credentials.
 *
 * It then DELETES ITSELF, so it runs exactly once and a reload returning 404 is
 * the success case, not a problem — re-upload it if you need to run it again.
 * If it could not delete itself it says so in place of that, loudly and with
 * the absolute path, because until it is gone this page publishes the server's
 * PHP version, extensions and limits to anyone who guesses the URL. See the
 * note above the unlink at the end of the file for why it works this way.
 *
 * It deliberately does NOT need an API key. Reaching api.groq.com and being
 * told "unauthorized" is proof the network path works — which is the thing
 * being tested. Never put a real key in a file you upload to a host you are
 * still evaluating.
 *
 * @package EduAI
 */

// This file is a diagnostic, not part of the app. It must run before WordPress
// exists on the host, so it deliberately has no ABSPATH guard.

header( 'Content-Type: text/plain; charset=utf-8' );

$fail = 0;
$warn = 0;

/**
 * Report one requirement.
 *
 * @param bool   $ok       Did it pass.
 * @param string $label    What was tested.
 * @param string $detail   What was found.
 * @param bool   $critical A failure here means the app cannot work at all.
 */
function req( bool $ok, string $label, string $detail, bool $critical = true ): void {
	global $fail, $warn;

	if ( $ok ) {
		$mark = 'ok   ';
	} elseif ( $critical ) {
		$mark = 'FAIL ';
		$fail++;
	} else {
		$mark = 'warn ';
		$warn++;
	}

	printf( "%s %-34s %s\n", $mark, $label, $detail );
}

/**
 * Bytes from an ini shorthand value ("64M", "512K").
 */
function bytes( string $val ): int {
	$val  = trim( $val );
	$unit = strtolower( substr( $val, -1 ) );
	$num  = (int) $val;

	if ( 'g' === $unit ) {
		return $num * 1024 * 1024 * 1024;
	}
	if ( 'm' === $unit ) {
		return $num * 1024 * 1024;
	}
	if ( 'k' === $unit ) {
		return $num * 1024;
	}

	return $num;
}

print "EduAi host probe\n";
print "================\n\n";
print "Delete this file when you are done reading it.\n\n";

print "-- language and extensions --\n";

req(
	version_compare( PHP_VERSION, '8.1', '>=' ),
	'PHP 8.1 or newer',
	PHP_VERSION
);

foreach ( array(
	'curl'     => 'talking to the AI provider',
	'zip'      => 'reading .pptx and .docx lectures',
	'zlib'     => 'extracting text from PDFs',
	'mbstring' => 'handling non-English text',
	'mysqli'   => 'the database',
) as $ext => $why ) {
	req( extension_loaded( $ext ), "ext-$ext", extension_loaded( $ext ) ? $why : "MISSING — $why will not work" );
}

print "\n-- limits --\n";

$mem = bytes( ini_get( 'memory_limit' ) );
req( -1 === $mem || $mem >= 256 * 1024 * 1024, 'memory_limit >= 256M', ini_get( 'memory_limit' ) );

$up = bytes( ini_get( 'upload_max_filesize' ) );
req( $up >= 64 * 1024 * 1024, 'upload_max_filesize >= 64M', ini_get( 'upload_max_filesize' ) . ' — lecture decks are large' );

$post = bytes( ini_get( 'post_max_size' ) );
req( $post >= 64 * 1024 * 1024, 'post_max_size >= 64M', ini_get( 'post_max_size' ) );

$exec = (int) ini_get( 'max_execution_time' );
req( 0 === $exec || $exec >= 120, 'max_execution_time >= 120', ( 0 === $exec ? 'unlimited' : $exec . 's' ) . ' — generating an exam is slow' );

print "\n-- the network, which is what free hosts usually block --\n";

if ( ! function_exists( 'curl_init' ) ) {
	req( false, 'outbound HTTPS', 'cannot test — ext-curl missing' );
} else {
	// No key sent on purpose. A 401 proves the request left the building and
	// came back, which is exactly what is being tested. A timeout or a DNS
	// error is the answer that matters.
	$ch = curl_init( 'https://api.groq.com/openai/v1/models' );
	curl_setopt_array( $ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 15,
		CURLOPT_SSL_VERIFYPEER => true,
	) );

	$body = curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$err  = curl_error( $ch );
	curl_close( $ch );

	if ( $code > 0 ) {
		req(
			true,
			'outbound HTTPS to api.groq.com',
			"reached it (HTTP $code" . ( 401 === $code ? ', unauthorized as expected without a key' : '' ) . ')'
		);
	} else {
		req( false, 'outbound HTTPS to api.groq.com', 'BLOCKED — ' . ( $err ?: 'no response' ) . '. Every AI feature will fail here.' );
	}
}

// Password resets go out over SMTP. InfinityFree and several other free hosts
// block this outright, which strands anyone who forgets a password — and the
// site looks perfectly healthy while it happens.
$smtp_ok = false;
$smtp_note = 'no socket function available to test with';

if ( function_exists( 'fsockopen' ) ) {
	$sock = @fsockopen( 'smtp.gmail.com', 587, $errno, $errstr, 10 );

	if ( $sock ) {
		$smtp_ok   = true;
		$smtp_note = 'outbound port 587 is open';
		fclose( $sock );
	} else {
		$smtp_note = "BLOCKED — $errstr. Password resets will never arrive.";
	}
}

req( $smtp_ok, 'outbound SMTP (port 587)', $smtp_note, false );

print "\n-- filesystem --\n";

$dir     = __DIR__ . '/eduai-probe-tmp';
$made    = @mkdir( $dir );
$written = $made && false !== @file_put_contents( $dir . '/t.txt', 'x' );

if ( $written ) {
	@unlink( $dir . '/t.txt' );
}
if ( $made ) {
	@rmdir( $dir );
}

req( $written, 'writable filesystem', $written ? 'can create and write directories' : 'CANNOT WRITE — uploads will fail' );

print "\n";
print str_repeat( '=', 60 ) . "\n";

if ( $fail > 0 ) {
	printf( "%d critical requirement(s) failed. EduAi will not work here.\n", $fail );
	print "Do not migrate to this host. Read the FAIL lines above — an outbound\n";
	print "block is the usual one, and it cannot be worked around from inside PHP.\n";
} elseif ( $warn > 0 ) {
	printf( "Everything critical passed, with %d warning(s).\n", $warn );
	print "The site will run. Read the warn lines — an SMTP block means password\n";
	print "resets silently never arrive, which you can live with only if you know.\n";
} else {
	print "Everything passed. This host can run EduAi.\n";
}

// ---------------------------------------------------------------- self-delete
//
// "Now delete this file" was the whole cleanup, and a remembered step is not a
// step. Left in place this is an unauthenticated page publishing the server's
// PHP version, loaded extensions and ini limits to anyone who guesses the URL —
// a phpinfo() page by another name, on a host chosen for being cheap. It cannot
// be gated behind a token, because it has to run before WordPress exists and
// its whole value is "upload one file, open it".
//
// So it runs exactly once and removes itself. If that fails — read-only
// directory, wrong owner — say so loudly rather than printing a tidy summary
// and leaving the page up, because a silent failure here is indistinguishable
// from success and leaves the disclosure in place indefinitely.
print "\n";

if ( @unlink( __FILE__ ) ) {
	print "This file has deleted itself. Re-upload it if you need to run it again.\n";
} else {
	print "!! COULD NOT DELETE ITSELF — DO IT NOW, BY HAND.\n";
	printf( "   %s\n", __FILE__ );
	print "   Until you do, anyone with this URL can read the server's PHP\n";
	print "   version, extensions and limits.\n";
}
