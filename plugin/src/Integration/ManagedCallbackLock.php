<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use RuntimeException;
use Zarbalman\Core\Infrastructure\PurchaseRequestOperationLockPort;

/**
 * Holds one request-scoped advisory lock from the first callback hook until
 * Core has durably reconciled the provider result.
 */
final class ManagedCallbackLock {
	/** @var array<int,int> order ID => request ID */
	private array $held = array();

	public function __construct( private readonly PurchaseRequestOperationLockPort $lock ) {}

	public function acquire( int $order_id, int $request_id, int $timeout_seconds = 30 ): void {
		if ( $order_id < 1 || $request_id < 1 ) {
			throw new RuntimeException( 'A managed callback lock requires valid resource IDs.' );
		}
		if ( isset( $this->held[ $order_id ] ) ) {
			if ( $this->held[ $order_id ] !== $request_id ) {
				throw new RuntimeException( 'A managed callback order cannot change request ownership.' );
			}
			return;
		}

		$this->lock->acquire( $request_id, $timeout_seconds );
		$this->held[ $order_id ] = $request_id;
	}

	public function isHeld( int $order_id, int $request_id ): bool {
		return $order_id > 0 && $request_id > 0 && ( $this->held[ $order_id ] ?? 0 ) === $request_id;
	}

	public function release( int $order_id ): void {
		$request_id = $this->held[ $order_id ] ?? 0;
		unset( $this->held[ $order_id ] );
		if ( $request_id > 0 ) {
			$this->lock->release( $request_id );
		}
	}

	public function releaseAll(): void {
		foreach ( array_keys( $this->held ) as $order_id ) {
			$this->release( (int) $order_id );
		}
	}
}
