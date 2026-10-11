<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\ManagedFlow;

/** Pure stage/generation filter. Provider sends and secret handling are outside it. */
final class OperationsNotificationPolicy {
	public const RETIRED = array( 'request_accepted_vendor', 'request_rejected_vendor', 'deposit_paid_buyer', 'deposit_paid_vendor', 'managed_ready_buyer', 'managed_dispatched_buyer', 'managed_delivery_code', 'managed_final_weight_locked', 'managed_buyer_review', 'managed_vendor_review' );
	public const CODE_LINKS = array( 'managed_pickup_available', 'managed_dispatch_available' );

	public static function allows( string $template, string $role, array $payload, array $case, string $now ): bool {
		if ( in_array( $template, self::RETIRED, true ) ) { return false; }
		$state = $case['state'];
		$window = $case['data']['operations']['window'] ?? null;
		$pre = in_array( $state, OperationsData::PRE_DELIVERY, true );
		$paused = true === $case['data']['pause']['active'];
		if ( in_array( $template, self::CODE_LINKS, true ) ) {
			return 'buyer' === $role && ! $paused && is_int( $payload['generation'] ?? null )
				&& $payload['generation'] === $case['data']['delivery']['generation']
				&& null === $case['data']['delivery']['delivered_at']
				&& is_string( $case['data']['delivery']['encrypted_code'] )
				&& $case['data']['delivery']['attempts'] < 5
				&& $state === ( 'managed_pickup_available' === $template ? FlowState::READY_FOR_PICKUP : FlowState::IN_TRANSIT )
				&& ( ! is_array( $window ) || $now < $window['end_gmt'] );
		}
		if ( in_array( $template, array( 'managed_delivery_reminder', 'managed_delivery_overdue', 'managed_delivery_extended' ), true ) ) {
			if ( ! is_array( $window ) || ( $payload['window_generation'] ?? null ) !== $window['generation'] || $paused ) { return false; }
			return match ( $template ) {
				'managed_delivery_reminder' => $pre && null !== $window['reminded_at'] && $now < $window['start_gmt'],
				'managed_delivery_overdue' => FlowState::ADMIN_REVIEW === $state && null !== $window['overdue_at'],
				default => $pre && $now < $window['end_gmt'],
			};
		}
		if ( in_array( $template, array( 'managed_received_weight_due', 'managed_received_weight_review', 'managed_deadline_extended', 'managed_review_reopened' ), true ) ) {
			if ( $paused || ! is_string( $case['deadline_gmt'] ) || $now >= $case['deadline_gmt'] || ( $payload['deadline'] ?? null ) !== $case['deadline_gmt'] ) { return false; }
			return match ( $template ) {
				'managed_received_weight_due' => 'buyer' === $role && FlowState::BUYER_REVIEW === $state,
				'managed_received_weight_review' => 'vendor' === $role && FlowState::SELLER_REVIEW === $state,
				default => ( 'buyer' === $role && FlowState::BUYER_REVIEW === $state ) || ( 'vendor' === $role && FlowState::SELLER_REVIEW === $state ) || 'admin' === $role,
			};
		}
		return match ( $template ) {
			'managed_deposit_received_buyer' => 'buyer' === $role && $pre,
			'managed_preparation_started', 'managed_preparation_reopened' => 'vendor' === $role && ! $paused && FlowState::PREPARING === $state,
			'managed_code_locked' => FlowState::ADMIN_REVIEW === $state && $case['data']['delivery']['attempts'] >= 5,
			'managed_objection_review', 'managed_silence_review' => FlowState::ADMIN_REVIEW === $state,
			'managed_paused' => $paused,
			'managed_resumed' => ! $paused && ! FlowState::isClosed( $state ),
			'managed_weight_final_buyer' => 'buyer' === $role && null !== $case['data']['final']['locked_at'] && ! in_array( $case['data']['spec4']['substate'] ?? '', array( 'balance_due', 'receipt_review', 'warning', 'completed', 'closed' ), true ),
			'managed_weight_final_vendor' => 'vendor' === $role && null !== $case['data']['final']['locked_at'],
			'managed_return_review' => in_array( $state, array( FlowState::RETURN_REVIEW, FlowState::CANCELLED ), true ),
			default => true,
		};
	}
}
