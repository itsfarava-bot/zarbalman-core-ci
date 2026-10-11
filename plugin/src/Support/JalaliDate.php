<?php

declare(strict_types=1);

namespace Zarbalman\Core\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Dependency-free Jalali calendar conversion for user-facing dates.
 *
 * Core stores timestamps in Gregorian/GMT form. This class is only used at
 * the presentation and input boundaries, so operational comparisons remain
 * timezone-aware and independent from calendar formatting.
 */
final class JalaliDate {
	/** @var array<string,string> */
	private const DIGITS = array(
		'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
		'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
		'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
		'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
	);

	/** @var array<string,string> */
	private const PERSIAN_DIGITS = array(
		'0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
		'5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
	);

	/** @return array{0:int,1:int,2:int} */
	public static function fromGregorian( DateTimeInterface $date ): array {
		return self::toJalali(
			(int) $date->format( 'Y' ),
			(int) $date->format( 'n' ),
			(int) $date->format( 'j' )
		);
	}

	public static function format( DateTimeInterface $date, string $separator = '/', bool $persian_digits = true ): string {
		[$year, $month, $day] = self::fromGregorian( $date );
		$value = sprintf( '%04d%s%02d%s%02d', $year, $separator, $month, $separator, $day );
		return $persian_digits ? self::toPersianDigits( $value ) : $value;
	}

	public static function formatGmt( string $gmt, DateTimeZone $timezone, bool $include_time = true ): string {
		$utc = new DateTimeZone( 'UTC' );
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', trim( $gmt ), $utc );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			throw new InvalidArgumentException( 'Invalid GMT date.' );
		}

		$local = $date->setTimezone( $timezone );
		$value = self::format( $local );
		return $include_time ? $value . ' ' . self::toPersianDigits( $local->format( 'H:i' ) ) : $value;
	}

	public static function parse( string $value, DateTimeZone $timezone ): DateTimeImmutable {
		$value = trim( strtr( $value, self::DIGITS ) );
		$value = preg_replace( '/[.\-]+/', '/', $value ) ?: '';
		if ( 1 !== preg_match( '/\A([0-9]{4})\/([0-9]{1,2})\/([0-9]{1,2})\z/', $value, $matches ) ) {
			throw new InvalidArgumentException( 'Invalid Jalali date.' );
		}

		$year = (int) $matches[1];
		$month = (int) $matches[2];
		$day = (int) $matches[3];
		if ( $year < 1 || $year > 3000 || $month < 1 || $month > 12 || $day < 1 || $day > self::monthLength( $year, $month ) ) {
			throw new InvalidArgumentException( 'Invalid Jalali date.' );
		}

		[$gregorian_year, $gregorian_month, $gregorian_day] = self::toGregorian( $year, $month, $day );
		$date = DateTimeImmutable::createFromFormat(
			'!Y-m-d',
			sprintf( '%04d-%02d-%02d', $gregorian_year, $gregorian_month, $gregorian_day ),
			$timezone
		);
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			throw new InvalidArgumentException( 'Invalid Gregorian date.' );
		}

		return $date;
	}

	/** @return array{0:int,1:int,2:int} */
	public static function toGregorian( int $jalali_year, int $jalali_month, int $jalali_day ): array {
		if ( $jalali_year < 1 || $jalali_year > 3000 || $jalali_month < 1 || $jalali_month > 12 || $jalali_day < 1 || $jalali_day > self::monthLength( $jalali_year, $jalali_month ) ) {
			throw new InvalidArgumentException( 'Invalid Jalali date.' );
		}

		return self::toGregorianUnchecked( $jalali_year, $jalali_month, $jalali_day );
	}

	/** @return array{0:int,1:int,2:int} */
	private static function toGregorianUnchecked( int $jalali_year, int $jalali_month, int $jalali_day ): array {

		$jy = $jalali_year;
		$gy = $jy <= 979 ? 621 : 1600;
		$jy -= $jy <= 979 ? 0 : 979;
		$days = 365 * $jy
			+ intdiv( $jy, 33 ) * 8
			+ intdiv( ( $jy % 33 ) + 3, 4 )
			+ 78
			+ $jalali_day
			+ ( $jalali_month < 7 ? ( $jalali_month - 1 ) * 31 : ( ( $jalali_month - 7 ) * 30 + 186 ) );

		$gy += 400 * intdiv( $days, 146097 );
		$days %= 146097;
		if ( $days > 36524 ) {
			$gy += 100 * intdiv( --$days, 36524 );
			$days %= 36524;
			if ( $days >= 365 ) {
				++$days;
			}
		}
		$gy += 4 * intdiv( $days, 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			$gy += intdiv( $days - 1, 365 );
			$days = ( $days - 1 ) % 365;
		}

		$month_lengths = array( 31, self::isGregorianLeapYear( $gy ) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		$month = 1;
		while ( $month <= 12 && $days >= $month_lengths[ $month - 1 ] ) {
			$days -= $month_lengths[ $month - 1 ];
			++$month;
		}

		return array( $gy, $month, $days + 1 );
	}

	/** @return array{0:int,1:int,2:int} */
	public static function toJalali( int $gregorian_year, int $gregorian_month, int $gregorian_day ): array {
		if ( $gregorian_year < 1 || $gregorian_month < 1 || $gregorian_month > 12 || $gregorian_day < 1 || $gregorian_day > 31 ) {
			throw new InvalidArgumentException( 'Invalid Gregorian date.' );
		}

		$month_lengths = array( 31, self::isGregorianLeapYear( $gregorian_year ) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		if ( $gregorian_day > $month_lengths[ $gregorian_month - 1 ] ) {
			throw new InvalidArgumentException( 'Invalid Gregorian date.' );
		}

		$g_days_before_month = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
		$jy = $gregorian_year <= 1600 ? 0 : 979;
		$gy = $gregorian_year - ( $gregorian_year <= 1600 ? 621 : 1600 );
		$gy2 = $gregorian_month > 2 ? $gy + 1 : $gy;
		$days = 365 * $gy
			+ intdiv( $gy2 + 3, 4 )
			- intdiv( $gy2 + 99, 100 )
			+ intdiv( $gy2 + 399, 400 )
			- 80
			+ $gregorian_day
			+ $g_days_before_month[ $gregorian_month - 1 ];

		$jy += 33 * intdiv( $days, 12053 );
		$days %= 12053;
		$jy += 4 * intdiv( $days, 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			$jy += intdiv( $days - 1, 365 );
			$days = ( $days - 1 ) % 365;
		}

		$month = $days < 186 ? 1 + intdiv( $days, 31 ) : 7 + intdiv( $days - 186, 30 );
		$day = 1 + ( $days < 186 ? $days % 31 : ( $days - 186 ) % 30 );
		return array( $jy, $month, $day );
	}

	public static function toPersianDigits( string $value ): string {
		return strtr( $value, self::PERSIAN_DIGITS );
	}

	private static function monthLength( int $year, int $month ): int {
		if ( $month <= 6 ) {
			return 31;
		}
		if ( $month <= 11 ) {
			return 30;
		}
		return self::isLeapJalaliYear( $year ) ? 30 : 29;
	}

	private static function isLeapJalaliYear( int $year ): bool {
		try {
			$gregorian = self::toGregorianUnchecked( $year, 12, 30 );
			$jalali = self::toJalali( $gregorian[0], $gregorian[1], $gregorian[2] );
			return $jalali[0] === $year && 12 === $jalali[1] && 30 === $jalali[2];
		} catch ( InvalidArgumentException ) {
			return false;
		}
	}

	private static function isGregorianLeapYear( int $year ): bool {
		return 0 === $year % 400 || ( 0 === $year % 4 && 0 !== $year % 100 );
	}
}
