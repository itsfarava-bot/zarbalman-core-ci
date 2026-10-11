<?php

declare(strict_types=1);

namespace Zarbalman\Core\Security;

use InvalidArgumentException;

final class IdempotencyKey {
	public static function hash(
		string $namespace,
		string $resource_type,
		int $resource_id,
		string $raw_key
	): string {
		$namespace     = self::canonical_name( $namespace );
		$resource_type = self::canonical_name( $resource_type );
		$raw_key       = trim( $raw_key );

		if ( $resource_id < 1 || '' === $raw_key || strlen( $raw_key ) > 128 ) {
			throw new InvalidArgumentException( 'Invalid idempotency key scope.' );
		}

		return hash(
			'sha256',
			implode( '|', array( $namespace, $resource_type, (string) $resource_id, $raw_key ) )
		);
	}

	private static function canonical_name( string $value ): string {
		$value = strtolower( trim( $value ) );
		if ( '' === $value || 1 !== preg_match( '/\A[a-z0-9_-]{1,64}\z/', $value ) ) {
			throw new InvalidArgumentException( 'Invalid idempotency namespace.' );
		}

		return $value;
	}
}
