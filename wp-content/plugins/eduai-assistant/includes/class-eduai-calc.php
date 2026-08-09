<?php
/**
 * AiCalc — the deterministic half.
 *
 * A language model should not be asked what 12 * 8 is. It is slower than
 * arithmetic, it costs money, and it is occasionally wrong. So AiCalc routes on
 * the input: anything that is purely arithmetic is evaluated here, exactly, with
 * every step recorded; anything symbolic, worded or unit-bearing falls through
 * to the model (docs/06-eduai-rebuild.md §2.2).
 *
 * This is a port of the reference implementation in design/preview.html, not a
 * rewrite — that version was verified against a case list covering precedence,
 * parentheses, right-associative powers and unary minus, and the two must agree.
 * scripts/calc-parity.js lifts the reference out of the preview page and records
 * what it produces in scripts/calc-cases.json; scripts/calc-parity.php runs this
 * class over the same table and requires identical output, refusals included.
 * Both run in CI, so a divergence fails the build rather than reaching a student
 * as two different answers to the same sum.
 *
 * Nothing here evaluates anything it was not handed: the input is tokenised, and
 * a single character outside the arithmetic alphabet rejects the whole string
 * before a number is ever parsed. There is no eval() anywhere in this path.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exact arithmetic with a visible working.
 */
class EduAI_Calc {

	/**
	 * Binding power. `^` binds tightest, then prefix negation, then the
	 * multiplicative operators, then the additive ones.
	 *
	 * `u` is prefix negation — a minus with nothing to its left — and it sits
	 * BELOW `^` deliberately. That makes `-2^2` mean `-(2^2) = -4`, which is
	 * what a maths textbook, Python and Wolfram all say, and not `(-2)^2 = 4`.
	 * An earlier version folded the minus into the number literal and got 4;
	 * a calculator that teaches a student the wrong precedence is worse than
	 * one that refuses the sum. Brackets still win, so `(-2)^2` is still 4.
	 */
	private const PRECEDENCE = array( '^' => 4, 'u' => 3, '*' => 2, '/' => 2, '+' => 1, '-' => 1 );

	/**
	 * Operators that resolve right to left: `2^3^2` is `2^(3^2)`, and `--3`
	 * applies the inner negation first.
	 */
	private const RIGHT_ASSOCIATIVE = array( '^' => true, 'u' => true );

	/**
	 * Guard against a pathological nest rather than looping forever.
	 */
	private const MAX_DEPTH = 40;

	/**
	 * Pull an arithmetic expression out of what the student typed.
	 *
	 * Returns null when the input is not purely arithmetic — which is the signal
	 * to hand the question to the model instead. "Calculate the molar mass of
	 * Ca(OH)2" contains letters and must not be mistaken for a sum.
	 *
	 * @param string $text Raw input.
	 * @return string|null Normalised expression, or null.
	 */
	public static function expression( string $text ): ?string {
		$s = strtolower( trim( $text ) );

		$s = (string) preg_replace(
			'/^(?:what(?:\'s| is)|whats|how much is|calculate|compute|work out|evaluate|solve)\b/',
			'',
			$s
		);
		$s = (string) preg_replace( '/[?=\s]+$/', '', $s );

		// Typed maths uses the real symbols; the parser only knows ASCII.
		$s = strtr( $s, array( '×' => '*', '÷' => '/', '−' => '-', '–' => '-' ) );
		$s = trim( $s );

		if ( '' === $s ) {
			return null;
		}
		// A single character outside this set disqualifies the whole string.
		if ( ! preg_match( '#^[0-9.+\-*/^() ]+$#', $s ) ) {
			return null;
		}
		// A bare number is not a sum, and an operator with no operand is not either.
		if ( ! preg_match( '#[+\-*/^]#', $s ) || ! preg_match( '/\d/', $s ) ) {
			return null;
		}

		return $s;
	}

	/**
	 * Evaluate an expression, recording one line per operation resolved.
	 *
	 * @param string $expr Expression from self::expression().
	 * @return array{steps:string[],value:float}|null Null when it cannot be evaluated.
	 */
	public static function solve( string $expr ): ?array {
		$tk = self::tokens( $expr );

		if ( null === $tk || ! $tk ) {
			return null;
		}

		$lines = array( self::show( $tk ) );
		$guard = 0;

		// Innermost brackets first. Their internal steps are not recorded — the
		// line showing the group replaced by its value is the useful one.
		while ( in_array( '(', $tk, true ) ) {
			if ( ++$guard > self::MAX_DEPTH ) {
				return null;
			}

			$open  = self::last_index_of( $tk, '(' );
			$close = self::index_of( $tk, ')', $open );

			if ( $open < 0 || $close < 0 ) {
				return null;
			}

			$inner   = array_slice( $tk, $open + 1, $close - $open - 1 );
			$discard = null;
			$value   = self::reduce( $inner, $discard );

			if ( null === $value ) {
				return null;
			}

			array_splice( $tk, $open, $close - $open + 1, array( $value ) );
			$lines[] = self::show( $tk );
		}

		$result = self::reduce( $tk, $lines );

		if ( null === $result ) {
			return null;
		}

		// A single-operation sum would otherwise print the same line twice.
		$steps = array();
		foreach ( $lines as $i => $line ) {
			if ( 0 === $i || $line !== $lines[ $i - 1 ] ) {
				$steps[] = $line;
			}
		}

		return array( 'steps' => $steps, 'value' => $result );
	}

	/**
	 * Split into numbers and operators, folding a unary minus into its number.
	 *
	 * @param string $s Normalised expression.
	 * @return array|null
	 */
	private static function tokens( string $s ): ?array {
		$tk     = array();
		$i      = 0;
		$length = strlen( $s );

		while ( $i < $length ) {
			$c = $s[ $i ];

			if ( ' ' === $c ) {
				++$i;
				continue;
			}

			if ( false !== strpos( '+-*/^()', $c ) ) {
				$prev = $tk ? $tk[ count( $tk ) - 1 ] : null;

				// A minus is prefix negation at the start, after '(', or after
				// another operator including a previous negation. Anywhere else
				// it is subtraction. It becomes its own 'u' token rather than
				// being folded into the following number, because folding would
				// give it the number's binding power and silently make -2^2
				// equal 4.
				$unary = ( '-' === $c )
					&& ( null === $prev || '(' === $prev
						|| ( is_string( $prev ) && false !== strpos( '+-*/^u', $prev ) ) );

				$tk[] = $unary ? 'u' : $c;
				++$i;
				continue;
			}

			if ( ! preg_match( '/^\d*\.?\d+/', substr( $s, $i ), $m ) ) {
				return null;
			}

			$tk[] = (float) $m[0];
			$i   += strlen( $m[0] );
		}

		return $tk;
	}

	/**
	 * Resolve one operation at a time, highest precedence first.
	 *
	 * @param array      $tk    Token list, consumed in place.
	 * @param array|null $steps Collected working, or null to discard it.
	 * @return float|null
	 */
	private static function reduce( array &$tk, ?array &$steps ): ?float {
		while ( count( $tk ) > 1 ) {
			$at   = -1;
			$best = 0;

			foreach ( $tk as $i => $t ) {
				if ( ! is_string( $t ) || ! isset( self::PRECEDENCE[ $t ] ) ) {
					continue;
				}

				// Only consider an operator whose operands are already numbers.
				// `-2^2` tokenises to [u, 2, ^, 2]: the negation outranks
				// nothing yet, because its operand is still the unresolved
				// `2^2`. Skipping it here is what defers it until last.
				$ready = 'u' === $t
					? isset( $tk[ $i + 1 ] ) && is_float( $tk[ $i + 1 ] )
					: ( isset( $tk[ $i - 1 ], $tk[ $i + 1 ] ) && is_float( $tk[ $i - 1 ] ) && is_float( $tk[ $i + 1 ] ) );

				if ( ! $ready ) {
					continue;
				}

				$p = self::PRECEDENCE[ $t ];

				// Right-associative operators take the last one at a given
				// precedence; the left-associative ones keep the first.
				if ( $p > $best || ( $p === $best && isset( self::RIGHT_ASSOCIATIVE[ $t ] ) ) ) {
					$best = $p;
					$at   = $i;
				}
			}

			if ( $at < 0 ) {
				return null;
			}

			if ( 'u' === $tk[ $at ] ) {
				$value = self::apply( 0.0, '-', $tk[ $at + 1 ] );

				if ( null === $value ) {
					return null;
				}

				array_splice( $tk, $at, 2, array( $value ) );
			} else {
				if ( $at < 1 ) {
					return null;
				}

				$value = self::apply( $tk[ $at - 1 ], $tk[ $at ], $tk[ $at + 1 ] );

				if ( null === $value ) {
					return null;
				}

				array_splice( $tk, $at - 1, 3, array( $value ) );
			}

			if ( null !== $steps ) {
				$steps[] = self::show( $tk );
			}
		}

		return ( isset( $tk[0] ) && is_float( $tk[0] ) ) ? $tk[0] : null;
	}

	/**
	 * One binary operation. Null for anything without a real answer.
	 */
	private static function apply( float $a, string $op, float $b ): ?float {
		switch ( $op ) {
			case '+':
				$v = $a + $b;
				break;
			case '-':
				$v = $a - $b;
				break;
			case '*':
				$v = $a * $b;
				break;
			case '/':
				// Dividing by zero is not an answer to report; it is a question
				// with no answer, and the student should be told that.
				if ( 0.0 === $b ) {
					return null;
				}
				$v = $a / $b;
				break;
			case '^':
				$v = pow( $a, $b );
				break;
			default:
				return null;
		}

		if ( ! is_float( $v ) || ! is_finite( $v ) ) {
			return null;
		}

		// Binary floating point makes 0.1 + 0.2 land just shy of 0.3.
		return round( $v, 10 );
	}

	/**
	 * Render a token list the way a student would write it.
	 */
	private static function show( array $tk ): string {
		$out = '';

		foreach ( $tk as $i => $t ) {
			if ( '*' === $t ) {
				$out .= ' × ';
			} elseif ( '/' === $t ) {
				$out .= ' ÷ ';
			} elseif ( 'u' === $t ) {
				// Prefix negation binds to what follows it, so no spaces around
				// it: "-2^2", not "- 2^2" which reads as subtraction.
				$out .= '-';
			} elseif ( '+' === $t || '-' === $t ) {
				$out .= ' ' . $t . ' ';
			} elseif ( is_float( $t ) && $t < 0 && isset( $tk[ $i + 1 ] ) && '^' === $tk[ $i + 1 ] ) {
				// A negative number about to be raised to a power has to be
				// bracketed, or the working contradicts the rule the answer
				// follows: (-2)^2 resolves its bracket and would then print
				// "-2^2 = 4", which reads back as -(2^2) = -4. The steps are
				// shown to a student, so each one has to parse as itself.
				$out .= '(' . self::num( $t ) . ')';
			} else {
				$out .= self::num( $t );
			}
		}

		return trim( (string) preg_replace( '/\s+/', ' ', $out ) );
	}

	/**
	 * A number without the trailing zeros a float would print.
	 *
	 * Always fixed notation, never exponential — which is the one place this and
	 * the JavaScript reference could disagree, because String() there switches to
	 * exponential below 1e-6 and at or above 1e21. Between those bounds the two
	 * are identical character for character, and scripts/calc-parity.js refuses
	 * to record a case outside them rather than claim a parity that does not
	 * hold. Nothing typed at a calculator gets near either bound.
	 *
	 * @param mixed $v Token.
	 */
	private static function num( $v ): string {
		if ( ! is_float( $v ) && ! is_int( $v ) ) {
			return (string) $v;
		}

		// IEEE negative zero: 0 * -5 is -0.0, which would otherwise print as
		// "-0" here and as "0" in the reference.
		if ( 0.0 === (float) $v ) {
			return '0';
		}

		$s = rtrim( rtrim( sprintf( '%.10F', $v ), '0' ), '.' );

		return ( '' === $s || '-' === $s ) ? '0' : $s;
	}

	/**
	 * num(), for callers outside this class.
	 *
	 * The REST layer and the parity harness both need to render a value exactly
	 * the way the working renders it; without this they would each grow their
	 * own formatter and the three would drift.
	 *
	 * @param float $v Value.
	 */
	public static function format( float $v ): string {
		return self::num( $v );
	}

	/**
	 * Everything AiCalc needs about an input, or null if it is not arithmetic
	 * and the model should answer it instead.
	 *
	 * The one entry point the REST route uses, so that "is this a sum?" and "what
	 * is the sum?" cannot be answered inconsistently by asking twice.
	 *
	 * @param string $text Raw student input.
	 * @return array{expression:string,steps:string[],value:float,display:string,mixed:bool}|null
	 */
	public static function evaluate( string $text ): ?array {
		$expression = self::expression( $text );

		if ( null === $expression ) {
			return null;
		}

		$solved = self::solve( $expression );

		if ( null === $solved ) {
			return null;
		}

		// Whether precedence actually mattered, which decides if the working is
		// worth a sentence of explanation. A leading minus is not an operator
		// for this purpose, so -4 * 2 does not count as mixed.
		$mixed = 1 === preg_match( '#[*/^]#', $expression )
			&& 1 === preg_match( '#[+\-]#', (string) preg_replace( '/^-/', '', $expression ) );

		return array(
			'expression' => $expression,
			'steps'      => $solved['steps'],
			'value'      => $solved['value'],
			'display'    => self::num( $solved['value'] ),
			'mixed'      => $mixed,
		);
	}

	/**
	 * Last position of a token, or -1.
	 *
	 * @param array $tk     Tokens.
	 * @param mixed $needle Token to find.
	 */
	private static function last_index_of( array $tk, $needle ): int {
		for ( $i = count( $tk ) - 1; $i >= 0; $i-- ) {
			if ( $needle === $tk[ $i ] ) {
				return $i;
			}
		}
		return -1;
	}

	/**
	 * First position of a token at or after $from, or -1.
	 *
	 * @param array $tk     Tokens.
	 * @param mixed $needle Token to find.
	 * @param int   $from   Start index.
	 */
	private static function index_of( array $tk, $needle, int $from = 0 ): int {
		$count = count( $tk );
		for ( $i = max( 0, $from ); $i < $count; $i++ ) {
			if ( $needle === $tk[ $i ] ) {
				return $i;
			}
		}
		return -1;
	}
}
