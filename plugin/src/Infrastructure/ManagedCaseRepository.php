<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WP_Error;
use wpdb;
use Zarbalman\Core\Domain\ManagedFlow\Amounts;
use Zarbalman\Core\Domain\ManagedFlow\DepositProof;
use Zarbalman\Core\Domain\ManagedFlow\FlowState;
use Zarbalman\Core\Domain\ManagedFlow\OperationsData;
use Zarbalman\Core\Domain\Finance\Spec4Data;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Domain\PurchaseRequest\RequestPricingPolicy;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;
use Zarbalman\Core\Integration\DokanVendorEligibility;
use Zarbalman\Core\Security\AdminCapabilities;

/** Canonical managed aggregate. Use only from authenticated services, never as a POST mapper. */
final class ManagedCaseRepository {
	private Schema $schema;
	/** @var array<int,bool> Guards reentrant transactions even across repository instances. */
	private static array $active = array();
	public function hasActiveMutation(): bool { return isset( self::$active[ spl_object_id( $this->db ) ] ); }

	/** All repositories MUST share this wpdb connection and the callback's request lock. */
	public function __construct(
		private readonly wpdb $db,
		private readonly PurchaseRequestRepository $requests,
		private readonly PurchaseRequestOperationLockPort $lock,
		private readonly AuditLogRepository $audit,
		private readonly NotificationOutboxRepository $outbox
	) {
		$this->schema = new Schema( $db );
	}

	/** @return array{request_id:int,version:int,state:string,mode:string,sync_pending:int,deadline_gmt:?string,closed_at_gmt:?string,data:array}|null Internal/private; never return raw case to UI. */
	public function find( int $request_id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE request_id = %d', $this->schema->managed_cases_table(), $request_id ), ARRAY_A );
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Managed case storage is unavailable.' );
		}
		return is_array( $row ) ? $this->decode( $row ) : null;
	}

	/** Minimal status projection; never reads private evidence, bank or ledger data. */
	public function displayStatus( PurchaseRequest $request ): ?array {
		$viewer = get_current_user_id();
		if ( $viewer < 1 || ( ! in_array( $viewer, array( $request->buyerId(), $request->vendorId() ), true )
			&& ! AdminCapabilities::userCanOperate( $viewer ) && ! AdminCapabilities::userCanManageFinance( $viewer ) ) ) { return null; }
		$row = $this->db->get_row( $this->db->prepare( 'SELECT state, mode, deadline_gmt FROM %i WHERE request_id = %d', $this->schema->managed_cases_table(), $request->id() ), ARRAY_A );
		if ( ! is_array( $row ) || $row['mode'] !== $request->executionMode() || ! in_array( $row['state'], FlowState::all(), true ) ) { return null; }
		return array( 'state' => $row['state'], 'deadline_gmt' => $row['deadline_gmt'] );
	}

	/** Admin-only bounded keyset queue. No bank/evidence payload in list rows. */
	public function operationsPage( string $queue, int $before = 0 ): array {
		if ( ! AdminCapabilities::currentUserCanOperate() ) { return array(); }
		$where = match ( $queue ) {
			'supplier' => "r.current_state = 'supplier_review'",
			'deposit' => "r.current_state IN ('supplier_accepted','deposit_pending')",
			'receipts' => "JSON_UNQUOTE(JSON_EXTRACT(c.data_json, '$.manual.deposit.status')) = 'submitted'",
			'preparing' => "c.state = 'preparing'",
			'delivery' => "c.state IN ('ready_for_delivery','ready_for_pickup','in_transit')",
			'buyer' => "c.state = 'buyer_review'",
			'vendor' => "c.state = 'seller_review'",
			'review' => "(c.state IN ('admin_review','return_review') OR r.needs_reconciliation = 1 OR r.current_state IN ('admin_review','late_payment_review'))",
			'sync' => "(c.sync_pending = 1 OR (r.current_state = 'deposit_paid' AND (c.request_id IS NULL OR (c.state IN ('preparing','ready_for_delivery','ready_for_pickup','in_transit','buyer_review','seller_review','admin_review') AND JSON_EXTRACT(c.data_json, '$.operations.bootstrapped_at') IS NULL))))",
			'final' => "c.state = 'final_weight_locked'",
			default => '1=1',
		};
		$before = $before > 0 ? $before : PHP_INT_MAX;
		$sql = $this->db->prepare( "SELECT r.id, r.request_number, r.current_state, r.buyer_id, r.vendor_id, r.delivery_start_gmt, r.delivery_end_gmt, c.state AS operation_state, c.sync_pending, c.deadline_gmt, c.updated_at_gmt FROM %i r LEFT JOIN %i c ON c.request_id = r.id WHERE r.id < %d AND ({$where}) ORDER BY r.id DESC LIMIT 31", $this->schema->purchase_requests_table(), $this->schema->managed_cases_table(), $before );
		$rows = $this->db->get_results( $sql, ARRAY_A );
		if ( '' !== $this->db->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Operations queue unavailable.' ); }
		return $rows;
	}

	/** Paginated immutable audit, selected fields only; caller must not show it to owners. */
	public function historyForAdmin( int $request_id, int $before = 0 ): array {
		if ( ! AdminCapabilities::currentUserCanOperate() || $request_id < 1 ) { return array(); }
		$rows = $this->db->get_results( $this->db->prepare( "SELECT id, event_key, from_state, to_state, actor_type, actor_user_id, created_at_gmt FROM %i WHERE resource_type = 'request' AND resource_id = %d AND id < %d ORDER BY id DESC LIMIT 51", $this->schema->audit_table(), $request_id, $before > 0 ? $before : PHP_INT_MAX ), ARRAY_A );
		if ( '' !== $this->db->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Operations history unavailable.' ); }
		return $rows;
	}

	/** Read-only proof; locked=true is allowed only inside this repository's transaction. */
	public function depositVerified( PurchaseRequest $request, array $case, bool $locked = false ): bool {
		if ( $locked && ! isset( self::$active[ spl_object_id( $this->db ) ] ) ) {
			throw new RuntimeException( 'Deposit proof requires the aggregate transaction.' );
		}
		if ( RequestState::DEPOSIT_PAID !== $request->state() || $request->needsReconciliation() || ! $request->wcOrderId()
			|| $case['request_id'] !== $request->id() || $case['mode'] !== $request->executionMode() ) { return false; }
		$fund = $case['data']['funds']['deposit'];
		if ( 'test' === $case['mode'] ) {
			return 0 === $case['sync_pending'] && array( 'amount' => 0, 'provider' => null, 'payment_id' => null ) === $fund;
		}
		$known_projection = ( 'manual_deposit_projection' === $case['data']['recovery']['operation'] && 'manual_bank' === $fund['provider'] )
			|| ( isset( $case['data']['spec4'] ) && in_array( $case['data']['recovery']['operation'], array( 'manual_completion_projection', 'managed_completion_projection' ), true ) );
		if ( 'live' !== $case['mode'] || ( 1 === $case['sync_pending'] && ! $known_projection ) ) { return false; }
		$suffix = $locked ? ' FOR UPDATE' : '';
		$payments = $this->db->get_results( $this->db->prepare( "SELECT * FROM %i WHERE request_id = %d AND phase = 'deposit' AND status IN ('captured','late_accepted') LIMIT 2{$suffix}", $this->schema->payments_table(), $request->id() ), ARRAY_A );
		if ( '' !== $this->db->last_error || ! is_array( $payments ) || 1 !== count( $payments ) ) { return false; }
		$cash = $this->db->get_results( $this->db->prepare( "SELECT * FROM %i WHERE request_id = %d AND payment_id = %d AND entry_type = 'employer_cash_received' LIMIT 2{$suffix}", $this->schema->ledger_table(), $request->id(), $fund['payment_id'] ), ARRAY_A );
		if ( '' !== $this->db->last_error || ! is_array( $cash ) || 1 !== count( $cash ) ) { return false; }
		if ( ! DepositProof::matches( $request, $fund, $payments[0], $cash[0], $case['data']['manual']['deposit'] ) ) { return false; }
		// Another received/unknown attempt must not be hidden by one valid capture.
		$other = $this->db->get_results( $this->db->prepare( "SELECT id FROM %i WHERE request_id = %d AND phase = 'deposit' AND id <> %d AND (status NOT IN ('expected','expired') OR TRIM(COALESCE(authority,'')) <> '' OR TRIM(COALESCE(reference,'')) <> '') LIMIT 1{$suffix}", $this->schema->payments_table(), $request->id(), $fund['payment_id'] ), ARRAY_A );
		return '' === $this->db->last_error && is_array( $other ) && array() === $other;
	}

	/**
	 * No enclosing transaction, nested service transactions, WooCommerce saves or external sends.
	 * SQL financial adapters may append through this same connection inside the callback.
	 * @param array<string,int|bool|string> $fingerprint Safe IDs/flags and *_hash SHA256 values ONLY; never raw user input.
	 * @param callable(PurchaseRequest,array): (array|WP_Error) $operation
	 * Callback envelope: ['case'=>full case,'request_fields'=>allowlisted fields,'audit'=>safe metadata,
	 * 'notifications'=>list<array{role:string,user_id:int,template:string,variables?:array}>].
	 * @return array|WP_Error Persisted CURRENT case on replay; callback is not called twice.
	 */
	public function mutate( int $request_id, int $expected_version, string $actor, int $actor_id, string $key, string $event, array $fingerprint, callable $operation ): array|WP_Error {
		$unavailable = Migrator::managedAvailabilityError();
		if ( null !== $unavailable ) {
			return $unavailable;
		}
		if ( $request_id < 1 || $expected_version < 0 || $expected_version >= PHP_INT_MAX || ! wp_is_uuid( $key ) || 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,99}\z/', $event ) ) {
			return $this->error( 'command' );
		}
		$connection = spl_object_id( $this->db );
		if ( isset( self::$active[ $connection ] ) ) {
			return $this->error( 'nested_transaction' );
		}
		$acquired = false;
		$transaction = false;
		$committing = false;
		$previous_wait = null;
		try {
			$this->safeMetadata( $fingerprint );
			ksort( $fingerprint );
			$payload_hash = hash( 'sha256', $this->json( array( $actor, $actor_id, $event, $fingerprint ) ) );
			$key_hash = hash( 'sha256', strtolower( $key ) );
			$this->lock->acquire( $request_id, 10 );
			$acquired = true;
			self::$active[ $connection ] = true;
			$wait = $this->db->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' );
			if ( ! is_scalar( $wait ) || ! ctype_digit( (string) $wait ) || (int) $wait < 1 ) {
				throw new RuntimeException( 'Cannot bound the transaction lock wait.' );
			}
			$previous_wait = (int) $wait;
			$this->query( 'SET SESSION innodb_lock_wait_timeout = 10' );
			$this->query( 'START TRANSACTION' );
			$transaction = true;
			$started = microtime( true );
			// Match the existing deposit callback's ordering: request first, then case.
			$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', $this->schema->purchase_requests_table(), $request_id ), ARRAY_A );
			if ( ! is_array( $row ) ) {
				throw new RuntimeException( 'Missing request.' );
			}
			$request = PurchaseRequest::fromRow( $row );
			if ( ! $this->authorized( $request, $actor, $actor_id, $event ) ) {
				$this->query( 'ROLLBACK' );
				$transaction = false;
				return $this->error( 'forbidden' );
			}
			$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE request_id = %d FOR UPDATE', $this->schema->managed_cases_table(), $request_id ), ARRAY_A );
			if ( '' !== $this->db->last_error ) {
				throw new RuntimeException( 'Cannot lock managed case.' );
			}
			$exists = is_array( $row );
			$case = $exists ? $this->decode( $row ) : $this->initial( $request );
			$replay = $this->db->get_row( $this->db->prepare( 'SELECT payload_hash FROM %i WHERE request_id = %d AND key_hash = %s FOR UPDATE', $this->schema->managed_case_commands_table(), $request_id, $key_hash ), ARRAY_A );
			if ( '' !== $this->db->last_error ) {
				throw new RuntimeException( 'Cannot read durable command.' );
			}
			if ( is_array( $replay ) ) {
				$this->query( 'ROLLBACK' );
				$transaction = false;
				return $exists && hash_equals( (string) $replay['payload_hash'], $payload_hash ) ? $case : $this->error( 'idempotency_conflict' );
			}
			if ( $case['version'] !== $expected_version ) {
				$this->query( 'ROLLBACK' );
				$transaction = false;
				return $this->error( 'stale' );
			}
			$this->validateCase( $case, $request );
			if ( $case['mode'] !== $request->executionMode() || 'test_pending' === $request->executionMode() || ( ( $request->isPaused() || $case['data']['pause']['active'] ) && ! in_array( $actor, array( 'admin', 'system' ), true ) ) ) {
				throw new InvalidArgumentException( 'Managed mode or pause conflict.' );
			}
			$result = $operation( $request, $case );
			if ( is_wp_error( $result ) ) {
				$this->query( 'ROLLBACK' );
				$transaction = false;
				return $result;
			}
			if ( ! is_array( $result ) || array_diff( array( 'case', 'request_fields', 'audit', 'notifications' ), array_keys( $result ) ) || count( $result ) !== 4 ) {
				throw new InvalidArgumentException( 'Invalid managed mutation envelope.' );
			}
			$next = $result['case'];
			if ( ! is_array( $next ) || $next['request_id'] !== $request_id || $next['version'] !== $case['version'] || $next['mode'] !== $case['mode'] ) {
				throw new InvalidArgumentException( 'Immutable managed identity changed.' );
			}
			$this->validateCase( $next, $request );
			$this->validateChanges( $case, $next, $request, $actor, $actor_id, $event );
			$this->assertEvidence( $next, $request );
			$this->safeMetadata( $result['audit'] );
			$this->requestFields( $request, $result['request_fields'], $next, $actor, $actor_id );
			$now = current_time( 'mysql', true );
			$next['version'] = $case['version'] + 1;
			$next['closed_at_gmt'] = FlowState::isClosed( $next['state'] ) ? ( $case['closed_at_gmt'] ?? $now ) : null;
			$fields = array( 'version' => $next['version'], 'state' => $next['state'], 'mode' => $next['mode'], 'sync_pending' => $next['sync_pending'], 'deadline_gmt' => $next['deadline_gmt'], 'closed_at_gmt' => $next['closed_at_gmt'], 'data_json' => $this->json( $next['data'] ), 'updated_at_gmt' => $now );
			if ( $exists ) {
				$written = $this->db->update( $this->schema->managed_cases_table(), $fields, array( 'request_id' => $request_id, 'version' => $case['version'] ), array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ), array( '%d', '%d' ) );
			} else {
				$fields['request_id'] = $request_id;
				$fields['created_at_gmt'] = $now;
				$written = $this->db->insert( $this->schema->managed_cases_table(), $fields, array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' ) );
			}
			if ( 1 !== $written ) {
				throw new RuntimeException( 'Managed case CAS failed.' );
			}
			$event_uuid = wp_generate_uuid4();
			if ( false === $this->audit->append_resource( 'request', $request_id, $request->wcOrderId(), $event, $case['state'], $next['state'], $actor, $actor_id, $key, $result['audit'], $event_uuid ) ) {
				throw new RuntimeException( 'Managed audit failed.' );
			}
			$this->notifications( $request, $event_uuid, $result['notifications'] );
			if ( 1 !== $this->db->insert( $this->schema->managed_case_commands_table(), array( 'request_id' => $request_id, 'key_hash' => $key_hash, 'payload_hash' => $payload_hash, 'event_key' => $event, 'actor_type' => $actor, 'actor_id' => $actor_id, 'result_version' => $next['version'], 'event_uuid' => $event_uuid, 'created_at_gmt' => $now ), array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ) ) ) {
				throw new RuntimeException( 'Managed durable command failed.' );
			}
			if ( microtime( true ) - $started > 10 ) {
				throw new RuntimeException( 'Managed transaction exceeded its budget.' );
			}
			$committing = true;
			$this->query( 'COMMIT' );
			$transaction = false;
			// Return what was persisted without post-commit reads/sends that could
			// misreport a committed operation as rolled back.
			return $next;
		} catch ( Throwable ) {
			if ( $transaction ) {
				try {
					// If COMMIT was indeterminate this only releases any still-open
					// transaction; it cannot undo committed facts. Result stays UNKNOWN.
					$this->db->query( 'ROLLBACK' );
				} catch ( Throwable ) {
					// Do not replace the safe operation error with a driver exception.
				}
			}
			return $this->error( $committing ? 'commit_unknown' : 'storage_failed' );
		} finally {
			if ( $acquired ) {
				unset( self::$active[ $connection ] );
				try {
					if ( null !== $previous_wait ) {
						$this->db->query( $this->db->prepare( 'SET SESSION innodb_lock_wait_timeout = %d', $previous_wait ) );
					}
				} catch ( Throwable ) {
					// No post-commit failure may pretend the durable mutation rolled back.
				}
				try {
					$this->lock->release( $request_id );
				} catch ( Throwable ) {
					// The committed result remains authoritative. Connection close releases locks.
				}
			}
		}
	}

	/** @deprecated IDs-only queues cannot convey progress. Use independent scanReviewIds/scanSyncIds cursors. */
	public function dueIds( int $limit = 50 ): array {
		throw new \LogicException( 'Use scanReviewIds and scanSyncIds with independently persisted cursors and budgets.' );
	}

	/** @deprecated Use scanClosedIds; an empty eligible-ID page does not mean the scan is exhausted. */
	public function closedIds( int $limit = 25 ): array {
		throw new \LogicException( 'Use scanClosedIds and persist next_cursor even when no eligible IDs are returned.' );
	}

	/**
	 * Capture the finite request-ID ceiling for a new sweep (0 when empty).
	 * Persist this with each queue's cursor; do NOT refresh it on every page.
	 * @throws RuntimeException On SQL failure or an unrepresentable stored ID.
	 */
	public function scanUpperBound(): int {
		$value = $this->db->get_var( $this->db->prepare( 'SELECT COALESCE(MAX(request_id), 0) FROM %i', $this->schema->managed_cases_table() ) );
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Managed scan upper bound is unavailable.' );
		}
		return $this->scanId( $value );
	}

	/**
	 * Independent expired-review queue; projection failures never consume its budget.
	 * @return array{ids:list<int>,next_cursor:int,exhausted:bool,invalid_ids:list<int>}
	 * Persist cursor/ceiling separately from sync and retention. Recheck deadline/paused state under mutate.
	 */
	public function scanReviewIds( int $cursor, int $through_id, int $limit = 50 ): array {
		return $this->scanPage( 'review', $cursor, $through_id, $limit );
	}

	/**
	 * Independent sync_pending queue, including paused cases needing committed-fact projection.
	 * @return array{ids:list<int>,next_cursor:int,exhausted:bool,invalid_ids:list<int>}
	 * Persist returned progress before bounded per-ID recovery; failures retry on the NEXT sweep.
	 */
	public function scanSyncIds( int $cursor, int $through_id, int $limit = 50 ): array {
		return $this->scanPage( 'sync', $cursor, $through_id, $limit );
	}

	public function scanDeliveryIds( int $cursor, int $through_id, int $limit = 25 ): array {
		return $this->scanPage( 'delivery', $cursor, $through_id, $limit );
	}

	/**
	 * Closed >=30 days, settled, unpaused and no reconciliation/sync candidates.
	 * @return array{ids:list<int>,next_cursor:int,exhausted:bool,invalid_ids:list<int>}
	 * Cursor advances over ALL scanned rows, including unsettled/paused/corrupt JSON rows.
	 * Empty ids is not exhaustion. Invalid payload IDs are reported without exposing private data.
	 */
	public function scanClosedIds( int $cursor, int $through_id, int $limit = 25 ): array {
		return $this->scanPage( 'closed', $cursor, $through_id, $limit );
	}

	/** Keyset paging reads at most limit+1 rows; immutable ID ceilings make each sweep finite. */
	private function scanPage( string $queue, int $cursor, int $through_id, int $limit ): array {
		if ( $cursor < 0 || $through_id < $cursor ) {
			throw new InvalidArgumentException( 'Invalid managed scan cursor/ceiling.' );
		}
		$page = array( 'ids' => array(), 'next_cursor' => $cursor, 'exhausted' => true, 'invalid_ids' => array() );
		if ( $cursor === $through_id ) {
			return $page;
		}
		$limit = max( 1, min( 100, $limit ) );
		$args = array( $this->schema->managed_cases_table(), $this->schema->purchase_requests_table() );
		if ( 'review' === $queue ) {
			$where = "c.state IN ('buyer_review','seller_review') AND c.deadline_gmt <= %s AND r.is_paused = 0";
			$args[] = current_time( 'mysql', true );
		} elseif ( 'sync' === $queue ) {
			$where = 'c.sync_pending = 1';
		} elseif ( 'delivery' === $queue ) {
			$where = "c.state IN ('preparing','ready_for_delivery','ready_for_pickup','in_transit') AND r.current_state = 'deposit_paid' AND r.is_paused = 0 AND r.needs_reconciliation = 0";
		} elseif ( 'closed' === $queue ) {
			$where = "c.state IN ('completed','cancelled') AND c.closed_at_gmt <= %s AND c.sync_pending = 0 AND r.needs_reconciliation = 0 AND r.is_paused = 0";
			$args[] = gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 );
		} else {
			throw new InvalidArgumentException( 'Unknown managed scan queue.' );
		}
		$columns = 'closed' === $queue ? 'c.*' : 'c.request_id';
		$args[] = $cursor;
		$args[] = $through_id;
		$args[] = $limit + 1;
		$sql = $this->db->prepare( "SELECT {$columns} FROM %i c INNER JOIN %i r ON r.id = c.request_id WHERE {$where} AND c.request_id > %d AND c.request_id <= %d ORDER BY c.request_id ASC LIMIT %d", ...$args );
		$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Strict internal column/predicate allowlist and prepared values.
		if ( '' !== $this->db->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( 'Managed queue scan is unavailable; retain its current cursor.' );
		}
		$page['exhausted'] = count( $rows ) <= $limit;
		foreach ( array_slice( $rows, 0, $limit ) as $row ) {
			$id = $this->scanId( $row['request_id'] ?? null );
			if ( $id <= $page['next_cursor'] || $id > $through_id ) {
				throw new RuntimeException( 'Managed scan returned an invalid keyset range.' );
			}
			// Advance independently of eligibility and processing success. The
			// lookahead row is deliberately left for the next bounded page.
			$page['next_cursor'] = $id;
			if ( 'closed' === $queue ) {
				try {
					$case = $this->decode( $row );
					$settlement = $case['data']['final']['settlement'] ?? null;
					$paused = $case['data']['pause']['active'] ?? null;
					if ( ! in_array( $settlement, array( 'unresolved', 'pending', 'credited', 'not_required' ), true ) || ! is_bool( $paused ) ) {
						throw new RuntimeException( 'Invalid retention eligibility payload.' );
					}
				} catch ( Throwable ) {
					$page['invalid_ids'][] = $id;
					continue;
				}
				if ( ! in_array( $settlement, array( 'credited', 'not_required' ), true ) || $paused ) {
					continue;
				}
			}
			$page['ids'][] = $id;
		}
		if ( $page['exhausted'] ) {
			$page['next_cursor'] = $through_id;
		}
		return $page;
	}

	private function scanId( mixed $value ): int {
		$value = is_int( $value ) || is_string( $value ) ? (string) $value : '';
		$max = (string) PHP_INT_MAX;
		if ( ! ctype_digit( $value ) || strlen( $value ) > strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) > 0 ) ) {
			throw new RuntimeException( 'Managed scan ID cannot be represented.' );
		}
		return (int) $value;
	}

	/** Preserve legacy format 1; canonical request line snapshots use format 2. */
	public static function initialData( PurchaseRequest $request ): array {
		$fund = array( 'amount' => 0, 'provider' => null, 'payment_id' => null );
		$snapshots = $request->lines();
		$format = str_starts_with( (string) array_key_first( $snapshots ), 'line:' ) ? 2 : 1;
		$lines = array();
		foreach ( $snapshots as $key => $snapshot ) {
			$lines[ $key ] = array( 'reference_tenths' => $snapshot['reference_tenths'], 'estimated_total' => $snapshot['estimated_total'],
				'vendor_tenths' => null, 'buyer_tenths' => null, 'final_tenths' => null, 'vendor_evidence' => array(), 'buyer_evidence' => array(),
				'buyer_objection' => array(), 'vendor_objection' => array(), 'buyer_reason' => '', 'vendor_reason' => '',
				'warning_ack' => array( 'buyer' => false, 'vendor' => false, 'admin' => false ) );
			if ( 2 === $format ) { $lines[ $key ] += array( 'snapshot' => $snapshot, 'vendor_submitted_at' => null, 'buyer_submitted_at' => null ); }
		}
		return array(
			'format' => $format,
			'lines' => $lines,
			'manual' => array( 'deposit' => null, 'balance' => null ),
			'funds' => array( 'deposit' => $fund, 'balance' => $fund ),
			'delivery' => array( 'generation' => 0, 'hmac' => null, 'encrypted_code' => null, 'code_issued_at' => null, 'attempts' => 0, 'delivered_at' => null, 'address_kind' => null, 'address_encrypted' => null ),
			'pause' => array( 'active' => $request->isPaused(), 'remaining' => $request->pausedRemainingSeconds() ),
			'notes' => array( 'public' => array(), 'private' => array() ),
			'final' => array( 'total' => null, 'commission' => null, 'net' => null, 'locked_at' => null, 'settlement' => 'unresolved', 'allocation_hash' => null, 'credited_at' => null ),
			'recovery' => array( 'event_uuid' => null, 'operation' => null, 'last_error_code' => null, 'attempts' => 0 ),
			'retention' => array( 'private_cleaned_at' => null, 'evidence_files_purged' => 0 ),
		);
	}

	private function initial( PurchaseRequest $request ): array {
		if ( ! in_array( $request->state(), array( RequestState::SUPPLIER_ACCEPTED, RequestState::DEPOSIT_PENDING, RequestState::DEPOSIT_PAID ), true ) || ! in_array( $request->executionMode(), array( 'live', 'test' ), true ) || ( 'test' === $request->executionMode() && RequestState::DEPOSIT_PAID !== $request->state() ) ) {
			throw new InvalidArgumentException( 'Request is not eligible for a managed case.' );
		}
		$data = self::initialData( $request );
		if ( 'live' === $request->executionMode() && RequestState::DEPOSIT_PAID === $request->state() ) {
			$rows = $this->db->get_results( $this->db->prepare( "SELECT id,provider,paid_amount_toman FROM %i WHERE request_id = %d AND phase = 'deposit' AND status IN ('captured','late_accepted') FOR UPDATE", $this->schema->payments_table(), $request->id() ), ARRAY_A );
			if ( ! is_array( $rows ) || count( $rows ) !== 1 || (int) $rows[0]['paid_amount_toman'] < 1 ) {
				throw new RuntimeException( 'Confirmed deposit needs reconciliation.' );
			}
			$data['funds']['deposit'] = array( 'amount' => (int) $rows[0]['paid_amount_toman'], 'provider' => $rows[0]['provider'], 'payment_id' => (int) $rows[0]['id'] );
		}
		return array( 'request_id' => $request->id(), 'version' => 0, 'state' => RequestState::DEPOSIT_PAID === $request->state() ? FlowState::PREPARING : FlowState::AWAITING_DEPOSIT, 'mode' => $request->executionMode(), 'sync_pending' => 0, 'deadline_gmt' => null, 'closed_at_gmt' => null, 'data' => $data );
	}

	private function authorized( PurchaseRequest $request, string $actor, int $id, string $event ): bool {
		return match ( $actor ) {
			'buyer' => $id > 0 && $id === $request->buyerId(),
			'vendor' => $id === $request->vendorId() && ( new DokanVendorEligibility() )->isApprovedVendor( $id ),
			'admin' => AdminCapabilities::userCanOperate( $id ) || AdminCapabilities::userCanManageFinance( $id ),
			'system' => 0 === $id && in_array( $event, array( 'managed_timeout', 'managed_cleanup', 'managed_sync_deposit', 'managed_recovery', 'manual_deposit_window_ready', 'managed_delivery_reminder', 'managed_delivery_overdue',
				'spec4_open', 'spec4_balance_opened', 'spec4_authority_issued', 'spec4_issuance_failed', 'spec4_callback_received', 'spec4_balance_verified', 'spec4_payment_review', 'spec4_remainder_expired', 'spec4_warning_expired', 'spec4_vendor_credit', 'spec4_allocation_review' ), true ),
			default => false,
		};
	}

	private function decode( array $row ): array {
		foreach ( array( 'request_id', 'version', 'sync_pending' ) as $field ) {
			$value = (string) ( $row[ $field ] ?? '' );
			$max = (string) PHP_INT_MAX;
			if ( ! ctype_digit( $value ) || strlen( $value ) > strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) > 0 ) ) {
				throw new RuntimeException( 'Managed integer cannot be represented.' );
			}
		}
		$data = json_decode( (string) $row['data_json'], true, 32, JSON_THROW_ON_ERROR );
		if ( ! is_array( $data ) || ! in_array( $data['format'] ?? null, array( 1, 2 ), true ) ) {
			throw new RuntimeException( 'Unsupported managed data format.' );
		}
		return array( 'request_id' => (int) $row['request_id'], 'version' => (int) $row['version'], 'state' => $row['state'], 'mode' => $row['mode'], 'sync_pending' => (int) $row['sync_pending'], 'deadline_gmt' => $row['deadline_gmt'], 'closed_at_gmt' => $row['closed_at_gmt'], 'data' => $data );
	}

	private function validateCase( array $case, PurchaseRequest $request ): void {
		$this->keys( $case, array( 'request_id', 'version', 'state', 'mode', 'sync_pending', 'deadline_gmt', 'closed_at_gmt', 'data' ) );
		$this->integer( $case['sync_pending'], 0, 1 );
		$this->integer( $case['request_id'], 1 );
		$this->integer( $case['version'], 0, PHP_INT_MAX - 1 );
		if ( ! in_array( $case['state'], FlowState::all(), true ) || ! in_array( $case['mode'], array( 'live', 'test' ), true ) ) {
			throw new InvalidArgumentException( 'Invalid managed state/mode.' );
		}
		$this->date( $case['deadline_gmt'] );
		$this->date( $case['closed_at_gmt'] );
		$data = $case['data'];
		$data_keys = array( 'format', 'lines', 'manual', 'funds', 'delivery', 'pause', 'notes', 'final', 'recovery', 'retention' );
		if ( array_key_exists( 'operations', $data ) ) {
			$data_keys[] = 'operations';
			OperationsData::validate( $data['operations'], $request );
		}
		if ( array_key_exists( 'spec4', $data ) ) {
			$data_keys[] = 'spec4';
			Spec4Data::validate( $data['spec4'], $request, $case );
		}
		$this->keys( $data, $data_keys );
		$snapshots = $request->lines();
		if ( ! in_array( $data['format'], array( 1, 2 ), true ) || ! is_array( $data['lines'] ) || ! $data['lines']
			|| ( 1 === $data['format'] && ( 1 !== count( $data['lines'] ) || $request->isMultiline() ) )
			|| ( 2 === $data['format'] && array_keys( $data['lines'] ) !== array_keys( $snapshots ) ) ) {
			throw new InvalidArgumentException( 'Managed rows must match the immutable request.' );
		}
		foreach ( $data['lines'] as $id => $line ) {
			if ( 1 === $data['format'] && (string) $id !== 'request:' . $request->id() && ( 1 !== preg_match( '/\A[1-9][0-9]*\z/', (string) $id ) || ! $request->wcOrderId() ) ) {
				throw new InvalidArgumentException( 'Invalid managed line key.' );
			}
			$line_keys = array( 'reference_tenths', 'estimated_total', 'vendor_tenths', 'buyer_tenths', 'final_tenths', 'vendor_evidence', 'buyer_evidence', 'buyer_objection', 'vendor_objection', 'buyer_reason', 'vendor_reason', 'warning_ack' );
			if ( 2 === $data['format'] ) {
				$line_keys = array_merge( $line_keys, array( 'snapshot', 'vendor_submitted_at', 'buyer_submitted_at' ) );
				if ( $line['snapshot'] !== $snapshots[ $id ] ) { throw new InvalidArgumentException( 'Product snapshot cannot change.' ); }
				$this->date( $line['vendor_submitted_at'] );
				$this->date( $line['buyer_submitted_at'] );
			}
			$this->keys( $line, $line_keys );
			$this->integer( $line['reference_tenths'], 1 );
			$this->integer( $line['estimated_total'], 1 );
			$snapshot = 2 === $data['format'] ? $snapshots[ $id ] : reset( $snapshots );
			if ( $line['reference_tenths'] !== $snapshot['reference_tenths'] || $line['estimated_total'] !== $snapshot['estimated_total'] ) {
				throw new InvalidArgumentException( 'Historical pricing snapshot changed.' );
			}
			foreach ( array( 'vendor_tenths', 'buyer_tenths', 'final_tenths' ) as $field ) {
				if ( null !== $line[ $field ] ) {
					$this->integer( $line[ $field ], 1 );
				}
			}
			foreach ( array( 'vendor_evidence', 'buyer_evidence', 'buyer_objection', 'vendor_objection' ) as $field ) {
				$this->ids( $line[ $field ], 4 );
			}
			if ( 2 === $data['format'] ) {
				foreach ( array( 'vendor', 'buyer' ) as $owner ) {
					$submitted = null !== $line[ $owner . '_tenths' ];
					if ( $submitted !== is_string( $line[ $owner . '_submitted_at' ] ) || $submitted !== ( count( $line[ $owner . '_evidence' ] ) > 0 ) ) { throw new InvalidArgumentException( 'Row weight, timestamp and proof must be recorded together.' ); }
				}
				if ( null !== $line['final_tenths'] && null === $data['delivery']['delivered_at'] ) { throw new InvalidArgumentException( 'Final row weight requires recorded delivery.' ); }
			}
			$this->text( $line['buyer_reason'], 1000 );
			$this->text( $line['vendor_reason'], 1000 );
			$this->keys( $line['warning_ack'], array( 'buyer', 'vendor', 'admin' ) );
			foreach ( $line['warning_ack'] as $ack ) {
				if ( ! is_bool( $ack ) ) {
					throw new InvalidArgumentException( 'Invalid warning acknowledgment.' );
				}
			}
		}
		$this->lineEvidenceBindings( $data );
		$this->keys( $data['manual'], array( 'deposit', 'balance' ) );
		$this->keys( $data['funds'], array( 'deposit', 'balance' ) );
		foreach ( array( 'deposit', 'balance' ) as $phase ) {
			$offer = $data['manual'][ $phase ];
			if ( null !== $offer ) {
				$offer_keys = array( 'offer_id', 'amount', 'status', 'account_encrypted', 'receipt_ids', 'receipt_history', 'offered_at', 'submitted_at', 'reviewed_at', 'reviewed_by', 'reason' );
				$this->keys( $offer, array_key_exists( 'buyer_note', $offer ) ? array_merge( $offer_keys, array( 'buyer_note' ) ) : $offer_keys );
				if ( array_key_exists( 'buyer_note', $offer ) ) {
					$this->text( $offer['buyer_note'], 1000 );
				}
				if ( 'live' !== $case['mode'] || ! wp_is_uuid( $offer['offer_id'] ) || ! in_array( $offer['status'], array( 'offered', 'submitted', 'approved', 'rejected' ), true ) ) {
					throw new InvalidArgumentException( 'Invalid manual offer.' );
				}
				$this->integer( $offer['amount'], 1 );
				$this->text( $offer['account_encrypted'], 20000, null === $data['retention']['private_cleaned_at'] );
				$this->ids( $offer['receipt_ids'], 1 );
				if ( ! is_array( $offer['receipt_history'] ) || ! array_is_list( $offer['receipt_history'] ) || count( $offer['receipt_history'] ) > 20 ) {
					throw new InvalidArgumentException( 'Manual receipt history exceeds its bound.' );
				}
				foreach ( $offer['receipt_history'] as $history ) {
					$history_keys = array( 'receipt_ids', 'submitted_at', 'reviewed_at', 'reviewed_by', 'reason' );
					$this->keys( $history, array_key_exists( 'buyer_note', $history ) ? array_merge( $history_keys, array( 'buyer_note' ) ) : $history_keys );
					if ( array_key_exists( 'buyer_note', $history ) ) {
						$this->text( $history['buyer_note'], 1000 );
					}
					$this->ids( $history['receipt_ids'], 1 );
					if ( 1 !== count( $history['receipt_ids'] ) ) {
					throw new InvalidArgumentException( 'Rejected receipt history requires one file.' );
					}
					$this->date( $history['submitted_at'] );
					$this->date( $history['reviewed_at'] );
					$this->integer( $history['reviewed_by'], 1 );
					$this->text( $history['reason'], 1000, true );
				}
				if ( in_array( $offer['status'], array( 'submitted', 'approved' ), true ) && count( $offer['receipt_ids'] ) !== 1 ) {
					throw new InvalidArgumentException( 'Manual receipt requires exactly one file.' );
				}
				foreach ( array( 'offered_at', 'submitted_at', 'reviewed_at' ) as $date ) {
					$this->date( $offer[ $date ] );
				}
				$this->integer( $offer['reviewed_by'], 0 );
				$this->text( $offer['reason'], 1000 );
			}
			$fund = $data['funds'][ $phase ];
			$this->keys( $fund, array( 'amount', 'provider', 'payment_id' ) );
			$this->integer( $fund['amount'], 0 );
			if ( 0 === $fund['amount'] ) {
				if ( null !== $fund['provider'] || null !== $fund['payment_id'] ) {
					throw new InvalidArgumentException( 'Empty funds cannot have a provider.' );
				}
			} else {
				$this->integer( $fund['payment_id'], 1 );
				if ( 'live' !== $case['mode'] || ! in_array( $fund['provider'], array( 'zarinpal', 'manual_bank' ), true ) ) {
					throw new InvalidArgumentException( 'Test cases cannot contain real funds.' );
				}
			}
		}
		$delivery = $data['delivery'];
		$this->keys( $delivery, array( 'generation', 'hmac', 'encrypted_code', 'code_issued_at', 'attempts', 'delivered_at', 'address_kind', 'address_encrypted' ) );
		$this->integer( $delivery['generation'], 0 );
		$this->integer( $delivery['attempts'], 0, 5 );
		$this->date( $delivery['code_issued_at'] );
		$this->date( $delivery['delivered_at'] );
		if ( null !== $delivery['hmac'] ) {
			if ( ! is_string( $delivery['hmac'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $delivery['hmac'] ) || $delivery['generation'] < 1 || ! $request->wcOrderId() || null === $delivery['code_issued_at'] ) {
				throw new InvalidArgumentException( 'Invalid scoped delivery code.' );
			}
			$this->text( $delivery['encrypted_code'], 20000, true );
		} elseif ( null !== $delivery['encrypted_code'] || null !== $delivery['code_issued_at'] ) {
			throw new InvalidArgumentException( 'Unscoped encrypted code.' );
		}
		if ( null !== $delivery['delivered_at'] && ( null !== $delivery['hmac'] || null !== $delivery['encrypted_code'] ) ) {
			throw new InvalidArgumentException( 'Consumed code must be removed.' );
		}
		if ( null === $delivery['address_kind'] ) {
			if ( null !== $delivery['address_encrypted'] ) {
				throw new InvalidArgumentException( 'Unscoped delivery address.' );
			}
		} elseif ( ! in_array( $delivery['address_kind'], array( 'buyer_delivery', 'vendor_pickup' ), true ) ) {
			throw new InvalidArgumentException( 'Invalid delivery address kind.' );
		} elseif ( null === $data['retention']['private_cleaned_at'] ) {
			$this->text( $delivery['address_encrypted'], 20000, true );
		} elseif ( null !== $delivery['address_encrypted'] ) {
			throw new InvalidArgumentException( 'Cleaned private address must remain absent.' );
		}
		$this->keys( $data['pause'], array( 'active', 'remaining' ) );
		if ( ! is_bool( $data['pause']['active'] ) ) {
			throw new InvalidArgumentException( 'Invalid pause flag.' );
		}
		if ( null !== $data['pause']['remaining'] ) {
			$this->integer( $data['pause']['remaining'], 0, 604800 );
		}
		if ( $data['pause']['active'] && null !== $case['deadline_gmt'] ) {
			throw new InvalidArgumentException( 'Paused cases cannot have a running clock.' );
		}
		if ( ! $data['pause']['active'] && in_array( $case['state'], array( 'buyer_review', 'seller_review' ), true ) && null === $case['deadline_gmt'] ) {
			throw new InvalidArgumentException( 'Review requires an explicit UTC deadline.' );
		}
		$this->keys( $data['notes'], array( 'public', 'private' ) );
		foreach ( $data['notes'] as $notes ) {
			if ( ! is_array( $notes ) || ! array_is_list( $notes ) || count( $notes ) > 100 ) {
				throw new InvalidArgumentException( 'Managed notes limit exceeded.' );
			}
			foreach ( $notes as $note ) {
				$this->keys( $note, array( 'actor_id', 'at_gmt', 'text' ) );
				$this->integer( $note['actor_id'], 1 );
				$this->date( $note['at_gmt'] );
				$this->text( $note['text'], 1000, true );
			}
		}
		$final = $data['final'];
		$final_keys = array( 'total', 'commission', 'net', 'locked_at', 'settlement', 'allocation_hash', 'credited_at' );
		$operational_lock = array_key_exists( 'lock_version', $final );
		if ( $operational_lock ) {
			$final_keys = array_merge( $final_keys, array( 'lock_version', 'pricing_version', 'product', 'shipping', 'remaining' ) );
		}
		$this->keys( $final, $final_keys );
		$this->date( $final['locked_at'] );
		$this->date( $final['credited_at'] );
		if ( ! in_array( $final['settlement'], array( 'unresolved', 'pending', 'credited', 'not_required' ), true ) || ( null !== $final['allocation_hash'] && ( ! is_string( $final['allocation_hash'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $final['allocation_hash'] ) ) ) ) {
			throw new InvalidArgumentException( 'Invalid settlement state.' );
		}
		if ( ( 'test' === $case['mode'] && ( 'credited' === $final['settlement'] || null !== $final['allocation_hash'] || null !== $final['credited_at'] ) ) || ( 'not_required' === $final['settlement'] && ( $data['funds']['deposit']['amount'] > 0 || $data['funds']['balance']['amount'] > 0 ) ) ) {
			throw new InvalidArgumentException( 'Settlement cannot hide real funds or credit test cases.' );
		}
		if ( null !== $final['total'] ) {
			foreach ( array( 'total', 'commission', 'net' ) as $field ) {
				$this->integer( $final[ $field ], 0 );
			}
			$weights = array_column( $data['lines'], 'final_tenths' );
			$split = Amounts::commission( $final['total'] );
			if ( null === $final['locked_at'] || in_array( null, $weights, true ) ) {
				throw new InvalidArgumentException( 'Final total requires a locked weight and timestamp.' );
			}
			$expected = 2 === $data['format']
				? RequestPricingPolicy::finalLineAmounts( $request, array_combine( array_keys( $data['lines'] ), $weights ), $data['funds']['deposit']['amount'], $data['funds']['balance']['amount'] )
				: RequestPricingPolicy::finalAmounts( $request, $weights[0], $data['funds']['deposit']['amount'], $data['funds']['balance']['amount'] );
			if ( $final['total'] !== $expected['total'] || $final['commission'] !== $split['commission'] || $final['net'] !== $split['net'] ) {
				throw new InvalidArgumentException( 'Final totals differ from the pricing snapshot.' );
			}
			if ( $operational_lock ) {
				$adopted = isset( $data['spec4'] ) && 'final' === $data['spec4']['basis_kind'];
				if ( RequestPricingPolicy::OPERATIONAL_LOCK !== $final['lock_version'] || $request->pricingVersion() !== $final['pricing_version'] ) {
					throw new InvalidArgumentException( 'Final pricing policy cannot change.' );
				}
				if ( ! $adopted && ( 'unresolved' !== $final['settlement'] || null !== $final['allocation_hash'] || null !== $final['credited_at']
					|| null !== $data['manual']['balance'] || 0 !== $data['funds']['balance']['amount']
					|| ! in_array( $case['state'], array( FlowState::FINAL_WEIGHT_LOCKED, FlowState::ADMIN_REVIEW ), true ) || null !== $case['deadline_gmt'] ) ) {
					throw new InvalidArgumentException( 'Operational final locks cannot start a financial phase.' );
				}
				// The historic remaining component is the amount at weight lock, not
				// a mutable balance. Spec4 carries the current outstanding amount.
				if ( $adopted ) { $expected['remaining'] = Amounts::remaining( $final['total'], $data['funds']['deposit']['amount'] ); }
				foreach ( $expected as $component => $value ) {
					if ( $value !== $final[ $component ] ) {
						throw new InvalidArgumentException( 'Final component differs from its immutable pricing policy.' );
					}
				}
			} elseif ( RequestPricingPolicy::LEGACY !== $request->pricingVersion() ) {
				throw new InvalidArgumentException( 'New pricing requires explicit final components.' );
			}
		} elseif ( null !== $final['commission'] || null !== $final['net'] || null !== $final['locked_at'] ) {
			throw new InvalidArgumentException( 'Incomplete final pricing.' );
		} elseif ( $operational_lock ) {
			throw new InvalidArgumentException( 'Operational lock marker requires complete final pricing.' );
		}
		$this->keys( $data['recovery'], array( 'event_uuid', 'operation', 'last_error_code', 'attempts' ) );
		$this->integer( $data['recovery']['attempts'], 0, 1000 );
		if ( null !== $data['recovery']['event_uuid'] && ( ! is_string( $data['recovery']['event_uuid'] ) || ! wp_is_uuid( $data['recovery']['event_uuid'] ) ) ) {
			throw new InvalidArgumentException( 'Invalid recovery event UUID.' );
		}
		foreach ( array( 'operation', 'last_error_code' ) as $key ) {
			$value = $data['recovery'][ $key ];
			if ( null !== $value && ( ! is_string( $value ) || 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $value ) ) ) {
				throw new InvalidArgumentException( 'Recovery only accepts safe symbolic codes.' );
			}
		}
		$recovery_operations = array( 'manual_order_sync', 'manual_deposit_projection', 'manual_completion_projection', 'managed_completion_projection' );
		$has_recovery_identity = is_string( $data['recovery']['event_uuid'] )
			&& wp_is_uuid( $data['recovery']['event_uuid'] )
			&& is_string( $data['recovery']['operation'] )
			&& in_array( $data['recovery']['operation'], $recovery_operations, true );
		if ( 1 === $case['sync_pending'] && ! $has_recovery_identity ) {
			throw new InvalidArgumentException( 'Pending projection requires a scoped recovery identity.' );
		}
		if ( 0 === $case['sync_pending'] && ( null !== $data['recovery']['event_uuid'] || null !== $data['recovery']['operation'] || null !== $data['recovery']['last_error_code'] ) ) {
			throw new InvalidArgumentException( 'Completed projection cannot retain recovery identity or error.' );
		}
		$this->keys( $data['retention'], array( 'private_cleaned_at', 'evidence_files_purged' ) );
		$this->date( $data['retention']['private_cleaned_at'] );
		$this->integer( $data['retention']['evidence_files_purged'], 0, 1000000 );
		$this->json( $data );
	}

	private function validateChanges( array $before, array $after, PurchaseRequest $request, string $actor, int $actor_id, string $event ): void {
		$old = $before['data'];
		$new = $after['data'];
		if ( isset( $old['spec4'] ) && ! isset( $new['spec4'] ) ) { throw new InvalidArgumentException( 'Financial state cannot disappear.' ); }
		if ( isset( $new['spec4'] ) ) {
			Spec4Data::validateChanges( $old['spec4'] ?? null, $new['spec4'] );
			if ( ! isset( $old['spec4'] ) && ! in_array( $event, array( 'spec4_open', 'spec4_create_refund' ), true ) ) { throw new InvalidArgumentException( 'Financial adoption requires an explicit command.' ); }
			if ( 'vendor' === $actor && ( $old['spec4'] ?? null ) !== $new['spec4'] ) { throw new InvalidArgumentException( 'Vendors cannot change financial state.' ); }
			if ( 'buyer' === $actor && ( $old['spec4'] ?? null ) !== $new['spec4'] && ! in_array( $event, array( 'spec4_create_intent', 'manual_receipt_submitted' ), true ) ) { throw new InvalidArgumentException( 'Buyer cannot decide a financial outcome.' ); }
			if ( 'admin' === $actor && ( $old['spec4'] ?? null ) !== $new['spec4'] && ! AdminCapabilities::userCanManageFinance( $actor_id ) ) { throw new InvalidArgumentException( 'Financial decisions require finance capability.' ); }
		}
		$old_bindings = $this->lineEvidenceBindings( $old );
		$new_bindings = $this->lineEvidenceBindings( $new );
		foreach ( $old_bindings as $id => $binding ) {
			if ( ! isset( $new_bindings[ $id ] ) || $new_bindings[ $id ] !== $binding ) { throw new InvalidArgumentException( 'Evidence cannot move between rows or disappear.' ); }
		}
		$this->validateOperationalChanges( $old, $new, $actor, $actor_id, $event );
		$cleanup = 'system' === $actor && 'managed_cleanup' === $event && FlowState::isClosed( $after['state'] ) && in_array( $new['final']['settlement'], array( 'credited', 'not_required' ), true );
		$old_line_keys = array_keys( $old['lines'] );
		$new_line_keys = array_keys( $new['lines'] );
		if ( $old['format'] !== $new['format'] || $old_line_keys !== $new_line_keys ) {
			throw new InvalidArgumentException( 'Managed line identity cannot change.' );
		}
		if ( $new['delivery']['generation'] < $old['delivery']['generation'] || ( $new['delivery']['generation'] === $old['delivery']['generation'] && $new['delivery']['attempts'] < $old['delivery']['attempts'] ) ) {
			throw new InvalidArgumentException( 'Delivery generation/counter cannot decrease.' );
		}
		if ( $new['delivery']['generation'] > $old['delivery']['generation'] && $old['delivery']['generation'] > 0 && ( 'admin' !== $actor || ! AdminCapabilities::userCanOperate( $actor_id ) ) ) {
			throw new InvalidArgumentException( 'Only an operations admin can reissue a code.' );
		}
		if ( 5 === $new['delivery']['attempts'] && null === $new['delivery']['delivered_at'] && ! in_array( $after['state'], array( 'admin_review', 'return_review', 'cancelled' ), true ) ) {
			throw new InvalidArgumentException( 'Fifth code failure requires review.' );
		}
		if ( null !== $old['delivery']['delivered_at'] && $old['delivery']['delivered_at'] !== $new['delivery']['delivered_at'] ) {
			throw new InvalidArgumentException( 'A recorded handoff cannot be removed or rewritten.' );
		}
		if ( null === $old['delivery']['delivered_at'] && null !== $new['delivery']['delivered_at'] ) {
			if ( 'vendor' !== $actor || $actor_id !== $request->vendorId() || 'managed_deliver' !== $event
				|| ! in_array( $before['state'], array( FlowState::READY_FOR_PICKUP, FlowState::IN_TRANSIT ), true )
				|| null === $old['delivery']['hmac'] || $old['delivery']['attempts'] >= 5
				|| $old['delivery']['generation'] !== $new['delivery']['generation'] || null !== $new['delivery']['hmac']
				|| null !== $new['delivery']['encrypted_code'] || FlowState::BUYER_REVIEW !== $after['state'] ) {
				throw new InvalidArgumentException( 'Handoff requires the vendor to consume the current unlocked delivery code.' );
			}
		}
		if ( 'credited' === $old['final']['settlement'] && $old['final'] !== $new['final'] ) {
			throw new InvalidArgumentException( 'Credited financial facts are immutable.' );
		}
		if ( null !== $old['final']['locked_at'] && ( $old['final']['total'] !== $new['final']['total'] || $old['final']['locked_at'] !== $new['final']['locked_at'] || array_column( $old['lines'], 'final_tenths' ) !== array_column( $new['lines'], 'final_tenths' ) ) ) {
			throw new InvalidArgumentException( 'Final financial weights are immutable.' );
		}
		if ( isset( $old['final']['lock_version'] ) && $old['final'] !== $new['final'] ) {
			$mutable = array_flip( array( 'settlement', 'allocation_hash', 'credited_at' ) );
			if ( ! isset( $new['spec4'] ) || array_diff_key( $old['final'], $mutable ) !== array_diff_key( $new['final'], $mutable ) ) {
				throw new InvalidArgumentException( 'Operational final components and policy cannot be rewritten.' );
			}
		}
		foreach ( array( 'deposit', 'balance' ) as $phase ) {
			$fund = $new['funds'][ $phase ];
			if ( $fund['amount'] < $old['funds'][ $phase ]['amount'] || ( $old['funds'][ $phase ]['amount'] > 0 && $fund !== $old['funds'][ $phase ] ) ) {
				throw new InvalidArgumentException( 'Confirmed funds cannot be rewritten.' );
			}
			if ( $fund !== $old['funds'][ $phase ] ) {
				if ( ! ( 'admin' === $actor && AdminCapabilities::userCanManageFinance( $actor_id ) ) && 'system' !== $actor ) {
					throw new InvalidArgumentException( 'Only finance confirmation can change funds.' );
				}
				$payment = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', $this->schema->payments_table(), $fund['payment_id'] ), ARRAY_A );
				if ( ! is_array( $payment ) || (int) $payment['request_id'] !== $request->id() || (int) $payment['order_id'] !== $request->wcOrderId() || (int) $payment['paid_amount_toman'] !== $fund['amount'] || (int) $payment['expected_amount_toman'] !== $fund['amount'] || $payment['provider'] !== $fund['provider'] || $payment['phase'] !== $phase || ! in_array( $payment['status'], array( 'captured', 'late_accepted' ), true ) ) {
					throw new InvalidArgumentException( 'Funds require an actual confirmed payment row.' );
				}
			}
			if ( null !== $old['manual'][ $phase ] && null !== $new['manual'][ $phase ] ) {
				$history = $old['manual'][ $phase ]['receipt_history'];
				if ( $history !== array_slice( $new['manual'][ $phase ]['receipt_history'], 0, count( $history ) ) ) {
					throw new InvalidArgumentException( 'Receipt history is append-only.' );
				}
				foreach ( array( 'offer_id', 'amount', 'offered_at' ) as $field ) {
					if ( $old['manual'][ $phase ][ $field ] !== $new['manual'][ $phase ][ $field ] ) {
						throw new InvalidArgumentException( 'An offered obligation is authoritative.' );
					}
				}
				if ( $old['manual'][ $phase ]['account_encrypted'] !== $new['manual'][ $phase ]['account_encrypted'] && ! ( $cleanup && '' === $new['manual'][ $phase ]['account_encrypted'] ) ) {
					throw new InvalidArgumentException( 'A manual account snapshot cannot change.' );
				}
			} elseif ( null !== $old['manual'][ $phase ] ) {
				throw new InvalidArgumentException( 'Manual obligations cannot disappear.' );
			}
		}
		$spec4_credit = isset( $new['spec4'] ) && 'spec4_vendor_credit' === $event && 'system' === $actor
			&& in_array( $new['spec4']['substate'], array( 'completed', 'closed' ), true ) && array() !== $new['spec4']['finance_action_ids'];
		if ( $spec4_credit ) {
			$site = 0; $vendor = 0;
			$return = 'nonpayment_return' === $new['spec4']['trigger'];
			$expected = $return ? \Zarbalman\Core\Domain\Finance\Spec4AllocationPolicy::nonpaymentReturn( $new['funds']['deposit']['amount'], $new['funds']['balance']['amount'], null !== $new['spec4']['return_confirmed_at_gmt'] ) : \Zarbalman\Core\Domain\Finance\Spec4AllocationPolicy::completed( $new['final']['total'], $new['funds']['deposit']['amount'], $new['funds']['balance']['amount'] );
			foreach ( $new['spec4']['finance_action_ids'] as $id ) {
				$row = $this->db->get_row( $this->db->prepare( 'SELECT a.*,l.amount_toman AS ledger_amount,l.request_id AS ledger_request FROM %i a INNER JOIN %i l ON l.id = a.ledger_entry_id WHERE a.id = %d FOR UPDATE', $this->schema->financial_actions_table(), $this->schema->ledger_table(), $id ), ARRAY_A );
				if ( ! is_array( $row ) || '' !== $this->db->last_error || (int) $row['request_id'] !== $request->id() || (int) $row['order_id'] !== $request->wcOrderId() || (int) $row['ledger_request'] !== $request->id() || 'reconciled' !== $row['status'] || ! in_array( $row['action_type'], $return ? array( 'nonpayment_site', 'nonpayment_vendor' ) : array( 'sale_site', 'sale_vendor' ), true ) || (int) $row['ledger_amount'] !== FinancialActionRepository::amount( $row ) ) { throw new InvalidArgumentException( 'Seller credit requires reconciled allocations and matching ledger facts.' ); }
				$site += (int) $row['site_amount_toman']; $vendor += (int) $row['vendor_amount_toman'];
			}
			if ( $site !== $expected['site'] || $vendor !== $expected['vendor'] ) { throw new InvalidArgumentException( 'Seller allocation totals differ.' ); }
		}
		if ( $new['final']['settlement'] !== $old['final']['settlement'] && 'credited' === $new['final']['settlement'] && ( 'live' !== $after['mode'] || ( ! $spec4_credit && ( 'admin' !== $actor || ! AdminCapabilities::userCanManageFinance( $actor_id ) || 'completed' !== $after['state'] ) ) || null === $new['final']['allocation_hash'] || null === $new['final']['credited_at'] ) ) {
			throw new InvalidArgumentException( 'Seller credit requires separate finance authorization.' );
		}
		if ( 'live' === $after['mode'] && 'completed' === $after['state'] && ( null === $new['final']['total'] || 0 !== Amounts::remaining( $new['final']['total'], $new['funds']['deposit']['amount'], $new['funds']['balance']['amount'] ) ) ) {
			throw new InvalidArgumentException( 'Completion requires exact confirmed funds.' );
		}
		if ( $old['delivery']['address_kind'] !== $new['delivery']['address_kind'] || $old['delivery']['address_encrypted'] !== $new['delivery']['address_encrypted'] ) {
			$initial_system_snapshot = null === $old['delivery']['address_kind'] && 'managed_sync_deposit' === $event && 'system' === $actor && null !== $new['delivery']['address_encrypted'];
			$initial_pickup_snapshot = null === $old['delivery']['address_kind'] && 'managed_ready_pickup' === $event && 'vendor' === $actor && 'vendor_pickup' === $new['delivery']['address_kind'] && null !== $new['delivery']['address_encrypted'];
			$final_cleanup = $cleanup && $old['delivery']['address_kind'] === $new['delivery']['address_kind'] && null === $new['delivery']['address_encrypted'];
			if ( ! $initial_system_snapshot && ! $initial_pickup_snapshot && ! $final_cleanup ) {
				throw new InvalidArgumentException( 'Delivery address snapshot cannot change.' );
			}
		}
		if ( $old['retention'] !== $new['retention'] ) {
			if ( ! $cleanup || ( null !== $old['retention']['private_cleaned_at'] && $old['retention']['private_cleaned_at'] !== $new['retention']['private_cleaned_at'] ) || $new['retention']['evidence_files_purged'] < $old['retention']['evidence_files_purged'] ) {
				throw new InvalidArgumentException( 'Private retention facts are monotonic and system-owned.' );
			}
		}
	}

	private function requestFields( PurchaseRequest $request, array $fields, array $case, string $actor, int $actor_id ): void {
		$allowed = array( 'current_state', 'wc_order_id', 'payment_ready', 'needs_reconciliation', 'stock_reserved', 'stock_released', 'is_paused', 'pause_reason', 'paused_at_gmt', 'paused_remaining_seconds', 'deposit_deadline_gmt', 'delivery_payload_encrypted' );
		if ( array_diff( array_keys( $fields ), $allowed ) ) {
			throw new InvalidArgumentException( 'Forbidden request field.' );
		}
		$formats = array();
		foreach ( $fields as $name => $value ) {
			if ( in_array( $name, array( 'payment_ready', 'needs_reconciliation', 'stock_reserved', 'stock_released', 'is_paused' ), true ) ) {
				$this->integer( $value, 0, 1 );
				$formats[] = '%d';
			} elseif ( in_array( $name, array( 'wc_order_id', 'paused_remaining_seconds' ), true ) ) {
				if ( null !== $value ) {
					$this->integer( $value, 'wc_order_id' === $name ? 1 : 0 );
				}
				if ( 'wc_order_id' === $name && ( null === $value || ( null !== $request->wcOrderId() && $value !== $request->wcOrderId() ) ) ) {
					throw new InvalidArgumentException( 'Order binding cannot be replaced.' );
				}
				$formats[] = '%d';
			} elseif ( 'current_state' === $name ) {
				if ( ! in_array( $value, array( RequestState::SUPPLIER_ACCEPTED, RequestState::DEPOSIT_PENDING, RequestState::DEPOSIT_PAID, RequestState::ADMIN_REVIEW, RequestState::CANCELLED ), true ) || ( RequestState::DEPOSIT_PAID === $request->state() && $value !== RequestState::DEPOSIT_PAID ) ) {
					throw new InvalidArgumentException( 'Invalid managed request state update.' );
				}
				if ( RequestState::DEPOSIT_PAID === $value && $request->state() !== $value && ( 'live' !== $case['mode'] || $case['data']['funds']['deposit']['amount'] < 1 || ( 'system' !== $actor && ! AdminCapabilities::userCanManageFinance( $actor_id ) ) ) ) {
					throw new InvalidArgumentException( 'Deposit state requires confirmed funds.' );
				}
				$formats[] = '%s';
			} elseif ( 'pause_reason' === $name ) {
				$this->text( $value, 1000, true );
				$formats[] = '%s';
			} elseif ( 'delivery_payload_encrypted' === $name ) {
				if ( 'system' !== $actor || '' !== $value || ! FlowState::isClosed( $case['state'] ) || null === $case['data']['retention']['private_cleaned_at'] ) {
					throw new InvalidArgumentException( 'Delivery payload can only be removed by final retention cleanup.' );
				}
				$formats[] = '%s';
			} else {
				$this->date( $value );
				$formats[] = '%s';
			}
		}
		if ( isset( $fields['is_paused'] ) && ( 'admin' !== $actor || ! AdminCapabilities::userCanOperate( $actor_id ) || (bool) $fields['is_paused'] !== $case['data']['pause']['active'] || empty( $fields['pause_reason'] ) ) ) {
			throw new InvalidArgumentException( 'Pause requires an operations-admin reason.' );
		}
		if ( array() === $fields ) {
			return;
		}
		if ( $request->version() >= PHP_INT_MAX ) {
			throw new InvalidArgumentException( 'Request version exhausted.' );
		}
		$fields['version'] = $request->version() + 1;
		$fields['updated_at_gmt'] = current_time( 'mysql', true );
		$formats[] = '%d';
		$formats[] = '%s';
		if ( 1 !== $this->db->update( $this->schema->purchase_requests_table(), $fields, array( 'id' => $request->id(), 'version' => $request->version() ), $formats, array( '%d', '%d' ) ) ) {
			throw new RuntimeException( 'Request CAS failed.' );
		}
	}

	/** The canonical aggregate is the row binding; history retains that binding forever. */
	private function lineEvidenceBindings( array $data ): array {
		$bindings = array();
		$bind = static function ( array $ids, string $line_id, string $purpose ) use ( &$bindings ): void {
			foreach ( $ids as $id ) {
				if ( isset( $bindings[ $id ] ) ) { throw new InvalidArgumentException( 'Evidence cannot be shared or reused.' ); }
				$bindings[ $id ] = array( $line_id, $purpose );
			}
		};
		foreach ( $data['lines'] as $line_id => $line ) {
			foreach ( array( 'vendor_evidence' => 'vendor_weight', 'buyer_evidence' => 'buyer_weight', 'buyer_objection' => 'buyer_objection', 'vendor_objection' => 'vendor_objection' ) as $field => $purpose ) {
				$bind( $line[ $field ], (string) $line_id, $purpose );
			}
		}
		foreach ( $data['operations']['evidence_history'] ?? array() as $entry ) {
			$line_id = $entry['line_id'] ?? ( 1 === $data['format'] ? (string) array_key_first( $data['lines'] ) : null );
			if ( ! is_string( $line_id ) || ! array_key_exists( $line_id, $data['lines'] ) ) { throw new InvalidArgumentException( 'Archived evidence requires its original row.' ); }
			$bind( $entry['evidence'], $line_id, $entry['purpose'] );
		}
		return $bindings;
	}

	private function assertEvidence( array $case, PurchaseRequest $request ): void {
		$groups = array();
		foreach ( $case['data']['spec4']['evidence'] ?? array() as $entry ) { $groups[] = array( array( $entry['id'] ), $entry['actor_id'], $entry['purpose'] ); }
		foreach ( $case['data']['lines'] as $line ) {
			$groups[] = array( $line['vendor_evidence'], $request->vendorId(), 'vendor_weight' );
			$groups[] = array( $line['buyer_evidence'], $request->buyerId(), 'buyer_weight' );
			$groups[] = array( $line['buyer_objection'], $request->buyerId(), 'buyer_objection' );
			$groups[] = array( $line['vendor_objection'], $request->vendorId(), 'vendor_objection' );
		}
		foreach ( array( 'deposit', 'balance' ) as $phase ) {
			$groups[] = array( $case['data']['manual'][ $phase ]['receipt_ids'] ?? array(), $request->buyerId(), 'manual_' . $phase . '_receipt' );
			foreach ( $case['data']['manual'][ $phase ]['receipt_history'] ?? array() as $history ) {
				$groups[] = array( $history['receipt_ids'] ?? array(), $request->buyerId(), 'manual_' . $phase . '_receipt' );
			}
		}
		foreach ( $case['data']['operations']['evidence_history'] ?? array() as $entry ) {
			$groups[] = array( $entry['evidence'], str_starts_with( $entry['purpose'], 'buyer_' ) ? $request->buyerId() : $request->vendorId(), $entry['purpose'] );
		}
		foreach ( $groups as [ $ids, $owner, $purpose ] ) {
			foreach ( $ids as $id ) {
				// These immutable upload bindings are checked INSIDE the aggregate
				// transaction; making the IDs canonical is the atomic bind operation.
				if ( ! ManagedEvidence::matchesOwner( ManagedEvidence::metadata( $id ), $request->id(), $owner, $purpose, $request->wcOrderId() ?? 0 ) ) {
					throw new InvalidArgumentException( 'Evidence binding mismatch.' );
				}
			}
		}
	}

	private function validateOperationalChanges( array $old, array $new, string $actor, int $actor_id, string $event ): void {
		$previous = $old['operations'] ?? null;
		$next = $new['operations'] ?? null;
		$is_admin = 'admin' === $actor && AdminCapabilities::userCanOperate( $actor_id );
		if ( null === $previous && null !== $next && ( 'system' !== $actor || 'managed_sync_deposit' !== $event ) ) { throw new InvalidArgumentException( 'Operational adoption requires system bootstrap.' ); }
		if ( null !== $previous ) {
			if ( null === $next || $previous['bootstrapped_at'] !== $next['bootstrapped_at'] || ! OperationsData::isPrefix( $previous['evidence_history'], $next['evidence_history'] ) ) { throw new InvalidArgumentException( 'Operational provenance cannot disappear.' ); }
			if ( $previous['evidence_history'] !== $next['evidence_history'] && ( ! $is_admin || ! in_array( $event, array( 'managed_reopen_buyer', 'managed_reopen_seller', 'managed_reopen_preparation' ), true ) ) ) { throw new InvalidArgumentException( 'Only an audited reopen can archive evidence.' ); }
			$old_window = $previous['window']; $new_window = $next['window'];
			if ( ! OperationsData::isPrefix( $old_window['history'], $new_window['history'] ) || $new_window['generation'] < $old_window['generation'] ) { throw new InvalidArgumentException( 'Delivery window history is immutable.' ); }
			if ( $new_window['generation'] !== $old_window['generation'] && ( ! $is_admin || 'managed_extend_delivery' !== $event || $new_window['generation'] !== $old_window['generation'] + 1 ) ) { throw new InvalidArgumentException( 'Only an operations admin can extend delivery.' ); }
			if ( $new_window['generation'] === $old_window['generation'] && ( $old_window['history'] !== $new_window['history'] || $old_window['start_gmt'] !== $new_window['start_gmt'] || $old_window['end_gmt'] !== $new_window['end_gmt'] ) ) { throw new InvalidArgumentException( 'Delivery dates cannot change in-place.' ); }
			if ( $new_window['generation'] === $old_window['generation'] ) {
				foreach ( array( 'reminded_at' => 'managed_delivery_reminder', 'overdue_at' => 'managed_delivery_overdue' ) as $field => $expected_event ) {
					if ( $old_window[ $field ] !== $new_window[ $field ] && ( null !== $old_window[ $field ] || ! is_string( $new_window[ $field ] ) || 'system' !== $actor || $expected_event !== $event ) ) { throw new InvalidArgumentException( 'Delivery alert facts are immutable and system-owned.' ); }
				}
				if ( $old_window['resume_state'] !== $new_window['resume_state'] && ( 'system' !== $actor || 'managed_delivery_overdue' !== $event || null !== $old_window['resume_state'] ) ) { throw new InvalidArgumentException( 'Delivery recovery context cannot be forged.' ); }
			} else {
				$entry = end( $new_window['history'] );
				if ( $entry['actor_id'] !== $actor_id ) { throw new InvalidArgumentException( 'Delivery extension actor mismatch.' ); }
			}
			foreach ( array( 'prepared_at' => array( 'vendor', 'managed_record_weight', 'managed_reopen_preparation' ), 'buyer_submitted_at' => array( 'buyer', 'managed_propose_weight', 'managed_reopen_buyer' ), 'dispatched_at' => array( 'vendor', 'managed_dispatch', '' ) ) as $field => $rule ) {
				if ( $previous[ $field ] === $next[ $field ] ) { continue; }
				$submission = null === $previous[ $field ] && is_string( $next[ $field ] ) && $actor === $rule[0] && $event === $rule[1];
				$reopen = null !== $previous[ $field ] && null === $next[ $field ] && $is_admin && $event === $rule[2];
				if ( ! $submission && ! $reopen ) { throw new InvalidArgumentException( 'Operational timestamps cannot be rewritten.' ); }
			}
		}
		// Existing weights and proofs may only be cleared by a matching admin
		// reopen, with the exact former evidence retained in the append-only archive.
		foreach ( $old['lines'] as $line_key => $old_line ) {
			$new_line = $new['lines'][ $line_key ] ?? null;
			if ( ! is_array( $new_line ) ) { continue; } // Identity guard below rejects missing rows.
			if ( null !== $old_line['final_tenths'] && $old_line['final_tenths'] !== $new_line['final_tenths'] ) { throw new InvalidArgumentException( 'A finalized row cannot change.' ); }
			if ( null === $old_line['final_tenths'] && null !== $new_line['final_tenths']
				&& ! ( ( 'vendor' === $actor && 'managed_accept_weight' === $event ) || ( $is_admin && 'managed_set_final_weight' === $event ) ) ) { throw new InvalidArgumentException( 'Final row weight requires an explicit decision.' ); }
			if ( 2 === $old['format'] ) {
				if ( $old_line['snapshot'] !== $new_line['snapshot'] ) { throw new InvalidArgumentException( 'Row snapshot is immutable.' ); }
				foreach ( array( 'vendor', 'buyer' ) as $owner ) {
					$field = $owner . '_submitted_at';
					if ( $old_line[ $field ] === $new_line[ $field ] ) { continue; }
					$submit = null === $old_line[ $field ] && is_string( $new_line[ $field ] ) && $actor === $owner && $event === ( 'vendor' === $owner ? 'managed_record_weight' : 'managed_propose_weight' );
					$reopen = null !== $old_line[ $field ] && null === $new_line[ $field ] && $is_admin && $event === ( 'vendor' === $owner ? 'managed_reopen_preparation' : 'managed_reopen_buyer' );
					if ( ! $submit && ! $reopen ) { throw new InvalidArgumentException( 'Row submission time is immutable.' ); }
				}
			}
			foreach ( array( 'vendor', 'buyer' ) as $role ) {
				foreach ( array( 'weight', 'objection' ) as $kind ) {
					$field = 'weight' === $kind ? $role . '_evidence' : $role . '_objection';
					$weight = 'weight' === $kind ? $old_line[ $role . '_tenths' ] : null;
					$new_weight = 'weight' === $kind ? $new_line[ $role . '_tenths' ] : null;
					if ( ( null === $weight && array() === $old_line[ $field ] ) || ( $old_line[ $field ] === $new_line[ $field ] && $weight === $new_weight ) ) { continue; }
					$expected_event = 'buyer' === $role ? 'managed_reopen_buyer' : ( 'weight' === $kind ? 'managed_reopen_preparation' : 'managed_reopen_seller' );
					if ( ! $is_admin || ( $expected_event !== $event && ! ( 'vendor' === $role && 'objection' === $kind && 'managed_reopen_preparation' === $event ) ) || null !== $new_weight || array() !== $new_line[ $field ] ) { throw new InvalidArgumentException( 'Submitted evidence cannot be overwritten.' ); }
					$found = false;
					foreach ( array_slice( $next['evidence_history'] ?? array(), count( $previous['evidence_history'] ?? array() ) ) as $entry ) {
						if ( (string) ( $entry['line_id'] ?? array_key_first( $old['lines'] ) ) === (string) $line_key && $entry['purpose'] === $role . '_' . $kind && $entry['weight_tenths'] === $weight && $entry['evidence'] === $old_line[ $field ] && $entry['reason'] === $old_line[ $role . '_reason' ] && $entry['archived_by'] === $actor_id ) { $found = true; }
					}
					if ( ! $found ) { throw new InvalidArgumentException( 'Reopening would discard previous evidence.' ); }
				}
			}
		}
	}

	private function notifications( PurchaseRequest $request, string $uuid, array $notifications ): void {
		if ( ! array_is_list( $notifications ) || count( $notifications ) > 20 ) {
			throw new InvalidArgumentException( 'Notification count exceeds bound.' );
		}
		foreach ( $notifications as $notice ) {
			$role = $notice['role'] ?? '';
			$id = $notice['user_id'] ?? 0;
			$allowed = is_int( $id ) && match ( $role ) {
				'buyer' => $id === $request->buyerId(),
				'vendor' => $id === $request->vendorId(),
				'admin' => AdminCapabilities::userCanOperate( $id ) || AdminCapabilities::userCanManageFinance( $id ),
				default => false,
			};
			$variables = $notice['variables'] ?? array();
			// Intentionally exclude free text/reason, URLs, contact data and codes.
			if ( ! $allowed || ! is_array( $variables ) || array_diff( array_keys( $variables ), array( 'request_public_id', 'quantity', 'deadline', 'amount_toman', 'generation', 'window_generation' ) ) ) {
				throw new InvalidArgumentException( 'Unsafe managed notification.' );
			}
			if ( ! $this->outbox->enqueue( $uuid, $request->id(), $role, $id, $notice['template'], $variables ) ) {
				throw new RuntimeException( 'Managed outbox failed.' );
			}
		}
	}

	private function safeMetadata( array $metadata ): void {
		if ( count( $metadata ) > 30 ) {
			throw new InvalidArgumentException( 'Managed metadata too large.' );
		}
		foreach ( $metadata as $name => $value ) {
			if ( ! is_string( $name ) || 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $name ) ) {
				throw new InvalidArgumentException( 'Invalid safe metadata key.' );
			}
			if ( 'line_id' === $name ) {
				if ( ! is_string( $value ) || 1 !== preg_match( '/\A(?:[1-9][0-9]*|request:[1-9][0-9]*|line:[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})\z/', $value ) ) { throw new InvalidArgumentException( 'Invalid audit row identity.' ); }
			} elseif ( str_ends_with( $name, '_hash' ) ) {
				if ( ! is_string( $value ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $value ) ) {
					throw new InvalidArgumentException( 'Metadata hashes must be SHA256.' );
				}
			} elseif ( ! in_array( $name, array( 'request_id', 'order_id', 'payment_id', 'financial_action_id', 'actor_id', 'offer_version', 'generation', 'attempts', 'amount_toman', 'total_toman', 'commission_toman', 'net_toman', 'weight_tenths', 'acknowledged', 'confirmed', 'version', 'count' ), true ) || ( ! is_bool( $value ) && ! is_int( $value ) ) || ( is_int( $value ) && $value < 0 ) ) {
				throw new InvalidArgumentException( 'Raw payloads are forbidden in managed metadata.' );
			}
		}
	}

	private function keys( mixed $array, array $keys ): void {
		if ( ! is_array( $array ) || count( $array ) !== count( $keys ) || array_diff( $keys, array_keys( $array ) ) ) {
			throw new InvalidArgumentException( 'Managed data shape mismatch.' );
		}
	}

	private function integer( mixed $value, int $min, int $max = PHP_INT_MAX ): void {
		if ( ! is_int( $value ) || $value < $min || $value > $max ) {
			throw new InvalidArgumentException( 'Managed integer outside bounds.' );
		}
	}

	private function ids( mixed $ids, int $max ): void {
		if ( ! is_array( $ids ) || ! array_is_list( $ids ) || count( $ids ) > $max || count( array_unique( $ids, SORT_REGULAR ) ) !== count( $ids ) ) {
			throw new InvalidArgumentException( 'Invalid evidence selection.' );
		}
		foreach ( $ids as $id ) {
			$this->integer( $id, 1 );
		}
	}

	private function text( mixed $text, int $max, bool $required = false ): void {
		if ( ! is_string( $text ) || strlen( $text ) > $max || ( $required && '' === trim( $text ) ) ) {
			throw new InvalidArgumentException( 'Invalid managed text.' );
		}
	}

	private function date( mixed $date ): void {
		if ( null === $date ) {
			return;
		}
		$parsed = is_string( $date ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date, new \DateTimeZone( 'UTC' ) ) : false;
		if ( ! $parsed || $parsed->format( 'Y-m-d H:i:s' ) !== $date ) {
			throw new InvalidArgumentException( 'Invalid UTC datetime.' );
		}
	}

	private function json( array $data ): string {
		$json = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR, 32 );
		if ( strlen( $json ) > 262144 ) {
			throw new InvalidArgumentException( 'Managed payload exceeds 256 KiB.' );
		}
		return $json;
	}

	private function query( string $sql ): void {
		if ( false === $this->db->query( $sql ) ) {
			throw new RuntimeException( 'Managed transaction statement failed.' );
		}
	}

	private function error( string $code ): WP_Error {
		return new WP_Error( 'zbm_managed_' . $code, 'commit_unknown' === $code
			? __( 'نتیجه ثبت قطعی روشن نیست؛ فقط با همان شناسه اقدام دوباره بررسی کنید.', 'zarbalman-core' )
			: __( 'عملیات پرونده ثبت نشد؛ وضعیت و دسترسی را دوباره بررسی کنید.', 'zarbalman-core' ) );
	}
}
