<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Workflow;

use WP_Error;
use Zarbalman\Core\Infrastructure\WorkflowStore;

final class WorkflowService {
	public function __construct(
		private readonly StateMachine $state_machine,
		private readonly AuthorizationPolicy $authorization,
		private readonly WorkflowStore $store,
		private readonly TransitionPayloadValidator $payload_validator
	) {}

	/**
	 * Side-effect-free validation used before evidence files are read or stored.
	 * The full transition repeats every check under the store's normal
	 * optimistic/transactional rules, so a race after preflight still fails.
	 *
	 * @return true|array{duplicate:bool,event_uuid:string,from_state:string,to_state:string,version:int}|WP_Error
	 */
	public function preflight( TransitionRequest $request ): bool|array|WP_Error {
		if ( $request->order_id < 1 ) {
			return new WP_Error( 'zbm_invalid_order', __( 'شناسه سفارش معتبر نیست.', 'zarbalman-core' ) );
		}
		if ( strlen( $request->idempotency_key ) < 8 || strlen( $request->idempotency_key ) > 128 ) {
			return new WP_Error( 'zbm_invalid_idempotency_key', __( 'کلید یکتای عملیات معتبر نیست.', 'zarbalman-core' ) );
		}
		if ( ! wp_is_uuid( $request->correlation_id ) ) {
			return new WP_Error( 'zbm_invalid_correlation_id', __( 'شناسه رهگیری معتبر نیست.', 'zarbalman-core' ) );
		}
		$order = wc_get_order( $request->order_id );
		if ( ! $order ) {
			return new WP_Error( 'zbm_order_not_found', __( 'سفارش پیدا نشد.', 'zarbalman-core' ) );
		}
		$authorized = $this->authorization->authorize( $order, $request );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}
		$validated = $this->payload_validator->validateBeforeEvidence( $order, $request );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$replay = $this->store->replayIdentity( $validated );
		if ( null !== $replay ) {
			return $replay;
		}
		$from = $this->store->current_state( $validated->order_id );
		if ( ! $this->state_machine->can_transition( $from, $validated->to_state, $validated->actor_type ) ) {
			return new WP_Error( 'zbm_transition_forbidden', __( 'این تغییر وضعیت در این مرحله مجاز نیست.', 'zarbalman-core' ) );
		}
		$snapshot = $this->store->snapshot( $validated->order_id );
		if ( $snapshot['is_paused'] ) {
			return new WP_Error( 'zbm_workflow_paused', __( 'این سفارش توسط مدیریت متوقف شده است.', 'zarbalman-core' ) );
		}
		$deadline_error = $this->validate_deadline( $validated, $snapshot );
		if ( is_wp_error( $deadline_error ) ) {
			return $deadline_error;
		}
		if ( WorkflowDefinition::SELLER_WEIGHT_CONFIRMED === $validated->to_state ) {
			$weight_error = $this->validate_recorded_weights( $order, $snapshot, $validated );
			if ( is_wp_error( $weight_error ) ) {
				return $weight_error;
			}
		}
		return true;
	}

	/** @return array{duplicate:bool,event_uuid:string,from_state:string,to_state:string,version:int}|WP_Error */
	public function transition( TransitionRequest $request ): array|WP_Error {
		if ( $request->order_id < 1 ) {
			return new WP_Error( 'zbm_invalid_order', __( 'شناسه سفارش معتبر نیست.', 'zarbalman-core' ) );
		}

		if ( strlen( $request->idempotency_key ) < 8 || strlen( $request->idempotency_key ) > 128 ) {
			return new WP_Error( 'zbm_invalid_idempotency_key', __( 'کلید یکتای عملیات معتبر نیست.', 'zarbalman-core' ) );
		}

		if ( ! wp_is_uuid( $request->correlation_id ) ) {
			return new WP_Error( 'zbm_invalid_correlation_id', __( 'شناسه رهگیری معتبر نیست.', 'zarbalman-core' ) );
		}

		$order = wc_get_order( $request->order_id );
		if ( ! $order ) {
			return new WP_Error( 'zbm_order_not_found', __( 'سفارش پیدا نشد.', 'zarbalman-core' ) );
		}

		$authorized = $this->authorization->authorize( $order, $request );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$validated_request = $this->payload_validator->validate( $order, $request );
		if ( is_wp_error( $validated_request ) ) {
			return $validated_request;
		}

		$replay = $this->store->replay( $validated_request );
		if ( null !== $replay ) {
			return $replay;
		}

		$from = $this->store->current_state( $validated_request->order_id );
		if ( ! $this->state_machine->can_transition( $from, $validated_request->to_state, $validated_request->actor_type ) ) {
			return new WP_Error( 'zbm_transition_forbidden', __( 'این تغییر وضعیت در این مرحله مجاز نیست.', 'zarbalman-core' ) );
		}

		$snapshot = $this->store->snapshot( $request->order_id );
		if ( $snapshot['is_paused'] ) {
			return new WP_Error( 'zbm_workflow_paused', __( 'این سفارش توسط مدیریت متوقف شده است.', 'zarbalman-core' ) );
		}

		$deadline_error = $this->validate_deadline( $validated_request, $snapshot );
		if ( is_wp_error( $deadline_error ) ) {
			return $deadline_error;
		}

		if ( WorkflowDefinition::SELLER_WEIGHT_CONFIRMED === $validated_request->to_state ) {
			$weight_error = $this->validate_recorded_weights( $order, $snapshot, $validated_request );
			if ( is_wp_error( $weight_error ) ) {
				return $weight_error;
			}
		}

		$result = $this->store->apply( $validated_request, $from );
		if ( is_array( $result ) && ! $result['duplicate'] ) {
			do_action( 'zbm_core_workflow_transitioned', $result, $validated_request );
		}

		return $result;
	}

	/** @param array<string,mixed> $snapshot */
	private function validate_deadline( TransitionRequest $request, array $snapshot ): bool|WP_Error {
		if ( Actor::BUYER === $request->actor_type && WorkflowDefinition::DELIVERED === $snapshot['state'] ) {
			return $this->deadline_is_open( $snapshot['buyer_deadline_gmt'] ?? null )
				? true
				: new WP_Error( 'zbm_buyer_deadline_expired', __( 'مهلت دو ساعته بررسی پایان یافته و سفارش برای مدیریت متوقف شده است.', 'zarbalman-core' ) );
		}

		if ( Actor::VENDOR === $request->actor_type && WorkflowDefinition::BUYER_WEIGHT_RECORDED === $snapshot['state'] ) {
			return $this->deadline_is_open( $snapshot['vendor_deadline_gmt'] ?? null )
				? true
				: new WP_Error( 'zbm_vendor_deadline_expired', __( 'مهلت پاسخ به وزن خریدار پایان یافته و سفارش برای مدیریت متوقف شده است.', 'zarbalman-core' ) );
		}

		return true;
	}

	private function deadline_is_open( mixed $deadline ): bool {
		if ( ! is_string( $deadline ) || '' === $deadline ) {
			return false;
		}
		$timestamp = strtotime( $deadline . ' UTC' );
		return false !== $timestamp && $timestamp >= time();
	}

	/** @param array<string,mixed> $snapshot */
	private function validate_recorded_weights( \WC_Order $order, array $snapshot, TransitionRequest $request ): bool|WP_Error {
		if ( isset( $request->metadata['weights_tenths'] ) ) {
			return true;
		}

		$expected = count( $order->get_items( 'line_item' ) );
		$recorded = count(
			array_filter(
				$snapshot['weights'],
				static fn ( array $row ): bool => null !== $row['buyer_weight_tenths']
			)
		);

		return $expected > 0 && $recorded === $expected
			? true
			: new WP_Error( 'zbm_missing_buyer_weights', __( 'وزن کامل خریدار برای تأیید موجود نیست.', 'zarbalman-core' ) );
	}
}
