<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Order;

use WP_Error;

/**
 * Exact one-decimal kilogram value stored as tenths of a kilogram.
 * Floating point arithmetic is deliberately avoided.
 */
final class WeightValue {
	private const MAX_TENTHS = 9999999999;

	/** @return int|WP_Error */
	public static function parse_tenths( mixed $raw ): int|WP_Error {
		if ( ! is_scalar( $raw ) ) {
			return self::invalid();
		}

		$value = trim( self::ascii_digits( (string) $raw ) );
		$value = str_replace( array( '٫', '،', ',' ), '.', $value );
		if ( strlen( $value ) > 32 ) {
			return self::invalid();
		}

		if ( 1 !== preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9])?$/', $value ) ) {
			return new WP_Error(
				'zbm_invalid_weight_precision',
				__( 'وزن باید یک عدد مثبت با حداکثر یک رقم اعشار باشد؛ مانند ۰٫۸ یا ۱۲٫۵.', 'zarbalman-core' )
			);
		}

		$parts  = explode( '.', $value, 2 );
		$whole  = (int) $parts[0];
		$decimal = isset( $parts[1] ) ? (int) $parts[1] : 0;

		if ( $whole > intdiv( self::MAX_TENTHS, 10 ) ) {
			return self::invalid();
		}

		$tenths = ( $whole * 10 ) + $decimal;
		if ( $tenths < 1 || $tenths > self::MAX_TENTHS ) {
			return self::invalid();
		}

		return $tenths;
	}

	public static function format_tenths( int $tenths ): string {
		$tenths = max( 0, $tenths );
		return intdiv( $tenths, 10 ) . '.' . ( $tenths % 10 );
	}

	private static function ascii_digits( string $value ): string {
		return strtr(
			$value,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
	}

	private static function invalid(): WP_Error {
		return new WP_Error( 'zbm_invalid_weight', __( 'وزن واردشده معتبر نیست.', 'zarbalman-core' ) );
	}
}
