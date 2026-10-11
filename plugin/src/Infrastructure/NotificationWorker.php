<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use InvalidArgumentException;
use Throwable;
use Zarbalman\Core\Domain\ManagedFlow\DeliveryCode;
use Zarbalman\Core\Domain\ManagedFlow\FlowState;
use Zarbalman\Core\Domain\ManagedFlow\OperationsNotificationPolicy;
use Zarbalman\Core\Domain\PurchaseRequest\ExecutionMode;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;
use Zarbalman\Core\Integration\PwSmsAdapter;
use Zarbalman\Core\Notification\MessageCatalog;
use Zarbalman\Core\Notification\RecipientResolver;
use Zarbalman\Core\Support\JalaliDate;

final class NotificationWorker {
	public const HOOK = 'zbm_dispatch_notification_outbox';

	public function __construct(
		private readonly NotificationOutboxRepository $outbox,
		private readonly PurchaseRequestRepository $requests,
		private readonly RecipientResolver $recipients,
		private readonly MessageCatalog $catalog,
		private readonly PwSmsAdapter $sms,
		private readonly AuditLogRepository $audit,
		private readonly ?ManagedCaseRepository $cases = null,
		private readonly ?ProtectedText $protected = null,
		private readonly ?DeliveryCodeSecret $codeSecret = null
	) {}

	public function register(): void {
		$this->sms->register();
		add_action( self::HOOK, array( $this, 'dispatch' ) );
		add_action( 'init', array( $this, 'ensureSchedule' ), 31 );
	}

	public function ensureSchedule(): void {
		if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_next_scheduled_action( self::HOOK, array(), Scheduler::GROUP ) ) {
				as_schedule_recurring_action( time() + 10, 60, self::HOOK, array(), Scheduler::GROUP, true );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 10, 'zbm_every_minute', self::HOOK );
		}
	}

	public function dispatch(): void {
		foreach ( $this->outbox->due() as $row ) {
			try {
				$request = ! empty( $row['request_id'] ) ? $this->requests->find( (int) $row['request_id'] ) : null;
				$payload = json_decode( (string) ( $row['payload_json'] ?? '{}' ), true, 8, JSON_THROW_ON_ERROR );
				$variables = is_array( $payload ) ? $payload : array();
				$relevant = $this->stillRelevant( (string) $row['template_key'], $request, $variables, $row );
			} catch ( Throwable ) {
				$this->outbox->markFailedAttempt( (int) $row['id'], (int) $row['attempt_count'], 'Notification payload could not be decoded.' );
				continue;
			}
			if ( ! $relevant ) {
				$this->outbox->markCancelled( (int) $row['id'], 'Notification no longer matches the authoritative state.' );
				continue;
			}
			try {
				if ( $request ) {
					$product = wc_get_product( $request->variationId() > 0 ? $request->variationId() : $request->productId() );
					$normalized = $this->normalizeVariables( $variables );
					// The installed SMS plugin archives the full text. Secrets stay in the
					// authenticated buyer panel, including when a saved template uses {code}.
					$normalized['code'] = __( 'در پنل امن شما', 'zarbalman-core' );
					if ( 'vendor' === (string) $row['recipient_role'] ) { $normalized['amount'] = ''; $normalized['reason'] = ''; $normalized['code'] = ''; }
					$variables = array_merge(
						array(
							'request' => $request->requestNumber(),
							'product' => $request->isMultiline() ? sprintf( __( '%s محصول', 'zarbalman-core' ), number_format_i18n( count( $request->lines() ) ) ) : ( $product ? $product->get_name() : __( 'محصول', 'zarbalman-core' ) ),
							'link'    => $this->recipients->dashboardUrl( (string) $row['recipient_role'], $request ),
						),
						$normalized
					);
					if ( 'admin' === $row['recipient_role'] && str_starts_with( $row['template_key'], 'spec4_' ) ) { $variables['link'] = add_query_arg( array( 'page' => 'zbm-core-finance', 'request' => $request->id() ), admin_url( 'admin.php' ) ); }
				}
				$message = $this->catalog->render( (string) $row['template_key'], $variables );
				$mobile = $this->recipients->mobile( (string) $row['recipient_role'], (int) $row['recipient_user_id'], $request );
				$this->sms->send( $mobile, $message, $request && $request->wcOrderId() ? $request->wcOrderId() : 0 );
				$this->outbox->markSent( (int) $row['id'] );
			} catch ( Throwable ) {
				$attempt = in_array( (string) $row['template_key'], array( 'managed_delivery_reminder', NotificationOutboxRepository::SUPPLIER_REMINDER ), true ) ? 3 : (int) $row['attempt_count'];
				$this->outbox->markFailedAttempt( (int) $row['id'], $attempt, 'Automatic SMS delivery failed.' );
				if ( $attempt >= 3 ) {
					$this->audit->append_resource(
						'request', $request?->id(), $request?->wcOrderId(), 'notification_failed', null, $request?->state(),
						'system', 0, wp_generate_uuid4(), array( 'template' => (string) $row['template_key'], 'recipient_role' => (string) $row['recipient_role'] )
					);
				}
			}
		}
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $row */
	private function stillRelevant( string $template, mixed $request, array $payload = array(), array $row = array() ): bool {
		if ( ! $request ) {
			return false;
		}
		if ( ExecutionMode::LIVE !== $request->executionMode() ) { return false; }
		$role = (string) ( $row['recipient_role'] ?? '' );
		if ( ( 'buyer' === $role && (int) ( $row['recipient_user_id'] ?? 0 ) !== $request->buyerId() ) || ( 'vendor' === $role && (int) ( $row['recipient_user_id'] ?? 0 ) !== $request->vendorId() ) ) { return false; }
		if ( in_array( $template, OperationsNotificationPolicy::RETIRED, true ) ) { return false; }
		$now = current_time( 'mysql', true );
		$state = $request->state();
		if ( NotificationOutboxRepository::SUPPLIER_REMINDER === $template ) {
			$deadline = $request->supplierDeadlineGmt();
			return 'vendor' === $role && RequestState::SUPPLIER_REVIEW === $state && ! $request->isPaused() && is_string( $deadline ) && $deadline > $now
				&& $deadline <= gmdate( 'Y-m-d H:i:s', time() + NotificationOutboxRepository::SUPPLIER_REMINDER_LEAD_SECONDS ) && ( $payload['deadline'] ?? null ) === $deadline;
		}
		if ( 'managed_order_creation_failed' === $template ) { return 'admin' === $role && RequestState::ADMIN_REVIEW === $state; }
		if ( in_array( $template, array( 'request_created_buyer', 'request_created_vendor', 'supplier_deadline_reopened', 'supplier_deadline_reopened_vendor' ), true ) ) {
			$deadline = $request->supplierDeadlineGmt();
			return RequestState::SUPPLIER_REVIEW === $state && ! $request->isPaused() && is_string( $deadline ) && $deadline > $now && ( $payload['deadline'] ?? null ) === $deadline;
		}
		$expected_state = match ( $template ) {
			'request_rejected' => RequestState::SUPPLIER_REJECTED,
			'supplier_response_expired', 'supplier_response_expired_vendor' => RequestState::SUPPLIER_RESPONSE_EXPIRED,
			'deposit_expired', 'deposit_expired_vendor' => RequestState::DEPOSIT_EXPIRED,
			'late_payment_review', 'late_payment_review_buyer', 'late_payment_review_vendor' => RequestState::LATE_PAYMENT_REVIEW,
			'late_payment_accepted_buyer', 'late_payment_accepted_vendor' => RequestState::DEPOSIT_PAID,
			'admin_review_cancelled_buyer', 'admin_review_cancelled_vendor' => RequestState::CANCELLED,
			'request_admin_review_buyer', 'request_admin_review_vendor' => RequestState::ADMIN_REVIEW,
			default => null,
		};
		if ( null !== $expected_state && $expected_state !== $state ) { return false; }
		if ( in_array( $template, array( 'request_accepted_payment_waiting', 'request_accepted_payment_waiting_vendor' ), true ) ) {
			return RequestState::SUPPLIER_ACCEPTED === $state && ! $request->paymentReady() && ! $request->isPaused();
		}
		if ( in_array( $template, array( 'request_paused', 'request_paused_vendor' ), true ) ) { return $request->isPaused(); }
		if ( in_array( $template, array( 'request_resumed', 'request_resumed_vendor' ), true ) ) { return ! $request->isPaused() && ! RequestState::isTerminal( $state ); }
		if ( str_starts_with( $template, 'managed_' ) && $this->cases instanceof ManagedCaseRepository ) {
			$case = $this->cases->find( $request->id() );
			if ( ! is_array( $case ) || RequestState::DEPOSIT_PAID !== $request->state() ) { return false; }
			if ( $request->isPaused() && ! in_array( $template, array( 'managed_paused', 'managed_code_locked', 'managed_objection_review', 'managed_silence_review' ), true ) ) { return false; }
			if ( in_array( $template, OperationsNotificationPolicy::CODE_LINKS, true ) && ! $this->cases->depositVerified( $request, $case ) ) { return false; }
			if ( ! OperationsNotificationPolicy::allows( $template, $role, $payload, $case, current_time( 'mysql', true ) ) ) { return false; }
		}
		if ( str_starts_with( $template, 'spec4_' ) ) {
			$case = $this->cases ? $this->cases->find( $request->id() ) : null;
			$f = $case['data']['spec4'] ?? null;
			if ( ! $f || $request->isPaused() || $case['data']['pause']['active'] ) { return false; }
			return match ( $template ) {
				'spec4_balance_ready' => 'buyer' === $role && 'balance_due' === $f['substate'] && is_string( $case['deadline_gmt'] ) && $case['deadline_gmt'] > $now && ( $payload['deadline'] ?? null ) === $case['deadline_gmt'],
				'spec4_warning' => in_array( $role, array( 'buyer', 'admin' ), true ) && 'warning' === $f['substate'] && $case['deadline_gmt'] > $now && ( $payload['deadline'] ?? null ) === $case['deadline_gmt'],
				'spec4_contact_due' => 'admin' === $role && in_array( $f['substate'], array( 'contact_due', 'warning_elapsed' ), true ),
				'spec4_payment_review' => 'admin' === $role && 'payment_review' === $f['substate'],
				'spec4_payment_completed' => in_array( $role, array( 'buyer', 'vendor' ), true ) && 'completed' === $f['substate'],
				'spec4_refund_completed' => 'buyer' === $role && 'closed' === $f['substate'] && in_array( $f['trigger'], array( 'seller_incapacity', 'paid_cancellation' ), true ),
				'spec4_return_confirmed' => in_array( $role, array( 'buyer', 'vendor' ), true ) && 'nonpayment_return' === $f['trigger'],
				'spec4_allocation_review' => 'admin' === $role && 'review' === $f['reconciliation_status'] && 'credited' !== $case['data']['final']['settlement'],
				default => false,
			};
		}
		if ( str_starts_with( $template, 'manual_' ) && $this->cases instanceof ManagedCaseRepository ) {
			$case = $this->cases->find( $request->id() );
			if ( ! is_array( $case ) ) { return false; }
			if ( in_array( $template, array( 'manual_payment_approved_buyer', 'manual_payment_approved_vendor' ), true ) && 0 === $case['data']['funds']['balance']['amount'] ) { return false; }
			$status = match ( $template ) { 'manual_receipt_review' => 'submitted', 'manual_receipt_rejected' => 'rejected', 'manual_payment_offered' => 'offered', default => null };
			if ( null !== $status ) {
				$matching = false;
				foreach ( $case['data']['manual'] as $offer ) {
					if ( is_array( $offer ) && $offer['status'] === $status && ( $payload['amount_toman'] ?? null ) === $offer['amount'] ) { $matching = true; }
				}
				if ( ! $matching ) { return false; }
			}
		}
		if ( 'managed_delivery_code' === $template ) {
			if (
				! $this->cases instanceof ManagedCaseRepository
				|| ! $this->protected instanceof ProtectedText
				|| ! $this->codeSecret instanceof DeliveryCodeSecret
				|| ExecutionMode::LIVE !== $request->executionMode()
				|| 'buyer' !== ( $row['recipient_role'] ?? null )
				|| (int) ( $row['recipient_user_id'] ?? 0 ) !== $request->buyerId()
				|| ! is_int( $payload['generation'] ?? null )
			) {
				return false;
			}
			try {
				$case = $this->cases->find( $request->id() );
			} catch ( Throwable ) {
				return false;
			}
			return is_array( $case )
				&& ExecutionMode::LIVE === $case['mode']
				&& $payload['generation'] === $case['data']['delivery']['generation']
				&& is_string( $case['data']['delivery']['hmac'] )
				&& is_string( $case['data']['delivery']['encrypted_code'] )
				&& null === $case['data']['delivery']['delivered_at']
				&& in_array( $case['state'], array( FlowState::READY_FOR_DELIVERY, FlowState::READY_FOR_PICKUP, FlowState::IN_TRANSIT ), true );
		}
		if ( in_array( $template, array( 'deposit_link_created', 'deposit_deadline_reopened', 'deposit_deadline_reopened_vendor' ), true ) ) {
			if ( RequestState::DEPOSIT_PENDING !== $request->state() || ! $request->paymentReady() || $request->isPaused() || ! $request->wcOrderId() ) {
				return false;
			}
			$deadline = $request->depositDeadlineGmt();
			if ( ! is_string( $deadline ) || $deadline <= $now || ( $payload['deadline'] ?? null ) !== $deadline ) { return false; }
			if ( 'vendor' === $role ) { return true; }
			$order = wc_get_order( $request->wcOrderId() );
			return $order && 'yes' === $order->get_meta( '_zbm_payment_enabled', true );
		}
		return true;
	}

	/** @param array<string,mixed> $payload */
	private function deliveryCode( mixed $request, array $payload ): string {
		if ( ! $this->cases || ! $this->protected || ! $this->codeSecret || ! is_int( $payload['generation'] ?? null ) || ! $request->wcOrderId() ) {
			throw new \RuntimeException( 'Delivery-code dependencies are unavailable.' );
		}
		$case = $this->cases->find( $request->id() );
		$engine = $this->codeSecret->loadOrCreate();
		if ( ! is_array( $case ) || ! $engine instanceof DeliveryCode || $payload['generation'] !== $case['data']['delivery']['generation'] ) {
			throw new \RuntimeException( 'Delivery-code generation is stale.' );
		}
		$code = $this->protected->decrypt( 'delivery_code', $request->id(), $request->wcOrderId() . ':' . $payload['generation'], $case['data']['delivery']['encrypted_code'] );
		if ( ! $engine->verify( $code, $case['data']['delivery']['hmac'], $request->id(), $request->wcOrderId(), $payload['generation'] ) ) {
			throw new \RuntimeException( 'Delivery-code integrity check failed.' );
		}
		return $code;
	}

	/** @param array<string,mixed> $variables
	 *  @return array<string,int|string>
	 */
	private function normalizeVariables( array $variables ): array {
		$result = array();
		if ( isset( $variables['deadline'] ) && is_string( $variables['deadline'] ) ) {
			try {
				$result['deadline'] = JalaliDate::formatGmt( $variables['deadline'], wp_timezone(), true );
			} catch ( InvalidArgumentException ) {
				$result['deadline'] = '';
			}
		}
		if ( isset( $variables['amount_toman'] ) ) {
			$result['amount'] = number_format_i18n( (int) $variables['amount_toman'], 0 );
		}
		if ( isset( $variables['reason'] ) ) {
			$result['reason'] = sanitize_text_field( (string) $variables['reason'] );
		}
		return $result;
	}
}
