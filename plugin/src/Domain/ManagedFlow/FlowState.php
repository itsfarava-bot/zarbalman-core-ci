<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\ManagedFlow;

final class FlowState {
	public const AWAITING_DEPOSIT = 'awaiting_deposit';
	public const PREPARING = 'preparing';
	public const READY_FOR_DELIVERY = 'ready_for_delivery';
	public const READY_FOR_PICKUP = 'ready_for_pickup';
	public const IN_TRANSIT = 'in_transit';
	public const BUYER_REVIEW = 'buyer_review';
	public const SELLER_REVIEW = 'seller_review';
	public const ADMIN_REVIEW = 'admin_review';
	public const FINAL_WEIGHT_LOCKED = 'final_weight_locked';
	public const BALANCE_DUE = 'balance_due';
	public const COMPLETED = 'completed';
	public const RETURN_REVIEW = 'return_review';
	public const CANCELLED = 'cancelled';

	/** @return list<string> Canonical managed states, never legacy workflow states. */
	public static function all(): array {
		return array( self::AWAITING_DEPOSIT, self::PREPARING, self::READY_FOR_DELIVERY, self::READY_FOR_PICKUP, self::IN_TRANSIT, self::BUYER_REVIEW, self::SELLER_REVIEW, self::ADMIN_REVIEW, self::FINAL_WEIGHT_LOCKED, self::BALANCE_DUE, self::COMPLETED, self::RETURN_REVIEW, self::CANCELLED );
	}

	/** Closed does not itself authorize retention cleanup; settlement must also be resolved. */
	public static function isClosed( string $state ): bool {
		return in_array( $state, array( self::COMPLETED, self::CANCELLED ), true );
	}
}
