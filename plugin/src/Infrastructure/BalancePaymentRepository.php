<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use RuntimeException;
use wpdb;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;

/** Separate phase=balance intents; never edits a paid deposit intent or Woo order total. */
final class BalancePaymentRepository {
	private Schema $schema;
	public function __construct( private readonly wpdb $db, private readonly ManagedCaseRepository $cases ) { $this->schema = new Schema( $db ); }

	public function get( int $id, bool $lock = false ): ?array {
		if ( $lock ) { $this->transaction(); }
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM %i WHERE id = %d AND phase = 'balance' AND provider = 'zarinpal'" . ( $lock ? ' FOR UPDATE' : '' ), $this->schema->payments_table(), $id ), ARRAY_A );
		if ( '' !== $this->db->last_error ) { throw new RuntimeException( 'Balance intent read failed.' ); }
		return is_array( $row ) ? $row : null;
	}

	public function prepare( PurchaseRequest $request, array $case, string $merchant_fingerprint ): array {
		$this->transaction();
		$finance = $case['data']['spec4'];
		$amount = $finance['remaining_toman'];
		if ( 'live' !== $request->executionMode() || ! $request->wcOrderId() || $amount < 1 || $amount > intdiv( PHP_INT_MAX, 10 ) || null !== $case['data']['manual']['balance'] || ! preg_match( '/\A[a-f0-9]{64}\z/', $merchant_fingerprint ) ) { throw new RuntimeException( 'Invalid balance intent basis.' ); }
		$key = hash( 'sha256', 'spec4-balance|' . $request->id() . '|' . $finance['offer_id'] . '|' . $finance['basis_hash'] );
		$now = current_time( 'mysql', true );
		$sql = $this->db->prepare( "INSERT IGNORE INTO %i (request_id,order_id,provider,phase,expected_amount_toman,status,idempotency_hash,merchant_fingerprint,intent_deadline_gmt,created_at_gmt,updated_at_gmt) VALUES (%d,%d,'zarinpal','balance',%d,'expected',%s,%s,%s,%s,%s)", $this->schema->payments_table(), $request->id(), $request->wcOrderId(), $amount, $key, $merchant_fingerprint, $case['deadline_gmt'], $now, $now );
		if ( false === $this->db->query( $sql ) ) { throw new RuntimeException( 'Balance intent insert failed.' ); }
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE idempotency_hash = %s FOR UPDATE', $this->schema->payments_table(), $key ), ARRAY_A );
		if ( ! is_array( $row ) || '' !== $this->db->last_error || (int) $row['request_id'] !== $request->id() || (int) $row['order_id'] !== $request->wcOrderId() || (int) $row['expected_amount_toman'] !== $amount || 'balance' !== $row['phase'] || 'zarinpal' !== $row['provider'] || ! hash_equals( $merchant_fingerprint, (string) $row['merchant_fingerprint'] ) ) { throw new RuntimeException( 'Balance intent binding differs.' ); }
		return $row;
	}

	public function begin( array $row, string $deadline ): array {
		if ( 'expected' !== $row['status'] || ! empty( $row['authority'] ) || ! empty( $row['reference'] ) ) { throw new RuntimeException( 'An online attempt is already unresolved.' ); }
		if ( $deadline <= current_time( 'mysql', true ) || ! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $deadline ) ) { throw new RuntimeException( 'Payment issuance requires a future Core deadline.' ); }
		// Only an unissued/definitively rejected attempt can snapshot a new window.
		return $this->update( $row, array( 'status' => 'issuance_pending', 'intent_deadline_gmt' => $deadline ) );
	}

	public function issued( array $row, string $authority ): array {
		if ( 'issuance_pending' !== $row['status'] || ! preg_match( '/\A[A-Za-z0-9]{20,128}\z/', $authority ) ) { throw new RuntimeException( 'Invalid authority response.' ); }
		return $this->update( $row, array( 'authority' => $authority, 'status' => 'authority_issued' ) );
	}

	public function issuanceFailed( array $row, bool $definitive ): array {
		if ( 'issuance_pending' !== $row['status'] || ! empty( $row['authority'] ) ) { throw new RuntimeException( 'Invalid issuance failure.' ); }
		return $this->update( $row, array( 'status' => $definitive ? 'expected' : 'issuance_unknown' ) );
	}

	public function captured( array $row, string $authority, string $reference, bool $late ): array {
		if ( ! is_string( $row['authority'] ) || ! hash_equals( $row['authority'], $authority ) || ! preg_match( '/\A[0-9]{1,128}\z/', $reference ) ) { throw new RuntimeException( 'Verified payment identity differs.' ); }
		if ( in_array( $row['status'], array( 'captured', 'late_captured', 'late_accepted' ), true ) ) {
			if ( ! hash_equals( (string) $row['reference'], $reference ) || (int) $row['paid_amount_toman'] !== (int) $row['expected_amount_toman'] ) { throw new RuntimeException( 'Verified replay differs.' ); }
			return $row;
		}
		if ( ! in_array( $row['status'], array( 'authority_issued', 'verification_pending' ), true ) ) { throw new RuntimeException( 'Intent cannot be captured.' ); }
		return $this->update( $row, array( 'status' => $late ? 'late_captured' : 'captured', 'paid_amount_toman' => (int) $row['expected_amount_toman'], 'reference' => $reference, 'paid_at_gmt' => current_time( 'mysql', true ) ) );
	}

	public function verificationPending( array $row ): array {
		if ( 'verification_pending' === $row['status'] ) { return $row; }
		if ( 'authority_issued' !== $row['status'] ) { throw new RuntimeException( 'Invalid verification state.' ); }
		return $this->update( $row, array( 'status' => 'verification_pending' ) );
	}
	public function callbackSeen( array $row ): array {
		if ( ! empty( $row['callback_at_gmt'] ) ) { return $row; }
		if ( ! in_array( $row['status'], array( 'authority_issued', 'verification_pending' ), true ) ) { throw new RuntimeException( 'Callback has no issued intent.' ); }
		return $this->update( $row, array( 'status' => 'verification_pending', 'callback_at_gmt' => current_time( 'mysql', true ) ) );
	}
	public function acceptLate( array $row ): array {
		if ( 'late_captured' !== $row['status'] || (int) $row['expected_amount_toman'] !== (int) $row['paid_amount_toman'] || empty( $row['reference'] ) ) { throw new RuntimeException( 'No verified late payment.' ); }
		return $this->update( $row, array( 'status' => 'late_accepted' ) );
	}
	private function update( array $row, array $values ): array {
		$this->transaction();
		$values['updated_at_gmt'] = current_time( 'mysql', true );
		if ( 1 !== $this->db->update( $this->schema->payments_table(), $values, array( 'id' => (int) $row['id'], 'status' => $row['status'] ) ) ) { throw new RuntimeException( 'Payment write conflict.' ); }
		return $this->get( (int) $row['id'], true ) ?? throw new RuntimeException( 'Payment readback failed.' );
	}
	private function transaction(): void { if ( ! $this->cases->hasActiveMutation() ) { throw new RuntimeException( 'Payment write requires a Core transaction.' ); } }
}
