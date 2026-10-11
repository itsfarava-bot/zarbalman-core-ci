<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\ManagedFlow;

use InvalidArgumentException;

/** No persistence, encryption, logging or WordPress dependencies. */
final class DeliveryCode {
	/** Caller supplies a protected secret of at least 32 bytes; never default or regenerate it. */
	public function __construct( private readonly string $secret ) {
		if ( strlen( $secret ) < 32 ) {
			throw new InvalidArgumentException( 'Delivery HMAC requires a protected secret.' );
		}
	}

	/** Six cryptographically random decimal digits, including leading zeros. */
	public function generate(): string {
		return str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	}

	/** Request/order/generation-bound HMAC; order must exist when issuing a delivery code. */
	public function sign( string $code, int $request_id, int $order_id, int $generation ): string {
		if ( min( $request_id, $order_id, $generation ) < 1 || 1 !== preg_match( '/\A[0-9]{6}\z/', $code ) ) {
			throw new InvalidArgumentException( 'Invalid delivery code scope.' );
		}
		return hash_hmac( 'sha256', implode( '|', array( 'zbm-delivery-v1', $request_id, $order_id, $generation, $code ) ), $this->secret );
	}

	/** Constant-time comparison of a correctly scoped six-digit candidate. */
	public function verify( string $code, string $hmac, int $request_id, int $order_id, int $generation ): bool {
		if ( min( $request_id, $order_id, $generation ) < 1 || 1 !== preg_match( '/\A[0-9]{6}\z/', $code ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $hmac ) ) {
			return false;
		}
		return hash_equals( $hmac, $this->sign( $code, $request_id, $order_id, $generation ) );
	}

	/**
	 * @return array{valid:bool,attempts:int,locked:bool}
	 * Every wrong attempt is a successful persistable command, NOT WP_Error/rollback.
	 * Consumer MUST commit attempts and admin_review on fifth failure under the case lock.
	 */
	public function attempt( string $code, string $hmac, int $request_id, int $order_id, int $generation, int $attempts ): array {
		if ( $attempts < 0 || $attempts > 5 ) {
			throw new InvalidArgumentException( 'Invalid delivery attempt counter.' );
		}
		$valid = $attempts < 5 && $this->verify( $code, $hmac, $request_id, $order_id, $generation );
		$attempts = $valid ? $attempts : min( 5, $attempts + 1 );
		return array( 'valid' => $valid, 'attempts' => $attempts, 'locked' => 5 === $attempts );
	}
}
