<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Workflow;

use WC_Order;
use WP_Error;
use Zarbalman\Core\Security\AdminCapabilities;

final class AuthorizationPolicy {
	public function authorize( WC_Order $order, TransitionRequest $request ): bool|WP_Error {
		if ( ! in_array( $request->actor_type, Actor::all(), true ) ) {
			return new WP_Error( 'zbm_unknown_actor', __( 'نوع اقدام‌کننده معتبر نیست.', 'zarbalman-core' ) );
		}

		if ( Actor::SYSTEM === $request->actor_type ) {
			return 0 === $request->actor_user_id
				? true
				: new WP_Error( 'zbm_invalid_system_actor', __( 'اقدام سیستمی نباید شناسه کاربر داشته باشد.', 'zarbalman-core' ) );
		}

		if ( $request->actor_user_id < 1 ) {
			return new WP_Error( 'zbm_missing_actor', __( 'شناسه اقدام‌کننده لازم است.', 'zarbalman-core' ) );
		}

		if ( Actor::ADMIN === $request->actor_type ) {
			return AdminCapabilities::userCanOperate( $request->actor_user_id )
				? true
				: new WP_Error( 'zbm_admin_forbidden', __( 'کاربر اجازه مدیریت سفارش‌ها را ندارد.', 'zarbalman-core' ) );
		}

		if ( Actor::BUYER === $request->actor_type ) {
			return (int) $order->get_customer_id() === $request->actor_user_id
				? true
				: new WP_Error( 'zbm_buyer_forbidden', __( 'این سفارش متعلق به خریدار نیست.', 'zarbalman-core' ) );
		}

		$owns_order = $this->vendor_owns_order( $request->actor_user_id, $order );

		return $owns_order
			? true
			: new WP_Error(
				'zbm_vendor_adapter_missing',
				__( 'مالکیت فروشنده هنوز توسط اتصال دکان تأیید نشده است.', 'zarbalman-core' )
			);
	}

	public function can_view( WC_Order $order, int $user_id ): bool {
		if ( $user_id < 1 ) {
			return false;
		}
		return AdminCapabilities::userCanOperate( $user_id )
			|| (int) $order->get_customer_id() === $user_id
			|| $this->vendor_owns_order( $user_id, $order );
	}

	public function actor_type_for( WC_Order $order, int $user_id ): ?string {
		if ( AdminCapabilities::userCanOperate( $user_id ) ) {
			return Actor::ADMIN;
		}
		if ( (int) $order->get_customer_id() === $user_id ) {
			return Actor::BUYER;
		}
		return $this->vendor_owns_order( $user_id, $order ) ? Actor::VENDOR : null;
	}

	private function vendor_owns_order( int $user_id, WC_Order $order ): bool {
		return (bool) apply_filters( 'zbm_core_vendor_owns_order', false, $user_id, $order );
	}
}
