<?php
/**
 * Safe arithmetic for calculated fields (no eval).
 *
 * Grammar: numbers, {variables}, + - * / ( ), unary minus and the functions
 * round(x[, digits]), min(a, b, …), max(a, b, …), ceil(x), floor(x), abs(x).
 * The same rules are implemented in assets/frontend/src/formula.js.
 *
 * @package Bookora
 */

namespace Bookora\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Formula parser and evaluator.
 */
final class Formula {

	public const FUNCTIONS = array( 'round', 'min', 'max', 'ceil', 'floor', 'abs' );

	private const MAX_LENGTH = 500;

	/**
	 * Clean a formula for storage. Returns '' if it cannot be parsed.
	 *
	 * @param mixed $formula Raw formula.
	 */
	public static function sanitize( $formula ): string {
		$formula = trim( mb_substr( is_string( $formula ) ? $formula : '', 0, self::MAX_LENGTH ) );
		$formula = str_replace( array( '×', '÷' ), array( '*', '/' ), $formula );
		if ( '' === $formula || null === self::rpn( $formula ) ) {
			return '';
		}
		return $formula;
	}

	/**
	 * Variable names used by a formula.
	 *
	 * @param string $formula Formula.
	 * @return string[]
	 */
	public static function variables( string $formula ): array {
		preg_match_all( '/\{([a-z0-9_]+)\}/', $formula, $m );
		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * Evaluate. Unknown variables count as 0, division by zero gives 0, errors give 0.
	 *
	 * @param string               $formula Formula.
	 * @param array<string, float> $vars    Variables.
	 */
	public static function evaluate( string $formula, array $vars ): float {
		$rpn = self::rpn( $formula );
		if ( null === $rpn ) {
			return 0.0;
		}
		$stack = array();
		foreach ( $rpn as [ $kind, $value, $argc ] ) {
			switch ( $kind ) {
				case 'num':
					$stack[] = (float) $value;
					break;
				case 'var':
					$stack[] = (float) ( $vars[ $value ] ?? 0 );
					break;
				case 'neg':
					$stack[] = - (float) array_pop( $stack );
					break;
				case 'op':
					$b = (float) array_pop( $stack );
					$a = (float) array_pop( $stack );
					switch ( $value ) {
						case '+':
							$stack[] = $a + $b;
							break;
						case '-':
							$stack[] = $a - $b;
							break;
						case '*':
							$stack[] = $a * $b;
							break;
						default:
							$stack[] = 0.0 === $b ? 0.0 : $a / $b;
					}
					break;
				case 'fn':
					$args    = $argc ? array_splice( $stack, -$argc ) : array();
					$stack[] = self::call( $value, $args );
					break;
			}
		}
		$result = (float) ( $stack[0] ?? 0 );
		return is_finite( $result ) ? $result : 0.0;
	}

	/**
	 * Built-in functions.
	 *
	 * @param string  $name Function.
	 * @param float[] $args Arguments.
	 */
	private static function call( string $name, array $args ): float {
		$x = (float) ( $args[0] ?? 0 );
		switch ( $name ) {
			case 'round':
				return round( $x, max( 0, min( 6, (int) ( $args[1] ?? 0 ) ) ) );
			case 'min':
				return $args ? (float) min( $args ) : 0.0;
			case 'max':
				return $args ? (float) max( $args ) : 0.0;
			case 'ceil':
				return ceil( $x );
			case 'floor':
				return floor( $x );
			default:
				return abs( $x );
		}
	}

	/**
	 * Tokens: [kind, value].
	 *
	 * @param string $formula Formula.
	 * @return list<array{0:string, 1:string}>|null
	 */
	private static function tokens( string $formula ): ?array {
		$tokens = array();
		$len    = strlen( $formula );
		$i      = 0;
		while ( $i < $len ) {
			$c = $formula[ $i ];
			if ( ctype_space( $c ) ) {
				++$i;
			} elseif ( preg_match( '/\G\d+(?:\.\d+)?/', $formula, $m, 0, $i ) ) {
				$tokens[] = array( 'num', $m[0] );
				$i       += strlen( $m[0] );
			} elseif ( preg_match( '/\G\{([a-z0-9_]+)\}/', $formula, $m, 0, $i ) ) {
				$tokens[] = array( 'var', $m[1] );
				$i       += strlen( $m[0] );
			} elseif ( preg_match( '/\G([a-z]+)\s*\(/', $formula, $m, 0, $i ) ) {
				if ( ! in_array( $m[1], self::FUNCTIONS, true ) ) {
					return null;
				}
				$tokens[] = array( 'fn', $m[1] );
				$tokens[] = array( '(', '(' );
				$i       += strlen( $m[0] );
			} elseif ( false !== strpos( '+-*/(),', $c ) ) {
				$tokens[] = array( $c, $c );
				++$i;
			} else {
				return null;
			}
		}
		return $tokens;
	}

	/**
	 * Shunting-yard: tokens → reverse polish notation, with syntax check.
	 *
	 * @param string $formula Formula.
	 * @return list<array{0:string, 1:string, 2:int}>|null
	 */
	private static function rpn( string $formula ): ?array {
		$tokens = self::tokens( $formula );
		if ( null === $tokens || ! $tokens || count( $tokens ) > 300 ) {
			return null;
		}
		$prec   = array(
			'+'   => 1,
			'-'   => 1,
			'*'   => 2,
			'/'   => 2,
			'neg' => 3,
		);
		$out    = array();
		$ops    = array();
		$argc   = array();
		$expect = true; // Expecting an operand.
		$depth  = 0;

		foreach ( $tokens as [ $kind, $value ] ) {
			if ( 'num' === $kind || 'var' === $kind ) {
				if ( ! $expect ) {
					return null;
				}
				$out[]  = array( $kind, $value, 0 );
				$expect = false;
			} elseif ( 'fn' === $kind ) {
				if ( ! $expect ) {
					return null;
				}
				$ops[] = array( 'fn', $value );
			} elseif ( '(' === $kind ) {
				if ( ! $expect ) {
					return null;
				}
				$ops[]  = array( '(', '(' );
				$argc[] = 1;
				++$depth;
			} elseif ( ',' === $kind ) {
				if ( $expect || ! $argc ) {
					return null;
				}
				while ( $ops && '(' !== end( $ops )[0] ) {
					$out[] = self::op_out( array_pop( $ops ) );
				}
				++$argc[ count( $argc ) - 1 ];
				$expect = true;
			} elseif ( ')' === $kind ) {
				if ( $expect || $depth < 1 ) {
					return null;
				}
				while ( $ops && '(' !== end( $ops )[0] ) {
					$out[] = self::op_out( array_pop( $ops ) );
				}
				array_pop( $ops );
				$n = array_pop( $argc );
				--$depth;
				if ( $ops && 'fn' === end( $ops )[0] ) {
					$fn    = array_pop( $ops );
					$out[] = array( 'fn', $fn[1], $n );
				} elseif ( $n > 1 ) {
					return null;
				}
				$expect = false;
			} else {
				// Operator.
				if ( $expect ) {
					if ( '-' === $kind ) {
						$ops[] = array( 'neg', 'neg' );
						continue;
					}
					if ( '+' === $kind ) {
						continue;
					}
					return null;
				}
				while ( $ops && isset( $prec[ end( $ops )[1] ] ) && $prec[ end( $ops )[1] ] >= $prec[ $kind ] ) {
					$out[] = self::op_out( array_pop( $ops ) );
				}
				$ops[]  = array( 'op', $kind );
				$expect = true;
			}
		}
		if ( $expect || 0 !== $depth ) {
			return null;
		}
		while ( $ops ) {
			$op = array_pop( $ops );
			if ( '(' === $op[0] || 'fn' === $op[0] ) {
				return null;
			}
			$out[] = self::op_out( $op );
		}
		return $out;
	}

	/**
	 * Operator stack entry → output entry.
	 *
	 * @param array{0:string, 1:string} $op Operator.
	 * @return array{0:string, 1:string, 2:int}
	 */
	private static function op_out( array $op ): array {
		return 'neg' === $op[0] ? array( 'neg', 'neg', 1 ) : array( 'op', $op[1], 2 );
	}

	/**
	 * Numeric value of a field for formulas.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Clean value.
	 */
	public static function field_number( array $field, $value ): float {
		$prices = array();
		foreach ( (array) ( $field['options'] ?? array() ) as $option ) {
			$prices[ (string) $option['value'] ] = isset( $option['price'] ) ? (float) $option['price'] : ( is_numeric( $option['value'] ) ? (float) $option['value'] : 0.0 );
		}
		switch ( $field['type'] ) {
			case 'select':
			case 'radio':
			case 'checkboxes':
				$sum = 0.0;
				foreach ( (array) $value as $v ) {
					$sum += $prices[ (string) $v ] ?? 0.0;
				}
				return $sum;
			case 'consent':
				return empty( $value ) ? 0.0 : 1.0;
			default:
				return is_numeric( $value ) ? (float) $value : 0.0;
		}
	}

	/**
	 * Compute all calculated fields of a form in field order (a formula may use earlier results).
	 *
	 * @param array<int, array<string, mixed>> $fields  Fields.
	 * @param array<string, mixed>             $clean   Clean values by key (only visible fields).
	 * @param array<string, float>             $extra   Extra variables (service_price, service_duration).
	 * @return array<string, float> Results by field key.
	 */
	public static function compute( array $fields, array $clean, array $extra = array() ): array {
		$vars = $extra;
		foreach ( $fields as $field ) {
			if ( ! empty( $field['key'] ) && 'calculation' !== $field['type'] && array_key_exists( $field['key'], $clean ) ) {
				$vars[ $field['key'] ] = self::field_number( $field, $clean[ $field['key'] ] );
			}
		}
		$results = array();
		foreach ( $fields as $field ) {
			if ( 'calculation' === $field['type'] && ! empty( $field['key'] ) && array_key_exists( $field['key'], $clean ) ) {
				$value                    = round( self::evaluate( (string) ( $field['formula'] ?? '' ), $vars ), max( 0, min( 4, (int) ( $field['decimals'] ?? 2 ) ) ) );
				$vars[ $field['key'] ]    = $value;
				$results[ $field['key'] ] = $value;
			}
		}
		return $results;
	}

	/**
	 * "74,50 €" – number in site locale with prefix/suffix.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param float                $value Value.
	 */
	public static function format( array $field, float $value ): string {
		$decimals = max( 0, min( 4, (int) ( $field['decimals'] ?? 2 ) ) );
		return trim( (string) ( $field['prefix'] ?? '' ) . ' ' . number_format_i18n( $value, $decimals ) . ' ' . (string) ( $field['suffix'] ?? '' ) );
	}
}
