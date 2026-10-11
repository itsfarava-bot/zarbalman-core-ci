<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\PurchaseRequest;

use InvalidArgumentException;

final class ExecutionMode {
	public const LIVE         = 'live';
	public const TEST_PENDING = 'test_pending';
	public const TEST         = 'test';

	/** @return list<string> */
	public static function all(): array {
		return array( self::LIVE, self::TEST_PENDING, self::TEST );
	}

	public static function assertValid( string $mode ): void {
		if ( ! in_array( $mode, self::all(), true ) ) {
			throw new InvalidArgumentException( 'Unknown purchase-request execution mode.' );
		}
	}

	public static function isTest( string $mode ): bool {
		self::assertValid( $mode );
		return in_array( $mode, array( self::TEST_PENDING, self::TEST ), true );
	}

	private function __construct() {}
}
