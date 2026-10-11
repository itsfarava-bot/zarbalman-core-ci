<?php

declare(strict_types=1);

namespace Zarbalman\Core\Application;

use RuntimeException;
use Throwable;
use WP_Error;
use Zarbalman\Core\Domain\PurchaseRequest\PricingSnapshot;
use Zarbalman\Core\Domain\PurchaseRequest\ExecutionMode;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;
use Zarbalman\Core\Domain\PurchaseRequest\ExternalRecoveryPolicy;
use Zarbalman\Core\Domain\Finance\DepositCapturePolicy;
use Zarbalman\Core\Domain\Finance\DepositVerificationStatus;
use Zarbalman\Core\Infrastructure\DeliveryDataCrypto;
use Zarbalman\Core\Infrastructure\DependencyHealth;
use Zarbalman\Core\Infrastructure\Migrator;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;
use Zarbalman\Core\Infrastructure\PurchaseRequestOperationLock;
use Zarbalman\Core\Integration\ManagedOrderCreator;
use Zarbalman\Core\Integration\DokanVendorEligibility;
use Zarbalman\Core\Integration\ManualPaymentRouteGuard;
use Zarbalman\Core\Integration\SecureZarinpalVerifier;
use Zarbalman\Core\Notification\RecipientResolver;
use Zarbalman\Core\Security\AdminCapabilities;

final class PurchaseRequestService {
	public const SUPPLIER_TTL_SECONDS = 3600;
	public const DEPOSIT_TTL_SECONDS  = 86400;

	public function __construct(
		private readonly PurchaseRequestRepository $repository,
		private readonly DeliveryDataCrypto $crypto,
		private readonly DependencyHealth $health,
		private readonly RecipientResolver $recipients,
		private readonly ManagedOrderCreator $order_creator,
		private readonly DepositPaymentService $deposit_payments,
		private readonly SecureZarinpalVerifier $zarinpal_verifier,
		private readonly PurchaseRequestOperationLock $operation_lock,
		private readonly DokanVendorEligibility $vendor_eligibility,
		private readonly ?ManualPaymentRouteGuard $manual_routes = null
	) {}

	public function create(
		PurchaseRequestInput $input,
		int $buyer_id,
		string $idempotency_key,
		string $correlation_id
	): PurchaseRequest|WP_Error {
		$schema_error = Migrator::pricingAvailabilityError();
		if ( $schema_error ) {
			return $schema_error;
		}
		$dependency_error = $this->health->requestCreationError();
		if ( $dependency_error ) {
			return $dependency_error;
		}
		if ( $buyer_id < 1 ) {
			return new WP_Error( 'zbm_login_required', __( 'برای ثبت درخواست وارد حساب خود شوید.', 'zarbalman-core' ) );
		}
		if ( ! $this->validIdempotencyKey( $idempotency_key ) ) {
			return new WP_Error( 'zbm_invalid_idempotency_key', __( 'کلید یکتای ثبت درخواست معتبر نیست؛ صفحه را تازه کنید.', 'zarbalman-core' ) );
		}
		if ( $buyer_id === $input->vendorId() ) {
			return new WP_Error( 'zbm_self_purchase_forbidden', __( 'فروشنده نمی‌تواند برای محصول خودش درخواست خرید ثبت کند.', 'zarbalman-core' ) );
		}
		if ( ! $this->vendor_eligibility->isApprovedVendor( $input->vendorId() ) ) {
			return new WP_Error( 'zbm_vendor_not_approved', __( 'این فروشنده هنوز برای دریافت درخواست خرید توسط مدیریت فعال نشده است.', 'zarbalman-core' ) );
		}
		$contact_error = $this->contactError( $buyer_id, $input->vendorId() );
		if ( $contact_error ) {
			return $contact_error;
		}
		if ( ! $this->crypto->ensureKey() ) {
			return new WP_Error( 'zbm_delivery_crypto_unavailable', __( 'ثبت امن نشانی موقتاً در دسترس نیست.', 'zarbalman-core' ) );
		}

		try {
			$pricing = $input->pricingSnapshot();
			$encrypted = $this->crypto->encrypt( $input->deliveryPayload() );
		} catch ( Throwable ) {
			return new WP_Error( 'zbm_request_input_invalid', __( 'اطلاعات درخواست معتبر نیست.', 'zarbalman-core' ) );
		}

		$request = $this->repository->create(
			$input,
			$pricing,
			$buyer_id,
			$encrypted,
			gmdate( 'Y-m-d H:i:s', time() + self::SUPPLIER_TTL_SECONDS ),
			$idempotency_key,
			$correlation_id
		);
		if ( $request instanceof PurchaseRequest ) {
			do_action( 'zbm_purchase_request_created', $request->id(), $request->version() );
		}

		return $request;
	}

	/** Read-only preflight shared by the product UI and the authoritative create guard. */
	public function contactError( int $buyer_id, int $vendor_id ): ?WP_Error {
		$dependency_error = $this->health->requestCreationError();
		if ( $dependency_error ) {
			return $dependency_error;
		}
		if ( ! $this->validMobile( $this->recipients->mobile( 'buyer', $buyer_id, null ) ) ) {
			return new WP_Error( 'zbm_buyer_mobile_required', __( 'شمارهٔ همراه معتبر در حساب شما در دسترس نیست. ابتدا از «جزئیات حساب» شمارهٔ همراه را تکمیل کنید؛ سپس به همین محصول برگردید.', 'zarbalman-core' ) );
		}
		if ( ! $this->validMobile( $this->recipients->mobile( 'vendor', $vendor_id, null ) ) ) {
			return new WP_Error( 'zbm_vendor_mobile_required', __( 'شمارهٔ همراه معتبر فروشنده در دسترس نیست. ثبت درخواست برای این فروشنده تا تکمیل اطلاعات تماس او متوقف است.', 'zarbalman-core' ) );
		}
		return null;
	}

	public function accept(
		int $request_id,
		int $vendor_id,
		int $expected_version,
		string $idempotency_key,
		string $correlation_id
	): PurchaseRequest|WP_Error {
		try {
			return $this->operation_lock->synchronized(
				$request_id,
				fn (): PurchaseRequest|WP_Error => $this->acceptLocked(
					$request_id, $vendor_id, $expected_version, $idempotency_key, $correlation_id
				)
			);
		} catch ( Throwable ) {
			return new WP_Error( 'zbm_request_busy', __( 'این درخواست در حال پردازش است؛ چند لحظه بعد دوباره تلاش کنید.', 'zarbalman-core' ) );
		}
	}

	private function acceptLocked(
		int $request_id,
		int $vendor_id,
		int $expected_version,
		string $idempotency_key,
		string $correlation_id
	): PurchaseRequest|WP_Error {
		$request = $this->ownedVendorRequest( $request_id, $vendor_id );
		if ( is_wp_error( $request ) ) {
			return $request;
		}
		if ( ! $this->validIdempotencyKey( $idempotency_key ) ) {
			return new WP_Error( 'zbm_invalid_idempotency_key', __( 'کلید یکتای تصمیم معتبر نیست؛ صفحه را تازه کنید.', 'zarbalman-core' ) );
		}
		$dependency_error = $this->health->requestCreationError();
		if ( $dependency_error ) {
			return $dependency_error;
		}
		$payment_dependency_error = $this->health->depositOrderError();
		if ( RequestState::DEPOSIT_PAID === $request->state() ) {
			return $request;
		}
		if ( ExecutionMode::LIVE !== $request->executionMode() ) {
			return new WP_Error( 'zbm_test_request_vendor_locked', __( 'این پرونده در مسیر آزمایشی مدیریت قرار دارد و از اقدام فروشنده عبور نمی‌کند.', 'zarbalman-core' ) );
		}
		if ( RequestState::DEPOSIT_PENDING === $request->state() ) {
			if ( $this->manual_routes?->blocksOnline( $request ) ) {
				return $request;
			}
			return $request->paymentReady() || $payment_dependency_error
				? $request
				: $this->recoverPendingPaymentLocked( $request );
		}

		if ( RequestState::SUPPLIER_ACCEPTED === $request->state() ) {
			// A previous worker may have committed the vendor decision and then
			// stopped before it could attach the WooCommerce order. Resuming this
			// durable intermediate state is safe and required for crash recovery.
			$accepted = $request;
		} else {
			$deferred_notifications = $payment_dependency_error
				? array(
					array( 'role' => 'buyer', 'user_id' => $request->buyerId(), 'template' => 'request_accepted_payment_waiting' ),
					array( 'role' => 'vendor', 'user_id' => $vendor_id, 'template' => 'request_accepted_payment_waiting_vendor' ),
				)
				: array();
			$accepted = $this->repository->transition(
				$request_id,
				RequestState::SUPPLIER_REVIEW,
				RequestState::SUPPLIER_ACCEPTED,
				$expected_version,
				'vendor',
				$vendor_id,
				$idempotency_key,
				'supplier_accepted_request',
				$correlation_id,
				array(
					'accepted_at_gmt'       => current_time( 'mysql', true ),
					'supplier_deadline_gmt' => null,
				),
				$deferred_notifications
			);
			if ( is_wp_error( $accepted ) ) {
				return $accepted;
			}
		}

		if ( RequestState::DEPOSIT_PENDING === $accepted->state() || RequestState::DEPOSIT_PAID === $accepted->state() ) {
			return $accepted;
		}
		if ( $this->manual_routes?->blocksOnline( $accepted ) ) {
			return $accepted;
		}
		if ( $payment_dependency_error ) {
			return $accepted;
		}

		try {
			$order_id = $accepted->wcOrderId() ?: $this->order_creator->createForAcceptedRequest( $accepted );
			if ( $order_id < 1 ) {
				throw new RuntimeException( 'Managed order ID is invalid.' );
			}
		} catch ( Throwable ) {
			$this->moveAcceptedFailureToAdminReview( $accepted, $correlation_id );
			return new WP_Error( 'zbm_managed_order_failed', __( 'ساخت امن لینک پیش‌پرداخت انجام نشد و پرونده برای مدیریت متوقف شد.', 'zarbalman-core' ) );
		}
		$prepared = $this->deposit_payments->prepareExpected( $accepted, $order_id );
		if ( is_wp_error( $prepared ) ) {
			$stock_released = false;
			try {
				$this->order_creator->releaseReservedStockByOrderId( $order_id );
				$stock_released = true;
			} catch ( Throwable ) {
				// The request is still moved to admin review below. Recovery can reconcile stock.
			}
			$this->moveAcceptedFailureToAdminReview(
				$accepted,
				$correlation_id,
				array( 'wc_order_id' => $order_id, 'stock_reserved' => 1, 'stock_released' => $stock_released ? 1 : 0 )
			);
			return $prepared;
		}

		$deposit_deadline = gmdate( 'Y-m-d H:i:s', time() + self::DEPOSIT_TTL_SECONDS );
		$pending = $this->repository->transition(
			$request_id,
			RequestState::SUPPLIER_ACCEPTED,
			RequestState::DEPOSIT_PENDING,
			$accepted->version(),
			'system',
			0,
			'attach-order-' . $order_id,
			'deposit_order_created',
			$correlation_id,
			array(
				'wc_order_id'          => $order_id,
				'deposit_deadline_gmt' => $deposit_deadline,
				'payment_ready'        => 0,
				'stock_reserved'       => 1,
			)
		);
		if ( is_wp_error( $pending ) ) {
			$converged = $this->repository->find( $request_id );
			if (
				$converged
				&& $converged->wcOrderId() === $order_id
				&& in_array( $converged->state(), array( RequestState::DEPOSIT_PENDING, RequestState::DEPOSIT_PAID, RequestState::LATE_PAYMENT_REVIEW ), true )
			) {
				if ( RequestState::DEPOSIT_PENDING !== $converged->state() ) {
					return $converged;
				}
				$pending = $converged;
			} else {
				$stock_released = false;
				try {
					$this->order_creator->releaseReservedStockByOrderId( $order_id );
					$stock_released = true;
					$this->deposit_payments->markExpectedExpired( $order_id );
				} catch ( Throwable ) {
					// The admin-review state below remains the reconciliation target.
				}
				$this->moveAcceptedFailureToAdminReview(
					$accepted,
					$correlation_id,
					array( 'wc_order_id' => $order_id, 'stock_reserved' => 1, 'stock_released' => $stock_released ? 1 : 0 )
				);
				return $pending;
			}
		}
		if ( $pending instanceof PurchaseRequest ) {
			try {
				$this->order_creator->enablePayment( $order_id );
			} catch ( Throwable ) {
				$stock_released = false;
				try {
					$this->order_creator->releaseReservedStockByOrderId( $order_id );
					$stock_released = true;
					$this->deposit_payments->markExpectedExpired( $order_id );
				} catch ( Throwable ) {
					// The admin-review transition below preserves the reconciliation target.
				}
				$this->repository->transition(
					$pending->id(), RequestState::DEPOSIT_PENDING, RequestState::ADMIN_REVIEW, $pending->version(),
					'system', 0, 'payment-enable-failed-v' . $pending->version(), 'payment_enable_failed', $correlation_id,
					array( 'stock_released' => $stock_released ? 1 : 0 ), $this->reviewNotifications( $pending, 'payment_enable_failed' )
				);
				return new WP_Error( 'zbm_payment_enable_failed', __( 'فعال‌سازی امن پرداخت انجام نشد و پرونده برای مدیریت متوقف شد.', 'zarbalman-core' ) );
			}
			$ready = $this->markDepositPaymentReady( $pending, $correlation_id );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
			$pending = $ready;
		}

		return $pending;
	}

	public function reject(
		int $request_id,
		int $vendor_id,
		int $expected_version,
		string $reason,
		string $idempotency_key,
		string $correlation_id
	): PurchaseRequest|WP_Error {
		$request = $this->ownedVendorRequest( $request_id, $vendor_id );
		if ( is_wp_error( $request ) ) {
			return $request;
		}
		$dependency_error = $this->health->requestCreationError();
		if ( $dependency_error ) {
			return $dependency_error;
		}
		if ( ! $this->validIdempotencyKey( $idempotency_key ) ) {
			return new WP_Error( 'zbm_invalid_idempotency_key', __( 'کلید یکتای تصمیم معتبر نیست؛ صفحه را تازه کنید.', 'zarbalman-core' ) );
		}
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( '' === $reason || $this->textLength( $reason ) > 1000 ) {
			return new WP_Error( 'zbm_rejection_reason_required', __( 'دلیل رد درخواست را بنویسید.', 'zarbalman-core' ) );
		}
		if ( PurchaseRequestInput::containsContactData( $reason ) ) {
			return new WP_Error( 'zbm_rejection_contact_forbidden', __( 'در دلیل رد، شماره تماس یا ایمیل ننویسید.', 'zarbalman-core' ) );
		}

		return $this->repository->transition(
			$request_id,
			RequestState::SUPPLIER_REVIEW,
			RequestState::SUPPLIER_REJECTED,
			$expected_version,
			'vendor',
			$vendor_id,
			$idempotency_key,
			'supplier_rejected_request',
			$correlation_id,
			array(
				'rejection_reason'      => $reason,
				'rejected_at_gmt'       => current_time( 'mysql', true ),
				'supplier_deadline_gmt' => null,
			),
			array(
				array( 'role' => 'buyer', 'user_id' => $request->buyerId(), 'template' => 'request_rejected', 'variables' => array( 'reason' => $reason ) ),
				array( 'role' => 'vendor', 'user_id' => $vendor_id, 'template' => 'request_rejected_vendor' ),
			)
		);
	}

	public function expireSupplierReview( int $request_id, int $expected_version ): PurchaseRequest|WP_Error {
		$request = $this->repository->find( $request_id );
		if ( ! $request ) {
			return new WP_Error( 'zbm_request_not_found', __( 'درخواست در دسترس نیست.', 'zarbalman-core' ) );
		}
		if ( null === $request->supplierDeadlineGmt() || $request->supplierDeadlineGmt() > current_time( 'mysql', true ) ) {
			return new WP_Error( 'zbm_supplier_deadline_not_due', __( 'مهلت پاسخ فروشنده هنوز پایان نیافته است.', 'zarbalman-core' ) );
		}
		return $this->repository->transition(
			$request_id,
			RequestState::SUPPLIER_REVIEW,
			RequestState::SUPPLIER_RESPONSE_EXPIRED,
			$expected_version,
			'system',
			0,
			'supplier-expiry-v' . $expected_version,
			'supplier_response_expired',
			wp_generate_uuid4(),
			array( 'supplier_deadline_gmt' => null ),
			array(
				array( 'role' => 'buyer', 'user_id' => $request->buyerId(), 'template' => 'supplier_response_expired' ),
				array( 'role' => 'vendor', 'user_id' => $request->vendorId(), 'template' => 'supplier_response_expired_vendor' ),
			)
		);
	}

	public function expireDeposit( int $request_id, int $expected_version ): PurchaseRequest|WP_Error {
		try {
			return $this->operation_lock->synchronized(
				$request_id,
				fn (): PurchaseRequest|WP_Error => $this->expireDepositLocked( $request_id, $expected_version )
			);
		} catch ( Throwable ) {
			return new WP_Error( 'zbm_request_busy', __( 'عملیات انقضا هم‌زمان در حال پردازش است.', 'zarbalman-core' ) );
		}
	}

	private function expireDepositLocked( int $request_id, int $expected_version ): PurchaseRequest|WP_Error {
		$request = $this->repository->find( $request_id );
		if ( ! $request ) {
			return new WP_Error( 'zbm_request_not_found', __( 'درخواست در دسترس نیست.', 'zarbalman-core' ) );
		}
		if ( null === $request->depositDeadlineGmt() || $request->depositDeadlineGmt() > current_time( 'mysql', true ) ) {
			return new WP_Error( 'zbm_deposit_deadline_not_due', __( 'مهلت پیش‌پرداخت هنوز پایان نیافته است.', 'zarbalman-core' ) );
		}
		if ( $request->wcOrderId() && $this->deposit_payments->recoverableIntent( $request->wcOrderId() ) ) {
			$recovery = $this->recoverVerifiedPaymentIntentLocked( $request );
			$fresh = $this->repository->find( $request_id );
			if ( ! $fresh || RequestState::DEPOSIT_PENDING !== $fresh->state() ) {
				return $fresh ?: $recovery;
			}
			$remaining = $this->deposit_payments->recoverableIntent( $request->wcOrderId() );
			$remaining_status = is_array( $remaining ) ? (string) ( $remaining['status'] ?? '' ) : '';
			if ( DepositVerificationStatus::isPending( $remaining_status ) || DepositVerificationStatus::isVerified( $remaining_status ) ) {
				return $recovery;
			}
			$request = $fresh;
		}
		$expired = $this->repository->transition(
			$request_id,
			RequestState::DEPOSIT_PENDING,
			RequestState::DEPOSIT_EXPIRED,
			$expected_version,
			'system',
			0,
			'deposit-expiry-v' . $expected_version,
			'deposit_expired',
			wp_generate_uuid4(),
			array( 'deposit_deadline_gmt' => null ),
			array(
				array( 'role' => 'buyer', 'user_id' => $request->buyerId(), 'template' => 'deposit_expired' ),
				array( 'role' => 'vendor', 'user_id' => $request->vendorId(), 'template' => 'deposit_expired_vendor' ),
			)
		);
		return $expired instanceof PurchaseRequest ? $this->finishDepositExpiry( $expired ) : $expired;
	}

	public function recoverIncomplete( PurchaseRequest $request ): PurchaseRequest|WP_Error {
		if ( ExecutionMode::LIVE !== $request->executionMode() ) {
			return $request;
		}
		if (
			ExternalRecoveryPolicy::mustRepairStockFirst(
				$request->state(),
				$request->needsReconciliation(),
				$request->stockOperation(),
				$request->wcOrderId() ?? 0
			)
		) {
			try {
				return $this->operation_lock->synchronized(
					$request->id(),
					function () use ( $request ): PurchaseRequest|WP_Error {
						$fresh = $this->repository->find( $request->id() );
						return $fresh ? $this->reconcileExternalIntentLocked( $fresh ) : new WP_Error( 'zbm_request_not_found', __( 'درخواست در دسترس نیست.', 'zarbalman-core' ) );
					}
				);
			} catch ( Throwable ) {
				return new WP_Error( 'zbm_stock_reconciliation_failed', __( 'ترمیم موجودی برای تلاش خودکار بعدی باقی ماند.', 'zarbalman-core' ) );
			}
		}
		if ( $request->wcOrderId() && $this->deposit_payments->recoverableIntent( $request->wcOrderId() ) ) {
			try {
				return $this->operation_lock->synchronized(
					$request->id(),
					function () use ( $request ): PurchaseRequest|WP_Error {
						$fresh = $this->repository->find( $request->id() );
						return $fresh ? $this->recoverVerifiedPaymentIntentLocked( $fresh ) : new WP_Error( 'zbm_request_not_found', __( 'درخواست در دسترس نیست.', 'zarbalman-core' ) );
					}
				);
			} catch ( Throwable ) {
				return new WP_Error( 'zbm_payment_recovery_failed', __( 'بازیابی پرداخت قطعی انجام نشد و برای تلاش خودکار بعدی باقی ماند.', 'zarbalman-core' ) );
			}
		}
		if ( $request->needsReconciliation() ) {
			try {
				return $this->operation_lock->synchronized(
					$request->id(),
					function () use ( $request ): PurchaseRequest|WP_Error {
						$fresh = $this->repository->find( $request->id() );
						return $fresh ? $this->reconcileExternalIntentLocked( $fresh ) : new WP_Error( 'zbm_request_not_found', __( 'درخواست در دسترس نیست.', 'zarbalman-core' ) );
					}
				);
			} catch ( Throwable ) {
				return new WP_Error( 'zbm_external_reconciliation_failed', __( 'هماهنگ‌سازی سفارش برای تلاش خودکار بعدی باقی ماند.', 'zarbalman-core' ) );
			}
		}
		if ( RequestState::SUPPLIER_ACCEPTED === $request->state() ) {
			if ( $this->manual_routes?->blocksOnline( $request ) ) {
				return $request;
			}
			return $this->accept(
				$request->id(),
				$request->vendorId(),
				$request->version(),
				'recover-accepted-v' . $request->version(),
				wp_generate_uuid4()
			);
		}
		if ( RequestState::DEPOSIT_PENDING === $request->state() && ! $request->paymentReady() && ! $request->isPaused() ) {
			if ( $this->manual_routes?->blocksOnline( $request ) ) {
				return $request;
			}
			try {
				return $this->operation_lock->synchronized(
					$request->id(),
					function () use ( $request ): PurchaseRequest|WP_Error {
						$fresh = $this->repository->find( $request->id() );
						if ( ! $fresh || RequestState::DEPOSIT_PENDING !== $fresh->state() || $fresh->paymentReady() || $fresh->isPaused() ) {
							return $fresh ?: new WP_Error( 'zbm_request_not_found', __( 'درخواست در دسترس نیست.', 'zarbalman-core' ) );
						}
						return $this->recoverPendingPaymentLocked( $fresh );
					}
				);
			} catch ( Throwable ) {
				return new WP_Error( 'zbm_payment_recovery_failed', __( 'بازیابی لینک پرداخت انجام نشد و برای تلاش خودکار بعدی باقی ماند.', 'zarbalman-core' ) );
			}
		}
		if ( RequestState::DEPOSIT_EXPIRED !== $request->state() ) {
			return $request;
		}
		try {
			return $this->operation_lock->synchronized(
				$request->id(),
				function () use ( $request ): PurchaseRequest|WP_Error {
					$fresh = $this->repository->find( $request->id() );
					return $fresh instanceof PurchaseRequest && RequestState::DEPOSIT_EXPIRED === $fresh->state()
						? $this->finishDepositExpiry( $fresh )
						: ( $fresh ?: new WP_Error( 'zbm_request_not_found', __( 'درخواست در دسترس نیست.', 'zarbalman-core' ) ) );
				}
			);
		} catch ( Throwable ) {
			return new WP_Error( 'zbm_request_busy', __( 'بازیابی درخواست هم‌زمان در حال اجرا است.', 'zarbalman-core' ) );
		}
	}

	private function finishDepositExpiry( PurchaseRequest $expired ): PurchaseRequest|WP_Error {
		$manual_route = $this->manual_routes?->blocksOnline( $expired ) ?? false;
		if ( $expired->wcOrderId() ) {
			try {
				$this->order_creator->disablePayment( $expired->wcOrderId() );
				if ( ! $manual_route && ! $this->deposit_payments->recoverableIntent( $expired->wcOrderId() ) ) {
					$this->deposit_payments->markExpectedExpired( $expired->wcOrderId() );
				}
			} catch ( Throwable ) {
				return $this->repository->transition(
					$expired->id(), RequestState::DEPOSIT_EXPIRED, RequestState::ADMIN_REVIEW, $expired->version(),
					'system', 0, 'payment-disable-failed-v' . $expired->version(), 'payment_disable_failed', wp_generate_uuid4(),
					array(), $this->reviewNotifications( $expired, 'payment_disable_failed' )
				);
			}
		}
		if ( $expired->stockReserved() && ! $expired->stockReleased() ) {
			try {
				$this->order_creator->releaseReservedStock( $expired );
				$marked = $this->repository->transition(
					$expired->id(), RequestState::DEPOSIT_EXPIRED, RequestState::DEPOSIT_EXPIRED, $expired->version(),
					'system', 0, 'stock-released-v' . $expired->version(), 'stock_released', wp_generate_uuid4(),
					array( 'stock_released' => 1 )
				);
				if ( $marked instanceof PurchaseRequest ) {
					$expired = $marked;
				}
			} catch ( Throwable ) {
				return $this->repository->transition(
					$expired->id(), RequestState::DEPOSIT_EXPIRED, RequestState::ADMIN_REVIEW, $expired->version(),
					'system', 0, 'stock-release-failed-v' . $expired->version(), 'stock_release_failed', wp_generate_uuid4(),
					array(), $this->reviewNotifications( $expired, 'stock_release_failed' )
				);
			}
		}

		return $expired;
	}

	private function recoverVerifiedPaymentIntentLocked( PurchaseRequest $request ): PurchaseRequest|WP_Error {
		if ( ! DepositCapturePolicy::canReceiveCallback( $request->state() ) ) {
			return new WP_Error( 'zbm_payment_recovery_state_invalid', __( 'وضعیت درخواست اجازه استعلام یا ثبت پرداخت را نمی‌دهد.', 'zarbalman-core' ) );
		}
		$order_id = $request->wcOrderId();
		$intent = $order_id ? $this->deposit_payments->recoverableIntent( $order_id ) : null;
		if ( ! $order_id || ! is_array( $intent ) ) {
			return $request;
		}
		$authority = trim( (string) ( $intent['authority'] ?? '' ) );
		$reference = trim( (string) ( $intent['reference'] ?? '' ) );
		$status = (string) ( $intent['status'] ?? '' );
		$is_authority_issued = 'authority_issued' === $status;
		if (
			'' === $authority
			|| ( ! $is_authority_issued && ! DepositVerificationStatus::isRecoverable( $status ) )
			|| ( ! $is_authority_issued && ! DepositVerificationStatus::isReceived( $status ) && '' === $reference )
		) {
			return new WP_Error( 'zbm_payment_recovery_identity_invalid', __( 'هویت ذخیره‌شده پرداخت برای بازیابی معتبر نیست.', 'zarbalman-core' ) );
		}
		$verified = $is_authority_issued || DepositVerificationStatus::isReceived( $status )
			? $this->zarinpal_verifier->verifyReceivedCallback( $request, $authority )
			: $this->zarinpal_verifier->verifyDepositCompletion( $request, $authority, $reference );
		if ( is_wp_error( $verified ) ) {
			if ( DepositVerificationStatus::isReceived( $status ) && 'zbm_zarinpal_reverify_failed' === $verified->get_error_code() ) {
				$this->deposit_payments->releaseUnverifiedCallbackReceipt( $order_id, $authority );
				return $this->repository->find( $request->id() ) ?: $request;
			}
			if ( $is_authority_issued && 'zbm_zarinpal_reverify_failed' === $verified->get_error_code() ) {
				return $request;
			}
			if ( 'zbm_zarinpal_reverify_unavailable' === $verified->get_error_code() ) {
				return new WP_Error( 'zbm_payment_reverification_pending', __( 'استعلام قطعی پرداخت هنوز ممکن نیست؛ callback ذخیره شده و بازیابی خودکار دوباره تلاش می‌کند.', 'zarbalman-core' ) );
			}
			$this->deposit_payments->recordUntrustedCompletion( $order_id );
			return new WP_Error( 'zbm_payment_reverification_pending', __( 'استعلام قطعی پرداخت هنوز ممکن نیست؛ پرونده بدون انقضا برای بازیابی باقی ماند.', 'zarbalman-core' ) );
		}
		if ( $is_authority_issued || DepositVerificationStatus::isReceived( $status ) ) {
			$attempt = $this->deposit_payments->persistCallbackAttempt( $order_id, $verified['authority'], $verified['reference'] );
			if ( is_wp_error( $attempt ) ) {
				return $attempt;
			}
		}
		$confirmed = $this->deposit_payments->confirmCallbackVerification( $order_id, $verified['authority'], $verified['reference'] );
		if ( is_wp_error( $confirmed ) ) {
			return $confirmed;
		}
		$confirmed_payment = $this->deposit_payments->paymentRecord( $order_id );
		$confirmed_status = is_array( $confirmed_payment ) ? (string) ( $confirmed_payment['status'] ?? '' ) : '';
		if ( DepositVerificationStatus::wasOnTime( $confirmed_status ) ) {
			$stock_intent = $this->beginStockReservationIntent( $request, 'verified-payment-stock-recovery' );
			if ( is_wp_error( $stock_intent ) ) {
				return $stock_intent;
			}
			$request = $stock_intent;
			try {
				$this->order_creator->reserveStockForVerifiedPayment( $request );
			} catch ( Throwable ) {
				$this->deposit_payments->recordUntrustedCompletion( $order_id );
				return new WP_Error( 'zbm_payment_stock_recovery_failed', __( 'پرداخت قطعی است اما موجودی برای ادامه امن نیست؛ پرونده به بررسی مدیریت رفت.', 'zarbalman-core' ) );
			}
		}
		try {
			$this->order_creator->pinCapturedZarinpalAuthority( $order_id, $verified['authority'] );
			$this->order_creator->markRemotelyVerifiedPaidOrder( $order_id, $verified['reference'] );
		} catch ( Throwable ) {
			return new WP_Error( 'zbm_payment_order_recovery_failed', __( 'پرداخت تأیید شد اما وضعیت سفارش هنوز بازیابی نشده است.', 'zarbalman-core' ) );
		}
		$result = $this->deposit_payments->recordSuccessfulDeposit( $order_id, $verified['authority'], $verified['reference'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->repository->find( $request->id() ) ?: new WP_Error( 'zbm_payment_recovery_reload_failed', __( 'پرداخت ثبت شد اما بارگذاری پرونده انجام نشد.', 'zarbalman-core' ) );
	}

	private function recoverPendingPaymentLocked( PurchaseRequest $request ): PurchaseRequest|WP_Error {
		if ( $this->manual_routes?->blocksOnline( $request ) ) {
			return $request;
		}
		if (
			RequestState::DEPOSIT_PENDING !== $request->state()
			|| $request->paymentReady()
			|| $request->isPaused()
			|| ! $request->wcOrderId()
			|| null === $request->depositDeadlineGmt()
			|| $request->depositDeadlineGmt() <= current_time( 'mysql', true )
		) {
			return $request;
		}
		$prepared = $this->deposit_payments->prepareExpected( $request, $request->wcOrderId() );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		try {
			$this->order_creator->enablePayment( $request->wcOrderId() );
		} catch ( Throwable ) {
			return new WP_Error( 'zbm_payment_recovery_failed', __( 'بازیابی لینک پرداخت انجام نشد و برای تلاش خودکار بعدی باقی ماند.', 'zarbalman-core' ) );
		}
		return $this->markDepositPaymentReady( $request, wp_generate_uuid4() );
	}

	private function markDepositPaymentReady( PurchaseRequest $request, string $correlation_id ): PurchaseRequest|WP_Error {
		if ( RequestState::DEPOSIT_PENDING !== $request->state() || $request->paymentReady() || ! $request->wcOrderId() || null === $request->depositDeadlineGmt() ) {
			return $request;
		}
		$ready = $this->repository->transition(
			$request->id(), RequestState::DEPOSIT_PENDING, RequestState::DEPOSIT_PENDING, $request->version(),
			'system', 0, 'payment-ready-v' . $request->version(), 'deposit_payment_ready', $correlation_id,
			array( 'payment_ready' => 1 ),
			array(
				array(
					'role' => 'buyer', 'user_id' => $request->buyerId(), 'template' => 'deposit_link_created',
					'variables' => array( 'amount_toman' => $request->depositToman(), 'deadline' => $request->depositDeadlineGmt() ),
				),
				array( 'role' => 'vendor', 'user_id' => $request->vendorId(), 'template' => 'request_accepted_vendor' ),
			)
		);
		if ( $ready instanceof PurchaseRequest ) {
			do_action( 'zbm_purchase_request_deposit_pending', $ready->id(), $ready->version() );
		}
		return $ready;
	}

	private function reconcileExternalIntentLocked( PurchaseRequest $request ): PurchaseRequest|WP_Error {
		if ( ! $request->needsReconciliation() ) {
			return $request;
		}
		if ( RequestState::LATE_PAYMENT_REVIEW === $request->state() && 'reserve_pending' === $request->stockOperation() && $request->wcOrderId() ) {
			try {
				$this->order_creator->reserveStockForVerifiedPayment( $request );
			} catch ( Throwable ) {
				return new WP_Error( 'zbm_stock_reconciliation_failed', __( 'هماهنگ‌سازی موجودی پرداخت دیرهنگام کامل نشد و خودکار دوباره تلاش می‌شود.', 'zarbalman-core' ) );
			}
			return $this->repository->transition(
				$request->id(), $request->state(), $request->state(), $request->version(),
				'system', 0, 'late-stock-reconciled-v' . $request->version(), 'late_payment_stock_reconciled', wp_generate_uuid4(),
				array( 'needs_reconciliation' => 0, 'stock_operation' => null, 'stock_reserved' => 1, 'stock_released' => 0 )
			);
		}
		if ( RequestState::DEPOSIT_PENDING !== $request->state() || ! $request->wcOrderId() ) {
			return new WP_Error( 'zbm_external_reconciliation_state_invalid', __( 'وضعیت هماهنگ‌سازی خارجی معتبر نیست.', 'zarbalman-core' ) );
		}
		$payment = $this->deposit_payments->paymentRecord( $request->wcOrderId() );
		$status = is_array( $payment ) ? (string) ( $payment['status'] ?? '' ) : '';
		try {
			if ( 'expired' === $status ) {
				$this->order_creator->reopenPaymentAndReserveStock( $request );
				if ( ! $this->deposit_payments->reopenExpected( $request->wcOrderId() ) ) {
					throw new RuntimeException( 'Expected payment could not be reopened.' );
				}
				$template = 'deposit_deadline_reopened';
				$notifications = array(
					array(
						'role' => 'buyer', 'user_id' => $request->buyerId(), 'template' => $template,
						'variables' => array( 'deadline' => (string) $request->depositDeadlineGmt(), 'amount_toman' => $request->depositToman() ),
					),
					array(
						'role' => 'vendor', 'user_id' => $request->vendorId(), 'template' => $template . '_vendor',
						'variables' => array( 'deadline' => (string) $request->depositDeadlineGmt() ),
					),
				);
			} elseif ( in_array( $status, array( 'expected', 'authority_issued' ), true ) ) {
				$request->isPaused()
					? $this->order_creator->disablePayment( $request->wcOrderId() )
					: $this->order_creator->enablePayment( $request->wcOrderId() );
				$template = $request->isPaused() ? 'request_paused' : 'request_resumed';
				$notifications = array(
					array( 'role' => 'buyer', 'user_id' => $request->buyerId(), 'template' => $template ),
					array( 'role' => 'vendor', 'user_id' => $request->vendorId(), 'template' => $template . '_vendor' ),
				);
			} else {
				return new WP_Error( 'zbm_external_reconciliation_payment_invalid', __( 'رکورد پرداخت برای هماهنگ‌سازی معتبر نیست.', 'zarbalman-core' ) );
			}
		} catch ( Throwable ) {
			return new WP_Error( 'zbm_external_reconciliation_failed', __( 'هماهنگ‌سازی پرداخت یا موجودی کامل نشد و خودکار دوباره تلاش می‌شود.', 'zarbalman-core' ) );
		}

		$result = $this->repository->transition(
			$request->id(), $request->state(), $request->state(), $request->version(),
			'system', 0, 'external-reconciled-v' . $request->version(), $template, wp_generate_uuid4(),
			array( 'needs_reconciliation' => 0 ), $notifications
		);
		if ( $result instanceof PurchaseRequest && ! $result->isPaused() && null !== $result->depositDeadlineGmt() ) {
			do_action( 'zbm_purchase_request_deposit_pending', $result->id(), $result->version() );
		}
		return $result;
	}

	public function reopenSupplierDeadline( int $request_id, int $admin_id, int $seconds, string $reason ): PurchaseRequest|WP_Error {
		$request = $this->repository->find( $request_id );
		if ( ! $request || RequestState::SUPPLIER_RESPONSE_EXPIRED !== $request->state() ) {
			return new WP_Error( 'zbm_reopen_not_allowed', __( 'این مهلت فروشنده قابل بازگشایی نیست.', 'zarbalman-core' ) );
		}
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( ! AdminCapabilities::userCanOperate( $admin_id ) || '' === $reason || $this->textLength( $reason ) > 1000 || self::SUPPLIER_TTL_SECONDS !== $seconds ) {
			return new WP_Error( 'zbm_reopen_invalid', __( 'دلیل و مدت معتبر برای بازگشایی لازم است.', 'zarbalman-core' ) );
		}
		$deadline = gmdate( 'Y-m-d H:i:s', time() + $seconds );
		$result = $this->repository->transition(
			$request_id, RequestState::SUPPLIER_RESPONSE_EXPIRED, RequestState::SUPPLIER_REVIEW, $request->version(),
			'admin', $admin_id, 'reopen-supplier-v' . $request->version() . '-' . wp_generate_uuid4(), 'supplier_deadline_reopened', wp_generate_uuid4(),
			array( 'supplier_deadline_gmt' => $deadline, 'pause_reason' => $reason, 'is_paused' => 0, 'paused_at_gmt' => null ),
			array(
				array( 'role' => 'buyer', 'user_id' => $request->buyerId(), 'template' => 'supplier_deadline_reopened', 'variables' => array( 'deadline' => $deadline ) ),
				array( 'role' => 'vendor', 'user_id' => $request->vendorId(), 'template' => 'supplier_deadline_reopened_vendor', 'variables' => array( 'deadline' => $deadline ) ),
			)
		);
		if ( $result instanceof PurchaseRequest ) {
			do_action( 'zbm_purchase_request_created', $result->id(), $result->version() );
		}
		return $result;
	}

	public function setPaused(
		int $request_id,
		int $admin_id,
		int $expected_version,
		bool $paused,
		string $reason
	): PurchaseRequest|WP_Error {
		try {
			return $this->operation_lock->synchronized(
				$request_id,
				fn (): PurchaseRequest|WP_Error => $this->setPausedLocked( $request_id, $admin_id, $expected_version, $paused, $reason )
			);
		} catch ( Throwable ) {
			return new WP_Error( 'zbm_request_busy', __( 'این درخواست در حال پردازش است؛ چند لحظه بعد دوباره تلاش کنید.', 'zarbalman-core' ) );
		}
	}

	private function setPausedLocked(
		int $request_id,
		int $admin_id,
		int $expected_version,
		bool $paused,
		string $reason
	): PurchaseRequest|WP_Error {
		$request = $this->repository->find( $request_id );
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( ! $request || ! AdminCapabilities::userCanOperate( $admin_id ) || '' === $reason || $this->textLength( $reason ) > 1000 ) {
			return new WP_Error( 'zbm_admin_control_invalid', __( 'دسترسی، درخواست یا دلیل مدیریت معتبر نیست.', 'zarbalman-core' ) );
		}
		if ( ! in_array( $request->state(), array( RequestState::SUPPLIER_REVIEW, RequestState::DEPOSIT_PENDING ), true ) ) {
			return new WP_Error( 'zbm_admin_control_not_timed', __( 'این مرحله زمان فعال قابل توقف یا ادامه ندارد.', 'zarbalman-core' ) );
		}
		if ( $request->version() !== $expected_version || $request->isPaused() === $paused || $request->needsReconciliation() ) {
			return new WP_Error( 'zbm_admin_control_stale', __( 'وضعیت کنترل مدیریت تغییر کرده است؛ صفحه را تازه کنید.', 'zarbalman-core' ) );
		}
		$fields = array();
		if ( $paused ) {
			$deadline = RequestState::SUPPLIER_REVIEW === $request->state() ? $request->supplierDeadlineGmt() : $request->depositDeadlineGmt();
			$remaining = null === $deadline ? 0 : max( 0, strtotime( $deadline . ' UTC' ) - time() );
			if ( null !== $deadline && $remaining < 1 ) {
				return new WP_Error( 'zbm_admin_deadline_already_expired', __( 'مهلت این مرحله پایان یافته و بازیابی زمان‌بندی باید ابتدا وضعیت انقضا را ثبت کند.', 'zarbalman-core' ) );
			}
			$fields = array(
				'is_paused' => 1,
				'pause_reason' => $reason,
				'paused_at_gmt' => current_time( 'mysql', true ),
				'paused_remaining_seconds' => $remaining,
			);
			if ( RequestState::SUPPLIER_REVIEW === $request->state() ) {
				$fields['supplier_deadline_gmt'] = null;
			} elseif ( RequestState::DEPOSIT_PENDING === $request->state() ) {
				$fields['deposit_deadline_gmt'] = null;
			}
		} else {
			$remaining = $request->pausedRemainingSeconds() ?? 0;
			if ( $remaining < 1 && in_array( $request->state(), array( RequestState::SUPPLIER_REVIEW, RequestState::DEPOSIT_PENDING ), true ) ) {
				return new WP_Error( 'zbm_admin_reopen_required', __( 'مهلت این مرحله تمام شده است؛ از بازگشایی مهلت استفاده کنید.', 'zarbalman-core' ) );
			}
			$fields = array(
				'is_paused' => 0,
				'pause_reason' => $reason,
				'paused_at_gmt' => null,
				'paused_remaining_seconds' => null,
			);
			if ( RequestState::SUPPLIER_REVIEW === $request->state() ) {
				$fields['supplier_deadline_gmt'] = gmdate( 'Y-m-d H:i:s', time() + $remaining );
			} elseif ( RequestState::DEPOSIT_PENDING === $request->state() ) {
				$fields['deposit_deadline_gmt'] = gmdate( 'Y-m-d H:i:s', time() + $remaining );
			}
		}

		$template = $paused ? 'request_paused' : 'request_resumed';
		if ( RequestState::DEPOSIT_PENDING === $request->state() ) {
			$fields['needs_reconciliation'] = 1;
			$intent = $this->repository->transition(
				$request_id, $request->state(), $request->state(), $expected_version,
				'admin', $admin_id, $template . '-intent-v' . $expected_version . '-' . wp_generate_uuid4(), $template . '_requested',
				wp_generate_uuid4(), $fields
			);
			if ( is_wp_error( $intent ) ) {
				return $intent;
			}
			return $this->reconcileExternalIntentLocked( $intent );
		}

		$result = $this->repository->transition(
			$request_id, $request->state(), $request->state(), $expected_version,
			'admin', $admin_id, $template . '-v' . $expected_version . '-' . wp_generate_uuid4(), $template,
			wp_generate_uuid4(), $fields,
			array(
				array( 'role' => 'buyer', 'user_id' => $request->buyerId(), 'template' => $template ),
				array( 'role' => 'vendor', 'user_id' => $request->vendorId(), 'template' => $template . '_vendor' ),
			)
		);
		return $result;
	}

	public function reopenDepositDeadline( int $request_id, int $admin_id, int $seconds, string $reason ): PurchaseRequest|WP_Error {
		try {
			return $this->operation_lock->synchronized(
				$request_id,
				fn (): PurchaseRequest|WP_Error => $this->reopenDepositDeadlineLocked( $request_id, $admin_id, $seconds, $reason )
			);
		} catch ( Throwable ) {
			return new WP_Error( 'zbm_request_busy', __( 'این درخواست در حال پردازش است؛ چند لحظه بعد دوباره تلاش کنید.', 'zarbalman-core' ) );
		}
	}

	private function reopenDepositDeadlineLocked( int $request_id, int $admin_id, int $seconds, string $reason ): PurchaseRequest|WP_Error {
		$request = $this->repository->find( $request_id );
		if ( ! $request || RequestState::DEPOSIT_EXPIRED !== $request->state() || ! $request->wcOrderId() ) {
			return new WP_Error( 'zbm_reopen_not_allowed', __( 'این مهلت پرداخت قابل بازگشایی نیست.', 'zarbalman-core' ) );
		}
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( ! AdminCapabilities::userCanOperate( $admin_id ) || '' === $reason || $this->textLength( $reason ) > 1000 || $seconds < 300 || $seconds > self::DEPOSIT_TTL_SECONDS ) {
			return new WP_Error( 'zbm_reopen_invalid', __( 'دلیل و مدت معتبر برای بازگشایی لازم است.', 'zarbalman-core' ) );
		}
		$deadline = gmdate( 'Y-m-d H:i:s', time() + $seconds );
		$intent = $this->repository->transition(
			$request_id, RequestState::DEPOSIT_EXPIRED, RequestState::DEPOSIT_PENDING, $request->version(),
			'admin', $admin_id, 'reopen-deposit-intent-v' . $request->version() . '-' . wp_generate_uuid4(), 'deposit_deadline_reopen_requested', wp_generate_uuid4(),
			array(
				'deposit_deadline_gmt' => $deadline, 'payment_ready' => 1, 'needs_reconciliation' => 1,
				'stock_reserved' => 1, 'stock_released' => 0, 'pause_reason' => $reason,
				'is_paused' => 0, 'paused_at_gmt' => null,
			)
		);
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}
		return $this->reconcileExternalIntentLocked( $intent );
	}

	public function acceptLatePayment( int $request_id, int $admin_id, string $reason ): PurchaseRequest|WP_Error {
		try {
			return $this->operation_lock->synchronized(
				$request_id,
				fn (): PurchaseRequest|WP_Error => $this->acceptLatePaymentLocked( $request_id, $admin_id, $reason )
			);
		} catch ( Throwable ) {
			return new WP_Error( 'zbm_request_busy', __( 'این پرداخت هم‌زمان در حال بررسی است؛ چند لحظه بعد دوباره تلاش کنید.', 'zarbalman-core' ) );
		}
	}

	private function acceptLatePaymentLocked( int $request_id, int $admin_id, string $reason ): PurchaseRequest|WP_Error {
		$request = $this->repository->find( $request_id );
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( ! $request || RequestState::LATE_PAYMENT_REVIEW !== $request->state() || ! $request->wcOrderId() ) {
			return new WP_Error( 'zbm_late_payment_state_invalid', __( 'این پرونده پرداخت دیرهنگام قابل پذیرش نیست.', 'zarbalman-core' ) );
		}
		if ( ! AdminCapabilities::userCanManageFinance( $admin_id ) || '' === $reason || $this->textLength( $reason ) > 1000 ) {
			return new WP_Error( 'zbm_late_payment_admin_invalid', __( 'دسترسی و دلیل مستند مدیریت معتبر نیست.', 'zarbalman-core' ) );
		}
		$intent = $this->deposit_payments->recoverableIntent( $request->wcOrderId() );
		$verified = is_array( $intent )
			? (
					( 'authority_issued' === (string) ( $intent['status'] ?? '' ) || DepositVerificationStatus::isReceived( (string) ( $intent['status'] ?? '' ) ) )
					? $this->zarinpal_verifier->verifyReceivedCallback( $request, (string) ( $intent['authority'] ?? '' ) )
					: $this->zarinpal_verifier->verifyDepositCompletion(
						$request,
						(string) ( $intent['authority'] ?? '' ),
						(string) ( $intent['reference'] ?? '' )
					)
			)
			: $this->zarinpal_verifier->verifyCapturedDeposit( $request );
		if ( is_wp_error( $verified ) ) {
			return new WP_Error( 'zbm_late_payment_not_verified', __( 'شناسه و مبلغ پرداخت دیرهنگام با رکورد قطعی زرین‌پال سازگار نیست.', 'zarbalman-core' ) );
		}
		$attempt = $this->deposit_payments->persistCallbackAttempt( $request->wcOrderId(), $verified['authority'], $verified['reference'] );
		if ( is_wp_error( $attempt ) ) {
			return $attempt;
		}
		$confirmed = $this->deposit_payments->confirmCallbackVerification( $request->wcOrderId(), $verified['authority'], $verified['reference'] );
		if ( is_wp_error( $confirmed ) ) {
			return $confirmed;
		}
		$stock_intent = $this->beginStockReservationIntent( $request, 'late-payment-stock-approval', $reason );
		if ( is_wp_error( $stock_intent ) ) {
			return $stock_intent;
		}
		$request = $stock_intent;
		try {
			$this->order_creator->reserveStockForVerifiedPayment( $request );
		} catch ( Throwable ) {
			return new WP_Error( 'zbm_late_payment_stock_unavailable', __( 'موجودی لازم برای پذیرش پرداخت دیرهنگام در دسترس نیست.', 'zarbalman-core' ) );
		}
		try {
			$this->order_creator->pinCapturedZarinpalAuthority( $request->wcOrderId(), $verified['authority'] );
			$this->order_creator->markRemotelyVerifiedPaidOrder( $request->wcOrderId(), $verified['reference'] );
		} catch ( Throwable ) {
			return new WP_Error( 'zbm_late_payment_order_recovery_failed', __( 'پرداخت تأیید شد اما بازیابی امن وضعیت سفارش انجام نشد؛ پرونده بدون تخصیص مالی متوقف ماند.', 'zarbalman-core' ) );
		}
		$reconciled = $this->deposit_payments->recordSuccessfulDeposit( $request->wcOrderId(), $verified['authority'], $verified['reference'] );
		if ( is_wp_error( $reconciled ) ) {
			return $reconciled;
		}
		$request = $this->repository->find( $request_id ) ?: $request;
		$result = $this->deposit_payments->acceptLateDeposit( $request_id, $admin_id, $reason );
		return $result;
	}

	private function beginStockReservationIntent( PurchaseRequest $request, string $event, string $reason = '' ): PurchaseRequest|WP_Error {
		if ( $request->stockReserved() && ! $request->stockReleased() ) {
			return $request;
		}
		$fields = array(
			'needs_reconciliation' => 1,
			'stock_operation'      => 'reserve_pending',
			'stock_reserved'       => 1,
			'stock_released'       => 0,
		);
		if ( '' !== $reason ) {
			$fields['pause_reason'] = $reason;
		}
		return $this->repository->transition(
			$request->id(), $request->state(), $request->state(), $request->version(),
			'system', 0, $event . '-v' . $request->version(), $event, wp_generate_uuid4(), $fields
		);
	}

	public function cancelUnpaidAdminReview(
		int $request_id,
		int $admin_id,
		int $expected_version,
		string $reason
	): PurchaseRequest|WP_Error {
		try {
			return $this->operation_lock->synchronized(
				$request_id,
				fn (): PurchaseRequest|WP_Error => $this->cancelUnpaidAdminReviewLocked( $request_id, $admin_id, $expected_version, $reason )
			);
		} catch ( Throwable ) {
			return new WP_Error( 'zbm_request_busy', __( 'این پرونده هم‌زمان در حال پردازش است؛ چند لحظه بعد دوباره تلاش کنید.', 'zarbalman-core' ) );
		}
	}

	private function cancelUnpaidAdminReviewLocked(
		int $request_id,
		int $admin_id,
		int $expected_version,
		string $reason
	): PurchaseRequest|WP_Error {
		$request = $this->repository->find( $request_id );
		$reason = trim( sanitize_textarea_field( $reason ) );
		if (
			! $request
			|| ! in_array( $request->state(), array( RequestState::ADMIN_REVIEW, RequestState::LATE_PAYMENT_REVIEW ), true )
			|| $request->version() !== $expected_version
			|| ! AdminCapabilities::userCanOperate( $admin_id )
			|| '' === $reason
			|| $this->textLength( $reason ) > 1000
		) {
			return new WP_Error( 'zbm_admin_cancel_invalid', __( 'درخواست، نسخه، دسترسی یا دلیل لغو معتبر نیست.', 'zarbalman-core' ) );
		}
		$expected_state = $request->state();
		if ( $request->wcOrderId() ) {
			try {
				$order_id = $request->wcOrderId();
				if ( $this->order_creator->hasZarinpalPaymentIdentity( $order_id ) ) {
					throw new RuntimeException( 'An issued Zarinpal Authority may still settle and cannot be cancelled safely.' );
				}
				$payment = $this->deposit_payments->paymentRecord( $order_id );
				if ( is_array( $payment ) ) {
					$status = (string) ( $payment['status'] ?? '' );
					if ( in_array( $status, array( 'captured', 'late_captured', 'late_accepted' ), true ) || DepositVerificationStatus::isVerified( $status ) ) {
						return new WP_Error( 'zbm_admin_cancel_verified_payment', __( 'این پرداخت در زرین‌پال قطعی شده و از مسیر لغو پرداخت‌نشده قابل بستن نیست.', 'zarbalman-core' ) );
					}
					if ( DepositVerificationStatus::isReceived( $status ) || DepositVerificationStatus::isPending( $status ) ) {
						$verification = DepositVerificationStatus::isReceived( $status )
							? $this->zarinpal_verifier->verifyReceivedCallback( $request, (string) ( $payment['authority'] ?? '' ) )
							: $this->zarinpal_verifier->verifyDepositCompletion(
								$request,
								(string) ( $payment['authority'] ?? '' ),
								(string) ( $payment['reference'] ?? '' )
							);
						if ( ! is_wp_error( $verification ) ) {
							if ( DepositVerificationStatus::isReceived( $status ) ) {
								$this->deposit_payments->persistCallbackAttempt( $order_id, $verification['authority'], $verification['reference'] );
							}
							$this->deposit_payments->confirmCallbackVerification( $order_id, $verification['authority'], $verification['reference'] );
							return new WP_Error( 'zbm_admin_cancel_verified_payment', __( 'استعلام مستقیم زرین‌پال پرداخت را قطعی اعلام کرد؛ لغو پرداخت‌نشده مجاز نیست.', 'zarbalman-core' ) );
						}
						if ( 'zbm_zarinpal_reverify_failed' !== $verification->get_error_code() ) {
							return new WP_Error( 'zbm_admin_cancel_verification_unavailable', __( 'تا زمانی که استعلام قطعی زرین‌پال در دسترس نباشد، لغو این پرونده مجاز نیست.', 'zarbalman-core' ) );
						}
						if ( DepositVerificationStatus::isReceived( $status ) ) {
							$this->deposit_payments->releaseUnverifiedCallbackReceipt( $order_id, (string) ( $payment['authority'] ?? '' ) );
						}
					}
					if ( ! $this->deposit_payments->expireUnverified( $order_id ) ) {
						throw new RuntimeException( 'Payment is verified or cannot be expired safely.' );
					}
				} elseif (
					RequestState::ADMIN_REVIEW !== $expected_state
					|| $request->paymentReady()
				) {
					throw new RuntimeException( 'Missing payment row is not a proven pre-payment failure.' );
				}
				$this->order_creator->cancelUnpaidOrder( $order_id, __( 'پروندهٔ پرداخت‌نشده پس از بررسی مستند مدیریت لغو شد.', 'zarbalman-core' ) );
			} catch ( Throwable ) {
				return new WP_Error( 'zbm_admin_cancel_paid_or_failed', __( 'لغو امن انجام نشد؛ وجود پرداخت قطعی یا وضعیت موجودی/سفارش باید بررسی شود.', 'zarbalman-core' ) );
			}
		}

		return $this->repository->transition(
			$request_id, $expected_state, RequestState::CANCELLED, $expected_version,
			'admin', $admin_id, 'cancel-admin-review-v' . $expected_version . '-' . wp_generate_uuid4(), 'admin_review_cancelled', wp_generate_uuid4(),
			array(
				'supplier_deadline_gmt' => null,
				'deposit_deadline_gmt' => null,
				'is_paused' => 0,
				'pause_reason' => $reason,
				'paused_at_gmt' => null,
				'paused_remaining_seconds' => null,
				'stock_released' => $request->wcOrderId() ? 1 : $request->stockReleased(),
			),
			array(
				array( 'role' => 'buyer', 'user_id' => $request->buyerId(), 'template' => 'admin_review_cancelled_buyer' ),
				array( 'role' => 'vendor', 'user_id' => $request->vendorId(), 'template' => 'admin_review_cancelled_vendor' ),
			)
		);
	}

	private function ownedVendorRequest( int $request_id, int $vendor_id ): PurchaseRequest|WP_Error {
		$request = $this->repository->find( $request_id );
		if (
			! $request
			|| $vendor_id < 1
			|| $request->vendorId() !== $vendor_id
			|| ! $this->vendor_eligibility->isApprovedVendor( $vendor_id )
		) {
			return new WP_Error( 'zbm_request_not_found', __( 'درخواست در دسترس نیست.', 'zarbalman-core' ) );
		}
		return $request;
	}

	/** @param array<string,mixed> $fields */
	private function moveAcceptedFailureToAdminReview( PurchaseRequest $request, string $correlation_id, array $fields = array() ): void {
		$this->repository->transition(
			$request->id(), RequestState::SUPPLIER_ACCEPTED, RequestState::ADMIN_REVIEW, $request->version(),
			'system', 0, 'managed-order-failed-v' . $request->version(), 'managed_order_creation_failed', $correlation_id,
			$fields,
			$this->reviewNotifications( $request, 'managed_order_creation_failed' )
		);
	}

	/** @return list<array<string,mixed>> */
	private function adminNotifications( string $template ): array {
		$notifications = array();
		foreach ( get_users( array( 'role' => 'administrator', 'fields' => 'ids' ) ) as $user_id ) {
			$user_id = (int) $user_id;
			if ( AdminCapabilities::userCanOperate( $user_id ) ) {
				$notifications[] = array( 'role' => 'admin', 'user_id' => $user_id, 'template' => $template );
			}
		}
		return $notifications;
	}

	/** @return list<array<string,mixed>> */
	private function reviewNotifications( PurchaseRequest $request, string $admin_template ): array {
		return array_merge(
			array(
				array( 'role' => 'buyer', 'user_id' => $request->buyerId(), 'template' => 'request_admin_review_buyer' ),
				array( 'role' => 'vendor', 'user_id' => $request->vendorId(), 'template' => 'request_admin_review_vendor' ),
			),
			$this->adminNotifications( $admin_template )
		);
	}

	private function textLength( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	private function validIdempotencyKey( string $value ): bool {
		$length = strlen( trim( $value ) );
		return $length >= 8 && $length <= 128;
	}

	private function validMobile( string $mobile ): bool {
		if ( '' === $mobile || ! function_exists( 'PWSMS' ) || ! is_object( PWSMS() ) ) {
			return false;
		}
		return ! method_exists( PWSMS(), 'validate_mobile' ) || (bool) PWSMS()->validate_mobile( $mobile );
	}
}
