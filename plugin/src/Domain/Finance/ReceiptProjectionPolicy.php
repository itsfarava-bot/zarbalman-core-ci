<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Finance;

use Zarbalman\Core\Domain\ManagedFlow\FlowState;

/** Classifies a pending projection; never authorizes money or changes of owner. */
final class ReceiptProjectionPolicy {
	public static function isReceiptOnlyProjection( array $case ): bool {
		$recovery = $case['data']['recovery'] ?? array();
		$finance = $case['data']['spec4'] ?? null;
		if ( is_array( $finance ) && FlowState::BALANCE_DUE === ( $case['state'] ?? null ) && in_array( $finance['substate'] ?? '', array( 'balance_due', 'warning', 'receipt_review' ), true )
			&& 1 === ( $case['sync_pending'] ?? null ) && 'manual_deposit_projection' === ( $recovery['operation'] ?? null ) && self::uuid( $recovery['event_uuid'] ?? null )
			&& ( $case['data']['funds']['deposit']['amount'] ?? 0 ) > 0 && ( $case['data']['funds']['deposit']['payment_id'] ?? 0 ) > 0 && 0 === ( $case['data']['funds']['balance']['amount'] ?? null )
			&& null !== ( $case['data']['final']['locked_at'] ?? null ) && null !== ( $case['data']['delivery']['delivered_at'] ?? null ) ) { return true; }
		$offer = $case['data']['manual']['deposit'] ?? null;
		return 1 === ( $case['sync_pending'] ?? null )
			&& FlowState::AWAITING_DEPOSIT === ( $case['state'] ?? null )
			&& 'manual_order_sync' === ( $recovery['operation'] ?? null )
			&& self::uuid( $recovery['event_uuid'] ?? null )
			&& is_array( $offer ) && self::uuid( $offer['offer_id'] ?? null )
			&& is_int( $offer['amount'] ?? null ) && $offer['amount'] > 0
			&& in_array( $offer['status'] ?? null, array( 'offered', 'submitted', 'rejected' ), true )
			&& 0 === ( $case['data']['funds']['deposit']['amount'] ?? null )
			&& 0 === ( $case['data']['funds']['balance']['amount'] ?? null );
	}

	private static function uuid( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value );
	}

	private function __construct() {}
}
