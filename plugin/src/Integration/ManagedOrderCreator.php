<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;

interface ManagedOrderCreator {
	/** Returns a WooCommerce order ID. */
	public function createForAcceptedRequest( PurchaseRequest $request ): int;

	/** Creates a payment-disabled managed order for an isolated administrator test flow. */
	public function createTestForAcceptedRequest( PurchaseRequest $request ): int;

	public function releaseReservedStock( PurchaseRequest $request ): void;

	public function releaseReservedStockByOrderId( int $order_id ): void;

	public function enablePayment( int $order_id ): void;

	public function disablePayment( int $order_id ): void;

	public function reopenPaymentAndReserveStock( PurchaseRequest $request ): void;

	/** Returns true only when this call newly reserved stock. */
	public function reserveStockForVerifiedPayment( PurchaseRequest $request ): bool;

	public function markRemotelyVerifiedPaidOrder( int $order_id, string $reference ): void;

	public function pinCapturedZarinpalAuthority( int $order_id, string $authority ): void;

	public function hasZarinpalPaymentIdentity( int $order_id ): bool;

	public function releaseVerifiedPaymentStock( int $order_id ): void;

	public function cancelUnpaidOrder( int $order_id, string $reason ): void;
}
