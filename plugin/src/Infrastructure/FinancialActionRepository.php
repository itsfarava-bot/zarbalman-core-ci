<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use RuntimeException;
use wpdb;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Security\AdminCapabilities;

/** Monetary facts are immutable. Every write shares the locked Core aggregate transaction. */
final class FinancialActionRepository {
	private Schema $schema;
	public function __construct( private readonly wpdb $db, private readonly ManagedCaseRepository $cases ) { $this->schema = new Schema( $db ); }

	public function create( PurchaseRequest $request, string $type, int $amount, int $basis_toman, string $basis_hash, int $actor, string $reason_ciphertext = '', array $evidence = array(), array $destination = array() ): array {
		$this->requireTransaction();
		$types = array( 'sale_site', 'sale_vendor', 'nonpayment_site', 'nonpayment_vendor', 'incapacity_site', 'incapacity_refund', 'cancellation_site', 'cancellation_refund' );
		if ( 'live' !== $request->executionMode() || ! $request->wcOrderId() || ! in_array( $type, $types, true ) || $amount < 1 || $basis_toman < 1 || ! preg_match( '/\A[a-f0-9]{64}\z/', $basis_hash )
			|| ( $actor > 0 && ! AdminCapabilities::userCanManageFinance( $actor ) ) || $actor < 0 ) { throw new RuntimeException( 'Invalid immutable allocation.' ); }
		$key = hash( 'sha256', 'spec4|' . $request->id() . '|' . $basis_hash . '|' . $type );
		$row = $this->byKey( $key );
		if ( null !== $row ) {
			if ( (int) $row['request_id'] !== $request->id() || (int) $row['order_id'] !== $request->wcOrderId() || $row['action_type'] !== $type || (int) $row['base_amount_toman'] !== $basis_toman || self::amount( $row ) !== $amount ) { throw new RuntimeException( 'Allocation replay differs.' ); }
			if ( str_ends_with( $type, '_refund' ) && ( ! is_string( $destination['fingerprint'] ?? null ) || ! hash_equals( (string) $row['destination_fingerprint'], $destination['fingerprint'] ) ) ) { throw new RuntimeException( 'Refund destination cannot be replaced on replay.' ); }
			return $row;
		}
		$refund = str_ends_with( $type, '_refund' );
		if ( $refund && ( ! is_string( $destination['ciphertext'] ?? null ) || '' === $destination['ciphertext'] || ! preg_match( '/\A[a-f0-9]{64}\z/', $destination['fingerprint'] ?? '' ) || ! preg_match( '/\A[0-9]{4}\z/', $destination['last4'] ?? '' ) ) ) { throw new RuntimeException( 'A refund requires a protected destination.' ); }
		$now = current_time( 'mysql', true );
		$values = array(
			'request_id' => $request->id(), 'order_id' => $request->wcOrderId(), 'phase' => 'allocation', 'action_type' => $type,
			'status' => 'pending', 'currency' => 'IRT', 'base_amount_toman' => $basis_toman,
			'site_amount_toman' => str_ends_with( $type, '_site' ) ? $amount : 0,
			'vendor_amount_toman' => str_ends_with( $type, '_vendor' ) ? $amount : 0,
			'buyer_refund_amount_toman' => $refund ? $amount : 0,
			'allocation_key' => $key, 'idempotency_key' => $key,
			'destination_ciphertext' => $refund ? $destination['ciphertext'] : null,
			'destination_fingerprint' => $refund ? $destination['fingerprint'] : null,
			'destination_last4' => $refund ? $destination['last4'] : null,
			'reason_ciphertext' => '' !== $reason_ciphertext ? $reason_ciphertext : null,
			'evidence_json' => wp_json_encode( $evidence, JSON_THROW_ON_ERROR ),
			'created_by' => $actor, 'created_at_gmt' => $now, 'updated_at_gmt' => $now, 'version' => 1,
		);
		if ( 1 !== $this->db->insert( $this->schema->financial_actions_table(), $values ) ) { throw new RuntimeException( 'Allocation insert failed.' ); }
		return $this->get( (int) $this->db->insert_id, $request->id(), true ) ?? throw new RuntimeException( 'Allocation readback failed.' );
	}

	public function get( int $id, int $request_id, bool $lock = false ): ?array {
		if ( $lock ) { $this->requireTransaction(); }
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d AND request_id = %d' . ( $lock ? ' FOR UPDATE' : '' ), $this->schema->financial_actions_table(), $id, $request_id ), ARRAY_A );
		if ( '' !== $this->db->last_error ) { throw new RuntimeException( 'Allocation read failed.' ); }
		return is_array( $row ) ? $row : null;
	}

	/** Status transition only: no financial amount, recipient or basis can be edited. */
	public function advance( array $row, string $next, int $actor, string $reference = '' ): array {
		$this->requireTransaction();
		$allowed = array( 'pending' => array( 'approved' ), 'approved' => array( 'executed', 'failed' ), 'failed' => array( 'approved' ), 'executed' => array( 'reconciled' ), 'reconciled' => array() );
		if ( ! in_array( $next, $allowed[ $row['status'] ] ?? array(), true ) || ( $actor > 0 && ! AdminCapabilities::userCanManageFinance( $actor ) ) || $actor < 0 ) { throw new RuntimeException( 'Invalid allocation transition.' ); }
		$refund = str_ends_with( $row['action_type'], '_refund' );
		if ( $refund && $actor < 1 ) { throw new RuntimeException( 'Refund actions require a named finance admin.' ); }
		$now = current_time( 'mysql', true );
		$values = array( 'status' => $next, 'updated_at_gmt' => $now, 'version' => (int) $row['version'] + 1 );
		if ( 'executed' === $next ) {
			if ( strlen( $reference ) < 3 || strlen( $reference ) > 190 || preg_match( '/[\x00-\x1f\x7f]/', $reference ) ) { throw new RuntimeException( 'Execution requires an external reference.' ); }
			// Do not allow one bank reference to be spent on two refunds.
			$used = $this->db->get_var( $this->db->prepare( "SELECT id FROM %i WHERE external_reference = %s AND id <> %d AND action_type LIKE %s LIMIT 1 FOR UPDATE", $this->schema->financial_actions_table(), $reference, (int) $row['id'], '%_refund' ) );
			if ( '' !== $this->db->last_error || ( $refund && null !== $used ) ) { throw new RuntimeException( 'Refund reference requires reconciliation.' ); }
			$values['external_reference'] = $reference;
			if ( $refund ) { $values['external_refund_hash'] = hash( 'sha256', 'bank-refund|' . $reference ); }
		}
		$stamp = match ( $next ) { 'approved' => 'approved', 'executed' => 'executed', 'reconciled' => 'reconciled', default => 'failed' };
		$values[ $stamp . '_at_gmt' ] = $now;
		$values[ $stamp . '_by' ] = $actor;
		if ( 'failed' === $next ) { $values['failure_code'] = 'admin_confirmed_not_transferred'; $values['failure_message_safe'] = 'انتقال طبق اعلام ثبت‌شدهٔ ادمین انجام نشده است؛ دلیل در سابقهٔ خصوصی پرونده است.'; }
		if ( 1 !== $this->db->update( $this->schema->financial_actions_table(), $values, array( 'id' => (int) $row['id'], 'version' => (int) $row['version'], 'status' => $row['status'] ) ) ) { throw new RuntimeException( 'Stale allocation transition.' ); }
		return $this->get( (int) $row['id'], (int) $row['request_id'], true ) ?? throw new RuntimeException( 'Allocation disappeared.' );
	}

	public function forFinance( int $request_id ): array {
		if ( ! AdminCapabilities::currentUserCanManageFinance() ) { throw new RuntimeException( 'Finance permission required.' ); }
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE request_id = %d ORDER BY id ASC LIMIT 30', $this->schema->financial_actions_table(), $request_id ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $this->db->last_error ) { throw new RuntimeException( 'Finance query failed.' ); }
		return $rows;
	}
	public static function amount( array $row ): int { return (int) $row['site_amount_toman'] + (int) $row['vendor_amount_toman'] + (int) $row['buyer_refund_amount_toman']; }
	public function linkLedger( array $row, string $key ): void {
		$this->requireTransaction();
		$ledger = $this->db->get_row( $this->db->prepare( 'SELECT id,request_id,order_id,amount_toman FROM %i WHERE allocation_key = %s FOR UPDATE', $this->schema->ledger_table(), hash( 'sha256', $key ) ), ARRAY_A );
		if ( ! is_array( $ledger ) || '' !== $this->db->last_error || (int) $ledger['request_id'] !== (int) $row['request_id'] || (int) $ledger['order_id'] !== (int) $row['order_id'] || (int) $ledger['amount_toman'] !== self::amount( $row ) ) { throw new RuntimeException( 'Allocation ledger binding failed.' ); }
		if ( null !== $row['ledger_entry_id'] ) { if ( (int) $row['ledger_entry_id'] !== (int) $ledger['id'] ) { throw new RuntimeException( 'Allocation ledger cannot change.' ); } return; }
		if ( 1 !== $this->db->update( $this->schema->financial_actions_table(), array( 'ledger_entry_id' => (int) $ledger['id'] ), array( 'id' => (int) $row['id'], 'ledger_entry_id' => null ) ) ) { throw new RuntimeException( 'Allocation ledger relation failed.' ); }
	}

	private function byKey( string $key ): ?array {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE allocation_key = %s FOR UPDATE', $this->schema->financial_actions_table(), $key ), ARRAY_A );
		if ( '' !== $this->db->last_error ) { throw new RuntimeException( 'Allocation lookup failed.' ); }
		return is_array( $row ) ? $row : null;
	}
	private function requireTransaction(): void { if ( ! $this->cases->hasActiveMutation() ) { throw new RuntimeException( 'Financial write outside Core transaction.' ); } }
}
