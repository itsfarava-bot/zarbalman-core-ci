<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Infrastructure\ManagedCaseRepository;

/** Authoritative online-issuance guard; read again after acquiring the shared lock. */
final class ManualPaymentRouteGuard {
	public function __construct( private readonly ManagedCaseRepository $cases ) {}

	public function blocksOnline( PurchaseRequest $request ): bool {
		try {
			$case = $this->cases->find( $request->id() );
		} catch ( \Throwable ) {
			return true;
		}
		if ( null === $case ) {
			return false;
		}
		if ( 'live' !== ( $case['mode'] ?? null ) ) {
			return true;
		}
		$manual = $case['data']['manual']['deposit'] ?? null;
		$funds = $case['data']['funds']['deposit']['amount'] ?? null;
		return is_array( $manual ) || ( is_int( $funds ) && $funds > 0 );
	}
}
