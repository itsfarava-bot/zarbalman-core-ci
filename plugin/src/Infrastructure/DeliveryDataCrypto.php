<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use RuntimeException;

final class DeliveryDataCrypto {
	private const KEY_OPTION = 'zbm_core_delivery_key';
	private const KEY_CHECK_OPTION = 'zbm_core_delivery_key_check';
	private const PREFIX     = 'ZBMD2';
	private const KEY_PREFIX = 'v2:';
	private const KEY_CHECK_CONTEXT = 'zarbalman-delivery-key-check-v2';
	private const MAX_PLAINTEXT_BYTES = 12288;
	private const MAX_ENCODED_BYTES = 20000;

	public function ensureKey(): bool {
		$key = $this->key();
		if ( null !== $key ) {
			return $this->verifyOrCreateKeyCheck( $key );
		}
		if ( $this->hasEncryptedRows() ) {
			return false;
		}

		try {
			$encoded = self::KEY_PREFIX . base64_encode( random_bytes( 32 ) );
		} catch ( \Throwable ) {
			return false;
		}

		$existing = get_option( self::KEY_OPTION, '' );
		$stored = ( ! is_string( $existing ) || '' === $existing )
			? add_option( self::KEY_OPTION, $encoded, '', false )
			: update_option( self::KEY_OPTION, $encoded, false );
		$key = $this->key();
		return ( $stored || null !== $key ) && null !== $key && $this->verifyOrCreateKeyCheck( $key );
	}

	/** @param array<string,mixed> $payload */
	public function encrypt( array $payload ): string {
		$key = $this->key();
		if ( null === $key ) {
			throw new RuntimeException( 'Delivery encryption key is unavailable.' );
		}
		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_PLAINTEXT_BYTES ) {
			throw new RuntimeException( 'Delivery payload cannot be encoded safely.' );
		}

		try {
			if ( function_exists( 'sodium_crypto_secretbox' ) ) {
				$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$body  = 'S' . $nonce . sodium_crypto_secretbox( $json, $nonce, $key );
			} elseif ( $this->hasOpenSslGcm() ) {
				$iv = random_bytes( 12 );
				$tag = '';
				$ciphertext = openssl_encrypt( $json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::PREFIX, 16 );
				if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
					throw new RuntimeException( 'Delivery encryption failed.' );
				}
				$body = 'O' . $iv . $tag . $ciphertext;
			} else {
				throw new RuntimeException( 'Authenticated encryption is unavailable.' );
			}
		} catch ( \Throwable $error ) {
			throw new RuntimeException( 'Delivery encryption failed.', 0, $error );
		}

		return self::PREFIX . base64_encode( $body );
	}

	/** @return array<string,mixed> */
	public function decrypt( string $encoded ): array {
		$key = $this->key();
		if ( null === $key || ! str_starts_with( $encoded, self::PREFIX ) || strlen( $encoded ) > self::MAX_ENCODED_BYTES ) {
			throw new RuntimeException( 'Delivery data is unavailable.' );
		}
		$body = base64_decode( substr( $encoded, strlen( self::PREFIX ) ), true );
		if ( ! is_string( $body ) || strlen( $body ) < 30 ) {
			throw new RuntimeException( 'Delivery data is unavailable.' );
		}

		$algorithm = substr( $body, 0, 1 );
		$payload   = substr( $body, 1 );
		if ( 'S' === $algorithm && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$nonce = substr( $payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( substr( $payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $key );
		} elseif ( 'O' === $algorithm && $this->hasOpenSslGcm() ) {
			$plain = openssl_decrypt(
				substr( $payload, 28 ),
				'aes-256-gcm',
				$key,
				OPENSSL_RAW_DATA,
				substr( $payload, 0, 12 ),
				substr( $payload, 12, 16 ),
				self::PREFIX
			);
		} else {
			$plain = false;
		}
		if ( ! is_string( $plain ) ) {
			throw new RuntimeException( 'Delivery data integrity check failed.' );
		}
		$data = json_decode( $plain, true, 8, JSON_THROW_ON_ERROR );
		if ( ! is_array( $data ) || ! isset( $data['recipient_name'] ) ) {
			throw new RuntimeException( 'Delivery data is invalid.' );
		}

		$result = array(
			'recipient_name' => (string) $data['recipient_name'],
			'postal_code'    => isset( $data['postal_code'] ) ? (string) $data['postal_code'] : null,
			'address'        => isset( $data['address'] ) ? (string) $data['address'] : null,
		);
		if ( isset( $data['province_code'] ) && is_scalar( $data['province_code'] ) ) {
			$result['province_code'] = (string) $data['province_code'];
		}
		if ( isset( $data['province_name'] ) && is_scalar( $data['province_name'] ) ) {
			$result['province_name'] = (string) $data['province_name'];
		}
		foreach ( array( 'shipping_rate_per_kg_toman', 'estimated_shipping_toman' ) as $amount_key ) {
			if ( isset( $data[ $amount_key ] ) && is_int( $data[ $amount_key ] ) && $data[ $amount_key ] >= 0 ) {
				$result[ $amount_key ] = $data[ $amount_key ];
			}
		}
		if ( isset( $data['shipping_rate_version'] ) && is_scalar( $data['shipping_rate_version'] ) ) {
			$result['shipping_rate_version'] = (string) $data['shipping_rate_version'];
		}

		$has_pickup_data = array_key_exists( 'pickup_address', $data )
			|| array_key_exists( 'pickup_note', $data )
			|| array_key_exists( 'pickup_visit_confirmed', $data );
		if ( $has_pickup_data ) {
			if ( ! is_array( $data['pickup_address'] ?? null ) || true !== ( $data['pickup_visit_confirmed'] ?? null ) ) {
				throw new RuntimeException( 'Pickup delivery data is invalid.' );
			}
			$pickup_address = array();
			foreach ( array( 'store_name', 'street_1', 'street_2', 'city', 'zip', 'state', 'country' ) as $field ) {
				$value = $data['pickup_address'][ $field ] ?? null;
				if ( ! is_string( $value ) || $this->length( $value ) > 300 ) {
					throw new RuntimeException( 'Pickup delivery data is invalid.' );
				}
				$pickup_address[ $field ] = $value;
			}
			$pickup_note = $data['pickup_note'] ?? null;
			if ( null !== $pickup_note && ( ! is_string( $pickup_note ) || $this->length( $pickup_note ) > 500 ) ) {
				throw new RuntimeException( 'Pickup delivery data is invalid.' );
			}
			$result['pickup_address'] = $pickup_address;
			$result['pickup_note'] = $pickup_note;
			$result['pickup_visit_confirmed'] = true;
		}

		return $result;
	}

	private function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	private function key(): ?string {
		$encoded = get_option( self::KEY_OPTION, '' );
		if ( ! is_string( $encoded ) || ! str_starts_with( $encoded, self::KEY_PREFIX ) ) {
			return null;
		}
		$key = base64_decode( substr( $encoded, strlen( self::KEY_PREFIX ) ), true );
		return is_string( $key ) && 32 === strlen( $key ) ? $key : null;
	}

	private function verifyOrCreateKeyCheck( string $key ): bool {
		$expected = base64_encode( hash_hmac( 'sha256', self::KEY_CHECK_CONTEXT, $key, true ) );
		$stored = get_option( self::KEY_CHECK_OPTION, '' );
		if ( is_string( $stored ) && '' !== $stored ) {
			return hash_equals( $expected, $stored );
		}
		return add_option( self::KEY_CHECK_OPTION, $expected, '', false )
			|| hash_equals( $expected, (string) get_option( self::KEY_CHECK_OPTION, '' ) );
	}

	private function hasOpenSslGcm(): bool {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_decrypt' )
			&& in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
	}

	private function hasEncryptedRows(): bool {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}
		$table = ( new Schema( $wpdb ) )->purchase_requests_table();
		$like  = $wpdb->esc_like( $table );
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) ) {
			return false;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE delivery_payload_encrypted <> ''" ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted schema-generated table name.
	}
}
