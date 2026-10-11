<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Finance;

/** All flags originate from server records; not a payment or ownership authority. */
final class PaymentRoutingPolicy {
	public static function select( bool $online_available, bool $manual_enabled, bool $bank_obligation, bool $online_unknown ): string {
		if ( $online_unknown ) { return 'blocked'; }
		if ( $bank_obligation ) { return 'manual'; }
		if ( $online_available ) { return 'online'; }
		return $manual_enabled ? 'manual' : 'blocked';
	}
	private function __construct() {}
}
