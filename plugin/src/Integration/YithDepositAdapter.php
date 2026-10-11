<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use WC_Order;
use WC_Order_Item_Product;
use Zarbalman\Core\Domain\PurchaseRequest\PricingSnapshot;
use Zarbalman\Core\Domain\PurchaseRequest\RequestPricingPolicy;

final class YithDepositAdapter {
	public function register(): void {
		// These guards must run after YITH and compatibility extensions: a later
		// filter must not turn a managed physical order into a virtual order or
		// create a second, independently payable balance sub-order.
		add_filter( 'yith_wcdp_virtual_on_deposit', array( $this, 'keepManagedOrderPhysical' ), PHP_INT_MAX, 2 );
		add_filter( 'yith_wcdp_skip_suborder_creation', array( $this, 'skipManagedBalanceCreation' ), PHP_INT_MAX, 6 );
	}

	public function decorate( WC_Order $order, WC_Order_Item_Product $item, PricingSnapshot $pricing, int $pricing_version = RequestPricingPolicy::LEGACY ): void {
		$item->update_meta_data( '_zbm_authoritative_line_deposit_toman', $pricing->depositToman() );
		$order->update_meta_data( '_zbm_spec4_balance_deferred', 'yes' );
		if ( RequestPricingPolicy::SHIPPING_INCLUSIVE === $pricing_version ) {
			// An aggregate rounded shipping deposit cannot in general be expressed
			// exactly as YITH's per-unit decimal. Do not publish competing figures
			// or mark this Core payment as a YITH deposit. Legacy orders are untouched.
			$order->update_meta_data( '_zbm_deposit_engine', 'core' );
			return;
		}
		if ( RequestPricingPolicy::LEGACY !== $pricing_version ) {
			throw new \InvalidArgumentException( 'Unsupported YITH pricing compatibility version.' );
		}
		// YITH stores these values per unit and multiplies them by quantity.
		// Compatibility decimals are exact strings derived from integer Toman;
		// Core's authoritative payable amount remains an integer snapshot.
		$unit_deposit = $this->tenthsString( $pricing->packagePriceToman() );
		$unit_balance = $this->tenthsString( $this->checkedMultiply( $pricing->packagePriceToman(), 9 ) );
		$item->update_meta_data( '_deposit', true );
		$item->update_meta_data( '_deposit_type', 'rate' );
		$item->update_meta_data( '_deposit_amount', 0 );
		$item->update_meta_data( '_deposit_rate', 10 );
		$item->update_meta_data( '_deposit_value', $unit_deposit );
		$item->update_meta_data( '_deposit_balance', $unit_balance );
		$item->update_meta_data( '_deposit_balance_shipping', 'none' );
		$item->update_meta_data( '_zbm_yith_values_are_per_unit', 'yes' );
		$order->update_meta_data( '_has_deposit', true );
	}

	public function keepManagedOrderPhysical( mixed $virtual, mixed $order ): mixed {
		return $order instanceof WC_Order && 'yes' === $order->get_meta( '_zbm_managed_order', true ) ? false : $virtual;
	}

	public function skipManagedBalanceCreation( bool $skip, int $item_id, mixed $item, int $order_id, mixed $order, mixed $product ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $skip || ( $order instanceof WC_Order && 'yes' === $order->get_meta( '_zbm_managed_order', true ) );
	}

	private function tenthsString( int $tenths ): string {
		if ( $tenths < 0 ) {
			throw new \InvalidArgumentException( 'YITH compatibility amount must not be negative.' );
		}
		$whole = intdiv( $tenths, 10 );
		$remainder = $tenths % 10;
		return 0 === $remainder ? (string) $whole : $whole . '.' . $remainder;
	}

	private function checkedMultiply( int $left, int $right ): int {
		if ( $left < 0 || $right < 0 || ( 0 !== $left && $right > intdiv( PHP_INT_MAX, $left ) ) ) {
			throw new \OverflowException( 'YITH compatibility amount overflow.' );
		}
		return $left * $right;
	}
}
