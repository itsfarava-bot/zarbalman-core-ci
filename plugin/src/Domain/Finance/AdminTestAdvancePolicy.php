<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Finance;

use Zarbalman\Core\Domain\PurchaseRequest\ExecutionMode;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;

final class AdminTestAdvancePolicy {
	public static function mayStart(
		string $request_state,
		string $execution_mode,
		bool $feature_enabled,
		bool $live_gateway_ready,
		bool $test_prerequisites_ready
	): bool {
		return RequestState::SUPPLIER_ACCEPTED === $request_state
			&& ExecutionMode::LIVE === $execution_mode
			&& $feature_enabled
			&& ! $live_gateway_ready
			&& $test_prerequisites_ready;
	}

	public static function mayResume( string $request_state, string $execution_mode ): bool {
		return RequestState::SUPPLIER_ACCEPTED === $request_state
			&& ExecutionMode::TEST_PENDING === $execution_mode;
	}

	private function __construct() {}
}
