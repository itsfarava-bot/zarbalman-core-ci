<?php

declare(strict_types=1);

namespace Zarbalman\Core\Security;

use DomainException;

final class RequestGuard {
	public function requireAuthenticatedOwner( int $user_id, int $owner_id ): void {
		if ( $user_id < 1 || $owner_id < 1 || $user_id !== $owner_id ) {
			throw new DomainException( 'Resource not found.' );
		}
	}

	public function requireNonce( string $action, string $nonce ): void {
		if ( '' === $nonce || ! function_exists( 'wp_verify_nonce' ) || ! wp_verify_nonce( $nonce, $action ) ) {
			throw new DomainException( 'Resource not found.' );
		}
	}

	public function requireCapability( string $capability ): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( $capability ) ) {
			throw new DomainException( 'Resource not found.' );
		}
	}
}
