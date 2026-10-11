<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\PurchaseRequest;

final class RequestState {
	public const SUPPLIER_REVIEW           = 'supplier_review';
	public const SUPPLIER_ACCEPTED         = 'supplier_accepted';
	public const SUPPLIER_REJECTED         = 'supplier_rejected';
	public const SUPPLIER_RESPONSE_EXPIRED = 'supplier_response_expired';
	public const DEPOSIT_PENDING           = 'deposit_pending';
	public const DEPOSIT_PAID              = 'deposit_paid';
	public const DEPOSIT_EXPIRED           = 'deposit_expired';
	public const LATE_PAYMENT_REVIEW       = 'late_payment_review';
	public const ADMIN_REVIEW              = 'admin_review';
	public const CANCELLED                 = 'cancelled';

	/** @return list<string> */
	public static function all(): array {
		return array(
			self::SUPPLIER_REVIEW,
			self::SUPPLIER_ACCEPTED,
			self::SUPPLIER_REJECTED,
			self::SUPPLIER_RESPONSE_EXPIRED,
			self::DEPOSIT_PENDING,
			self::DEPOSIT_PAID,
			self::DEPOSIT_EXPIRED,
			self::LATE_PAYMENT_REVIEW,
			self::ADMIN_REVIEW,
			self::CANCELLED,
		);
	}

	public static function isTerminal( string $state ): bool {
		return in_array(
			$state,
			array( self::SUPPLIER_REJECTED, self::SUPPLIER_RESPONSE_EXPIRED, self::DEPOSIT_EXPIRED, self::CANCELLED ),
			true
		);
	}

	private function __construct() {}
}
