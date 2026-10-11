<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\PurchaseRequest;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class DeliveryWindow {
	private const EXACT    = 'exact';
	private const FLEXIBLE = 'flexible';

	private function __construct(
		private readonly string $type,
		private readonly string $start_gmt,
		private readonly string $end_gmt
	) {}

	public static function exact( string $start_gmt, string $end_gmt, string $now_gmt, string $business_timezone = 'UTC' ): self {
		return self::create( self::EXACT, $start_gmt, $end_gmt, $now_gmt, $business_timezone );
	}

	public static function flexible( string $start_gmt, string $end_gmt, string $now_gmt, string $business_timezone = 'UTC' ): self {
		return self::create( self::FLEXIBLE, $start_gmt, $end_gmt, $now_gmt, $business_timezone );
	}

	private static function create( string $type, string $start_gmt, string $end_gmt, string $now_gmt, string $business_timezone ): self {
		$utc       = new DateTimeZone( 'UTC' );
		$timezone  = new DateTimeZone( $business_timezone );
		$start     = self::parse( $start_gmt, $utc );
		$end       = self::parse( $end_gmt, $utc );
		$now       = self::parse( $now_gmt, $utc );
		$local_now = $now->setTimezone( $timezone );
		$tomorrow  = $local_now->setTime( 0, 0 )->modify( '+1 day' );
		$last_day  = $local_now->setTime( 23, 59, 59 )->modify( '+30 days' );
		$local_start = $start->setTimezone( $timezone );
		$local_end   = $end->setTimezone( $timezone );

		if ( $local_start < $tomorrow || $local_start > $last_day || $end <= $start || $local_end > $last_day ) {
			throw new InvalidArgumentException( 'Delivery window must be between tomorrow and 30 days from now.' );
		}

		if ( self::EXACT === $type && $local_start->format( 'Y-m-d' ) !== $local_end->format( 'Y-m-d' ) ) {
			throw new InvalidArgumentException( 'An exact delivery window must start and end on the same day.' );
		}

		return new self( $type, $start->format( 'Y-m-d H:i:s' ), $end->format( 'Y-m-d H:i:s' ) );
	}

	private static function parse( string $value, DateTimeZone $timezone ): DateTimeImmutable {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, $timezone );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			throw new InvalidArgumentException( 'Invalid GMT date.' );
		}

		return $date;
	}

	public function type(): string {
		return $this->type;
	}

	public function startGmt(): string {
		return $this->start_gmt;
	}

	public function endGmt(): string {
		return $this->end_gmt;
	}
}
