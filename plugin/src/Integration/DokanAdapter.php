<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use WC_Order;

final class DokanAdapter {
	public function register(): void {
		add_filter( 'zbm_core_vendor_owns_order', array( $this, 'vendor_owns_order' ), 10, 3 );
	}

	public function vendor_owns_order( bool $owns, int $vendor_user_id, WC_Order $order ): bool {
		if ( $owns || $vendor_user_id < 1 ) {
			return $owns;
		}

		if ( function_exists( 'dokan_get_seller_ids_by' ) ) {
			$seller_ids = array_values(
				array_unique(
					array_filter( array_map( 'absint', (array) dokan_get_seller_ids_by( $order->get_id() ) ) )
				)
			);
			if ( array() !== $seller_ids ) {
				return 1 === count( $seller_ids ) && $vendor_user_id === $seller_ids[0];
			}
		}

		if ( function_exists( 'dokan_get_seller_id_by_order' ) ) {
			return $vendor_user_id === absint( dokan_get_seller_id_by_order( $order ) );
		}

		return false;
	}
}
