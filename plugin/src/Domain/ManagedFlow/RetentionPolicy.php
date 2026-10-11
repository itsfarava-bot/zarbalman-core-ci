<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\ManagedFlow;

/** Pure fail-closed eligibility for deleting only private payloads after 30 days. */
final class RetentionPolicy {
	/** @param array<string,mixed> $context */
	public static function eligible( array $context, string $now_gmt ): bool {
		if (
			! FlowState::isClosed( is_string( $context['state'] ?? null ) ? $context['state'] : '' )
			|| ! in_array( $context['settlement'] ?? null, array( 'credited', 'not_required' ), true )
			|| true === ( $context['sync_pending'] ?? true )
			|| true === ( $context['reconciliation'] ?? true )
			|| true === ( $context['paused'] ?? true )
			|| ! is_string( $context['closed_at'] ?? null )
		) {
			return false;
		}
		try {
			$eligible_at = Deadline::after( $context['closed_at'], 30 * 86400 );
			$now = Deadline::timestamp( $now_gmt );
			$eligible = Deadline::timestamp( $eligible_at );
		} catch ( \Throwable ) {
			return false;
		}
		return is_int( $now ) && is_int( $eligible ) && $now >= $eligible;
	}

	private function __construct() {}
}
