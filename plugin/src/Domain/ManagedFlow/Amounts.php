<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\ManagedFlow;

use InvalidArgumentException;
use OverflowException;

/** Integer IRT and integer tenths of kg. No floating-point money or weights. */
final class Amounts {
	/** Parse an ASCII decimal string (or whole-kg integer); positive, at most one decimal. */
	public static function weight( mixed $value ): int {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			throw new InvalidArgumentException( 'Weight must be an exact decimal.' );
		}
		if ( 1 !== preg_match( '/\A([0-9]+)(?:\.([0-9]))?\z/', (string) $value, $match ) ) {
			throw new InvalidArgumentException( 'Weight requires at most one decimal.' );
		}
		$digits = ltrim( $match[1] . ( $match[2] ?? '0' ), '0' );
		$maximum = (string) PHP_INT_MAX;
		if ( strlen( $digits ) > strlen( $maximum ) || ( strlen( $digits ) === strlen( $maximum ) && strcmp( $digits, $maximum ) > 0 ) ) {
			throw new OverflowException( 'Weight exceeds integer capacity.' );
		}
		if ( '' === $digits ) {
			throw new InvalidArgumentException( 'Weight must be positive.' );
		}
		return (int) $digits;
	}

	/** Checked product of nonnegative integers; throws before PHP can produce a float. */
	public static function multiply( int $left, int $right ): int {
		if ( $left < 0 || $right < 0 ) {
			throw new InvalidArgumentException( 'Negative multiplication input.' );
		}
		if ( $left > 0 && $right > intdiv( PHP_INT_MAX, $left ) ) {
			throw new OverflowException( 'Integer multiplication overflow.' );
		}
		return $left * $right;
	}

	/** Final IRT = estimated IRT × final tenths / reference tenths, rounded half-up. */
	public static function finalTotal( int $estimated_total, int $reference_tenths, int $final_tenths ): int {
		if ( $estimated_total < 1 || $reference_tenths < 1 || $final_tenths < 1 ) {
			throw new InvalidArgumentException( 'Final pricing inputs must be positive.' );
		}
		return self::divideHalfUp( self::multiply( $estimated_total, $final_tenths ), $reference_tenths );
	}

	/** Signed remaining IRT. Negative means overpayment review, never automatic refund. */
	public static function remaining( int $total, int $deposit, int $balance = 0 ): int {
		if ( min( $total, $deposit, $balance ) < 0 ) {
			throw new InvalidArgumentException( 'Confirmed funds cannot be negative.' );
		}
		if ( $deposit > PHP_INT_MAX - $balance ) {
			throw new OverflowException( 'Confirmed funds overflow.' );
		}
		return $total - ( $deposit + $balance );
	}

	/** @return array{commission:int,net:int} Approved 10% half-up; net is exact complement. */
	public static function commission( int $total ): array {
		if ( $total < 0 ) {
			throw new InvalidArgumentException( 'Negative total.' );
		}
		$commission = self::divideHalfUp( $total, 10 );
		return array( 'commission' => $commission, 'net' => $total - $commission );
	}

	/** Strictly more than 25% deviation; this is an acknowledgment warning, not forced review. */
	public static function warning( int $reference_tenths, int $actual_tenths ): bool {
		if ( min( $reference_tenths, $actual_tenths ) < 1 ) {
			throw new InvalidArgumentException( 'Warning weights must be positive.' );
		}
		return abs( $actual_tenths - $reference_tenths ) > intdiv( $reference_tenths, 4 );
	}

	private static function divideHalfUp( int $numerator, int $denominator ): int {
		$whole = intdiv( $numerator, $denominator );
		$threshold = intdiv( $denominator, 2 ) + ( $denominator % 2 );
		return $whole + ( $numerator % $denominator >= $threshold ? 1 : 0 );
	}
}
