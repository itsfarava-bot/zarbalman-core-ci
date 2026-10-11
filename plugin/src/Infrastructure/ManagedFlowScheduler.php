<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use Throwable;
use Zarbalman\Core\Application\ManagedFlowService;
use Zarbalman\Core\Application\ManualPaymentService;

/** Independent bounded queues for managed bootstrap, review expiry and Woo projections. */
final class ManagedFlowScheduler {
	public const BOOTSTRAP_HOOK = 'zbm_core_bootstrap_managed_case';
	public const DEADLINE_HOOK = 'zbm_core_expire_managed_case_review';
	public const SYNC_HOOK = 'zbm_core_sync_managed_case';
	public const DELIVERY_REMINDER_HOOK = 'zbm_core_remind_delivery_window';
	public const DELIVERY_OVERDUE_HOOK = 'zbm_core_expire_delivery_window';
	public const RECOVERY_HOOK = 'zbm_core_recover_managed_cases';
	private const BOOTSTRAP_OPTION = 'zbm_core_managed_bootstrap_sweep';
	private const REVIEW_OPTION = 'zbm_core_managed_review_sweep';
	private const SYNC_OPTION = 'zbm_core_managed_sync_sweep';
	private const DELIVERY_OPTION = 'zbm_core_managed_delivery_sweep';
	private const PAGE_SIZE = 25;

	public function __construct(
		private readonly PurchaseRequestRepository $requests,
		private readonly ManagedCaseRepository $cases,
		private readonly ManagedFlowService $flow,
		private readonly ManualPaymentService $manual
	) {}

	public function register(): void {
		add_action( 'zbm_purchase_request_deposit_paid', array( $this, 'scheduleBootstrap' ), 10, 2 );
		add_action( 'zbm_purchase_request_test_deposit_advanced', array( $this, 'scheduleBootstrap' ), 10, 2 );
		add_action( 'zbm_managed_deadline_changed', array( $this, 'scheduleDeadline' ), 10, 3 );
		add_action( 'zbm_managed_sync_requested', array( $this, 'scheduleSync' ), 10, 1 );
		add_action( 'zbm_managed_delivery_window_changed', array( $this, 'scheduleWindow' ), 10, 4 );
		add_action( self::BOOTSTRAP_HOOK, array( $this, 'bootstrap' ), 10, 1 );
		add_action( self::DEADLINE_HOOK, array( $this, 'expire' ), 10, 3 );
		add_action( self::SYNC_HOOK, array( $this, 'sync' ), 10, 1 );
		add_action( self::DELIVERY_REMINDER_HOOK, array( $this, 'deliveryTick' ), 10, 2 );
		add_action( self::DELIVERY_OVERDUE_HOOK, array( $this, 'deliveryTick' ), 10, 2 );
		add_action( self::RECOVERY_HOOK, array( $this, 'recover' ) );
		add_action( 'init', array( $this, 'ensureRecovery' ), 40 );
		add_action( 'action_scheduler_init', array( $this, 'ensureRecovery' ) );
		add_action( 'action_scheduler_ensure_recurring_actions', array( $this, 'ensureRecovery' ) );
		add_filter( 'cron_schedules', array( $this, 'cronSchedules' ) );
	}

	/** @param array<string,array<string,int|string>> $schedules @return array<string,array<string,int|string>> */
	public function cronSchedules( array $schedules ): array {
		$schedules['zbm_every_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display' => __( 'هر پنج دقیقه (پرونده‌های زربال‌من)', 'zarbalman-core' ),
		);
		return $schedules;
	}

	public function scheduleBootstrap( int $request_id, int $request_version = 0 ): void {
		if ( $request_id < 1 || $request_version < 0 ) {
			return;
		}
		// The event is emitted after payment commit. Finish only the Core transition
		// now so both dashboards see the next action without waiting for cron.
		try { $this->flow->synchronizeDeposit( $request_id ); } catch ( \Throwable ) {
			// A committed payment is never rolled back by a bootstrap failure.
		}
		$this->scheduleOnce( self::BOOTSTRAP_HOOK, time() + 1, array( $request_id ) );
	}

	public function scheduleDeadline( int $request_id, int $case_version, mixed $deadline_gmt ): void {
		if ( $request_id < 1 || $case_version < 1 || ! is_string( $deadline_gmt ) ) {
			return;
		}
		$timestamp = strtotime( $deadline_gmt . ' UTC' );
		if ( false === $timestamp ) {
			return;
		}
		$this->scheduleOnce( self::DEADLINE_HOOK, max( time() + 1, $timestamp ), array( $request_id, $case_version, $deadline_gmt ) );
	}

	public function scheduleSync( int $request_id ): void {
		if ( $request_id > 0 ) {
			$case = $this->cases->find( $request_id );
			$attempts = (int) ( $case['data']['recovery']['attempts'] ?? 0 );
			$delay = $attempts > 0 ? min( 3600, 15 * ( 2 ** min( 8, $attempts ) ) ) : 1;
			$this->scheduleOnce( self::SYNC_HOOK, time() + $delay, array( $request_id ) );
		}
	}

	public function scheduleWindow( int $request_id, int $generation, string $start_gmt, string $end_gmt ): void {
		$start = \Zarbalman\Core\Domain\ManagedFlow\Deadline::timestamp( $start_gmt );
		$end = \Zarbalman\Core\Domain\ManagedFlow\Deadline::timestamp( $end_gmt );
		if ( $request_id < 1 || $generation < 1 || ! is_int( $start ) || ! is_int( $end ) || $start >= $end ) { return; }
		$args = array( $request_id, $generation );
		$lead = ManagedFlowService::deliveryReminderSeconds();
		if ( $lead > 0 && time() < $start ) { $this->scheduleOnce( self::DELIVERY_REMINDER_HOOK, max( time() + 1, $start - $lead ), $args ); }
		$this->scheduleOnce( self::DELIVERY_OVERDUE_HOOK, max( time() + 1, $end ), $args );
	}

	public function deliveryTick( int $request_id, int $generation ): void {
		$this->flow->deliveryTick( $request_id, $generation );
	}

	public function bootstrap( int $request_id ): void {
		if ( $request_id > 0 ) {
			$this->flow->synchronizeDeposit( $request_id );
		}
	}

	public function expire( int $request_id, int $case_version, string $deadline_gmt ): void {
		// A secondary projection retry or an internal note advances the version,
		// but does not revoke an unchanged review deadline. The service still
		// locks and verifies the current version, stage, pause and exact deadline.
		$case = $this->cases->find( $request_id );
		if ( is_array( $case ) && $case['version'] >= $case_version && $case['deadline_gmt'] === $deadline_gmt ) {
			$this->flow->expireReview( $request_id, $case['version'], $deadline_gmt );
		}
	}

	public function sync( int $request_id ): void {
		if ( $request_id > 0 ) {
			$this->manual->recover( $request_id );
		}
	}

	/** Each queue has its own budget and cursor; one failure cannot starve another. */
	public function recover(): void {
		try {
			$this->recoverBootstrap();
		} catch ( Throwable ) {
			// Preserve the last persisted bootstrap cursor and retry on the next run.
		}
		try {
			$this->recoverReviews();
		} catch ( Throwable ) {
			// Review expiry has an exact event too; the next sweep remains a fallback.
		}
		try {
			$this->recoverSync();
		} catch ( Throwable ) {
			// Financial truth stays committed while the bounded projection queue retries.
		}
		try { $this->recoverDelivery(); } catch ( Throwable ) {
			// Independent finite delivery sweep cannot starve reviews or payment recovery.
		}
	}

	public function ensureRecovery(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::RECOVERY_HOOK, array(), Scheduler::GROUP ) ) {
				as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, self::RECOVERY_HOOK, array(), Scheduler::GROUP, true );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::RECOVERY_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'zbm_every_five_minutes', self::RECOVERY_HOOK );
		}
	}

	private function recoverBootstrap(): void {
		[ $cursor, $through ] = $this->sweep( self::BOOTSTRAP_OPTION, fn (): int => $this->requests->managedBootstrapUpperBound() );
		$page = $this->requests->scanManagedBootstrapIds( $cursor, $through, self::PAGE_SIZE );
		$this->saveSweep( self::BOOTSTRAP_OPTION, $page['next_cursor'], $through );
		foreach ( $page['ids'] as $request_id ) {
			$this->flow->synchronizeDeposit( $request_id );
		}
	}

	private function recoverReviews(): void {
		[ $cursor, $through ] = $this->sweep( self::REVIEW_OPTION, fn (): int => $this->cases->scanUpperBound() );
		$page = $this->cases->scanReviewIds( $cursor, $through, self::PAGE_SIZE );
		$this->saveSweep( self::REVIEW_OPTION, $page['next_cursor'], $through );
		foreach ( $page['ids'] as $request_id ) {
			try {
				$case = $this->cases->find( $request_id );
				if ( is_array( $case ) && is_string( $case['deadline_gmt'] ) ) {
					$this->flow->expireReview( $request_id, (int) $case['version'], $case['deadline_gmt'] );
				}
			} catch ( Throwable ) {
				// This ID is retried during the next finite sweep; later IDs still run now.
			}
		}
	}

	private function recoverSync(): void {
		[ $cursor, $through ] = $this->sweep( self::SYNC_OPTION, fn (): int => $this->cases->scanUpperBound() );
		$page = $this->cases->scanSyncIds( $cursor, $through, self::PAGE_SIZE );
		$this->saveSweep( self::SYNC_OPTION, $page['next_cursor'], $through );
		foreach ( $page['ids'] as $request_id ) {
			$this->manual->recover( $request_id );
		}
	}

	private function recoverDelivery(): void {
		[ $cursor, $through ] = $this->sweep( self::DELIVERY_OPTION, fn (): int => $this->cases->scanUpperBound() );
		$page = $this->cases->scanDeliveryIds( $cursor, $through, self::PAGE_SIZE );
		$this->saveSweep( self::DELIVERY_OPTION, $page['next_cursor'], $through );
		foreach ( $page['ids'] as $request_id ) {
			try {
				$case = $this->cases->find( $request_id );
				$window = $case['data']['operations']['window'] ?? null;
				if ( ! is_array( $window ) ) { continue; }
				$this->scheduleWindow( $request_id, $window['generation'], $window['start_gmt'], $window['end_gmt'] );
				$this->flow->deliveryTick( $request_id, $window['generation'] );
			} catch ( Throwable ) { /* Next ID still proceeds; this ID is retried in the next sweep. */ }
		}
	}

	/** @param callable():int $upper @return array{0:int,1:int} */
	private function sweep( string $option, callable $upper ): array {
		$stored = get_option( $option, array() );
		$cursor = is_array( $stored ) && is_int( $stored['cursor'] ?? null ) ? $stored['cursor'] : 0;
		$through = is_array( $stored ) && is_int( $stored['through'] ?? null ) ? $stored['through'] : 0;
		if ( $cursor < 0 || $through < 0 || $cursor > $through ) {
			$cursor = 0;
			$through = 0;
		}
		if ( $cursor >= $through ) {
			$cursor = 0;
			$through = $upper();
			$this->saveSweep( $option, $cursor, $through );
		}
		return array( $cursor, $through );
	}

	private function saveSweep( string $option, int $cursor, int $through ): void {
		if ( $cursor < 0 || $through < $cursor ) {
			throw new \RuntimeException( 'Managed sweep progress is invalid.' );
		}
		if ( ! update_option( $option, array( 'cursor' => $cursor, 'through' => $through ), false ) && get_option( $option ) !== array( 'cursor' => $cursor, 'through' => $through ) ) {
			throw new \RuntimeException( 'Managed sweep progress could not be persisted.' );
		}
	}

	/** @param list<int|string> $args */
	private function scheduleOnce( string $hook, int $timestamp, array $args ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( max( time() + 1, $timestamp ), $hook, $args, Scheduler::GROUP, true );
			return;
		}
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( max( time() + 1, $timestamp ), $hook, $args );
		}
	}
}
