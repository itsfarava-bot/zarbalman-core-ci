<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\ManagedFlow;

use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;

/** Read-only identity proof, reconstructed from canonical payment and cash records. */
final class DepositProof {
	public static function matches( PurchaseRequest $request, array $fund, array $payment, array $cash, ?array $offer ): bool {
		$order_id = $request->wcOrderId();
		$payment_id = self::integer( $payment['id'] ?? null );
		$amount = $request->depositToman();
		if ( ! is_int( $order_id ) || $order_id < 1 || ! is_int( $payment_id ) || $payment_id < 1 || $amount < 1
			|| $request->isTest() || ( $fund['payment_id'] ?? null ) !== $payment_id || ( $fund['amount'] ?? null ) !== $amount
			|| ( $fund['provider'] ?? null ) !== ( $payment['provider'] ?? null )
			|| self::integer( $payment['request_id'] ?? null ) !== $request->id() || self::integer( $payment['order_id'] ?? null ) !== $order_id
			|| self::integer( $payment['expected_amount_toman'] ?? null ) !== $amount || self::integer( $payment['paid_amount_toman'] ?? null ) !== $amount
			|| 'deposit' !== ( $payment['phase'] ?? null ) || ! in_array( $payment['status'] ?? null, array( 'captured', 'late_accepted' ), true ) ) {
			return false;
		}
		$provider = $payment['provider'];
		if ( 'manual_bank' === $provider ) {
			$offer_id = $offer['offer_id'] ?? null;
			if ( ! is_string( $offer_id ) || 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $offer_id )
				|| 'approved' !== ( $offer['status'] ?? null ) || ( $offer['amount'] ?? null ) !== $amount || 'captured' !== $payment['status']
				|| '' !== (string) ( $payment['authority'] ?? '' ) || '' !== (string) ( $payment['reference'] ?? '' )
				|| ! hash_equals( hash( 'sha256', 'manual-bank|' . $request->id() . '|deposit|' . strtolower( $offer_id ) ), (string) ( $payment['idempotency_hash'] ?? '' ) ) ) {
				return false;
			}
			$allocation = 'manual-cash:' . $offer_id;
		} elseif ( 'zarinpal' === $provider ) {
			if ( '' === trim( (string) ( $payment['authority'] ?? '' ) ) || '' === trim( (string) ( $payment['reference'] ?? '' ) ) ) {
				return false;
			}
			$allocation = 'payment:' . $payment_id . ':cash';
		} else {
			return false;
		}
		return self::integer( $cash['request_id'] ?? null ) === $request->id() && self::integer( $cash['order_id'] ?? null ) === $order_id
			&& self::integer( $cash['payment_id'] ?? null ) === $payment_id && self::integer( $cash['amount_toman'] ?? null ) === $amount
			&& self::integer( $cash['vendor_id'] ?? null ) === 0 && 'employer' === ( $cash['owner_type'] ?? null )
			&& 'employer_cash_received' === ( $cash['entry_type'] ?? null ) && 'IRT' === ( $cash['currency'] ?? null )
			&& 'recorded' === ( $cash['status'] ?? null ) && hash_equals( hash( 'sha256', $allocation ), (string) ( $cash['allocation_key'] ?? '' ) );
	}

	private static function integer( mixed $value ): ?int {
		if ( ! is_int( $value ) && ! is_string( $value ) ) { return null; }
		$text = (string) $value;
		$max = (string) PHP_INT_MAX;
		if ( 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $text ) || strlen( $text ) > strlen( $max ) || ( strlen( $text ) === strlen( $max ) && strcmp( $text, $max ) > 0 ) ) { return null; }
		return (int) $text;
	}
}
