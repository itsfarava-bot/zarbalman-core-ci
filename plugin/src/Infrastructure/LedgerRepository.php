<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use RuntimeException;
use wpdb;

final class LedgerRepository {
	private Schema $schema;

	public function __construct( private readonly wpdb $db ) {
		$this->schema = new Schema( $db );
	}

	/** Provisional accounting only; never makes a native seller balance withdrawable. */
	public function provisionalShares( int $request_id, int $order_id, int $payment_id, int $vendor_id, int $paid ): void {
		if ( $payment_id < 1 || $vendor_id < 1 || $paid < 1 ) { throw new RuntimeException( 'Invalid captured payment allocation.' ); }
		$split = \Zarbalman\Core\Domain\ManagedFlow\Amounts::commission( $paid );
		if ( $split['commission'] > 0 ) { $this->append( $request_id, $order_id, $payment_id, 'platform_share_provisional', 'platform', 0, $split['commission'], 'provisional', "payment:{$payment_id}:platform" ); }
		if ( $split['net'] > 0 ) { $this->append( $request_id, $order_id, $payment_id, 'vendor_share_provisional', 'vendor', $vendor_id, $split['net'], 'provisional', "payment:{$payment_id}:vendor" ); }
	}

	/** The receipt must already have one immutable cash entry. This never writes money. */
	public function confirmedManualDepositMatches( int $request_id, int $order_id, int $payment_id, int $amount, string $offer_id ): bool {
		if ( $request_id < 1 || $order_id < 1 || $payment_id < 1 || $amount < 1 || ! wp_is_uuid( $offer_id ) ) {
			return false;
		}
		$hash = hash( 'sha256', 'manual-cash:' . $offer_id );
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM %i WHERE allocation_key = %s LIMIT 1', $this->schema->ledger_table(), $hash ),
			ARRAY_A
		);
		return '' === $this->db->last_error && is_array( $row )
			&& (int) ( $row['request_id'] ?? 0 ) === $request_id
			&& (int) ( $row['order_id'] ?? 0 ) === $order_id
			&& (int) ( $row['payment_id'] ?? 0 ) === $payment_id
			&& 'employer_cash_received' === ( $row['entry_type'] ?? null )
			&& 'employer' === ( $row['owner_type'] ?? null )
			&& 0 === (int) ( $row['vendor_id'] ?? -1 )
			&& $amount === (int) ( $row['amount_toman'] ?? 0 )
			&& 'IRT' === ( $row['currency'] ?? null )
			&& 'recorded' === ( $row['status'] ?? null )
			&& hash_equals( $hash, (string) ( $row['allocation_key'] ?? '' ) );
	}

	/** Must be called inside the caller's transaction. */
	public function append(
		int $request_id,
		int $order_id,
		?int $payment_id,
		string $entry_type,
		string $owner_type,
		int $vendor_id,
		int $amount_toman,
		string $status,
		string $allocation_key,
		array $metadata = array()
	): void {
		if ( $request_id < 1 || $order_id < 1 || $amount_toman < 1 || '' === $allocation_key ) {
			throw new RuntimeException( 'Invalid ledger entry.' );
		}
		$encoded = $metadata ? wp_json_encode( $metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null;
		$result = $this->db->query(
			$this->db->prepare(
				"INSERT IGNORE INTO %i
				(request_id,order_id,payment_id,entry_type,owner_type,vendor_id,amount_toman,currency,status,allocation_key,metadata_json,created_at_gmt)
				VALUES (%d,%d,%d,%s,%s,%d,%d,'IRT',%s,%s,%s,%s)",
				$this->schema->ledger_table(), $request_id, $order_id, $payment_id, sanitize_key( $entry_type ),
				sanitize_key( $owner_type ), $vendor_id, $amount_toman, sanitize_key( $status ),
				hash( 'sha256', $allocation_key ), is_string( $encoded ) ? $encoded : null, current_time( 'mysql', true )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		if ( false === $result ) {
			throw new RuntimeException( 'Ledger insert failed.' );
		}
		if ( 0 === $result ) {
			$hash = hash( 'sha256', $allocation_key );
			$sql = $this->db->prepare( 'SELECT * FROM %i WHERE allocation_key = %s LIMIT 1', $this->schema->ledger_table(), $hash );
			$existing = $this->db->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			if (
				! is_array( $existing )
				|| (int) $existing['request_id'] !== $request_id
				|| (int) $existing['order_id'] !== $order_id
				|| (int) $existing['payment_id'] !== (int) $payment_id
				|| (string) $existing['entry_type'] !== sanitize_key( $entry_type )
				|| (string) $existing['owner_type'] !== sanitize_key( $owner_type )
				|| (int) $existing['vendor_id'] !== $vendor_id
				|| (int) $existing['amount_toman'] !== $amount_toman
				|| 'IRT' !== (string) $existing['currency']
				|| (string) $existing['status'] !== sanitize_key( $status )
			) {
				throw new RuntimeException( 'Ledger idempotency conflict.' );
			}
		}
	}
}
