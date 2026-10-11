<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Finance;

use InvalidArgumentException;

final class DepositAllocation {
	private function __construct(
		private readonly int $paid_toman,
		private readonly int $platform_share_toman,
		private readonly int $vendor_share_toman
	) {}

	public static function fromPaidAmount( int $paid_toman ): self {
		if ( $paid_toman < 10 ) {
			throw new InvalidArgumentException( 'Paid amount must support two positive integer-Toman allocations.' );
		}
		if ( $paid_toman > intdiv( PHP_INT_MAX - 50, 10 ) ) {
			throw new InvalidArgumentException( 'Paid amount is too large.' );
		}
		$platform = intdiv( $paid_toman * 10 + 50, 100 );
		return new self( $paid_toman, $platform, $paid_toman - $platform );
	}

	public function paidToman(): int { return $this->paid_toman; }
	public function platformShareToman(): int { return $this->platform_share_toman; }
	public function vendorShareToman(): int { return $this->vendor_share_toman; }
}
