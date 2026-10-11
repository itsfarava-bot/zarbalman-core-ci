<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

final class DokanVendorEligibility {
	public function isApprovedVendor( int $user_id ): bool {
		if (
			$user_id < 1
			|| ! function_exists( 'dokan_is_user_seller' )
			|| ! function_exists( 'dokan_is_seller_enabled' )
		) {
			return false;
		}

		return (bool) dokan_is_user_seller( $user_id )
			&& (bool) dokan_is_seller_enabled( $user_id );
	}
}
