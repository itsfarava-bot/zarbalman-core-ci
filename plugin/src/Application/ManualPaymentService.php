<?php

declare(strict_types=1);

namespace Zarbalman\Core\Application;

use RuntimeException;
use Throwable;
use WP_Error;
use Zarbalman\Core\Domain\Finance\ManualPaymentPolicy;
use Zarbalman\Core\Domain\Finance\ReceiptProjectionPolicy;
use Zarbalman\Core\Domain\Finance\PaymentRoutingPolicy;
use Zarbalman\Core\Domain\Finance\Spec4Data;
use Zarbalman\Core\Domain\Finance\ManualSettlementPolicy;
use Zarbalman\Core\Domain\ManagedFlow\FlowState;
use Zarbalman\Core\Domain\PurchaseRequest\ExecutionMode;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Domain\PurchaseRequest\RequestPricingPolicy;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;
use Zarbalman\Core\Infrastructure\LedgerRepository;
use Zarbalman\Core\Infrastructure\DependencyHealth;
use Zarbalman\Core\Infrastructure\ManagedCaseRepository;
use Zarbalman\Core\Infrastructure\ManagedEvidence;
use Zarbalman\Core\Infrastructure\PaymentRepository;
use Zarbalman\Core\Infrastructure\ProtectedText;
use Zarbalman\Core\Infrastructure\PurchaseRequestOperationLockPort;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;
use Zarbalman\Core\Integration\DokanManualSettlementAdapter;
use Zarbalman\Core\Integration\DokanVendorEligibility;
use Zarbalman\Core\Integration\ManualPaymentOrderAdapter;
use Zarbalman\Core\Security\AdminCapabilities;

/** Real manual deposit/balance orchestration; upload alone never records funds. */
final class ManualPaymentService {
	public function __construct(
		private readonly PurchaseRequestRepository $requests,
		private readonly ManagedCaseRepository $cases,
		private readonly PurchaseRequestOperationLockPort $lock,
		private readonly PaymentRepository $payments,
		private readonly LedgerRepository $ledger,
		private readonly ManagedEvidence $evidence,
		private readonly DependencyHealth $health,
		private readonly ManualPaymentSettings $settings,
		private readonly ProtectedText $protected,
		private readonly ManualPaymentOrderAdapter $orders,
		private readonly DokanManualSettlementAdapter $settlements,
		private readonly DokanVendorEligibility $vendorEligibility
	) {}

	/** @return array|WP_Error Canonical case; no decrypted account is returned here. */
	public function offer( int $request_id, int $expected_version, int $buyer_id, string $key ): array|WP_Error {
		if ( $request_id < 1 || $buyer_id < 1 || $expected_version < 0 || ! wp_is_uuid( $key ) ) {
			return $this->error( 'command' );
		}
		return $this->locked( $request_id, function () use ( $request_id, $expected_version, $buyer_id, $key ): array|WP_Error {
			$request = $this->requests->find( $request_id );
			if ( ! $request || $request->buyerId() !== $buyer_id || ExecutionMode::LIVE !== $request->executionMode() ) {
				return $this->error( 'owner' );
			}
			$case = $this->cases->find( $request_id );
			if ( null !== $case && $case['version'] !== $expected_version ) {
				return $this->error( 'stale' );
			}
			if ( null === $case && 0 !== $expected_version ) {
				return $this->error( 'stale' );
			}
			$preview = $case ?? $this->previewCase( $request );
			$decision = ManualPaymentPolicy::offer( $this->context( $request, $preview, true, false ) );
			if ( is_wp_error( $decision ) ) {
				return $decision;
			}
			if ( null === $decision['phase'] ) {
				return $case ?? $this->error( 'nothing_due' );
			}
			if ( $decision['existing'] ) {
				return null !== $case ? $case : $this->error( 'offer_missing' );
			}
			if ( 'manual' !== $this->availableRoute( $request, $case, $decision['phase'] ) ) {
				return new WP_Error( 'zbm_manual_online_available', __( 'پرداخت دستی جدید برای این مرحله فعال نیست؛ آخرین روش پرداخت را در صفحهٔ درخواست بررسی کنید.', 'zarbalman-core' ) );
			}
			$dependency_error = $this->health->manualOrderError();
			if ( $dependency_error ) {
				return $dependency_error;
			}
			$account = $this->settings->publicAccount();
			if ( array() === $account || ! $this->settings->enabled() ) {
				return $this->error( 'settings' );
			}
			$offer_id = wp_generate_uuid4();
			try {
				$plain = wp_json_encode( $account, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
				$encrypted = $this->protected->encrypt( 'manual_account_snapshot', $request_id, $offer_id, $plain );
			} catch ( Throwable ) {
				return $this->error( 'encryption' );
			}
			$recovery_id = 'deposit' === $decision['phase'] ? wp_generate_uuid4() : null;
			$result = $this->cases->mutate(
				$request_id, $expected_version, 'buyer', $buyer_id, $key, 'manual_offer_created',
				array( 'actor_id' => $buyer_id, 'amount_toman' => $decision['amount'], 'offer_hash' => hash( 'sha256', $offer_id ) ),
				function ( PurchaseRequest $locked, array $current ) use ( $buyer_id, $decision, $offer_id, $encrypted, $recovery_id ): array|WP_Error {
					$inside = ManualPaymentPolicy::offer( $this->context( $locked, $current, true, false ) );
					if ( is_wp_error( $inside ) || $inside !== $decision ) {
						return is_wp_error( $inside ) ? $inside : $this->error( 'stale' );
					}
					$phase = $inside['phase'];
					if ( ! is_string( $phase ) || null !== $current['data']['manual'][ $phase ] ) {
						return $this->error( 'offer_conflict' );
					}
					if ( 'manual' !== $this->availableRoute( $locked, $current, $phase ) ) { return $this->error( 'route_changed' ); }
					$now = current_time( 'mysql', true );
					$current['data']['manual'][ $phase ] = array(
						'offer_id' => $offer_id, 'amount' => $inside['amount'], 'status' => 'offered',
						'account_encrypted' => $encrypted, 'receipt_ids' => array(), 'receipt_history' => array(),
						'offered_at' => $now, 'submitted_at' => null, 'reviewed_at' => null,
						'reviewed_by' => 0, 'reason' => '', 'buyer_note' => '',
					);
					$fields = array();
					if ( 'deposit' === $phase ) {
						$current['sync_pending'] = 1;
						$current['data']['recovery'] = array( 'event_uuid' => $recovery_id, 'operation' => 'manual_order_sync', 'last_error_code' => null, 'attempts' => 0 );
						$fields = array( 'current_state' => RequestState::DEPOSIT_PENDING, 'payment_ready' => 1,
							'deposit_deadline_gmt' => $locked->depositDeadlineGmt() ?? gmdate( 'Y-m-d H:i:s', time() + PurchaseRequestService::DEPOSIT_TTL_SECONDS ) );
					}
					return array(
						'case' => $current,
						'request_fields' => $fields,
						'audit' => array( 'actor_id' => $buyer_id, 'amount_toman' => $inside['amount'], 'offer_hash' => hash( 'sha256', $offer_id ) ),
						'notifications' => array( array( 'role' => 'buyer', 'user_id' => $locked->buyerId(), 'template' => 'manual_payment_offered', 'variables' => array( 'amount_toman' => $inside['amount'] ) ) ),
					);
				}
			);
			if ( is_wp_error( $result ) || 'deposit' !== $decision['phase'] ) {
				return $result;
			}
			// The immutable bank obligation is usable immediately. Woo is a queued projection.
			$this->announceDepositPending( $request_id );
			$this->announceSyncRequested( $request_id );
			return $result;
		} );
	}

	/** @return array|WP_Error */
	public function submitReceipt( int $request_id, int $expected_version, int $buyer_id, string $key, string $offer_id, mixed $files, string $note = '' ): array|WP_Error {
		$note = trim( sanitize_textarea_field( $note ) );
		if ( $request_id < 1 || $buyer_id < 1 || $expected_version < 0 || ! wp_is_uuid( $key ) || ! wp_is_uuid( $offer_id ) || strlen( $note ) > 1000 ) {
			return $this->error( 'command' );
		}
		return $this->locked( $request_id, function () use ( $request_id, $expected_version, $buyer_id, $key, $offer_id, $files, $note ): array|WP_Error {
			$request = $this->requests->find( $request_id );
			$case = $this->cases->find( $request_id );
			if ( ! $request || ! $case || $request->buyerId() !== $buyer_id || $case['version'] !== $expected_version || 'live' !== $case['mode'] ) {
				return $this->error( 'stale' );
			}
			if ( 1 === $case['sync_pending'] && ! ReceiptProjectionPolicy::isReceiptOnlyProjection( $case ) ) {
				$recovered = $this->recoverLocked( $request_id, $case );
				if ( is_wp_error( $recovered ) ) {
					return $recovered;
				}
				$case = $recovered;
				if ( 1 === $case['sync_pending'] ) {
					$this->announceSyncRequested( $request_id );
					return $this->syncPendingError();
				}
				$request = $this->requests->find( $request_id );
				if ( ! $request || $request->buyerId() !== $buyer_id ) {
					return $this->error( 'stale' );
				}
				$expected_version = $case['version'];
			}
			$phase = $this->phaseByOffer( $case, $offer_id );
			if ( null === $phase ) {
				return $this->error( 'offer' );
			}
			if ( 'deposit' === $phase ) {
				$prepared = $this->ensureManualDepositReadyLocked( $request, $case );
				if ( is_wp_error( $prepared ) ) {
					return $prepared;
				}
				$case = $prepared;
				$request = $this->requests->find( $request_id );
				if ( ! $request || $request->buyerId() !== $buyer_id ) {
					return $this->error( 'stale' );
				}
				$expected_version = $case['version'];
			}
			$allowed = ManualPaymentPolicy::submitReceipt( $this->context( $request, $case, true, false ), $phase );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
			$offer = $case['data']['manual'][ $phase ];
			if ( count( $offer['receipt_history'] ) >= 20 ) {
				return $this->error( 'receipt_state' );
			}
			$purpose = 'manual_' . $phase . '_receipt';
			$ids = $this->evidence->upload( $request_id, $request->wcOrderId() ?? 0, $buyer_id, $purpose, $files, 1 );
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}
			return $this->cases->mutate(
				$request_id, $expected_version, 'buyer', $buyer_id, $key, 'manual_receipt_submitted',
				array( 'actor_id' => $buyer_id, 'offer_hash' => hash( 'sha256', $offer_id ), 'count' => count( $ids ), 'note_hash' => hash( 'sha256', $note ) ),
				function ( PurchaseRequest $locked, array $current ) use ( $buyer_id, $phase, $offer_id, $purpose, $ids, $note ): array|WP_Error {
					$allowed = ManualPaymentPolicy::submitReceipt( $this->context( $locked, $current, true, false ), $phase );
					if ( is_wp_error( $allowed ) ) {
						return $allowed;
					}
					$offer = $current['data']['manual'][ $phase ] ?? null;
					if ( ! is_array( $offer ) || ! hash_equals( $offer['offer_id'], $offer_id ) || ! in_array( $offer['status'], array( 'offered', 'rejected' ), true ) || ! $this->evidence->assertOwned( $ids, $locked->id(), $buyer_id, $purpose, 1, 1 ) ) {
						return $this->error( 'receipt_binding' );
					}
					if ( 'rejected' === $offer['status'] && 1 === count( $offer['receipt_ids'] ) ) {
						$offer['receipt_history'][] = array( 'receipt_ids' => $offer['receipt_ids'], 'submitted_at' => $offer['submitted_at'], 'reviewed_at' => $offer['reviewed_at'], 'reviewed_by' => $offer['reviewed_by'], 'reason' => $offer['reason'], 'buyer_note' => (string) ( $offer['buyer_note'] ?? '' ) );
					}
					$offer['receipt_ids'] = $ids;
					$offer['status'] = 'submitted';
					$offer['submitted_at'] = current_time( 'mysql', true );
					$offer['reviewed_at'] = null;
					$offer['reviewed_by'] = 0;
					$offer['reason'] = '';
					$offer['buyer_note'] = $note;
					$current['data']['manual'][ $phase ] = $offer;
					if ( 'balance' === $phase && isset( $current['data']['spec4'] ) ) {
						$current['data']['spec4']['substate'] = 'receipt_review';
						$current['deadline_gmt'] = null;
					}
					$notifications = array();
					foreach ( $this->financeAdminIds() as $admin_id ) {
						$notifications[] = array( 'role' => 'admin', 'user_id' => $admin_id, 'template' => 'manual_receipt_review', 'variables' => array( 'amount_toman' => $offer['amount'] ) );
					}
					$fields = 'deposit' === $phase
						? array( 'deposit_deadline_gmt' => null, 'payment_ready' => 0 )
						: array();
					return array( 'case' => $current, 'request_fields' => $fields, 'audit' => array( 'actor_id' => $buyer_id, 'offer_hash' => hash( 'sha256', $offer_id ), 'count' => 1, 'note_hash' => hash( 'sha256', $note ) ), 'notifications' => $notifications );
				}
			);
		} );
	}

	/** @return array|WP_Error */
	public function approve( int $request_id, int $expected_version, int $admin_id, string $key, string $phase, string $reason, bool $confirmed, int $confirmed_amount ): array|WP_Error {
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( ! AdminCapabilities::userCanManageFinance( $admin_id ) ) {
			return $this->error( 'finance' );
		}
		if ( $request_id < 1 || $expected_version < 0 || $expected_version >= PHP_INT_MAX || ! wp_is_uuid( $key ) ) {
			return $this->error( 'command' );
		}
		if ( ! in_array( $phase, array( 'deposit', 'balance' ), true ) ) {
			return $this->error( 'phase' );
		}
		if ( $confirmed_amount < 1 ) {
			return $this->error( 'amount' );
		}
		return $this->locked( $request_id, function () use ( $request_id, $expected_version, $admin_id, $key, $phase, $reason, $confirmed, $confirmed_amount ): array|WP_Error {
			$projection_id = wp_generate_uuid4();
			$recovery_case = null;
			$result = $this->cases->mutate(
				$request_id, $expected_version, 'admin', $admin_id, $key, 'manual_payment_approved',
				array( 'actor_id' => $admin_id, 'confirmed' => $confirmed, 'amount_toman' => $confirmed_amount, 'reason_hash' => hash( 'sha256', $reason ) ),
				function ( PurchaseRequest $locked, array $current ) use ( $admin_id, $phase, $reason, $confirmed, $confirmed_amount, $projection_id, &$recovery_case ): array|WP_Error {
					$decision = ManualPaymentPolicy::approve( $this->context( $locked, $current, false, true ), $phase, $confirmed, $reason );
					if ( is_wp_error( $decision ) ) {
						return $decision;
					}
					if ( $confirmed_amount !== $decision['amount'] ) {
						return new WP_Error( 'zbm_manual_amount_mismatch', __( 'مبلغ وصول‌شده با مبلغ این مرحله برابر نیست. رسید را با دلیل رد کنید و وضعیت واریز را بررسی کنید؛ هیچ وجهی قطعی نشد.', 'zarbalman-core' ) );
					}
					$offer = $current['data']['manual'][ $phase ];
					if ( 1 !== count( $offer['receipt_ids'] ) || ! $this->evidence->assertOwned( $offer['receipt_ids'], $locked->id(), $locked->buyerId(), 'manual_' . $phase . '_receipt', 1, 1 ) ) {
						return $this->error( 'receipt_binding' );
					}
					if ( 1 === $current['sync_pending'] ) {
						if ( ! ReceiptProjectionPolicy::isReceiptOnlyProjection( $current ) ) {
							return $this->error( 'approval_sync' );
						}
						if ( 'manual_order_sync' === $current['data']['recovery']['operation'] ) {
							if ( 'deposit' !== $phase ) { return $this->error( 'approval_sync' ); }
							// Replay/CAS, policy, amount and evidence have passed. Roll back
							// before Woo recovery; never perform Woo saves in this transaction.
							$recovery_case = $current;
							return $this->error( 'order_recovery_required' );
						}
					}
					if ( ! $locked->wcOrderId() ) {
						return $this->error( 'order' );
					}
					$captured = $this->payments->captureManual( $locked, $locked->wcOrderId(), $phase, $decision['amount'], $offer['offer_id'] );
					if ( is_wp_error( $captured ) ) {
						return $captured;
					}
					$this->ledger->append( $locked->id(), $locked->wcOrderId(), $captured['id'], 'employer_cash_received', 'employer', 0, $decision['amount'], 'recorded', 'manual-cash:' . $offer['offer_id'] );
					$this->ledger->provisionalShares( $locked->id(), $locked->wcOrderId(), $captured['id'], $locked->vendorId(), $decision['amount'] );
					$now = current_time( 'mysql', true );
					$offer['status'] = 'approved';
					$offer['reviewed_at'] = $now;
					$offer['reviewed_by'] = $admin_id;
					$offer['reason'] = $reason;
					$current['data']['manual'][ $phase ] = $offer;
					$current['data']['funds'][ $phase ] = array( 'amount' => $decision['amount'], 'provider' => 'manual_bank', 'payment_id' => $captured['id'] );
					$current['state'] = $decision['state'];
					if ( 'balance' === $phase ) {
						Spec4FinanceService::queueCompletionProjection( $current );
					} else {
						$current['sync_pending'] = 1;
						$current['data']['recovery'] = array( 'event_uuid' => $projection_id, 'operation' => 'manual_deposit_projection', 'last_error_code' => null, 'attempts' => 0 );
					}
					$fields = 'deposit' === $phase ? array( 'current_state' => RequestState::DEPOSIT_PAID, 'payment_ready' => 0, 'deposit_deadline_gmt' => null, 'needs_reconciliation' => 0 ) : array();
					if ( 'balance' === $phase ) {
						$current['deadline_gmt'] = null;
						$current['data']['final']['settlement'] = 'pending';
						if ( isset( $current['data']['spec4'] ) ) {
							$current['data']['spec4']['remaining_toman'] = 0;
							$current['data']['spec4']['substate'] = 'completed';
						}
					}
					return array(
						'case' => $current, 'request_fields' => $fields,
						'audit' => array( 'actor_id' => $admin_id, 'payment_id' => $captured['id'], 'amount_toman' => $decision['amount'], 'confirmed' => true, 'reason_hash' => hash( 'sha256', $reason ) ),
						'notifications' => 'deposit' === $phase ? array() : array(
							array( 'role' => 'buyer', 'user_id' => $locked->buyerId(), 'template' => 'manual_payment_approved_buyer', 'variables' => array( 'amount_toman' => $decision['amount'] ) ),
							array( 'role' => 'vendor', 'user_id' => $locked->vendorId(), 'template' => 'manual_payment_approved_vendor', 'variables' => array() ),
						),
					);
				}
			);
			if ( is_wp_error( $result ) && 'zbm_manual_service_order_recovery_required' === $result->get_error_code() && is_array( $recovery_case ) ) {
				// Keep the outer request lock, use the verified adapter and durable
				// recovery identity, but never advance this approval's expected version.
				$recovered = $this->recoverLocked( $request_id, $recovery_case );
				if ( is_wp_error( $recovered ) ) { return $recovered; }
				return $this->error( 1 === $recovered['sync_pending'] ? 'order_recovery_pending' : 'order_recovered_review' );
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( 'deposit' === $phase ) {
				$this->announceDepositPaid( $request_id );
				$this->announceSyncRequested( $request_id );
				return $result;
			}
			$this->announceSyncRequested( $request_id );
			Spec4FinanceService::announce( $request_id );
			return $result;
		} );
	}

	/** @return array|WP_Error */
	public function reject( int $request_id, int $expected_version, int $admin_id, string $key, string $phase, string $reason ): array|WP_Error {
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( ! in_array( $phase, array( 'deposit', 'balance' ), true ) || ! wp_is_uuid( $key ) || ! AdminCapabilities::userCanManageFinance( $admin_id ) ) {
			return $this->error( 'finance' );
		}
		return $this->locked( $request_id, function () use ( $request_id, $expected_version, $admin_id, $key, $phase, $reason ): array|WP_Error {
			$result = $this->cases->mutate(
				$request_id, $expected_version, 'admin', $admin_id, $key, 'manual_receipt_rejected',
				array( 'actor_id' => $admin_id, 'reason_hash' => hash( 'sha256', $reason ) ),
				function ( PurchaseRequest $locked, array $current ) use ( $admin_id, $phase, $reason ): array|WP_Error {
					$allowed = ManualPaymentPolicy::reject( $this->context( $locked, $current, false, true ), $phase, $reason );
					if ( is_wp_error( $allowed ) ) {
						return $allowed;
					}
					$offer = $current['data']['manual'][ $phase ];
					$offer['status'] = 'rejected';
					$offer['reviewed_at'] = current_time( 'mysql', true );
					$offer['reviewed_by'] = $admin_id;
					$offer['reason'] = $reason;
					$current['data']['manual'][ $phase ] = $offer;
					if ( 'balance' === $phase && isset( $current['data']['spec4'] ) ) {
						$current['data']['spec4']['substate'] = 'balance_due';
						$current['deadline_gmt'] = gmdate( 'Y-m-d H:i:s', time() + Spec4Data::BALANCE_SECONDS );
					}
					$fields = 'deposit' === $phase
						? array(
							'deposit_deadline_gmt' => gmdate( 'Y-m-d H:i:s', time() + PurchaseRequestService::DEPOSIT_TTL_SECONDS ),
							'payment_ready' => 1,
						)
						: array();
					return array( 'case' => $current, 'request_fields' => $fields, 'audit' => array( 'actor_id' => $admin_id, 'reason_hash' => hash( 'sha256', $reason ) ), 'notifications' => array( array( 'role' => 'buyer', 'user_id' => $locked->buyerId(), 'template' => 'manual_receipt_rejected', 'variables' => array( 'amount_toman' => $offer['amount'] ) ) ) );
				}
			);
			if ( ! is_wp_error( $result ) && 'deposit' === $phase ) {
				$this->announceDepositPending( $request_id );
			}
			if ( ! is_wp_error( $result ) && 'balance' === $phase ) { Spec4FinanceService::announce( $request_id ); }
			return $result;
		} );
	}

	/** Separate explicit seller credit; never implied by balance approval. @return array|WP_Error */
	public function creditSeller( int $request_id, int $expected_version, int $admin_id, string $key, string $reason ): array|WP_Error {
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( ! wp_is_uuid( $key ) || ! AdminCapabilities::userCanManageFinance( $admin_id ) ) {
			return $this->error( 'finance' );
		}
		return $this->locked( $request_id, fn (): array|WP_Error => $this->cases->mutate(
			$request_id, $expected_version, 'admin', $admin_id, $key, 'manual_seller_credited',
			array( 'actor_id' => $admin_id, 'reason_hash' => hash( 'sha256', $reason ) ),
			function ( PurchaseRequest $locked, array $current ) use ( $admin_id, $reason ): array|WP_Error {
				if ( isset( $current['data']['spec4'] ) ) { return $this->error( 'spec4_allocation_required' ); }
				$context = $this->context( $locked, $current, false, true );
				$context['sync_pending'] = 1 === $current['sync_pending'];
				$decision = ManualSettlementPolicy::decide( $context, $reason );
				if ( is_wp_error( $decision ) || ! $locked->wcOrderId() ) {
					return is_wp_error( $decision ) ? $decision : $this->error( 'order' );
				}
				$allocation_hash = hash( 'sha256', implode( '|', array( 'zbm-final-v1', $locked->id(), $locked->wcOrderId(), $current['data']['final']['total'], $current['data']['funds']['deposit']['payment_id'], $current['data']['funds']['balance']['payment_id'], $decision['net'] ) ) );
				$credited = $this->settlements->credit( $locked, $locked->wcOrderId(), $decision['net'], $allocation_hash );
				if ( is_wp_error( $credited ) ) {
					return $credited;
				}
				$current['data']['final']['settlement'] = 'credited';
				$current['data']['final']['allocation_hash'] = $allocation_hash;
				$current['data']['final']['credited_at'] = current_time( 'mysql', true );
				return array(
					'case' => $current, 'request_fields' => array(),
					'audit' => array( 'actor_id' => $admin_id, 'order_id' => $locked->wcOrderId(), 'commission_toman' => $decision['commission'], 'net_toman' => $decision['net'], 'reason_hash' => hash( 'sha256', $reason ) ),
					'notifications' => array( array( 'role' => 'vendor', 'user_id' => $locked->vendorId(), 'template' => 'manual_seller_credited', 'variables' => array( 'amount_toman' => $decision['net'] ) ) ),
				);
			}
		) );
	}

	/** Recover one durable Woo projection; safe to call repeatedly. @return array|WP_Error */
	public function recover( int $request_id ): array|WP_Error {
		return $this->locked( $request_id, function () use ( $request_id ): array|WP_Error {
			$case = $this->cases->find( $request_id );
			return null === $case ? $this->error( 'case' ) : $this->recoverLocked( $request_id, $case );
		} );
	}

	/** Repair only the Core payment window; no Woo writes or network calls. */
	public function prepareReceiptWindow( int $request_id, int $actor_id ): array|WP_Error {
		return $this->locked( $request_id, function () use ( $request_id, $actor_id ): array|WP_Error {
			$request = $this->requests->find( $request_id );
			$case = $this->cases->find( $request_id );
			if ( ! $request || ! $case || ( $request->buyerId() !== $actor_id && ! AdminCapabilities::userCanManageFinance( $actor_id ) )
				|| $request->isPaused() || $request->needsReconciliation() || $this->payments->hasUnresolvedOnlineAttempt( $request_id, 'deposit' ) ) {
				return $this->error( 'receipt_window' );
			}
			return $this->ensureManualDepositReadyLocked( $request, $case );
		} );
	}

	/** Same selection for deposit and remainder; existing bank commitments remain receivable. */
	public function availableRoute( PurchaseRequest $request, ?array $case, string $phase ): string {
		if ( ! in_array( $phase, array( 'deposit', 'balance' ), true ) || $request->needsReconciliation() || $request->isPaused() ) { return 'blocked'; }
		return PaymentRoutingPolicy::select( $this->health->onlineAvailableForNewPayment(), $this->settings->enabled(),
			is_array( $case['data']['manual'][ $phase ] ?? null ), $this->payments->hasUnresolvedOnlineAttempt( $request->id(), $phase ) );
	}

	/** Safe role-specific data; never returns the raw aggregate. @return array<string,mixed> */
	public function view( PurchaseRequest $request, ?array $case, string $role, int $actor_id ): array {
		$role = sanitize_key( $role );
		if ( 'vendor' === $role ) {
			// Manual payment is a buyer/finance-admin concern. Keep this boundary
			// closed even if a future caller bypasses the normal panel renderer.
			return array();
		}
		$finance = AdminCapabilities::userCanManageFinance( $actor_id );
		$authorized = match ( $role ) {
			'buyer' => $actor_id === $request->buyerId(),
			'vendor' => $actor_id === $request->vendorId() && $this->vendorEligibility->isApprovedVendor( $actor_id ),
			'admin' => $finance || AdminCapabilities::userCanOperate( $actor_id ),
			default => false,
		};
		if ( ! $authorized || null === $case || $case['request_id'] !== $request->id() ) {
			return array();
		}
		$result = array( 'version' => $case['version'], 'state' => $case['state'], 'mode' => $case['mode'], 'sync_pending' => 1 === $case['sync_pending'], 'receipt_projection_safe' => ReceiptProjectionPolicy::isReceiptOnlyProjection( $case ), 'manual_enabled' => $this->settings->enabled(), 'phases' => array() );
		foreach ( array( 'deposit', 'balance' ) as $phase ) {
			$offer = $case['data']['manual'][ $phase ];
			if ( ! is_array( $offer ) ) {
				$result['phases'][ $phase ] = null;
				continue;
			}
			$row = array( 'amount' => $offer['amount'], 'status' => $offer['status'], 'submitted_at' => $offer['submitted_at'], 'reviewed_at' => $offer['reviewed_at'] );
			if ( 'buyer' === $role || 'admin' === $role ) {
				$row['offer_id'] = $offer['offer_id'];
				$row['reason'] = $offer['reason'];
				$row['buyer_note'] = (string) ( $offer['buyer_note'] ?? '' );
			}
			if ( 'buyer' === $role || $finance ) {
				$row['receipt_links'] = $this->evidence->links( $offer['receipt_ids'], $request->id(), $actor_id );
				$row['receipt_history'] = array_map( fn ( array $history ): array => array( 'submitted_at' => $history['submitted_at'], 'reviewed_at' => $history['reviewed_at'], 'reason' => $history['reason'], 'buyer_note' => (string) ( $history['buyer_note'] ?? '' ), 'links' => $this->evidence->links( $history['receipt_ids'], $request->id(), $actor_id ) ), $offer['receipt_history'] );
				try {
					$account = json_decode( $this->protected->decrypt( 'manual_account_snapshot', $request->id(), $offer['offer_id'], $offer['account_encrypted'] ), true, 4, JSON_THROW_ON_ERROR );
					$row['account'] = is_array( $account ) ? $account : array();
				} catch ( Throwable ) {
					$row['account'] = array();
				}
			}
			$result['phases'][ $phase ] = $row;
		}
		$result['funds'] = array( 'deposit' => $case['data']['funds']['deposit']['amount'], 'balance' => $case['data']['funds']['balance']['amount'] );
		$result['final'] = array( 'total' => $case['data']['final']['total'], 'commission' => $case['data']['final']['commission'], 'net' => $case['data']['final']['net'], 'settlement' => $case['data']['final']['settlement'] );
		return $result;
	}

	/** @param callable(): (array|WP_Error) $operation */
	private function locked( int $request_id, callable $operation ): array|WP_Error {
		if ( $request_id < 1 ) {
			return $this->error( 'request' );
		}
		try {
			$this->lock->acquire( $request_id, 10 );
		} catch ( Throwable ) {
			return $this->error( 'busy' );
		}
		try {
			return $operation();
		} catch ( Throwable ) {
			return $this->error( 'unavailable' );
		} finally {
			try {
				$this->lock->release( $request_id );
			} catch ( Throwable ) {
				// A lock-release failure must not replace a committed result.
			}
		}
	}

	/** @return array<string,mixed> */
	private function context( PurchaseRequest $request, array $case, bool $owner, bool $finance ): array {
		$final = $case['data']['final'];
		$deposit_deadline = $request->depositDeadlineGmt();
		return array(
			'mode' => $case['mode'], 'owner' => $owner, 'finance' => $finance, 'enabled' => $this->settings->enabled(),
			'paused' => $request->isPaused() || $case['data']['pause']['active'], 'reconciliation' => $request->needsReconciliation(),
			'online_unknown' => $this->payments->hasUnresolvedOnlineAttempt( $request->id(), in_array( $case['state'], array( FlowState::FINAL_WEIGHT_LOCKED, FlowState::BALANCE_DUE ), true ) ? 'balance' : 'deposit' ),
			'state' => $case['state'], 'request_state' => $request->state(), 'deposit_due' => $request->depositToman(),
			'deposit' => $case['data']['funds']['deposit']['amount'], 'balance' => $case['data']['funds']['balance']['amount'],
			'final_total' => $final['total'], 'final_locked' => null !== $final['locked_at'], 'delivered' => null !== $case['data']['delivery']['delivered_at'],
			'operations_only' => RequestPricingPolicy::OPERATIONAL_LOCK === ( $final['lock_version'] ?? null ) && ! isset( $case['data']['spec4'] ),
			'manual' => $case['data']['manual'], 'sync_pending' => 1 === $case['sync_pending'], 'settlement' => $final['settlement'], 'total' => $final['total'],
			'receipt_projection_safe' => ReceiptProjectionPolicy::isReceiptOnlyProjection( $case ),
			'deposit_payable' => RequestState::DEPOSIT_PENDING === $request->state() && $request->paymentReady() && is_string( $deposit_deadline ) && $deposit_deadline > current_time( 'mysql', true ),
			'balance_payable' => isset( $case['data']['spec4'] ) && in_array( $case['data']['spec4']['substate'], array( 'balance_due', 'warning' ), true ) && is_string( $case['deadline_gmt'] ) && $case['deadline_gmt'] > current_time( 'mysql', true ),
		);
	}

	/** @return array<string,mixed> */
	private function previewCase( PurchaseRequest $request ): array {
		if ( ! in_array( $request->state(), array( RequestState::SUPPLIER_ACCEPTED, RequestState::DEPOSIT_PENDING, RequestState::DEPOSIT_PAID ), true ) ) {
			throw new RuntimeException( 'Request has no managed preview state.' );
		}
		return array( 'request_id' => $request->id(), 'version' => 0, 'state' => RequestState::DEPOSIT_PAID === $request->state() ? FlowState::PREPARING : FlowState::AWAITING_DEPOSIT, 'mode' => $request->executionMode(), 'sync_pending' => 0, 'deadline_gmt' => null, 'closed_at_gmt' => null, 'data' => ManagedCaseRepository::initialData( $request ) );
	}

	private function phaseByOffer( array $case, string $offer_id ): ?string {
		foreach ( array( 'deposit', 'balance' ) as $phase ) {
			$offer = $case['data']['manual'][ $phase ] ?? null;
			if ( is_array( $offer ) && is_string( $offer['offer_id'] ?? null ) && hash_equals( $offer['offer_id'], $offer_id ) ) {
				return $phase;
			}
		}
		return null;
	}

	private function announceDepositPaid( int $request_id ): void {
		try {
			do_action( 'zbm_purchase_request_deposit_paid', $request_id, 0 );
		} catch ( Throwable ) {
			// Confirmed funds remain authoritative; the managed bootstrap sweep retries independently.
		}
	}

	/** @return array|WP_Error */
	private function recoverLocked( int $request_id, array $case ): array|WP_Error {
		if ( 0 === $case['sync_pending'] ) {
			return $case;
		}
		$request = $this->requests->find( $request_id );
		$recovery = $case['data']['recovery'];
		if ( ! $request || ! is_string( $recovery['event_uuid'] ) || ! wp_is_uuid( $recovery['event_uuid'] ) || ! is_string( $recovery['operation'] ) ) {
			return $this->error( 'recovery_identity' );
		}
		try {
			$order_id = $request->wcOrderId() ?? 0;
			if ( 'manual_order_sync' === $recovery['operation'] ) {
				$order_id = $this->orders->ensure( $request );
			} elseif ( 'manual_deposit_projection' === $recovery['operation'] ) {
				$this->orders->depositConfirmed( $request, $case );
			} elseif ( in_array( $recovery['operation'], array( 'manual_completion_projection', 'managed_completion_projection' ), true ) ) {
				$this->orders->completed( $request );
			} else {
				return $this->error( 'recovery_operation' );
			}
		} catch ( Throwable ) {
			return $this->recordRecoveryFailure( $request, $case, 'woo_projection_failed' );
		}
		$result = $this->cases->mutate(
			$request_id, $case['version'], 'system', 0, $recovery['event_uuid'], 'managed_recovery',
			array( 'order_id' => $order_id, 'count' => $recovery['attempts'] ),
			function ( PurchaseRequest $locked, array $current ) use ( $recovery, $order_id ): array|WP_Error {
				if ( 1 !== $current['sync_pending'] || $current['data']['recovery']['event_uuid'] !== $recovery['event_uuid'] || $current['data']['recovery']['operation'] !== $recovery['operation'] ) {
					return $this->error( 'recovery_stale' );
				}
				$fields = array();
				if ( 'manual_order_sync' === $recovery['operation'] ) {
					if ( null !== $locked->wcOrderId() && $locked->wcOrderId() !== $order_id ) {
						return $this->error( 'order_conflict' );
					}
					$receipt_submitted = 'submitted' === ( $current['data']['manual']['deposit']['status'] ?? null );
					$deadline = $receipt_submitted ? null : ( $locked->depositDeadlineGmt() ?? gmdate( 'Y-m-d H:i:s', time() + PurchaseRequestService::DEPOSIT_TTL_SECONDS ) );
					$fields = array(
						'wc_order_id' => $order_id,
						'stock_reserved' => 1,
						'stock_released' => 0,
						'deposit_deadline_gmt' => $deadline,
						'payment_ready' => $receipt_submitted ? 0 : 1,
					);
				}
				$current['sync_pending'] = 0;
				$current['data']['recovery'] = array( 'event_uuid' => null, 'operation' => null, 'last_error_code' => null, 'attempts' => $current['data']['recovery']['attempts'] );
				return array( 'case' => $current, 'request_fields' => $fields, 'audit' => array( 'order_id' => $order_id, 'count' => $current['data']['recovery']['attempts'] ), 'notifications' => array() );
			}
		);
		if ( ! is_wp_error( $result ) ) {
			if ( 'manual_order_sync' === $recovery['operation'] ) {
				$this->announceDepositPending( $request_id );
			} elseif ( 'manual_deposit_projection' === $recovery['operation'] ) {
				$this->announceDepositPaid( $request_id );
			}
		}
		return $result;
	}

	/** Financial truth remains committed; this records only a bounded projection retry. @return array|WP_Error */
	private function recordRecoveryFailure( PurchaseRequest $request, array $case, string $code ): array|WP_Error {
		$count = min( 1000, (int) $case['data']['recovery']['attempts'] + 1 );
		$result = $this->cases->mutate(
			$request->id(), $case['version'], 'system', 0, wp_generate_uuid4(), 'managed_recovery',
			array( 'count' => $count ),
			function ( PurchaseRequest $locked, array $current ) use ( $case, $code, $count ): array|WP_Error {
				if ( 1 !== $current['sync_pending'] || $current['data']['recovery']['event_uuid'] !== $case['data']['recovery']['event_uuid'] ) {
					return $this->error( 'recovery_stale' );
				}
				$current['data']['recovery']['last_error_code'] = $code;
				$current['data']['recovery']['attempts'] = $count;
				return array( 'case' => $current, 'request_fields' => array(), 'audit' => array( 'count' => $count ), 'notifications' => array() );
			}
		);
		if ( ! is_wp_error( $result ) ) {
			$this->announceSyncRequested( $request->id() );
		}
		return $result;
	}

	/** Restore a usable bank obligation's Core window without depending on Woo. @return array|WP_Error */
	private function ensureManualDepositReadyLocked( PurchaseRequest $request, array $case ): array|WP_Error {
		$deposit_offer = $case['data']['manual']['deposit'] ?? null;
		if (
			( 1 === $case['sync_pending'] && ! ReceiptProjectionPolicy::isReceiptOnlyProjection( $case ) )
			|| RequestState::DEPOSIT_PENDING !== $request->state()
			|| ! is_array( $deposit_offer )
			|| ! in_array( $deposit_offer['status'] ?? null, array( 'offered', 'rejected' ), true )
		) {
			return $case;
		}
		$deadline = $request->depositDeadlineGmt();
		if ( $request->paymentReady() && null !== $deadline ) {
			return $case;
		}
		if ( null !== $deadline && $deadline <= current_time( 'mysql', true ) ) {
			return $case;
		}
		$deadline ??= gmdate( 'Y-m-d H:i:s', time() + PurchaseRequestService::DEPOSIT_TTL_SECONDS );
		$result = $this->cases->mutate(
			$request->id(), $case['version'], 'system', 0, wp_generate_uuid4(), 'manual_deposit_window_ready',
			array( 'order_id' => $request->wcOrderId() ?? 0 ),
			function ( PurchaseRequest $locked, array $current ) use ( $deadline ): array|WP_Error {
				$locked_offer = $current['data']['manual']['deposit'] ?? null;
				if (
					( 1 === $current['sync_pending'] && ! ReceiptProjectionPolicy::isReceiptOnlyProjection( $current ) )
					|| RequestState::DEPOSIT_PENDING !== $locked->state()
					|| ! is_array( $locked_offer )
					|| ! in_array( $locked_offer['status'] ?? null, array( 'offered', 'rejected' ), true )
				) {
					return $this->error( 'deposit_ready' );
				}
				return array(
					'case' => $current,
					'request_fields' => array( 'deposit_deadline_gmt' => $deadline, 'payment_ready' => 1 ),
					'audit' => array( 'order_id' => $locked->wcOrderId() ?? 0 ),
					'notifications' => array(),
				);
			}
		);
		if ( ! is_wp_error( $result ) ) {
			$this->announceDepositPending( $request->id() );
		}
		return $result;
	}

	private function announceDepositPending( int $request_id ): void {
		try {
			$request = $this->requests->find( $request_id );
			if ( $request && RequestState::DEPOSIT_PENDING === $request->state() && $request->paymentReady() && null !== $request->depositDeadlineGmt() ) {
				do_action( 'zbm_purchase_request_deposit_pending', $request_id, $request->version() );
			}
		} catch ( Throwable ) {
			// The one-minute deadline sweep remains authoritative if exact scheduling fails.
		}
	}

	private function announceSyncRequested( int $request_id ): void {
		try {
			do_action( 'zbm_managed_sync_requested', $request_id );
		} catch ( Throwable ) {
			// The bounded five-minute sync sweep remains the fallback.
		}
	}

	private function syncPendingError(): WP_Error {
		return new WP_Error(
			'zbm_manual_service_sync_pending',
			__( 'سفارش پرداخت دستی هنوز در حال آماده‌سازی است؛ رسیدی ثبت نشد. چند لحظه بعد صفحه را تازه کنید و دوباره بفرستید.', 'zarbalman-core' )
		);
	}

	/** @return list<int> */
	private function financeAdminIds(): array {
		$ids = get_users( array( 'role' => 'administrator', 'fields' => 'ids', 'number' => 10, 'orderby' => 'ID', 'order' => 'ASC' ) );
		return array_values( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : array() ), static fn ( int $id ): bool => AdminCapabilities::userCanManageFinance( $id ) ) );
	}

	private function error( string $code ): WP_Error {
		$message = match ( $code ) {
			'finance' => __( 'برای این اقدام دسترسی مدیریت مالی لازم است.', 'zarbalman-core' ),
			'command' => __( 'شناسه یا نسخهٔ اقدام معتبر نیست؛ صفحه را تازه و فرم را دوباره بررسی کنید.', 'zarbalman-core' ),
			'phase' => __( 'مرحلهٔ پرداخت معتبر نیست؛ پیش‌پرداخت یا ماندهٔ همین درخواست را بررسی کنید.', 'zarbalman-core' ),
			'amount' => __( 'مبلغ وصول‌شده باید عدد صحیح مثبت در محدودهٔ مجاز و به تومان باشد.', 'zarbalman-core' ),
			'stale', 'recovery_stale' => __( 'نسخهٔ پرونده تغییر کرده است؛ صفحه را تازه و رسید و مبلغ را دوباره بررسی کنید.', 'zarbalman-core' ),
			'order' => __( 'سفارش معتبر به درخواست متصل نیست و مسیر بازیابی آماده‌ای وجود ندارد. رسید محفوظ است؛ اتصال سفارش باید بررسی شود و وجه تأیید نشد.', 'zarbalman-core' ),
			'order_conflict' => __( 'شناسهٔ سفارش بازیابی‌شده با سفارش درخواست تعارض دارد؛ اتصال مالی باید بررسی شود.', 'zarbalman-core' ),
			'order_recovery_required', 'order_recovery_pending' => __( 'بازیابی سفارش معتبر هنوز کامل نشده است؛ رسید محفوظ است و وجه تأیید نشد. وضعیت هماهنگی سفارش را بررسی و سپس صفحه را تازه کنید.', 'zarbalman-core' ),
			'order_recovered_review' => __( 'سفارش معتبر بازیابی شد؛ وجه هنوز تأیید نشده است. صفحه را تازه کنید و پس از بازبینی رسید و مبلغ، تأیید را دوباره انجام دهید.', 'zarbalman-core' ),
			'approval_sync', 'recovery_identity', 'recovery_operation' => __( 'وضعیت هماهنگی پرونده برای تأیید وجه معتبر نیست؛ ابتدا بازیابی سفارش و اسناد مالی را بررسی کنید. رسید محفوظ است.', 'zarbalman-core' ),
			'receipt_binding' => __( 'تعداد یا اتصال خصوصی رسید به خریدار و مرحلهٔ این درخواست معتبر نیست؛ وجه تأیید نشد و مدرک باید بررسی شود.', 'zarbalman-core' ),
			'busy' => __( 'پرونده در حال پردازش است؛ پس از چند لحظه صفحه را تازه و وضعیت را بررسی کنید.', 'zarbalman-core' ),
			'unavailable' => __( 'نتیجهٔ عملیات در دسترس نیست؛ پیش از ارسال دوباره، وضعیت تازهٔ پرونده و ثبت مالی را بررسی کنید.', 'zarbalman-core' ),
			default => __( 'عملیات پرداخت دستی ثبت نشد؛ وضعیت، نسخه و دسترسی پرونده را دوباره بررسی کنید.', 'zarbalman-core' ),
		};
		return new WP_Error( 'zbm_manual_service_' . $code, $message );
	}
}
