<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use WC_Order;
use WC_Order_Item_Product;
use Zarbalman\Core\Domain\ManagedFlow\Amounts;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Domain\PurchaseRequest\ExecutionMode;
use Zarbalman\Core\Domain\PurchaseRequest\RequestPricingPolicy;

final class ManagedOrderSnapshot {
	public static function matches( WC_Order $order, PurchaseRequest $request ): bool {
		$order_mode = (string) $order->get_meta( '_zbm_execution_mode', true );
		$order_mode = '' === $order_mode ? ExecutionMode::LIVE : $order_mode;
		$expected_mode = $request->isTest() ? ExecutionMode::TEST : ExecutionMode::LIVE;
		$route = self::route( $order );
		$valid_route = match ( $route ) {
			'online' => ExecutionMode::LIVE === $expected_mode && 'WC_ZPal' === $order->get_payment_method(),
			'manual_bank' => ExecutionMode::LIVE === $expected_mode && 'zbm_manual_bank' === $order->get_payment_method(),
			'test' => ExecutionMode::TEST === $expected_mode && 'WC_ZPal' === $order->get_payment_method(),
			default => false,
		};
		if (
			'yes' !== $order->get_meta( '_zbm_managed_order', true )
			|| 'zarbalman-core-spec3' !== $order->get_created_via()
			|| 'IRT' !== strtoupper( $order->get_currency() )
			|| ! $valid_route
			|| $order_mode !== $expected_mode
			|| ( 'test' === $route ) !== ( 'yes' === $order->get_meta( '_zbm_test_advance', true ) )
			|| (int) $order->get_customer_id() !== $request->buyerId()
			|| self::integer( $order->get_total() ) !== $request->depositToman()
			|| self::integer( $order->get_shipping_total() ) !== 0
			|| self::integer( $order->get_total_tax() ) !== 0
			|| self::integer( $order->get_discount_total() ) !== 0
			|| (int) $order->get_meta( '_zbm_request_id', true ) !== $request->id()
			|| ! hash_equals( $request->publicId(), (string) $order->get_meta( '_zbm_request_public_id', true ) )
			|| (int) $order->get_meta( '_zbm_vendor_id', true ) !== $request->vendorId()
			|| (int) $order->get_meta( '_dokan_vendor_id', true ) !== $request->vendorId()
			|| (int) $order->get_meta( '_zbm_estimated_total_toman', true ) !== $request->estimatedTotalToman()
			|| (int) $order->get_meta( '_zbm_deposit_toman', true ) !== $request->depositToman()
			|| (int) $order->get_meta( '_zbm_reference_weight_tenths', true ) !== $request->referenceWeightTenths()
			|| ! self::pricingMetadataMatches( $order, $request )
			|| (string) $order->get_meta( '_zbm_authoritative_wc_status', true ) !== $order->get_status()
			|| self::containsPrivateCustomerData( $order )
			|| count( $order->get_items( 'fee' ) ) > 0
			|| count( $order->get_items( 'shipping' ) ) > 0
			|| count( $order->get_items( 'coupon' ) ) > 0
		) {
			return false;
		}

		$items = array_values( $order->get_items( 'line_item' ) );
		if ( $request->isMultiline() ) {
			try {
				$lines = self::multilineProjection( $request );
			} catch ( \InvalidArgumentException | \OverflowException $error ) {
				return false;
			}
			if ( count( $items ) !== count( $lines ) || count( $order->get_items( 'tax' ) ) > 0 ) {
				return false;
			}
			foreach ( $items as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					return false;
				}
				$key = (string) $item->get_meta( '_zbm_request_line_id', true );
				if ( ! isset( $lines[ $key ] ) || ! self::multilineItemMatches( $item, $lines[ $key ], $request->vendorId() ) ) {
					return false;
				}
				unset( $lines[ $key ] ); // Duplicate keys cannot stand in for a missing row.
			}
			return array() === $lines;
		}
		if ( 1 !== count( $items ) || ! $items[0] instanceof WC_Order_Item_Product ) {
			return false;
		}
		return self::itemMatches( $items[0], $request );
	}

	/** Legacy orders without the marker are online/test according to their immutable execution marker. */
	public static function route( WC_Order $order ): string {
		$route = (string) $order->get_meta( '_zbm_payment_route', true );
		if ( '' !== $route ) {
			return in_array( $route, array( 'online', 'manual_bank', 'test' ), true ) ? $route : 'invalid';
		}
		return 'yes' === $order->get_meta( '_zbm_test_advance', true ) ? 'test' : 'online';
	}

	/** wc_create_order writes IP and user-agent before Core's second snapshot save. */
	public static function containsPrivateCustomerData( WC_Order $order, bool $allow_initial_woo_provenance = false ): bool {
		$values = array(
			$order->get_billing_first_name(),
			$order->get_billing_last_name(),
			$order->get_billing_company(),
			$order->get_billing_address_1(),
			$order->get_billing_address_2(),
			$order->get_billing_city(),
			$order->get_billing_state(),
			$order->get_billing_postcode(),
			$order->get_billing_country(),
			$order->get_billing_email(),
			$order->get_billing_phone(),
			$order->get_shipping_first_name(),
			$order->get_shipping_last_name(),
			$order->get_shipping_company(),
			$order->get_shipping_address_1(),
			$order->get_shipping_address_2(),
			$order->get_shipping_city(),
			$order->get_shipping_state(),
			$order->get_shipping_postcode(),
			$order->get_shipping_country(),
			$order->get_shipping_phone(),
			$order->get_customer_note(),
		);
		if ( ! $allow_initial_woo_provenance ) {
			$values[] = $order->get_customer_ip_address();
			$values[] = $order->get_customer_user_agent();
		}
		foreach ( $values as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return true;
			}
		}
		return false;
	}

	public static function itemMatches( WC_Order_Item_Product $item, PurchaseRequest $request ): bool {
		if ( $request->isMultiline() ) {
			try {
				$lines = self::multilineProjection( $request );
			} catch ( \InvalidArgumentException | \OverflowException $error ) {
				return false;
			}
			$key = (string) $item->get_meta( '_zbm_request_line_id', true );
			return isset( $lines[ $key ] ) && self::multilineItemMatches( $item, $lines[ $key ], $request->vendorId() );
		}
		return (int) $item->get_product_id() === $request->productId()
			&& (int) $item->get_variation_id() === $request->variationId()
			&& (int) $item->get_quantity() === $request->quantity()
			&& self::integer( $item->get_subtotal() ) === $request->estimatedGrossToman()
			&& self::integer( $item->get_total() ) === $request->depositToman()
			&& self::integer( $item->get_subtotal_tax() ) === 0
			&& self::integer( $item->get_total_tax() ) === 0
			&& (int) $item->get_meta( '_zbm_package_price_toman', true ) === $request->packagePriceToman()
			&& (int) $item->get_meta( '_zbm_nominal_package_weight_tenths', true ) === $request->nominalPackageWeightTenths()
			&& (int) $item->get_meta( '_zbm_reference_weight_tenths', true ) === $request->referenceWeightTenths()
			&& ( RequestPricingPolicy::LEGACY === $request->pricingVersion() || ! $item->get_meta( '_deposit', true ) );
	}

	/**
	 * The sole allocation used by both creation and guards. Shipping is included
	 * in row subtotals, never added as another Woo charge. Stable-key cumulative
	 * floors distribute rounding deterministically and telescope to exact totals.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function multilineProjection( PurchaseRequest $request ): array {
		$lines = $request->lines();
		if ( ! $request->isMultiline() || count( $lines ) < 2 ) {
			throw new \InvalidArgumentException( 'A multiline projection requires every request row.' );
		}
		$estimated = 0;
		$reference = 0;
		foreach ( $lines as $key => &$line ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, 'line:' ) || strlen( $key ) <= 5 || ! is_array( $line ) || ! is_string( $line['name'] ?? null ) ) {
				throw new \InvalidArgumentException( 'Invalid immutable request line identity.' );
			}
			foreach ( array( 'product_id', 'variation_id', 'quantity', 'package_price_toman', 'nominal_weight_tenths', 'reference_tenths', 'estimated_total' ) as $field ) {
				$value = $line[ $field ] ?? null;
				$integer = is_int( $value ) || is_string( $value ) ? self::integer( $value ) : null;
				if ( null === $integer || ( 'variation_id' !== $field && $integer < 1 ) ) {
					throw new \InvalidArgumentException( 'Invalid immutable request line amount or product.' );
				}
				$line[ $field ] = $integer;
			}
			if ( Amounts::multiply( $line['quantity'], $line['package_price_toman'] ) !== $line['estimated_total']
				|| Amounts::multiply( $line['quantity'], $line['nominal_weight_tenths'] ) !== $line['reference_tenths'] ) {
				throw new \InvalidArgumentException( 'Request line totals do not match their snapshots.' );
			}
			$estimated = RequestPricingPolicy::add( $estimated, $line['estimated_total'] );
			$reference = RequestPricingPolicy::add( $reference, $line['reference_tenths'] );
		}
		unset( $line );
		$gross = RequestPricingPolicy::add( $estimated, $request->estimatedShippingToman() );
		if ( $estimated !== $request->estimatedTotalToman() || $reference !== $request->referenceWeightTenths()
			|| $gross !== $request->estimatedGrossToman() || $request->depositToman() < 1 || $request->depositToman() > $gross ) {
			throw new \InvalidArgumentException( 'Request header does not match its immutable rows.' );
		}
		$weights = array_column( $lines, 'reference_tenths' );
		$shipping_weights = array_combine( array_keys( $lines ), $weights );
		$shipping = self::allocate( $request->estimatedShippingToman(), $shipping_weights, $reference );
		$gross_weights = array();
		foreach ( $lines as $key => &$line ) {
			$line['subtotal'] = RequestPricingPolicy::add( $line['estimated_total'], $shipping[ $key ] );
			$gross_weights[ $key ] = $line['subtotal'];
		}
		unset( $line );
		$deposits = self::allocate( $request->depositToman(), $gross_weights, $gross );
		foreach ( $lines as $key => &$line ) {
			$line['deposit'] = $deposits[ $key ];
		}
		unset( $line );
		return $lines;
	}

	/** @param array<string,int> $weights @return array<string,int> */
	private static function allocate( int $amount, array $weights, int $total ): array {
		ksort( $weights, SORT_STRING );
		$allocated = 0;
		$cumulative = 0;
		$result = array();
		foreach ( $weights as $key => $weight ) {
			$cumulative = RequestPricingPolicy::add( $cumulative, $weight );
			$next = self::proportionalFloor( $amount, $cumulative, $total );
			$result[ $key ] = $next - $allocated;
			$allocated = $next;
		}
		return $result;
	}

	/** floor(amount * weight / total), without overflowing the intermediate product. */
	private static function proportionalFloor( int $amount, int $weight, int $total ): int {
		$whole = intdiv( $amount, $total ) * $weight; // weight <= total, so <= amount.
		$part = $amount % $total;
		$quotient = 0;
		$remainder = 0;
		foreach ( str_split( decbin( $weight ) ) as $bit ) {
			$quotient *= 2;
			if ( $remainder >= $total - $remainder ) {
				$remainder -= $total - $remainder;
				++$quotient;
			} else {
				$remainder *= 2;
			}
			if ( '1' === $bit ) {
				if ( $remainder >= $total - $part ) {
					$remainder -= $total - $part;
					++$quotient;
				} else {
					$remainder += $part;
				}
			}
		}
		return $whole + $quotient;
	}

	/** @param array<string,mixed> $line */
	private static function multilineItemMatches( WC_Order_Item_Product $item, array $line, int $vendor_id ): bool {
		return (int) $item->get_product_id() === $line['product_id']
			&& (int) $item->get_variation_id() === $line['variation_id']
			&& self::integer( $item->get_quantity() ) === $line['quantity']
			&& $item->get_name() === $line['name']
			&& self::integer( $item->get_subtotal() ) === $line['subtotal']
			&& self::integer( $item->get_total() ) === $line['deposit']
			&& self::integer( $item->get_subtotal_tax() ) === 0
			&& self::integer( $item->get_total_tax() ) === 0
			&& self::integer( $item->get_meta( '_zbm_vendor_id', true ) ) === $vendor_id
			&& self::integer( $item->get_meta( '_zbm_package_price_toman', true ) ) === $line['package_price_toman']
			&& self::integer( $item->get_meta( '_zbm_nominal_package_weight_tenths', true ) ) === $line['nominal_weight_tenths']
			&& self::integer( $item->get_meta( '_zbm_reference_weight_tenths', true ) ) === $line['reference_tenths']
			&& self::integer( $item->get_meta( '_zbm_authoritative_line_deposit_toman', true ) ) === $line['deposit']
			&& ! $item->get_meta( '_deposit', true );
	}

	/** New-policy payment snapshots carry separate components, never a second shipping charge. */
	public static function pricingMetadataMatches( WC_Order $order, PurchaseRequest $request, bool $allow_empty = false ): bool {
		if ( $request->isMultiline() || RequestPricingPolicy::SHIPPING_INCLUSIVE === $request->pricingVersion() ) {
			$engine = (string) $order->get_meta( '_zbm_deposit_engine', true );
			if ( ( 'core' !== $engine && ! ( $allow_empty && '' === $engine ) ) || $order->get_meta( '_has_deposit', true ) ) {
				return false;
			}
		}
		$expected = array(
			'_zbm_pricing_version' => $request->pricingVersion(),
			'_zbm_estimated_shipping_toman' => $request->estimatedShippingToman(),
			'_zbm_shipping_rate_per_kg_toman' => $request->shippingRatePerKgToman(),
			'_zbm_estimated_gross_toman' => $request->estimatedGrossToman(),
		);
		foreach ( $expected as $key => $value ) {
			$actual = $order->get_meta( $key, true );
			if ( '' === (string) $actual && ( $allow_empty || RequestPricingPolicy::LEGACY === $request->pricingVersion() ) ) {
				continue;
			}
			if ( self::integer( $actual ) !== $value ) {
				return false;
			}
		}
		return true;
	}

	private static function integer( mixed $value ): ?int {
		$value = trim( (string) $value );
		if ( 1 !== preg_match( '/\A([0-9]+)(?:\.0+)?\z/', $value, $matches ) ) {
			return null;
		}
		$canonical = ltrim( $matches[1], '0' );
		if ( '' === $canonical ) {
			return 0;
		}
		$maximum = (string) PHP_INT_MAX;
		if ( strlen( $canonical ) > strlen( $maximum ) || ( strlen( $canonical ) === strlen( $maximum ) && strcmp( $canonical, $maximum ) > 0 ) ) {
			return null;
		}
		return (int) $canonical;
	}

	private function __construct() {}
}
