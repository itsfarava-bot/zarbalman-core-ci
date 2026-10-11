<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\PurchaseRequest;

use InvalidArgumentException;
use OverflowException;

/** Integer-only shipping arithmetic. Weight is stored in tenths of a kilogram. */
final class ShippingQuote {
	public static function estimateForQuantity( int $quantity, int $nominal_weight_tenths, int $rate_per_kg_toman ): int {
		if ( $quantity < 1 || $nominal_weight_tenths < 1 ) {
			throw new InvalidArgumentException( 'Quantity and package weight must be positive.' );
		}

		return self::estimateToman( self::checkedMultiply( $quantity, $nominal_weight_tenths ), $rate_per_kg_toman );
	}

	public static function estimateToman( int $weight_tenths, int $rate_per_kg_toman ): int {
		if ( $weight_tenths < 0 || $rate_per_kg_toman < 0 ) {
			throw new InvalidArgumentException( 'Shipping weight and rate cannot be negative.' );
		}

		if ( 0 === $weight_tenths || 0 === $rate_per_kg_toman ) {
			return 0;
		}

		$product = self::checkedMultiply( $weight_tenths, $rate_per_kg_toman );
		if ( $product > PHP_INT_MAX - 5 ) {
			throw new OverflowException( 'Shipping rounding overflow.' );
		}

		return intdiv( $product + 5, 10 );
	}

	private static function checkedMultiply( int $left, int $right ): int {
		if ( $left !== 0 && $right > intdiv( PHP_INT_MAX, $left ) ) {
			throw new OverflowException( 'Shipping calculation overflow.' );
		}

		return $left * $right;
	}
}
