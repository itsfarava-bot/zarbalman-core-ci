<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Finance;

use InvalidArgumentException;
use Zarbalman\Core\Domain\ManagedFlow\Amounts;

/** Integer IRT allocations. The complement owns rounding, so no money disappears. */
final class Spec4AllocationPolicy {
	public static function completed( int $total, int $deposit, int $balance ): array {
		if ( $total < 1 || 0 !== Amounts::remaining( $total, $deposit, $balance ) ) { throw new InvalidArgumentException( 'Completion requires exact funds.' ); }
		$split = Amounts::commission( $total );
		return array( 'base' => $total, 'site' => $split['commission'], 'vendor' => $split['net'], 'refund' => 0 );
	}

	/** Latest owner rule: half of actual deposit, not five percent of a changed final price. */
	public static function nonpaymentReturn( int $deposit, int $balance, bool $return_confirmed ): array {
		if ( $deposit < 1 || 0 !== $balance || ! $return_confirmed ) { throw new InvalidArgumentException( 'Actual return and an unpaid balance are required.' ); }
		$vendor = intdiv( $deposit, 2 ) + $deposit % 2;
		return array( 'base' => $deposit, 'site' => $deposit - $vendor, 'vendor' => $vendor, 'refund' => 0 );
	}

	/** The caller must supply an authorized immutable estimate/final basis and proved cash. */
	public static function refund( int $basis, int $paid, string $trigger ): array {
		if ( $basis < 1 || $paid < 1 || ! in_array( $trigger, array( 'seller_incapacity', 'paid_cancellation' ), true ) ) { throw new InvalidArgumentException( 'Invalid refund basis.' ); }
		$site = intdiv( $basis, 100 ) + ( $basis % 100 >= 50 ? 1 : 0 );
		if ( $site > $paid ) { throw new InvalidArgumentException( 'Retention cannot exceed actual cash.' ); }
		return array( 'base' => $basis, 'site' => $site, 'vendor' => 0, 'refund' => $paid - $site );
	}

	private function __construct() {}
}
