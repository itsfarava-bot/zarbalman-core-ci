<?php

declare(strict_types=1);

namespace Zarbalman\Core\Application;

use Throwable;
use WP_Error;
use Zarbalman\Core\Domain\Finance\AdminTestAdvancePolicy;
use Zarbalman\Core\Domain\PurchaseRequest\ExecutionMode;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;
use Zarbalman\Core\Infrastructure\DependencyHealth;
use Zarbalman\Core\Infrastructure\PurchaseRequestOperationLock;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;
use Zarbalman\Core\Integration\ManagedOrderCreator;
use Zarbalman\Core\Security\AdminCapabilities;

final class AdminTestAdvanceService {
	public function __construct(
		private readonly PurchaseRequestRepository $repository,
		private readonly PurchaseRequestOperationLock $operation_lock,
		private readonly DependencyHealth $health,
		private readonly AdminTestModeManager $test_mode,
		private readonly ManagedOrderCreator $order_creator
	) {}

	public function advanceDeposit(
		int $request_id,
		int $admin_id,
		int $expected_version,
		string $reason,
		string $idempotency_key,
		string $correlation_id
	): PurchaseRequest|WP_Error {
		if ( ! AdminCapabilities::userCanOperate( $admin_id ) || ! AdminCapabilities::userCanManageTestMode( $admin_id ) ) {
			return new WP_Error( 'zbm_test_advance_forbidden', __( 'دسترسی تأیید آزمایشی برای این حساب فعال نیست.', 'zarbalman-core' ) );
		}
		$reason = trim( sanitize_textarea_field( $reason ) );
		$reason_length = function_exists( 'mb_strlen' ) ? mb_strlen( $reason, 'UTF-8' ) : strlen( $reason );
		if ( '' === $reason || $reason_length > 1000 || PurchaseRequestInput::containsContactData( $reason ) ) {
			return new WP_Error( 'zbm_test_advance_reason_invalid', __( 'دلیل اجباری معتبر بنویسید و اطلاعات تماس در آن قرار ندهید.', 'zarbalman-core' ) );
		}
		if ( ! wp_is_uuid( $idempotency_key ) || ! wp_is_uuid( $correlation_id ) ) {
			return new WP_Error( 'zbm_test_advance_identity_invalid', __( 'شناسه یکتای عملیات معتبر نیست؛ صفحه را تازه کنید.', 'zarbalman-core' ) );
		}

		try {
			return $this->operation_lock->synchronized(
				$request_id,
				fn (): PurchaseRequest|WP_Error => $this->advanceLocked(
					$request_id,
					$admin_id,
					$expected_version,
					$reason,
					$idempotency_key,
					$correlation_id
				)
			);
		} catch ( Throwable ) {
			return new WP_Error( 'zbm_test_advance_busy', __( 'پرونده هم‌زمان در حال پردازش است؛ وضعیت را تازه کنید.', 'zarbalman-core' ) );
		}
	}

	private function advanceLocked(
		int $request_id,
		int $admin_id,
		int $expected_version,
		string $reason,
		string $idempotency_key,
		string $correlation_id
	): PurchaseRequest|WP_Error {
		$request = $this->repository->find( $request_id );
		if ( ! $request ) {
			return new WP_Error( 'zbm_test_advance_not_found', __( 'درخواست در دسترس نیست.', 'zarbalman-core' ) );
		}
		if ( RequestState::DEPOSIT_PAID === $request->state() && ExecutionMode::TEST === $request->executionMode() ) {
			return $request;
		}
		if ( $request->version() !== $expected_version || $request->isPaused() || $request->needsReconciliation() ) {
			return new WP_Error( 'zbm_test_advance_stale', __( 'نسخه یا وضعیت پرونده تغییر کرده است؛ صفحه را تازه کنید.', 'zarbalman-core' ) );
		}

		if ( ! AdminTestAdvancePolicy::mayResume( $request->state(), $request->executionMode() ) ) {
			return new WP_Error( 'zbm_test_advance_retired', __( 'شروع پروندهٔ آزمایشی جدید بازنشسته شده است؛ این مسیر فقط یک پروندهٔ test_pending قدیمی را بازیابی می‌کند.', 'zarbalman-core' ) );
		}

		try {
			$order_id = $this->order_creator->createTestForAcceptedRequest( $request );
		} catch ( Throwable ) {
			return new WP_Error( 'zbm_test_order_failed', __( 'سفارش آزمایشی ساخته نشد؛ نیت آزمایشی برای بازیابی امن باقی ماند و هیچ پرداختی ثبت نشد.', 'zarbalman-core' ) );
		}
		if ( $order_id < 1 ) {
			return new WP_Error( 'zbm_test_order_invalid', __( 'شناسه سفارش آزمایشی معتبر نیست و هیچ پرداختی ثبت نشد.', 'zarbalman-core' ) );
		}

		$completed = $this->repository->transition(
			$request->id(), RequestState::SUPPLIER_ACCEPTED, RequestState::DEPOSIT_PAID, $request->version(),
			'admin', $admin_id, 'test-complete-' . $idempotency_key, 'admin_test_deposit_advanced', $correlation_id,
			array(
				'wc_order_id' => $order_id,
				'execution_mode' => ExecutionMode::TEST,
				'test_advance_reason' => $reason,
				'test_advanced_by' => $admin_id,
				'test_advanced_at_gmt' => current_time( 'mysql', true ),
				'supplier_deadline_gmt' => null,
				'deposit_deadline_gmt' => null,
				'payment_ready' => 0,
				'stock_reserved' => 1,
				'stock_released' => 0,
				'needs_reconciliation' => 0,
				'stock_operation' => null,
			)
		);
		if ( $completed instanceof PurchaseRequest ) {
			do_action( 'zbm_purchase_request_test_deposit_advanced', $completed->id(), $completed->version() );
		}
		return $completed;
	}
}
