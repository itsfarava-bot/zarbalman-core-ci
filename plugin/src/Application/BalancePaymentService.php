<?php

declare(strict_types=1);

namespace Zarbalman\Core\Application;

use Throwable;
use WP_Error;
use Zarbalman\Core\Domain\ManagedFlow\FlowState;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Infrastructure\{BalancePaymentRepository, DependencyHealth, LedgerRepository, ManagedCaseRepository, PurchaseRequestOperationLock, PurchaseRequestRepository};
use Zarbalman\Core\Integration\ZarinpalBalanceAdapter;
use Zarbalman\Core\Security\AdminCapabilities;

/** Provider calls happen outside SQL transactions; verified money and its ledger commit together. */
final class BalancePaymentService {
	public function __construct(
		private readonly PurchaseRequestRepository $requests, private readonly ManagedCaseRepository $cases,
		private readonly BalancePaymentRepository $payments, private readonly LedgerRepository $ledger,
		private readonly DependencyHealth $health, private readonly ZarinpalBalanceAdapter $gateway,
		private readonly PurchaseRequestOperationLock $lock,
		private readonly \Zarbalman\Core\Infrastructure\ProtectedText $protected
	) {}

	/** The returned URL is always constructed from a persisted provider Authority. */
	public function start( int $request_id, int $version, int $buyer_id, string $key ): string|WP_Error {
		if ( $error = \Zarbalman\Core\Infrastructure\Migrator::financeAvailabilityError() ) { return $error; }
		$held = false;
		try {
			$this->lock->acquire( $request_id, 10 ); $held = true;
			$request = $this->requests->find( $request_id ); $case = $this->cases->find( $request_id );
			if ( ! $request || ! $case || $buyer_id !== $request->buyerId() || ! $this->payable( $request, $case ) || ! $this->health->onlineAvailableForNewPayment() || ! wp_is_uuid( $key ) ) { return $this->error( 'not_payable' ); }
			$existing = empty( $case['data']['spec4']['payment_id'] ) ? null : $this->payments->get( $case['data']['spec4']['payment_id'] );
			if ( $existing && $this->binding( $existing, $request, $case ) && 'authority_issued' === $existing['status'] ) { return ZarinpalBalanceAdapter::url( $existing['authority'] ); }
			$fingerprint = $this->gateway->fingerprint();
			$claimed = false;
			$result = $this->cases->mutate( $request_id, $version, 'buyer', $buyer_id, $key, 'spec4_create_intent', array( 'actor_id' => $buyer_id ),
				function ( PurchaseRequest $locked, array $current ) use ( &$claimed, $fingerprint ): array|WP_Error {
					if ( ! $this->payable( $locked, $current ) || ! $this->health->onlineAvailableForNewPayment() ) { return $this->error( 'not_payable' ); }
					$row = $this->payments->prepare( $locked, $current, $fingerprint );
					$row = $this->payments->begin( $row, $current['deadline_gmt'] );
					$current['data']['spec4']['payment_id'] = (int) $row['id']; $claimed = true;
					return $this->result( $current, array( 'payment_id' => (int) $row['id'] ) );
				}
			);
			if ( is_wp_error( $result ) ) { return $result; }
			// A replay of the same command may not repeat an external request.
			if ( ! $claimed ) { return $this->error( 'pending' ); }
			$intent = $this->payments->get( $result['data']['spec4']['payment_id'] );
			if ( ! $intent ) { return $this->error( 'intent' ); }
			$callback = add_query_arg( array( 'action' => 'zbm_balance_callback', 'payment' => (int) $intent['id'], 'offer' => $result['data']['spec4']['offer_id'] ), admin_url( 'admin-post.php', 'https' ) );
			$response = $this->gateway->issue( $intent, $callback );
			$event = 'issued' === $response['status'] ? 'spec4_authority_issued' : 'spec4_issuance_failed';
			$saved = $this->cases->mutate( $request_id, $result['version'], 'system', 0, Spec4FinanceService::key( $event . '|' . $intent['id'] . '|' . $result['version'] ), $event, array( 'payment_id' => (int) $intent['id'] ),
				function ( PurchaseRequest $locked, array $current ) use ( $intent, $response ): array|WP_Error {
					$row = $this->payments->get( (int) $intent['id'], true );
					if ( ! $row || ! $this->binding( $row, $locked, $current ) ) { return $this->error( 'binding' ); }
					if ( 'issued' === $response['status'] ) { $this->payments->issued( $row, $response['authority'] ); }
					else {
						$this->payments->issuanceFailed( $row, 'rejected' === $response['status'] );
						if ( 'unknown' === $response['status'] ) { $current['data']['spec4']['substate'] = 'payment_review'; $current['data']['spec4']['reconciliation_status'] = 'review'; $current['state'] = FlowState::ADMIN_REVIEW; $current['deadline_gmt'] = null; }
					}
					return $this->result( $current, array( 'payment_id' => (int) $row['id'] ), 'unknown' === $response['status'] ? $this->reviewNotices() : array() );
				}
			);
			if ( is_wp_error( $saved ) || 'issued' !== $response['status'] ) { return is_wp_error( $saved ) ? $saved : $this->error( $response['status'] ); }
			return ZarinpalBalanceAdapter::url( $response['authority'] );
		} catch ( Throwable ) { return $this->error( 'unavailable' ); }
		finally { if ( $held ) { $this->lock->release( $request_id ); } }
	}

	/** Callback Status is intentionally not authoritative; amount/phase come only from the intent. */
	public function callback( int $payment_id, string $offer, string $authority ): array|WP_Error {
		if ( $error = \Zarbalman\Core\Infrastructure\Migrator::financeAvailabilityError() ) { return $error; }
		$held = false; $request_id = 0;
		try {
			$intent = $this->payments->get( $payment_id );
			if ( ! $intent || ! is_string( $intent['authority'] ) || ! hash_equals( $intent['authority'], $authority ) || ! wp_is_uuid( $offer ) ) { return $this->error( 'callback' ); }
			$request_id = (int) $intent['request_id'];
			$this->lock->acquire( $request_id, 10 ); $held = true;
			$request = $this->requests->find( $request_id ); $case = $this->cases->find( $request_id );
			if ( ! $request || ! $case || ! $this->binding( $intent, $request, $case ) || ! hash_equals( $case['data']['spec4']['offer_id'], $offer ) ) { return $this->error( 'binding' ); }
			if ( in_array( $intent['status'], array( 'captured', 'late_captured', 'late_accepted' ), true ) ) { return $case; }
			$cooldown = 'zbm_balance_callback_' . $payment_id;
			if ( get_transient( $cooldown ) ) { return $this->error( 'pending' ); }
			set_transient( $cooldown, true, 30 );
			$seen = $this->cases->mutate( $request_id, $case['version'], 'system', 0, Spec4FinanceService::key( 'callback|' . $payment_id ), 'spec4_callback_received', array( 'payment_id' => $payment_id ),
				function ( PurchaseRequest $locked, array $current ) use ( $payment_id ): array|WP_Error {
					$row = $this->payments->get( $payment_id, true );
					if ( ! $row || ! $this->binding( $row, $locked, $current ) ) { return $this->error( 'binding' ); }
					$this->payments->callbackSeen( $row ); return $this->result( $current, array( 'payment_id' => $payment_id ) );
				}
			);
			if ( is_wp_error( $seen ) ) { return $seen; }
			$intent = $this->payments->get( $payment_id );
			if ( ! $intent ) { return $this->error( 'intent' ); }
			$verified = $this->gateway->verify( $intent );
			$fresh = $this->cases->find( $request_id );
			if ( ! $fresh ) { return $this->error( 'case' ); }
			if ( ! $verified->isVerified() ) {
				return $this->cases->mutate( $request_id, $fresh['version'], 'system', 0, Spec4FinanceService::key( 'verify-review|' . $payment_id ), 'spec4_payment_review', array( 'payment_id' => $payment_id ),
					function ( PurchaseRequest $locked, array $current ): array {
						$current['data']['spec4']['substate'] = 'payment_review'; $current['data']['spec4']['reconciliation_status'] = 'review'; $current['state'] = FlowState::ADMIN_REVIEW; $current['deadline_gmt'] = null;
						return $this->result( $current, array(), $this->reviewNotices() );
					}
				);
			}
			$reference = $verified->reference();
			if ( ! is_string( $reference ) ) { return $this->error( 'verify' ); }
			$result = $this->cases->mutate( $request_id, $fresh['version'], 'system', 0, Spec4FinanceService::key( 'capture|' . $payment_id . '|' . $reference ), 'spec4_balance_verified', array( 'payment_id' => $payment_id, 'reference_hash' => hash( 'sha256', $reference ) ),
				function ( PurchaseRequest $locked, array $current ) use ( $payment_id, $authority, $reference ): array|WP_Error {
					$row = $this->payments->get( $payment_id, true );
					if ( ! $row || ! $this->binding( $row, $locked, $current ) ) { return $this->error( 'binding' ); }
					$finance =& $current['data']['spec4'];
					$late = null !== $finance['trigger'] || ! is_string( $row['intent_deadline_gmt'] ) || empty( $row['callback_at_gmt'] ) || $row['callback_at_gmt'] > $row['intent_deadline_gmt']
						|| current_time( 'mysql', true ) > $row['intent_deadline_gmt']
						|| $locked->isPaused() || $locked->needsReconciliation() || $current['data']['pause']['active'] || null !== $current['data']['manual']['balance'];
					$row = $this->payments->captured( $row, $authority, $reference, $late );
					$this->ledger->append( $locked->id(), $locked->wcOrderId(), $payment_id, 'employer_cash_received', 'employer', 0, (int) $row['paid_amount_toman'], 'recorded', 'spec4-balance-cash:' . $payment_id );
					if ( $late ) { $finance['substate'] = 'payment_review'; $finance['reconciliation_status'] = 'review'; $current['state'] = FlowState::ADMIN_REVIEW; $current['deadline_gmt'] = null; }
					else { $this->ledger->provisionalShares( $locked->id(), $locked->wcOrderId(), $payment_id, $locked->vendorId(), (int) $row['paid_amount_toman'] ); $this->complete( $current, $row ); }
					return $this->result( $current, array( 'payment_id' => $payment_id, 'amount_toman' => (int) $row['paid_amount_toman'] ), $late ? $this->reviewNotices() : $this->completedNotices( $locked ) );
				}
			);
			if ( ! is_wp_error( $result ) ) { Spec4FinanceService::announce( $request_id ); }
			return $result;
		} catch ( Throwable ) { return $this->error( 'verification' ); }
		finally { if ( $held ) { $this->lock->release( $request_id ); } }
	}

	public function acceptLate( int $request_id, int $version, int $admin_id, string $key, string $reason ): array|WP_Error {
		if ( $error = \Zarbalman\Core\Infrastructure\Migrator::financeAvailabilityError() ) { return $error; }
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( ! AdminCapabilities::userCanManageFinance( $admin_id ) || '' === $reason || strlen( $reason ) > 1000 ) { return $this->error( 'permission' ); }
		$result = $this->cases->mutate( $request_id, $version, 'admin', $admin_id, $key, 'spec4_accept_late', array( 'actor_id' => $admin_id, 'reason_hash' => hash( 'sha256', $reason ) ),
			function ( PurchaseRequest $request, array $case ) use ( $admin_id, $reason ): array|WP_Error {
				$finance = $case['data']['spec4'] ?? null;
				if ( ! $finance || 'payment_review' !== $finance['substate'] || null !== $finance['trigger'] || $finance['finance_action_ids'] || $request->isPaused() || $request->needsReconciliation() || $case['data']['pause']['active'] || $case['data']['funds']['balance']['amount'] > 0 ) { return $this->error( 'late' ); }
				$row = $this->payments->get( (int) $finance['payment_id'], true );
				if ( ! $row || ! $this->binding( $row, $request, $case ) || (int) $row['paid_amount_toman'] !== $finance['remaining_toman'] ) { return $this->error( 'amount' ); }
				$row = $this->payments->acceptLate( $row ); $this->complete( $case, $row );
				$this->ledger->provisionalShares( $request->id(), $request->wcOrderId(), (int) $row['id'], $request->vendorId(), (int) $row['paid_amount_toman'] );
				$this->decision( $request, $case, $admin_id, 'accept_late', $reason );
				return $this->result( $case, array( 'payment_id' => (int) $row['id'], 'amount_toman' => (int) $row['paid_amount_toman'] ), $this->completedNotices( $request ) );
			}
		);
		if ( ! is_wp_error( $result ) ) { Spec4FinanceService::announce( $request_id ); }
		return $result;
	}
	public function view( PurchaseRequest $request, array $case, int $viewer ): array {
		if ( $viewer !== $request->buyerId() && ! AdminCapabilities::userCanManageFinance( $viewer ) ) { return array(); }
		$finance = $case['data']['spec4'] ?? null; if ( ! $finance ) { return array(); }
		$row = empty( $finance['payment_id'] ) ? null : $this->payments->get( $finance['payment_id'] );
		return array( 'can_start' => $viewer === $request->buyerId() && $this->payable( $request, $case ) && $this->health->onlineAvailableForNewPayment() && ( ! $row || in_array( $row['status'], array( 'expected', 'authority_issued' ), true ) ),
			'payment_status' => $row['status'] ?? '', 'payment_id' => $row ? (int) $row['id'] : 0, 'can_verify' => $row && in_array( $row['status'], array( 'authority_issued', 'verification_pending' ), true ), 'can_accept_late' => $row && 'late_captured' === $row['status'] );
	}
	public function reverify( int $request_id, int $version, int $admin_id, string $key, string $reason ): array|WP_Error {
		if ( $error = \Zarbalman\Core\Infrastructure\Migrator::financeAvailabilityError() ) { return $error; }
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( ! AdminCapabilities::userCanManageFinance( $admin_id ) || '' === $reason || strlen( $reason ) > 1000 ) { return $this->error( 'permission' ); }
		$case = $this->cases->mutate( $request_id, $version, 'admin', $admin_id, $key, 'spec4_reverify', array( 'actor_id' => $admin_id, 'reason_hash' => hash( 'sha256', $reason ) ),
			function ( PurchaseRequest $request, array $current ) use ( $admin_id, $reason ): array|WP_Error {
				$f = $current['data']['spec4'] ?? null;
				$row = $f && $f['payment_id'] ? $this->payments->get( $f['payment_id'], true ) : null;
				if ( ! $row || ! $this->binding( $row, $request, $current ) || ! in_array( $row['status'], array( 'authority_issued', 'verification_pending' ), true ) ) { return $this->error( 'intent' ); }
				$this->decision( $request, $current, $admin_id, 'reverify_payment', $reason );
				return $this->result( $current, array( 'actor_id' => $admin_id, 'payment_id' => (int) $row['id'] ) );
			} );
		if ( is_wp_error( $case ) ) { return $case; }
		$finance = $case['data']['spec4'] ?? null;
		$row = $finance && ! empty( $finance['payment_id'] ) ? $this->payments->get( $finance['payment_id'] ) : null;
		return $row && is_string( $row['authority'] ) ? $this->callback( (int) $row['id'], $finance['offer_id'], $row['authority'] ) : $this->error( 'intent' );
	}
	/** Scheduler-only retry of an already observed callback; never creates a payment. */
	public function retryVerification( int $request_id ): void {
		$case = $this->cases->find( $request_id ); $finance = $case['data']['spec4'] ?? null;
		$row = $finance && ! empty( $finance['payment_id'] ) ? $this->payments->get( $finance['payment_id'] ) : null;
		if ( $row && 'issuance_pending' === $row['status'] && $row['updated_at_gmt'] < gmdate( 'Y-m-d H:i:s', time() - 60 ) ) {
			$this->cases->mutate( $request_id, $case['version'], 'system', 0, Spec4FinanceService::key( 'abandoned-issuance|' . $row['id'] ), 'spec4_issuance_failed', array( 'payment_id' => (int) $row['id'] ), function ( PurchaseRequest $request, array $current ) use ( $row ): array|WP_Error {
				$locked = $this->payments->get( (int) $row['id'], true );
				if ( ! $locked || ! $this->binding( $locked, $request, $current ) || 'issuance_pending' !== $locked['status'] || $locked['updated_at_gmt'] >= gmdate( 'Y-m-d H:i:s', time() - 60 ) ) { return $this->error( 'intent' ); }
				$this->payments->issuanceFailed( $locked, false );
				$current['data']['spec4']['substate'] = 'payment_review'; $current['data']['spec4']['reconciliation_status'] = 'review'; $current['state'] = FlowState::ADMIN_REVIEW; $current['deadline_gmt'] = null;
				return $this->result( $current, array( 'payment_id' => (int) $row['id'] ), $this->reviewNotices() );
			} );
			return;
		}
		if ( ! $row || 'verification_pending' !== $row['status'] || ! is_string( $row['authority'] ) ) { return; }
		$key = 'zbm_balance_verify_' . (int) $row['id'];
		if ( get_transient( $key ) ) { return; }
		set_transient( $key, true, 300 );
		$this->callback( (int) $row['id'], $finance['offer_id'], $row['authority'] );
	}
	private function payable( PurchaseRequest $request, array $case ): bool {
		$f = $case['data']['spec4'] ?? null;
		return $f && 'live' === $request->executionMode() && ! $request->isPaused() && ! $request->needsReconciliation() && ! $case['data']['pause']['active']
			&& FlowState::BALANCE_DUE === $case['state'] && in_array( $f['substate'], array( 'balance_due', 'warning' ), true ) && null === $f['trigger']
			&& $f['remaining_toman'] > 0 && $case['deadline_gmt'] > current_time( 'mysql', true ) && null !== $case['data']['delivery']['delivered_at'] && null !== $case['data']['final']['locked_at'] && null === $case['data']['manual']['balance'];
	}
	private function binding( array $row, PurchaseRequest $request, array $case ): bool {
		$f = $case['data']['spec4'] ?? null;
		return $f && 'live' === $request->executionMode() && 'final' === $f['basis_kind'] && 'balance' === $row['phase'] && 'zarinpal' === $row['provider']
			&& (int) $row['id'] === $f['payment_id'] && (int) $row['request_id'] === $request->id() && (int) $row['order_id'] === $request->wcOrderId()
			&& (int) $row['expected_amount_toman'] === $f['basis_toman'] - $case['data']['funds']['deposit']['amount'];
	}
	private function complete( array &$case, array $row ): void {
		$case['data']['funds']['balance'] = array( 'amount' => (int) $row['paid_amount_toman'], 'provider' => 'zarinpal', 'payment_id' => (int) $row['id'] );
		$case['data']['spec4']['remaining_toman'] = 0; $case['data']['spec4']['substate'] = 'completed'; $case['data']['spec4']['reconciliation_status'] = 'pending';
		$case['state'] = FlowState::COMPLETED; $case['deadline_gmt'] = null; $case['data']['final']['settlement'] = 'pending';
		Spec4FinanceService::queueCompletionProjection( $case );
	}
	private function decision( PurchaseRequest $request, array &$case, int $admin_id, string $action, string $reason ): void {
		$f =& $case['data']['spec4'];
		$f['decision_history'][] = array( 'actor_id' => $admin_id, 'at_gmt' => current_time( 'mysql', true ), 'action' => $action, 'financial_action_id' => 0, 'reason_encrypted' => $this->protected->encrypt( 'finance_reason', $request->id(), $f['offer_id'], $reason ), 'evidence_ids' => array() );
	}
	private function reviewNotices(): array {
		$ids = get_users( array( 'capability' => AdminCapabilities::MANAGE_FINANCE, 'fields' => 'ids', 'number' => 10 ) );
		$notices = array();
		foreach ( $ids as $id ) { if ( AdminCapabilities::userCanManageFinance( (int) $id ) ) { $notices[] = array( 'role' => 'admin', 'user_id' => (int) $id, 'template' => 'spec4_payment_review', 'variables' => array() ); } }
		return $notices;
	}
	private function completedNotices( PurchaseRequest $request ): array {
		return array( array( 'role' => 'buyer', 'user_id' => $request->buyerId(), 'template' => 'spec4_payment_completed', 'variables' => array() ), array( 'role' => 'vendor', 'user_id' => $request->vendorId(), 'template' => 'spec4_payment_completed', 'variables' => array() ) );
	}
	private function result( array $case, array $audit = array(), array $notices = array() ): array { return array( 'case' => $case, 'request_fields' => array(), 'audit' => $audit, 'notifications' => $notices ); }
	private function error( string $code ): WP_Error { return new WP_Error( 'zbm_balance_' . $code, __( 'نتیجهٔ پرداخت مانده هنوز قطعی نیست. آخرین وضعیت را بررسی کنید و تا تعیین نتیجه دوباره واریز نکنید.', 'zarbalman-core' ) ); }
}
