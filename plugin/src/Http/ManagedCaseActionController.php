<?php

declare(strict_types=1);

namespace Zarbalman\Core\Http;

use Throwable;
use WP_Error;
use Zarbalman\Core\Application\ManagedFlowService;
use Zarbalman\Core\Application\ManualPaymentService;
use Zarbalman\Core\Domain\ManagedFlow\FlowState;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Frontend\BuyerRequestEndpoint;
use Zarbalman\Core\Frontend\ManagedCasePanel;
use Zarbalman\Core\Frontend\RequestResponse;
use Zarbalman\Core\Infrastructure\ManagedCaseRepository;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;
use Zarbalman\Core\Integration\DokanPurchaseRequestEndpoint;
use Zarbalman\Core\Integration\DokanVendorEligibility;
use Zarbalman\Core\Security\AdminCapabilities;

/** Authenticated POST boundary for Spec 3. Posted roles, money and states are never consumed. */
final class ManagedCaseActionController {
	public const ACTION = 'zbm_managed_case_action';

	private const VENDOR_FLOW = array(
		'flow_record_weight' => 'record_weight',
		'flow_ready_delivery' => 'ready_delivery',
		'flow_ready_pickup' => 'ready_pickup',
		'flow_dispatch' => 'dispatch',
		'flow_deliver' => 'deliver',
	);

	private const BUYER_FLOW = array(
		'flow_propose_weight' => 'propose_weight',
	);
	private const FINANCE_ACTIONS = array( 'spec4_contact', 'spec4_confirm_return', 'spec4_create_refund', 'spec4_approve_action', 'spec4_execute_action', 'spec4_fail_action', 'spec4_reconcile_action', 'spec4_accept_late', 'spec4_reverify' );

	private const ADMIN_FLOW = array(
		'flow_pause' => 'pause',
		'flow_resume' => 'resume',
		'flow_extend' => 'extend',
		'flow_extend_delivery' => 'extend_delivery',
		'flow_reopen_preparation' => 'reopen_preparation',
		'flow_reopen_buyer' => 'reopen_buyer',
		'flow_reopen_seller' => 'reopen_seller',
		'flow_set_final_weight' => 'set_final_weight',
		'flow_reissue_code' => 'reissue_code',
		'flow_override_delivery' => 'override_delivery',
		'flow_note' => 'note',
		'flow_refer_return' => 'refer_return',
		'flow_cancel' => 'cancel',
	);

	public function __construct(
		private readonly PurchaseRequestRepository $requests,
		private readonly ManagedCaseRepository $cases,
		private readonly ManagedFlowService $flow,
		private readonly ManualPaymentService $manual,
		private readonly DokanVendorEligibility $vendorEligibility,
		private readonly ?\Zarbalman\Core\Application\Spec4FinanceService $finance = null,
		private readonly ?\Zarbalman\Core\Application\BalancePaymentService $balance = null
	) {}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function handle(): never {
		RequestResponse::preventCaching();
		if ( ! is_user_logged_in() ) {
			$this->fail( 401 );
		}
		$actor_id = get_current_user_id();
		$request_id = $this->positiveInt( $_POST['request_id'] ?? null ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked below before mutation.
		$version = $this->nonnegativeInt( $_POST['version'] ?? null ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$operation = sanitize_key( is_string( $_POST['operation'] ?? null ) ? wp_unslash( $_POST['operation'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$key = sanitize_text_field( is_string( $_POST['idempotency_key'] ?? null ) ? wp_unslash( $_POST['idempotency_key'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( null === $request_id || null === $version || ! wp_is_uuid( $key ) || ! $this->knownOperation( $operation ) ) {
			$this->fail( 400 );
		}
		try {
			$request = $this->requests->find( $request_id );
		} catch ( Throwable ) {
			$request = null;
		}
		if ( ! $request instanceof PurchaseRequest ) {
			$this->fail( 404 );
		}
		$role = $this->deriveRole( $request, $operation, $actor_id );
		if ( null === $role ) {
			$this->fail( 403 );
		}
		if ( ! RequestResponse::verifyFormNonce( $_POST['_zbm_nonce'] ?? null, 'zbm_managed_case_' . $request_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is the verification boundary.
			$this->finish( $request, $role, new WP_Error( 'zbm_managed_nonce', __( 'اعتبار فرم پایان یافته است؛ صفحه را تازه و دوباره تلاش کنید.', 'zarbalman-core' ) ) );
		}

		try {
			$result = $this->dispatch( $request, $version, $actor_id, $role, $key, $operation );
		} catch ( Throwable ) {
			$result = new WP_Error( 'zbm_managed_unavailable', __( 'نتیجهٔ عملیات اکنون در دسترس نیست. پیش از ارسال دوباره، آخرین وضعیت درخواست را تازه کنید.', 'zarbalman-core' ) );
		}
		$this->finish( $request, $role, $result, $operation );
	}

	/** @return array|WP_Error */
	private function dispatch( PurchaseRequest $request, int $version, int $actor_id, string $role, string $key, string $operation ): array|WP_Error {
		$request_id = $request->id();
		$reason = sanitize_textarea_field( is_string( $_POST['reason'] ?? null ) ? wp_unslash( $_POST['reason'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by handle.
		if ( in_array( $operation, self::FINANCE_ACTIONS, true ) && $this->finance && $this->balance ) {
			if ( in_array( $operation, array( 'spec4_confirm_return', 'spec4_create_refund', 'spec4_execute_action', 'spec4_reconcile_action', 'spec4_accept_late' ), true ) && '1' !== ( $_POST['finance_confirmed'] ?? null ) ) { return new WP_Error( 'zbm_finance_confirmation', __( 'جزئیات و نتیجهٔ اقدام مالی را تأیید کنید.', 'zarbalman-core' ) ); }
			if ( 'spec4_reverify' === $operation ) { return $this->balance->reverify( $request_id, $version, $actor_id, $key, $reason ); }
			if ( 'spec4_accept_late' === $operation ) { return $this->balance->acceptLate( $request_id, $version, $actor_id, $key, $reason ); }
			$payload = array( 'financial_action_id' => $this->positiveInt( $_POST['financial_action_id'] ?? null ) ?? 0,
				'trigger' => sanitize_key( is_string( $_POST['trigger'] ?? null ) ? wp_unslash( $_POST['trigger'] ) : '' ),
				'iban' => is_string( $_POST['iban'] ?? null ) ? wp_unslash( $_POST['iban'] ) : '',
				'reference' => is_string( $_POST['reference'] ?? null ) ? wp_unslash( $_POST['reference'] ) : '' );
			return $this->finance->act( $request_id, $version, $actor_id, $key, substr( $operation, 6 ), $reason, $payload, $_FILES['evidence'] ?? null );
		}
		if ( 'manual_offer' === $operation ) {
			return $this->manual->offer( $request_id, $version, $actor_id, $key );
		}
		if ( 'manual_receipt' === $operation ) {
			$offer_id = sanitize_text_field( is_string( $_POST['offer_id'] ?? null ) ? wp_unslash( $_POST['offer_id'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$receipt = isset( $_FILES['receipt'] ) ? $_FILES['receipt'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Binary metadata is validated by private uploader.
			$note = sanitize_textarea_field( is_string( $_POST['buyer_note'] ?? null ) ? wp_unslash( $_POST['buyer_note'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $this->manual->submitReceipt( $request_id, $version, $actor_id, $key, $offer_id, $receipt, $note );
		}
		if ( in_array( $operation, array( 'manual_approve', 'manual_reject' ), true ) ) {
			$phase = sanitize_key( is_string( $_POST['phase'] ?? null ) ? wp_unslash( $_POST['phase'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$amount = $this->financialAmount( $_POST['confirmed_amount_toman'] ?? null ) ?? 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return 'manual_approve' === $operation
				? $this->manual->approve( $request_id, $version, $actor_id, $key, $phase, $reason, $this->truthy( $_POST['confirmed'] ?? false ), $amount ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
				: $this->manual->reject( $request_id, $version, $actor_id, $key, $phase, $reason );
		}
		if ( 'manual_credit_seller' === $operation ) {
			return $this->manual->creditSeller( $request_id, $version, $actor_id, $key, $reason );
		}

		$action = self::VENDOR_FLOW[ $operation ] ?? self::BUYER_FLOW[ $operation ] ?? self::ADMIN_FLOW[ $operation ] ?? match ( $operation ) {
			'flow_accept_weight' => 'accept_weight',
			'flow_object' => 'object',
			default => '',
		};
		if ( '' === $action ) {
			return new WP_Error( 'zbm_managed_operation', __( 'اقدام انتخاب‌شده معتبر نیست.', 'zarbalman-core' ) );
		}
		$minutes = $this->positiveInt( $_POST['minutes'] ?? null ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$payload = array(
			'line_id' => is_string( $_POST['line_id'] ?? null ) ? wp_unslash( $_POST['line_id'] ) : '',
			'weight' => is_scalar( $_POST['weight'] ?? null ) ? wp_unslash( (string) $_POST['weight'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'acknowledged' => $this->truthy( $_POST['acknowledged'] ?? false ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'reason' => $reason,
			'code' => is_scalar( $_POST['code'] ?? null ) ? wp_unslash( (string) $_POST['code'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'resume_state' => sanitize_key( is_string( $_POST['resume_state'] ?? null ) ? wp_unslash( $_POST['resume_state'] ) : '' ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'visibility' => sanitize_key( is_string( $_POST['visibility'] ?? null ) ? wp_unslash( $_POST['visibility'] ) : 'private' ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);
		if ( null !== $minutes && $minutes <= 10080 ) {
			$payload['seconds'] = $minutes * MINUTE_IN_SECONDS;
		}
		$files = isset( $_FILES['evidence'] ) ? $_FILES['evidence'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Private uploader validates exact images.
		return $this->flow->act( $request_id, $version, $role, $actor_id, $key, $action, $payload, $files );
	}

	private function deriveRole( PurchaseRequest $request, string $operation, int $actor_id ): ?string {
		if ( in_array( $operation, self::FINANCE_ACTIONS, true ) ) { return AdminCapabilities::userCanManageFinance( $actor_id ) ? 'admin' : null; }
		if ( isset( self::ADMIN_FLOW[ $operation ] ) ) {
			return AdminCapabilities::userCanOperate( $actor_id ) ? 'admin' : null;
		}
		if ( in_array( $operation, array( 'manual_approve', 'manual_reject', 'manual_credit_seller' ), true ) ) {
			return AdminCapabilities::userCanManageFinance( $actor_id ) ? 'admin' : null;
		}
		if ( 'manual_offer' === $operation || 'manual_receipt' === $operation || isset( self::BUYER_FLOW[ $operation ] ) ) {
			return $request->buyerId() === $actor_id ? 'buyer' : null;
		}
		if ( isset( self::VENDOR_FLOW[ $operation ] ) ) {
			return $request->vendorId() === $actor_id && $this->vendorEligibility->isApprovedVendor( $actor_id ) ? 'vendor' : null;
		}
		if ( ! in_array( $operation, array( 'flow_accept_weight', 'flow_object' ), true ) ) {
			return null;
		}
		try {
			$case = $this->cases->find( $request->id() );
		} catch ( Throwable ) {
			return null;
		}
		$state = is_array( $case ) && is_string( $case['state'] ?? null ) ? $case['state'] : '';
		if ( 'flow_object' === $operation && FlowState::BUYER_REVIEW === $state && $request->buyerId() === $actor_id ) {
			return 'buyer';
		}
		if ( FlowState::SELLER_REVIEW === $state && $request->vendorId() === $actor_id && $this->vendorEligibility->isApprovedVendor( $actor_id ) ) {
			return 'vendor';
		}
		return null;
	}

	private function knownOperation( string $operation ): bool {
		return in_array( $operation, array_merge(
			self::FINANCE_ACTIONS,
			array_keys( self::VENDOR_FLOW ),
			array_keys( self::BUYER_FLOW ),
			array_keys( self::ADMIN_FLOW ),
			array( 'flow_accept_weight', 'flow_object', 'manual_offer', 'manual_receipt', 'manual_approve', 'manual_reject', 'manual_credit_seller' )
		), true );
	}

	private function positiveInt( mixed $value ): ?int {
		$value = is_int( $value ) || is_string( $value ) ? (string) $value : '';
		return 1 === preg_match( '/\A[1-9][0-9]*\z/', $value ) && $this->fitsNativeInteger( $value ) ? (int) $value : null;
	}

	private function financialAmount( mixed $value ): ?int {
		if ( ! is_string( $value ) && ! is_int( $value ) ) { return null; }
		$value = trim( strtr( (string) $value, array( '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٬'=>',' ) ) );
		if ( str_contains( $value, ',' ) ) {
			if ( 1 !== preg_match( '/\A[1-9][0-9]{0,2}(?:,[0-9]{3})+\z/', $value ) ) { return null; }
			$value = str_replace( ',', '', $value );
		}
		return $this->positiveInt( $value );
	}

	private function nonnegativeInt( mixed $value ): ?int {
		$value = is_int( $value ) || is_string( $value ) ? (string) $value : '';
		return 1 === preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) && $this->fitsNativeInteger( $value ) ? (int) $value : null;
	}

	private function fitsNativeInteger( string $value ): bool {
		$maximum = (string) PHP_INT_MAX;
		$length = strlen( $value );
		return $length < strlen( $maximum ) || ( $length === strlen( $maximum ) && strcmp( $value, $maximum ) <= 0 );
	}

	private function truthy( mixed $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'yes' === $value || 'on' === $value;
	}

	private function finish( PurchaseRequest $request, string $role, array|WP_Error $result, string $operation = '' ): never {
		$success = ! is_wp_error( $result );
		$message = $success ? match ( $operation ) {
			'manual_receipt' => __( 'رسید شما برای بررسی مدیریت ثبت شد. تا اعلام نتیجه نیازی به ارسال دوباره نیست.', 'zarbalman-core' ),
			'flow_record_weight' => __( 'وزن و عکس این محصول ثبت شد. پس از ثبت همهٔ ردیف‌ها، آماده‌بودن سفارش را اعلام کنید.', 'zarbalman-core' ),
			'flow_propose_weight' => __( 'وزن و عکس این محصول ثبت شد. پس از تکمیل همهٔ ردیف‌ها، بررسی فروشنده آغاز می‌شود.', 'zarbalman-core' ),
			'flow_accept_weight' => __( 'وزن نهایی ثبت شد و مدارک در پرونده محفوظ است.', 'zarbalman-core' ),
			'flow_object' => __( 'اعتراض و مدارک ثبت شد. مدیریت پرونده را بررسی می‌کند.', 'zarbalman-core' ),
			default => __( 'اقدام شما ثبت شد. وضعیت تازهٔ درخواست در همین صفحه نمایش داده می‌شود.', 'zarbalman-core' ),
		} : $result->get_error_message();
		$notice_type = $success ? 'success' : 'error';
		if ( $success && 'flow_deliver' === $operation && empty( $result['data']['delivery']['delivered_at'] ) ) {
			$notice_type = 'error';
			$message = FlowState::ADMIN_REVIEW === $result['state']
				? __( 'کد صحیح نبود و سقف تلاش‌ها پایان یافت. پرونده برای بررسی به مدیریت ارجاع شد.', 'zarbalman-core' )
				: __( 'کد صحیح نبود؛ تحویل ثبت نشده است. کد فعلی را هنگام دریافت کالا از خریدار بگیرید.', 'zarbalman-core' );
		}
		if ( wp_doing_ajax() ) {
			if ( $success ) {
				wp_send_json_success( array(
					'message' => $message,
					'notice_type' => $notice_type,
					'view_url' => $this->returnUrl( $request, $role, $operation ),
				), 200 );
			}
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => 'admin' === $role ? sanitize_key( (string) $result->get_error_code() ) : '' ), 409 );
		}
		ManagedCasePanel::storeNotice(
			get_current_user_id(),
			array(
				'type' => $notice_type,
				'message' => $message,
			)
		);
		RequestResponse::redirect( $this->returnUrl( $request, $role, $operation ) );
	}

	private function returnUrl( PurchaseRequest $request, string $role, string $operation = '' ): string {
		if ( 'admin' === $role ) {
			if ( str_starts_with( $operation, 'spec4_' ) ) { return add_query_arg( array( 'page' => 'zbm-core-finance', 'request' => $request->id() ), admin_url( 'admin.php' ) ); }
			return add_query_arg( array( 'page' => str_starts_with( $operation, 'flow_' ) ? 'zbm-core-operations' : 'zbm-core-manual-payments', 'request' => $request->id() ), admin_url( 'admin.php' ) );
		}
		if ( 'vendor' === $role ) {
			$base = function_exists( 'dokan_get_navigation_url' ) ? dokan_get_navigation_url( DokanPurchaseRequestEndpoint::ENDPOINT ) : home_url( '/approval-by-supplier/' );
			return add_query_arg( 'request', $request->id(), $base );
		}
		$base = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( BuyerRequestEndpoint::ENDPOINT ) : home_url( '/order-review-by-buyer/' );
		return add_query_arg( 'request', $request->id(), $base );
	}

	private function fail( int $status ): never {
		if ( wp_doing_ajax() ) {
			wp_send_json_error( array( 'message' => __( 'درخواست معتبر یا مجاز نیست؛ صفحه را تازه کنید.', 'zarbalman-core' ) ), $status );
		}
		wp_die( esc_html__( 'درخواست معتبر یا مجاز نیست.', 'zarbalman-core' ), esc_html__( 'عملیات پرونده', 'zarbalman-core' ), array( 'response' => $status ) );
	}
}
