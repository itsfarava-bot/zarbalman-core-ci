<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\ManagedFlow;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OverflowException;

/** Exact UTC deadline arithmetic without WordPress-local timezone conversion. */
final class Deadline {
	public static function after( string $now_gmt, int $seconds ): string {
		if ( $seconds < 0 || $seconds > 31622400 ) {
			throw new InvalidArgumentException( 'Managed deadline interval is outside its bound.' );
		}
		$now = self::parse( $now_gmt );
		try {
			$next = $now->modify( '+' . $seconds . ' seconds' );
		} catch ( \Throwable $error ) {
			throw new OverflowException( 'Managed deadline cannot be represented.', 0, $error );
		}
		if ( ! $next instanceof DateTimeImmutable ) {
			throw new OverflowException( 'Managed deadline cannot be represented.' );
		}
		return $next->format( 'Y-m-d H:i:s' );
	}

	public static function remaining( string $deadline_gmt, string $now_gmt ): int {
		$deadline = self::parse( $deadline_gmt )->getTimestamp();
		$now = self::parse( $now_gmt )->getTimestamp();
		return max( 0, min( 604800, $deadline - $now ) );
	}

	public static function timestamp( ?string $gmt ): ?int {
		return null === $gmt ? null : self::parse( $gmt )->getTimestamp();
	}

	private static function parse( string $gmt ): DateTimeImmutable {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $gmt, new DateTimeZone( 'UTC' ) );
		if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $gmt ) {
			throw new InvalidArgumentException( 'Managed deadline is not a canonical UTC datetime.' );
		}
		return $date;
	}

	private function __construct() {}
}
