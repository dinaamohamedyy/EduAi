<?php
/**
 * Asserts the PHP arithmetic port agrees with the JavaScript reference.
 *
 *     php scripts/calc-parity.php
 *
 * scripts/calc-cases.json is generated from design/preview.html by
 * scripts/calc-parity.js — that is the reference half. This is the other half:
 * it runs the same inputs through EduAI_Calc and requires the same expression,
 * the same intermediate lines and the same final value, character for
 * character.
 *
 * The two must not drift. A student who works a sum out in the demo panel and
 * again on the live site has to get the same answer and the same steps, and a
 * silent divergence in a calculator is the kind of bug nobody reports because
 * nobody believes it.
 *
 * Refusals are part of the contract too: an input the reference declines must
 * be declined here, because that is what routes it to the model instead of
 * being answered wrongly in code.
 *
 * @package EduAI
 */

// This file runs standalone, outside WordPress. EduAI_Calc guards itself with
// `defined( 'ABSPATH' ) || exit;`, so stand that constant up before loading it.
define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../wp-content/plugins/eduai-assistant/includes/class-eduai-calc.php';

$fixture_path = __DIR__ . '/calc-cases.json';

if ( ! is_readable( $fixture_path ) ) {
	fwrite( STDERR, "calc-cases.json is missing — run: node scripts/calc-parity.js --write\n" );
	exit( 1 );
}

$fixture = json_decode( (string) file_get_contents( $fixture_path ), true );

if ( ! is_array( $fixture ) || empty( $fixture['cases'] ) ) {
	fwrite( STDERR, "calc-cases.json is not readable as a case table\n" );
	exit( 1 );
}

$problems = array();
$checked  = 0;

foreach ( $fixture['cases'] as $case ) {
	$input    = (string) $case['input'];
	$label    = json_encode( $input );
	++$checked;

	$expression = EduAI_Calc::expression( $input );

	if ( $expression !== $case['expression'] ) {
		$problems[] = sprintf(
			"%s: expression is %s, reference says %s",
			$label,
			json_encode( $expression ),
			json_encode( $case['expression'] )
		);
		continue;
	}

	// A case the reference refused: the port has to refuse it too, either at
	// expression() above or at solve() below.
	if ( null === $case['value'] ) {
		if ( null === $expression ) {
			continue;
		}

		$solved = EduAI_Calc::solve( $expression );

		if ( null !== $solved ) {
			$problems[] = sprintf(
				'%s: the port evaluated this to %s, but the reference refuses it — '
					. 'an input that should reach the model is being answered in code instead',
				$label,
				json_encode( EduAI_Calc::format( $solved['value'] ) )
			);
		}
		continue;
	}

	$solved = EduAI_Calc::solve( (string) $expression );

	if ( null === $solved ) {
		$problems[] = sprintf(
			'%s: the port refuses this, but the reference evaluates it to %s — '
				. 'a sum that should be computed exactly is being sent to the model instead',
			$label,
			json_encode( $case['value'] )
		);
		continue;
	}

	$value = EduAI_Calc::format( $solved['value'] );

	if ( $value !== $case['value'] ) {
		$problems[] = sprintf( '%s: value is %s, reference says %s', $label, json_encode( $value ), json_encode( $case['value'] ) );
	}

	if ( $solved['steps'] !== $case['lines'] ) {
		$problems[] = sprintf(
			"%s: working differs\n      port:      %s\n      reference: %s",
			$label,
			json_encode( $solved['steps'] ),
			json_encode( $case['lines'] )
		);
	}
}

if ( $problems ) {
	fwrite( STDERR, "AiCalc has drifted from the reference implementation:\n" );
	foreach ( $problems as $problem ) {
		fwrite( STDERR, '  ' . $problem . "\n" );
	}
	exit( 1 );
}

printf( "calc parity: %d cases agree with design/preview.html\n", $checked );
exit( 0 );
