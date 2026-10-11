<?php

declare(strict_types=1);

namespace Zarbalman\Core\Application;

use RuntimeException;
use Throwable;
use WC_Order;
use WP_Error;
use Zarbalman\Core\Domain\Finance\DepositAllocation;
use Zarbalman\Core\Domain\Finance\DepositCapturePolicy;
use Zarbalman\Core\Domain\Finance\DepositVerificationStatus;
use Zarbalman\Core\Domain\PurchaseRequest\ExecutionMode;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;
use Zarbalman\Core\Infrastructure\AuditLogRepository;
use Zarbalman\Core\Infrastructure\LedgerRepository;
use Zarbalman\Core\Infrastructure\NotificationOutboxRepository;
use Zarbalman\Core\Infrastructure\PaymentRepository;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;
use Zarbalman\Core\Infrastructure\PurchaseRequestOperationLock;
use Zarbalman\Core\Infrastructure\Schema;
use Zarbalman\Core\Integration\ManagedOrderSnapshot;
use Zarbalman\Core\Security\AdminCapabilities;
use wpdb;

final class DepositPaymentService {
	private Schema $schema;

	public function __construct(
		private readonly wpdb $db,
		private readonly PurchaseRequestRepository $requests,
		private readonly PaymentRepository $payments,
		private readonly LedgerRepository $ledger,
		private readonly AuditLogRepository $audit,
		private readonly NotificationOutboxRepository $outbox,
		private readonly PurchaseRequestOperationLock $operation_lock
	) {
		$this->schema = new Schema( $db );
	}

	public function prepareExpected( PurchaseRequest $request, int $order_id ): bool|WP_Error {
		if ( ExecutionMode::LIVE !== $request->executionMode() ) {
			return new WP_Error( 'zbm_test_payment_forbidden', __( 'برای پروندهٔ آزمایشی رکورد پرداخت واقعی ساخته نمی‌شود.', 'zarbalman-core' ) );
		}
		return $this->payments->prepareExpectedDeposit( $request, $order_id );
	}

	public function markExpectedExpired( int $order_id ): void {
		if ( ! $this->payments->markExpiredByOrder( $order_id ) ) {
			throw new RuntimeException( 'Expected deposit could not be marked expired.' );
		}
	}

	public function expireUnverified( int $order_id ): bool {
		return $this->payments->expireUnverifiedByOrder( $order_id );
	}

	public function reopenExpected( int $order_id ): bool {
		return $this->payments->reopenExpectedByOrder( $order_id );
	}

	public function persistIssuedAuthority( int $order_id, string $authority ): bool|WP_Error {
		$order = wc_get_order( $order_id );
		$request = $this->requests->findByOrderId( $order_id );
		$authority = trim( $authority );
		if (
			! $order instanceof WC_Order
			|| ! $request
			|| ExecutionMode::LIVE !== $request->executionMode()
			|| ! ManagedOrderSnapshot::matches( $order, $request )
			|| 'WC_ZPal' !== $order->get_payment_method()
			|| ! $this->authorityBelongsToOrder( $order, $authority )
		) {
			return new WP_Error( 'zbm_payment_authority_invalid', __( 'شناسه صادرشده با سفارش مدیریت‌شده سازگار نیست.', 'zarbalman-core' ) );
		}
		return $this->payments->persistIssuedAuthority( $request, $order_id, $authority );
	}

	public function persistCallbackReceipt( int $order_id, string $authority ): string|WP_Error {
		$order = wc_get_order( $order_id );
		$request = $this->requests->findByOrderId( $order_id );
		$authority = trim( $authority );
		if (
			! $order instanceof WC_Order
			|| ! $request
			|| ExecutionMode::LIVE !== $request->executionMode()
			|| ! ManagedOrderSnapshot::matches( $order, $request )
			|| ! DepositCapturePolicy::canReceiveCallback( $request->state() )
			|| 'WC_ZPal' !== $order->get_payment_method()
			|| (int) $order->get_customer_id() !== $request->buyerId()
			|| $this->integerToman( (string) $order->get_total() ) !== $request->depositToman()
			|| ! $this->authorityBelongsToOrder( $order, $authority )
		) {
			return new WP_Error( 'zbm_payment_callback_receipt_invalid', __( 'ورود callback با سفارش مدیریت‌شده سازگار نیست.', 'zarbalman-core' ) );
		}
		$on_time = RequestState::DEPOSIT_PENDING === $request->state()
			&& $request->paymentReady()
			&& ! $request->isPaused()
			&& null !== $request->depositDeadlineGmt()
			&& $request->depositDeadlineGmt() > current_time( 'mysql', true );
		return $this->payments->persistCallbackReceipt( $request, $order_id, $authority, $on_time );
	}

	public function releaseUnverifiedCallbackReceipt( int $order_id, string $authority ): bool {
		$request = $this->requests->findByOrderId( $order_id );
		$payment_still_open = $request
			&& ExecutionMode::LIVE === $request->executionMode()
			&& RequestState::DEPOSIT_PENDING === $request->state()
			&& $request->paymentReady()
			&& ! $request->isPaused()
			&& null !== $request->depositDeadlineGmt()
			&& $request->depositDeadlineGmt() > current_time( 'mysql', true );
		return $this->payments->releaseUnverifiedCallbackReceipt( $order_id, trim( $authority ), (bool) $payment_still_open );
	}

	public function persistCallbackAttempt( int $order_id, string $authority, string $reference ): bool|WP_Error {
		$order = wc_get_order( $order_id );
		$request = $this->requests->findByOrderId( $order_id );
		$authority = trim( $authority );
		$reference = trim( $reference );
		if (
			! $order instanceof WC_Order
			|| ! $request
			|| ExecutionMode::LIVE !== $request->executionMode()
			|| ! ManagedOrderSnapshot::matches( $order, $request )
			|| ! DepositCapturePolicy::canReceiveCallback( $request->state() )
			|| 'WC_ZPal' !== $order->get_payment_method()
			|| (int) $order->get_customer_id() !== $request->buyerId()
			|| $this->integerToman( (string) $order->get_total() ) !== $request->depositToman()
			|| ! $this->authorityBelongsToOrder( $order, $authority )
			|| '' === $reference
			|| strlen( $reference ) > 128
		) {
			return new WP_Error( 'zbm_payment_callback_identity_invalid', __( 'هویت callback با درخواست پرداخت سازگار نیست.', 'zarbalman-core' ) );
		}
		$on_time = RequestState::DEPOSIT_PENDING === $request->state()
			&& $request->paymentReady()
			&& ! $request->isPaused()
			&& null !== $request->depositDeadlineGmt()
			&& $request->depositDeadlineGmt() > current_time( 'mysql', true );
		return $this->payments->persistVerificationAttempt( $request, $order_id, $authority, $reference, $on_time );
	}

	public function confirmCallbackVerification( int $order_id, string $authority, string $reference ): bool|WP_Error {
		return $this->payments->markVerificationConfirmed( $order_id, trim( $authority ), trim( $reference ) );
	}

	/** @return array<string,mixed>|null */
	public function recoverableIntent( int $order_id ): ?array {
		return $this->payments->recoverableIntentByOrder( $order_id );
	}

	/** @return array<string,mixed>|null */
	public function paymentRecord( int $order_id ): ?array {
		return $this->payments->findExpectedByOrder( $order_id );
	}

	public function recordSuccessfulDeposit( int $order_id, string $verified_authority, string $verified_reference ): bool|WP_Error {
		$request = $this->requests->findByOrderId( $order_id );
		if ( ! $request ) {
			return new WP_Error( 'zbm_payment_request_invalid', __( 'درخواست متناظر پرداخت معتبر نیست.', 'zarbalman-core' ) );
		}
		if ( ExecutionMode::LIVE !== $request->executionMode() ) {
			return new WP_Error( 'zbm_test_payment_forbidden', __( 'پروندهٔ آزمایشی نمی‌تواند اثر مالی واقعی ثبت کند.', 'zarbalman-core' ) );
		}
		try {
			return $this->operation_lock->synchronized(
				$request->id(),
				fn (): bool|WP_Error => $this->recordSuccessfulDepositLocked( $order_id, $verified_authority, $verified_reference )
			);
		} catch ( Throwable ) {
			return new WP_Error( 'zbm_payment_busy', __( 'پرداخت هم‌زمان در حال ثبت است؛ نتیجه بدون تغییر برای بازیابی باقی ماند.', 'zarbalman-core' ) );
		}
	}

	private function recordSuccessfulDepositLocked( int $order_id, string $verified_authority, string $verified_reference ): bool|WP_Error {
		$order = wc_get_order( $order_id );
		if (
			! $order instanceof WC_Order
			|| (
				'yes' !== $order->get_meta( '_zbm_managed_order', true )
				&& 'zarbalman-core-spec3' !== $order->get_created_via()
				&& null === $this->requests->findByOrderId( $order_id )
			)
		) {
			return new WP_Error( 'zbm_payment_order_invalid', __( 'سفارش پرداخت مدیریت‌شده معتبر نیست.', 'zarbalman-core' ) );
		}
		$request = $this->requests->findByOrderId( $order_id );
		if ( ! $request || $request->buyerId() !== (int) $order->get_customer_id() ) {
			return new WP_Error( 'zbm_payment_request_invalid', __( 'درخواست متناظر پرداخت معتبر نیست.', 'zarbalman-core' ) );
		}
		if ( ! ManagedOrderSnapshot::matches( $order, $request ) ) {
			return $this->recordExceptionState( $request, 'payment_snapshot_mismatch' );
		}
		if ( 'WC_ZPal' !== $order->get_payment_method() ) {
			return $this->recordExceptionState( $request, 'payment_gateway_mismatch' );
		}

		$authority = trim( $verified_authority );
		$reference = trim( $verified_reference );
		$paid_toman = $this->integerToman( (string) $order->get_total() );
		if (
			'' === $authority
			|| '' === $reference
			|| strlen( $authority ) > 128
			|| strlen( $reference ) > 128
			|| null === $paid_toman
			|| ! $this->authorityBelongsToOrder( $order, $authority )
			|| ! hash_equals( trim( (string) $order->get_transaction_id() ), $reference )
		) {
			return $this->recordExceptionState( $request, 'payment_identity_mismatch' );
		}

		$this->db->query( 'START TRANSACTION' );
		try {
			$payment_sql = $this->db->prepare(
				"SELECT * FROM %i WHERE order_id = %d AND provider = 'zarinpal' AND phase = 'deposit' ORDER BY id ASC LIMIT 1 FOR UPDATE",
				$this->schema->payments_table(), $order_id
			);
			$payment = $this->db->get_row( $payment_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			$request_sql = $this->db->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', $this->schema->purchase_requests_table(), $request->id() );
			$request_row = $this->db->get_row( $request_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			if ( ! is_array( $payment ) || ! is_array( $request_row ) ) {
				throw new RuntimeException( 'Expected payment or request is missing.' );
			}
			$locked = PurchaseRequest::fromRow( $request_row );
			if ( ExecutionMode::LIVE !== $locked->executionMode() ) {
				throw new RuntimeException( 'A test request cannot capture a real payment.' );
			}
			if ( 'zarinpal' !== (string) $payment['provider'] ) {
				throw new RuntimeException( 'Online capture selected a non-Zarinpal payment row.' );
			}
			if ( in_array( (string) $payment['status'], array( 'captured', 'late_captured', 'late_accepted' ), true ) ) {
				if ( hash_equals( (string) $payment['authority'], $authority ) && hash_equals( (string) $payment['reference'], $reference ) ) {
					$this->db->query( 'COMMIT' );
					if ( RequestState::DEPOSIT_PAID === $locked->state() ) {
						$this->announceDepositPaid( $locked->id(), $locked->version() );
					}
					return true;
				}
				throw new RuntimeException( 'Captured payment identity conflict.' );
			}
			$previous_payment_status = (string) $payment['status'];
			if ( ! DepositVerificationStatus::isVerified( $previous_payment_status ) ) {
				throw new RuntimeException( 'Payment is not in a capturable state.' );
			}
			if ( ! DepositCapturePolicy::canReceiveCallback( $locked->state() ) ) {
				throw new RuntimeException( 'Request state is terminal or cannot capture a deposit.' );
			}
			if ( (int) $payment['request_id'] !== $locked->id() || (int) $payment['expected_amount_toman'] !== $paid_toman || $locked->depositToman() !== $paid_toman ) {
				throw new RuntimeException( 'Captured amount mismatch.' );
			}
			$late = ! DepositVerificationStatus::wasOnTime( $previous_payment_status );
			$new_state = $late ? RequestState::LATE_PAYMENT_REVIEW : RequestState::DEPOSIT_PAID;
			$now = current_time( 'mysql', true );
			$updated_payment = $this->db->update(
				$this->schema->payments_table(),
				array(
					'paid_amount_toman' => $paid_toman,
					'status' => $late ? 'late_captured' : 'captured',
					'authority' => substr( $authority, 0, 128 ),
					'reference' => substr( $reference, 0, 128 ),
					'paid_at_gmt' => $now,
					'updated_at_gmt' => $now,
				),
				array( 'id' => (int) $payment['id'], 'status' => $previous_payment_status ),
				array( '%d', '%s', '%s', '%s', '%s', '%s' ), array( '%d', '%s' )
			);
			if ( 1 !== $updated_payment ) {
				throw new RuntimeException( 'Payment optimistic update failed.' );
			}
			$request_fields = array(
				'current_state'       => $new_state,
				'version'             => $locked->version() + 1,
				'deposit_deadline_gmt' => null,
				'needs_reconciliation' => 0,
				'stock_operation'      => null,
				'updated_at_gmt'      => $now,
			);
			$request_formats = array( '%s', '%d', '%s', '%d', '%s', '%s' );
			if ( ! $late ) {
				$request_fields['stock_reserved'] = 1;
				$request_fields['stock_released'] = 0;
				$request_formats[] = '%d';
				$request_formats[] = '%d';
			}
			$updated_request = $this->db->update(
				$this->schema->purchase_requests_table(),
				$request_fields,
				array( 'id' => $locked->id(), 'version' => $locked->version(), 'current_state' => $locked->state() ),
				$request_formats, array( '%d', '%d', '%s' )
			);
			if ( 1 !== $updated_request ) {
				throw new RuntimeException( 'Payment request transition failed.' );
			}

			$payment_id = (int) $payment['id'];
			$this->ledger->append( $locked->id(), $order_id, $payment_id, 'employer_cash_received', 'employer', 0, $paid_toman, 'recorded', "payment:{$payment_id}:cash" );
			if ( ! $late ) {
				$allocation = DepositAllocation::fromPaidAmount( $paid_toman );
				$this->ledger->append( $locked->id(), $order_id, $payment_id, 'platform_share_provisional', 'platform', 0, $allocation->platformShareToman(), 'provisional', "payment:{$payment_id}:platform" );
				$this->ledger->append( $locked->id(), $order_id, $payment_id, 'vendor_share_provisional', 'vendor', $locked->vendorId(), $allocation->vendorShareToman(), 'provisional', "payment:{$payment_id}:vendor" );
			}

			$event_uuid = wp_generate_uuid4();
			$event_key = $late ? 'late_deposit_captured' : 'deposit_paid';
			if ( false === $this->audit->append_resource(
				'request', $locked->id(), $order_id, $event_key, $locked->state(), $new_state,
				'system', 0, wp_generate_uuid4(), array( 'payment_id' => $payment_id, 'amount_toman' => $paid_toman ), $event_uuid
			) ) {
				throw new RuntimeException( 'Payment audit insert failed.' );
			}
			if ( $late ) {
				if ( ! $this->outbox->enqueue( $event_uuid, $locked->id(), 'buyer', $locked->buyerId(), 'late_payment_review_buyer', array( 'amount_toman' => $paid_toman ) )
					|| ! $this->outbox->enqueue( $event_uuid, $locked->id(), 'vendor', $locked->vendorId(), 'late_payment_review_vendor', array() ) ) {
					throw new RuntimeException( 'Late-payment participant notification enqueue failed.' );
				}
				foreach ( $this->adminIds() as $admin_id ) {
					if ( ! $this->outbox->enqueue( $event_uuid, $locked->id(), 'admin', $admin_id, 'late_payment_review', array( 'amount_toman' => $paid_toman ) ) ) {
						throw new RuntimeException( 'Late-payment notification enqueue failed.' );
					}
				}
			} else {
				if ( ! $this->outbox->enqueue( $event_uuid, $locked->id(), 'buyer', $locked->buyerId(), 'deposit_paid_buyer', array( 'amount_toman' => $paid_toman ) )
					|| ! $this->outbox->enqueue( $event_uuid, $locked->id(), 'vendor', $locked->vendorId(), 'deposit_paid_vendor', array() ) ) {
					throw new RuntimeException( 'Deposit notification enqueue failed.' );
				}
				}
				$this->db->query( 'COMMIT' );
				if ( ! $late ) {
					$this->announceDepositPaid( $locked->id(), $locked->version() + 1 );
				}
				return true;
		} catch ( Throwable ) {
			$this->db->query( 'ROLLBACK' );
			return $this->recordExceptionState( $request, 'payment_recording_failed' );
		}
	}

	public function acceptLateDeposit( int $request_id, int $admin_id, string $reason ): PurchaseRequest|WP_Error {
		$request = $this->requests->find( $request_id );
		$reason = trim( sanitize_textarea_field( $reason ) );
		$reason_length = function_exists( 'mb_strlen' ) ? mb_strlen( $reason, 'UTF-8' ) : strlen( $reason );
		if ( ! $request || ! AdminCapabilities::userCanManageFinance( $admin_id ) || '' === $reason || $reason_length > 1000 ) {
			return new WP_Error( 'zbm_late_payment_admin_invalid', __( 'درخواست، دسترسی یا دلیل مدیریت معتبر نیست.', 'zarbalman-core' ) );
		}
		if ( RequestState::DEPOSIT_PAID === $request->state() ) {
			return $request;
		}
		if ( RequestState::LATE_PAYMENT_REVIEW !== $request->state() || ! $request->wcOrderId() ) {
			return new WP_Error( 'zbm_late_payment_state_invalid', __( 'این پرونده پرداخت دیرهنگام قابل پذیرش نیست.', 'zarbalman-core' ) );
		}

		$this->db->query( 'START TRANSACTION' );
		try {
			$payment_sql = $this->db->prepare(
				"SELECT * FROM %i WHERE request_id = %d AND provider = 'zarinpal' AND phase = 'deposit' ORDER BY id ASC LIMIT 1 FOR UPDATE",
				$this->schema->payments_table(), $request_id
			);
			$payment = $this->db->get_row( $payment_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			$request_sql = $this->db->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', $this->schema->purchase_requests_table(), $request_id );
			$request_row = $this->db->get_row( $request_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			if ( ! is_array( $payment ) || ! is_array( $request_row ) ) {
				throw new RuntimeException( 'Late payment records are missing.' );
			}
			$locked = PurchaseRequest::fromRow( $request_row );
			$paid_toman = (int) ( $payment['paid_amount_toman'] ?? 0 );
			if (
				RequestState::LATE_PAYMENT_REVIEW !== $locked->state()
				|| 'late_captured' !== (string) $payment['status']
				|| $paid_toman !== $locked->depositToman()
				|| '' === (string) ( $payment['authority'] ?? '' )
				|| '' === (string) ( $payment['reference'] ?? '' )
			) {
				throw new RuntimeException( 'Late payment is not a verified exact capture.' );
			}
			$now = current_time( 'mysql', true );
			$payment_updated = $this->db->update(
				$this->schema->payments_table(),
				array( 'status' => 'late_accepted', 'updated_at_gmt' => $now ),
				array( 'id' => (int) $payment['id'], 'status' => 'late_captured' ),
				array( '%s', '%s' ), array( '%d', '%s' )
			);
			if ( 1 !== $payment_updated ) {
				throw new RuntimeException( 'Late payment optimistic update failed.' );
			}
			$request_updated = $this->db->update(
				$this->schema->purchase_requests_table(),
				array(
					'current_state' => RequestState::DEPOSIT_PAID,
					'version' => $locked->version() + 1,
					'is_paused' => 0,
					'pause_reason' => $reason,
					'paused_at_gmt' => null,
					'paused_remaining_seconds' => null,
					'deposit_deadline_gmt' => null,
					'stock_reserved' => 1,
					'stock_released' => 0,
					'needs_reconciliation' => 0,
					'stock_operation' => null,
					'updated_at_gmt' => $now,
				),
				array( 'id' => $locked->id(), 'version' => $locked->version() ),
				array( '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s' ),
				array( '%d', '%d' )
			);
			if ( 1 !== $request_updated ) {
				throw new RuntimeException( 'Late payment request update failed.' );
			}
			$allocation = DepositAllocation::fromPaidAmount( $paid_toman );
			$payment_id = (int) $payment['id'];
			$this->ledger->append( $locked->id(), $locked->wcOrderId() ?? 0, $payment_id, 'platform_share_provisional', 'platform', 0, $allocation->platformShareToman(), 'provisional', "payment:{$payment_id}:platform" );
			$this->ledger->append( $locked->id(), $locked->wcOrderId() ?? 0, $payment_id, 'vendor_share_provisional', 'vendor', $locked->vendorId(), $allocation->vendorShareToman(), 'provisional', "payment:{$payment_id}:vendor" );
			$event_uuid = wp_generate_uuid4();
			if ( false === $this->audit->append_resource(
				'request', $locked->id(), $locked->wcOrderId(), 'late_deposit_accepted', $locked->state(), RequestState::DEPOSIT_PAID,
				'admin', $admin_id, wp_generate_uuid4(), array( 'reason' => $reason, 'payment_id' => $payment_id, 'amount_toman' => $paid_toman ), $event_uuid
			) ) {
				throw new RuntimeException( 'Late payment audit insert failed.' );
			}
			if ( ! $this->outbox->enqueue( $event_uuid, $locked->id(), 'buyer', $locked->buyerId(), 'late_payment_accepted_buyer', array( 'amount_toman' => $paid_toman ) )
				|| ! $this->outbox->enqueue( $event_uuid, $locked->id(), 'vendor', $locked->vendorId(), 'late_payment_accepted_vendor', array() ) ) {
				throw new RuntimeException( 'Late payment acceptance notification failed.' );
			}
			$this->db->query( 'COMMIT' );
			$accepted = $this->requests->find( $request_id ) ?: throw new RuntimeException( 'Accepted late payment request cannot be loaded.' );
			$this->announceDepositPaid( $accepted->id(), $accepted->version() );
			return $accepted;
		} catch ( Throwable ) {
			$this->db->query( 'ROLLBACK' );
			return new WP_Error( 'zbm_late_payment_accept_failed', __( 'پذیرش پرداخت دیرهنگام انجام نشد و پرونده بدون تغییر در بررسی باقی ماند.', 'zarbalman-core' ) );
		}
	}

	public function recordUntrustedCompletion( int $order_id ): WP_Error {
		$request = $this->requests->findByOrderId( $order_id );
		if ( $request ) {
			try {
				return $this->operation_lock->synchronized(
					$request->id(),
					function () use ( $request, $order_id ): WP_Error {
						if ( $this->payments->recoverableIntentByOrder( $order_id ) ) {
							return $this->recordExceptionState( $request, 'untrusted_payment_completion' );
						}
						$fresh = $this->requests->find( $request->id() ) ?: $request;
						if ( in_array( $fresh->state(), array( RequestState::DEPOSIT_PENDING, RequestState::DEPOSIT_EXPIRED ), true ) ) {
							$this->requests->transition(
								$fresh->id(), $fresh->state(), RequestState::ADMIN_REVIEW, $fresh->version(),
								'system', 0, 'untrusted-no-proof-v' . $fresh->version(), 'untrusted_payment_without_proof',
								wp_generate_uuid4(), array(), $this->reviewNotifications( $fresh, 'untrusted_payment_without_proof' )
							);
						}
						return new WP_Error( 'zbm_payment_requires_review', __( 'پرداخت برای بررسی مدیریت متوقف شد.', 'zarbalman-core' ) );
					}
				);
			} catch ( Throwable ) {
				return new WP_Error( 'zbm_payment_busy', __( 'پرداخت برای بازیابی امن در صف باقی ماند.', 'zarbalman-core' ) );
			}
		}
		return new WP_Error( 'zbm_payment_request_invalid', __( 'درخواست متناظر پرداخت معتبر نیست.', 'zarbalman-core' ) );
	}

	private function recordExceptionState( PurchaseRequest $request, string $event ): WP_Error {
		$fresh = $this->requests->find( $request->id() ) ?: $request;
		if ( in_array( $fresh->state(), array( RequestState::DEPOSIT_PENDING, RequestState::DEPOSIT_EXPIRED, RequestState::ADMIN_REVIEW ), true ) ) {
			$this->requests->transition(
				$fresh->id(), $fresh->state(), RequestState::LATE_PAYMENT_REVIEW, $fresh->version(),
					'system', 0, $event . '-v' . $fresh->version(), $event, wp_generate_uuid4(), array(), $this->reviewNotifications( $fresh, $event )
			);
		}
		return new WP_Error( 'zbm_payment_requires_review', __( 'پرداخت برای بررسی مدیریت متوقف شد.', 'zarbalman-core' ) );
	}

	private function integerToman( string $amount ): ?int {
		$amount = trim( $amount );
		if ( 1 !== preg_match( '/\A([0-9]+)(?:\.0+)?\z/', $amount, $matches ) ) {
			return null;
		}
		$canonical = ltrim( $matches[1], '0' );
		if ( '' === $canonical ) {
			return null;
		}
		$maximum = (string) PHP_INT_MAX;
		if ( strlen( $canonical ) > strlen( $maximum ) || ( strlen( $canonical ) === strlen( $maximum ) && strcmp( $canonical, $maximum ) > 0 ) ) {
			return null;
		}
		return (int) $canonical;
	}

	private function authorityBelongsToOrder( WC_Order $order, string $authority ): bool {
		$current = trim( (string) $order->get_meta( '_zarinpal_authority', true ) );
		if ( '' !== $current && hash_equals( $current, $authority ) ) {
			return true;
		}
		$history = $order->get_meta( '_zarinpal_authority_history', true );
		if ( ! is_array( $history ) ) {
			return false;
		}
		foreach ( $history as $candidate ) {
			if ( is_string( $candidate ) && hash_equals( trim( $candidate ), $authority ) ) {
				return true;
			}
		}
		return false;
	}

	private function announceDepositPaid( int $request_id, int $version ): void {
		try {
			do_action( 'zbm_purchase_request_deposit_paid', $request_id, $version );
		} catch ( Throwable ) {
			// Payment is already committed; the managed five-minute bootstrap sweep is the fallback.
		}
	}

	/** @return list<int> */
	private function adminIds(): array {
		return array_values(
			array_filter(
				array_map( 'absint', get_users( array( 'role' => 'administrator', 'fields' => 'ids' ) ) ),
				static fn ( int $user_id ): bool => AdminCapabilities::userCanManageFinance( $user_id )
			)
		);
	}

	/** @return list<array<string,mixed>> */
	private function adminNotifications( string $template ): array {
		return array_map( static fn ( int $id ): array => array( 'role' => 'admin', 'user_id' => $id, 'template' => $template ), $this->adminIds() );
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
}
