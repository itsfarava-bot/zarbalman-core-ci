<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Workflow;

final class WorkflowDefinition {
	public const CREATED = 'created';
	public const SUPPLIER_REVIEW = 'supplier_review';
	public const DEPOSIT_PENDING = 'deposit_pending';
	public const DEPOSIT_PAID = 'deposit_paid';
	public const PREPARING = 'preparing';
	public const IN_TRANSIT = 'in_transit';
	public const DELIVERED = 'delivered';
	public const BUYER_WEIGHT_RECORDED = 'buyer_weight_recorded';
	public const SELLER_WEIGHT_CONFIRMED = 'seller_weight_confirmed';
	public const WEIGHT_DISPUTED = 'weight_disputed';
	public const ADMIN_REVIEW = 'admin_review';
	public const BALANCE_PENDING = 'balance_pending';
	public const BALANCE_PAID = 'balance_paid';
	public const ACCEPTANCE_WINDOW = 'acceptance_window';
	public const DISPUTE_OPEN = 'dispute_open';
	public const CANCELLATION_REQUESTED = 'cancellation_requested';
	public const RETURN_COORDINATION = 'return_coordination';
	public const COMPLETED = 'completed';
	public const CANCELLED = 'cancelled';

	/** @return list<string> */
	public function states(): array {
		return array(
			self::CREATED,
			self::SUPPLIER_REVIEW,
			self::DEPOSIT_PENDING,
			self::DEPOSIT_PAID,
			self::PREPARING,
			self::IN_TRANSIT,
			self::DELIVERED,
			self::BUYER_WEIGHT_RECORDED,
			self::SELLER_WEIGHT_CONFIRMED,
			self::WEIGHT_DISPUTED,
			self::ADMIN_REVIEW,
			self::BALANCE_PENDING,
			self::BALANCE_PAID,
			self::ACCEPTANCE_WINDOW,
			self::DISPUTE_OPEN,
			self::CANCELLATION_REQUESTED,
			self::RETURN_COORDINATION,
			self::COMPLETED,
			self::CANCELLED,
		);
	}

	/**
	 * Each edge contains the actor types permitted to request it. Resource-level
	 * ownership and WordPress capabilities are checked separately.
	 *
	 * @return array<string,array<string,list<string>>>
	 */
	public function transitions(): array {
		$admin_system = array( Actor::ADMIN, Actor::SYSTEM );
		$vendor_admin = array( Actor::VENDOR, Actor::ADMIN );
		$buyer_admin  = array( Actor::BUYER, Actor::ADMIN );

		return array(
			self::CREATED => array(
				self::DEPOSIT_PENDING => $admin_system,
			),
			self::DEPOSIT_PENDING => array(
				self::DEPOSIT_PAID          => array( Actor::SYSTEM ),
				self::CANCELLATION_REQUESTED => $buyer_admin,
			),
			self::DEPOSIT_PAID => array(
				self::SUPPLIER_REVIEW       => $admin_system,
				self::CANCELLATION_REQUESTED => array( Actor::ADMIN ),
			),
			self::SUPPLIER_REVIEW => array(
				self::PREPARING             => $vendor_admin,
				self::CANCELLATION_REQUESTED => $vendor_admin,
			),
			self::PREPARING => array(
				self::IN_TRANSIT             => $vendor_admin,
				self::CANCELLATION_REQUESTED => $vendor_admin,
			),
			self::IN_TRANSIT => array(
				self::DELIVERED => $vendor_admin,
			),
			self::DELIVERED => array(
				self::BUYER_WEIGHT_RECORDED => $buyer_admin,
				self::DISPUTE_OPEN          => $buyer_admin,
				self::ADMIN_REVIEW          => $admin_system,
			),
			self::BUYER_WEIGHT_RECORDED => array(
				self::SELLER_WEIGHT_CONFIRMED => $vendor_admin,
				self::WEIGHT_DISPUTED         => $vendor_admin,
				self::ADMIN_REVIEW            => $admin_system,
			),
			self::WEIGHT_DISPUTED => array(
				self::ADMIN_REVIEW => array( Actor::ADMIN ),
			),
			self::ADMIN_REVIEW => array(
				self::SELLER_WEIGHT_CONFIRMED => array( Actor::ADMIN ),
				self::CANCELLATION_REQUESTED => array( Actor::ADMIN ),
			),
			self::SELLER_WEIGHT_CONFIRMED => array(
				self::BALANCE_PENDING => array( Actor::SYSTEM ),
			),
			self::BALANCE_PENDING => array(
				self::BALANCE_PAID => array( Actor::SYSTEM ),
				self::DISPUTE_OPEN => $buyer_admin,
				self::RETURN_COORDINATION => $admin_system,
			),
			self::BALANCE_PAID => array(
				self::ACCEPTANCE_WINDOW => $admin_system,
			),
			self::ACCEPTANCE_WINDOW => array(
				self::COMPLETED    => array( Actor::BUYER, Actor::ADMIN, Actor::SYSTEM ),
				self::DISPUTE_OPEN => $buyer_admin,
			),
			self::DISPUTE_OPEN => array(
				self::ADMIN_REVIEW => array( Actor::ADMIN ),
			),
			self::CANCELLATION_REQUESTED => array(
				self::CANCELLED => array( Actor::ADMIN ),
			),
			self::RETURN_COORDINATION => array(
				self::CANCELLATION_REQUESTED => array( Actor::ADMIN ),
			),
		);
	}
}
