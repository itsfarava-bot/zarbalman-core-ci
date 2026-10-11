<?php

declare(strict_types=1);

namespace Zarbalman\Core\Application;

use Throwable;
use WP_Error;
use Zarbalman\Core\Domain\Finance\Spec4AllocationPolicy;
use Zarbalman\Core\Domain\Finance\Spec4Data;
use Zarbalman\Core\Domain\ManagedFlow\FlowState;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Infrastructure\{DependencyHealth, FinancialActionRepository, LedgerRepository, ManagedCaseRepository, ManagedEvidence, PaymentRepository, ProtectedText, PurchaseRequestRepository};
use Zarbalman\Core\Integration\DokanManualSettlementAdapter;
use Zarbalman\Core\Security\AdminCapabilities;

/** Final-weight finance, explicit return decisions and manually reconciled refunds. */
final class Spec4FinanceService {
	public function __construct(
		private readonly PurchaseRequestRepository $requests, private readonly ManagedCaseRepository $cases,
		private readonly FinancialActionRepository $actions, private readonly PaymentRepository $payments,
		private readonly LedgerRepository $ledger, private readonly DokanManualSettlementAdapter $dokan,
		private readonly ManagedEvidence $evidence, private readonly ProtectedText $protected,
		private readonly DependencyHealth $health, private readonly ManualPaymentSettings $settings
	) {}

	/** Called after a committed final-weight event; it never calls a payment provider. */
	public function open( int $request_id ): array|WP_Error {
		if ( $error = \Zarbalman\Core\Infrastructure\Migrator::financeAvailabilityError() ) { return $error; }
		$case = $this->cases->find( $request_id );
		if ( ! $case || 'live' !== $case['mode'] ) { return $this->error( 'case' ); }
		if ( isset( $case['data']['spec4'] ) ) { return $case; }
		$key = self::key( 'open|' . $request_id );
		return $this->cases->mutate( $request_id, $case['version'], 'system', 0, $key, 'spec4_open', array(),
			function ( PurchaseRequest $request, array $current ): array|WP_Error {
				if ( ! $this->operable( $request, $current ) || ! $this->cases->depositVerified( $request, $current, true )
					|| FlowState::FINAL_WEIGHT_LOCKED !== $current['state'] || null === $current['data']['final']['locked_at'] || null === $current['data']['delivery']['delivered_at']
					|| 'unresolved' !== $current['data']['final']['settlement'] || null !== $current['data']['manual']['balance'] || 0 !== $current['data']['funds']['balance']['amount'] ) { return $this->error( 'basis' ); }
				$current['data']['spec4'] = Spec4Data::initial( $request, $current, current_time( 'mysql', true ), wp_generate_uuid4() );
				$this->ledger->provisionalShares( $request->id(), $request->wcOrderId(), $current['data']['funds']['deposit']['payment_id'], $request->vendorId(), $current['data']['funds']['deposit']['amount'] );
				$remaining = $current['data']['spec4']['remaining_toman'];
				if ( $remaining < 0 ) {
					$current['state'] = FlowState::ADMIN_REVIEW;
					$current['data']['spec4']['substate'] = 'payment_review';
					$current['data']['spec4']['reconciliation_status'] = 'review';
				} elseif ( 0 === $remaining ) {
					$current['state'] = FlowState::COMPLETED;
					$current['data']['spec4']['substate'] = 'completed';
					$current['data']['final']['settlement'] = 'pending';
					$current['deadline_gmt'] = null;
					self::queueCompletionProjection( $current );
				} elseif ( $this->routeConfigured() ) {
					$current['state'] = FlowState::BALANCE_DUE;
					$current['data']['spec4']['substate'] = 'balance_due';
					$current['deadline_gmt'] = gmdate( 'Y-m-d H:i:s', time() + Spec4Data::BALANCE_SECONDS );
				}
				$notices = match ( $current['data']['spec4']['substate'] ) { 'balance_due' => $this->buyerNotice( $request, 'spec4_balance_ready', $current ), 'payment_review' => $this->adminNotices( 'spec4_payment_review' ), 'completed' => $this->pairNotices( $request, 'spec4_payment_completed' ), default => array() };
				return $this->result( $current, array( 'amount_toman' => max( 0, $remaining ) ), $notices );
			}
		);
	}

	/** Latest state is always checked inside the same transaction, including old scheduler jobs. */
	public function tick( int $request_id ): array|WP_Error {
		$current = $this->cases->find( $request_id );
		if ( ! $current ) { return $this->error( 'case' ); }
		if ( ! isset( $current['data']['spec4'] ) ) { $current = $this->open( $request_id ); }
		if ( is_wp_error( $current ) ) { return $current; }
		$substate = $current['data']['spec4']['substate'];
		if ( in_array( $substate, array( 'completed', 'return_confirmed' ), true ) ) {
			$result = $this->allocate( $request_id, $current );
			if ( ! is_wp_error( $result ) && 'credited' === $result['data']['final']['settlement'] && class_exists( \WeDevs\Dokan\Withdraw\WithdrawCache::class ) ) {
				$request = $this->requests->find( $request_id );
				if ( $request ) { \WeDevs\Dokan\Withdraw\WithdrawCache::delete( $request->vendorId() ); }
			}
			return $result;
		}
		$ready = 'ready' === $substate && $this->routeConfigured();
		$expired = in_array( $substate, array( 'balance_due', 'warning' ), true ) && is_string( $current['deadline_gmt'] ) && $current['deadline_gmt'] <= current_time( 'mysql', true );
		if ( ! $ready && ! $expired ) { return $current; }
		$event = $ready ? 'spec4_balance_opened' : ( 'warning' === $substate ? 'spec4_warning_expired' : 'spec4_remainder_expired' );
		return $this->cases->mutate( $request_id, $current['version'], 'system', 0, self::key( $event . '|' . $request_id . '|' . $current['version'] ), $event, array(),
			function ( PurchaseRequest $request, array $case ) use ( $ready, $substate ): array|WP_Error {
				if ( ! $this->operable( $request, $case ) || $substate !== $case['data']['spec4']['substate'] ) { return $this->error( 'state' ); }
				if ( $ready ) {
					if ( ! $this->routeConfigured() ) { return $this->error( 'route' ); }
					$case['state'] = FlowState::BALANCE_DUE;
					$case['data']['spec4']['substate'] = 'balance_due';
					$case['deadline_gmt'] = gmdate( 'Y-m-d H:i:s', time() + Spec4Data::BALANCE_SECONDS );
					return $this->result( $case, array(), $this->buyerNotice( $request, 'spec4_balance_ready', $case ) );
				}
				if ( ! is_string( $case['deadline_gmt'] ) || $case['deadline_gmt'] > current_time( 'mysql', true ) ) { return $this->error( 'deadline' ); }
				$case['data']['spec4']['substate'] = 'warning' === $substate ? 'warning_elapsed' : 'contact_due';
				$case['state'] = FlowState::ADMIN_REVIEW;
				$case['deadline_gmt'] = null;
				return $this->result( $case, array(), $this->adminNotices( 'spec4_contact_due' ) );
			}
		);
	}

	/** Uploads happen before the transaction; only verified immutable upload IDs are bound inside it. */
	public function act( int $request_id, int $version, int $admin_id, string $key, string $action, string $reason, array $payload = array(), mixed $files = null ): array|WP_Error {
		if ( $error = \Zarbalman\Core\Infrastructure\Migrator::financeAvailabilityError() ) { return $error; }
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( ! AdminCapabilities::userCanManageFinance( $admin_id ) || ! wp_is_uuid( $key ) || '' === $reason || strlen( $reason ) > 1000
			|| ! in_array( $action, array( 'contact', 'confirm_return', 'create_refund', 'approve_action', 'execute_action', 'fail_action', 'reconcile_action' ), true ) ) { return $this->error( 'authorization' ); }
		$request = $this->requests->find( $request_id );
		$case = $this->cases->find( $request_id );
		if ( ! $request || ! $case || ! $this->operable( $request, $case ) ) { return $this->error( 'stale' ); }
		$purpose = match ( $action ) { 'confirm_return' => 'finance_return', 'create_refund' => 'finance_refund', 'execute_action' => 'finance_execution', default => null };
		if ( 'execute_action' === $action ) {
			$execution = $this->actions->get( is_int( $payload['financial_action_id'] ?? null ) ? $payload['financial_action_id'] : 0, $request_id );
			if ( ! $execution ) { return $this->error( 'action_binding' ); }
			if ( str_ends_with( $execution['action_type'], '_site' ) ) { $purpose = null; }
		}
		$ids = null === $purpose ? array() : $this->evidence->upload( $request_id, $request->wcOrderId() ?? 0, $admin_id, $purpose, $files );
		if ( is_wp_error( $ids ) ) { return $ids; }
		$destination = '';
		if ( 'create_refund' === $action ) {
			$destination = strtr( is_string( $payload['iban'] ?? null ) ? $payload['iban'] : '', array_combine( preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ), str_split( '01234567890123456789' ) ) );
			$destination = strtoupper( preg_replace( '/\s+/', '', $destination ) ?? '' );
			if ( ! self::validIban( $destination ) ) { return $this->error( 'destination' ); }
		}
		$evidence_hashes = array_map( static fn ( int $id ): string => ManagedEvidence::metadata( $id )['sha256'], $ids );
		$fingerprint = array( 'actor_id' => $admin_id, 'reason_hash' => hash( 'sha256', $reason ), 'payload_hash' => hash( 'sha256', wp_json_encode( array( $payload, $evidence_hashes ), JSON_THROW_ON_ERROR ) ) );
		$result = $this->cases->mutate( $request_id, $version, 'admin', $admin_id, $key, 'spec4_' . $action, $fingerprint,
			function ( PurchaseRequest $locked, array $current ) use ( $admin_id, $action, $reason, $payload, $ids, $purpose, $destination ): array|WP_Error {
				if ( ! $this->operable( $locked, $current ) || ! $this->cases->depositVerified( $locked, $current, true ) ) { return $this->error( 'state' ); }
				if ( null !== $purpose && ! $this->evidence->assertOwned( $ids, $locked->id(), $admin_id, $purpose, 1, 4 ) ) { return $this->error( 'evidence' ); }
				if ( ! isset( $current['data']['spec4'] ) ) {
					if ( 'create_refund' !== $action ) { return $this->error( 'not_open' ); }
					$current['data']['spec4'] = Spec4Data::initial( $locked, $current, current_time( 'mysql', true ), wp_generate_uuid4(), null === $current['data']['final']['locked_at'] ? 'estimate' : 'final' );
				}
				$finance =& $current['data']['spec4'];
				$now = current_time( 'mysql', true );
				$encrypted_reason = $this->protected->encrypt( 'finance_reason', $locked->id(), $finance['offer_id'], $reason );
				$notices = array();
				switch ( $action ) {
					case 'contact':
						if ( ! in_array( $finance['substate'], array( 'contact_due', 'warning_elapsed' ), true ) || null !== $finance['trigger'] ) { return $this->error( 'contact' ); }
						$finance['contact_history'][] = array( 'actor_id' => $admin_id, 'at_gmt' => $now, 'reason_encrypted' => $encrypted_reason );
						$finance['warning_started_at_gmt'] = $now;
						$finance['warning_deadline_gmt'] = gmdate( 'Y-m-d H:i:s', time() + Spec4Data::WARNING_SECONDS );
						$finance['substate'] = 'warning';
						$current['state'] = FlowState::BALANCE_DUE;
						$current['deadline_gmt'] = $finance['warning_deadline_gmt'];
						$notices = array_merge( $this->buyerNotice( $locked, 'spec4_warning', $current ), $this->adminNotices( 'spec4_warning', array( 'deadline' => $current['deadline_gmt'] ) ) );
						break;
					case 'confirm_return':
						if ( 'warning_elapsed' !== $finance['substate'] || null !== $finance['trigger'] || ! $finance['contact_history'] || ! is_string( $finance['warning_deadline_gmt'] ) || $finance['warning_deadline_gmt'] > $now
							|| 0 !== $current['data']['funds']['balance']['amount'] || 'submitted' === ( $current['data']['manual']['balance']['status'] ?? null ) || $this->payments->hasUnresolvedOnlineAttempt( $locked->id(), 'balance' ) ) { return $this->error( 'return' ); }
						$finance['trigger'] = 'nonpayment_return';
						$finance['substate'] = 'return_confirmed';
						$finance['return_confirmed_at_gmt'] = $now;
						$finance['return_evidence_ids'] = $ids;
						$finance['contact_history'][] = array( 'actor_id' => $admin_id, 'at_gmt' => $now, 'reason_encrypted' => $encrypted_reason );
						$current['state'] = FlowState::RETURN_REVIEW;
						$current['deadline_gmt'] = null;
						$notices = $this->pairNotices( $locked, 'spec4_return_confirmed' );
						break;
					case 'create_refund':
						$trigger = $payload['trigger'] ?? '';
						if ( null !== $finance['trigger'] || $finance['finance_action_ids'] || 'credited' === $current['data']['final']['settlement'] || ! in_array( $trigger, array( 'seller_incapacity', 'paid_cancellation' ), true )
							|| ( 0 === $current['data']['funds']['balance']['amount'] && $this->payments->hasUnresolvedOnlineAttempt( $locked->id(), 'balance' ) ) || 'submitted' === ( $current['data']['manual']['balance']['status'] ?? null ) ) { return $this->error( 'refund' ); }
						$paid = $current['data']['funds']['deposit']['amount'] + $current['data']['funds']['balance']['amount'];
						$allocation = Spec4AllocationPolicy::refund( $finance['basis_toman'], $paid, $trigger );
						$prefix = 'seller_incapacity' === $trigger ? 'incapacity' : 'cancellation';
						$bank = array( 'ciphertext' => $this->protected->encrypt( 'refund_destination', $locked->id(), $finance['offer_id'], $destination ), 'fingerprint' => hash_hmac( 'sha256', $destination, wp_salt( 'auth' ) ), 'last4' => substr( $destination, -4 ) );
						foreach ( array( 'site', 'refund' ) as $part ) {
							if ( $allocation[ $part ] < 1 ) { continue; }
							$row = $this->actions->create( $locked, $prefix . '_' . $part, $allocation[ $part ], $allocation['base'], $finance['basis_hash'], $admin_id, $encrypted_reason, $ids, $bank );
							$finance['finance_action_ids'][] = (int) $row['id'];
						}
						$finance['trigger'] = $trigger;
						$finance['substate'] = 'refund_pending';
						$current['state'] = FlowState::ADMIN_REVIEW;
						$current['deadline_gmt'] = null;
						break;
					default:
						$id = is_int( $payload['financial_action_id'] ?? null ) ? $payload['financial_action_id'] : 0;
						if ( ! in_array( $id, $finance['finance_action_ids'], true ) ) { return $this->error( 'action_binding' ); }
						$row = $this->actions->get( $id, $locked->id(), true );
						if ( ! $row || 'refund_pending' !== $finance['substate'] || str_ends_with( $row['action_type'], '_vendor' ) ) { return $this->error( 'action' ); }
						$next = match ( $action ) { 'approve_action' => 'approved', 'execute_action' => 'executed', 'fail_action' => 'failed', 'reconcile_action' => 'reconciled' };
						$reference = is_string( $payload['reference'] ?? null ) ? trim( sanitize_text_field( $payload['reference'] ) ) : '';
						if ( str_ends_with( $row['action_type'], '_site' ) ) { $reference = 'core:' . $row['allocation_key']; }
						$this->actions->advance( $row, $next, $admin_id, $reference );
						if ( 'executed' === $next ) {
							$is_refund = str_ends_with( $row['action_type'], '_refund' );
							$this->ledger->append( $locked->id(), $locked->wcOrderId(), null, $is_refund ? 'buyer_refund_executed' : $row['action_type'], $is_refund ? 'buyer' : 'site', 0, FinancialActionRepository::amount( $row ), 'recorded', 'spec4-action:' . $row['allocation_key'], array( 'allocation_hash' => $row['allocation_key'] ) );
							$this->actions->linkLedger( $row, 'spec4-action:' . $row['allocation_key'] );
						}
						$all_reconciled = true;
						foreach ( $finance['finance_action_ids'] as $action_id ) { $saved = $this->actions->get( $action_id, $locked->id(), true ); $all_reconciled = $all_reconciled && $saved && 'reconciled' === $saved['status']; }
						if ( $all_reconciled ) { $finance['substate'] = 'closed'; $finance['reconciliation_status'] = 'reconciled'; $current['state'] = FlowState::CANCELLED; $notices = $this->buyerNotice( $locked, 'spec4_refund_completed', $current ); }
						break;
				}
				foreach ( $ids as $id ) { $finance['evidence'][] = array( 'id' => $id, 'actor_id' => $admin_id, 'purpose' => $purpose ); }
				$finance['decision_history'][] = array( 'actor_id' => $admin_id, 'at_gmt' => $now, 'action' => $action, 'financial_action_id' => $payload['financial_action_id'] ?? 0, 'reason_encrypted' => $encrypted_reason, 'evidence_ids' => $ids );
				return $this->result( $current, array( 'actor_id' => $admin_id, 'reason_hash' => hash( 'sha256', $reason ) ), $notices );
			}
		);
		if ( ! is_wp_error( $result ) ) { self::announce( $request_id ); }
		return $result;
	}

	private function allocate( int $request_id, array $before ): array|WP_Error {
		if ( 'credited' === $before['data']['final']['settlement'] ) { return $before; }
		return $this->cases->mutate( $request_id, $before['version'], 'system', 0, self::key( 'allocate|' . $request_id . '|' . $before['data']['spec4']['basis_hash'] ), 'spec4_vendor_credit', array(),
			function ( PurchaseRequest $request, array $case ): array|WP_Error {
				if ( ! $this->operable( $request, $case ) || ! $this->cases->depositVerified( $request, $case, true ) ) { return $this->error( 'allocation' ); }
				$finance =& $case['data']['spec4'];
				$return = 'return_confirmed' === $finance['substate'] && 'nonpayment_return' === $finance['trigger'] && null !== $finance['return_confirmed_at_gmt'] && count( $finance['return_evidence_ids'] ) > 0;
				if ( ! $return && ( 'completed' !== $finance['substate'] || null !== $finance['trigger'] || FlowState::COMPLETED !== $case['state'] || null === $case['data']['delivery']['delivered_at'] ) ) { return $this->error( 'allocation' ); }
				$allocation = $return ? Spec4AllocationPolicy::nonpaymentReturn( $case['data']['funds']['deposit']['amount'], $case['data']['funds']['balance']['amount'], true )
					: Spec4AllocationPolicy::completed( $case['data']['final']['total'], $case['data']['funds']['deposit']['amount'], $case['data']['funds']['balance']['amount'] );
				$prefix = $return ? 'nonpayment' : 'sale';
				$credit_hash = hash( 'sha256', 'spec4-credit|' . $request->id() . '|' . $finance['basis_hash'] . '|' . $prefix );
				foreach ( array( 'site', 'vendor' ) as $part ) {
					if ( $allocation[ $part ] < 1 ) { continue; }
					$row = $this->actions->create( $request, $prefix . '_' . $part, $allocation[ $part ], $allocation['base'], $finance['basis_hash'], 0 );
					if ( 'vendor' === $part ) {
						$credit = $this->dokan->credit( $request, $request->wcOrderId(), $allocation['vendor'], $credit_hash, $prefix . '_vendor_allocation', 'spec4-action:' . $row['allocation_key'] );
						if ( is_wp_error( $credit ) ) { return $credit; }
						$reference = 'dokan:' . $credit['balance_id'];
					} else { $reference = 'core:' . $row['allocation_key']; }
					// The native adapter already appended the vendor allocation in this transaction.
					if ( 'site' === $part ) { $this->ledger->append( $request->id(), $request->wcOrderId(), null, $prefix . '_site_allocation', 'site', 0, $allocation['site'], 'allocated', 'spec4-action:' . $row['allocation_key'], array( 'allocation_hash' => $row['allocation_key'] ) ); }
					foreach ( array( 'approved', 'executed', 'reconciled' ) as $step ) { $row = $this->actions->advance( $row, $step, 0, $reference ); }
					$this->actions->linkLedger( $row, 'spec4-action:' . $row['allocation_key'] );
					$finance['finance_action_ids'][] = (int) $row['id'];
				}
				$case['data']['final']['settlement'] = 'credited';
				$case['data']['final']['allocation_hash'] = $credit_hash;
				$case['data']['final']['credited_at'] = current_time( 'mysql', true );
				$finance['reconciliation_status'] = 'reconciled';
				if ( $return ) { $case['state'] = FlowState::CANCELLED; $finance['substate'] = 'closed'; }
				return $this->result( $case, array( 'net_toman' => $allocation['vendor'], 'commission_toman' => $allocation['site'], 'allocation_hash' => $credit_hash ), array() );
			}
		);
	}

	public function view( PurchaseRequest $request, array $case, int $viewer ): array {
		$admin = AdminCapabilities::userCanManageFinance( $viewer );
		if ( ! $admin && $viewer !== $request->buyerId() ) { return array(); }
		$finance = $case['data']['spec4'] ?? null;
		if ( ! is_array( $finance ) ) { return array(); }
		$result = array_intersect_key( $finance, array_flip( array( 'substate', 'remaining_toman', 'reconciliation_status', 'trigger' ) ) );
		if ( in_array( $finance['trigger'], array( 'seller_incapacity', 'paid_cancellation' ), true ) ) {
			$allocation = Spec4AllocationPolicy::refund( $finance['basis_toman'], $case['data']['funds']['deposit']['amount'] + $case['data']['funds']['balance']['amount'], $finance['trigger'] );
			$result['refund_toman'] = $allocation['refund']; $result['retained_toman'] = $allocation['site'];
		}
		if ( $admin ) {
			$result['actions'] = $this->actions->forFinance( $request->id() );
			foreach ( $result['actions'] as &$row ) { unset( $row['destination_ciphertext'], $row['destination_fingerprint'], $row['reason_ciphertext'], $row['evidence_json'] ); } unset( $row );
			$result['evidence'] = $this->evidence->links( array_column( $finance['evidence'], 'id' ), $request->id(), $viewer );
			$result['contacts'] = array();
			foreach ( $finance['contact_history'] as $contact ) {
				$result['contacts'][] = array( 'actor_id' => $contact['actor_id'], 'at_gmt' => $contact['at_gmt'], 'reason' => $this->protected->decrypt( 'finance_reason', $request->id(), $finance['offer_id'], $contact['reason_encrypted'] ) );
			}
			$result['decisions'] = array();
			foreach ( $finance['decision_history'] as $decision ) {
				$decision['reason'] = $this->protected->decrypt( 'finance_reason', $request->id(), $finance['offer_id'], $decision['reason_encrypted'] ); unset( $decision['reason_encrypted'] );
				$decision['evidence'] = $this->evidence->links( $decision['evidence_ids'], $request->id(), $viewer );
				$result['decisions'][] = $decision;
			}
		}
		return $result;
	}

	/** POST-only controller returns plaintext only after the named read is durably audited. */
	public function destination( int $request_id, int $version, int $action_id, int $admin_id, string $key, string $reason ): string|WP_Error {
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( ! AdminCapabilities::userCanManageFinance( $admin_id ) || '' === $reason || strlen( $reason ) > 1000 ) { return $this->error( 'permission' ); }
		$result = $this->cases->mutate( $request_id, $version, 'admin', $admin_id, $key, 'spec4_destination_viewed', array( 'actor_id' => $admin_id, 'financial_action_id' => $action_id, 'reason_hash' => hash( 'sha256', $reason ) ),
			function ( PurchaseRequest $request, array $case ) use ( $action_id, $admin_id, $reason ): array|WP_Error {
				$f =& $case['data']['spec4'];
				if ( ! is_array( $f ) || ! in_array( $action_id, $f['finance_action_ids'], true ) ) { return $this->error( 'action_binding' ); }
				$row = $this->actions->get( $action_id, $request->id(), true );
				if ( ! $row || ! str_ends_with( $row['action_type'], '_refund' ) || ! is_string( $row['destination_ciphertext'] ) ) { return $this->error( 'destination' ); }
				$f['decision_history'][] = array( 'actor_id' => $admin_id, 'at_gmt' => current_time( 'mysql', true ), 'action' => 'destination_viewed', 'financial_action_id' => $action_id, 'reason_encrypted' => $this->protected->encrypt( 'finance_reason', $request->id(), $f['offer_id'], $reason ), 'evidence_ids' => array() );
				return $this->result( $case, array( 'actor_id' => $admin_id, 'financial_action_id' => $action_id ), array() );
			}
		);
		if ( is_wp_error( $result ) ) { return $result; }
		$row = $this->actions->get( $action_id, $request_id );
		try { return $row ? $this->protected->decrypt( 'refund_destination', $request_id, $result['data']['spec4']['offer_id'], $row['destination_ciphertext'] ) : $this->error( 'destination' ); }
		catch ( Throwable ) { return $this->error( 'destination' ); }
	}

	/** Preserve the prerequisite repair when Woo is still missing the paid deposit snapshot. */
	public static function queueCompletionProjection( array &$case ): void {
		if ( 1 === $case['sync_pending'] && 'manual_deposit_projection' === $case['data']['recovery']['operation'] ) { return; }
		$case['sync_pending'] = 1;
		$case['data']['recovery'] = array( 'event_uuid' => wp_generate_uuid4(), 'operation' => 'managed_completion_projection', 'last_error_code' => null, 'attempts' => 0 );
	}

	private function operable( PurchaseRequest $request, array $case ): bool { return 'live' === $request->executionMode() && ! $request->isPaused() && ! $request->needsReconciliation() && ! $case['data']['pause']['active'] && $request->wcOrderId() > 0; }
	private function routeConfigured(): bool { return $this->health->onlineAvailableForNewPayment() || ( $this->settings->enabled() && array() !== $this->settings->publicAccount() ); }
	private function result( array $case, array $audit, array $notifications ): array { return array( 'case' => $case, 'request_fields' => array(), 'audit' => $audit, 'notifications' => $notifications ); }
	private function buyerNotice( PurchaseRequest $request, string $template, array $case ): array {
		return array( array( 'role' => 'buyer', 'user_id' => $request->buyerId(), 'template' => $template, 'variables' => array( 'amount_toman' => max( 0, $case['data']['spec4']['remaining_toman'] ), 'deadline' => $case['deadline_gmt'] ?? '' ) ) );
	}
	private function adminNotices( string $template, array $variables = array() ): array {
		$ids = array_slice( array_map( 'intval', get_users( array( 'capability' => AdminCapabilities::MANAGE_FINANCE, 'fields' => 'ids', 'number' => 10 ) ) ), 0, 10 );
		return array_map( static fn ( int $id ): array => array( 'role' => 'admin', 'user_id' => $id, 'template' => $template, 'variables' => $variables ), array_values( array_filter( $ids, static fn ( int $id ): bool => AdminCapabilities::userCanManageFinance( $id ) ) ) );
	}
	private function pairNotices( PurchaseRequest $request, string $template ): array { return array( array( 'role' => 'buyer', 'user_id' => $request->buyerId(), 'template' => $template, 'variables' => array() ), array( 'role' => 'vendor', 'user_id' => $request->vendorId(), 'template' => $template, 'variables' => array() ) ); }
	public function recordCreditIssue( int $request_id ): void {
		$case = $this->cases->find( $request_id );
		if ( ! $case || ! isset( $case['data']['spec4'] ) || ! in_array( $case['data']['spec4']['substate'], array( 'completed', 'return_confirmed' ), true ) || 'credited' === $case['data']['final']['settlement'] ) { return; }
		$this->cases->mutate( $request_id, $case['version'], 'system', 0, self::key( 'allocation-review|' . $request_id ), 'spec4_allocation_review', array(), function ( PurchaseRequest $request, array $current ): array {
			$current['data']['spec4']['reconciliation_status'] = 'review';
			return $this->result( $current, array(), $this->adminNotices( 'spec4_allocation_review' ) );
		} );
	}
	public static function key( string $binding ): string { $h = hash( 'sha256', 'zbm-spec4|' . $binding ); return substr( $h, 0, 8 ) . '-' . substr( $h, 8, 4 ) . '-4' . substr( $h, 13, 3 ) . '-a' . substr( $h, 17, 3 ) . '-' . substr( $h, 20, 12 ); }
	/** Side effects cannot turn a committed financial fact into an apparent failure. */
	public static function announce( int $request_id ): void {
		foreach ( array( 'zbm_spec4_changed', 'zbm_managed_sync_requested' ) as $hook ) {
			try { do_action( $hook, $request_id ); } catch ( Throwable ) { /* Durable recovery sweeps retry. */ }
		}
	}
	private static function validIban( string $value ): bool {
		if ( ! preg_match( '/\AIR[0-9]{24}\z/', $value ) ) { return false; }
		$digits = substr( $value, 4 ) . '1827' . substr( $value, 2, 2 ); $remainder = 0;
		foreach ( str_split( $digits ) as $digit ) { $remainder = ( $remainder * 10 + (int) $digit ) % 97; }
		return 1 === $remainder;
	}
	private function error( string $code ): WP_Error { return new WP_Error( 'zbm_spec4_' . $code, __( 'اقدام مالی با وضعیت فعلی پرونده سازگار نیست. آخرین وضعیت، مدرک و نتیجهٔ پرداخت را بررسی کنید؛ مبلغی تغییر نکرد.', 'zarbalman-core' ) ); }
}
