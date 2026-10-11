<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Finance;

interface DokanCreditPort {
	/** Spec 4 only. Implementations must be idempotent by allocation key. */
	public function creditOnce( int $vendor_id, int $order_id, int $amount_toman, string $allocation_key ): void;
}
