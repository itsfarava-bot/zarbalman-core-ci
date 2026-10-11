<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\PurchaseRequest;

use InvalidArgumentException;
use OverflowException;

final class PricingSnapshot {
	private function __construct(
		private readonly int $quantity,
		private readonly int $package_price_toman,
		private readonly int $nominal_package_weight_tenths,
		private readonly int $reference_weight_tenths,
		private readonly int $estimated_total_toman,
		private readonly int $shipping_rate_per_kg_toman,
		private readonly int $estimated_shipping_toman,
		private readonly int $deposit_toman
	) {}

	public static function fromProduct( int $quantity, int $package_price_toman, int $nominal_weight_tenths, int $shipping_rate_per_kg_toman = 0 ): self {
		if ( $quantity < 1 || $package_price_toman < 1 || $nominal_weight_tenths < 1 ) {
			throw new InvalidArgumentException( 'Pricing values must be positive integers.' );
		}

		$reference_weight = self::checkedMultiply( $quantity, $nominal_weight_tenths );
		$estimated_total  = self::checkedMultiply( $quantity, $package_price_toman );
		$shipping         = ShippingQuote::estimateToman( $reference_weight, $shipping_rate_per_kg_toman );
		$gross            = self::checkedAdd( $estimated_total, $shipping );
		$deposit          = self::roundHalfUpRatio( $gross, 1, 10 );
		// Both the platform and vendor provisional shares must be representable as
		// positive integer-Toman ledger legs. A smaller deposit cannot preserve
		// the approved 10/90 split without silently dropping one side.
		if ( $deposit < 10 ) {
			throw new InvalidArgumentException( 'Calculated deposit must be at least ten Toman.' );
		}

		return new self(
			$quantity,
			$package_price_toman,
			$nominal_weight_tenths,
			$reference_weight,
			$estimated_total,
			$shipping_rate_per_kg_toman,
			$shipping,
			$deposit
		);
	}

	public function quantity(): int {
		return $this->quantity;
	}

	public static function fromLines( array $lines, int $shipping_rate_per_kg_toman = 0 ): self {
		$totals = RequestLines::totals( $lines );
		$first = reset( $lines );
		$shipping = ShippingQuote::estimateToman( $totals['reference_tenths'], $shipping_rate_per_kg_toman );
		$gross = self::checkedAdd( $totals['estimated_total'], $shipping );
		$deposit = self::roundHalfUpRatio( $gross, 1, 10 );
		if ( $deposit < 10 ) { throw new InvalidArgumentException( 'Calculated deposit must be at least ten Toman.' ); }
		return new self( $totals['quantity'], $first['package_price_toman'], $first['nominal_weight_tenths'], $totals['reference_tenths'], $totals['estimated_total'], $shipping_rate_per_kg_toman, $shipping, $deposit );
	}

	public function packagePriceToman(): int {
		return $this->package_price_toman;
	}

	public function nominalPackageWeightTenths(): int {
		return $this->nominal_package_weight_tenths;
	}

	public function referenceWeightTenths(): int {
		return $this->reference_weight_tenths;
	}

	public function estimatedTotalToman(): int {
		return $this->estimated_total_toman;
	}

	public function depositToman(): int {
		return $this->deposit_toman;
	}

	public function shippingRatePerKgToman(): int { return $this->shipping_rate_per_kg_toman; }
	public function estimatedShippingToman(): int { return $this->estimated_shipping_toman; }
	public function estimatedGrossToman(): int {
		return self::checkedAdd( $this->estimated_total_toman, $this->estimated_shipping_toman );
	}

	public function finalTotalToman( int $final_weight_tenths ): int {
		if ( $final_weight_tenths < 1 ) {
			throw new InvalidArgumentException( 'Final weight must be positive.' );
		}

		return self::roundHalfUpRatio(
			$this->estimated_total_toman,
			$final_weight_tenths,
			$this->reference_weight_tenths
		);
	}

	public function requiresAdminReviewForWeight( int $final_weight_tenths ): bool {
		if ( $final_weight_tenths < 1 ) {
			return true;
		}

		return self::checkedMultiply( $final_weight_tenths, 100 ) < self::checkedMultiply( $this->reference_weight_tenths, 75 )
			|| self::checkedMultiply( $final_weight_tenths, 100 ) > self::checkedMultiply( $this->reference_weight_tenths, 125 );
	}

	private static function roundHalfUpRatio( int $base, int $numerator, int $denominator ): int {
		if ( $base < 0 || $numerator < 0 || $denominator < 1 ) {
			throw new InvalidArgumentException( 'Invalid integer ratio.' );
		}

		$product = self::checkedMultiply( $base, $numerator );
		$rounding = intdiv( $denominator, 2 );
		if ( $product > PHP_INT_MAX - $rounding ) {
			throw new OverflowException( 'Integer rounding overflow.' );
		}
		return intdiv( $product + $rounding, $denominator );
	}

	private static function checkedMultiply( int $left, int $right ): int {
		if ( $left !== 0 && $right > intdiv( PHP_INT_MAX, $left ) ) {
			throw new OverflowException( 'Integer calculation overflow.' );
		}

		return $left * $right;
	}

	private static function checkedAdd( int $left, int $right ): int {
		if ( $left > PHP_INT_MAX - $right ) {
			throw new OverflowException( 'Integer addition overflow.' );
		}
		return $left + $right;
	}
}
