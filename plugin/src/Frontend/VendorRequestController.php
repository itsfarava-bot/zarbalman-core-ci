<?php

declare(strict_types=1);

namespace Zarbalman\Core\Frontend;

use Zarbalman\Core\Application\PurchaseRequestService;
use Zarbalman\Core\Integration\DokanVendorEligibility;

final class VendorRequestController {
	public const POST_ACTION = 'zbm_vendor_request_decision';

	public function __construct(
		private readonly PurchaseRequestService $service,
		private readonly DokanVendorEligibility $vendor_eligibility
	) {}

	public function register(): void {
		add_action( 'admin_post_' . self::POST_ACTION, array( $this, 'handle' ) );
	}

	public function handle(): never {
		RequestResponse::preventCaching();
		$request_id = isset( $_POST['request_id'] ) && is_scalar( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		if ( ! RequestResponse::verifyFormNonce( $_POST['_zbm_nonce'] ?? null, 'zbm_vendor_request_' . $request_id ) ) {
			$this->finish( __( 'اعتبار فرم پایان یافته یا حساب ورود تغییر کرده است. وضعیت تازهٔ درخواست را بررسی و سپس دوباره اقدام کنید.', 'zarbalman-core' ), 'error', $request_id );
		}
		$vendor_id = get_current_user_id();
		if ( ! $this->vendor_eligibility->isApprovedVendor( $vendor_id ) ) {
			$this->finish( __( 'درخواست در دسترس نیست.', 'zarbalman-core' ), 'error' );
		}
		$decision = isset( $_POST['decision'] ) && is_string( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$version = isset( $_POST['version'] ) && is_scalar( $_POST['version'] ) ? absint( $_POST['version'] ) : -1;
		$key = isset( $_POST['idempotency_key'] ) && is_string( $_POST['idempotency_key'] ) ? sanitize_text_field( wp_unslash( $_POST['idempotency_key'] ) ) : '';
		if ( 'accept' === $decision ) {
			$result = $this->service->accept( $request_id, $vendor_id, $version, $key, wp_generate_uuid4() );
		} elseif ( 'reject' === $decision ) {
			$reason = isset( $_POST['reason'] ) && is_string( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
			$result = $this->service->reject( $request_id, $vendor_id, $version, $reason, $key, wp_generate_uuid4() );
		} else {
			$this->finish( __( 'تصمیم فروشنده معتبر نیست.', 'zarbalman-core' ), 'error', $request_id );
		}
		if ( is_wp_error( $result ) ) {
			$this->finish( $result->get_error_message(), 'error', $request_id );
		}
		$this->finish( __( 'تصمیم شما با موفقیت و به‌صورت غیرقابل‌برگشت ثبت شد.', 'zarbalman-core' ), 'success', $result->id() );
	}

	private function finish( string $message, string $type, int $request_id = 0 ): never {
		set_transient( 'zbm_vendor_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), 5 * MINUTE_IN_SECONDS );
		$url = function_exists( 'dokan_get_navigation_url' )
			? dokan_get_navigation_url( 'purchase-requests' )
			: home_url( '/approval-by-supplier/' );
		if ( $request_id > 0 ) {
			$url = add_query_arg( 'request', $request_id, $url );
		}
		RequestResponse::redirect( $url );
	}
}
