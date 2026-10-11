<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use RuntimeException;

/** Purpose/request/binding-scoped authenticated text using the existing ZBMD2 key. */
final class ProtectedText {
	public function __construct( private readonly DeliveryDataCrypto $crypto ) {}

	public function ensureKey(): bool {
		return $this->crypto->ensureKey();
	}

	public function encrypt( string $purpose, int $request_id, string $binding, string $plain ): string {
		$this->assertScope( $purpose, $request_id, $binding );
		if ( '' === $plain || strlen( $plain ) > 3000 ) {
			throw new RuntimeException( 'Protected text is empty or exceeds its bound.' );
		}
		return $this->crypto->encrypt(
			array(
				'recipient_name' => $purpose,
				'postal_code'    => $request_id . ':' . hash( 'sha256', $binding ),
				'address'        => $plain,
			)
		);
	}

	public function decrypt( string $purpose, int $request_id, string $binding, string $encoded ): string {
		$this->assertScope( $purpose, $request_id, $binding );
		$payload = $this->crypto->decrypt( $encoded );
		$expected = $request_id . ':' . hash( 'sha256', $binding );
		if (
			! hash_equals( $purpose, $payload['recipient_name'] )
			|| ! is_string( $payload['postal_code'] )
			|| ! hash_equals( $expected, $payload['postal_code'] )
			|| ! is_string( $payload['address'] )
			|| '' === $payload['address']
		) {
			throw new RuntimeException( 'Protected text scope does not match.' );
		}
		return $payload['address'];
	}

	private function assertScope( string $purpose, int $request_id, string $binding ): void {
		if (
			$request_id < 0
			|| 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $purpose )
			|| '' === $binding
			|| strlen( $binding ) > 512
		) {
			throw new RuntimeException( 'Protected text scope is invalid.' );
		}
	}
}
