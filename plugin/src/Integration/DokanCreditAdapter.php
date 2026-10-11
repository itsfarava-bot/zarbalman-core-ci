<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use RuntimeException;
use Throwable;
use WeDevs\Dokan\Models\VendorBalance;
use Zarbalman\Core\Domain\Finance\DokanCreditPort;
use Zarbalman\Core\Infrastructure\Schema;
use wpdb;

/**
 * Dormant new-roadmap Spec 4 adapter. Spec 1 and the Spec 2 baseline neither
 * instantiate nor call creditOnce(). Dokan calls earnings "debit" rows.
 */
final class DokanCreditAdapter implements DokanCreditPort {
	private Schema $schema;

	public function __construct( private readonly wpdb $db ) {
		$this->schema = new Schema( $db );
	}

	public function creditOnce( int $vendor_id, int $order_id, int $amount_toman, string $allocation_key ): void {
		if ( $vendor_id < 1 || $order_id < 1 || $amount_toman < 1 || '' === $allocation_key || $amount_toman > 9007199254740991 ) {
			throw new RuntimeException( 'Invalid Dokan credit allocation.' );
		}
		if ( ! class_exists( VendorBalance::class ) || ! function_exists( 'dokan' ) || ! function_exists( 'dokan_current_datetime' ) ) {
			throw new RuntimeException( 'Dokan balance API is unavailable.' );
		}
		$hash = hash( 'sha256', $allocation_key );
		$now = current_time( 'mysql', true );
		$this->db->query( 'START TRANSACTION' );
		try {
			$sql = $this->db->prepare( 'SELECT * FROM %i WHERE allocation_hash = %s FOR UPDATE', $this->schema->dokan_credit_mirrors_table(), $hash );
			$row = $this->db->get_row( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			if ( $row ) {
				if ( (int) $row->vendor_id !== $vendor_id || (int) $row->order_id !== $order_id || (int) $row->amount_toman !== $amount_toman ) {
					throw new RuntimeException( 'Dokan credit idempotency conflict.' );
				}
				if ( 'completed' === $row->status ) {
					$this->db->query( 'COMMIT' );
					return;
				}
			} else {
				$inserted = $this->db->insert(
					$this->schema->dokan_credit_mirrors_table(),
					array(
						'allocation_hash' => $hash, 'vendor_id' => $vendor_id, 'order_id' => $order_id,
						'amount_toman' => $amount_toman, 'status' => 'processing', 'created_at_gmt' => $now, 'updated_at_gmt' => $now,
					),
					array( '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
				);
				if ( false === $inserted ) {
					throw new RuntimeException( 'Dokan credit mirror insert failed.' );
				}
			}

			$existing_sql = $this->db->prepare(
				"SELECT id,vendor_id,debit,credit,status,perticulars FROM {$this->db->prefix}dokan_vendor_balance WHERE trn_id = %d AND trn_type = 'dokan_orders' ORDER BY id ASC FOR UPDATE",
				$order_id
			);
			$existing_rows = $this->db->get_results( $existing_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; fixed Dokan table suffix.
			$existing_rows = is_array( $existing_rows ) ? $existing_rows : array();
			$allocation_marker = 'Zarbalman 5% allocation ' . substr( $hash, 0, 16 );
			$allocated = null;
			$convertible = null;
			foreach ( $existing_rows as $existing ) {
				if ( str_contains( (string) $existing->perticulars, substr( $hash, 0, 16 ) ) ) {
					$allocated = $existing;
					break;
				}
				if ( str_starts_with( (string) $existing->perticulars, 'Zarbalman 5% allocation ' ) || 0 !== (int) $existing->debit || 0 !== (int) $existing->credit ) {
					throw new RuntimeException( 'Unexpected Dokan earning exists for the managed order.' );
				}
				if ( null === $convertible && 0 === (int) $existing->debit && 0 === (int) $existing->credit ) {
					$convertible = $existing;
				}
			}

			if ( $allocated ) {
				if ( (int) $allocated->vendor_id !== $vendor_id || (int) $allocated->debit !== $amount_toman || 0 !== (int) $allocated->credit || 'approved' !== $allocated->status ) {
					throw new RuntimeException( 'Existing Dokan balance row conflicts with Core allocation.' );
				}
				$balance_id = (int) $allocated->id;
			} elseif ( $convertible ) {
				$balance_time = dokan_current_datetime()->format( 'Y-m-d H:i:s' );
				$converted = $this->db->update(
					$this->db->prefix . 'dokan_vendor_balance',
					array(
						'vendor_id' => $vendor_id,
						'perticulars' => $allocation_marker,
						'debit' => $amount_toman,
						'credit' => 0,
						'status' => 'approved',
						'trn_date' => $balance_time,
						'balance_date' => $balance_time,
					),
					array( 'id' => (int) $convertible->id, 'debit' => 0, 'credit' => 0 ),
					array( '%d', '%s', '%f', '%f', '%s', '%s', '%s' ),
					array( '%d', '%f', '%f' )
				);
				if ( 1 !== $converted ) {
					throw new RuntimeException( 'Dokan zero-earning row could not be converted safely.' );
				}
				$balance_id = (int) $convertible->id;
			} else {
				$balance = new VendorBalance();
				$balance->set_vendor_id( $vendor_id );
				$balance->set_trn_id( $order_id );
				$balance->set_trn_type( VendorBalance::TRN_TYPE_DOKAN_ORDERS );
				$balance->set_particulars( $allocation_marker );
				$balance->set_debit( (float) $amount_toman );
				$balance->set_credit( 0.0 );
				$balance->set_status( 'approved' );
				$balance->set_trn_date( dokan_current_datetime()->format( 'Y-m-d H:i:s' ) );
				$balance->set_balance_date( dokan_current_datetime()->format( 'Y-m-d H:i:s' ) );
				$balance->save();
				$balance_id = (int) $balance->get_id();
				if ( $balance_id < 1 ) {
					throw new RuntimeException( 'Dokan balance credit failed.' );
				}
			}
			$updated = $this->db->update(
				$this->schema->dokan_credit_mirrors_table(),
				array( 'status' => 'completed', 'dokan_balance_id' => $balance_id, 'updated_at_gmt' => $now ),
				array( 'allocation_hash' => $hash ), array( '%s', '%d', '%s' ), array( '%s' )
			);
			if ( 1 !== $updated ) {
				throw new RuntimeException( 'Dokan credit mirror completion failed.' );
			}
			$this->db->query( 'COMMIT' );
		} catch ( Throwable $error ) {
			$this->db->query( 'ROLLBACK' );
			throw new RuntimeException( 'Dokan credit was not recorded.', 0, $error );
		}
	}
}
