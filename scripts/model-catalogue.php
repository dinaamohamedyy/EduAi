<?php
/**
 * Do the models we pin still exist on the providers we pin them to?
 *
 *   docker compose --profile tools run --rm cli \
 *     wp eval-file /scripts/model-catalogue.php --allow-root
 *
 * THE FAILURE THIS EXISTS FOR, which happened rather than being imagined:
 * Groq retired the whole Llama line. `llama-3.3-70b-versatile` and
 * `llama-3.1-8b-instant` stopped existing, which was the balanced and fast
 * tiers, which was the ordinary chat tab. Nothing in this repository changed.
 * Someone else's catalogue did, and it arrived as a 404 at request time.
 *
 * No test we own could have caught it. Every other check in this repo asks
 * whether we agree with ourselves; a pinned model id is a dependency on a
 * catalogue nobody here controls, and that whole class is invisible to a suite
 * whose evidence comes from the same source as the thing it is checking.
 *
 * IT READS THE IDS FROM WHERE THE PLUGIN READS THEM. EduAI_Claude::providers()
 * and EduAI_Transcript::MODEL, at runtime, never a list of its own. A checker
 * carrying its own copy of the ids is precisely the defect it exists to
 * prevent, and this codebase produced three of those in one day: an acceptance
 * script with a private copy of the transcript gate, a harness with a stale
 * copy of that copy, and three HTML files repeating these very model ids.
 *
 * Costs nothing. `GET /models` is a catalogue listing, not inference, so this
 * can run as often as anyone likes.
 *
 * Exits non-zero, naming the model and the tier, when a pinned id is gone.
 *
 * @package Scholaris
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run me through wp-cli, not php.\n" );
	exit( 1 );
}

if ( ! class_exists( 'EduAI_Claude' ) ) {
	fwrite( STDERR, "EduAI_Claude is not loaded - there is nothing to check.\n" );
	exit( 1 );
}

/**
 * Where a provider lists its catalogue, derived from where it takes requests.
 *
 * Derived rather than configured, so a provider whose endpoint moves cannot
 * leave this pointing at the old host and reporting confidently about it.
 *
 * @param array $provider One entry from EduAI_Claude::providers().
 * @return string Absolute URL, or '' when this provider's catalogue cannot be
 *                reached and the check must declare itself skipped.
 */
function mc_catalogue_url( array $provider ): string {
	$endpoint = (string) ( $provider['endpoint'] ?? '' );

	if ( '' === $endpoint ) {
		return '';
	}

	foreach ( array( '/chat/completions', '/messages' ) as $tail ) {
		if ( str_ends_with( $endpoint, $tail ) ) {
			return substr( $endpoint, 0, -strlen( $tail ) ) . '/models';
		}
	}

	return '';
}

/**
 * Every model id a provider currently offers.
 *
 * @param string $url      Catalogue URL.
 * @param string $key      API key.
 * @param string $format   Provider wire format.
 * @return array|WP_Error  List of ids.
 */
function mc_fetch( string $url, string $key, string $format ) {
	$headers = array( 'Authorization' => 'Bearer ' . $key );

	if ( 'anthropic' === $format ) {
		$headers = array(
			'x-api-key'         => $key,
			'anthropic-version' => '2023-06-01',
		);
	}

	$response = wp_remote_get( $url, array( 'timeout' => 30, 'headers' => $headers ) );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 !== $code ) {
		return new WP_Error( 'mc_http', sprintf( 'HTTP %d from %s', $code, $url ) );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	$rows = $body['data'] ?? $body['models'] ?? array();
	$ids  = array();

	foreach ( (array) $rows as $row ) {
		if ( ! empty( $row['id'] ) ) {
			$ids[] = (string) $row['id'];
		}
	}

	if ( ! $ids ) {
		return new WP_Error( 'mc_empty', sprintf( 'no model ids in the response from %s', $url ) );
	}

	return $ids;
}

/*
 * Everything this install pins, gathered from the classes that own it.
 *
 * The transcription model is here because it is pinned to the same catalogue
 * and would fail the same way. Its provider is matched by HOST rather than
 * named, so moving that endpoint moves this check with it.
 */
$providers = EduAI_Claude::providers();
$pinned    = array();

foreach ( $providers as $id => $provider ) {
	foreach ( (array) ( $provider['models'] ?? array() ) as $tier => $model ) {
		$pinned[ $id ][] = array( 'what' => $tier, 'model' => $model );
	}
}

if ( class_exists( 'EduAI_Transcript' ) && defined( 'EduAI_Transcript::MODEL' ) ) {
	$host = wp_parse_url( EduAI_Transcript::ENDPOINT, PHP_URL_HOST );

	foreach ( $providers as $id => $provider ) {
		if ( $host && wp_parse_url( (string) ( $provider['endpoint'] ?? '' ), PHP_URL_HOST ) === $host ) {
			$pinned[ $id ][] = array( 'what' => 'transcription', 'model' => EduAI_Transcript::MODEL );
			break;
		}
	}
}

$missing = array();
$skipped = array();
$checked = 0;

foreach ( $pinned as $id => $entries ) {
	$provider = $providers[ $id ] ?? array();
	$label    = $provider['label'] ?? $id;
	$key      = EduAI_Settings::api_key( $id );
	$url      = mc_catalogue_url( $provider );

	printf( "\n%s\n", $label );

	if ( '' === $key ) {
		// Not a pass. Say which ids went unchecked, or a green run implies a
		// coverage it never had.
		foreach ( $entries as $e ) {
			$skipped[] = sprintf( '%s/%s (%s) - no API key configured', $id, $e['what'], $e['model'] );
			printf( "  skip  %-14s %-32s no API key\n", $e['what'], $e['model'] );
		}
		continue;
	}

	if ( '' === $url ) {
		foreach ( $entries as $e ) {
			$skipped[] = sprintf( '%s/%s (%s) - no catalogue endpoint could be derived', $id, $e['what'], $e['model'] );
			printf( "  skip  %-14s %-32s no catalogue endpoint\n", $e['what'], $e['model'] );
		}
		continue;
	}

	$live = mc_fetch( $url, $key, (string) ( $provider['format'] ?? 'openai' ) );

	if ( is_wp_error( $live ) ) {
		foreach ( $entries as $e ) {
			$skipped[] = sprintf( '%s/%s (%s) - %s', $id, $e['what'], $e['model'], $live->get_error_message() );
		}
		printf( "  skip  catalogue unreachable: %s\n", $live->get_error_message() );
		continue;
	}

	foreach ( $entries as $e ) {
		++$checked;

		if ( in_array( $e['model'], $live, true ) ) {
			printf( "  ok    %-14s %s\n", $e['what'], $e['model'] );
			continue;
		}

		$missing[] = sprintf( '%s %s -> %s', $id, $e['what'], $e['model'] );
		printf( "  GONE  %-14s %-32s not in %s's catalogue\n", $e['what'], $e['model'], $id );
	}

	printf( "        (%d models offered)\n", count( $live ) );
}

printf( "\n%s\n", str_repeat( '-', 68 ) );

if ( $skipped ) {
	printf( "\n%d pinned model(s) NOT checked:\n", count( $skipped ) );

	foreach ( $skipped as $s ) {
		printf( "  %s\n", $s );
	}
}

if ( $missing ) {
	printf( "\n%d pinned model(s) NO LONGER EXIST:\n\n", count( $missing ) );

	foreach ( $missing as $m ) {
		printf( "  %s\n", $m );
	}

	printf(
		"\nThe feature asking for that tier is returning 404 to users right now.\n" .
		"Fix in EduAI_Claude::providers(), then re-run scripts/contract-tests.pl -\n" .
		"the same ids are repeated in design/preview.html, tools/agent-test.html\n" .
		"and the gitignored root preview.html, and parity will fail until all\n" .
		"four agree.\n"
	);

	exit( 1 );
}

printf( "\nall %d checked pinned model(s) still exist\n", $checked );

exit( 0 );
