<?php

declare(strict_types=1);

namespace Zarbalman\Core\Frontend;

use InvalidArgumentException;
use Throwable;
use Zarbalman\Core\Application\ManagedFlowService;
use Zarbalman\Core\Application\ManualPaymentService;
use Zarbalman\Core\Application\ManualPaymentSettings;
use Zarbalman\Core\Domain\ManagedFlow\FlowState;
use Zarbalman\Core\Domain\PurchaseRequest\DeliveryMethod;
use Zarbalman\Core\Domain\PurchaseRequest\ExecutionMode;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;
use Zarbalman\Core\Infrastructure\DependencyHealth;
use Zarbalman\Core\Infrastructure\ManagedCaseRepository;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;
use Zarbalman\Core\Security\AdminCapabilities;
use Zarbalman\Core\Support\JalaliDate;

/** Role-safe Spec 3 projection. Templates never receive the canonical aggregate. */
final class ManagedCasePanel {
	public const NOTICE_KEY = 'zbm_managed_case_notice_';

	public function __construct(
		private readonly PurchaseRequestRepository $requests,
		private readonly ManagedCaseRepository $cases,
		private readonly ManagedFlowService $flow,
		private readonly ManualPaymentService $manual,
		private readonly ManualPaymentSettings $settings,
		private readonly DependencyHealth $health,
		private readonly ?\Zarbalman\Core\Application\Spec4FinanceService $finance = null,
		private readonly ?\Zarbalman\Core\Application\BalancePaymentService $balance = null
	) {}

	public function render( PurchaseRequest $request, string $role, int $actor_id, ?string $online_payment_url = null ): string {
		$role = sanitize_key( $role );
		$online_payment_url = is_string( $online_payment_url ) && '' !== trim( $online_payment_url ) ? trim( $online_payment_url ) : null;
		if ( ! in_array( $role, array( 'buyer', 'vendor', 'admin' ), true ) || $actor_id < 1 ) {
			return '';
		}
		// Until the deposit is actually paid, the vendor has no managed-flow
		// operation to perform. Avoid even reading the managed case on that page.
		if ( 'vendor' === $role && RequestState::DEPOSIT_PAID !== $request->state() ) {
			return '';
		}
		try {
			$case = $this->cases->find( $request->id() );
			if ( null === $case && RequestState::DEPOSIT_PAID === $request->state() && ( $this->canRecoverForRole( $request, $role, $actor_id ) || ( 'vendor' === $role && $actor_id === $request->vendorId() ) ) ) {
				$bootstrap = $this->flow->synchronizeDeposit( $request->id() );
				if ( ! is_wp_error( $bootstrap ) ) { $case = $this->cases->find( $request->id() ); }
			}
			if ( 'vendor' === $role ) {
				// The vendor receives only the operational projection. In particular,
				// do not run manual-payment recovery or construct its finance view.
				$flow = $this->flow->view( $request, $case, $role, $actor_id );
				if ( array() !== $flow && ManagedFlowService::needsDepositBootstrap( $request, $case ) ) {
					$bootstrap = $this->flow->synchronizeDeposit( $request->id() );
					if ( ! is_wp_error( $bootstrap ) ) {
						$case = $this->cases->find( $request->id() );
						$request = $this->requests->find( $request->id() ) ?? $request;
						$flow = $this->flow->view( $request, $case, $role, $actor_id );
					}
				}
				$manual = array();
			} else {
				// Recover only the Core window here. Page rendering must not wait on Woo.
				if ( is_array( $case ) && is_array( $case['data']['manual']['deposit'] ?? null ) && $this->canRecoverForRole( $request, $role, $actor_id ) ) {
					$recovered = $this->manual->prepareReceiptWindow( $request->id(), $actor_id );
					if ( ! is_wp_error( $recovered ) ) {
						$case = $this->cases->find( $request->id() );
						$request = $this->requests->find( $request->id() ) ?? $request;
					}
				}
				$flow = $this->flow->view( $request, $case, $role, $actor_id );
				if ( array() !== $flow && $this->canRecoverForRole( $request, $role, $actor_id ) && ManagedFlowService::needsDepositBootstrap( $request, $case ) ) {
					$bootstrap = $this->flow->synchronizeDeposit( $request->id() );
					if ( ! is_wp_error( $bootstrap ) ) {
						$case = $this->cases->find( $request->id() );
						$request = $this->requests->find( $request->id() ) ?? $request;
						$flow = $this->flow->view( $request, $case, $role, $actor_id );
					}
				}
				$manual = $this->manual->view( $request, $case, $role, $actor_id );
			}
		} catch ( Throwable ) {
			return '<div class="zbm-managed-alert is-error" role="alert">' . esc_html__( 'نمایش جزئیات موقتاً ممکن نیست. برای دیدن آخرین وضعیت صفحه را تازه کنید؛ پرداخت یا مدرک را دوباره ارسال نکنید.', 'zarbalman-core' ) . '</div>';
		}

		if ( 'vendor' === $role ) {
			return $this->renderVendorOperations( $request, $flow, $actor_id );
		}

		$eligible_request = in_array(
			$request->state(),
			array( RequestState::SUPPLIER_ACCEPTED, RequestState::DEPOSIT_PENDING, RequestState::DEPOSIT_PAID ),
			true
		);
		if ( array() === $flow && array() === $manual && ! $eligible_request ) {
			return '';
		}

		$version = is_array( $case ) ? (int) $case['version'] : 0;
		$state = is_string( $flow['state'] ?? null ) ? $flow['state'] : '';
		$paused = true === ( $flow['paused'] ?? false ) || $request->isPaused();
		$sync_pending = true === ( $flow['sync_pending'] ?? false ) || true === ( $manual['sync_pending'] ?? false );
		$receipt_blocked = $request->needsReconciliation() || ( $sync_pending && true !== ( $manual['receipt_projection_safe'] ?? false ) );
		$operational_blocked = true === ( $flow['operational_blocked'] ?? true );
		$operations_only = true === ( $flow['operations_only'] ?? false );
		$line = is_array( $flow['lines'][0] ?? null ) ? $flow['lines'][0] : array();
		$deposit_offer = is_array( $manual['phases']['deposit'] ?? null ) ? $manual['phases']['deposit'] : null;
		$balance_offer = is_array( $manual['phases']['balance'] ?? null ) ? $manual['phases']['balance'] : null;
		$manual_enabled = $this->settings->enabled();
		$gateway_ready = $this->health->onlineAvailableForNewPayment();
		$deposit_stage = in_array( $request->state(), array( RequestState::SUPPLIER_ACCEPTED, RequestState::DEPOSIT_PENDING ), true );
		$balance_stage = ! $operations_only && in_array( $state, array( FlowState::FINAL_WEIGHT_LOCKED, FlowState::BALANCE_DUE ), true );
		$can_offer_manual = 'buyer' === $role
			&& ExecutionMode::LIVE === $request->executionMode()
			&& ! $paused
			&& ! $receipt_blocked
			&& $manual_enabled
			&& 'manual' === $this->manual->availableRoute( $request, $case, $balance_stage ? 'balance' : 'deposit' )
			&& ( ( $deposit_stage && null === $deposit_offer ) || ( $balance_stage && null === $balance_offer ) );
		$manual_phase = $balance_stage ? 'balance' : 'deposit';
		$balance_receipt_ready = $balance_stage && ! $receipt_blocked && ! $paused && is_array( $case )
			&& in_array( $case['data']['spec4']['substate'] ?? '', array( 'balance_due', 'warning' ), true )
			&& is_string( $case['deadline_gmt'] ) && $case['deadline_gmt'] > current_time( 'mysql', true );
		if ( $balance_stage && ! $balance_receipt_ready ) { $can_offer_manual = false; }
		$show_online_payment = 'buyer' === $role
			&& $gateway_ready
			&& ExecutionMode::LIVE === $request->executionMode()
			&& $deposit_stage
			&& null === $deposit_offer
			&& ! $paused
			&& ! $sync_pending
			&& is_string( $online_payment_url )
			&& '' !== trim( $online_payment_url );
		$show_gateway_unavailable = 'buyer' === $role
			&& ExecutionMode::LIVE === $request->executionMode()
			&& $deposit_stage
			&& null === $deposit_offer
			&& null === $online_payment_url
			&& ! $gateway_ready;
		$show_gateway_preparing = 'buyer' === $role
			&& ExecutionMode::LIVE === $request->executionMode()
			&& $deposit_stage
			&& null === $deposit_offer
			&& null === $online_payment_url
			&& $gateway_ready;
		$deposit_deadline = $request->depositDeadlineGmt();
		$deposit_receipt_ready = RequestState::DEPOSIT_PENDING === $request->state()
			&& ! $receipt_blocked && ! $paused && $request->paymentReady()
			&& is_string( $deposit_deadline ) && $deposit_deadline > current_time( 'mysql', true );
		$deposit_deadline_ready = RequestState::DEPOSIT_PENDING === $request->state()
			&& $request->paymentReady()
			&& ! $paused
			&& ! $receipt_blocked
			&& is_string( $deposit_deadline )
			&& '' !== trim( $deposit_deadline );
		$deposit_deadline_iso = null;
		$deposit_deadline_label = null;
		if ( $deposit_deadline_ready ) {
			$deposit_deadline_iso = str_replace( ' ', 'T', $deposit_deadline ) . 'Z';
			try {
				$deposit_deadline_label = JalaliDate::formatGmt( $deposit_deadline, wp_timezone(), true );
			} catch ( InvalidArgumentException ) {
				$deposit_deadline_label = null;
			}
		}
		$show_deposit_preparing_notice = ! $paused && 'submitted' !== ( $deposit_offer['status'] ?? null ) && (
			RequestState::SUPPLIER_ACCEPTED === $request->state()
			|| ( RequestState::DEPOSIT_PENDING === $request->state() && ! $deposit_deadline_ready )
		);
		$show_managed_details = array() !== $flow && FlowState::AWAITING_DEPOSIT !== $state;
		$notice = self::consumeNotice( $actor_id );
		$action_url = admin_url( 'admin-post.php' );
		$nonce_action = 'zbm_managed_case_' . $request->id();
		$is_operations_admin = 'admin' === $role && AdminCapabilities::userCanOperate( $actor_id );
		$is_finance_admin = 'admin' === $role && AdminCapabilities::userCanManageFinance( $actor_id );
		$state_label = $this->stateLabel( $state, $request );
		$deadline_iso = is_string( $flow['deadline_gmt'] ?? null ) && '' !== $flow['deadline_gmt']
			? str_replace( ' ', 'T', $flow['deadline_gmt'] ) . 'Z'
			: null;
		$delivery = is_array( $flow['delivery'] ?? null ) ? $flow['delivery'] : array();
		$address = is_array( $delivery['address'] ?? null ) ? $delivery['address'] : array();
		$final = is_array( $flow['final'] ?? null ) ? $flow['final'] : array();
		$funds = is_array( $flow['funds'] ?? null ) ? $flow['funds'] : array();
		$delivery_recorded = is_string( $delivery['delivered_at'] ?? null ) && '' !== trim( $delivery['delivered_at'] );
		$final_locked = is_string( $final['locked_at'] ?? null ) && '' !== trim( $final['locked_at'] );
		$buyer_weight_available = ! empty( $flow['lines'] );
		$all_vendor_weights_available = ! empty( $flow['lines'] );
		$any_vendor_weight_available = false;
		foreach ( $flow['lines'] ?? array() as $row ) {
			$has_vendor_weight = is_int( $row['vendor_tenths'] ?? null ) && ! empty( $row['vendor_evidence'] );
			$any_vendor_weight_available = $any_vendor_weight_available || $has_vendor_weight;
			$all_vendor_weights_available = $all_vendor_weights_available && $has_vendor_weight;
			if ( null === ( $row['final_tenths'] ?? null ) && ( ! is_int( $row['buyer_tenths'] ?? null ) || empty( $row['buyer_evidence'] ) ) ) { $buyer_weight_available = false; }
		}
		$expected_resume_state = DeliveryMethod::PICKUP_FROM_VENDOR === $request->deliveryMethod()
			? FlowState::READY_FOR_PICKUP
			: FlowState::IN_TRANSIT;
		$can_recover_delivery = ! $delivery_recorded && ! $final_locked && (int) ( $delivery['generation'] ?? 0 ) > 0
			&& $all_vendor_weights_available && empty( $flow['window']['overdue_at'] ) && empty( $flow['delivery_expired'] );

		ob_start();
		$finance_view = is_array( $case ) && $this->finance ? $this->finance->view( $request, $case, $actor_id ) : array();
		$balance_view = is_array( $case ) && $this->balance ? $this->balance->view( $request, $case, $actor_id ) : array();
		$can_create_refund = $is_finance_admin && ! $paused && RequestState::DEPOSIT_PAID === $request->state()
			&& empty( $case['data']['spec4']['trigger'] ) && empty( $case['data']['spec4']['finance_action_ids'] ) && 'credited' !== ( $case['data']['final']['settlement'] ?? '' );
		$refund_quote = array();
		$return_quote = array();
		if ( $is_finance_admin && 'warning_elapsed' === ( $case['data']['spec4']['substate'] ?? '' ) ) {
			try { $return_quote = \Zarbalman\Core\Domain\Finance\Spec4AllocationPolicy::nonpaymentReturn( $case['data']['funds']['deposit']['amount'], $case['data']['funds']['balance']['amount'], true ); }
			catch ( \Throwable ) { /* A conflicting receipt/payment must be reviewed first. */ }
		}
		if ( $can_create_refund ) {
			try { $refund_quote = \Zarbalman\Core\Domain\Finance\Spec4AllocationPolicy::refund( $case['data']['final']['total'] ?? $request->estimatedGrossToman(), $case['data']['funds']['deposit']['amount'] + $case['data']['funds']['balance']['amount'], 'paid_cancellation' ); }
			catch ( \Throwable ) { $can_create_refund = false; }
		}
		include ZBM_CORE_DIR . 'templates/managed/panel.php';
		return (string) ob_get_clean();
	}

	/** Render only the controls and facts required for a vendor's next action. */
	private function renderVendorOperations( PurchaseRequest $request, array $flow, int $actor_id ): string {
		$state = is_string( $flow['state'] ?? null ) ? $flow['state'] : '';
		if ( '' === $state || FlowState::AWAITING_DEPOSIT === $state ) {
			return '';
		}
		$version = (int) ( $flow['version'] ?? 0 );
		if ( $version < 1 ) {
			return '';
		}
		$paused = true === ( $flow['paused'] ?? false ) || $request->isPaused();
		$operational_blocked = true === ( $flow['operational_blocked'] ?? true );
		$role = 'vendor';
		$line = is_array( $flow['lines'][0] ?? null ) ? $flow['lines'][0] : array();
		$notice = self::consumeNotice( $actor_id );
		$action_url = admin_url( 'admin-post.php' );
		$nonce_action = 'zbm_managed_case_' . $request->id();
		$state_label = $this->stateLabel( $state, $request );
		$command_fields = static function ( string $operation ) use ( $request, $version, $nonce_action, &$line ): void {
			?>
			<input type="hidden" name="action" value="zbm_managed_case_action">
			<input type="hidden" name="operation" value="<?php echo esc_attr( $operation ); ?>">
			<input type="hidden" name="line_id" value="<?php echo esc_attr( (string) ( $line['line_id'] ?? '' ) ); ?>">
			<input type="hidden" name="request_id" value="<?php echo absint( $request->id() ); ?>">
			<input type="hidden" name="version" value="<?php echo absint( $version ); ?>">
			<input type="hidden" name="idempotency_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
			<?php wp_nonce_field( $nonce_action, '_zbm_nonce' ); ?>
			<?php
		};

		ob_start();
		include ZBM_CORE_DIR . 'templates/managed/vendor-operations.php';
		return (string) ob_get_clean();
	}

	private function canRecoverForRole( PurchaseRequest $request, string $role, int $actor_id ): bool {
		return match ( $role ) {
			'buyer' => $actor_id === $request->buyerId(),
			'vendor' => false,
			'admin' => AdminCapabilities::userCanOperate( $actor_id ) || AdminCapabilities::userCanManageFinance( $actor_id ),
			default => false,
		};
	}

	/** @param array{type:string,message:string} $notice */
	public static function storeNotice( int $user_id, array $notice ): void {
		if ( $user_id < 1 ) {
			return;
		}
		$type = 'success' === ( $notice['type'] ?? '' ) ? 'success' : 'error';
		$message = sanitize_text_field( (string) ( $notice['message'] ?? '' ) );
		set_transient( self::NOTICE_KEY . $user_id, array( 'type' => $type, 'message' => $message ), MINUTE_IN_SECONDS );
	}

	/** @return array{type:string,message:string}|null */
	private static function consumeNotice( int $user_id ): ?array {
		$value = get_transient( self::NOTICE_KEY . $user_id );
		delete_transient( self::NOTICE_KEY . $user_id );
		if ( ! is_array( $value ) || ! is_string( $value['message'] ?? null ) ) {
			return null;
		}
		return array( 'type' => 'success' === ( $value['type'] ?? '' ) ? 'success' : 'error', 'message' => $value['message'] );
	}

	private function stateLabel( string $state, PurchaseRequest $request ): string {
		$labels = array(
			FlowState::AWAITING_DEPOSIT => __( 'در انتظار پیش‌پرداخت', 'zarbalman-core' ),
			FlowState::PREPARING => __( 'در حال آماده‌سازی و ثبت وزن', 'zarbalman-core' ),
			FlowState::READY_FOR_DELIVERY => __( 'آمادهٔ ارسال به نشانی خریدار', 'zarbalman-core' ),
			FlowState::READY_FOR_PICKUP => __( 'آمادهٔ دریافت حضوری', 'zarbalman-core' ),
			FlowState::IN_TRANSIT => __( 'در مسیر تحویل', 'zarbalman-core' ),
			FlowState::BUYER_REVIEW => __( 'در انتظار وزن و عکس خریدار', 'zarbalman-core' ),
			FlowState::SELLER_REVIEW => __( 'در انتظار تأیید وزن فروشنده', 'zarbalman-core' ),
			FlowState::ADMIN_REVIEW => __( 'در بررسی مدیریت', 'zarbalman-core' ),
			FlowState::FINAL_WEIGHT_LOCKED => __( 'وزن نهایی ثبت شد', 'zarbalman-core' ),
			FlowState::BALANCE_DUE => __( 'در انتظار پرداخت مانده', 'zarbalman-core' ),
			FlowState::COMPLETED => __( 'تکمیل‌شده', 'zarbalman-core' ),
			FlowState::RETURN_REVIEW => __( 'در بررسی بازگشت یا لغو', 'zarbalman-core' ),
			FlowState::CANCELLED => __( 'لغوشده', 'zarbalman-core' ),
		);
		return $labels[ $state ] ?? ( RequestState::DEPOSIT_PAID === $request->state()
			? __( 'در حال ساخت پروندهٔ تحویل', 'zarbalman-core' )
			: __( 'در انتظار شروع مرحلهٔ بعد', 'zarbalman-core' ) );
	}

}
