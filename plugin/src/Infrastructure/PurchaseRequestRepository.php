<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use RuntimeException;
use Throwable;
use WP_Error;
use Zarbalman\Core\Application\PurchaseRequestInput;
use Zarbalman\Core\Domain\ManagedFlow\FlowState;
use Zarbalman\Core\Domain\PurchaseRequest\ExecutionMode;
use Zarbalman\Core\Domain\PurchaseRequest\PricingSnapshot;
use Zarbalman\Core\Domain\PurchaseRequest\RequestPricingPolicy;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Domain\PurchaseRequest\RequestNumber;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;
use Zarbalman\Core\Security\IdempotencyKey;
use wpdb;

final class PurchaseRequestRepository {
	private Schema $schema;

	public function __construct(
		private readonly wpdb $db,
		private readonly AuditLogRepository $audit,
		private readonly NotificationOutboxRepository $outbox
	) {
		$this->schema = new Schema( $db );
	}

	public function find( int $request_id ): ?PurchaseRequest {
		$sql = $this->db->prepare( 'SELECT * FROM %i WHERE id = %d', $this->schema->purchase_requests_table(), $request_id );
		$row = $this->db->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		return is_array( $row ) ? PurchaseRequest::fromRow( $row ) : null;
	}

	public function findByOrderId( int $order_id ): ?PurchaseRequest {
		$sql = $this->db->prepare( 'SELECT * FROM %i WHERE wc_order_id = %d', $this->schema->purchase_requests_table(), $order_id );
		$row = $this->db->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		return is_array( $row ) ? PurchaseRequest::fromRow( $row ) : null;
	}

	/** @return list<PurchaseRequest> */
	public function forBuyer( int $buyer_id, int $limit = 50 ): array {
		return $this->forOwner( 'buyer_id', $buyer_id, $limit );
	}

	/** @return list<PurchaseRequest> */
	public function forVendor( int $vendor_id, int $limit = 50 ): array {
		return $this->forOwner( 'vendor_id', $vendor_id, $limit );
	}

	public function findForBuyerDashboard( int $request_id, int $buyer_id ): ?PurchaseRequest {
		return $this->findForOwnerDashboard( 'buyer_id', $request_id, $buyer_id );
	}

	public function findForVendorDashboard( int $request_id, int $vendor_id ): ?PurchaseRequest {
		return $this->findForOwnerDashboard( 'vendor_id', $request_id, $vendor_id );
	}

	/** @return list<PurchaseRequest> */
	public function recentForAdmin( int $limit = 100, ?string $request_number = null ): array {
		$limit = max( 1, min( 200, $limit ) );
		$request_number = RequestNumber::isValid( $request_number ) ? $request_number : null;
		$sql = null === $request_number
			? $this->db->prepare(
				'SELECT * FROM %i ORDER BY id DESC LIMIT %d',
				$this->schema->purchase_requests_table(),
				$limit
			)
			: $this->db->prepare(
				'SELECT * FROM %i WHERE request_number = %s ORDER BY id DESC LIMIT %d',
				$this->schema->purchase_requests_table(),
				$request_number,
				$limit
			);
		$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		return array_map( static fn ( array $row ): PurchaseRequest => PurchaseRequest::fromRow( $row ), is_array( $rows ) ? $rows : array() );
	}

	/** @return list<PurchaseRequest> */
	private function forOwner( string $column, int $owner_id, int $limit ): array {
		if ( ! in_array( $column, array( 'buyer_id', 'vendor_id' ), true ) || $owner_id < 1 ) {
			return array();
		}
		$limit = max( 1, min( 100, $limit ) );
		$cutoff = $this->ownerVisibilityCutoff();
		$sql = $this->db->prepare(
			"SELECT requests.* FROM %i requests WHERE requests.{$column} = %d {$this->ownerVisibilitySql()} ORDER BY requests.id DESC LIMIT %d",
			$this->schema->purchase_requests_table(),
			$owner_id,
			RequestState::SUPPLIER_REJECTED,
			$cutoff,
			$this->schema->managed_cases_table(),
			FlowState::COMPLETED,
			$cutoff,
			$limit
		);
		$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column is strict allowlist; table/value are prepared.
		return array_map( static fn ( array $row ): PurchaseRequest => PurchaseRequest::fromRow( $row ), is_array( $rows ) ? $rows : array() );
	}

	private function findForOwnerDashboard( string $column, int $request_id, int $owner_id ): ?PurchaseRequest {
		if ( ! in_array( $column, array( 'buyer_id', 'vendor_id' ), true ) || $request_id < 1 || $owner_id < 1 ) {
			return null;
		}
		$cutoff = $this->ownerVisibilityCutoff();
		$sql = $this->db->prepare(
			"SELECT requests.* FROM %i requests WHERE requests.id = %d AND requests.{$column} = %d {$this->ownerVisibilitySql()}",
			$this->schema->purchase_requests_table(),
			$request_id,
			$owner_id,
			RequestState::SUPPLIER_REJECTED,
			$cutoff,
			$this->schema->managed_cases_table(),
			FlowState::COMPLETED,
			$cutoff
		);
		$row = $this->db->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column is strict allowlist; values/table are prepared.
		return is_array( $row ) ? PurchaseRequest::fromRow( $row ) : null;
	}

	private function ownerVisibilityCutoff(): string {
		return gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
	}

	private function ownerVisibilitySql(): string {
		// FlowState::BALANCE_DUE is deliberately absent: an unresolved balance must remain visible.
		return "AND NOT (
			(requests.current_state = %s AND requests.needs_reconciliation = 0 AND requests.rejected_at_gmt IS NOT NULL AND requests.rejected_at_gmt <= %s)
			OR EXISTS (
				SELECT 1 FROM %i managed_cases
				WHERE managed_cases.request_id = requests.id
				  AND managed_cases.state = %s
				  AND managed_cases.sync_pending = 0
				  AND requests.needs_reconciliation = 0
				  AND JSON_UNQUOTE(JSON_EXTRACT(managed_cases.data_json, '$.final.settlement')) IN ('credited', 'not_required')
				  AND managed_cases.closed_at_gmt IS NOT NULL
				  AND managed_cases.closed_at_gmt <= %s
			)
		)";
	}

	public function create(
		PurchaseRequestInput $input,
		PricingSnapshot $pricing,
		int $buyer_id,
		string $encrypted_delivery,
		string $supplier_deadline_gmt,
		string $idempotency_key,
		string $correlation_id
	): PurchaseRequest|WP_Error {
		$schema_error = Migrator::pricingAvailabilityError();
		if ( null !== $schema_error ) {
			return $schema_error;
		}
		$key_hash = IdempotencyKey::hash( 'purchase_request_create', 'buyer', $buyer_id, $idempotency_key );
		$payload_hash = hash( 'sha256', (string) wp_json_encode( array(
			$input->productId(), $input->variationId(), $input->vendorId(), $input->quantity(),
			$input->deliveryMethod(), $input->deliveryWindow()->type(), $input->deliveryWindow()->startGmt(),
			$input->deliveryWindow()->endGmt(), $input->deliveryPayload(), array_values( $input->lines() ),
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		$now = current_time( 'mysql', true );

		$this->db->query( 'START TRANSACTION' );
		try {
			$replay = $this->lockIdempotency( $key_hash, 'purchase_request_create', $payload_hash, $now );
			if ( is_wp_error( $replay ) ) {
				$this->db->query( 'ROLLBACK' );
				return $replay;
			}
			if ( isset( $replay['resource_id'] ) ) {
				$this->db->query( 'COMMIT' );
				return $this->find( (int) $replay['resource_id'] ) ?: new WP_Error( 'zbm_request_replay_missing', __( 'نتیجه درخواست قبلی در دسترس نیست.', 'zarbalman-core' ) );
			}
			// Rebuild only a NEW request from trusted input; never reprice a replay.
			$expected = $input->pricingSnapshot();
			if ( $pricing != $expected ) {
				throw new RuntimeException( 'Request pricing differs from the trusted input snapshot.' );
			}
			$locked_buyer = $this->db->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately below; core users table is trusted.
				$this->db->prepare( "SELECT ID FROM {$this->db->users} WHERE ID = %d FOR UPDATE", $buyer_id )
			);
			if ( (int) $locked_buyer !== $buyer_id ) {
				throw new RuntimeException( 'Purchase request buyer is unavailable.' );
			}
			$recent_since = gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS );
			$recent_count = (int) $this->db->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately below.
				$this->db->prepare(
					'SELECT COUNT(*) FROM %i WHERE buyer_id = %d AND created_at_gmt >= %s',
					$this->schema->purchase_requests_table(), $buyer_id, $recent_since
				)
			);
			if ( $recent_count >= 10 ) {
				$this->db->query( 'ROLLBACK' );
				return new WP_Error( 'zbm_request_rate_limited', __( 'تعداد درخواست‌های شما در چند دقیقهٔ اخیر زیاد بوده است؛ کمی بعد دوباره تلاش کنید.', 'zarbalman-core' ) );
			}

			$request_data = array(
				'public_id'                     => wp_generate_uuid4(),
				'request_number'                => RequestNumber::generate(),
				'buyer_id'                      => $buyer_id,
				'vendor_id'                     => $input->vendorId(),
				'product_id'                    => $input->productId(),
				'variation_id'                  => $input->variationId(),
				'quantity'                      => $pricing->quantity(),
				'package_price_toman'           => $pricing->packagePriceToman(),
				'nominal_package_weight_tenths' => $pricing->nominalPackageWeightTenths(),
				'reference_weight_tenths'       => $pricing->referenceWeightTenths(),
				'estimated_total_toman'         => $pricing->estimatedTotalToman(),
				'pricing_version'               => RequestPricingPolicy::SHIPPING_INCLUSIVE,
				'estimated_shipping_toman'      => $pricing->estimatedShippingToman(),
				'shipping_rate_per_kg_toman'    => $pricing->shippingRatePerKgToman(),
				'deposit_toman'                 => $pricing->depositToman(),
				'delivery_method'               => $input->deliveryMethod(),
				'delivery_window_type'          => $input->deliveryWindow()->type(),
				'delivery_start_gmt'            => $input->deliveryWindow()->startGmt(),
				'delivery_end_gmt'              => $input->deliveryWindow()->endGmt(),
				'delivery_payload_encrypted'    => $encrypted_delivery,
				'current_state'                 => RequestState::SUPPLIER_REVIEW,
				'version'                       => 0,
				'supplier_deadline_gmt'          => $supplier_deadline_gmt,
				'execution_mode'                => ExecutionMode::LIVE,
				'created_at_gmt'                => $now,
				'updated_at_gmt'                => $now,
			);
			$insert_formats = array(
				'%s', '%s',
				'%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d',
				'%d', '%d', '%d',
				'%s', '%s', '%s', '%s', '%s', '%s',
				'%d',
				'%s', '%s', '%s', '%s',
			);
			$inserted = false;
			$request_data['lines_json'] = count( $input->lines() ) > 1 ? wp_json_encode( $input->lines(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) : null;
			$insert_formats[] = '%s';
			for ( $attempt = 0; $attempt < 25; $attempt++ ) {
				$inserted = $this->db->insert( $this->schema->purchase_requests_table(), $request_data, $insert_formats );
				if ( false !== $inserted ) {
					break;
				}
				// The unique key is the final concurrency guard. Retry only its
				// expected collision; all other storage errors fail closed.
				if ( ! str_contains( strtolower( (string) $this->db->last_error ), 'request_number' ) ) {
					break;
				}
				$request_data['request_number'] = RequestNumber::generate();
			}
			if ( false === $inserted ) {
				throw new RuntimeException( 'Purchase request insert failed.' );
			}
			$request_id = (int) $this->db->insert_id;
			$event_uuid = wp_generate_uuid4();
			if ( false === $this->audit->append_resource(
				'request', $request_id, null, 'purchase_request_created', null, RequestState::SUPPLIER_REVIEW,
				'buyer', $buyer_id, $correlation_id,
				array( 'product_id' => $input->productId(), 'quantity' => $pricing->quantity() ),
				$event_uuid
			) ) {
				throw new RuntimeException( 'Request audit insert failed.' );
			}
			$this->enqueuePair(
				$event_uuid,
				$request_id,
				$buyer_id,
				$input->vendorId(),
				'request_created_buyer',
				'request_created_vendor',
				array( 'deadline' => $supplier_deadline_gmt ),
				array( 'deadline' => $supplier_deadline_gmt, 'quantity' => $pricing->quantity() )
			);
			$this->completeIdempotency( $key_hash, $event_uuid, $request_id, $now );
			$this->db->query( 'COMMIT' );

			return $this->find( $request_id ) ?: throw new RuntimeException( 'Created request cannot be loaded.' );
		} catch ( Throwable ) {
			$this->db->query( 'ROLLBACK' );
			return new WP_Error( 'zbm_request_storage_failed', __( 'ثبت امن درخواست انجام نشد؛ هیچ تغییری ذخیره نشد.', 'zarbalman-core' ) );
		}
	}

	/** @param array<string,mixed> $fields */
	public function transition(
		int $request_id,
		string $expected_state,
		string $new_state,
		int $expected_version,
		string $actor_type,
		int $actor_user_id,
		string $idempotency_key,
		string $event_key,
		string $correlation_id,
		array $fields = array(),
		array $notifications = array()
	): PurchaseRequest|WP_Error {
		if ( ! in_array( $new_state, RequestState::all(), true ) ) {
			return new WP_Error( 'zbm_request_state_invalid', __( 'وضعیت درخواست معتبر نیست.', 'zarbalman-core' ) );
		}
		if (
			array_key_exists( 'execution_mode', $fields )
			&& ! in_array( (string) $fields['execution_mode'], ExecutionMode::all(), true )
		) {
			return new WP_Error( 'zbm_request_execution_mode_invalid', __( 'حالت اجرای درخواست معتبر نیست.', 'zarbalman-core' ) );
		}
		$key_hash = IdempotencyKey::hash( 'purchase_request_transition', 'request', $request_id, $idempotency_key );
		$payload_hash = hash( 'sha256', (string) wp_json_encode( array( $expected_state, $new_state, $expected_version, $actor_type, $actor_user_id, $fields ) ) );
		$now = current_time( 'mysql', true );
		$this->db->query( 'START TRANSACTION' );
		try {
			$replay = $this->lockIdempotency( $key_hash, 'purchase_request_transition', $payload_hash, $now );
			if ( is_wp_error( $replay ) ) {
				$this->db->query( 'ROLLBACK' );
				return $replay;
			}
			if ( isset( $replay['resource_id'] ) ) {
				$this->db->query( 'COMMIT' );
				return $this->find( $request_id ) ?: new WP_Error( 'zbm_request_replay_missing', __( 'نتیجه عملیات قبلی در دسترس نیست.', 'zarbalman-core' ) );
			}
			$current = $this->lockRequest( $request_id );
			if ( null === $current ) {
				$this->db->query( 'ROLLBACK' );
				return new WP_Error( 'zbm_request_not_found', __( 'درخواست در دسترس نیست.', 'zarbalman-core' ) );
			}
			if ( $current->state() !== $expected_state || $current->version() !== $expected_version ) {
				$this->db->query( 'ROLLBACK' );
				return new WP_Error( 'zbm_request_stale', __( 'درخواست هم‌زمان تغییر کرده است؛ صفحه را تازه کنید.', 'zarbalman-core' ) );
			}
			if ( array_key_exists( 'execution_mode', $fields ) ) {
				$target_mode = (string) $fields['execution_mode'];
				$valid_test_start = 'admin' === $actor_type
					&& RequestState::SUPPLIER_ACCEPTED === $expected_state
					&& RequestState::SUPPLIER_ACCEPTED === $new_state
					&& ExecutionMode::LIVE === $current->executionMode()
					&& ExecutionMode::TEST_PENDING === $target_mode;
				$valid_test_completion = 'admin' === $actor_type
					&& RequestState::SUPPLIER_ACCEPTED === $expected_state
					&& RequestState::DEPOSIT_PAID === $new_state
					&& ExecutionMode::TEST_PENDING === $current->executionMode()
					&& ExecutionMode::TEST === $target_mode;
				if ( ! $valid_test_start && ! $valid_test_completion ) {
					$this->db->query( 'ROLLBACK' );
					return new WP_Error( 'zbm_request_execution_transition_forbidden', __( 'گذار حالت اجرای درخواست مجاز نیست.', 'zarbalman-core' ) );
				}
			}
			$test_fields = array(
				'test_advance_reason', 'test_advanced_by',
				'test_advance_started_at_gmt', 'test_advanced_at_gmt',
			);
			$test_field_updates = array_intersect( $test_fields, array_keys( $fields ) );
			if ( $test_field_updates && ( 'admin' !== $actor_type || ! array_key_exists( 'execution_mode', $fields ) ) ) {
				$this->db->query( 'ROLLBACK' );
				return new WP_Error( 'zbm_request_test_fields_forbidden', __( 'ثبت دادهٔ آزمایشی فقط از مسیر کنترل‌شدهٔ مدیریت مجاز است.', 'zarbalman-core' ) );
			}
			if ( $current->isPaused() && ! in_array( $actor_type, array( 'admin', 'system' ), true ) ) {
				$this->db->query( 'ROLLBACK' );
				return new WP_Error( 'zbm_request_paused', __( 'این درخواست توسط مدیریت متوقف شده است.', 'zarbalman-core' ) );
			}
			if (
				RequestState::SUPPLIER_REVIEW === $expected_state
				&& 'vendor' === $actor_type
				&& null !== $current->supplierDeadlineGmt()
				&& $current->supplierDeadlineGmt() <= $now
			) {
				$this->db->query( 'ROLLBACK' );
				return new WP_Error( 'zbm_supplier_deadline_expired', __( 'مهلت پاسخ فروشنده پایان یافته است.', 'zarbalman-core' ) );
			}
			$allowed_fields = array(
				'wc_order_id', 'deposit_deadline_gmt', 'supplier_deadline_gmt', 'rejection_reason',
				'accepted_at_gmt', 'rejected_at_gmt', 'stock_reserved', 'stock_released',
				'payment_ready', 'needs_reconciliation', 'stock_operation',
				'execution_mode', 'test_advance_reason', 'test_advanced_by',
				'test_advance_started_at_gmt', 'test_advanced_at_gmt',
				'is_paused', 'pause_reason', 'paused_at_gmt',
				'paused_remaining_seconds',
			);
			$update = array( 'current_state' => $new_state, 'version' => $expected_version + 1, 'updated_at_gmt' => $now );
			foreach ( $fields as $key => $value ) {
				if ( in_array( $key, $allowed_fields, true ) ) {
					$update[ $key ] = $value;
				}
			}
			$formats = array_map(
				static fn ( string $key ): string => in_array( $key, array( 'version', 'wc_order_id', 'payment_ready', 'needs_reconciliation', 'stock_reserved', 'stock_released', 'test_advanced_by', 'is_paused', 'paused_remaining_seconds' ), true ) ? '%d' : '%s',
				array_keys( $update )
			);
			$updated = $this->db->update(
				$this->schema->purchase_requests_table(), $update,
				array( 'id' => $request_id, 'version' => $expected_version ),
				$formats, array( '%d', '%d' )
			);
			if ( 1 !== $updated ) {
				throw new RuntimeException( 'Request optimistic update failed.' );
			}
			$event_uuid = wp_generate_uuid4();
			$audit_metadata = array();
			if ( isset( $fields['rejection_reason'] ) ) {
				$audit_metadata['reason'] = (string) $fields['rejection_reason'];
			} elseif ( isset( $fields['test_advance_reason'] ) ) {
				$audit_metadata['reason'] = (string) $fields['test_advance_reason'];
			} elseif ( isset( $fields['pause_reason'] ) ) {
				$audit_metadata['reason'] = (string) $fields['pause_reason'];
			}
			$audit_order_id = array_key_exists( 'wc_order_id', $update ) ? absint( $update['wc_order_id'] ) : $current->wcOrderId();
			if ( false === $this->audit->append_resource(
				'request', $request_id, $audit_order_id ?: null, $event_key, $expected_state, $new_state,
				$actor_type, $actor_user_id, $correlation_id, $audit_metadata, $event_uuid
			) ) {
				throw new RuntimeException( 'Request transition audit failed.' );
			}
			foreach ( $notifications as $notification ) {
				$queued = $this->outbox->enqueue(
					$event_uuid,
					$request_id,
					(string) $notification['role'],
					(int) $notification['user_id'],
					(string) $notification['template'],
					isset( $notification['variables'] ) && is_array( $notification['variables'] ) ? $notification['variables'] : array()
				);
				if ( ! $queued ) {
					throw new RuntimeException( 'Request notification enqueue failed.' );
				}
			}
			$this->completeIdempotency( $key_hash, $event_uuid, $request_id, $now );
			$this->db->query( 'COMMIT' );
			return $this->find( $request_id ) ?: throw new RuntimeException( 'Updated request cannot be loaded.' );
		} catch ( Throwable ) {
			$this->db->query( 'ROLLBACK' );
			return new WP_Error( 'zbm_request_transition_failed', __( 'تغییر امن درخواست انجام نشد؛ هیچ تغییری ذخیره نشد.', 'zarbalman-core' ) );
		}
	}

	/** @return list<array{id:int,version:int,current_state:string}> */
	public function due( string $deadline_column, string $state, string $now_gmt, int $limit = 100 ): array {
		if ( ! in_array( $deadline_column, array( 'supplier_deadline_gmt', 'deposit_deadline_gmt' ), true ) ) {
			return array();
		}
		$limit = max( 1, min( 500, $limit ) );
		$sql = $this->db->prepare(
			"SELECT id,version,current_state FROM %i WHERE {$deadline_column} IS NOT NULL AND {$deadline_column} <= %s AND current_state = %s AND is_paused = 0 ORDER BY {$deadline_column} ASC LIMIT %d",
			$this->schema->purchase_requests_table(), $now_gmt, $state, $limit
		);
		$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column allowlist; remaining values prepared.
		return array_map(
			static fn ( array $row ): array => array( 'id' => (int) $row['id'], 'version' => (int) $row['version'], 'current_state' => (string) $row['current_state'] ),
			is_array( $rows ) ? $rows : array()
		);
	}

	/** @return list<PurchaseRequest> */
	public function recoverable( int $limit = 100 ): array {
		$limit = max( 1, min( 500, $limit ) );
		$sql = $this->db->prepare(
			"SELECT requests.* FROM %i requests
			WHERE requests.current_state = %s
			   OR (requests.current_state = %s AND requests.payment_ready = 0 AND requests.is_paused = 0)
			   OR (requests.current_state = %s AND requests.stock_reserved = 1 AND requests.stock_released = 0)
			   OR requests.needs_reconciliation = 1
			   OR (requests.current_state = %s AND EXISTS (
					SELECT 1 FROM %i issued_payments
					WHERE issued_payments.request_id = requests.id
					  AND issued_payments.phase = 'deposit'
					  AND issued_payments.status = 'authority_issued'
			   ))
			   OR EXISTS (
				SELECT 1 FROM %i payments
				WHERE payments.request_id = requests.id
				  AND payments.phase = 'deposit'
				  AND payments.status IN (%s,%s,%s,%s,%s,%s)
			   )
			ORDER BY requests.updated_at_gmt ASC LIMIT %d",
			$this->schema->purchase_requests_table(),
			RequestState::SUPPLIER_ACCEPTED,
			RequestState::DEPOSIT_PENDING,
			RequestState::DEPOSIT_EXPIRED,
			RequestState::DEPOSIT_EXPIRED,
			$this->schema->payments_table(),
			$this->schema->payments_table(),
			'callback_received_on_time',
			'callback_received_late',
			'verification_pending_on_time',
			'verification_pending_late',
			'verified_on_time_pending',
			'verified_late_pending',
			$limit
		);
		$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		return array_map(
			static fn ( array $row ): PurchaseRequest => PurchaseRequest::fromRow( $row ),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Capture a finite ceiling for the independent paid-request bootstrap sweep.
	 * The ceiling covers all immutable request IDs so a state change behind the
	 * active cursor is picked up by the next sweep instead of extending this one.
	 */
	public function managedBootstrapUpperBound(): int {
		$value = $this->db->get_var(
			$this->db->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $this->schema->purchase_requests_table() )
		);
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Managed bootstrap upper bound is unavailable.' );
		}
		return $this->scanIdentifier( $value );
	}

	/**
	 * Verified/test-completed deposits that do not yet have a managed case.
	 *
	 * @return array{ids:list<int>,next_cursor:int,exhausted:bool,invalid_ids:list<int>}
	 */
	public function scanManagedBootstrapIds( int $cursor, int $through_id, int $limit = 25 ): array {
		if ( $cursor < 0 || $through_id < $cursor ) {
			throw new \InvalidArgumentException( 'Invalid managed bootstrap cursor/ceiling.' );
		}
		$page = array( 'ids' => array(), 'next_cursor' => $cursor, 'exhausted' => true, 'invalid_ids' => array() );
		if ( $cursor === $through_id ) {
			return $page;
		}
		$limit = max( 1, min( 100, $limit ) );
		$sql = $this->db->prepare(
			"SELECT requests.id
			FROM %i requests
			LEFT JOIN %i cases ON cases.request_id = requests.id
			WHERE requests.current_state = %s
			  AND requests.needs_reconciliation = 0
			  AND requests.execution_mode IN ('live','test')
			  AND requests.wc_order_id IS NOT NULL
			  AND (cases.request_id IS NULL OR cases.state IN ('awaiting_deposit','preparing','ready_for_delivery','ready_for_pickup','in_transit','buyer_review','seller_review','admin_review'))
			  AND requests.id > %d AND requests.id <= %d
			ORDER BY requests.id ASC LIMIT %d",
			$this->schema->purchase_requests_table(),
			$this->schema->managed_cases_table(),
			RequestState::DEPOSIT_PAID,
			$cursor,
			$through_id,
			$limit + 1
		);
		$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		if ( '' !== $this->db->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( 'Managed bootstrap scan is unavailable; retain its current cursor.' );
		}
		$page['exhausted'] = count( $rows ) <= $limit;
		foreach ( array_slice( $rows, 0, $limit ) as $row ) {
			$id = $this->scanIdentifier( $row['id'] ?? null );
			if ( $id <= $page['next_cursor'] || $id > $through_id ) {
				throw new RuntimeException( 'Managed bootstrap returned an invalid keyset range.' );
			}
			$page['next_cursor'] = $id;
			$page['ids'][] = $id;
		}
		if ( $page['exhausted'] ) {
			$page['next_cursor'] = $through_id;
		}
		return $page;
	}

	private function scanIdentifier( mixed $value ): int {
		$value = is_int( $value ) || is_string( $value ) ? (string) $value : '';
		$max = (string) PHP_INT_MAX;
		if ( ! ctype_digit( $value ) || strlen( $value ) > strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) > 0 ) ) {
			throw new RuntimeException( 'Purchase-request scan ID cannot be represented.' );
		}
		return (int) $value;
	}

	private function lockRequest( int $request_id ): ?PurchaseRequest {
		$sql = $this->db->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', $this->schema->purchase_requests_table(), $request_id );
		$row = $this->db->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		return is_array( $row ) ? PurchaseRequest::fromRow( $row ) : null;
	}

	/** @return array{resource_id:int}|WP_Error|array{} */
	private function lockIdempotency( string $key_hash, string $namespace, string $payload_hash, string $now ): array|WP_Error {
		$sql = $this->db->prepare( 'SELECT payload_hash,status,result_resource_id,expires_at_gmt FROM %i WHERE key_hash = %s FOR UPDATE', $this->schema->idempotency_table(), $key_hash );
		$row = $this->db->get_row( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		if ( $row ) {
			if ( ! hash_equals( (string) $row->payload_hash, $payload_hash ) ) {
				return new WP_Error( 'zbm_idempotency_conflict', __( 'کلید عملیات قبلاً برای داده دیگری استفاده شده است.', 'zarbalman-core' ) );
			}
			if ( 'completed' === $row->status && (int) $row->result_resource_id > 0 ) {
				return array( 'resource_id' => (int) $row->result_resource_id );
			}
			if ( strtotime( (string) $row->expires_at_gmt . ' UTC' ) >= time() ) {
				return new WP_Error( 'zbm_operation_in_progress', __( 'این عملیات در حال پردازش است.', 'zarbalman-core' ) );
			}
			$this->db->update(
				$this->schema->idempotency_table(),
				array( 'status' => 'processing', 'expires_at_gmt' => gmdate( 'Y-m-d H:i:s', time() + 900 ), 'updated_at_gmt' => $now ),
				array( 'key_hash' => $key_hash ), array( '%s', '%s', '%s' ), array( '%s' )
			);
			return array();
		}
		$inserted = $this->db->insert(
			$this->schema->idempotency_table(),
			array(
				'key_hash' => $key_hash, 'namespace' => $namespace, 'payload_hash' => $payload_hash,
				'status' => 'processing', 'expires_at_gmt' => gmdate( 'Y-m-d H:i:s', time() + 900 ),
				'created_at_gmt' => $now, 'updated_at_gmt' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			throw new RuntimeException( 'Idempotency insert failed.' );
		}
		return array();
	}

	private function completeIdempotency( string $key_hash, string $event_uuid, int $request_id, string $now ): void {
		$updated = $this->db->update(
			$this->schema->idempotency_table(),
			array( 'status' => 'completed', 'result_event_uuid' => $event_uuid, 'result_resource_id' => $request_id, 'updated_at_gmt' => $now ),
			array( 'key_hash' => $key_hash ), array( '%s', '%s', '%d', '%s' ), array( '%s' )
		);
		if ( 1 !== $updated ) {
			throw new RuntimeException( 'Idempotency completion failed.' );
		}
	}

	/** @param array<string,int|string> $buyer_variables
	 *  @param array<string,int|string> $vendor_variables
	 */
	private function enqueuePair(
		string $event_uuid,
		int $request_id,
		int $buyer_id,
		int $vendor_id,
		string $buyer_template,
		string $vendor_template,
		array $buyer_variables = array(),
		array $vendor_variables = array()
	): void {
		if ( ! $this->outbox->enqueue( $event_uuid, $request_id, 'buyer', $buyer_id, $buyer_template, $buyer_variables ) ) {
			throw new RuntimeException( 'Buyer notification enqueue failed.' );
		}
		if ( ! $this->outbox->enqueue( $event_uuid, $request_id, 'vendor', $vendor_id, $vendor_template, $vendor_variables ) ) {
			throw new RuntimeException( 'Vendor notification enqueue failed.' );
		}
	}
}
