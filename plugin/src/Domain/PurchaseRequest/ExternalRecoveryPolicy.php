<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\PurchaseRequest;

final class ExternalRecoveryPolicy {
	public static function mustRepairStockFirst(
		string $state,
		bool $needs_reconciliation,
		string $stock_operation,
		int $order_id
	): bool {
		return RequestState::LATE_PAYMENT_REVIEW === $state
			&& $needs_reconciliation
			&& 'reserve_pending' === $stock_operation
			&& $order_id > 0;
	}
}
