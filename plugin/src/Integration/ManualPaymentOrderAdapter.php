<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use RuntimeException;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;

/** Narrow application port for recoverable manual-order side effects. */
final class ManualPaymentOrderAdapter {
	public function __construct( private readonly WooCommerceOrderAdapter $orders ) {}

	public function ensure( PurchaseRequest $request ): int {
		$order_id = $this->orders->ensureManualOrder( $request );
		if ( $order_id < 1 ) {
			throw new RuntimeException( 'Manual order adapter received no durable order ID.' );
		}
		return $order_id;
	}

	/** Recover only a previously captured, ledger-backed manual deposit. */
	public function depositConfirmed( PurchaseRequest $request, array $case ): void {
		$this->orders->repairConfirmedManualDepositOrder( $request, $case );
		if ( 'completed' === $case['state'] ) {
			$this->orders->projectManagedCompleted( $request );
		} else {
			$this->orders->projectManualDepositConfirmed( $request );
		}
	}

	public function completed( PurchaseRequest $request ): void {
		$this->orders->projectManagedCompleted( $request );
	}
}
