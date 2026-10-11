<?php

declare(strict_types=1);

namespace Zarbalman\Core\Application;

use Throwable;
use WP_Error;
use WC_Order;
use WC_Order_Item_Product;
use Zarbalman\Core\Domain\ManagedFlow\Amounts;
use Zarbalman\Core\Domain\ManagedFlow\Deadline;
use Zarbalman\Core\Domain\ManagedFlow\DeliveryCode;
use Zarbalman\Core\Domain\ManagedFlow\DeliveryWindowPolicy;
use Zarbalman\Core\Domain\ManagedFlow\FlowPolicy;
use Zarbalman\Core\Domain\ManagedFlow\FlowState;
use Zarbalman\Core\Domain\ManagedFlow\OperationsData;
use Zarbalman\Core\Domain\Order\WeightUnitPolicy;
use Zarbalman\Core\Domain\PurchaseRequest\DeliveryMethod;
use Zarbalman\Core\Domain\PurchaseRequest\ExecutionMode;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Domain\PurchaseRequest\RequestPricingPolicy;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;
use Zarbalman\Core\Domain\PurchaseRequest\ShippingQuote;
use Zarbalman\Core\Infrastructure\DeliveryCodeSecret;
use Zarbalman\Core\Infrastructure\DeliveryDataCrypto;
use Zarbalman\Core\Infrastructure\ManagedCaseRepository;
use Zarbalman\Core\Infrastructure\ManagedEvidence;
use Zarbalman\Core\Infrastructure\ProtectedText;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;
use Zarbalman\Core\Integration\DokanStoreAddressAdapter;
use Zarbalman\Core\Integration\DokanVendorEligibility;
use Zarbalman\Core\Integration\ManagedOrderSnapshot;
use Zarbalman\Core\Security\AdminCapabilities;

/** Canonical Spec 3 logistics orchestration. Browser values never decide identity, money or state. */
final class ManagedFlowService {
	private const BUYER_WINDOW = 7200;
	private const VENDOR_WINDOW = 1800;
	private const MAX_EXTENSION = 604800;

	public function __construct(
		private readonly PurchaseRequestRepository $requests,
		private readonly ManagedCaseRepository $cases,
		private readonly ManagedEvidence $evidence,
		private readonly FlowPolicy $policy,
		private readonly WeightUnitPolicy $weightUnit,
		private readonly DeliveryCodeSecret $codeSecret,
		private readonly ProtectedText $protected,
		private readonly DeliveryDataCrypto $deliveryCrypto,
		private readonly DokanStoreAddressAdapter $pickupAddress,
		private readonly DokanVendorEligibility $vendorEligibility
	) {}

	/** Create/finish the non-financial managed bootstrap after authoritative deposit confirmation. @return array|WP_Error */
	public function synchronizeDeposit( int $request_id ): array|WP_Error {
		try {
			$request = $this->requests->find( $request_id );
		} catch ( Throwable ) {
			return $this->error( 'storage' );
		}
		if ( ! $request || RequestState::DEPOSIT_PAID !== $request->state() || $request->needsReconciliation() || ! in_array( $request->executionMode(), array( ExecutionMode::LIVE, ExecutionMode::TEST ), true ) ) {
			return $this->error( 'deposit' );
		}
		try {
			$case = $this->cases->find( $request_id );
		} catch ( Throwable ) {
			return $this->error( 'storage' );
		}
		if ( ! $request->wcOrderId() ) {
			return $this->error( 'order_identity' );
		}
		if ( is_array( $case ) && ! self::needsDepositBootstrap( $request, $case ) ) {
			return $case;
		}
		$expected_version = $case['version'] ?? 0;
		$address = null;
		if ( null === $case || null === ( $case['data']['delivery']['address_kind'] ?? null ) ) {
			$address = $this->addressSnapshot( $request );
			if ( DeliveryMethod::DELIVERY_TO_BUYER_ADDRESS === $request->deliveryMethod() && is_wp_error( $address ) ) {
				return $address;
			}
			if ( is_wp_error( $address ) ) {
				$address = null;
			}
		}
		$key = $this->stableUuid( 'managed-deposit-sync-v2|' . $request->publicId() );
		$result = $this->cases->mutate(
			$request_id,
			$expected_version,
			'system',
			0,
			$key,
			'managed_sync_deposit',
			array( 'request_id' => $request_id, 'order_id' => $request->wcOrderId() ),
			function ( PurchaseRequest $locked, array $current ) use ( $request, $address ): array|WP_Error {
				if ( $locked->wcOrderId() !== $request->wcOrderId() || ! $this->cases->depositVerified( $locked, $current, true ) ) {
					return $this->error( 'deposit_locked' );
				}
				if ( FlowState::AWAITING_DEPOSIT === $current['state'] ) {
					$current['state'] = FlowState::PREPARING;
				}
				// Canonical request line remains stable; Woo item insertion is only
				// a secondary projection. Existing numeric line IDs are never replaced.
				$current['data']['operations'] = OperationsData::initial( $locked, current_time( 'mysql', true ) );
				if ( null === $current['data']['delivery']['address_kind'] && is_array( $address ) ) {
					$current['data']['delivery']['address_kind'] = $address['kind'];
					$current['data']['delivery']['address_encrypted'] = $address['encrypted'];
				}
				return $this->result(
					$current,
					array(),
					array( 'order_id' => $locked->wcOrderId() ),
					FlowState::PREPARING === $current['state'] ? array(
						array( 'role' => 'buyer', 'user_id' => $locked->buyerId(), 'template' => 'managed_deposit_received_buyer', 'variables' => array() ),
						array( 'role' => 'vendor', 'user_id' => $locked->vendorId(), 'template' => 'managed_preparation_started', 'variables' => array() )
					) : array()
				);
			}
		);
		$this->announceDeadline( $request_id, $result );
		return $result;
	}

	/**
	 * Apply one role action. Files are uploaded privately before the atomic bind; failed binds remain private orphans.
	 * @param array<string,mixed> $payload
	 * @return array|WP_Error
	 */
	public function act( int $request_id, int $version, string $role, int $actor_id, string $key, string $action, array $payload, mixed $files = null ): array|WP_Error {
		$role = sanitize_key( $role );
		$action = sanitize_key( $action );
		if ( $request_id < 1 || $version < 0 || ! wp_is_uuid( $key ) || ! $this->roleAllowed( $role, $actor_id, $action ) ) {
			return $this->error( 'command' );
		}
		try {
			$request = $this->requests->find( $request_id );
			$case = $this->cases->find( $request_id );
		} catch ( Throwable ) {
			return $this->error( 'storage' );
		}
		if ( ! $request || ! $case || $case['version'] !== $version || $case['request_id'] !== $request_id || RequestState::DEPOSIT_PAID !== $request->state() || $request->needsReconciliation() || $case['mode'] !== $request->executionMode() ) {
			return $this->error( 'stale' );
		}
		if ( ! $this->ownsRole( $request, $role, $actor_id ) ) {
			return $this->error( 'owner' );
		}
		if ( self::needsDepositBootstrap( $request, $case ) ) {
			return $this->error( 'bootstrap_pending' );
		}
		if ( $this->operationalBlocked( $request, $case ) ) {
			return $this->error( 'deposit_proof' );
		}
		$line_key = $this->selectedLine( $case, $action, $payload );
		if ( null === $line_key ) { return $this->error( 'line' ); }
		$line = $case['data']['lines'][ $line_key ];
		if ( $this->isLineAction( $action ) && null !== $line['final_tenths'] ) { return $this->error( 'submission_locked' ); }
		$window = $case['data']['operations']['window'] ?? null;
		if ( in_array( $role, array( 'buyer', 'vendor' ), true ) && is_array( $window ) && in_array( $case['state'], OperationsData::PRE_DELIVERY, true ) && current_time( 'mysql', true ) >= $window['end_gmt'] ) {
			return $this->error( 'delivery_window_expired' );
		}
		if ( in_array( $action, array( 'record_weight', 'propose_weight', 'object' ), true ) ) {
			if ( $request->isPaused() || $case['data']['pause']['active'] ) { return $this->error( 'paused' ); }
			if ( 'object' === $action && ! ( ( 'buyer' === $role && FlowState::BUYER_REVIEW === $case['state'] ) || ( 'vendor' === $role && FlowState::SELLER_REVIEW === $case['state'] ) ) ) { return $this->error( 'submission_stage' ); }
			if ( 'record_weight' !== $action && ( ! is_string( $case['deadline_gmt'] ) || current_time( 'mysql', true ) >= $case['deadline_gmt'] ) ) { return $this->error( 'deadline' ); }
		}
		if ( ( 'record_weight' === $action && ( 'vendor' !== $role || FlowState::PREPARING !== $case['state'] || null !== $line['vendor_tenths'] || array() !== $line['vendor_evidence'] ) )
			|| ( 'propose_weight' === $action && ( 'buyer' !== $role || FlowState::BUYER_REVIEW !== $case['state'] || null !== $line['buyer_tenths'] || array() !== $line['buyer_evidence'] ) )
			|| ( 'accept_weight' === $action && 'buyer' === $role ) ) {
			return $this->error( 'submission_locked' );
		}
		$prepared = $this->prepare( $request, $case, $role, $actor_id, $action, $payload, $files );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$fingerprint = array( 'actor_id' => $actor_id, 'line_id' => (string) $line_key );
		foreach ( array( 'weight_tenths', 'generation', 'attempts', 'count', 'acknowledged' ) as $field ) {
			if ( isset( $prepared[ $field ] ) && ( is_int( $prepared[ $field ] ) || is_bool( $prepared[ $field ] ) ) ) {
				$fingerprint[ $field ] = $prepared[ $field ];
			}
		}
		foreach ( array( 'reason_hash', 'evidence_hash', 'code_hash', 'note_hash' ) as $field ) {
			if ( isset( $prepared[ $field ] ) ) {
				$fingerprint[ $field ] = $prepared[ $field ];
			}
		}
		$result = $this->cases->mutate(
			$request_id,
			$version,
			$role,
			$actor_id,
			$key,
			'managed_' . $action,
			$fingerprint,
			fn ( PurchaseRequest $locked, array $current ): array|WP_Error => $this->apply( $locked, $current, $role, $actor_id, $action, $prepared )
		);
		$this->announceDeadline( $request_id, $result );
		$this->announceSync( $request_id, $result );
		if ( ! is_wp_error( $result ) && null !== $result['data']['final']['locked_at'] ) { Spec4FinanceService::announce( $request_id ); }
		return $result;
	}

	/** Exact-event/recovery entry point. Version and deadline are rechecked again under mutate. @return array|WP_Error */
	public function expireReview( int $request_id, int $expected_version, string $expected_deadline ): array|WP_Error {
		try {
			$request = $this->requests->find( $request_id );
			$case = $this->cases->find( $request_id );
			$deadline = Deadline::timestamp( $expected_deadline );
			$now = Deadline::timestamp( current_time( 'mysql', true ) );
		} catch ( Throwable ) {
			return $this->error( 'deadline' );
		}
		if (
			! $request
			|| ! $case
			|| $case['version'] !== $expected_version
			|| $case['deadline_gmt'] !== $expected_deadline
			|| ! in_array( $case['state'], array( FlowState::BUYER_REVIEW, FlowState::SELLER_REVIEW ), true )
			|| $request->isPaused()
			|| $case['data']['pause']['active']
			|| ! is_int( $deadline )
			|| ! is_int( $now )
			|| $now < $deadline
		) {
			return $this->error( 'deadline' );
		}
		$key = $this->stableUuid( implode( '|', array( 'managed-timeout', $request->publicId(), $expected_version, $expected_deadline ) ) );
		return $this->act( $request_id, $expected_version, 'system', 0, $key, 'timeout', array() );
	}

	/** Legacy active cases are adopted once; finalized history is not migrated by viewing. */
	public static function needsDepositBootstrap( PurchaseRequest $request, ?array $case ): bool {
		return RequestState::DEPOSIT_PAID === $request->state()
			&& ! $request->needsReconciliation()
			&& is_array( $case )
			&& ( $case['request_id'] ?? null ) === $request->id()
			&& ! FlowState::isClosed( $case['state'] )
			&& null === ( $case['data']['final']['locked_at'] ?? null )
			&& ( $case['mode'] ?? null ) === $request->executionMode()
			&& ! isset( $case['data']['operations']['bootstrapped_at'] );
	}

	/** UI hint only; every submitted action repeats this proof inside the locked transaction. */
	public function operationalBlocked( PurchaseRequest $request, array $case ): bool {
		try {
			return self::needsDepositBootstrap( $request, $case ) || ! $this->cases->depositVerified( $request, $case );
		} catch ( Throwable ) {
			return true;
		}
	}

	/** Bounded server-owned setting: 0 disables reminders, never overdue supervision. */
	public static function deliveryReminderSeconds(): int {
		$value = get_option( 'zbm_core_delivery_reminder_minutes', 60 );
		$minutes = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0, 'max_range' => 1440 ) ) );
		return false === $minutes || ( $minutes > 0 && $minutes < 5 ) ? 3600 : $minutes * 60;
	}

	/** Stale scheduled generations are harmless; every effect is idempotent under the request lock. */
	public function deliveryTick( int $request_id, int $generation ): array|WP_Error {
		try {
			$request = $this->requests->find( $request_id );
			$case = $this->cases->find( $request_id );
			$window = $case['data']['operations']['window'] ?? null;
			if ( ! $request || ! $case || ! is_array( $window ) || $generation !== $window['generation'] || ! in_array( $case['state'], OperationsData::PRE_DELIVERY, true )
				|| $request->isPaused() || $case['data']['pause']['active'] || null !== $case['data']['delivery']['delivered_at'] || $this->operationalBlocked( $request, $case ) ) { return $this->error( 'window_stale' ); }
			$due = DeliveryWindowPolicy::due( $window, current_time( 'mysql', true ), self::deliveryReminderSeconds() );
			if ( null === $due ) { return $case; }
			$key = $this->stableUuid( 'delivery-window|' . $request->publicId() . '|' . $generation . '|' . $due );
			$result = $this->cases->mutate( $request_id, $case['version'], 'system', 0, $key, 'managed_delivery_' . $due,
				array( 'generation' => $generation ), function ( PurchaseRequest $locked, array $current ) use ( $generation, $due ): array|WP_Error {
					$now = current_time( 'mysql', true );
					$window = $current['data']['operations']['window'] ?? null;
					if ( ! is_array( $window ) || $window['generation'] !== $generation || ! in_array( $current['state'], OperationsData::PRE_DELIVERY, true )
						|| $locked->isPaused() || $current['data']['pause']['active'] || null !== $current['data']['delivery']['delivered_at'] || ! $this->cases->depositVerified( $locked, $current, true )
						|| $due !== DeliveryWindowPolicy::due( $window, $now, self::deliveryReminderSeconds() ) ) { return $this->error( 'window_stale' ); }
					if ( 'overdue' === $due ) {
						$window['overdue_at'] = $now;
						$window['resume_state'] = $current['state'];
						$current['state'] = FlowState::ADMIN_REVIEW;
						$current['deadline_gmt'] = null;
						$notices = $this->reviewNotices( $locked, 'managed_delivery_overdue' );
						foreach ( $notices as &$notice ) { $notice['variables']['window_generation'] = $generation; }
						unset( $notice );
					} else {
						$window['reminded_at'] = $now;
						$notices = $this->pairNotices( $locked, 'managed_delivery_reminder', array( 'deadline' => $window['start_gmt'], 'window_generation' => $generation ) );
					}
					$current['data']['operations']['window'] = $window;
					return $this->result( $current, array(), array( 'generation' => $generation ), $notices );
				} );
			return $result;
		} catch ( Throwable ) {
			return $this->error( 'window_storage' );
		}
	}

	/** Safe role view; raw aggregate, manual receipts, buyer contacts and code are never exposed to vendor. @return array<string,mixed> */
	public function view( PurchaseRequest $request, ?array $case, string $role, int $actor_id ): array {
		$role = sanitize_key( $role );
		if ( null === $case || $case['request_id'] !== $request->id() || ! $this->ownsRole( $request, $role, $actor_id ) ) { return array(); }
		$operations = $case['data']['operations'] ?? array();
		$window = $operations['window'] ?? null;
		$expired = is_array( $window ) && in_array( $case['state'], OperationsData::PRE_DELIVERY, true ) && $window['end_gmt'] <= current_time( 'mysql', true );
		$result = array(
			'version' => $case['version'], 'state' => $case['state'],
			'paused' => $request->isPaused() || $case['data']['pause']['active'],
			'operational_blocked' => $this->operationalBlocked( $request, $case ),
			'delivery_expired' => $expired,
			'deadline_gmt' => $case['deadline_gmt'], 'delivery_method' => $request->deliveryMethod(),
			'window' => is_array( $window ) ? array_intersect_key( $window, array_flip( array( 'generation', 'start_gmt', 'end_gmt', 'overdue_at' ) ) ) : array( 'start_gmt' => $request->deliveryStartGmt(), 'end_gmt' => $request->deliveryEndGmt(), 'overdue_at' => null ),
			'times' => array( 'prepared_at' => $operations['prepared_at'] ?? null, 'dispatched_at' => $operations['dispatched_at'] ?? null, 'delivered_at' => $case['data']['delivery']['delivered_at'], 'buyer_submitted_at' => $operations['buyer_submitted_at'] ?? null ),
			'lines' => array(), 'evidence_history' => array(),
			'public_notes' => $case['data']['notes']['public'],
		);
		foreach ( $case['data']['lines'] as $line_id => $line ) {
			$row = array(
				'line_id' => (string) $line_id, 'reference_tenths' => $line['reference_tenths'],
				'name' => $line['snapshot']['name'] ?? '', 'quantity' => $line['snapshot']['quantity'] ?? $request->quantity(),
				'vendor_tenths' => $line['vendor_tenths'], 'buyer_tenths' => $line['buyer_tenths'], 'final_tenths' => $line['final_tenths'],
				'buyer_reason' => $line['buyer_reason'], 'vendor_reason' => $line['vendor_reason'],
			);
			foreach ( array( 'vendor_evidence', 'buyer_evidence', 'buyer_objection', 'vendor_objection' ) as $key ) {
				$row[ $key ] = $this->evidence->links( $line[ $key ], $request->id(), $actor_id );
			}
			$result['lines'][] = $row;
		}
		foreach ( $operations['evidence_history'] ?? array() as $entry ) {
			$result['evidence_history'][] = array( 'name' => $case['data']['lines'][ $entry['line_id'] ?? '' ]['snapshot']['name'] ?? '', 'purpose' => $entry['purpose'], 'weight_tenths' => $entry['weight_tenths'], 'submitted_at' => $entry['submitted_at'], 'archived_at' => $entry['archived_at'], 'reason' => $entry['reason'], 'evidence' => $this->evidence->links( $entry['evidence'], $request->id(), $actor_id ) );
		}
		// Return before constructing any finance, bank receipt, internal note or code data.
		if ( 'vendor' === $role ) { return $result; }
		$result['mode'] = $case['mode'];
		$result['sync_pending'] = 1 === $case['sync_pending'];
		$result['operations_only'] = RequestPricingPolicy::OPERATIONAL_LOCK === ( $case['data']['final']['lock_version'] ?? null ) && ! isset( $case['data']['spec4'] );
		$result['delivery'] = array( 'generation' => $case['data']['delivery']['generation'], 'attempts' => $case['data']['delivery']['attempts'], 'delivered_at' => $case['data']['delivery']['delivered_at'], 'address' => $this->decryptAddress( $request, $case ) );
		$result['notes'] = array( 'public' => $case['data']['notes']['public'] );
		$result['funds'] = array( 'deposit' => $case['data']['funds']['deposit']['amount'], 'balance' => $case['data']['funds']['balance']['amount'] );
		$result['final'] = array_intersect_key( $case['data']['final'], array_flip( array( 'total', 'product', 'shipping', 'remaining', 'locked_at' ) ) );
		if ( isset( $case['data']['spec4'] ) ) { $result['final']['remaining'] = $case['data']['spec4']['remaining_toman']; }
		if ( ! array_key_exists( 'remaining', $result['final'] ) && is_int( $result['final']['total'] ?? null ) ) {
			$result['final']['remaining'] = $result['final']['total'] - $result['funds']['deposit'] - $result['funds']['balance'];
		}
		if ( 'admin' === $role ) {
			$result['final']['commission'] = $case['data']['final']['commission'];
			$result['final']['net'] = $case['data']['final']['net'];
			$result['final']['settlement'] = $case['data']['final']['settlement'];
			$result['notes']['private'] = $case['data']['notes']['private'];
			$result['window_history'] = $window['history'] ?? array();
			$result['recovery'] = $case['data']['recovery'];
		}
		if ( 'buyer' === $role && ! $result['paused'] && ! $result['operational_blocked'] && ! $expired
			&& in_array( $case['state'], array( FlowState::READY_FOR_PICKUP, FlowState::IN_TRANSIT ), true )
			&& null !== $case['data']['delivery']['encrypted_code'] && null === $case['data']['delivery']['delivered_at'] ) {
			$result['delivery']['code'] = $this->decryptCode( $request, $case );
		}
		return $result;
	}

	/** @return array<string,mixed>|WP_Error */
	private function prepare( PurchaseRequest $request, array $case, string $role, int $actor_id, string $action, array $payload, mixed $files ): array|WP_Error {
		$prepared = array( 'line_id' => (string) $this->selectedLine( $case, $action, $payload ) );
		if ( in_array( $action, array( 'record_weight', 'accept_weight', 'propose_weight', 'set_final_weight' ), true ) ) {
			if ( ! $this->weightUnit->is_satisfied() ) {
				return $this->weightUnit->error();
			}
			if ( 'accept_weight' !== $action ) {
				try {
					$prepared['weight_tenths'] = Amounts::weight( $this->normalizeWeight( $payload['weight'] ?? '' ) );
				} catch ( Throwable ) {
					return $this->error( 'weight' );
				}
			}
			$prepared['acknowledged'] = $this->truthy( $payload['acknowledged'] ?? false );
		}
		if ( in_array( $action, array( 'propose_weight', 'object', 'pause', 'resume', 'extend', 'extend_delivery', 'reopen_preparation', 'reopen_buyer', 'reopen_seller', 'set_final_weight', 'reissue_code', 'override_delivery', 'refer_return', 'cancel', 'note' ), true ) ) {
			$reason = sanitize_textarea_field( is_scalar( $payload['reason'] ?? null ) ? (string) $payload['reason'] : '' );
			$reason = trim( $reason );
			if ( ( '' === $reason && 'propose_weight' !== $action ) || strlen( $reason ) > 1000 ) {
				return $this->error( 'reason' );
			}
			$prepared['reason'] = $reason;
			$prepared['reason_hash'] = hash( 'sha256', $reason );
		}
		$purpose = match ( $action ) {
			'record_weight' => 'vendor_weight',
			'propose_weight' => 'buyer_weight',
			'object' => 'vendor' === $role ? 'vendor_objection' : 'buyer_objection',
			default => null,
		};
		if ( null !== $purpose ) {
			$ids = $this->evidence->upload( $request->id(), $request->wcOrderId() ?? 0, $actor_id, $purpose, $files, 4 );
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}
			$prepared['evidence'] = $ids;
			$prepared['count'] = count( $ids );
			$prepared['evidence_hash'] = hash( 'sha256', implode( ',', $ids ) );
		}
		if ( 'ready_pickup' === $action && null === $case['data']['delivery']['address_kind'] ) {
			$address = $this->addressSnapshot( $request );
			if ( is_wp_error( $address ) ) {
				return $address;
			}
			$prepared['address'] = $address;
		}
		if ( in_array( $action, array( 'ready_pickup', 'reissue_code' ), true ) || ( 'dispatch' === $action && null === $case['data']['delivery']['hmac'] ) ) {
			$next_generation = $case['data']['delivery']['generation'] + 1;
			$issued = $this->issueCode( $request, $next_generation );
			if ( is_wp_error( $issued ) ) {
				return $issued;
			}
			$prepared['issued_code'] = $issued;
			$prepared['generation'] = $next_generation;
		}
		if ( 'deliver' === $action ) {
			$code = $this->codeSecret->loadOrCreate();
			if ( ! $code instanceof DeliveryCode || ! $request->wcOrderId() ) {
				return $this->error( 'code_key' );
			}
			$candidate = $this->normalizeDigits( is_scalar( $payload['code'] ?? null ) ? (string) $payload['code'] : '' );
			$prepared['candidate'] = $candidate;
			$prepared['generation'] = $case['data']['delivery']['generation'];
			$prepared['attempts'] = $case['data']['delivery']['attempts'];
			$prepared['code_hash'] = 1 === preg_match( '/\A[0-9]{6}\z/', $candidate )
				? $code->sign( $candidate, $request->id(), $request->wcOrderId(), $prepared['generation'] )
				: hash( 'sha256', 'invalid-delivery-code-shape' );
		}
		if ( in_array( $action, array( 'extend', 'extend_delivery' ), true ) ) {
			$seconds = filter_var( $payload['seconds'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 60, 'max_range' => self::MAX_EXTENSION ) ) );
			if ( false === $seconds ) {
				return $this->error( 'extension' );
			}
			$prepared['seconds'] = (int) $seconds;
			$prepared['count'] = (int) $seconds;
		}
		if ( 'reissue_code' === $action ) {
			$resume = sanitize_key( is_scalar( $payload['resume_state'] ?? null ) ? (string) $payload['resume_state'] : '' );
			$prepared['resume_state'] = $resume;
		}
		if ( 'note' === $action ) {
			$visibility = sanitize_key( is_scalar( $payload['visibility'] ?? null ) ? (string) $payload['visibility'] : 'private' );
			if ( ! in_array( $visibility, array( 'public', 'private' ), true ) ) {
				return $this->error( 'note' );
			}
			$prepared['visibility'] = $visibility;
			$prepared['note_hash'] = hash( 'sha256', $prepared['reason'] );
		}
		return $prepared;
	}

	/** @param array<string,mixed> $prepared @return array|WP_Error */
	private function apply( PurchaseRequest $request, array $case, string $role, int $actor_id, string $action, array $prepared ): array|WP_Error {
		if ( isset( $case['data']['spec4'] ) && ! in_array( $action, array( 'pause', 'resume', 'note' ), true ) ) { return $this->error( 'finance_stage' ); }
		if ( self::needsDepositBootstrap( $request, $case ) || ! $this->ownsRole( $request, $role, $actor_id ) || ! $this->cases->depositVerified( $request, $case, true ) ) {
			return $this->error( 'locked_state' );
		}
		$now = current_time( 'mysql', true );
		try {
			$window = $case['data']['operations']['window'] ?? null;
			if ( in_array( $role, array( 'buyer', 'vendor' ), true ) && in_array( $case['state'], OperationsData::PRE_DELIVERY, true ) && is_array( $window ) && $now >= $window['end_gmt'] ) { return $this->error( 'delivery_window_expired' ); }
			$context = array(
				'mode' => $case['mode'], 'paused' => $request->isPaused() || $case['data']['pause']['active'],
				'now' => Deadline::timestamp( $now ), 'deadline' => Deadline::timestamp( $case['deadline_gmt'] ),
				'final_locked' => null !== $case['data']['final']['locked_at'], 'delivery_locked' => $case['data']['delivery']['attempts'] >= 5,
			);
			$line_key = $this->selectedLine( $case, $action, $prepared );
			if ( null === $line_key ) { return $this->error( 'line' ); }
			$line = $case['data']['lines'][ $line_key ];
			if ( $this->isLineAction( $action ) && null !== $line['final_tenths'] ) { return $this->error( 'submission_locked' ); }
			$context['delivered'] = null !== $case['data']['delivery']['delivered_at'];
			$context['buyer_weight_available'] = is_int( $line['buyer_tenths'] ) && count( $line['buyer_evidence'] ) > 0;
			$context['expected_resume_state'] = DeliveryMethod::PICKUP_FROM_VENDOR === $request->deliveryMethod()
				? FlowState::READY_FOR_PICKUP
				: FlowState::IN_TRANSIT;
			if ( ! $this->vendorWeightsComplete( $case ) || 0 === $case['data']['delivery']['generation'] ) { $context['expected_resume_state'] = ''; }
			$fields = array();
			$audit = array( 'actor_id' => $actor_id, 'line_id' => (string) $line_key );
			$notices = array();
				switch ( $action ) {
				case 'record_weight':
					if ( null !== $line['vendor_tenths'] || array() !== $line['vendor_evidence'] ) { return $this->error( 'submission_locked' ); }
					$weight = $prepared['weight_tenths'];
					$warning = Amounts::warning( $line['reference_tenths'], $weight );
					$decision = $this->policy->decide( $case['state'], $action, $role, $context + array( 'weights_complete' => true, 'evidence_complete' => count( $prepared['evidence'] ) >= 1, 'warning' => $warning, 'acknowledged' => $prepared['acknowledged'] ) );
					if ( is_wp_error( $decision ) ) { return $decision; }
					$line['vendor_tenths'] = $weight;
					$line['vendor_evidence'] = $prepared['evidence'];
					if ( 2 === $case['data']['format'] ) { $line['vendor_submitted_at'] = $now; }
					$line['warning_ack']['vendor'] = $warning ? $prepared['acknowledged'] : false;
					$case['data']['lines'][ $line_key ] = $line;
					if ( $this->vendorWeightsComplete( $case ) ) { $case['data']['operations']['prepared_at'] = $now; }
					$audit += array( 'weight_tenths' => $weight, 'count' => count( $prepared['evidence'] ), 'acknowledged' => $line['warning_ack']['vendor'] );
					break;

				case 'ready_delivery':
				case 'ready_pickup':
					$decision = $this->policy->decide( $case['state'], $action, $role, $context + array( 'weights_complete' => $this->vendorWeightsComplete( $case ), 'delivery_method' => $request->deliveryMethod() ) );
					if ( is_wp_error( $decision ) ) { return $decision; }
					if ( 'ready_delivery' === $action && ( 'buyer_delivery' !== $case['data']['delivery']['address_kind'] || null === $case['data']['delivery']['address_encrypted'] ) ) {
						return $this->error( 'delivery_address' );
					}
					if ( 'ready_pickup' === $action ) {
						if ( null === $case['data']['delivery']['address_kind'] && isset( $prepared['address'] ) ) {
							$case['data']['delivery']['address_kind'] = $prepared['address']['kind'];
							$case['data']['delivery']['address_encrypted'] = $prepared['address']['encrypted'];
						}
						if ( 'vendor_pickup' !== $case['data']['delivery']['address_kind'] || null === $case['data']['delivery']['address_encrypted'] ) {
							return $this->error( 'pickup_address' );
						}
						$this->applyIssuedCode( $case, $prepared['issued_code'] );
						$notices[] = $this->codeNotice( $request, $case );
					}
					$case['state'] = $decision['state'];
					$case['deadline_gmt'] = null;
					$audit += array( 'generation' => $case['data']['delivery']['generation'] );
					break;

				case 'dispatch':
					$decision = $this->policy->decide( $case['state'], $action, $role, $context );
					if ( is_wp_error( $decision ) ) { return $decision; }
					if ( isset( $prepared['issued_code'] ) ) {
						$this->applyIssuedCode( $case, $prepared['issued_code'] );
						$notices[] = $this->codeNotice( $request, $case );
					} elseif ( null === $case['data']['delivery']['hmac'] || null === $case['data']['delivery']['encrypted_code'] ) {
						return $this->error( 'code_state' );
					}
					$case['state'] = $decision['state'];
					$case['data']['operations']['dispatched_at'] = $now;
					$case['deadline_gmt'] = null;
					$audit += array( 'generation' => $case['data']['delivery']['generation'] );
					break;

				case 'deliver':
					$engine = $this->codeSecret->loadOrCreate();
					$order_id = $request->wcOrderId() ?? 0;
					if ( ! $engine instanceof DeliveryCode || $order_id < 1 || null === $case['data']['delivery']['hmac'] || $prepared['generation'] !== $case['data']['delivery']['generation'] || $prepared['attempts'] !== $case['data']['delivery']['attempts'] ) {
						return $this->error( 'code_state' );
					}
					// Failed attempts must commit, but can only be made in a live handoff stage.
					if ( 'vendor' !== $role || ! in_array( $case['state'], array( FlowState::READY_FOR_PICKUP, FlowState::IN_TRANSIT ), true )
						|| $context['paused'] || $context['final_locked'] || $context['delivered'] || $context['delivery_locked'] ) { return $this->error( 'code_stage' ); }
					$attempt = $engine->attempt( $prepared['candidate'], $case['data']['delivery']['hmac'], $request->id(), $order_id, $case['data']['delivery']['generation'], $case['data']['delivery']['attempts'] );
					$case['data']['delivery']['attempts'] = $attempt['attempts'];
					$audit += array( 'generation' => $case['data']['delivery']['generation'], 'attempts' => $attempt['attempts'] );
					if ( ! $attempt['valid'] ) {
						if ( $attempt['locked'] ) {
							$case['state'] = FlowState::ADMIN_REVIEW;
							$case['deadline_gmt'] = null;
							$notices = array_merge( $notices, $this->reviewNotices( $request, 'managed_code_locked' ) );
						}
						break;
					}
					$decision = $this->policy->decide( $case['state'], $action, $role, array_replace( $context, array( 'code_valid' => $attempt['valid'] ) ) );
					if ( is_wp_error( $decision ) ) { return $decision; }
					$this->markDelivered( $case, $now, $decision['state'], self::BUYER_WINDOW );
					$notices[] = array( 'role' => 'buyer', 'user_id' => $request->buyerId(), 'template' => 'managed_received_weight_due', 'variables' => array( 'deadline' => $case['deadline_gmt'] ) );
					break;

				case 'accept_weight':
					if ( 'vendor' !== $role || count( $line['buyer_evidence'] ) < 1 ) { return $this->error( 'buyer_evidence' ); }
					$candidate = $line['buyer_tenths'];
					if ( ! is_int( $candidate ) ) { return $this->error( 'weight_missing' ); }
					$warning = Amounts::warning( $line['reference_tenths'], $candidate );
					$decision = $this->policy->decide( $case['state'], $action, $role, $context + array( 'warning' => $warning, 'acknowledged' => $prepared['acknowledged'] ?? false ) );
					if ( is_wp_error( $decision ) ) { return $decision; }
					$line['warning_ack'][ $role ] = $warning ? ( $prepared['acknowledged'] ?? false ) : false;
					$case['data']['lines'][ $line_key ] = $line;
					$this->lockFinal( $case, $request, $line_key, $candidate, $now );
					$audit += array( 'weight_tenths' => $candidate, 'acknowledged' => $line['warning_ack'][ $role ], 'total_toman' => $case['data']['final']['total'] );
					$notices = null !== $case['data']['final']['locked_at'] ? $this->finalNotices( $request, $case ) : array();
					break;

				case 'propose_weight':
					if ( null !== $line['buyer_tenths'] || array() !== $line['buyer_evidence'] ) { return $this->error( 'submission_locked' ); }
					$weight = $prepared['weight_tenths'];
					$warning = Amounts::warning( $line['reference_tenths'], $weight );
					$decision = $this->policy->decide( $case['state'], $action, $role, $context + array( 'reason' => $prepared['reason'], 'weights_complete' => true, 'evidence_complete' => count( $prepared['evidence'] ) >= 1, 'warning' => $warning, 'acknowledged' => $prepared['acknowledged'] ) );
					if ( is_wp_error( $decision ) ) { return $decision; }
					$line['buyer_tenths'] = $weight;
					$line['buyer_evidence'] = $prepared['evidence'];
					if ( 2 === $case['data']['format'] ) { $line['buyer_submitted_at'] = $now; }
					$line['buyer_reason'] = $prepared['reason'];
					$line['warning_ack']['buyer'] = $warning ? $prepared['acknowledged'] : false;
					$case['data']['lines'][ $line_key ] = $line;
					$all_submitted = $this->buyerWeightsComplete( $case );
					if ( $all_submitted ) {
						$case['data']['operations']['buyer_submitted_at'] = $now;
						$case['state'] = $decision['state'];
						$case['deadline_gmt'] = Deadline::after( $now, self::VENDOR_WINDOW );
					}
					$audit += array( 'weight_tenths' => $weight, 'count' => count( $prepared['evidence'] ), 'acknowledged' => $line['warning_ack']['buyer'], 'reason_hash' => $prepared['reason_hash'] );
					if ( $all_submitted ) { $notices[] = array( 'role' => 'vendor', 'user_id' => $request->vendorId(), 'template' => 'managed_received_weight_review', 'variables' => array( 'deadline' => $case['deadline_gmt'] ) ); }
					break;

				case 'object':
					$decision = $this->policy->decide( $case['state'], $action, $role, $context + array( 'reason' => $prepared['reason'], 'evidence_complete' => count( $prepared['evidence'] ) >= 1 ) );
					if ( is_wp_error( $decision ) ) { return $decision; }
					$field = 'vendor' === $role ? 'vendor_objection' : 'buyer_objection';
					$reason_field = 'vendor' === $role ? 'vendor_reason' : 'buyer_reason';
					$line[ $field ] = $prepared['evidence'];
					$line[ $reason_field ] = $prepared['reason'];
					$case['data']['lines'][ $line_key ] = $line;
					$case['state'] = $decision['state'];
					$case['deadline_gmt'] = null;
					$audit += array( 'count' => count( $prepared['evidence'] ), 'reason_hash' => $prepared['reason_hash'] );
					$notices = $this->reviewNotices( $request, 'managed_objection_review' );
					break;

				case 'timeout':
					$decision = $this->policy->decide( $case['state'], $action, $role, $context );
					if ( is_wp_error( $decision ) ) { return $decision; }
					$case['state'] = $decision['state'];
					$case['deadline_gmt'] = null;
					$notices = $this->reviewNotices( $request, 'managed_silence_review' );
					break;

				case 'pause':
					$decision = $this->policy->decide( $case['state'], $action, $role, $context + array( 'reason' => $prepared['reason'] ) );
					if ( is_wp_error( $decision ) ) { return $decision; }
					$remaining = null === $case['deadline_gmt'] ? null : Deadline::remaining( $case['deadline_gmt'], $now );
					$case['data']['pause'] = array( 'active' => true, 'remaining' => $remaining );
					$case['deadline_gmt'] = null;
					$fields = array( 'is_paused' => 1, 'pause_reason' => $prepared['reason'], 'paused_at_gmt' => $now, 'paused_remaining_seconds' => $remaining );
					$audit['reason_hash'] = $prepared['reason_hash'];
					$notices = $this->pairNotices( $request, 'managed_paused' );
					break;

				case 'resume':
					$decision = $this->policy->decide( $case['state'], $action, $role, $context + array( 'reason' => $prepared['reason'] ) );
					if ( is_wp_error( $decision ) ) { return $decision; }
					$remaining = $case['data']['pause']['remaining'];
					if ( in_array( $case['state'], array( FlowState::BUYER_REVIEW, FlowState::SELLER_REVIEW ), true ) && ! is_int( $remaining ) ) {
						return $this->error( 'pause_clock' );
					}
					$case['deadline_gmt'] = is_int( $remaining ) ? Deadline::after( $now, $remaining ) : null;
					$case['data']['pause'] = array( 'active' => false, 'remaining' => null );
					$fields = array( 'is_paused' => 0, 'pause_reason' => $prepared['reason'], 'paused_at_gmt' => null, 'paused_remaining_seconds' => null );
					$audit['reason_hash'] = $prepared['reason_hash'];
					$notices = $this->pairNotices( $request, 'managed_resumed' );
					break;

				case 'extend':
					$decision = $this->policy->decide( $case['state'], $action, $role, $context + array( 'reason' => $prepared['reason'] ) );
					if ( is_wp_error( $decision ) || null === $case['deadline_gmt'] ) { return is_wp_error( $decision ) ? $decision : $this->error( 'deadline' ); }
					$base = ( Deadline::timestamp( $case['deadline_gmt'] ) ?? 0 ) > ( Deadline::timestamp( $now ) ?? 0 ) ? $case['deadline_gmt'] : $now;
					$case['deadline_gmt'] = Deadline::after( $base, $prepared['seconds'] );
					$audit += array( 'count' => $prepared['seconds'], 'reason_hash' => $prepared['reason_hash'] );
					$notices = $this->pairNotices( $request, 'managed_deadline_extended', array( 'deadline' => $case['deadline_gmt'] ) );
					break;

				case 'reopen_buyer':
				case 'reopen_seller':
					if ( 'reopen_seller' === $action && ! $this->buyerWeightsComplete( $case ) ) { return $this->error( 'buyer_rows_incomplete' ); }
					$decision = $this->policy->decide( $case['state'], $action, $role, $context + array( 'reason' => $prepared['reason'] ) );
					if ( is_wp_error( $decision ) ) { return $decision; }
					$this->archiveEvidence( $case, $line_key, 'reopen_buyer' === $action ? 'buyer' : 'vendor', 'reopen_buyer' === $action, $actor_id, $now );
					$case['state'] = $decision['state'];
					$case['deadline_gmt'] = Deadline::after( $now, (int) $decision['window_seconds'] );
					$audit['reason_hash'] = $prepared['reason_hash'];
					$target = 'reopen_buyer' === $action ? array( 'role' => 'buyer', 'user_id' => $request->buyerId() ) : array( 'role' => 'vendor', 'user_id' => $request->vendorId() );
					$notices[] = $target + array( 'template' => 'managed_review_reopened', 'variables' => array( 'deadline' => $case['deadline_gmt'] ) );
					break;

				case 'extend_delivery':
					if ( ! is_array( $window ) || $window['generation'] >= 21 ) { return $this->error( 'window_limit' ); }
					$resume_state = FlowState::ADMIN_REVIEW === $case['state'] ? $window['resume_state'] : $case['state'];
					$decision = $this->policy->decide( $case['state'], $action, $role, $context + array( 'reason' => $prepared['reason'], 'delivery_resume_state' => $resume_state, 'delivery_window_overdue' => null !== $window['overdue_at'] ) );
					if ( is_wp_error( $decision ) ) { return $decision; }
					$start = max( $now, $window['start_gmt'] );
					$end = Deadline::after( max( $now, $window['end_gmt'] ), $prepared['seconds'] );
					$window['history'][] = array( 'previous_start' => $window['start_gmt'], 'previous_end' => $window['end_gmt'],
						'start_gmt' => $start, 'end_gmt' => $end, 'actor_id' => $actor_id, 'at_gmt' => $now, 'reason' => $prepared['reason'] );
					$window['generation']++;
					$window['start_gmt'] = $start; $window['end_gmt'] = $end;
					$window['reminded_at'] = null; $window['overdue_at'] = null; $window['resume_state'] = null;
					$case['data']['operations']['window'] = $window;
					$case['state'] = $decision['state'];
					$case['deadline_gmt'] = null;
					$audit += array( 'count' => $prepared['seconds'], 'generation' => $window['generation'], 'reason_hash' => $prepared['reason_hash'] );
					$notices = $this->pairNotices( $request, 'managed_delivery_extended', array( 'deadline' => $end, 'window_generation' => $window['generation'] ) );
					break;

				case 'reopen_preparation':
					$decision = $this->policy->decide( $case['state'], $action, $role, $context + array( 'reason' => $prepared['reason'] ) );
					if ( is_wp_error( $decision ) ) { return $decision; }
					if ( 0 !== $case['data']['delivery']['generation'] || $context['delivered'] ) { return $this->error( 'recovery_stage' ); }
					$this->archiveEvidence( $case, $line_key, 'vendor', true, $actor_id, $now );
					$case['state'] = $decision['state'];
					$audit['reason_hash'] = $prepared['reason_hash'];
					$notices[] = array( 'role' => 'vendor', 'user_id' => $request->vendorId(), 'template' => 'managed_preparation_reopened', 'variables' => array() );
					break;

				case 'set_final_weight':
					$weight = $prepared['weight_tenths'];
					$warning = Amounts::warning( $line['reference_tenths'], $weight );
					$decision = $this->policy->decide( $case['state'], $action, $role, $context + array( 'reason' => $prepared['reason'], 'weights_complete' => true, 'warning' => $warning, 'acknowledged' => $prepared['acknowledged'] ) );
					if ( is_wp_error( $decision ) ) { return $decision; }
					$line['warning_ack']['admin'] = $warning ? $prepared['acknowledged'] : false;
					$case['data']['lines'][ $line_key ] = $line;
					$this->lockFinal( $case, $request, $line_key, $weight, $now );
					$audit += array( 'weight_tenths' => $weight, 'acknowledged' => $line['warning_ack']['admin'], 'total_toman' => $case['data']['final']['total'], 'reason_hash' => $prepared['reason_hash'] );
					$notices = null !== $case['data']['final']['locked_at'] ? $this->finalNotices( $request, $case ) : array();
					break;

				case 'reissue_code':
					if ( null !== ( $window['overdue_at'] ?? null ) || ! $this->vendorWeightsComplete( $case ) || 0 === $case['data']['delivery']['generation'] ) { return $this->error( 'recovery_stage' ); }
					$decision = $this->policy->decide( $case['state'], $action, $role, $context + array( 'reason' => $prepared['reason'], 'resume_state' => $prepared['resume_state'] ) );
					if ( is_wp_error( $decision ) ) { return $decision; }
					$this->applyIssuedCode( $case, $prepared['issued_code'] );
					$case['state'] = $decision['state'];
					$case['deadline_gmt'] = null;
					$audit += array( 'generation' => $case['data']['delivery']['generation'], 'reason_hash' => $prepared['reason_hash'] );
					$notices[] = $this->codeNotice( $request, $case );
					break;

				case 'override_delivery':
					// Old form submissions cannot bypass the now-mandatory buyer code.
					return $this->error( 'code_required' );

				case 'note':
					$decision = $this->policy->decide( $case['state'], $action, $role, $context + array( 'reason' => $prepared['reason'] ) );
					if ( is_wp_error( $decision ) ) { return $decision; }
					$case['data']['notes'][ $prepared['visibility'] ][] = array( 'actor_id' => $actor_id, 'at_gmt' => $now, 'text' => $prepared['reason'] );
					$audit['note_hash'] = $prepared['note_hash'];
					break;

				case 'refer_return':
				case 'cancel':
					$decision = $this->policy->decide( $case['state'], $action, $role, $context + array( 'reason' => $prepared['reason'] ) );
					if ( is_wp_error( $decision ) ) { return $decision; }
					$case['state'] = $decision['state'];
					$case['deadline_gmt'] = null;
					if ( 'cancel' === $action && ExecutionMode::TEST === $case['mode'] ) {
						$case['data']['final']['settlement'] = 'not_required';
					}
					$audit['reason_hash'] = $prepared['reason_hash'];
					$notices = $this->pairNotices( $request, 'managed_return_review' );
					break;

				default:
					return $this->error( 'action' );
			}
			if ( 'admin' === $role && 'note' !== $action && ! empty( $prepared['reason'] ) ) {
				$case['data']['notes']['private'][] = array( 'actor_id' => $actor_id, 'at_gmt' => $now, 'text' => $prepared['reason'] );
			}
			if ( array_key_exists( 'total_toman', $audit ) && null === $audit['total_toman'] ) { unset( $audit['total_toman'] ); }
			return $this->result( $case, $fields, $audit, $notices );
		} catch ( Throwable ) {
			return $this->error( 'decision' );
		}
	}

	/** @return array{order_id:int,line_id:int}|WP_Error */
	private function orderLine( PurchaseRequest $request ): array|WP_Error {
		$order_id = $request->wcOrderId() ?? 0;
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order || ! ManagedOrderSnapshot::matches( $order, $request ) ) {
			return $this->error( 'order_snapshot' );
		}
		$items = array_values( $order->get_items( 'line_item' ) );
		if ( 1 !== count( $items ) || ! $items[0] instanceof WC_Order_Item_Product || $items[0]->get_id() < 1 ) {
			return $this->error( 'order_line' );
		}
		return array( 'order_id' => $order_id, 'line_id' => $items[0]->get_id() );
	}

	/** @return array{kind:string,encrypted:string}|WP_Error */
	private function addressSnapshot( PurchaseRequest $request ): array|WP_Error {
		if ( ! $this->protected->ensureKey() || ! $request->wcOrderId() ) {
			return $this->error( 'address_key' );
		}
		if ( DeliveryMethod::PICKUP_FROM_VENDOR === $request->deliveryMethod() ) {
			$data = $this->pickupAddressFromRequest( $request );
			if ( null === $data ) {
				// Requests created before pickup snapshots were introduced retain the
				// legacy Dokan lookup path. New requests always use their immutable data.
				$data = $this->pickupAddress->snapshot( $request->vendorId() );
			}
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$kind = 'vendor_pickup';
		} else {
			try {
				$data = $this->deliveryCrypto->decrypt( $request->encryptedDeliveryPayload() );
			} catch ( Throwable ) {
				return $this->error( 'buyer_address' );
			}
			if ( '' === trim( (string) ( $data['recipient_name'] ?? '' ) ) || '' === trim( (string) ( $data['address'] ?? '' ) ) ) {
				return $this->error( 'buyer_address' );
			}
			$kind = 'buyer_delivery';
		}
		try {
			$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
			$encrypted = $this->protected->encrypt( 'delivery_address_snapshot', $request->id(), $this->addressBinding( $request ), $json );
		} catch ( Throwable ) {
			return $this->error( 'address_encrypt' );
		}
		return array( 'kind' => $kind, 'encrypted' => $encrypted );
	}

	/**
	 * @return array{store_name:string,street_1:string,street_2:string,city:string,zip:string,state:string,country:string,recipient_name:string,pickup_note:?string,pickup_visit_confirmed:true}|WP_Error|null
	 */
	private function pickupAddressFromRequest( PurchaseRequest $request ): array|WP_Error|null {
		try {
			$delivery = $this->deliveryCrypto->decrypt( $request->encryptedDeliveryPayload() );
		} catch ( Throwable ) {
			return $this->error( 'pickup_address' );
		}
		if ( ! array_key_exists( 'pickup_address', $delivery ) ) {
			return null;
		}
		$address = $delivery['pickup_address'];
		if ( ! is_array( $address ) || true !== ( $delivery['pickup_visit_confirmed'] ?? null ) ) {
			return $this->error( 'pickup_address' );
		}
		foreach ( array( 'store_name', 'street_1', 'street_2', 'city', 'zip', 'state', 'country' ) as $field ) {
			if ( ! array_key_exists( $field, $address ) || ! is_string( $address[ $field ] ) ) {
				return $this->error( 'pickup_address' );
			}
		}
		foreach ( array( 'street_1', 'city', 'state', 'country' ) as $required ) {
			if ( '' === trim( $address[ $required ] ) ) {
				return $this->error( 'pickup_address' );
			}
		}
		$recipient = trim( (string) ( $delivery['recipient_name'] ?? '' ) );
		if ( '' === $recipient ) {
			return $this->error( 'pickup_address' );
		}
		$address['recipient_name'] = $recipient;
		$address['pickup_note'] = is_string( $delivery['pickup_note'] ?? null ) ? trim( $delivery['pickup_note'] ) : null;
		$address['pickup_visit_confirmed'] = true;
		return $address;
	}

	/** @return array{generation:int,hmac:string,encrypted:string,issued_at:string}|WP_Error */
	private function issueCode( PurchaseRequest $request, int $generation ): array|WP_Error {
		$engine = $this->codeSecret->loadOrCreate();
		$order_id = $request->wcOrderId() ?? 0;
		if ( ! $engine instanceof DeliveryCode || $order_id < 1 || $generation < 1 || ! $this->protected->ensureKey() ) {
			return $this->error( 'code_key' );
		}
		try {
			$plain = $engine->generate();
			return array(
				'generation' => $generation,
				'hmac' => $engine->sign( $plain, $request->id(), $order_id, $generation ),
				'encrypted' => $this->protected->encrypt( 'delivery_code', $request->id(), $this->codeBinding( $order_id, $generation ), $plain ),
				'issued_at' => current_time( 'mysql', true ),
			);
		} catch ( Throwable ) {
			return $this->error( 'code_issue' );
		}
	}

	/** @param array{generation:int,hmac:string,encrypted:string,issued_at:string} $issued */
	private function applyIssuedCode( array &$case, array $issued ): void {
		if ( $issued['generation'] !== $case['data']['delivery']['generation'] + 1 ) {
			throw new \RuntimeException( 'Delivery generation changed.' );
		}
		$case['data']['delivery']['generation'] = $issued['generation'];
		$case['data']['delivery']['hmac'] = $issued['hmac'];
		$case['data']['delivery']['encrypted_code'] = $issued['encrypted'];
		$case['data']['delivery']['code_issued_at'] = $issued['issued_at'];
		$case['data']['delivery']['attempts'] = 0;
		$case['data']['delivery']['delivered_at'] = null;
	}

	private function markDelivered( array &$case, string $now, string $state, int $window ): void {
		$case['data']['delivery']['hmac'] = null;
		$case['data']['delivery']['encrypted_code'] = null;
		$case['data']['delivery']['code_issued_at'] = null;
		$case['data']['delivery']['delivered_at'] = $now;
		$case['state'] = $state;
		$case['deadline_gmt'] = Deadline::after( $now, $window );
	}

	private function lockFinal( array &$case, PurchaseRequest $request, string|int $line_key, int $weight, string $now ): void {
		if ( null !== $case['data']['manual']['balance'] || $case['data']['funds']['balance']['amount'] > 0 || null !== $case['data']['final']['locked_at'] ) {
			throw new \RuntimeException( 'Final financial facts are already open.' );
		}
		$case['data']['lines'][ $line_key ]['final_tenths'] = $weight;
		$weights = array_column( $case['data']['lines'], 'final_tenths' );
		if ( in_array( null, $weights, true ) ) { return; }
		$amounts = 2 === $case['data']['format']
			? RequestPricingPolicy::finalLineAmounts( $request, array_combine( array_keys( $case['data']['lines'] ), $weights ), $case['data']['funds']['deposit']['amount'], $case['data']['funds']['balance']['amount'] )
			: RequestPricingPolicy::finalAmounts( $request, $weight, $case['data']['funds']['deposit']['amount'], $case['data']['funds']['balance']['amount'] );
		foreach ( $amounts as $component => $value ) {
			$case['data']['final'][ $component ] = $value;
		}
		$case['data']['final']['lock_version'] = RequestPricingPolicy::OPERATIONAL_LOCK;
		$case['data']['final']['pricing_version'] = $request->pricingVersion();
		$case['data']['final']['locked_at'] = $now;
		$case['data']['final']['settlement'] = 'unresolved';
		$case['deadline_gmt'] = null;
		// This release ends at an operational lock. Even exact coverage/test mode
		// must not activate Spec 4 completion, credit, balance or refund work.
		$case['state'] = $amounts['remaining'] < 0 ? FlowState::ADMIN_REVIEW : FlowState::FINAL_WEIGHT_LOCKED;
	}

	private function isLineAction( string $action ): bool {
		return in_array( $action, array( 'record_weight', 'propose_weight', 'accept_weight', 'object', 'set_final_weight', 'reopen_buyer', 'reopen_seller', 'reopen_preparation' ), true );
	}

	private function selectedLine( array $case, string $action, array $payload ): string|int|null {
		$key = $payload['line_id'] ?? '';
		if ( ! $this->isLineAction( $action ) ) { return array_key_first( $case['data']['lines'] ); }
		if ( '' === $key && 1 === count( $case['data']['lines'] ) ) { return array_key_first( $case['data']['lines'] ); }
		return is_string( $key ) && array_key_exists( $key, $case['data']['lines'] ) ? $key : null;
	}

	private function vendorWeightsComplete( array $case ): bool {
		foreach ( $case['data']['lines'] as $line ) {
			if ( ! is_int( $line['vendor_tenths'] ) || count( $line['vendor_evidence'] ) < 1 ) {
				return false;
			}
		}
		return count( $case['data']['lines'] ) > 0;
	}

	private function buyerWeightsComplete( array $case ): bool {
		foreach ( $case['data']['lines'] as $line ) {
			if ( null === $line['final_tenths'] && ( ! is_int( $line['buyer_tenths'] ) || count( $line['buyer_evidence'] ) < 1 ) ) { return false; }
		}
		return count( $case['data']['lines'] ) > 0;
	}

	/** Preserve evidence before the only allowed replacement route: an audited admin reopen. */
	private function archiveEvidence( array &$case, string|int $line_key, string $role, bool $weight, int $admin_id, string $now ): void {
		$line = &$case['data']['lines'][ $line_key ];
		$timestamp_key = 'buyer' === $role ? 'buyer_submitted_at' : 'prepared_at';
		$purposes = $weight ? array( $role . '_weight', $role . '_objection' ) : array( $role . '_objection' );
		foreach ( $purposes as $purpose ) {
			$is_weight = str_ends_with( $purpose, '_weight' );
			$field = $is_weight ? $role . '_evidence' : $purpose;
			$tenths = $is_weight ? $line[ $role . '_tenths' ] : null;
			if ( null !== $tenths || array() !== $line[ $field ] ) {
				$entry = array( 'purpose' => $purpose, 'weight_tenths' => $tenths,
					'evidence' => $line[ $field ], 'reason' => $line[ $role . '_reason' ],
					'submitted_at' => $is_weight ? $case['data']['operations'][ $timestamp_key ] : null,
					'archived_at' => $now, 'archived_by' => $admin_id );
				if ( 2 === $case['data']['format'] ) { $entry['line_id'] = (string) $line_key; $entry['submitted_at'] = $is_weight ? $line[ $role . '_submitted_at' ] : null; }
				$case['data']['operations']['evidence_history'][] = $entry;
			}
			$line[ $field ] = array();
		}
		$line[ $role . '_reason' ] = '';
		if ( $weight ) {
			if ( 2 === $case['data']['format'] ) { $line[ $role . '_submitted_at' ] = null; }
			$line[ $role . '_tenths' ] = null;
			$line['warning_ack'][ $role ] = false;
			$case['data']['operations'][ $timestamp_key ] = null;
		}
	}

	/** @return array<string,mixed> */
	private function decryptAddress( PurchaseRequest $request, array $case ): array {
		$encrypted = $case['data']['delivery']['address_encrypted'];
		if ( ! is_string( $encrypted ) || '' === $encrypted ) {
			return array();
		}
		try {
			$data = json_decode( $this->protected->decrypt( 'delivery_address_snapshot', $request->id(), $this->addressBinding( $request ), $encrypted ), true, 8, JSON_THROW_ON_ERROR );
			return is_array( $data ) ? $data : array();
		} catch ( Throwable ) {
			return array();
		}
	}

	private function decryptCode( PurchaseRequest $request, array $case ): string {
		$order_id = $request->wcOrderId() ?? 0;
		$generation = $case['data']['delivery']['generation'];
		try {
			$code = $this->protected->decrypt( 'delivery_code', $request->id(), $this->codeBinding( $order_id, $generation ), $case['data']['delivery']['encrypted_code'] );
			return 1 === preg_match( '/\A[0-9]{6}\z/', $code ) ? $code : '';
		} catch ( Throwable ) {
			return '';
		}
	}

	private function addressBinding( PurchaseRequest $request ): string {
		return $request->deliveryMethod() . ':' . ( $request->wcOrderId() ?? 0 );
	}

	private function codeBinding( int $order_id, int $generation ): string {
		return $order_id . ':' . $generation;
	}

	/** @return array{role:string,user_id:int,template:string,variables:array} */
	private function codeNotice( PurchaseRequest $request, array $case ): array {
		// PWSMS archives outgoing text. Send a panel link, never the secret itself.
		$template = DeliveryMethod::PICKUP_FROM_VENDOR === $request->deliveryMethod() ? 'managed_pickup_available' : 'managed_dispatch_available';
		return array( 'role' => 'buyer', 'user_id' => $request->buyerId(), 'template' => $template, 'variables' => array( 'generation' => $case['data']['delivery']['generation'] ) );
	}

	/** @return list<array> */
	private function pairNotices( PurchaseRequest $request, string $template, array $variables = array() ): array {
		return array(
			array( 'role' => 'buyer', 'user_id' => $request->buyerId(), 'template' => $template, 'variables' => $variables ),
			array( 'role' => 'vendor', 'user_id' => $request->vendorId(), 'template' => $template, 'variables' => $variables ),
		);
	}

	/** @return list<array> */
	private function reviewNotices( PurchaseRequest $request, string $template ): array {
		$notices = $this->pairNotices( $request, $template );
		foreach ( $this->operationsAdminIds() as $id ) {
			$notices[] = array( 'role' => 'admin', 'user_id' => $id, 'template' => $template, 'variables' => array() );
		}
		return $notices;
	}

	/** @return list<array> */
	private function finalNotices( PurchaseRequest $request, array $case ): array {
		return array(
			array( 'role' => 'buyer', 'user_id' => $request->buyerId(), 'template' => 'managed_weight_final_buyer', 'variables' => array() ),
			array( 'role' => 'vendor', 'user_id' => $request->vendorId(), 'template' => 'managed_weight_final_vendor', 'variables' => array() ),
		);
	}

	/** @return list<int> */
	private function operationsAdminIds(): array {
		$ids = get_users( array( 'role' => 'administrator', 'fields' => 'ids', 'number' => 10, 'orderby' => 'ID', 'order' => 'ASC' ) );
		return array_values( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : array() ), static fn ( int $id ): bool => AdminCapabilities::userCanOperate( $id ) ) );
	}

	/** @return array{case:array,request_fields:array,audit:array,notifications:array} */
	private function result( array $case, array $request_fields, array $audit, array $notifications ): array {
		return array( 'case' => $case, 'request_fields' => $request_fields, 'audit' => $audit, 'notifications' => $notifications );
	}

	private function roleAllowed( string $role, int $actor_id, string $action ): bool {
		if ( 'system' === $role ) {
			return 0 === $actor_id && 'timeout' === $action;
		}
		if ( 'admin' === $role ) {
			return AdminCapabilities::userCanOperate( $actor_id );
		}
		return $actor_id > 0 && in_array( $role, array( 'buyer', 'vendor' ), true );
	}

	private function ownsRole( PurchaseRequest $request, string $role, int $actor_id ): bool {
		return match ( $role ) {
			'buyer' => $actor_id === $request->buyerId(),
			'vendor' => $actor_id === $request->vendorId() && $this->vendorEligibility->isApprovedVendor( $actor_id ),
			'admin' => AdminCapabilities::userCanOperate( $actor_id ),
			'system' => 0 === $actor_id,
			default => false,
		};
	}

	private function truthy( mixed $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'yes' === $value || 'on' === $value;
	}

	private function normalizeWeight( mixed $value ): string|int {
		if ( is_int( $value ) ) {
			return $value;
		}
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		// A managed request never needs thousands separators for weight. Silently
		// removing a comma would turn an ambiguous value such as "22,4" into 224kg;
		// preserve unsupported separators so Amounts::weight() rejects them closed.
		return str_replace( '٫', '.', $this->normalizeDigits( $value ) );
	}

	private function normalizeDigits( string $value ): string {
		return strtr( $value, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' ) );
	}

	private function stableUuid( string $value ): string {
		$hex = hash( 'sha256', $value );
		$hex[12] = '5';
		$hex[16] = dechex( ( hexdec( $hex[16] ) & 0x3 ) | 0x8 );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	private function announceDeadline( int $request_id, array|WP_Error $result ): void {
		if ( is_wp_error( $result ) || ! isset( $result['version'] ) || ! array_key_exists( 'deadline_gmt', $result ) ) {
			return;
		}
		try {
			do_action( 'zbm_managed_deadline_changed', $request_id, (int) $result['version'], $result['deadline_gmt'] );
			$window = $result['data']['operations']['window'] ?? null;
			if ( is_array( $window ) && in_array( $result['state'], OperationsData::PRE_DELIVERY, true ) ) {
				do_action( 'zbm_managed_delivery_window_changed', $request_id, $window['generation'], $window['start_gmt'], $window['end_gmt'] );
			}
		} catch ( Throwable ) {
			// The aggregate is already committed; the five-minute recovery sweep is authoritative fallback.
		}
	}

	private function announceSync( int $request_id, array|WP_Error $result ): void {
		if ( is_wp_error( $result ) || 1 !== ( $result['sync_pending'] ?? 0 ) ) {
			return;
		}
		try {
			do_action( 'zbm_managed_sync_requested', $request_id );
		} catch ( Throwable ) {
			// The persisted five-minute sync sweep remains the authoritative fallback.
		}
	}

	private function error( string $code ): WP_Error {
		$message = match ( $code ) {
			'line' => __( 'محصول موردنظر را در همین درخواست انتخاب کنید.', 'zarbalman-core' ),
			'buyer_rows_incomplete' => __( 'وزن و عکس بعضی محصولات هنوز ثبت نشده است؛ ابتدا مرحلهٔ خریدار را بازگشایی یا وزن ردیف‌های باقی‌مانده را تعیین کنید.', 'zarbalman-core' ),
			'submission_locked' => __( 'اطلاعات این محصول قبلاً ثبت یا نهایی شده است. برای اصلاح مدرک ثبت‌شده، بازگشایی مدیریت لازم است.', 'zarbalman-core' ),
			default => __( 'عملیات پرونده انجام نشد؛ وضعیت، مهلت، نسخه و دسترسی را دوباره بررسی کنید.', 'zarbalman-core' ),
		};
		return new WP_Error( 'zbm_managed_service_' . $code, $message );
	}
}
