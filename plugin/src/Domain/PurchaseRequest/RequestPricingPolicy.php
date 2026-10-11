<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\PurchaseRequest;

use InvalidArgumentException;
use OverflowException;
use Zarbalman\Core\Domain\ManagedFlow\Amounts;

/** Version selection and component composition, using the existing integer calculators. */
final class RequestPricingPolicy {
	public const LEGACY = 1;
	public const SHIPPING_INCLUSIVE = 2;
	public const OPERATIONAL_LOCK = 2;

	/** @return array{product:int,shipping:int,total:int,commission:int,net:int,remaining:int} */
	public static function finalAmounts( PurchaseRequest $request, int $weight_tenths, int $deposit, int $balance = 0 ): array {
		if ( $request->isMultiline() ) { throw new InvalidArgumentException( 'Multiline pricing requires a separate weight for every product.' ); }
		$product = Amounts::finalTotal( $request->estimatedTotalToman(), $request->referenceWeightTenths(), $weight_tenths );
		$shipping = self::SHIPPING_INCLUSIVE === $request->pricingVersion()
			? ShippingQuote::estimateToman( $weight_tenths, $request->shippingRatePerKgToman() ) : 0;
		$total = self::add( $product, $shipping );
		$split = Amounts::commission( $total );
		return array( 'product' => $product, 'shipping' => $shipping, 'total' => $total,
			'commission' => $split['commission'], 'net' => $split['net'],
			'remaining' => Amounts::remaining( $total, $deposit, $balance ) );
	}

	public static function add( int $left, int $right ): int {
		if ( $left < 0 || $right < 0 ) {
			throw new InvalidArgumentException( 'Negative pricing component.' );
		}
		if ( $left > PHP_INT_MAX - $right ) {
			throw new OverflowException( 'Pricing component sum overflow.' );
		}
		return $left + $right;
	}

	/** Price each product against its own immutable reference, then charge shipping once. */
	public static function finalLineAmounts( PurchaseRequest $request, array $weights, int $deposit, int $balance = 0 ): array {
		$lines = $request->lines();
		if ( array_keys( $weights ) !== array_keys( $lines ) ) { throw new InvalidArgumentException( 'Final weights must cover every request line in snapshot order.' ); }
		$product = 0;
		$weight = 0;
		foreach ( $lines as $key => $line ) {
			if ( ! is_int( $weights[ $key ] ) || $weights[ $key ] < 1 ) { throw new InvalidArgumentException( 'Missing final line weight.' ); }
			$product = self::add( $product, Amounts::finalTotal( $line['estimated_total'], $line['reference_tenths'], $weights[ $key ] ) );
			$weight = self::add( $weight, $weights[ $key ] );
		}
		$shipping = self::SHIPPING_INCLUSIVE === $request->pricingVersion() ? ShippingQuote::estimateToman( $weight, $request->shippingRatePerKgToman() ) : 0;
		$total = self::add( $product, $shipping );
		$split = Amounts::commission( $total );
		return array( 'product' => $product, 'shipping' => $shipping, 'total' => $total, 'commission' => $split['commission'], 'net' => $split['net'], 'remaining' => Amounts::remaining( $total, $deposit, $balance ) );
	}

	private function __construct() {}
}
