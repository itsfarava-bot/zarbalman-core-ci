<?php

declare(strict_types=1);

namespace Zarbalman\Core\Support;

/** One canonical, non-mutating phone policy for profile display and request recipients. */
final class IranianMobile {
	public static function normalize( mixed $value ): string {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return '';
		}
		$value = strtr( trim( (string) $value ), array(
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
		) );
		// Accept formatting, not arbitrary text that happens to contain a phone.
		$value = preg_replace( '/[\s\p{Z}\x{200E}\x{200F}()\-]+/u', '', $value ) ?? '';
		if ( 1 === preg_match( '/\A(?:\+98|0098|98)(9[0-9]{9})\z/', $value, $matches ) ) {
			return '0' . $matches[1];
		}
		if ( 1 === preg_match( '/\A9[0-9]{9}\z/', $value ) ) {
			return '0' . $value;
		}
		return $value;
	}

	public static function isValid( mixed $value ): bool {
		return 1 === preg_match( '/\A09[0-9]{9}\z/', self::normalize( $value ) );
	}
}
