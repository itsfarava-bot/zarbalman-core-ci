<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use RuntimeException;
use WP_Error;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Domain\Finance\DepositVerificationStatus;
use Zarbalman\Core\Security\AdminCapabilities;
use wpdb;

final class PaymentRepository {
	private Schema $schema;

	public function __construct( private readonly wpdb $db ) {
		$this->schema = new Schema( $db );
	}

	/** Finance-only read of immutable captured manual payments, newest first. */
	public function capturedManualForFinance( int $page = 1, int $limit = 50 ): array {
		if ( ! AdminCapabilities::currentUserCanManageFinance() ) {
			throw new RuntimeException( 'Finance permission is required to view manual payments.' );
		}
		$page = max( 1, min( 100000, $page ) );
		$limit = max( 1, min( 100, $limit ) );
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id,request_id,order_id,phase,paid_amount_toman,paid_at_gmt,status FROM %i WHERE provider = 'manual_bank' AND status = 'captured' ORDER BY id DESC LIMIT %d OFFSET %d",
				$this->schema->payments_table(), $limit, ( $page - 1 ) * $limit
			),
			ARRAY_A
		);
		if ( '' !== $this->db->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( 'Finance payment report could not be loaded.' );
		}
		return $rows;
	}

	/** Read-only proof for recovering a previously confirmed deposit; no new capture. */
	public function confirmedManualDepositMatches( PurchaseRequest $request, int $order_id, int $payment_id, int $amount, string $offer_id ): bool {
		if ( $order_id < 1 || $payment_id < 1 || $amount !== $request->depositToman() || ! wp_is_uuid( $offer_id ) ) {
			return false;
		}
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM %i WHERE request_id = %d AND phase = 'deposit' AND status IN ('captured','late_captured','late_accepted') LIMIT 2",
				$this->schema->payments_table(), $request->id()
			),
			ARRAY_A
		);
		if ( '' !== $this->db->last_error || ! is_array( $rows ) || 1 !== count( $rows ) ) {
			return false;
		}
		$online_conflicts = $this->db->get_results(
			$this->db->prepare(
				"SELECT id FROM %i WHERE request_id = %d AND phase = 'deposit' AND provider = 'zarinpal' AND (TRIM(COALESCE(authority,'')) <> '' OR TRIM(COALESCE(reference,'')) <> '' OR status NOT IN ('expected','expired')) LIMIT 1",
				$this->schema->payments_table(), $request->id()
			),
			ARRAY_A
		);
		if ( '' !== $this->db->last_error || ! is_array( $online_conflicts ) || array() !== $online_conflicts ) {
			return false;
		}
		$row = $rows[0];
		return (int) ( $row['id'] ?? 0 ) === $payment_id
			&& (int) ( $row['request_id'] ?? 0 ) === $request->id()
			&& (int) ( $row['order_id'] ?? 0 ) === $order_id
			&& (int) ( $row['expected_amount_toman'] ?? 0 ) === $amount
			&& (int) ( $row['paid_amount_toman'] ?? 0 ) === $amount
			&& 'manual_bank' === ( $row['provider'] ?? null )
			&& 'deposit' === ( $row['phase'] ?? null )
			&& 'captured' === ( $row['status'] ?? null )
			&& hash_equals( hash( 'sha256', 'manual-bank|' . $request->id() . '|deposit|' . strtolower( $offer_id ) ), (string) ( $row['idempotency_hash'] ?? '' ) )
			&& '' === trim( (string) ( $row['authority'] ?? '' ) )
			&& '' === trim( (string) ( $row['reference'] ?? '' ) );
	}

	public function prepareExpectedDeposit( PurchaseRequest $request, int $order_id ): bool|WP_Error {
		$key = hash( 'sha256', 'expected-deposit|' . $request->id() . '|' . $order_id );
		$now = current_time( 'mysql', true );
		$sql = $this->db->prepare(
			"INSERT IGNORE INTO %i
			(request_id,order_id,provider,phase,expected_amount_toman,status,idempotency_hash,created_at_gmt,updated_at_gmt)
			VALUES (%d,%d,'zarinpal','deposit',%d,'expected',%s,%s,%s)",
			$this->schema->payments_table(), $request->id(), $order_id, $request->depositToman(), $key, $now, $now
		);
		$result = $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		if ( false === $result ) {
			return new WP_Error( 'zbm_payment_prepare_failed', __( 'ثبت امن انتظار پرداخت انجام نشد.', 'zarbalman-core' ) );
		}
		$existing = $this->findExpectedByOrder( $order_id );
		if (
			! $existing
			|| (int) $existing['request_id'] !== $request->id()
			|| (int) $existing['expected_amount_toman'] !== $request->depositToman()
			|| 'zarinpal' !== (string) $existing['provider']
			|| 'deposit' !== (string) $existing['phase']
			|| 'expected' !== (string) $existing['status']
		) {
			return new WP_Error( 'zbm_payment_prepare_conflict', __( 'اطلاعات انتظار پرداخت با سفارش سازگار نیست.', 'zarbalman-core' ) );
		}
		return true;
	}

	/** @return array<string,mixed>|null */
	public function findExpectedByOrder( int $order_id ): ?array {
		$sql = $this->db->prepare(
			"SELECT * FROM %i WHERE order_id = %d AND provider = 'zarinpal' AND phase = 'deposit' ORDER BY id ASC LIMIT 1",
			$this->schema->payments_table(), $order_id
		);
		$row = $this->db->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		return is_array( $row ) ? $row : null;
	}

	public function markExpiredByOrder( int $order_id ): bool {
		$row = $this->findExpectedByOrder( $order_id );
		if ( ! is_array( $row ) ) {
			return false;
		}
		$status = (string) $row['status'];
		if ( 'expired' === $status ) {
			return true;
		}
		if ( ! in_array( $status, array( 'expected', 'authority_issued' ), true ) ) {
			return false;
		}
		$result = $this->db->update(
			$this->schema->payments_table(),
			array( 'status' => 'expired', 'updated_at_gmt' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $row['id'], 'status' => $status ),
			array( '%s', '%s' ), array( '%d', '%s' )
		);
		return 1 === $result;
	}

	public function expireUnverifiedByOrder( int $order_id ): bool {
		$row = $this->findExpectedByOrder( $order_id );
		if ( ! is_array( $row ) ) {
			return false;
		}
		$status = (string) $row['status'];
		if ( 'expired' === $status ) {
			return true;
		}
		if ( ! in_array( $status, array( 'expected', 'authority_issued' ), true ) && ! DepositVerificationStatus::isPending( $status ) ) {
			return false;
		}
		$updated = $this->db->update(
			$this->schema->payments_table(),
			array( 'status' => 'expired', 'updated_at_gmt' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $row['id'], 'status' => $status ),
			array( '%s', '%s' ), array( '%d', '%s' )
		);
		return 1 === $updated;
	}

	public function reopenExpectedByOrder( int $order_id ): bool {
		$row = $this->findExpectedByOrder( $order_id );
		if ( ! is_array( $row ) || 'expired' !== (string) $row['status'] ) {
			return false;
		}
		$status = '' !== trim( (string) ( $row['authority'] ?? '' ) ) ? 'authority_issued' : 'expected';
		$result = $this->db->update(
			$this->schema->payments_table(),
			array( 'status' => $status, 'updated_at_gmt' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $row['id'], 'status' => 'expired' ),
			array( '%s', '%s' ), array( '%d', '%s' )
		);
		return 1 === $result;
	}

	/** Caller holds the request operation lock before any provider request is sent. */
	public function markIssuancePending( PurchaseRequest $request, int $order_id ): bool {
		if ( $order_id < 1 || $request->wcOrderId() !== $order_id ) { return false; }
		return 1 === $this->db->query( $this->db->prepare(
			"UPDATE %i SET status = 'issuance_pending', updated_at_gmt = %s WHERE request_id = %d AND order_id = %d AND provider = 'zarinpal' AND phase = 'deposit' AND status = 'expected' AND expected_amount_toman = %d AND COALESCE(authority,'') = '' AND COALESCE(reference,'') = ''",
			$this->schema->payments_table(), current_time( 'mysql', true ), $request->id(), $order_id, $request->depositToman()
		) );
	}

	/** Only an explicit negative request.json response may clear an issuance attempt. */
	public function recordIssuanceRejected( PurchaseRequest $request, int $order_id ): bool {
		if ( $request->wcOrderId() !== $order_id ) { return false; }
		return 1 === $this->db->query( $this->db->prepare(
			"UPDATE %i SET status = 'expected', updated_at_gmt = %s WHERE request_id = %d AND order_id = %d AND provider = 'zarinpal' AND phase = 'deposit' AND status = 'issuance_pending' AND COALESCE(authority,'') = '' AND COALESCE(reference,'') = ''",
			$this->schema->payments_table(), current_time( 'mysql', true ), $request->id(), $order_id
		) );
	}

	public function persistIssuedAuthority( PurchaseRequest $request, int $order_id, string $authority ): bool|WP_Error {
		$authority = trim( $authority );
		if ( '' === $authority || strlen( $authority ) > 128 ) {
			return new WP_Error( 'zbm_payment_authority_invalid', __( 'شناسه صادرشدهٔ پرداخت معتبر نیست.', 'zarbalman-core' ) );
		}
		$row = $this->findExpectedByOrder( $order_id );
		if (
			! is_array( $row )
			|| (int) $row['request_id'] !== $request->id()
			|| (int) $row['expected_amount_toman'] !== $request->depositToman()
		) {
			return new WP_Error( 'zbm_payment_authority_conflict', __( 'رکورد انتظار پرداخت با شناسه صادرشده سازگار نیست.', 'zarbalman-core' ) );
		}
		$status = (string) $row['status'];
		if ( 'authority_issued' === $status ) {
			return hash_equals( (string) ( $row['authority'] ?? '' ), $authority )
				? true
				: new WP_Error( 'zbm_payment_authority_conflict', __( 'برای این درخواست قبلاً شناسه پرداخت دیگری صادر شده است.', 'zarbalman-core' ) );
		}
		if ( ! in_array( $status, array( 'expected', 'issuance_pending' ), true ) || '' !== trim( (string) ( $row['authority'] ?? '' ) ) ) {
			return new WP_Error( 'zbm_payment_authority_state_invalid', __( 'وضعیت پرداخت برای ثبت شناسه جدید معتبر نیست.', 'zarbalman-core' ) );
		}
		$updated = $this->db->update(
			$this->schema->payments_table(),
			array( 'status' => 'authority_issued', 'authority' => $authority, 'updated_at_gmt' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $row['id'], 'status' => $status ),
			array( '%s', '%s', '%s' ), array( '%d', '%s' )
		);
		return 1 === $updated ? true : new WP_Error( 'zbm_payment_authority_store_failed', __( 'ثبت ماندگار شناسه پرداخت انجام نشد.', 'zarbalman-core' ) );
	}

	public function persistCallbackReceipt(
		PurchaseRequest $request,
		int $order_id,
		string $authority,
		bool $on_time
	): string|WP_Error {
		$authority = trim( $authority );
		if ( '' === $authority || strlen( $authority ) > 128 ) {
			return new WP_Error( 'zbm_payment_receipt_invalid', __( 'شناسه callback پرداخت معتبر نیست.', 'zarbalman-core' ) );
		}
		$row = $this->findExpectedByOrder( $order_id );
		if (
			! is_array( $row )
			|| (int) $row['request_id'] !== $request->id()
			|| (int) $row['expected_amount_toman'] !== $request->depositToman()
		) {
			return new WP_Error( 'zbm_payment_receipt_conflict', __( 'رکورد انتظار پرداخت با callback سازگار نیست.', 'zarbalman-core' ) );
		}

		$current_status = (string) $row['status'];
		if ( in_array( $current_status, array( 'captured', 'late_captured', 'late_accepted' ), true ) || DepositVerificationStatus::isRecoverable( $current_status ) ) {
			return hash_equals( (string) ( $row['authority'] ?? '' ), $authority )
				? $current_status
				: new WP_Error( 'zbm_payment_receipt_identity_conflict', __( 'شناسه callback با تلاش ثبت‌شدهٔ قبلی سازگار نیست.', 'zarbalman-core' ) );
		}
		if ( ! in_array( $current_status, array( 'expected', 'authority_issued', 'expired' ), true ) ) {
			return new WP_Error( 'zbm_payment_receipt_state_invalid', __( 'وضعیت پرداخت برای ثبت callback معتبر نیست.', 'zarbalman-core' ) );
		}
		$recorded_authority = trim( (string) ( $row['authority'] ?? '' ) );
		if ( '' !== $recorded_authority && ! hash_equals( $recorded_authority, $authority ) ) {
			return new WP_Error( 'zbm_payment_receipt_identity_conflict', __( 'شناسه callback با شناسه صادرشده سازگار نیست.', 'zarbalman-core' ) );
		}

		$new_status = DepositVerificationStatus::received( $on_time && in_array( $current_status, array( 'expected', 'authority_issued' ), true ) );
		$updated = $this->db->update(
			$this->schema->payments_table(),
			array(
				'status'         => $new_status,
				'authority'      => $authority,
				'reference'      => null,
				'paid_at_gmt'    => null,
				'updated_at_gmt' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $row['id'], 'status' => $current_status ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		return 1 === $updated ? $new_status : new WP_Error( 'zbm_payment_receipt_store_failed', __( 'ثبت ماندگار ورود callback انجام نشد.', 'zarbalman-core' ) );
	}

	public function releaseUnverifiedCallbackReceipt( int $order_id, string $authority, bool $payment_still_open ): bool {
		$row = $this->findExpectedByOrder( $order_id );
		if (
			! is_array( $row )
			|| ! DepositVerificationStatus::isReceived( (string) $row['status'] )
			|| ! hash_equals( (string) ( $row['authority'] ?? '' ), trim( $authority ) )
		) {
			return false;
		}
		$updated = $this->db->update(
			$this->schema->payments_table(),
			array(
				'status'            => $payment_still_open ? 'authority_issued' : 'expired',
				'authority'         => trim( $authority ),
				'reference'         => null,
				'paid_amount_toman' => null,
				'paid_at_gmt'       => null,
				'updated_at_gmt'    => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $row['id'], 'status' => (string) $row['status'] ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		return 1 === $updated;
	}

	public function persistVerificationAttempt(
		PurchaseRequest $request,
		int $order_id,
		string $authority,
		string $reference,
		bool $on_time
	): bool|WP_Error {
		$authority = trim( $authority );
		$reference = trim( $reference );
		if ( '' === $authority || '' === $reference || strlen( $authority ) > 128 || strlen( $reference ) > 128 ) {
			return new WP_Error( 'zbm_payment_attempt_invalid', __( 'هویت بازگشتی پرداخت معتبر نیست.', 'zarbalman-core' ) );
		}
		$row = $this->findExpectedByOrder( $order_id );
		if (
			! is_array( $row )
			|| (int) $row['request_id'] !== $request->id()
			|| (int) $row['expected_amount_toman'] !== $request->depositToman()
		) {
			return new WP_Error( 'zbm_payment_attempt_conflict', __( 'رکورد انتظار پرداخت با درخواست سازگار نیست.', 'zarbalman-core' ) );
		}

		$current_status = (string) $row['status'];
		if ( in_array( $current_status, array( 'captured', 'late_captured', 'late_accepted' ), true ) ) {
			return hash_equals( (string) $row['authority'], $authority ) && hash_equals( (string) $row['reference'], $reference )
				? true
				: new WP_Error( 'zbm_payment_attempt_identity_conflict', __( 'هویت پرداخت با ثبت قطعی قبلی سازگار نیست.', 'zarbalman-core' ) );
		}
		if ( DepositVerificationStatus::isReceived( $current_status ) ) {
			if ( ! hash_equals( (string) ( $row['authority'] ?? '' ), $authority ) ) {
				return new WP_Error( 'zbm_payment_attempt_identity_conflict', __( 'هویت پرداخت با callback ثبت‌شده سازگار نیست.', 'zarbalman-core' ) );
			}
			// Callback arrival is public and cannot prove settlement time. Timing is
			// frozen only when Core obtains an authoritative provider result.
			$new_status = DepositVerificationStatus::pending( $on_time );
		} elseif ( DepositVerificationStatus::isRecoverable( $current_status ) ) {
			return hash_equals( (string) $row['authority'], $authority ) && hash_equals( (string) $row['reference'], $reference )
				? true
				: new WP_Error( 'zbm_payment_attempt_identity_conflict', __( 'هویت پرداخت با تلاش قبلی سازگار نیست.', 'zarbalman-core' ) );
		} elseif ( ! in_array( $current_status, array( 'expected', 'authority_issued', 'expired' ), true ) ) {
			return new WP_Error( 'zbm_payment_attempt_state_invalid', __( 'وضعیت رکورد پرداخت برای ثبت callback معتبر نیست.', 'zarbalman-core' ) );
		} else {
			$recorded_authority = trim( (string) ( $row['authority'] ?? '' ) );
			if ( '' !== $recorded_authority && ! hash_equals( $recorded_authority, $authority ) ) {
				return new WP_Error( 'zbm_payment_attempt_identity_conflict', __( 'هویت پرداخت با شناسه صادرشده سازگار نیست.', 'zarbalman-core' ) );
			}
			$new_status = DepositVerificationStatus::pending( $on_time && in_array( $current_status, array( 'expected', 'authority_issued' ), true ) );
		}

		$now = current_time( 'mysql', true );
		$updated = $this->db->update(
			$this->schema->payments_table(),
			array(
				'paid_amount_toman' => $request->depositToman(),
				'status'            => $new_status,
				'authority'         => $authority,
				'reference'         => $reference,
				'paid_at_gmt'       => $now,
				'updated_at_gmt'    => $now,
			),
			array( 'id' => (int) $row['id'], 'status' => $current_status ),
			array( '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		return 1 === $updated ? true : new WP_Error( 'zbm_payment_attempt_store_failed', __( 'ثبت ماندگار callback پرداخت انجام نشد.', 'zarbalman-core' ) );
	}

	public function markVerificationConfirmed( int $order_id, string $authority, string $reference ): bool|WP_Error {
		$row = $this->findExpectedByOrder( $order_id );
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'zbm_payment_verification_missing', __( 'رکورد callback پرداخت موجود نیست.', 'zarbalman-core' ) );
		}
		$status = (string) $row['status'];
		if ( in_array( $status, array( 'captured', 'late_captured', 'late_accepted' ), true ) ) {
			return hash_equals( (string) $row['authority'], $authority ) && hash_equals( (string) $row['reference'], $reference )
				? true
				: new WP_Error( 'zbm_payment_verification_conflict', __( 'هویت پرداخت با ثبت قطعی قبلی سازگار نیست.', 'zarbalman-core' ) );
		}
		if ( DepositVerificationStatus::isVerified( $status ) ) {
			return hash_equals( (string) $row['authority'], $authority ) && hash_equals( (string) $row['reference'], $reference )
				? true
				: new WP_Error( 'zbm_payment_verification_conflict', __( 'هویت تأییدشده پرداخت تعارض دارد.', 'zarbalman-core' ) );
		}
		if (
			! DepositVerificationStatus::isPending( $status )
			|| ! hash_equals( (string) $row['authority'], $authority )
			|| ! hash_equals( (string) $row['reference'], $reference )
		) {
			return new WP_Error( 'zbm_payment_verification_conflict', __( 'callback ذخیره‌شده با استعلام قطعی سازگار نیست.', 'zarbalman-core' ) );
		}
		$updated = $this->db->update(
			$this->schema->payments_table(),
			array( 'status' => DepositVerificationStatus::verifiedFrom( $status ), 'updated_at_gmt' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $row['id'], 'status' => $status ),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
		return 1 === $updated ? true : new WP_Error( 'zbm_payment_verification_store_failed', __( 'ثبت تأیید قطعی زرین‌پال انجام نشد.', 'zarbalman-core' ) );
	}

	/** @return array<string,mixed>|null */
	public function recoverableIntentByOrder( int $order_id ): ?array {
		$row = $this->findExpectedByOrder( $order_id );
		return is_array( $row ) && ( 'authority_issued' === (string) $row['status'] || DepositVerificationStatus::isRecoverable( (string) $row['status'] ) ) ? $row : null;
	}

	/**
	 * Must be evaluated while the request operation lock is held. An expected or
	 * expired row with no provider identity is not evidence of a debit; every
	 * other/unknown status fails closed.
	 */
	public function hasUnresolvedOnlineAttempt( int $request_id, string $phase ): bool {
		if ( $request_id < 1 || ! in_array( $phase, array( 'deposit', 'balance' ), true ) ) {
			return true;
		}
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT status,authority,reference FROM %i WHERE request_id = %d AND provider = 'zarinpal' AND phase = %s FOR UPDATE",
				$this->schema->payments_table(), $request_id, $phase
			),
			ARRAY_A
		);
		if ( '' !== $this->db->last_error || ! is_array( $rows ) ) {
			return true;
		}
		foreach ( $rows as $row ) {
			if ( '' !== trim( (string) ( $row['authority'] ?? '' ) ) || '' !== trim( (string) ( $row['reference'] ?? '' ) ) ) {
				return true;
			}
			if ( ! in_array( (string) ( $row['status'] ?? '' ), array( 'expected', 'expired' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Insert exactly one real manual capture inside the caller's existing SQL
	 * transaction. No Authority/RefID is invented and no transaction is nested.
	 *
	 * @return array{id:int,amount:int}|WP_Error
	 */
	public function captureManual( PurchaseRequest $request, int $order_id, string $phase, int $amount_toman, string $offer_id ): array|WP_Error {
		if (
			$request->executionMode() !== 'live'
			|| $order_id < 1
			|| ! in_array( $phase, array( 'deposit', 'balance' ), true )
			|| $amount_toman < 1
			|| ! wp_is_uuid( $offer_id )
		) {
			return new WP_Error( 'zbm_manual_capture_invalid', __( 'هویت یا مبلغ پرداخت دستی معتبر نیست.', 'zarbalman-core' ) );
		}
		$idempotency = hash( 'sha256', 'manual-bank|' . $request->id() . '|' . $phase . '|' . strtolower( $offer_id ) );
		$existing = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM %i WHERE idempotency_hash = %s FOR UPDATE', $this->schema->payments_table(), $idempotency ),
			ARRAY_A
		);
		if ( '' !== $this->db->last_error ) {
			return new WP_Error( 'zbm_manual_capture_storage', __( 'ذخیره پرداخت دستی در دسترس نیست.', 'zarbalman-core' ) );
		}
		if ( is_array( $existing ) ) {
			return $this->sameManualCapture( $existing, $request, $order_id, $phase, $amount_toman )
				? array( 'id' => (int) $existing['id'], 'amount' => $amount_toman )
				: new WP_Error( 'zbm_manual_capture_conflict', __( 'شناسه پرداخت دستی با ثبت قطعی دیگری تعارض دارد.', 'zarbalman-core' ) );
		}

		$captured = $this->db->get_results(
			$this->db->prepare(
				"SELECT id FROM %i WHERE request_id = %d AND phase = %s AND status IN ('captured','late_captured','late_accepted') FOR UPDATE",
				$this->schema->payments_table(), $request->id(), $phase
			),
			ARRAY_A
		);
		if ( '' !== $this->db->last_error || ! is_array( $captured ) || array() !== $captured ) {
			return new WP_Error( 'zbm_manual_capture_duplicate', __( 'برای این مرحله قبلاً وجه قطعی ثبت شده یا وضعیت آن نامشخص است.', 'zarbalman-core' ) );
		}

		$now = current_time( 'mysql', true );
		$inserted = $this->db->insert(
			$this->schema->payments_table(),
			array(
				'request_id'           => $request->id(),
				'order_id'             => $order_id,
				'provider'             => 'manual_bank',
				'phase'                => $phase,
				'expected_amount_toman' => $amount_toman,
				'paid_amount_toman'    => $amount_toman,
				'status'               => 'captured',
				'authority'            => null,
				'reference'            => null,
				'idempotency_hash'     => $idempotency,
				'paid_at_gmt'          => $now,
				'created_at_gmt'       => $now,
				'updated_at_gmt'       => $now,
			),
			array( '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( 1 !== $inserted ) {
			return new WP_Error( 'zbm_manual_capture_storage', __( 'ثبت قطعی پرداخت دستی انجام نشد.', 'zarbalman-core' ) );
		}
		$id = (int) $this->db->insert_id;
		return $id > 0
			? array( 'id' => $id, 'amount' => $amount_toman )
			: new WP_Error( 'zbm_manual_capture_storage', __( 'شناسه ثبت قطعی پرداخت دستی دریافت نشد.', 'zarbalman-core' ) );
	}

	/** @param array<string,mixed> $row */
	private function sameManualCapture( array $row, PurchaseRequest $request, int $order_id, string $phase, int $amount ): bool {
		return (int) ( $row['request_id'] ?? 0 ) === $request->id()
			&& (int) ( $row['order_id'] ?? 0 ) === $order_id
			&& 'manual_bank' === ( $row['provider'] ?? null )
			&& $phase === ( $row['phase'] ?? null )
			&& $amount === (int) ( $row['expected_amount_toman'] ?? 0 )
			&& $amount === (int) ( $row['paid_amount_toman'] ?? 0 )
			&& 'captured' === ( $row['status'] ?? null )
			&& '' === trim( (string) ( $row['authority'] ?? '' ) )
			&& '' === trim( (string) ( $row['reference'] ?? '' ) );
	}
}
