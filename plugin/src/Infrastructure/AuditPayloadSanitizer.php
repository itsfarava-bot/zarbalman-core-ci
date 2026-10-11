<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

final class AuditPayloadSanitizer {
	private const MAX_DEPTH = 4;
	private const MAX_ITEMS = 50;
	private const MAX_STRING_BYTES = 1000;
	private const SENSITIVE_KEYS = array(
		'address', 'postal_code', 'billing_phone', 'shipping_phone', 'phone', 'mobile', 'email',
		'customer_ip', 'ip_address', 'order_key', 'authority', 'reference', 'merchantcode',
		'access_token', 'password', 'nonce', 'secret', 'delivery_payload', 'delivery_payload_encrypted',
	);

	/** @param array<string,mixed> $payload
	 *  @return array<string,mixed>
	 */
	public function sanitize( array $payload ): array {
		return $this->sanitize_array( $payload, 0 );
	}

	/** @param array<mixed> $value
	 *  @return array<mixed>
	 */
	private function sanitize_array( array $value, int $depth ): array {
		if ( $depth >= self::MAX_DEPTH ) {
			return array( '_truncated' => true );
		}

		$clean = array();
		$count = 0;

		foreach ( $value as $key => $item ) {
			if ( ++$count > self::MAX_ITEMS ) {
				$clean['_truncated'] = true;
				break;
			}

			$clean_key = is_int( $key ) ? $key : sanitize_key( (string) $key );
			if ( '' === (string) $clean_key ) {
				continue;
			}
			if ( is_string( $clean_key ) && $this->is_sensitive_key( $clean_key ) ) {
				$clean[ $clean_key ] = '[redacted]';
				continue;
			}

			if ( is_array( $item ) ) {
				$clean[ $clean_key ] = $this->sanitize_array( $item, $depth + 1 );
			} elseif ( is_bool( $item ) || is_int( $item ) || is_float( $item ) || null === $item ) {
				$clean[ $clean_key ] = $item;
			} elseif ( is_string( $item ) ) {
				$text = function_exists( 'wp_check_invalid_utf8' ) ? wp_check_invalid_utf8( $item, true ) : $item;
				$text = sanitize_text_field( $text );
				$clean[ $clean_key ] = strlen( $text ) > self::MAX_STRING_BYTES
					? $this->utf8_byte_cut( $text, self::MAX_STRING_BYTES )
					: $text;
			}
		}

		return $clean;
	}

	private function is_sensitive_key( string $key ): bool {
		if ( in_array( $key, self::SENSITIVE_KEYS, true ) ) {
			return true;
		}
		foreach ( array( 'phone', 'mobile', 'email', 'postal', 'address', 'ip_address', 'order_key', 'access_token', 'password', 'secret', 'nonce' ) as $needle ) {
			if ( str_contains( $key, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private function utf8_byte_cut( string $value, int $max_bytes ): string {
		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $value, 0, $max_bytes, 'UTF-8' );
		}

		$cut = substr( $value, 0, $max_bytes );
		while ( '' !== $cut && 1 !== preg_match( '//u', $cut ) ) {
			$cut = substr( $cut, 0, -1 );
		}

		return $cut;
	}
}
