<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use Throwable;
use Zarbalman\Core\Application\PurchaseRequestService;
use Zarbalman\Core\Domain\PurchaseRequest\ExecutionMode;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;

final class PurchaseRequestScheduler {
	public const SUPPLIER_HOOK = 'zbm_expire_supplier_request';
	public const DEPOSIT_HOOK  = 'zbm_expire_deposit_request';
	public const RECOVERY_HOOK = 'zbm_recover_request_deadlines';

	public function __construct(
		private readonly PurchaseRequestRepository $repository,
		private readonly PurchaseRequestService $service,
		private ?NotificationOutboxRepository $outbox = null
	) {}

	public function register(): void {
		add_action( 'zbm_purchase_request_created', array( $this, 'scheduleSupplier' ), 10, 2 );
		add_action( 'zbm_purchase_request_deposit_pending', array( $this, 'scheduleDeposit' ), 10, 2 );
		add_action( self::SUPPLIER_HOOK, array( $this, 'expireSupplier' ), 10, 2 );
		add_action( self::DEPOSIT_HOOK, array( $this, 'expireDeposit' ), 10, 2 );
		add_action( self::RECOVERY_HOOK, array( $this, 'recover' ) );
		add_action( 'init', array( $this, 'ensureRecovery' ), 30 );
		add_filter( 'cron_schedules', array( $this, 'cronSchedules' ) );
	}

	/** @param array<string,array<string,int|string>> $schedules
	 *  @return array<string,array<string,int|string>>
	 */
	public function cronSchedules( array $schedules ): array {
		$schedules['zbm_every_minute'] = array( 'interval' => 60, 'display' => __( 'هر یک دقیقه (زربال‌من)', 'zarbalman-core' ) );
		return $schedules;
	}

	public function scheduleSupplier( int $request_id, int $version ): void {
		$request = $this->repository->find( $request_id );
		if ( $request && null !== $request->supplierDeadlineGmt() ) {
			$this->scheduleOnce( self::SUPPLIER_HOOK, $request->supplierDeadlineGmt(), array( $request_id, $request->version() ) );
			if ( ExecutionMode::LIVE === $request->executionMode() && RequestState::SUPPLIER_REVIEW === $request->state() && ! $request->isPaused() && $request->supplierDeadlineGmt() > current_time( 'mysql', true ) ) {
				try {
					$this->notificationOutbox()->enqueue(
						wp_generate_uuid4(), $request->id(), 'vendor', $request->vendorId(),
						NotificationOutboxRepository::SUPPLIER_REMINDER, array( 'deadline' => $request->supplierDeadlineGmt() )
					);
				} catch ( Throwable ) {
					// The request is committed. The bounded reminder sweep repairs a missed enqueue.
				}
			}
		}
	}

	public function scheduleDeposit( int $request_id, int $version ): void {
		$request = $this->repository->find( $request_id );
		if ( $request && null !== $request->depositDeadlineGmt() ) {
			$this->scheduleOnce( self::DEPOSIT_HOOK, $request->depositDeadlineGmt(), array( $request_id, $version ) );
		}
	}

	public function expireSupplier( int $request_id, int $version ): void {
		$this->service->expireSupplierReview( $request_id, $version );
	}

	public function expireDeposit( int $request_id, int $version ): void {
		$this->service->expireDeposit( $request_id, $version );
	}

	public function recover(): void {
		$now = current_time( 'mysql', true );
		// Independent budget; a missed creation/resume hook must not lose the reminder.
		try {
			foreach ( $this->notificationOutbox()->missingSupplierReminderIds() as $request_id ) {
				try { $this->scheduleSupplier( $request_id, 0 ); } catch ( Throwable ) {
					// Continue with later IDs; the next minute retries this request while still timely.
				}
			}
		} catch ( Throwable ) {
			// Reminder recovery must not prevent the existing expiry/payment recovery.
		}
		// Provider callbacks are reconciled before deadline expiry so a durable
		// on-time verification intent can never lose its stock reservation.
		foreach ( $this->repository->recoverable() as $request ) {
			$this->service->recoverIncomplete( $request );
		}
		foreach ( $this->repository->due( 'supplier_deadline_gmt', RequestState::SUPPLIER_REVIEW, $now ) as $row ) {
			$this->expireSupplier( $row['id'], $row['version'] );
		}
		foreach ( $this->repository->due( 'deposit_deadline_gmt', RequestState::DEPOSIT_PENDING, $now ) as $row ) {
			$this->expireDeposit( $row['id'], $row['version'] );
		}
	}

	public function ensureRecovery(): void {
		if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_next_scheduled_action( self::RECOVERY_HOOK, array(), Scheduler::GROUP ) ) {
				as_schedule_recurring_action( time() + 60, 60, self::RECOVERY_HOOK, array(), Scheduler::GROUP, true );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::RECOVERY_HOOK ) ) {
			wp_schedule_event( time() + 60, 'zbm_every_minute', self::RECOVERY_HOOK );
		}
	}

	private function notificationOutbox(): NotificationOutboxRepository {
		if ( null === $this->outbox ) {
			global $wpdb;
			$this->outbox = new NotificationOutboxRepository( $wpdb );
		}
		return $this->outbox;
	}

	/** @param list<int> $args */
	private function scheduleOnce( string $hook, string $deadline_gmt, array $args ): void {
		$timestamp = strtotime( $deadline_gmt . ' UTC' );
		if ( false === $timestamp ) {
			return;
		}
		$timestamp = max( time() + 1, $timestamp );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, $hook, $args, Scheduler::GROUP, true );
		} elseif ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( $timestamp, $hook, $args );
		}
	}
}
