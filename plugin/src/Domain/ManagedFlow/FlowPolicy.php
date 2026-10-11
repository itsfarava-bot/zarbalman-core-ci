<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\ManagedFlow;

use WP_Error;

/** Pure logistics decisions. Caller verifies identity/capabilities and evidence ownership. */
final class FlowPolicy {
	private const ACTIONS = array(
		'awaiting_deposit' => array(),
		'preparing' => array( 'record_weight' => 'vendor', 'ready_delivery' => 'vendor', 'ready_pickup' => 'vendor', 'reopen_preparation' => 'admin' ),
		'ready_for_delivery' => array( 'dispatch' => 'vendor', 'reopen_preparation' => 'admin' ),
		'ready_for_pickup' => array( 'deliver' => 'vendor', 'reissue_code' => 'admin' ),
		'in_transit' => array( 'deliver' => 'vendor', 'reissue_code' => 'admin' ),
		'buyer_review' => array( 'propose_weight' => 'buyer', 'object' => 'buyer', 'timeout' => 'system', 'extend' => 'admin' ),
		'seller_review' => array( 'accept_weight' => 'vendor', 'object' => 'vendor', 'timeout' => 'system', 'extend' => 'admin' ),
		'admin_review' => array( 'reopen_buyer' => 'admin', 'reopen_seller' => 'admin', 'set_final_weight' => 'admin', 'reissue_code' => 'admin' ),
		'final_weight_locked' => array(),
		'balance_due' => array(),
		'completed' => array(),
		'return_review' => array( 'cancel' => 'admin' ),
		'cancelled' => array(),
	);

	/**
	 * @param array{mode?:string,paused?:bool,reason?:string,now?:int,deadline?:?int,
	 * weights_complete?:bool,evidence_complete?:bool,delivery_method?:string,code_valid?:bool,
	 * warning?:bool,acknowledged?:bool,final_locked?:bool,delivery_locked?:bool,delivered?:bool,
	 * buyer_weight_available?:bool,resume_state?:string,expected_resume_state?:string} $context
	 * All context is server-derived, not trusted POST flags. Missing timed-window data fails closed.
	 * @return array{state:string,window_seconds:?int}|WP_Error Null means no NEW window (caller handles preserving/clearing).
	 */
	public function decide( string $state, string $action, string $actor, array $context = array() ): array|WP_Error {
		$mode = $context['mode'] ?? 'live';
		if ( ! isset( self::ACTIONS[ $state ] ) || ! in_array( $mode, array( 'live', 'test' ), true ) ) {
			return $this->error( 'state' );
		}
		$global = in_array( $action, array( 'pause', 'resume', 'note', 'refer_return', 'extend_delivery' ), true ) && ! FlowState::isClosed( $state );
		$required = $global ? 'admin' : ( self::ACTIONS[ $state ][ $action ] ?? null );
		if ( null === $required || $actor !== $required ) {
			return $this->error( 'actor' );
		}
		if ( 'admin' === $actor && ( ! is_string( $context['reason'] ?? null ) || '' === trim( $context['reason'] ) || strlen( $context['reason'] ) > 1000 ) ) {
			return $this->error( 'reason' );
		}
		$paused = true === ( $context['paused'] ?? false );
		if ( ( $paused && ! in_array( $action, array( 'resume', 'note' ), true ) ) || ( ! $paused && 'resume' === $action ) ) {
			return $this->error( 'paused' );
		}
		if ( 'extend_delivery' === $action && ( true === ( $context['final_locked'] ?? true ) || false !== ( $context['delivered'] ?? null )
			|| ! in_array( $context['delivery_resume_state'] ?? null, OperationsData::PRE_DELIVERY, true )
			|| ! ( in_array( $state, OperationsData::PRE_DELIVERY, true ) || ( FlowState::ADMIN_REVIEW === $state && true === ( $context['delivery_window_overdue'] ?? false ) ) ) ) ) {
			return $this->error( 'recovery_stage' );
		}
		if ( in_array( $action, array( 'accept_weight', 'propose_weight', 'object', 'timeout' ), true ) ) {
			if ( ! is_int( $context['now'] ?? null ) || ! is_int( $context['deadline'] ?? null ) || $context['deadline'] < 1 ) {
				return $this->error( 'deadline' );
			}
			$expired = $context['now'] >= $context['deadline'];
			if ( ( 'timeout' === $action ) !== $expired ) {
				return $this->error( 'deadline' );
			}
		}
		if ( in_array( $action, array( 'record_weight', 'propose_weight', 'set_final_weight' ), true ) && true !== ( $context['weights_complete'] ?? false ) ) {
			return $this->error( 'weights' );
		}
		if ( in_array( $action, array( 'record_weight', 'propose_weight', 'object' ), true ) && true !== ( $context['evidence_complete'] ?? false ) ) {
			return $this->error( 'evidence' );
		}
		if ( 'object' === $action && ( ! is_string( $context['reason'] ?? null ) || '' === trim( $context['reason'] ) || strlen( $context['reason'] ) > 1000 ) ) {
			return $this->error( 'reason' );
		}
		if ( in_array( $action, array( 'record_weight', 'accept_weight', 'propose_weight', 'set_final_weight' ), true ) && true === ( $context['warning'] ?? false ) && true !== ( $context['acknowledged'] ?? false ) ) {
			return $this->error( 'acknowledgment' );
		}
		if ( in_array( $action, array( 'reopen_buyer', 'reopen_seller', 'set_final_weight', 'override_delivery', 'reissue_code' ), true ) && true === ( $context['final_locked'] ?? false ) ) {
			return $this->error( 'final_locked' );
		}
		if ( 'admin_review' === $state ) {
			$post_delivery = in_array( $action, array( 'reopen_buyer', 'reopen_seller', 'set_final_weight' ), true );
			$pre_delivery = in_array( $action, array( 'reissue_code', 'override_delivery' ), true );
			if ( ( $post_delivery && true !== ( $context['delivered'] ?? null ) ) || ( $pre_delivery && false !== ( $context['delivered'] ?? null ) ) ) {
				return $this->error( 'recovery_stage' );
			}
			if ( 'reopen_seller' === $action && true !== ( $context['buyer_weight_available'] ?? false ) ) {
				return $this->error( 'recovery_stage' );
			}
		}
		if ( 'deliver' === $action && ( true !== ( $context['code_valid'] ?? false ) || true === ( $context['delivery_locked'] ?? false ) ) ) {
			return $this->error( 'code' );
		}
		if ( 'reissue_code' === $action && 'admin_review' === $state ) {
			$resume = $context['resume_state'] ?? null;
			$expected = $context['expected_resume_state'] ?? null;
			if ( ! is_string( $resume ) || ! is_string( $expected ) || ! in_array( $expected, array( 'ready_for_pickup', 'in_transit' ), true ) || ! hash_equals( $expected, $resume ) ) {
				return $this->error( 'recovery_stage' );
			}
		}
		if ( in_array( $action, array( 'ready_delivery', 'ready_pickup' ), true ) ) {
			$method = 'ready_pickup' === $action ? 'pickup_from_vendor' : 'delivery_to_buyer_address';
			if ( true !== ( $context['weights_complete'] ?? false ) || $method !== ( $context['delivery_method'] ?? '' ) ) {
				return $this->error( 'readiness' );
			}
		}
		$next = match ( $action ) {
			'extend_delivery' => $context['delivery_resume_state'],
			'reopen_preparation' => FlowState::PREPARING,
			'ready_delivery' => FlowState::READY_FOR_DELIVERY,
			'ready_pickup' => FlowState::READY_FOR_PICKUP,
			'dispatch' => FlowState::IN_TRANSIT,
			'deliver', 'override_delivery', 'reopen_buyer' => FlowState::BUYER_REVIEW,
			'propose_weight', 'reopen_seller' => FlowState::SELLER_REVIEW,
			'object', 'timeout' => FlowState::ADMIN_REVIEW,
			'accept_weight', 'set_final_weight' => FlowState::FINAL_WEIGHT_LOCKED,
			'refer_return' => FlowState::RETURN_REVIEW,
			'cancel' => FlowState::CANCELLED,
			'reissue_code' => 'admin_review' === $state ? $context['resume_state'] : $state,
			default => $state,
		};
		$window = match ( $action ) {
			'deliver', 'override_delivery', 'reopen_buyer' => 7200,
			'propose_weight', 'reopen_seller' => 1800,
			default => null,
		};
		return array( 'state' => $next, 'window_seconds' => $window );
	}

	private function error( string $guard ): WP_Error {
		return new WP_Error( 'zbm_managed_' . $guard, __( 'این اقدام در وضعیت فعلی مجاز نیست؛ اطلاعات درخواست را تازه کنید.', 'zarbalman-core' ) );
	}
}
