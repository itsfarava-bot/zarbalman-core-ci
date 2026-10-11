<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use Zarbalman\Core\Domain\Workflow\Actor;
use Zarbalman\Core\Domain\Workflow\TransitionRequest;
use Zarbalman\Core\Domain\Workflow\WorkflowDefinition;
use Zarbalman\Core\Domain\Workflow\WorkflowService;

final class DeadlineScheduler {
	public const BUYER_HOOK = 'zbm_core_expire_buyer_review';
	public const VENDOR_HOOK = 'zbm_core_expire_vendor_weight';
	public const RECOVERY_HOOK = 'zbm_core_recover_operational_deadlines';

	public function __construct(
		private readonly WorkflowStore $store,
		private readonly WorkflowService $service
	) {}

	public function register(): void {
		add_action( 'zbm_core_workflow_transitioned', array( $this, 'schedule_for_transition' ), 10, 2 );
		add_action( 'zbm_core_workflow_controlled', array( $this, 'schedule_after_control' ), 10, 3 );
		add_action( 'action_scheduler_init', array( $this, 'ensure_recovery_action' ) );
		add_action( 'action_scheduler_ensure_recurring_actions', array( $this, 'ensure_recovery_action' ) );
		add_action( self::BUYER_HOOK, array( $this, 'expire_buyer_review' ), 10, 1 );
		add_action( self::VENDOR_HOOK, array( $this, 'expire_vendor_weight' ), 10, 1 );
		add_action( self::RECOVERY_HOOK, array( $this, 'recover_due_deadlines' ) );
	}

	/** @param array<string,mixed> $result */
	public function schedule_after_control( array $result, int $order_id, bool $paused ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BUYER_HOOK, array( $order_id ), Scheduler::GROUP );
			as_unschedule_all_actions( self::VENDOR_HOOK, array( $order_id ), Scheduler::GROUP );
		}
		if ( $paused || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$snapshot = $this->store->snapshot( $order_id );
		if ( $snapshot['buyer_deadline_gmt'] ) {
			$this->schedule_unique( self::BUYER_HOOK, $order_id, $snapshot['buyer_deadline_gmt'] );
		}
		if ( $snapshot['vendor_deadline_gmt'] ) {
			$this->schedule_unique( self::VENDOR_HOOK, $order_id, $snapshot['vendor_deadline_gmt'] );
		}
	}

	/** @param array<string,mixed> $result */
	public function schedule_for_transition( array $result, TransitionRequest $request ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$snapshot = $this->store->snapshot( $request->order_id );
		if ( WorkflowDefinition::DELIVERED === $request->to_state && $snapshot['buyer_deadline_gmt'] ) {
			$this->schedule_unique( self::BUYER_HOOK, $request->order_id, $snapshot['buyer_deadline_gmt'] );
		}
		if ( WorkflowDefinition::BUYER_WEIGHT_RECORDED === $request->to_state && $snapshot['vendor_deadline_gmt'] ) {
			$this->schedule_unique( self::VENDOR_HOOK, $request->order_id, $snapshot['vendor_deadline_gmt'] );
		}
	}

	public function ensure_recovery_action(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( as_has_scheduled_action( self::RECOVERY_HOOK, array(), Scheduler::GROUP ) ) {
			return;
		}
		as_schedule_recurring_action(
			time() + 5 * MINUTE_IN_SECONDS,
			5 * MINUTE_IN_SECONDS,
			self::RECOVERY_HOOK,
			array(),
			Scheduler::GROUP,
			true
		);
	}

	public function expire_buyer_review( int $order_id ): void {
		$this->expire( $order_id, 'buyer_review' );
	}

	public function expire_vendor_weight( int $order_id ): void {
		$this->expire( $order_id, 'vendor_weight' );
	}

	public function recover_due_deadlines(): void {
		foreach ( $this->store->due_deadlines( current_time( 'mysql', true ), 100 ) as $deadline ) {
			$this->expire( $deadline['order_id'], $deadline['deadline_type'] );
		}
	}

	private function schedule_unique( string $hook, int $order_id, string $deadline_gmt ): void {
		$timestamp = strtotime( $deadline_gmt . ' UTC' );
		if ( false === $timestamp ) {
			return;
		}
		as_schedule_single_action(
			max( time() + 1, $timestamp ),
			$hook,
			array( $order_id ),
			Scheduler::GROUP,
			true
		);
	}

	private function expire( int $order_id, string $type ): void {
		$snapshot = $this->store->snapshot( $order_id );
		if ( $snapshot['is_paused'] ) {
			return;
		}

		$expected_state = 'buyer_review' === $type
			? WorkflowDefinition::DELIVERED
			: WorkflowDefinition::BUYER_WEIGHT_RECORDED;
		$deadline = 'buyer_review' === $type
			? $snapshot['buyer_deadline_gmt']
			: $snapshot['vendor_deadline_gmt'];

		if ( $expected_state !== $snapshot['state'] || ! is_string( $deadline ) || '' === $deadline ) {
			return;
		}
		$timestamp = strtotime( $deadline . ' UTC' );
		if ( false === $timestamp || $timestamp > time() ) {
			return;
		}

		$this->service->transition(
			new TransitionRequest(
				$order_id,
				WorkflowDefinition::ADMIN_REVIEW,
				Actor::SYSTEM,
				0,
				'deadline:' . $type . ':' . $order_id . ':' . $deadline,
				wp_generate_uuid4(),
				array( 'timeout_type' => $type )
			)
		);
	}
}
