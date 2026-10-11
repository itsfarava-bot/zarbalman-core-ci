<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use RuntimeException;
use WP_Error;
use wpdb;
use Zarbalman\Core\Domain\Finance\ManualSettlementPolicy;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Infrastructure\LedgerRepository;
use Zarbalman\Core\Infrastructure\Schema;

/** Exact-once native Dokan credit using the pinned VendorBalance model API. */
final class DokanManualSettlementAdapter {
	private Schema $schema;

	public function __construct( private readonly wpdb $db, private readonly LedgerRepository $ledger ) {
		$this->schema = new Schema( $db );
	}

	/** Must be called inside ManagedCaseRepository's existing SQL transaction. */
	public function credit( PurchaseRequest $request, int $order_id, int $net_toman, string $allocation_hash, string $ledger_type = 'vendor_final_credit', ?string $ledger_key = null ): array|WP_Error {
		if ( ! in_array( $ledger_type, array( 'vendor_final_credit', 'sale_vendor_allocation', 'nonpayment_vendor_allocation' ), true ) ) { return $this->error( 'allocation_type' ); }
		$ledger_key ??= 'manual-settlement:' . $allocation_hash;
		// The supplied Dokan withdrawal path reads wc-* order statuses, not
		// the model's separate "approved" total-earnings status.
		if ( ! function_exists( 'dokan_withdraw_get_active_order_status' ) || ! in_array( 'wc-completed', dokan_withdraw_get_active_order_status(), true ) ) { return $this->error( 'withdraw_status' ); }
		$table = $this->db->get_row( $this->db->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $this->db->prefix . 'dokan_vendor_balance' ), ARRAY_A );
		if ( ! is_array( $table ) || 'innodb' !== strtolower( (string) ( $table['Engine'] ?? '' ) ) ) { return $this->error( 'native_engine' ); }
		if (
			$request->executionMode() !== 'live'
			|| $order_id < 1
			|| ! ManualSettlementPolicy::nativeAmountAllowed( $net_toman )
			|| 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $allocation_hash )
			|| ! class_exists( \WeDevs\Dokan\Models\VendorBalance::class )
		) {
			return $this->error( 'dependency' );
		}

		$mirror = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM %i WHERE allocation_hash = %s FOR UPDATE', $this->schema->dokan_credit_mirrors_table(), $allocation_hash ),
			ARRAY_A
		);
		if ( '' !== $this->db->last_error ) {
			return $this->error( 'storage' );
		}
		if ( is_array( $mirror ) ) {
			if ( ! $this->sameMirror( $mirror, $request, $order_id, $net_toman ) || 'credited' !== (string) $mirror['status'] || ! $this->validNativeRow( (int) $mirror['dokan_balance_id'], $request->vendorId(), $order_id, $net_toman, $allocation_hash ) ) {
				return $this->error( 'conflict' );
			}
			$this->ledger->append( $request->id(), $order_id, null, $ledger_type, 'vendor', $request->vendorId(), $net_toman, 'allocated', $ledger_key, array( 'allocation_hash' => $allocation_hash ) );
			return array( 'balance_id' => (int) $mirror['dokan_balance_id'], 'replayed' => true );
		}

		$now = current_time( 'mysql', true );
		if ( 1 !== $this->db->insert(
			$this->schema->dokan_credit_mirrors_table(),
			array( 'allocation_hash' => $allocation_hash, 'vendor_id' => $request->vendorId(), 'order_id' => $order_id, 'amount_toman' => $net_toman, 'status' => 'pending', 'dokan_balance_id' => null, 'created_at_gmt' => $now, 'updated_at_gmt' => $now ),
			array( '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s' )
		) ) {
			return $this->error( 'storage' );
		}

		$marker = self::marker( $allocation_hash );
		$unexpected = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->db->prefix}dokan_vendor_balance WHERE trn_id = %d AND trn_type = 'dokan_orders' AND perticulars = %s FOR UPDATE",
				$order_id, $marker
			),
			ARRAY_A
		);
		if ( '' !== $this->db->last_error || ! is_array( $unexpected ) || array() !== $unexpected ) {
			return $this->error( 'native_conflict' );
		}

		try {
			$balance = new \WeDevs\Dokan\Models\VendorBalance();
			$balance->set_vendor_id( $request->vendorId() );
			$balance->set_trn_id( $order_id );
			$balance->set_trn_type( \WeDevs\Dokan\Models\VendorBalance::TRN_TYPE_DOKAN_ORDERS );
			$balance->set_particulars( $marker );
			$balance->set_debit( (float) $net_toman );
			$balance->set_credit( 0.0 );
			$balance->set_status( 'wc-completed' );
			$balance->set_trn_date( current_time( 'mysql' ) );
			$balance->set_balance_date( current_time( 'mysql' ) );
			$balance_id = (int) $balance->save();
		} catch ( \Throwable ) {
			return $this->error( 'native_write' );
		}
		if ( $balance_id < 1 || ! $this->validNativeRow( $balance_id, $request->vendorId(), $order_id, $net_toman, $allocation_hash ) ) {
			return $this->error( 'native_verify' );
		}
		$updated = $this->db->update(
			$this->schema->dokan_credit_mirrors_table(),
			array( 'status' => 'credited', 'dokan_balance_id' => $balance_id, 'updated_at_gmt' => current_time( 'mysql', true ) ),
			array( 'allocation_hash' => $allocation_hash, 'status' => 'pending' ),
			array( '%s', '%d', '%s' ), array( '%s', '%s' )
		);
		if ( 1 !== $updated ) {
			return $this->error( 'mirror_finalize' );
		}
		$this->ledger->append( $request->id(), $order_id, null, $ledger_type, 'vendor', $request->vendorId(), $net_toman, 'allocated', $ledger_key, array( 'allocation_hash' => $allocation_hash ) );
		return array( 'balance_id' => $balance_id, 'replayed' => false );
	}

	/** @return list<int> Exact rows the automatic-earning guard must preserve. */
	public function authorizedBalanceIds( int $order_id ): array {
		if ( $order_id < 1 ) {
			return array();
		}
		$rows = $this->db->get_results(
			$this->db->prepare( "SELECT * FROM %i WHERE order_id = %d AND status IN ('credited','completed')", $this->schema->dokan_credit_mirrors_table(), $order_id ),
			ARRAY_A
		);
		$ids = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$hash = (string) ( $row['allocation_hash'] ?? '' );
			$id = (int) ( $row['dokan_balance_id'] ?? 0 );
			$new_valid = 'credited' === ( $row['status'] ?? null ) && $this->validNativeRow( $id, (int) $row['vendor_id'], $order_id, (int) $row['amount_toman'], $hash );
			$legacy_valid = 'completed' === ( $row['status'] ?? null ) && $this->validLegacyNativeRow( $id, (int) $row['vendor_id'], $order_id, (int) $row['amount_toman'], $hash );
			if ( 1 === preg_match( '/\A[a-f0-9]{64}\z/', $hash ) && ( $new_valid || $legacy_valid ) ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	public static function marker( string $allocation_hash ): string {
		return 'Zarbalman final allocation ' . $allocation_hash;
	}

	/** @param array<string,mixed> $row */
	private function sameMirror( array $row, PurchaseRequest $request, int $order_id, int $amount ): bool {
		return (int) ( $row['vendor_id'] ?? 0 ) === $request->vendorId()
			&& (int) ( $row['order_id'] ?? 0 ) === $order_id
			&& (int) ( $row['amount_toman'] ?? 0 ) === $amount
			&& (int) ( $row['dokan_balance_id'] ?? 0 ) > 0;
	}

	private function validNativeRow( int $id, int $vendor_id, int $order_id, int $amount, string $allocation_hash ): bool {
		if ( min( $id, $vendor_id, $order_id, $amount ) < 1 ) {
			return false;
		}
		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$this->db->prefix}dokan_vendor_balance WHERE id = %d", $id ),
			ARRAY_A
		);
		return is_array( $row )
			&& (int) $row['vendor_id'] === $vendor_id
			&& (int) $row['trn_id'] === $order_id
			&& 'dokan_orders' === (string) $row['trn_type']
			&& hash_equals( self::marker( $allocation_hash ), (string) $row['perticulars'] )
			&& $this->exactToman( $row['debit'] ?? null ) === $amount
			&& 0 === $this->exactToman( $row['credit'] ?? null )
			&& in_array( (string) $row['status'], array( 'approved', 'wc-completed' ), true );
	}

	/** Preserve historical Core allocations only when the old mirror and exact row still match. */
	private function validLegacyNativeRow( int $id, int $vendor_id, int $order_id, int $amount, string $allocation_hash ): bool {
		if ( min( $id, $vendor_id, $order_id, $amount ) < 1 ) {
			return false;
		}
		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$this->db->prefix}dokan_vendor_balance WHERE id = %d", $id ),
			ARRAY_A
		);
		return is_array( $row )
			&& (int) $row['vendor_id'] === $vendor_id
			&& (int) $row['trn_id'] === $order_id
			&& 'dokan_orders' === (string) $row['trn_type']
			&& hash_equals( 'Zarbalman 5% allocation ' . substr( $allocation_hash, 0, 16 ), (string) $row['perticulars'] )
			&& $this->exactToman( $row['debit'] ?? null ) === $amount
			&& 0 === $this->exactToman( $row['credit'] ?? null )
			&& 'approved' === (string) $row['status'];
	}

	private function exactToman( mixed $value ): ?int {
		$value = (string) $value;
		if ( 1 !== preg_match( '/\A([0-9]+)(?:\.0{1,4})?\z/', $value, $match ) ) {
			return null;
		}
		$digits = ltrim( $match[1], '0' );
		if ( '' === $digits ) {
			return 0;
		}
		$max = (string) PHP_INT_MAX;
		return strlen( $digits ) < strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) <= 0 ) ? (int) $digits : null;
	}

	private function error( string $code ): WP_Error {
		return new WP_Error( 'zbm_manual_dokan_' . $code, __( 'شارژ قطعی فروشنده در Dokan ثبت نشد؛ پرونده برای تلاش امن با همان شناسه بدون تغییر باقی ماند.', 'zarbalman-core' ) );
	}
}
