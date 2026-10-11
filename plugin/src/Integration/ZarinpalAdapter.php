<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use WC_Order;
use Zarbalman\Core\Domain\Finance\DepositCapturePolicy;
use Zarbalman\Core\Application\DepositPaymentService;
use Zarbalman\Core\Domain\Finance\DepositVerificationStatus;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;
use Zarbalman\Core\Infrastructure\OnlinePaymentAvailability;
use Zarbalman\Core\Infrastructure\PaymentRepository;

final class ZarinpalAdapter {
	/** @var array<int,array{authority:string,reference:string}> */
	private array $verified_payments = array();
	/** @var array<int,true> */
	private array $authority_issuance_orders = array();

	public function __construct(
		private readonly DepositPaymentService $payments,
		private readonly WooCommerceOrderAdapter $orders,
		private readonly PurchaseRequestRepository $requests,
		private readonly SecureZarinpalVerifier $verifier,
		private readonly ManagedCallbackLock $callback_lock,
		private readonly ManualPaymentRouteGuard $manual_routes,
		private readonly ?OnlinePaymentAvailability $availability = null,
		private readonly ?PaymentRepository $journal = null
	) {}

	public function register(): void {
		// The pinned gateway verifies (and therefore captures) before calling
		// payment_complete. Core must serialize and journal the callback first.
		add_action( 'woocommerce_api_wc_zpal', array( $this, 'handleManagedCallback' ), 0 );
		add_action( 'woocommerce_receipt_WC_ZPal', array( $this, 'beginManagedAuthorityIssuance' ), 0, 1 );
		add_action( 'woocommerce_receipt_WC_ZPal', array( $this, 'endManagedAuthorityIssuance' ), PHP_INT_MAX, 1 );
		add_action( 'woocommerce_after_order_object_save', array( $this, 'journalIssuedAuthority' ), PHP_INT_MAX, 2 );
		add_action( 'woocommerce_pre_payment_complete', array( $this, 'beginPaymentCompletion' ), 1, 2 );
		add_action( 'woocommerce_payment_complete', array( $this, 'paymentComplete' ), 1, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'detectUnexpectedPaidStatus' ), 1, 4 );
		add_action( 'wp_ajax_zpal_manual_verify', array( $this, 'blockDependencyManualVerifyForManagedOrder' ), 0 );
		add_action( 'shutdown', array( $this, 'shutdownCleanup' ), PHP_INT_MAX );
		add_action( 'http_api_debug', array( $this, 'observeIssuanceResponse' ), 10, 5 );
	}

	public function beginManagedAuthorityIssuance( int $order_id ): void {
		$order = wc_get_order( $order_id );
		$request = $this->requests->findByOrderId( $order_id );
		if ( ! $order instanceof WC_Order || ! $this->orders->isManagedOrder( $order ) ) {
			return;
		}
		if (
			! $request
			|| $this->manual_routes->blocksOnline( $request )
			|| ! $this->orders->isAuthoritativePayable( $order )
			|| get_current_user_id() < 1
			|| get_current_user_id() !== $request->buyerId()
		) {
			$this->haltManagedCallback( __( 'ایجاد شناسه پرداخت برای این درخواست مجاز نیست.', 'zarbalman-core' ), 403 );
		}
		try {
			$this->callback_lock->acquire( $order_id, $request->id(), 30 );
		} catch ( \Throwable ) {
			$this->haltManagedCallback( __( 'پرداخت دیگری برای این درخواست در حال پردازش است؛ چند لحظه بعد دوباره تلاش کنید.', 'zarbalman-core' ), 409 );
		}

		// The pre-lock check is only an early rejection. Payability must be
		// authoritative after the wait because a callback may have completed.
		$order = wc_get_order( $order_id );
		$request = $this->requests->findByOrderId( $order_id );
		if (
			! $order instanceof WC_Order
			|| ! $request
			|| $this->manual_routes->blocksOnline( $request )
			|| ! $this->orders->isManagedOrder( $order )
			|| ! $this->orders->isAuthoritativePayable( $order )
			|| get_current_user_id() < 1
			|| get_current_user_id() !== $request->buyerId()
		) {
			$this->callback_lock->release( $order_id );
			$this->haltManagedCallback( __( 'این درخواست پس از بررسی هم‌زمان دیگر قابل پرداخت نیست.', 'zarbalman-core' ), 409 );
		}

		$payment = $this->payments->paymentRecord( $order_id );
		if ( ! is_array( $payment ) || ! in_array( (string) ( $payment['status'] ?? '' ), array( 'expected', 'authority_issued' ), true ) ) {
			$this->callback_lock->release( $order_id );
			$this->haltManagedCallback( __( 'وضعیت داخلی پرداخت اجازه ایجاد شناسه جدید را نمی‌دهد.', 'zarbalman-core' ), 409 );
		}
		$issued = $this->issuedAuthorityForOrder( $order, $payment );
		if ( null !== $issued ) {
			$stored = $this->payments->persistIssuedAuthority( $order_id, $issued );
			$this->callback_lock->release( $order_id );
			if ( is_wp_error( $stored ) ) {
				$this->haltManagedCallback( __( 'شناسه پرداخت موجود با پرونده سازگار نیست؛ عملیات متوقف شد.', 'zarbalman-core' ), 409 );
			}
			$this->redirectToIssuedAuthority( $issued );
		}
		if ( 'authority_issued' === (string) ( $payment['status'] ?? '' ) || $this->orders->hasZarinpalPaymentIdentity( $order_id ) ) {
			$this->callback_lock->release( $order_id );
			$this->haltManagedCallback( __( 'شناسه پرداخت قبلی قابل تطبیق نیست؛ برای جلوگیری از ایجاد لینک هم‌زمان، عملیات متوقف شد.', 'zarbalman-core' ), 409 );
		}

		if ( null !== $this->journal && ! $this->journal->markIssuancePending( $request, $order_id ) ) {
			$this->callback_lock->release( $order_id );
			$this->haltManagedCallback( __( 'ثبت امن شروع پرداخت ممکن نیست؛ هیچ لینک جدیدی ایجاد نشد.', 'zarbalman-core' ), 409 );
		}
		$this->orders->beginInternalPaymentIdentityChange( $order_id );
		$this->authority_issuance_orders[ $order_id ] = true;
	}

	/** Observes only this adapter's real, locked production issuance request. */
	public function observeIssuanceResponse( mixed $response, string $context, string $transport, array $args, string $url ): void {
		if ( 'response' !== $context || 'https://payment.zarinpal.com/pg/v4/payment/request.json' !== $url
			|| array() === $this->authority_issuance_orders || null === $this->journal || null === $this->availability ) { return; }
		$body = is_string( $args['body'] ?? null ) ? json_decode( $args['body'], true, 16 ) : null;
		if ( ! is_array( $body ) || ! is_string( $body['callback_url'] ?? null ) ) { return; }
		parse_str( (string) wp_parse_url( $body['callback_url'], PHP_URL_QUERY ), $callback );
		$order_id = isset( $callback['wc_order'] ) && is_scalar( $callback['wc_order'] ) ? absint( $callback['wc_order'] ) : 0;
		if ( ! isset( $this->authority_issuance_orders[ $order_id ] ) ) { return; }
		$request = $this->requests->findByOrderId( $order_id );
		$settings = get_option( 'woocommerce_WC_ZPal_settings', array() );
		if ( ! $request || $request->depositToman() > intdiv( PHP_INT_MAX, 10 ) || ! is_scalar( $body['amount'] ?? null )
			|| (string) $body['amount'] !== (string) ( $request->depositToman() * 10 ) || ! is_string( $body['merchant_id'] ?? null )
			|| ! is_array( $settings ) || ! hash_equals( (string) ( $settings['merchantcode'] ?? '' ), $body['merchant_id'] ) ) { return; }
		$payload = ! is_wp_error( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true, 16 ) : null;
		$http = ! is_wp_error( $response ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
		$authority = is_array( $payload ) ? ( $payload['data']['authority'] ?? null ) : null;
		$success = $http >= 200 && $http < 300 && is_array( $payload ) && 100 === ( $payload['data']['code'] ?? null )
			&& is_string( $authority ) && '' !== trim( $authority ) && strlen( $authority ) <= 128;
		$this->availability->observe( $success );
		if ( $success ) {
			// Persist before Woo receives the response. A crash afterwards must not
			// lose a issued payment identity or expose a second payment route.
			$stored = $this->journal->persistIssuedAuthority( $request, $order_id, $authority );
			if ( is_wp_error( $stored ) ) { throw new \RuntimeException( 'Issued payment identity could not be journaled.' ); }
		} elseif ( $http >= 200 && $http < 500 && is_array( $payload ) && empty( $payload['data'] )
			&& is_int( $payload['errors']['code'] ?? null ) && $payload['errors']['code'] < 0 ) {
			$this->journal->recordIssuanceRejected( $request, $order_id );
		}
		// Timeout/malformed/ambiguous response stays issuance_pending. No guess of failure.
	}

	public function endManagedAuthorityIssuance( int $order_id ): void {
		if ( ! isset( $this->authority_issuance_orders[ $order_id ] ) ) {
			return;
		}
		unset( $this->authority_issuance_orders[ $order_id ] );
		$this->orders->endInternalPaymentIdentityChange( $order_id );
		$this->callback_lock->release( $order_id );
	}

	public function journalIssuedAuthority( mixed $saved_order, mixed $data_store ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WooCommerce hook contract.
		if ( ! $saved_order instanceof WC_Order || ! isset( $this->authority_issuance_orders[ $saved_order->get_id() ] ) ) {
			return;
		}
		$authority = trim( (string) $saved_order->get_meta( '_zarinpal_authority', true ) );
		if ( '' === $authority ) {
			return;
		}
		$stored = $this->payments->persistIssuedAuthority( $saved_order->get_id(), $authority );
		if ( is_wp_error( $stored ) ) {
			throw new \RuntimeException( 'Core could not journal the issued Zarinpal Authority.' );
		}
	}

	public function handleManagedCallback(): void {
		$order_id = isset( $_GET['wc_order'] ) ? absint( wp_unslash( $_GET['wc_order'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Provider callback; identity is verified remotely below.
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order || ! $this->orders->isManagedOrder( $order ) || $order->is_paid() ) {
			return;
		}
		$status = isset( $_GET['Status'] ) ? sanitize_text_field( wp_unslash( $_GET['Status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public provider callback.
		if ( 'OK' !== $status ) {
			return;
		}
		$authority = $this->trustedVerificationAuthority( $order_id );
		$request = $this->requests->findByOrderId( $order_id );
		if ( null === $authority || ! $request ) {
			$this->haltManagedCallback( __( 'هویت callback یا درخواست متناظر معتبر نیست؛ هیچ استعلام مالی انجام نشد.', 'zarbalman-core' ), 400 );
		}

		try {
			$this->callback_lock->acquire( $order_id, $request->id(), 30 );
		} catch ( \Throwable ) {
			$this->haltManagedCallback( __( 'پرداخت دیگری برای این درخواست در حال بررسی است؛ چند لحظه بعد دوباره وضعیت درخواست را مشاهده کنید.', 'zarbalman-core' ), 409 );
		}

		// Re-read after acquiring the lock; another callback may have completed
		// while this request waited on the advisory lock.
		$order = wc_get_order( $order_id );
		$request = $this->requests->findByOrderId( $order_id );
		if ( $order instanceof WC_Order && $order->is_paid() ) {
			$this->callback_lock->release( $order_id );
			return;
		}
		$authority = $this->trustedVerificationAuthority( $order_id );
		if (
			! $order instanceof WC_Order
			|| ! $request
			|| ! DepositCapturePolicy::canReceiveCallback( $request->state() )
			|| null === $authority
		) {
			$this->callback_lock->release( $order_id );
			$this->haltManagedCallback( __( 'این درخواست در وضعیت قابل پرداخت نیست و هیچ استعلام مالی انجام نشد.', 'zarbalman-core' ), 409 );
		}

		$receipt_status = $this->payments->persistCallbackReceipt( $order_id, $authority );
		if ( is_wp_error( $receipt_status ) ) {
			$this->callback_lock->release( $order_id );
			$this->haltManagedCallback( __( 'ثبت امن ورود پرداخت انجام نشد؛ برای جلوگیری از برداشت تکراری، عملیات متوقف شد.', 'zarbalman-core' ), 409 );
		}

		$verified = $this->verifier->verifyReceivedCallback( $request, $authority );
		if ( is_wp_error( $verified ) ) {
			if ( 'zbm_zarinpal_reverify_failed' === $verified->get_error_code() ) {
				$this->payments->releaseUnverifiedCallbackReceipt( $order_id, $authority );
			}
			$this->callback_lock->release( $order_id );
			$this->haltManagedCallback(
				'zbm_zarinpal_reverify_failed' === $verified->get_error_code()
					? __( 'زرین‌پال این پرداخت را قطعی اعلام نکرد؛ می‌توانید از صفحه درخواست دوباره تلاش کنید.', 'zarbalman-core' )
					: __( 'نتیجهٔ پرداخت موقتاً قابل استعلام نیست؛ شناسه callback ذخیره شد و بازیابی خودکار ادامه پیدا می‌کند.', 'zarbalman-core' ),
				'zbm_zarinpal_reverify_failed' === $verified->get_error_code() ? 400 : 503
			);
		}

		$attempt = $this->payments->persistCallbackAttempt( $order_id, $verified['authority'], $verified['reference'] );
		$confirmed = is_wp_error( $attempt )
			? $attempt
			: $this->payments->confirmCallbackVerification( $order_id, $verified['authority'], $verified['reference'] );
		if ( is_wp_error( $confirmed ) ) {
			$this->payments->recordUntrustedCompletion( $order_id );
			$this->callback_lock->release( $order_id );
			$this->haltManagedCallback( __( 'پرداخت قطعی است اما ثبت داخلی آن کامل نشد؛ پرونده برای بازیابی امن نگه داشته شد.', 'zarbalman-core' ), 503 );
		}

		$confirmed_payment = $this->payments->paymentRecord( $order_id );
		$confirmed_status = is_array( $confirmed_payment ) ? (string) ( $confirmed_payment['status'] ?? '' ) : '';
		try {
			$this->orders->pinCapturedZarinpalAuthority( $order_id, $verified['authority'] );
			if ( DepositVerificationStatus::wasOnTime( $confirmed_status ) ) {
				$this->orders->reserveStockForVerifiedPayment( $request );
			}
			$this->orders->markRemotelyVerifiedPaidOrder( $order_id, $verified['reference'] );
			$recorded = $this->payments->recordSuccessfulDeposit( $order_id, $verified['authority'], $verified['reference'] );
			if ( is_wp_error( $recorded ) ) {
				throw new \RuntimeException( 'Core payment reconciliation remains pending.' );
			}
		} catch ( \Throwable ) {
			$this->payments->recordUntrustedCompletion( $order_id );
			$this->callback_lock->release( $order_id );
			$this->haltManagedCallback( __( 'پرداخت تأیید شده است اما ادامهٔ امن سفارش نیاز به بازیابی خودکار یا بررسی مدیریت دارد.', 'zarbalman-core' ), 503 );
		}

		$this->callback_lock->release( $order_id );
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'پیش‌پرداخت با موفقیت ثبت شد.', 'zarbalman-core' ), 'success' );
		}
		wp_safe_redirect( $this->buyerRequestUrl( $request->id() ) );
		exit;
	}

	public function beginPaymentCompletion( int $order_id, string $transaction_id = '' ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $this->orders->isManagedOrder( $order ) ) {
			return;
		}
		$request = $this->requests->findByOrderId( $order_id );
		$authority = $this->trustedVerificationAuthority( $order_id );
		if (
			! $request
			|| null === $authority
			|| '' === trim( $transaction_id )
			|| strlen( trim( $transaction_id ) ) > 128
		) {
			$this->payments->recordUntrustedCompletion( $order_id );
			$this->callback_lock->release( $order_id );
			throw new \RuntimeException( 'Managed payment completion did not have an authoritative callback identity.' );
		}
		try {
			$this->callback_lock->acquire( $order_id, $request->id(), 30 );
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Managed payment completion is busy.', 0, $error );
		}
		$attempt = $this->payments->persistCallbackAttempt( $order_id, $authority, $transaction_id );
		if ( is_wp_error( $attempt ) ) {
			try {
				$this->payments->recordUntrustedCompletion( $order_id );
			} finally {
				$this->callback_lock->release( $order_id );
			}
			throw new \RuntimeException( 'Core could not durably record the managed Zarinpal callback.' );
		}
		$verified = $this->verifier->verifyDepositCompletion( $request, $authority, $transaction_id );
		if ( is_wp_error( $verified ) ) {
			try {
				$this->payments->recordUntrustedCompletion( $order_id );
			} finally {
				$this->callback_lock->release( $order_id );
			}
			throw new \RuntimeException( 'Core could not independently verify the managed Zarinpal payment.' );
		}
		$confirmed = $this->payments->confirmCallbackVerification( $order_id, $verified['authority'], $verified['reference'] );
		if ( is_wp_error( $confirmed ) ) {
			try {
				$this->payments->recordUntrustedCompletion( $order_id );
			} finally {
				$this->callback_lock->release( $order_id );
			}
			throw new \RuntimeException( 'Core could not durably record the verified Zarinpal payment.' );
		}
		$this->orders->pinCapturedZarinpalAuthority( $order_id, $verified['authority'] );
		$this->verified_payments[ $order_id ] = $verified;
		$this->orders->beginExternalPaymentCompletion( $order_id );
	}

	public function paymentComplete( int $order_id, string $transaction_id = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WooCommerce hook contract.
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $this->orders->isManagedOrder( $order ) ) {
			return;
		}
		if ( ! $this->orders->isInternalStatusChange( $order_id ) ) {
			$this->payments->recordUntrustedCompletion( $order_id );
			$order->add_order_note( __( 'تأیید پرداخت خارج از callback معتبر زرین‌پال توسط Core متوقف شد.', 'zarbalman-core' ) );
			$this->releaseOrderLock( $order_id );
			return;
		}
		try {
			$verified = $this->verified_payments[ $order_id ] ?? null;
			if ( ! is_array( $verified ) || ! isset( $verified['authority'], $verified['reference'] ) ) {
				$this->payments->recordUntrustedCompletion( $order_id );
				$order->add_order_note( __( 'مدرک تأیید مستقل زرین‌پال در Core موجود نبود؛ پرونده برای بررسی مدیریت متوقف شد.', 'zarbalman-core' ) );
				return;
			}
			$result = $this->payments->recordSuccessfulDeposit( $order_id, $verified['authority'], $verified['reference'] );
			if ( is_wp_error( $result ) ) {
				$this->payments->recordUntrustedCompletion( $order_id );
				$order = wc_get_order( $order_id );
				if ( $order instanceof WC_Order ) {
					$order->add_order_note( __( 'Core ادامه گردش پرداخت را متوقف کرد؛ بررسی مدیریت لازم است.', 'zarbalman-core' ) );
				}
			}
		} finally {
			unset( $this->verified_payments[ $order_id ] );
			$this->orders->endExternalPaymentCompletion( $order_id );
			$this->releaseOrderLock( $order_id );
		}
	}

	public function shutdownCleanup(): void {
		foreach ( array_keys( $this->verified_payments ) as $order_id ) {
			$this->orders->endExternalPaymentCompletion( (int) $order_id );
		}
		$this->verified_payments = array();
		foreach ( array_keys( $this->authority_issuance_orders ) as $order_id ) {
			$this->orders->endInternalPaymentIdentityChange( (int) $order_id );
		}
		$this->authority_issuance_orders = array();
		$this->callback_lock->releaseAll();
	}

	public function detectUnexpectedPaidStatus( int $order_id, string $old_status, string $new_status, WC_Order $order ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if (
			$this->orders->isManagedOrder( $order )
			&& in_array( $new_status, array( 'processing', 'completed' ), true )
			&& ! $this->orders->isInternalStatusChange( $order_id )
			&& 'WC_ZPal' !== $order->get_payment_method()
		) {
			$this->payments->recordUntrustedCompletion( $order_id );
		}
	}

	public function blockDependencyManualVerifyForManagedOrder(): void {
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This early guard blocks the dependency's nonce-less mutation.
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( $order instanceof WC_Order && $this->orders->isManagedOrder( $order ) ) {
			wp_die(
				esc_html__( 'برای این سفارش فقط «پذیرش پرداخت دیرهنگام» در صفحه درخواست‌های زربال‌من مجاز است؛ آن مسیر پرداخت را مستقیماً از زرین‌پال استعلام می‌کند.', 'zarbalman-core' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	private function trustedVerificationAuthority( int $order_id ): ?string {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $this->orders->isManagedOrder( $order ) || 'WC_ZPal' !== $order->get_payment_method() ) {
			return null;
		}

		// Bind the public callback to an Authority issued for this exact order.
		// Authenticity and amount are independently verified with Zarinpal before
		// Core treats the payment as captured.
		$callback_order = isset( $_GET['wc_order'] ) ? absint( wp_unslash( $_GET['wc_order'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public provider callback; remote verification follows.
		$status = isset( $_GET['Status'] ) ? sanitize_text_field( wp_unslash( $_GET['Status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$authority = isset( $_GET['Authority'] ) ? sanitize_text_field( wp_unslash( $_GET['Authority'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		if ( $callback_order === $order_id && 'OK' === $status && '' !== $authority && strlen( $authority ) <= 128 ) {
			$stored = (string) $order->get_meta( '_zarinpal_authority', true );
			$history = $order->get_meta( '_zarinpal_authority_history', true );
			$known = '' !== $stored && hash_equals( $stored, $authority );
			if ( ! $known && is_array( $history ) ) {
				foreach ( $history as $candidate ) {
					if ( is_string( $candidate ) && hash_equals( $candidate, $authority ) ) {
						$known = true;
						break;
					}
				}
			}
			return $known ? $authority : null;
		}

		return null;
	}

	/** @param array<string,mixed> $payment */
	private function issuedAuthorityForOrder( WC_Order $order, array $payment ): ?string {
		$current = trim( (string) $order->get_meta( '_zarinpal_authority', true ) );
		$recorded = trim( (string) ( $payment['authority'] ?? '' ) );
		if ( '' !== $recorded ) {
			return '' !== $current && hash_equals( $current, $recorded ) ? $recorded : null;
		}
		if ( '' === $current || strlen( $current ) > 128 ) {
			return null;
		}
		$history = $order->get_meta( '_zarinpal_authority_history', true );
		if ( ! is_array( $history ) ) {
			return null;
		}
		foreach ( $history as $authority ) {
			if ( is_string( $authority ) && hash_equals( trim( $authority ), $current ) ) {
				return $current;
			}
		}
		return null;
	}

	private function redirectToIssuedAuthority( string $authority ): never {
		$settings = get_option( 'woocommerce_WC_ZPal_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		if ( 'yes' === (string) ( $settings['sandbox'] ?? '' ) ) {
			$this->haltManagedCallback( __( 'حالت آزمایشی زرین‌پال برای پرونده واقعی مجاز نیست.', 'zarbalman-core' ), 409 );
		}
		$base = 'https://payment.zarinpal.com/pg/StartPay/';
		wp_redirect( esc_url_raw( $base . rawurlencode( $authority ) ), 302, 'Zarbalman Core' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Fixed trusted Zarinpal hosts only.
		exit;
	}

	private function releaseOrderLock( int $order_id ): void {
		$this->callback_lock->release( $order_id );
	}

	private function buyerRequestUrl( int $request_id ): string {
		$base = function_exists( 'wc_get_account_endpoint_url' )
			? wc_get_account_endpoint_url( 'purchase-requests' )
			: home_url( '/order-review-by-buyer/' );
		return add_query_arg( 'request', $request_id, $base );
	}

	private function haltManagedCallback( string $message, int $status ): never {
		wp_die( esc_html( $message ), '', array( 'response' => $status ) );
	}
}
