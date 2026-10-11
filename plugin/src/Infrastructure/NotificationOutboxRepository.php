<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use InvalidArgumentException;
use Throwable;
use wpdb;
use Zarbalman\Core\Domain\ManagedFlow\OperationsNotificationPolicy;
use Zarbalman\Core\Domain\PurchaseRequest\ExecutionMode;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;

final class NotificationOutboxRepository {
	public const SUPPLIER_REMINDER = 'supplier_response_reminder_vendor';
	public const SUPPLIER_REMINDER_LEAD_SECONDS = 600;
	private const SAFE_VARIABLES = array(
		'request_public_id', 'product_name', 'quantity', 'state_label', 'deadline',
		'reason', 'dashboard_url', 'amount_toman', 'recipient_name',
		'generation',
		'window_generation',
	);

	private Schema $schema;

	public function __construct( private readonly wpdb $db ) {
		$this->schema = new Schema( $db );
	}

	/** @param array<string,int|string> $safe_variables */
	public function enqueue(
		string $event_uuid,
		?int $request_id,
		string $recipient_role,
		int $recipient_user_id,
		string $template_key,
		array $safe_variables
	): bool {
		if ( ! wp_is_uuid( $event_uuid ) || $recipient_user_id < 1 ) {
			throw new InvalidArgumentException( 'Invalid notification recipient.' );
		}
		$recipient_role = sanitize_key( $recipient_role );
		$template_key   = sanitize_key( $template_key );
		if ( ! in_array( $recipient_role, array( 'buyer', 'vendor', 'admin' ), true ) || '' === $template_key ) {
			throw new InvalidArgumentException( 'Invalid notification route.' );
		}
		foreach ( $safe_variables as $key => $value ) {
			if ( ! in_array( $key, self::SAFE_VARIABLES, true ) || ! is_scalar( $value ) ) {
				throw new InvalidArgumentException( 'Unsafe notification variable.' );
			}
		}

		$encoded = wp_json_encode( $safe_variables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 5000 ) {
			throw new InvalidArgumentException( 'Notification payload is too large.' );
		}
		// Legacy callers may still announce merged events. Keep their canonical
		// audit, but do not create redundant queue work. The worker also cancels
		// retired messages that were already queued before the upgrade.
		if ( in_array( $template_key, OperationsNotificationPolicy::RETIRED, true ) ) { return true; }
		$dedupe = hash( 'sha256', implode( '|', array( $event_uuid, $recipient_role, (string) $recipient_user_id, $template_key ) ) );
		$next_attempt = current_time( 'mysql', true );
		if ( self::SUPPLIER_REMINDER === $template_key ) {
			$deadline = $safe_variables['deadline'] ?? null;
			$timestamp = is_string( $deadline ) ? strtotime( $deadline . ' UTC' ) : false;
			if ( ! $request_id || $request_id < 1 || 'vendor' !== $recipient_role || false === $timestamp || gmdate( 'Y-m-d H:i:s', $timestamp ) !== $deadline ) {
				throw new InvalidArgumentException( 'Invalid supplier reminder deadline or recipient.' );
			}
			// Deadline identity survives repeated hooks, recovery and owner changes.
			// A failed/cancelled/sent row is never re-created for this same deadline.
			$dedupe = hash( 'sha256', implode( '|', array( self::SUPPLIER_REMINDER, (string) $request_id, $deadline ) ) );
			$next_attempt = gmdate( 'Y-m-d H:i:s', $timestamp - self::SUPPLIER_REMINDER_LEAD_SECONDS );
		}
		$result = $this->db->query(
			$this->db->prepare(
				"INSERT IGNORE INTO %i
				(dedupe_hash,event_uuid,request_id,recipient_role,recipient_user_id,template_key,payload_json,status,attempt_count,next_attempt_gmt,created_at_gmt)
				VALUES (%s,%s,%d,%s,%d,%s,%s,'pending',0,%s,%s)",
				$this->schema->notification_outbox_table(),
				$dedupe,
				$event_uuid,
				$request_id,
				$recipient_role,
				$recipient_user_id,
				$template_key,
				$encoded,
				$next_attempt,
				current_time( 'mysql', true )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

		return false !== $result;
	}

	/** Recover only unqueued live deadlines in the final ten minutes, earliest first.
	 *  @return list<int>
	 */
	public function missingSupplierReminderIds( int $limit = 100 ): array {
		$now = current_time( 'mysql', true );
		$sql = $this->db->prepare(
			"SELECT requests.id FROM %i requests
			LEFT JOIN %i outbox ON outbox.dedupe_hash = SHA2(CONCAT(%s, '|', requests.id, '|', requests.supplier_deadline_gmt), 256)
			WHERE requests.supplier_deadline_gmt > %s AND requests.supplier_deadline_gmt <= %s
			AND requests.current_state = %s AND requests.execution_mode = %s
			AND requests.is_paused = 0 AND requests.vendor_id > 0 AND outbox.id IS NULL
			ORDER BY requests.supplier_deadline_gmt ASC, requests.id ASC LIMIT %d",
			$this->schema->purchase_requests_table(), $this->schema->notification_outbox_table(), self::SUPPLIER_REMINDER,
			$now, gmdate( 'Y-m-d H:i:s', time() + self::SUPPLIER_REMINDER_LEAD_SECONDS ),
			RequestState::SUPPLIER_REVIEW, ExecutionMode::LIVE, max( 1, min( 100, $limit ) )
		);
		$ids = $this->db->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/** @return list<array<string,mixed>> */
	public function due( int $limit = 25 ): array {
		$limit = max( 1, min( 100, $limit ) );
		$now = current_time( 'mysql', true );
		$this->db->query( 'START TRANSACTION' );
		try {
			$stale_before = gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS );
			// An indeterminate reminder is visible as failed; do not send it twice.
			$this->db->query( $this->db->prepare( "UPDATE %i SET status = 'failed', next_attempt_gmt = NULL, locked_at_gmt = NULL, last_error = %s WHERE status = 'sending' AND template_key IN ('managed_delivery_reminder', %s) AND locked_at_gmt < %s", $this->schema->notification_outbox_table(), 'Reminder outcome indeterminate; automatic repeat suppressed.', self::SUPPLIER_REMINDER, $stale_before ) );
			$this->db->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately below.
				$this->db->prepare(
					"UPDATE %i SET status = 'failed', next_attempt_gmt = NULL, locked_at_gmt = NULL, last_error = %s WHERE status = 'sending' AND locked_at_gmt < %s AND attempt_count >= 3",
					$this->schema->notification_outbox_table(), 'SMS delivery outcome remained indeterminate after the retry limit.', $stale_before
				)
			);
			$this->db->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately below.
				$this->db->prepare(
					"UPDATE %i SET status = 'retry', attempt_count = attempt_count + 1, next_attempt_gmt = %s, locked_at_gmt = NULL, last_error = %s WHERE status = 'sending' AND locked_at_gmt < %s AND attempt_count < 3",
					$this->schema->notification_outbox_table(), $now, 'Previous SMS dispatch outcome is indeterminate; retry is bounded.', $stale_before
				)
			);
			$sql = $this->db->prepare(
				"SELECT * FROM %i WHERE status IN ('pending','retry') AND next_attempt_gmt <= %s ORDER BY id ASC LIMIT %d FOR UPDATE",
				$this->schema->notification_outbox_table(), $now, $limit
			);
			$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			$claimed = array();
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$updated = $this->db->update(
					$this->schema->notification_outbox_table(),
					array( 'status' => 'sending', 'locked_at_gmt' => $now ),
					array( 'id' => (int) $row['id'], 'status' => (string) $row['status'] ),
					array( '%s', '%s' ),
					array( '%d', '%s' )
				);
				if ( 1 === $updated ) {
					$claimed[] = $row;
				}
			}
			$this->db->query( 'COMMIT' );
			return $claimed;
		} catch ( Throwable ) {
			$this->db->query( 'ROLLBACK' );
			return array();
		}
	}

	public function markSent( int $id, string $provider_message_id = '' ): void {
		$this->db->update(
			$this->schema->notification_outbox_table(),
			array(
				'status'              => 'sent',
				'provider_message_id' => substr( sanitize_text_field( $provider_message_id ), 0, 191 ),
				'sent_at_gmt'         => current_time( 'mysql', true ),
				'last_error'          => null,
				'locked_at_gmt'       => null,
			),
			array( 'id' => $id, 'status' => 'sending' ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	public function markFailedAttempt( int $id, int $attempt_count, string $safe_error ): void {
		$delays = array( 60, 300, 1800 );
		$is_final = $attempt_count >= count( $delays );
		$next = $is_final ? null : gmdate( 'Y-m-d H:i:s', time() + $delays[ $attempt_count ] );
		$this->db->update(
			$this->schema->notification_outbox_table(),
			array(
				'status'           => $is_final ? 'failed' : 'retry',
				'attempt_count'    => $attempt_count + 1,
				'next_attempt_gmt' => $next,
				'last_error'       => substr( sanitize_text_field( $safe_error ), 0, 1000 ),
				'locked_at_gmt'    => null,
			),
			array( 'id' => $id, 'status' => 'sending' ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	public function markCancelled( int $id, string $safe_reason ): void {
		$this->db->update(
			$this->schema->notification_outbox_table(),
			array( 'status' => 'cancelled', 'next_attempt_gmt' => null, 'last_error' => substr( sanitize_text_field( $safe_reason ), 0, 1000 ), 'locked_at_gmt' => null ),
			array( 'id' => $id, 'status' => 'sending' ), array( '%s', '%s', '%s', '%s' ), array( '%d', '%s' )
		);
	}

	public function failedCount(): int {
		$sql = $this->db->prepare(
			"SELECT COUNT(*) FROM %i WHERE status = 'failed'",
			$this->schema->notification_outbox_table()
		);
		return max( 0, (int) $this->db->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}
}
