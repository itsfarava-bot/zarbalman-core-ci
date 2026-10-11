<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use RuntimeException;
use Throwable;
use WP_Error;
use Zarbalman\Core\Domain\Workflow\Actor;
use Zarbalman\Core\Domain\Workflow\TransitionRequest;
use Zarbalman\Core\Domain\Workflow\WorkflowDefinition;
use Zarbalman\Core\Security\IdempotencyKey;
use wpdb;

final class WorkflowStore {
	private Schema $schema;

	public function __construct(
		private readonly wpdb $db,
		private readonly AuditLogRepository $audit
	) {
		$this->schema = new Schema( $db );
	}

	public function current_state( int $order_id ): string {
		$sql = $this->db->prepare(
			'SELECT current_state FROM %i WHERE order_id = %d',
			$this->schema->workflows_table(),
			$order_id
		);
		$state = $this->db->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

		return is_string( $state ) && '' !== $state ? $state : WorkflowDefinition::CREATED;
	}

	/**
	 * @return array{
	 *   order_id:int,state:string,version:int,is_paused:bool,pause_reason:string,
	 *   paused_at_gmt:?string,delivered_at_gmt:?string,buyer_deadline_gmt:?string,
	 *   vendor_deadline_gmt:?string,weights:list<array<string,int|string|null>>
	 * }
	 */
	public function snapshot( int $order_id ): array {
		$sql = $this->db->prepare(
			'SELECT order_id, current_state, version, is_paused, pause_reason, paused_at_gmt,
				delivered_at_gmt, buyer_deadline_gmt, vendor_deadline_gmt
			FROM %i WHERE order_id = %d',
			$this->schema->workflows_table(),
			$order_id
		);
		$row = $this->db->get_row( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

		$weights_sql = $this->db->prepare(
			'SELECT order_item_id, vendor_weight_tenths, buyer_weight_tenths, final_weight_tenths,
				vendor_recorded_at_gmt, buyer_recorded_at_gmt, finalized_at_gmt
			FROM %i WHERE order_id = %d ORDER BY order_item_id ASC',
			$this->schema->weights_table(),
			$order_id
		);
		$weight_rows = $this->db->get_results( $weights_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		$weights     = array();
		foreach ( is_array( $weight_rows ) ? $weight_rows : array() as $weight ) {
			$weights[] = array(
				'order_item_id'       => (int) $weight['order_item_id'],
				'vendor_weight_tenths' => null === $weight['vendor_weight_tenths'] ? null : (int) $weight['vendor_weight_tenths'],
				'buyer_weight_tenths' => null === $weight['buyer_weight_tenths'] ? null : (int) $weight['buyer_weight_tenths'],
				'final_weight_tenths' => null === $weight['final_weight_tenths'] ? null : (int) $weight['final_weight_tenths'],
				'vendor_recorded_at_gmt' => $weight['vendor_recorded_at_gmt'],
				'buyer_recorded_at_gmt' => $weight['buyer_recorded_at_gmt'],
				'finalized_at_gmt'      => $weight['finalized_at_gmt'],
			);
		}

		return array(
			'order_id'            => $order_id,
			'state'               => $row ? (string) $row->current_state : WorkflowDefinition::CREATED,
			'version'             => $row ? (int) $row->version : 0,
			'is_paused'           => $row ? 1 === (int) $row->is_paused : false,
			'pause_reason'        => $row ? (string) ( $row->pause_reason ?? '' ) : '',
			'paused_at_gmt'       => $row && $row->paused_at_gmt ? (string) $row->paused_at_gmt : null,
			'delivered_at_gmt'    => $row && $row->delivered_at_gmt ? (string) $row->delivered_at_gmt : null,
			'buyer_deadline_gmt'  => $row && $row->buyer_deadline_gmt ? (string) $row->buyer_deadline_gmt : null,
			'vendor_deadline_gmt' => $row && $row->vendor_deadline_gmt ? (string) $row->vendor_deadline_gmt : null,
			'weights'             => $weights,
		);
	}

	/** @return list<array{order_id:int,state:string,deadline_type:string,deadline_gmt:string}> */
	public function due_deadlines( string $now_gmt, int $limit = 100 ): array {
		$limit = max( 1, min( 500, $limit ) );
		$sql   = $this->db->prepare(
			'SELECT order_id, current_state, buyer_deadline_gmt, vendor_deadline_gmt
			FROM %i
			WHERE is_paused = 0
			AND ((buyer_deadline_gmt IS NOT NULL AND buyer_deadline_gmt <= %s)
				OR (vendor_deadline_gmt IS NOT NULL AND vendor_deadline_gmt <= %s))
			ORDER BY COALESCE(buyer_deadline_gmt, vendor_deadline_gmt) ASC LIMIT %d',
			$this->schema->workflows_table(),
			$now_gmt,
			$now_gmt,
			$limit
		);
		$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! empty( $row['buyer_deadline_gmt'] ) && $row['buyer_deadline_gmt'] <= $now_gmt ) {
				$result[] = array(
					'order_id' => (int) $row['order_id'], 'state' => (string) $row['current_state'],
					'deadline_type' => 'buyer_review', 'deadline_gmt' => (string) $row['buyer_deadline_gmt'],
				);
			} elseif ( ! empty( $row['vendor_deadline_gmt'] ) && $row['vendor_deadline_gmt'] <= $now_gmt ) {
				$result[] = array(
					'order_id' => (int) $row['order_id'], 'state' => (string) $row['current_state'],
					'deadline_type' => 'vendor_weight', 'deadline_gmt' => (string) $row['vendor_deadline_gmt'],
				);
			}
		}
		return $result;
	}

	/**
	 * Returns a completed idempotent result before transition validation. This
	 * makes a repeated callback return the original success even after the
	 * workflow has already advanced.
	 *
	 * @return array{duplicate:bool,event_uuid:string,from_state:string,to_state:string,version:int}|WP_Error|null
	 */
	public function replay( TransitionRequest $request ): array|WP_Error|null {
		$key_hash     = IdempotencyKey::hash( 'workflow', 'order', $request->order_id, $request->idempotency_key );
		$payload_hash = $this->payload_hash( $request );
		$sql          = $this->db->prepare(
			'SELECT payload_hash, status, result_event_uuid, expires_at_gmt FROM %i WHERE key_hash = %s',
			$this->schema->idempotency_table(),
			$key_hash
		);
		$row = $this->db->get_row( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

		if ( ! $row ) {
			return null;
		}

		if ( ! hash_equals( (string) $row->payload_hash, $payload_hash ) ) {
			return new WP_Error(
				'zbm_idempotency_conflict',
				__( 'این کلید عملیات قبلاً برای درخواست دیگری استفاده شده است.', 'zarbalman-core' )
			);
		}

		if ( 'completed' === $row->status && is_string( $row->result_event_uuid ) ) {
			$audit_sql = $this->db->prepare(
				'SELECT from_state, to_state FROM %i WHERE event_uuid = %s',
				$this->schema->audit_table(),
				$row->result_event_uuid
			);
			$event = $this->db->get_row( $audit_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
			$version_sql = $this->db->prepare(
				'SELECT version FROM %i WHERE order_id = %d',
				$this->schema->workflows_table(),
				$request->order_id
			);

			return array(
				'duplicate'  => true,
				'event_uuid' => $row->result_event_uuid,
				'from_state' => $event ? (string) $event->from_state : '',
				'to_state'   => $event ? (string) $event->to_state : $request->to_state,
				'version'    => (int) $this->db->get_var( $version_sql ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
			);
		}

		if ( strtotime( (string) $row->expires_at_gmt . ' UTC' ) >= time() ) {
			return new WP_Error( 'zbm_operation_in_progress', __( 'این عملیات در حال پردازش است.', 'zarbalman-core' ) );
		}

		return null;
	}

	/**
	 * Returns a completed result by operation identity only. Preflight uses this
	 * before evidence is read, because evidence hashes do not exist until after
	 * the encrypted upload has completed. A reused completed idempotency key
	 * always means “return the first result”; stale in-progress keys continue to
	 * the full payload-hash validation in apply().
	 *
	 * @return array{duplicate:bool,event_uuid:string,from_state:string,to_state:string,version:int}|WP_Error|null
	 */
	public function replayIdentity( TransitionRequest $request ): array|WP_Error|null {
		$key_hash = IdempotencyKey::hash( 'workflow', 'order', $request->order_id, $request->idempotency_key );
		$sql = $this->db->prepare(
			'SELECT status, result_event_uuid, expires_at_gmt FROM %i WHERE key_hash = %s',
			$this->schema->idempotency_table(),
			$key_hash
		);
		$row = $this->db->get_row( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		if ( ! $row ) {
			return null;
		}
		if ( 'completed' === $row->status && is_string( $row->result_event_uuid ) ) {
			$audit_sql = $this->db->prepare(
				'SELECT from_state, to_state FROM %i WHERE event_uuid = %s',
				$this->schema->audit_table(),
				$row->result_event_uuid
			);
			$event = $this->db->get_row( $audit_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			$version_sql = $this->db->prepare(
				'SELECT version FROM %i WHERE order_id = %d',
				$this->schema->workflows_table(),
				$request->order_id
			);
			return array(
				'duplicate'  => true,
				'event_uuid' => $row->result_event_uuid,
				'from_state' => $event ? (string) $event->from_state : '',
				'to_state'   => $event ? (string) $event->to_state : $request->to_state,
				'version'    => (int) $this->db->get_var( $version_sql ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			);
		}
		if ( strtotime( (string) $row->expires_at_gmt . ' UTC' ) >= time() ) {
			return new WP_Error( 'zbm_operation_in_progress', __( 'این عملیات در حال پردازش است.', 'zarbalman-core' ) );
		}
		return null;
	}

	/**
	 * Applies a domain-state transition atomically across workflow, idempotency,
	 * and audit tables. It does not alter the WooCommerce order status.
	 *
	 * @return array{duplicate:bool,event_uuid:string,from_state:string,to_state:string,version:int}|WP_Error
	 */
	public function apply( TransitionRequest $request, string $expected_from ): array|WP_Error {
		$key_hash     = IdempotencyKey::hash( 'workflow', 'order', $request->order_id, $request->idempotency_key );
		$payload_hash = $this->payload_hash( $request );
		$now          = current_time( 'mysql', true );
		$expires      = gmdate( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS );

		$this->db->query( 'START TRANSACTION' );

		try {
			$idempotency = $this->lock_idempotency( $key_hash, $payload_hash, $now, $expires );
			if ( is_wp_error( $idempotency ) ) {
				$this->db->query( 'ROLLBACK' );
				return $idempotency;
			}

			if ( isset( $idempotency['duplicate'] ) && true === $idempotency['duplicate'] ) {
				$event_uuid = (string) $idempotency['event_uuid'];
				$audit_sql  = $this->db->prepare(
					'SELECT from_state, to_state FROM %i WHERE event_uuid = %s',
					$this->schema->audit_table(),
					$event_uuid
				);
				$event       = $this->db->get_row( $audit_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
				$version_sql = $this->db->prepare(
					'SELECT version FROM %i WHERE order_id = %d',
					$this->schema->workflows_table(),
					$request->order_id
				);
				$this->db->query( 'COMMIT' );
				return array(
					'duplicate'  => true,
					'event_uuid' => $event_uuid,
					'from_state' => $event ? (string) $event->from_state : $expected_from,
					'to_state'   => $event ? (string) $event->to_state : $request->to_state,
					'version'    => (int) $this->db->get_var( $version_sql ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
				);
			}

			$current = $this->lock_workflow( $request->order_id, $now );
			if ( $expected_from !== $current['state'] ) {
				$this->db->query( 'ROLLBACK' );
				return new WP_Error(
					'zbm_stale_transition',
					__( 'وضعیت سفارش هم‌زمان تغییر کرده است؛ صفحه را تازه کنید.', 'zarbalman-core' )
				);
			}

			if ( $current['is_paused'] ) {
				$this->db->query( 'ROLLBACK' );
				return new WP_Error( 'zbm_workflow_paused', __( 'این سفارش توسط مدیریت متوقف شده است.', 'zarbalman-core' ) );
			}

			$deadline_error = $this->locked_deadline_error( $request, $current, $now );
			if ( is_wp_error( $deadline_error ) ) {
				$this->db->query( 'ROLLBACK' );
				return $deadline_error;
			}

			$new_version = $current['version'] + 1;
			$effects     = $this->operational_effects( $request, $now );
			$update_data = array_merge(
				array(
					'current_state' => sanitize_key( $request->to_state ),
					'version'       => $new_version,
					'updated_at_gmt' => $now,
				),
				$effects['workflow']
			);
			$update_formats = array();
			foreach ( $update_data as $column => $value ) {
				$update_formats[] = in_array( $column, array( 'version', 'is_paused' ), true ) ? '%d' : '%s';
			}
			$updated     = $this->db->update(
				$this->schema->workflows_table(),
				$update_data,
				array(
					'order_id' => $request->order_id,
					'version'  => $current['version'],
				),
				$update_formats,
				array( '%d', '%d' )
			);

			if ( 1 !== $updated ) {
				throw new RuntimeException( 'Optimistic workflow update failed.' );
			}

			$this->persist_weight_effects( $request, $effects, $now );

			$event_uuid = $this->audit->append(
				$request->order_id,
				'workflow_transitioned',
				$expected_from,
				$request->to_state,
				$request->actor_type,
				$request->actor_user_id,
				$request->correlation_id,
				array_merge( $request->metadata, $effects['audit'] )
			);

			if ( false === $event_uuid ) {
				throw new RuntimeException( 'Audit event insert failed.' );
			}

			$completed = $this->db->update(
				$this->schema->idempotency_table(),
				array(
					'status'            => 'completed',
					'result_event_uuid' => $event_uuid,
					'updated_at_gmt'    => $now,
				),
				array( 'key_hash' => $key_hash ),
				array( '%s', '%s', '%s' ),
				array( '%s' )
			);

			if ( 1 !== $completed ) {
				throw new RuntimeException( 'Idempotency completion failed.' );
			}

			$this->db->query( 'COMMIT' );

			return array(
				'duplicate'  => false,
				'event_uuid' => $event_uuid,
				'from_state' => $expected_from,
				'to_state'   => $request->to_state,
				'version'    => $new_version,
			);
		} catch ( Throwable ) {
			$this->db->query( 'ROLLBACK' );
			return new WP_Error(
				'zbm_transition_storage_failed',
				__( 'ثبت امن تغییر وضعیت انجام نشد. هیچ تغییری ذخیره نشد.', 'zarbalman-core' )
			);
		}
	}

	/** @return array{duplicate:bool,is_paused:bool,version:int,event_uuid?:string}|WP_Error */
	public function set_paused( int $order_id, bool $paused, int $actor_user_id, string $reason, string $correlation_id ): array|WP_Error {
		$now = current_time( 'mysql', true );
		$this->db->query( 'START TRANSACTION' );

		try {
			$current = $this->lock_workflow( $order_id, $now );
			if ( in_array( $current['state'], array( WorkflowDefinition::COMPLETED, WorkflowDefinition::CANCELLED ), true ) ) {
				$this->db->query( 'ROLLBACK' );
				return new WP_Error( 'zbm_terminal_workflow', __( 'سفارش نهایی‌شده قابل توقف یا بازگشایی نیست.', 'zarbalman-core' ) );
			}
			if ( $current['is_paused'] === $paused ) {
				$this->db->query( 'COMMIT' );
				return array( 'duplicate' => true, 'is_paused' => $paused, 'version' => $current['version'] );
			}

			$new_version = $current['version'] + 1;
			$update_data = array(
				'is_paused'     => $paused ? 1 : 0,
				'pause_reason'  => $paused ? $reason : null,
				'paused_at_gmt' => $paused ? $now : null,
				'version'       => $new_version,
				'updated_at_gmt' => $now,
			);
			if ( ! $paused && is_string( $current['paused_at_gmt'] ) ) {
				$paused_at = strtotime( $current['paused_at_gmt'] . ' UTC' );
				$now_ts    = strtotime( $now . ' UTC' );
				$shift     = false !== $paused_at && false !== $now_ts ? max( 0, $now_ts - $paused_at ) : 0;
				foreach ( array( 'buyer_deadline_gmt', 'vendor_deadline_gmt' ) as $deadline_key ) {
					if ( $shift > 0 && is_string( $current[ $deadline_key ] ) ) {
						$deadline_ts = strtotime( $current[ $deadline_key ] . ' UTC' );
						if ( false !== $deadline_ts ) {
							$update_data[ $deadline_key ] = gmdate( 'Y-m-d H:i:s', $deadline_ts + $shift );
						}
					}
				}
			}
			$formats = array();
			foreach ( $update_data as $column => $value ) {
				$formats[] = in_array( $column, array( 'is_paused', 'version' ), true ) ? '%d' : '%s';
			}
			$updated = $this->db->update(
				$this->schema->workflows_table(),
				$update_data,
				array( 'order_id' => $order_id, 'version' => $current['version'] ),
				$formats,
				array( '%d', '%d' )
			);
			if ( 1 !== $updated ) {
				throw new RuntimeException( 'Workflow pause update failed.' );
			}

			$event_uuid = $this->audit->append(
				$order_id,
				$paused ? 'workflow_paused' : 'workflow_resumed',
				$current['state'],
				$current['state'],
				'admin',
				$actor_user_id,
				$correlation_id,
				array( 'reason' => $reason, 'version' => $new_version )
			);
			if ( false === $event_uuid ) {
				throw new RuntimeException( 'Pause audit insert failed.' );
			}

			$this->db->query( 'COMMIT' );
			return array(
				'duplicate' => false, 'is_paused' => $paused, 'version' => $new_version, 'event_uuid' => $event_uuid,
			);
		} catch ( Throwable ) {
			$this->db->query( 'ROLLBACK' );
			return new WP_Error( 'zbm_pause_storage_failed', __( 'تغییر توقف سفارش ذخیره نشد.', 'zarbalman-core' ) );
		}
	}

	/** @return array{state:string,version:int,is_paused:bool,paused_at_gmt:?string,buyer_deadline_gmt:?string,vendor_deadline_gmt:?string} */
	private function lock_workflow( int $order_id, string $now ): array {
		$sql = $this->db->prepare(
			'SELECT current_state, version, is_paused, paused_at_gmt, buyer_deadline_gmt, vendor_deadline_gmt
			FROM %i WHERE order_id = %d FOR UPDATE',
			$this->schema->workflows_table(),
			$order_id
		);
		$row = $this->db->get_row( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

		if ( ! $row ) {
			$inserted = $this->db->insert(
				$this->schema->workflows_table(),
				array(
					'order_id'       => $order_id,
					'current_state'  => WorkflowDefinition::CREATED,
					'version'        => 0,
					'created_at_gmt' => $now,
					'updated_at_gmt' => $now,
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);

			if ( false === $inserted ) {
				throw new RuntimeException( 'Workflow initialization failed.' );
			}

			return array(
				'state' => WorkflowDefinition::CREATED, 'version' => 0, 'is_paused' => false,
				'paused_at_gmt' => null, 'buyer_deadline_gmt' => null, 'vendor_deadline_gmt' => null,
			);
		}

		return array(
			'state' => (string) $row->current_state,
			'version' => (int) $row->version,
			'is_paused' => 1 === (int) $row->is_paused,
			'paused_at_gmt' => $row->paused_at_gmt ? (string) $row->paused_at_gmt : null,
			'buyer_deadline_gmt' => $row->buyer_deadline_gmt ? (string) $row->buyer_deadline_gmt : null,
			'vendor_deadline_gmt' => $row->vendor_deadline_gmt ? (string) $row->vendor_deadline_gmt : null,
		);
	}

	/**
	 * Rechecks actor deadlines while holding the workflow row lock. The service
	 * performs the same check for fast feedback; this one closes the race between
	 * that read and the atomic update.
	 *
	 * @param array{state:string,version:int,is_paused:bool,paused_at_gmt:?string,buyer_deadline_gmt:?string,vendor_deadline_gmt:?string} $current
	 */
	private function locked_deadline_error( TransitionRequest $request, array $current, string $now ): bool|WP_Error {
		$deadline = null;
		$error    = null;
		if ( Actor::BUYER === $request->actor_type && WorkflowDefinition::DELIVERED === $current['state'] ) {
			$deadline = $current['buyer_deadline_gmt'];
			$error    = new WP_Error( 'zbm_buyer_deadline_expired', __( 'مهلت دو ساعته بررسی پایان یافته و سفارش برای مدیریت متوقف شده است.', 'zarbalman-core' ) );
		} elseif ( Actor::VENDOR === $request->actor_type && WorkflowDefinition::BUYER_WEIGHT_RECORDED === $current['state'] ) {
			$deadline = $current['vendor_deadline_gmt'];
			$error    = new WP_Error( 'zbm_vendor_deadline_expired', __( 'مهلت پاسخ به وزن خریدار پایان یافته و سفارش برای مدیریت متوقف شده است.', 'zarbalman-core' ) );
		}

		if ( null === $error ) {
			return true;
		}

		$deadline_ts = is_string( $deadline ) ? strtotime( $deadline . ' UTC' ) : false;
		$now_ts      = strtotime( $now . ' UTC' );
		return false !== $deadline_ts && false !== $now_ts && $deadline_ts >= $now_ts ? true : $error;
	}

	/** @return array{duplicate:bool,event_uuid?:string}|WP_Error */
	private function lock_idempotency( string $key_hash, string $payload_hash, string $now, string $expires ): array|WP_Error {
		$sql = $this->db->prepare(
			'SELECT payload_hash, status, result_event_uuid, expires_at_gmt FROM %i WHERE key_hash = %s FOR UPDATE',
			$this->schema->idempotency_table(),
			$key_hash
		);
		$row = $this->db->get_row( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

		if ( $row ) {
			if ( ! hash_equals( (string) $row->payload_hash, $payload_hash ) ) {
				return new WP_Error(
					'zbm_idempotency_conflict',
					__( 'این کلید عملیات قبلاً برای درخواست دیگری استفاده شده است.', 'zarbalman-core' )
				);
			}

			if ( 'completed' === $row->status && is_string( $row->result_event_uuid ) ) {
				return array( 'duplicate' => true, 'event_uuid' => $row->result_event_uuid );
			}

			if ( strtotime( (string) $row->expires_at_gmt . ' UTC' ) >= time() ) {
				return new WP_Error(
					'zbm_operation_in_progress',
					__( 'این عملیات در حال پردازش است.', 'zarbalman-core' )
				);
			}

			$this->db->update(
				$this->schema->idempotency_table(),
				array(
					'status'            => 'processing',
					'result_event_uuid' => null,
					'expires_at_gmt'    => $expires,
					'updated_at_gmt'    => $now,
				),
				array( 'key_hash' => $key_hash ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%s' )
			);

			return array( 'duplicate' => false );
		}

		$inserted = $this->db->insert(
			$this->schema->idempotency_table(),
			array(
				'key_hash'       => $key_hash,
				'namespace'      => 'workflow',
				'payload_hash'   => $payload_hash,
				'status'         => 'processing',
				'expires_at_gmt' => $expires,
				'created_at_gmt' => $now,
				'updated_at_gmt' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			throw new RuntimeException( 'Idempotency reservation failed.' );
		}

		return array( 'duplicate' => false );
	}

	/**
	 * @return array{
	 *   workflow:array<string,int|string|null>,audit:array<string,mixed>,
	 *   vendor_weights:array<int,int>,buyer_weights:array<int,int>,final_weights:array<int,int>,finalize_buyer_weights:bool
	 * }
	 */
	private function operational_effects( TransitionRequest $request, string $now ): array {
		$workflow              = array();
		$audit                 = array();
		$vendor_weights        = array();
		$buyer_weights         = array();
		$final_weights         = array();
		$finalize_buyer_weights = false;

		if ( WorkflowDefinition::IN_TRANSIT === $request->to_state ) {
			$vendor_weights = is_array( $request->metadata['weights_tenths'] ?? null )
				? $request->metadata['weights_tenths']
				: array();
		}

		if ( WorkflowDefinition::DELIVERED === $request->to_state ) {
			$seconds  = (int) apply_filters( 'zbm_core_buyer_review_window_seconds', 2 * HOUR_IN_SECONDS, $request->order_id );
			$seconds  = max( 15 * MINUTE_IN_SECONDS, min( DAY_IN_SECONDS, $seconds ) );
			$deadline = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) + $seconds );
			$workflow = array(
				'delivered_at_gmt'    => $now,
				'buyer_deadline_gmt'  => $deadline,
				'vendor_deadline_gmt' => null,
			);
			$audit['buyer_deadline_gmt'] = $deadline;
		}

		if ( WorkflowDefinition::BUYER_WEIGHT_RECORDED === $request->to_state ) {
			$seconds  = (int) apply_filters( 'zbm_core_vendor_weight_window_seconds', 30 * MINUTE_IN_SECONDS, $request->order_id );
			$seconds  = max( 5 * MINUTE_IN_SECONDS, min( 4 * HOUR_IN_SECONDS, $seconds ) );
			$deadline = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) + $seconds );
			$workflow = array( 'buyer_deadline_gmt' => null, 'vendor_deadline_gmt' => $deadline );
			$buyer_weights = is_array( $request->metadata['weights_tenths'] ?? null )
				? $request->metadata['weights_tenths']
				: array();
			$audit['vendor_deadline_gmt'] = $deadline;
		}

		if ( WorkflowDefinition::SELLER_WEIGHT_CONFIRMED === $request->to_state ) {
			$workflow = array( 'buyer_deadline_gmt' => null, 'vendor_deadline_gmt' => null );
			if ( is_array( $request->metadata['weights_tenths'] ?? null ) ) {
				$final_weights = $request->metadata['weights_tenths'];
			} else {
				$finalize_buyer_weights = true;
			}
		}

		if (
			in_array(
				$request->to_state,
				array(
					WorkflowDefinition::WEIGHT_DISPUTED,
					WorkflowDefinition::DISPUTE_OPEN,
					WorkflowDefinition::ADMIN_REVIEW,
					WorkflowDefinition::CANCELLATION_REQUESTED,
					WorkflowDefinition::RETURN_COORDINATION,
					WorkflowDefinition::CANCELLED,
					WorkflowDefinition::BALANCE_PENDING,
				),
				true
			)
		) {
			$workflow = array_merge( $workflow, array( 'buyer_deadline_gmt' => null, 'vendor_deadline_gmt' => null ) );
		}

		return array(
			'workflow'               => $workflow,
			'audit'                  => $audit,
			'vendor_weights'         => $vendor_weights,
			'buyer_weights'          => $buyer_weights,
			'final_weights'          => $final_weights,
			'finalize_buyer_weights' => $finalize_buyer_weights,
		);
	}

	/** @param array<string,mixed> $effects */
	private function persist_weight_effects( TransitionRequest $request, array $effects, string $now ): void {
		foreach ( $effects['vendor_weights'] as $item_id => $tenths ) {
			$this->upsert_weight( $request->order_id, (int) $item_id, (int) $tenths, null, null, $now );
		}
		foreach ( $effects['buyer_weights'] as $item_id => $tenths ) {
			$this->upsert_weight( $request->order_id, (int) $item_id, null, (int) $tenths, null, $now );
		}

		foreach ( $effects['final_weights'] as $item_id => $tenths ) {
			$this->upsert_weight( $request->order_id, (int) $item_id, null, null, (int) $tenths, $now );
		}

		if ( ! empty( $effects['finalize_buyer_weights'] ) ) {
			$sql = $this->db->prepare(
				'UPDATE %i SET final_weight_tenths = buyer_weight_tenths, finalized_at_gmt = %s, updated_at_gmt = %s
				WHERE order_id = %d AND buyer_weight_tenths IS NOT NULL',
				$this->schema->weights_table(),
				$now,
				$now,
				$request->order_id
			);
			$updated = $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
			if ( false === $updated || 0 === $updated ) {
				throw new RuntimeException( 'No buyer weights were available to finalize.' );
			}
		}
	}

	private function upsert_weight( int $order_id, int $item_id, ?int $vendor_tenths, ?int $buyer_tenths, ?int $final_tenths, string $now ): void {
		if ( null !== $vendor_tenths ) {
			$sql = $this->db->prepare(
				'INSERT INTO %i
				(order_id, order_item_id, vendor_weight_tenths, vendor_recorded_at_gmt, updated_at_gmt)
				VALUES (%d, %d, %d, %s, %s)
				ON DUPLICATE KEY UPDATE vendor_weight_tenths = VALUES(vendor_weight_tenths),
				vendor_recorded_at_gmt = VALUES(vendor_recorded_at_gmt), updated_at_gmt = VALUES(updated_at_gmt)',
				$this->schema->weights_table(), $order_id, $item_id, $vendor_tenths, $now, $now
			);
		} elseif ( null !== $buyer_tenths ) {
			$sql = $this->db->prepare(
				'INSERT INTO %i
				(order_id, order_item_id, buyer_weight_tenths, buyer_recorded_at_gmt, updated_at_gmt)
				VALUES (%d, %d, %d, %s, %s)
				ON DUPLICATE KEY UPDATE buyer_weight_tenths = VALUES(buyer_weight_tenths),
				buyer_recorded_at_gmt = VALUES(buyer_recorded_at_gmt), updated_at_gmt = VALUES(updated_at_gmt)',
				$this->schema->weights_table(),
				$order_id,
				$item_id,
				$buyer_tenths,
				$now,
				$now
			);
		} elseif ( null !== $final_tenths ) {
			$sql = $this->db->prepare(
				'INSERT INTO %i
				(order_id, order_item_id, final_weight_tenths, finalized_at_gmt, updated_at_gmt)
				VALUES (%d, %d, %d, %s, %s)
				ON DUPLICATE KEY UPDATE final_weight_tenths = VALUES(final_weight_tenths),
				finalized_at_gmt = VALUES(finalized_at_gmt), updated_at_gmt = VALUES(updated_at_gmt)',
				$this->schema->weights_table(),
				$order_id,
				$item_id,
				$final_tenths,
				$now,
				$now
			);
		} else {
			throw new RuntimeException( 'Weight upsert received no value.' );
		}
		$result = $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		if ( false === $result ) {
			throw new RuntimeException( 'Weight upsert failed.' );
		}
	}

	private function payload_hash( TransitionRequest $request ): string {
		$metadata = $request->metadata;
		unset( $metadata['evidence_attachment_ids'] );
		$metadata = $this->canonicalize( $metadata );
		$json = wp_json_encode(
			array(
				'order_id'   => $request->order_id,
				'to_state'   => $request->to_state,
				'actor_type' => $request->actor_type,
				'actor_id'   => $request->actor_user_id,
				'metadata'   => $metadata,
			)
		);

		return hash( 'sha256', is_string( $json ) ? $json : '' );
	}

	private function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( array_is_list( $value ) ) {
			return array_map( array( $this, 'canonicalize' ), $value );
		}

		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->canonicalize( $item );
		}
		return $value;
	}
}
