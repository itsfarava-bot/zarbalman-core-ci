<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use Zarbalman\Core\Domain\ManagedFlow\DeliveryCode;

/** Durable HMAC key. Missing/corrupt keys are never replaced after any managed case exists. */
final class DeliveryCodeSecret {
	private const OPTION = 'zbm_core_delivery_code_secret';
	private const CHECK_OPTION = 'zbm_core_delivery_code_secret_check';
	private const PREFIX = 'v1:';
	private const CHECK_CONTEXT = 'zarbalman-managed-delivery-code-key-v1';

	public function __construct( private readonly \wpdb $db ) {}

	public function loadOrCreate(): ?DeliveryCode {
		$key = $this->key();
		if ( null !== $key ) {
			return $this->validCheck( $key ) ? new DeliveryCode( $key ) : null;
		}
		$stored = get_option( self::OPTION, null );
		if ( null !== $stored && false !== $stored && '' !== $stored ) {
			return null;
		}
		if ( $this->hasManagedCase() ) {
			return null;
		}
		try {
			$encoded = self::PREFIX . base64_encode( random_bytes( 32 ) );
		} catch ( \Throwable ) {
			return null;
		}
		if ( ! add_option( self::OPTION, $encoded, '', false ) && $encoded !== get_option( self::OPTION, '' ) ) {
			$key = $this->key();
			return null !== $key && $this->validCheck( $key ) ? new DeliveryCode( $key ) : null;
		}
		$key = $this->key();
		return null !== $key && $this->validCheck( $key ) ? new DeliveryCode( $key ) : null;
	}

	private function key(): ?string {
		$encoded = get_option( self::OPTION, '' );
		if ( ! is_string( $encoded ) || ! str_starts_with( $encoded, self::PREFIX ) ) {
			return null;
		}
		$key = base64_decode( substr( $encoded, strlen( self::PREFIX ) ), true );
		return is_string( $key ) && 32 === strlen( $key ) ? $key : null;
	}

	private function validCheck( string $key ): bool {
		$expected = base64_encode( hash_hmac( 'sha256', self::CHECK_CONTEXT, $key, true ) );
		$stored = get_option( self::CHECK_OPTION, '' );
		if ( is_string( $stored ) && '' !== $stored ) {
			return hash_equals( $expected, $stored );
		}
		return add_option( self::CHECK_OPTION, $expected, '', false ) || hash_equals( $expected, (string) get_option( self::CHECK_OPTION, '' ) );
	}

	private function hasManagedCase(): bool {
		$table = ( new Schema( $this->db ) )->managed_cases_table();
		$found = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $table ) ) );
		if ( '' !== $this->db->last_error ) {
			return true;
		}
		if ( $found !== $table ) {
			return false;
		}
		$value = $this->db->get_var( $this->db->prepare( 'SELECT request_id FROM %i LIMIT 1', $table ) );
		return null !== $value || '' !== $this->db->last_error;
	}
}
