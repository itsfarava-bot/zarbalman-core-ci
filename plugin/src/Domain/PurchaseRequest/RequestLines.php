<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\PurchaseRequest;

use InvalidArgumentException;
use Zarbalman\Core\Domain\ManagedFlow\Amounts;

/** Immutable, bounded product snapshots. Monetary values are integer Toman. */
final class RequestLines {
	public const MAX_LINES = 20;

	public static function validate( array $lines ): void {
		if ( count( $lines ) < 1 || count( $lines ) > self::MAX_LINES ) {
			throw new InvalidArgumentException( 'Invalid request line count.' );
		}
		$products = array();
		foreach ( $lines as $key => $line ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/\Aline:[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/', $key ) || ! is_array( $line ) ) {
				throw new InvalidArgumentException( 'Invalid request line identity.' );
			}
			$expected = array( 'product_id', 'variation_id', 'quantity', 'package_price_toman', 'nominal_weight_tenths', 'reference_tenths', 'estimated_total', 'name' );
			$keys = array_keys( $line );
			sort( $keys ); sort( $expected );
			if ( $keys !== $expected ) { throw new InvalidArgumentException( 'Invalid line snapshot fields.' ); }
			foreach ( array_diff( $expected, array( 'name' ) ) as $field ) {
				if ( ! is_int( $line[ $field ] ) || $line[ $field ] < ( 'variation_id' === $field ? 0 : 1 ) ) {
					throw new InvalidArgumentException( 'Invalid line snapshot integer.' );
				}
			}
			if ( ! is_string( $line['name'] ) || '' === trim( $line['name'] ) || strlen( $line['name'] ) > 2000 ) { throw new InvalidArgumentException( 'Invalid line label.' ); }
			if ( Amounts::multiply( $line['quantity'], $line['package_price_toman'] ) !== $line['estimated_total'] || Amounts::multiply( $line['quantity'], $line['nominal_weight_tenths'] ) !== $line['reference_tenths'] ) {
				throw new InvalidArgumentException( 'Inconsistent line pricing snapshot.' );
			}
			$identity = $line['product_id'] . ':' . $line['variation_id'];
			if ( isset( $products[ $identity ] ) ) { throw new InvalidArgumentException( 'Duplicate request product.' ); }
			$products[ $identity ] = true;
		}
	}

	public static function totals( array $lines ): array {
		self::validate( $lines );
		$result = array( 'quantity' => 0, 'reference_tenths' => 0, 'estimated_total' => 0 );
		foreach ( $lines as $line ) {
			foreach ( $result as $field => $value ) { $result[ $field ] = RequestPricingPolicy::add( $value, $line[ $field ] ); }
		}
		return $result;
	}
}
