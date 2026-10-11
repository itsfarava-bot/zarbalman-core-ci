<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\ManagedFlow;

/** A window expiry requests coordination only. It never confirms delivery or moves money. */
final class DeliveryWindowPolicy {
	public static function due( array $window, string $now_gmt, int $reminder_seconds ): ?string {
		$now = Deadline::timestamp( $now_gmt );
		$start = Deadline::timestamp( $window['start_gmt'] );
		$end = Deadline::timestamp( $window['end_gmt'] );
		if ( ! is_int( $start ) || ! is_int( $end ) || $start >= $end ) { throw new \InvalidArgumentException( 'Invalid delivery window.' ); }
		if ( null !== $window['overdue_at'] ) { return null; }
		if ( $now >= $end ) { return 'overdue'; }
		if ( $reminder_seconds > 0 && $reminder_seconds <= 86400 && null === $window['reminded_at'] && $now >= $start - $reminder_seconds && $now < $start ) { return 'reminder'; }
		return null;
	}
}
