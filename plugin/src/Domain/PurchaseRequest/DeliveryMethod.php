<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\PurchaseRequest;

use InvalidArgumentException;

final class DeliveryMethod {
	public const DELIVERY_TO_BUYER_ADDRESS = 'delivery_to_buyer_address';
	public const PICKUP_FROM_VENDOR         = 'pickup_from_vendor';

	public static function assertValid( string $method ): void {
		if ( ! in_array( $method, array( self::DELIVERY_TO_BUYER_ADDRESS, self::PICKUP_FROM_VENDOR ), true ) ) {
			throw new InvalidArgumentException( 'Invalid delivery method.' );
		}
	}

	private function __construct() {}
}
