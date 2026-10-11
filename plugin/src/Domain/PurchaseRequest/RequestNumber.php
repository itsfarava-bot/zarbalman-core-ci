<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\PurchaseRequest;

use Exception;
use RuntimeException;

/** User-facing request reference. The UUID remains the internal correlation key. */
final class RequestNumber {
	public const MIN = 100000;
	public const MAX = 999999;

	public static function generate(): string {
		try {
			return (string) random_int( self::MIN, self::MAX );
		} catch ( Exception $exception ) {
			throw new RuntimeException( 'A secure request number could not be generated.', 0, $exception );
		}
	}

	public static function isValid( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[1-9][0-9]{5}\z/', $value );
	}

	/** Normalize Persian/Arabic numerals before validating a user-entered reference. */
	public static function normalize( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return strtr(
			trim( (string) $value ),
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
	}

	/** Deterministic migration candidate; the unique key still decides availability. */
	public static function fromInternalId( int $id ): string {
		if ( $id < 1 ) {
			throw new RuntimeException( 'A positive request ID is required.' );
		}
		return (string) ( self::MIN + ( ( $id - 1 ) % ( self::MAX - self::MIN + 1 ) ) );
	}
}
