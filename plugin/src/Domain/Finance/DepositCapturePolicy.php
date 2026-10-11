<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Finance;

use Zarbalman\Core\Domain\PurchaseRequest\RequestState;

final class DepositCapturePolicy {
	public static function canReceiveCallback( string $request_state ): bool {
		return in_array(
			$request_state,
			array(
				RequestState::DEPOSIT_PENDING,
				RequestState::DEPOSIT_EXPIRED,
				RequestState::ADMIN_REVIEW,
				RequestState::LATE_PAYMENT_REVIEW,
			),
			true
		);
	}

	private function __construct() {}
}
