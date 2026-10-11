<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Finance;

use WP_Error;
use Zarbalman\Core\Domain\ManagedFlow\Amounts;
use Zarbalman\Core\Domain\ManagedFlow\FlowState;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;

/**
 * Pure authorization/amount policy for a real manual-bank obligation.
 *
 * Every value in $context must be reconstructed by the application service from
 * locked server records. Browser-supplied owner, amount, state or payment flags
 * are never valid inputs to this policy.
 */
final class ManualPaymentPolicy {
	/**
	 * @param array<string,mixed> $context Server-derived context.
	 * @return array{phase:?string,amount:int,existing:bool}|WP_Error
	 */
	public static function offer( array $context ): array|WP_Error {
		$guard = self::commonGuard( $context, true );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$decision = self::due( $context );
		if ( is_wp_error( $decision ) || null === $decision['phase'] ) {
			return $decision;
		}

		$existing = self::offerFor( $context, $decision['phase'] );
		if ( 'balance' === $decision['phase'] && true !== ( $context['balance_payable'] ?? false ) ) { return self::error( 'deadline' ); }
		if ( null !== $existing ) {
			if ( ! self::validOfferSummary( $existing ) || (int) $existing['amount'] !== $decision['amount'] ) {
				return self::error( 'offer_conflict' );
			}
			if ( 'approved' === $existing['status'] ) {
				return self::error( 'already_approved' );
			}
			return array( 'phase' => $decision['phase'], 'amount' => $decision['amount'], 'existing' => true );
		}

		if ( true !== ( $context['enabled'] ?? false ) ) {
			return self::error( 'disabled' );
		}

		return array( 'phase' => $decision['phase'], 'amount' => $decision['amount'], 'existing' => false );
	}

	/**
	 * Authorize receipt bytes only after the server-owned obligation is ready.
	 *
	 * @param array<string,mixed> $context Server-derived context.
	 */
	public static function submitReceipt( array $context, string $phase ): true|WP_Error {
		$guard = self::commonGuard( $context, true );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		if ( true === ( $context['sync_pending'] ?? true ) && true !== ( $context['receipt_projection_safe'] ?? false ) ) {
			return self::error( 'sync' );
		}
		$decision = self::due( $context );
		if ( is_wp_error( $decision ) || $decision['phase'] !== $phase ) {
			return is_wp_error( $decision ) ? $decision : self::error( 'phase' );
		}
		$offer = self::offerFor( $context, $phase );
		if (
			null === $offer
			|| ! self::validOfferSummary( $offer )
			|| ! in_array( $offer['status'], array( 'offered', 'rejected' ), true )
			|| (int) $offer['amount'] !== $decision['amount']
		) {
			return self::error( 'receipt' );
		}
		if ( 'deposit' === $phase && true !== ( $context['deposit_payable'] ?? false ) ) {
			return self::error( 'deadline' );
		}
		if ( 'balance' === $phase && true !== ( $context['balance_payable'] ?? false ) ) { return self::error( 'deadline' ); }
		return true;
	}

	/**
	 * @param array<string,mixed> $context Server-derived context.
	 * @return array{amount:int,state:string}|WP_Error
	 */
	public static function approve( array $context, string $phase, bool $confirmed, string $reason ): array|WP_Error {
		$guard = self::commonGuard( $context, false );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		if ( true !== ( $context['finance'] ?? false ) ) {
			return self::error( 'finance' );
		}
		$reason = trim( $reason );
		if ( ! $confirmed || '' === $reason || strlen( $reason ) > 1000 ) {
			return self::error( 'confirmation' );
		}

		$decision = self::due( $context );
		if ( is_wp_error( $decision ) || null === $decision['phase'] || $decision['phase'] !== $phase ) {
			return is_wp_error( $decision ) ? $decision : self::error( 'phase' );
		}
		$offer = self::offerFor( $context, $phase );
		if ( null === $offer || ! self::validOfferSummary( $offer ) || 'submitted' !== $offer['status'] ) {
			return self::error( 'receipt' );
		}
		if ( (int) $offer['amount'] !== $decision['amount'] ) {
			return self::error( 'amount' );
		}

		return array(
			'amount' => $decision['amount'],
			'state'  => 'deposit' === $phase ? FlowState::PREPARING : FlowState::COMPLETED,
		);
	}

	/**
	 * @param array<string,mixed> $context Server-derived context.
	 */
	public static function reject( array $context, string $phase, string $reason ): true|WP_Error {
		$guard = self::commonGuard( $context, false );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$reason = trim( $reason );
		if ( true !== ( $context['finance'] ?? false ) || '' === $reason || strlen( $reason ) > 1000 ) {
			return self::error( 'finance' );
		}
		$offer = self::offerFor( $context, $phase );
		if ( null === $offer || ! self::validOfferSummary( $offer ) || 'submitted' !== $offer['status'] ) {
			return self::error( 'receipt' );
		}
		$decision = self::due( $context );
		if ( is_wp_error( $decision ) || $decision['phase'] !== $phase || (int) $offer['amount'] !== $decision['amount'] ) {
			return is_wp_error( $decision ) ? $decision : self::error( 'amount' );
		}
		return true;
	}

	/** @param array<string,mixed> $context
	 *  @return array{phase:?string,amount:int}|WP_Error
	 */
	private static function due( array $context ): array|WP_Error {
		if ( true === ( $context['operations_only'] ?? false ) ) {
			return array( 'phase' => null, 'amount' => 0 );
		}
		$state = is_string( $context['state'] ?? null ) ? $context['state'] : '';
		$request_state = is_string( $context['request_state'] ?? null ) ? $context['request_state'] : '';
		$deposit = self::nonnegative( $context['deposit'] ?? null );
		$balance = self::nonnegative( $context['balance'] ?? null );
		if ( null === $deposit || null === $balance ) {
			return self::error( 'funds' );
		}

		if ( FlowState::AWAITING_DEPOSIT === $state && in_array( $request_state, array( RequestState::SUPPLIER_ACCEPTED, RequestState::DEPOSIT_PENDING ), true ) ) {
			$due = self::positive( $context['deposit_due'] ?? null );
			if ( null === $due || $balance > 0 ) {
				return self::error( 'deposit' );
			}
			if ( $deposit > $due ) {
				return self::error( 'overpaid' );
			}
			return $deposit === $due
				? array( 'phase' => null, 'amount' => 0 )
				: array( 'phase' => 'deposit', 'amount' => $due );
		}

		if ( in_array( $state, array( FlowState::FINAL_WEIGHT_LOCKED, FlowState::BALANCE_DUE ), true ) && RequestState::DEPOSIT_PAID === $request_state ) {
			if ( true !== ( $context['final_locked'] ?? false ) || true !== ( $context['delivered'] ?? false ) ) {
				return self::error( 'delivery' );
			}
			$total = self::nonnegative( $context['final_total'] ?? null );
			if ( null === $total ) {
				return self::error( 'final_total' );
			}
			try {
				$remaining = Amounts::remaining( $total, $deposit, $balance );
			} catch ( \Throwable ) {
				return self::error( 'amount' );
			}
			if ( $remaining < 0 ) {
				return self::error( 'overpaid' );
			}
			return 0 === $remaining
				? array( 'phase' => null, 'amount' => 0 )
				: array( 'phase' => 'balance', 'amount' => $remaining );
		}

		return self::error( 'state' );
	}

	/** @param array<string,mixed> $context */
	private static function commonGuard( array $context, bool $require_owner ): true|WP_Error {
		if ( 'live' !== ( $context['mode'] ?? null ) ) {
			return self::error( 'mode' );
		}
		if ( $require_owner && true !== ( $context['owner'] ?? false ) ) {
			return self::error( 'owner' );
		}
		if ( true === ( $context['paused'] ?? false ) ) {
			return self::error( 'paused' );
		}
		if ( true === ( $context['reconciliation'] ?? false ) ) {
			return self::error( 'reconciliation' );
		}
		if ( true === ( $context['online_unknown'] ?? false ) ) {
			return self::error( 'online_unknown' );
		}
		return true;
	}

	/** @param array<string,mixed> $context
	 *  @return array<string,mixed>|null
	 */
	private static function offerFor( array $context, string $phase ): ?array {
		$manual = $context['manual'] ?? null;
		if ( ! is_array( $manual ) || ! array_key_exists( $phase, $manual ) ) {
			return null;
		}
		return is_array( $manual[ $phase ] ) ? $manual[ $phase ] : null;
	}

	/** @param array<string,mixed> $offer */
	private static function validOfferSummary( array $offer ): bool {
		return self::positive( $offer['amount'] ?? null ) !== null
			&& in_array( $offer['status'] ?? null, array( 'offered', 'submitted', 'approved', 'rejected' ), true );
	}

	private static function nonnegative( mixed $value ): ?int {
		return is_int( $value ) && $value >= 0 ? $value : null;
	}

	private static function positive( mixed $value ): ?int {
		return is_int( $value ) && $value > 0 ? $value : null;
	}

	private static function error( string $code ): WP_Error {
		return new WP_Error( 'zbm_manual_' . $code, __( 'پرداخت دستی در وضعیت فعلی مجاز نیست؛ صفحه را تازه و وضعیت پرونده را بررسی کنید.', 'zarbalman-core' ) );
	}

	private function __construct() {}
}
