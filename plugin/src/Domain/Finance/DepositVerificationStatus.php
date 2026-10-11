<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Finance;

use InvalidArgumentException;

/**
 * Durable stages between a provider callback and Core's atomic capture.
 *
 * The callback timing is encoded in the status itself so a delayed recovery
 * never turns an on-time payment into a late payment (or the reverse).
 */
final class DepositVerificationStatus {
	public const RECEIVED_ON_TIME = 'callback_received_on_time';
	public const RECEIVED_LATE    = 'callback_received_late';
	public const PENDING_ON_TIME = 'verification_pending_on_time';
	public const PENDING_LATE    = 'verification_pending_late';
	public const VERIFIED_ON_TIME = 'verified_on_time_pending';
	public const VERIFIED_LATE    = 'verified_late_pending';

	public static function pending( bool $on_time ): string {
		return $on_time ? self::PENDING_ON_TIME : self::PENDING_LATE;
	}

	public static function received( bool $on_time ): string {
		return $on_time ? self::RECEIVED_ON_TIME : self::RECEIVED_LATE;
	}

	public static function pendingFrom( string $status ): string {
		return match ( $status ) {
			self::RECEIVED_ON_TIME, self::PENDING_ON_TIME => self::PENDING_ON_TIME,
			self::RECEIVED_LATE, self::PENDING_LATE       => self::PENDING_LATE,
			default => throw new InvalidArgumentException( 'Payment callback status is invalid.' ),
		};
	}

	public static function verifiedFrom( string $status ): string {
		return match ( $status ) {
			self::PENDING_ON_TIME, self::VERIFIED_ON_TIME => self::VERIFIED_ON_TIME,
			self::PENDING_LATE, self::VERIFIED_LATE       => self::VERIFIED_LATE,
			default => throw new InvalidArgumentException( 'Payment verification status is invalid.' ),
		};
	}

	public static function isPending( string $status ): bool {
		return in_array( $status, array( self::PENDING_ON_TIME, self::PENDING_LATE ), true );
	}

	public static function isReceived( string $status ): bool {
		return in_array( $status, array( self::RECEIVED_ON_TIME, self::RECEIVED_LATE ), true );
	}

	public static function isVerified( string $status ): bool {
		return in_array( $status, array( self::VERIFIED_ON_TIME, self::VERIFIED_LATE ), true );
	}

	public static function isRecoverable( string $status ): bool {
		return self::isReceived( $status ) || self::isPending( $status ) || self::isVerified( $status );
	}

	public static function wasOnTime( string $status ): bool {
		return in_array( $status, array( self::RECEIVED_ON_TIME, self::PENDING_ON_TIME, self::VERIFIED_ON_TIME ), true );
	}

	private function __construct() {}
}
