<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Finance;

use WP_Error;
use Zarbalman\Core\Domain\ManagedFlow\Amounts;
use Zarbalman\Core\Domain\ManagedFlow\FlowState;

/** Pure decision policy for the separate, explicit Dokan seller credit. */
final class ManualSettlementPolicy {
	/** Maximum integer IRT exactly representable by Dokan's DECIMAL(19,4) float API boundary. */
	private const MAX_NATIVE_TOMAN = 999999999999999;

	/**
	 * @param array<string,mixed> $context Values reconstructed from locked records.
	 * @return array{commission:int,net:int}|WP_Error
	 */
	public static function decide( array $context, string $reason ): array|WP_Error {
		$reason = trim( $reason );
		if (
			'live' !== ( $context['mode'] ?? null )
			|| true !== ( $context['finance'] ?? false )
			|| FlowState::COMPLETED !== ( $context['state'] ?? null )
			|| true === ( $context['paused'] ?? false )
			|| true === ( $context['reconciliation'] ?? false )
			|| true === ( $context['sync_pending'] ?? false )
			|| true !== ( $context['final_locked'] ?? false )
			|| true !== ( $context['delivered'] ?? false )
			|| 'pending' !== ( $context['settlement'] ?? null )
			|| '' === $reason
			|| strlen( $reason ) > 1000
		) {
			return self::error( 'state' );
		}

		$total = self::nonnegative( $context['total'] ?? null );
		$deposit = self::nonnegative( $context['deposit'] ?? null );
		$balance = self::nonnegative( $context['balance'] ?? null );
		if ( null === $total || null === $deposit || null === $balance || $total < 1 ) {
			return self::error( 'amount' );
		}
		try {
			if ( 0 !== Amounts::remaining( $total, $deposit, $balance ) ) {
				return self::error( 'funds' );
			}
			$split = Amounts::commission( $total );
		} catch ( \Throwable ) {
			return self::error( 'amount' );
		}
		if ( ! self::nativeAmountAllowed( $split['net'] ) ) {
			return self::error( 'native_amount' );
		}
		return $split;
	}

	public static function nativeAmountAllowed( int $amount_toman ): bool {
		return $amount_toman > 0 && $amount_toman <= self::MAX_NATIVE_TOMAN;
	}

	private static function nonnegative( mixed $value ): ?int {
		return is_int( $value ) && $value >= 0 ? $value : null;
	}

	private static function error( string $code ): WP_Error {
		return new WP_Error( 'zbm_manual_settlement_' . $code, __( 'شارژ فروشنده در وضعیت فعلی مجاز نیست؛ وجوه، تحویل و وضعیت پرونده را بررسی کنید.', 'zarbalman-core' ) );
	}

	private function __construct() {}
}
