<?php

declare(strict_types=1);

namespace Zarbalman\Core\Notification;

use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Support\IranianMobile;

final class RecipientResolver {
	public function mobile( string $role, int $user_id, ?PurchaseRequest $request ): string {
		if ( $user_id < 1 ) {
			return '';
		}
		$profile = get_user_meta( $user_id, 'dokan_profile_settings', true );
		$profile_phone = is_array( $profile ) ? ( $profile['phone'] ?? '' ) : '';
		$candidates = array();
		if ( 'vendor' === $role ) {
			$candidates[] = $profile_phone;
		}
		// Preserve existing valid field priorities; only extend missing/invalid cases.
		foreach ( array( 'billing_phone', 'mobile', 'phone_number', 'phone' ) as $meta_key ) {
			$candidates[] = get_user_meta( $user_id, $meta_key, true );
		}
		if ( 'vendor' !== $role ) {
			// Dokan's registration stores this field without creating billing_phone.
			// The very same account may buy from a different seller.
			$candidates[] = $profile_phone;
		}
		$mobile = '';
		foreach ( $candidates as $candidate ) {
			if ( IranianMobile::isValid( $candidate ) ) {
				$mobile = IranianMobile::normalize( $candidate );
				break;
			}
		}
		// Keep the established integration hook and respect an intentional empty override.
		$mobile = apply_filters( 'zbm_core_user_mobile', $mobile, $user_id, $role );
		return IranianMobile::isValid( $mobile ) ? IranianMobile::normalize( $mobile ) : '';
	}

	public function dashboardUrl( string $role, ?PurchaseRequest $request ): string {
		if ( 'vendor' === $role ) {
			$base = function_exists( 'dokan_get_navigation_url' ) ? dokan_get_navigation_url( 'purchase-requests' ) : home_url( '/approval-by-supplier/' );
		} elseif ( 'admin' === $role ) {
			$base = admin_url( 'admin.php?page=zbm-core-requests' );
		} else {
			$base = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'purchase-requests' ) : home_url( '/order-review-by-buyer/' );
		}
		return $request ? add_query_arg( 'request', $request->id(), $base ) : $base;
	}
}
