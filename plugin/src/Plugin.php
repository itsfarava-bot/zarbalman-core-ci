<?php

declare(strict_types=1);

namespace Zarbalman\Core;

use Zarbalman\Core\Admin\AdminPage;
use Zarbalman\Core\Admin\OrderOperationsMetaBox;
use Zarbalman\Core\Admin\PurchaseRequestAdmin;
use Zarbalman\Core\Admin\SiteHealth;
use Zarbalman\Core\Admin\Spec3Settings;
use Zarbalman\Core\Admin\ManualPaymentsPage;
use Zarbalman\Core\Application\DepositPaymentService;
use Zarbalman\Core\Application\ManagedFlowService;
use Zarbalman\Core\Application\ManualPaymentService;
use Zarbalman\Core\Application\ManualPaymentSettings;
use Zarbalman\Core\Application\AdminTestAdvanceService;
use Zarbalman\Core\Application\AdminTestModeManager;
use Zarbalman\Core\Application\PurchaseRequestService;
use Zarbalman\Core\Domain\Order\WeightUnitPolicy;
use Zarbalman\Core\Domain\ManagedFlow\FlowPolicy;
use Zarbalman\Core\Domain\Workflow\AuthorizationPolicy;
use Zarbalman\Core\Domain\Workflow\StateMachine;
use Zarbalman\Core\Domain\Workflow\WorkflowDefinition;
use Zarbalman\Core\Domain\Workflow\TransitionPayloadValidator;
use Zarbalman\Core\Domain\Workflow\WorkflowControlService;
use Zarbalman\Core\Domain\Workflow\WorkflowService;
use Zarbalman\Core\Frontend\OrderWorkflowPanel;
use Zarbalman\Core\Frontend\BuyerAccountProfileController;
use Zarbalman\Core\Frontend\BuyerRequestController;
use Zarbalman\Core\Frontend\BuyerRequestEndpoint;
use Zarbalman\Core\Frontend\RequestPresenter;
use Zarbalman\Core\Frontend\RequestPageBridge;
use Zarbalman\Core\Frontend\RequestResponse;
use Zarbalman\Core\Frontend\VendorRequestController;
use Zarbalman\Core\Frontend\ManagedCasePanel;
use Zarbalman\Core\Http\WorkflowActionHandler;
use Zarbalman\Core\Http\EvidenceDownloadController;
use Zarbalman\Core\Http\ManagedCaseActionController;
use Zarbalman\Core\Infrastructure\AuditLogRepository;
use Zarbalman\Core\Infrastructure\DeadlineScheduler;
use Zarbalman\Core\Infrastructure\DeliveryCodeSecret;
use Zarbalman\Core\Infrastructure\DeliveryDataCrypto;
use Zarbalman\Core\Infrastructure\DependencyHealth;
use Zarbalman\Core\Infrastructure\EvidenceUploader;
use Zarbalman\Core\Infrastructure\EvidenceCrypto;
use Zarbalman\Core\Infrastructure\EvidenceLegacyMigrator;
use Zarbalman\Core\Infrastructure\EvidenceMigrationScheduler;
use Zarbalman\Core\Infrastructure\Migrator;
use Zarbalman\Core\Infrastructure\LedgerRepository;
use Zarbalman\Core\Infrastructure\ManagedCaseRepository;
use Zarbalman\Core\Infrastructure\ManagedEvidence;
use Zarbalman\Core\Infrastructure\ManagedFlowScheduler;
use Zarbalman\Core\Infrastructure\ManagedRetention;
use Zarbalman\Core\Infrastructure\NotificationOutboxRepository;
use Zarbalman\Core\Infrastructure\NotificationWorker;
use Zarbalman\Core\Infrastructure\OrderStatusObserver;
use Zarbalman\Core\Infrastructure\PaymentRepository;
use Zarbalman\Core\Infrastructure\ProtectedText;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;
use Zarbalman\Core\Infrastructure\PurchaseRequestOperationLock;
use Zarbalman\Core\Infrastructure\PurchaseRequestScheduler;
use Zarbalman\Core\Infrastructure\Scheduler;
use Zarbalman\Core\Infrastructure\SelfTestRunner;
use Zarbalman\Core\Infrastructure\StatusRegistryInspector;
use Zarbalman\Core\Infrastructure\WorkflowStore;
use Zarbalman\Core\Infrastructure\WorkflowOperationLock;
use Zarbalman\Core\Infrastructure\VendorShippingRateRepository;
use Zarbalman\Core\Infrastructure\VendorReportsRepository;
use Zarbalman\Core\Infrastructure\VendorShippingRateLock;
use Zarbalman\Core\Integration\DokanAdapter;
use Zarbalman\Core\Integration\DokanManagedOrderGuard;
use Zarbalman\Core\Integration\DokanManualSettlementAdapter;
use Zarbalman\Core\Integration\DokanPurchaseRequestEndpoint;
use Zarbalman\Core\Integration\DokanStoreAddressAdapter;
use Zarbalman\Core\Integration\DokanShippingRateSettings;
use Zarbalman\Core\Integration\DokanVendorReportsEndpoint;
use Zarbalman\Core\Integration\DokanVendorEligibility;
use Zarbalman\Core\Integration\ManagedCallbackLock;
use Zarbalman\Core\Integration\ManualPaymentOrderAdapter;
use Zarbalman\Core\Integration\ManualPaymentRouteGuard;
use Zarbalman\Core\Integration\PwSmsAdapter;
use Zarbalman\Core\Integration\SecureZarinpalVerifier;
use Zarbalman\Core\Integration\WooCommerceOrderAdapter;
use Zarbalman\Core\Integration\YithDepositAdapter;
use Zarbalman\Core\Integration\ZarinpalAdapter;
use Zarbalman\Core\Notification\MessageCatalog;
use Zarbalman\Core\Notification\RecipientResolver;
use Zarbalman\Core\Rest\WorkflowController;

final class Plugin {
	private static ?self $instance = null;

	private WorkflowStore $workflow_store;
	private StateMachine $state_machine;
	private AuthorizationPolicy $authorization;
	private WorkflowService $workflow_service;
	private WorkflowControlService $workflow_control;
	private WeightUnitPolicy $weight_unit;

	public static function boot(): void {
		if ( self::$instance instanceof self ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_order' ) ) {
			add_action( 'admin_notices', array( self::class, 'render_dependency_notice' ) );
			return;
		}

		self::$instance = new self();
		self::$instance->register();
	}

	private function __construct() {
		global $wpdb;

		( new Migrator( $wpdb ) )->maybe_migrate();

		$definition = new WorkflowDefinition();
		$transitions = apply_filters( 'zbm_core_workflow_transitions', $definition->transitions() );

		$this->state_machine = new StateMachine( $definition->states(), is_array( $transitions ) ? $transitions : array() );
		$this->authorization = new AuthorizationPolicy();
		$this->weight_unit = new WeightUnitPolicy();
		$this->workflow_store = new WorkflowStore( $wpdb, new AuditLogRepository( $wpdb ) );
		$this->workflow_service = new WorkflowService(
			$this->state_machine,
			$this->authorization,
			$this->workflow_store,
			new TransitionPayloadValidator( $this->weight_unit )
		);
		$this->workflow_control = new WorkflowControlService( $this->workflow_store );
	}

	private function register(): void {
		global $wpdb;

		$audit     = new AuditLogRepository( $wpdb );
		$inspector = new StatusRegistryInspector();
		$scheduler = new Scheduler( $wpdb, $audit );
		$weight_unit = $this->weight_unit;
		$crypto      = new EvidenceCrypto();
		$crypto->ensure_key();
		$evidence_migrator = new EvidenceLegacyMigrator( $crypto );
		$self_test          = new SelfTestRunner( $wpdb, $this->state_machine, $inspector, $weight_unit, $crypto, $evidence_migrator );
		$evidence           = new EvidenceUploader( $crypto );

		// The request-to-deposit baseline (new roadmap Spec 2) uses an independent
		// aggregate. Historical "spec3" storage markers remain for compatibility.
		$online_availability = new \Zarbalman\Core\Infrastructure\OnlinePaymentAvailability( $wpdb, $audit );
		$dependency_health = new DependencyHealth( $online_availability );
		$outbox             = new NotificationOutboxRepository( $wpdb );
		$request_repository = new PurchaseRequestRepository( $wpdb, $audit, $outbox );
		$operation_lock     = new PurchaseRequestOperationLock( $wpdb );
		$delivery_crypto    = new DeliveryDataCrypto();
		$vendor_eligibility = new DokanVendorEligibility();
		$shipping_rates     = new VendorShippingRateRepository();
		$vendor_reports     = new VendorReportsRepository( $wpdb );
		$pickup_address     = new DokanStoreAddressAdapter();
		$yith               = new YithDepositAdapter();
		$recipient_resolver = new RecipientResolver();
		$zarinpal_verifier  = new SecureZarinpalVerifier( $dependency_health );
		$payment_repository = new PaymentRepository( $wpdb );
		$ledger_repository  = new LedgerRepository( $wpdb );
		$managed_orders     = new WooCommerceOrderAdapter( $yith, $request_repository, $dependency_health, $payment_repository, $ledger_repository );
		$cases              = new ManagedCaseRepository( $wpdb, $request_repository, $operation_lock, $audit, $outbox );
		$managed_evidence   = new ManagedEvidence( $evidence, $crypto, $request_repository, $cases, $operation_lock );
		$evidence_download  = new EvidenceDownloadController( $crypto, $this->authorization, $managed_evidence );
		$protected          = new ProtectedText( $delivery_crypto );
		$delivery_code_secret = new DeliveryCodeSecret( $wpdb );
		// Create/check this key before the first managed case can exist. Once a
		// case exists, a missing or corrupt key is never replaced speculatively.
		$delivery_code_secret->loadOrCreate();
		$manual_settings    = new ManualPaymentSettings( $protected, $audit );
		$manual_orders      = new ManualPaymentOrderAdapter( $managed_orders );
		$manual_settlements = new DokanManualSettlementAdapter( $wpdb, $ledger_repository );
		$manual_routes      = new ManualPaymentRouteGuard( $cases );
		$deposit_payments   = new DepositPaymentService(
			$wpdb,
			$request_repository,
			$payment_repository,
			$ledger_repository,
			$audit,
			$outbox,
			$operation_lock
		);
		$request_service = new PurchaseRequestService(
			$request_repository,
			$delivery_crypto,
			$dependency_health,
			$recipient_resolver,
			$managed_orders,
			$deposit_payments,
			$zarinpal_verifier,
			$operation_lock,
			$vendor_eligibility,
			$manual_routes
		);
		$manual_payments = new ManualPaymentService(
			$request_repository, $cases, $operation_lock, $payment_repository, $ledger_repository,
			$managed_evidence, $dependency_health, $manual_settings, $protected, $manual_orders, $manual_settlements,
			$vendor_eligibility
		);
		$managed_flow = new ManagedFlowService(
			$request_repository,
			$cases,
			$managed_evidence,
			new FlowPolicy(),
			$weight_unit,
			$delivery_code_secret,
			$protected,
			$delivery_crypto,
			$pickup_address,
			$vendor_eligibility
		);
		$request_presenter = new RequestPresenter( $cases );
		$financial_actions = new \Zarbalman\Core\Infrastructure\FinancialActionRepository( $wpdb, $cases );
		$balance_repository = new \Zarbalman\Core\Infrastructure\BalancePaymentRepository( $wpdb, $cases );
		$finance_service = new \Zarbalman\Core\Application\Spec4FinanceService( $request_repository, $cases, $financial_actions, $payment_repository, $ledger_repository, $manual_settlements, $managed_evidence, $protected, $dependency_health, $manual_settings );
		$balance_service = new \Zarbalman\Core\Application\BalancePaymentService( $request_repository, $cases, $balance_repository, $ledger_repository, $dependency_health, new \Zarbalman\Core\Integration\ZarinpalBalanceAdapter( $online_availability ), $operation_lock, $protected );
		$managed_panel = new ManagedCasePanel( $request_repository, $cases, $managed_flow, $manual_payments, $manual_settings, $dependency_health, $finance_service, $balance_service );
		$message_catalog   = new MessageCatalog();
		$admin_test_mode   = new AdminTestModeManager( $dependency_health, $audit );
		$admin_test_advance = new AdminTestAdvanceService(
			$request_repository,
			$operation_lock,
			$dependency_health,
			$admin_test_mode,
			$managed_orders
		);

		( new DokanAdapter() )->register();
		( new DokanShippingRateSettings( $vendor_eligibility, $shipping_rates, new VendorShippingRateLock( $wpdb ) ) )->register();
		( new DokanVendorReportsEndpoint( $vendor_eligibility, $vendor_reports ) )->register();
		$scheduler->register();
		( new EvidenceMigrationScheduler( $evidence_migrator ) )->register();
		( new DeadlineScheduler( $this->workflow_store, $this->workflow_service ) )->register();
		( new OrderStatusObserver( $audit ) )->register();
		( new SiteHealth( $wpdb, $inspector, $weight_unit, $crypto, $evidence_migrator, $delivery_crypto, $outbox, $dependency_health ) )->register();
		( new AdminPage( $audit, $inspector, $self_test, $weight_unit, $crypto, $evidence_migrator ) )->register();
		( new OrderOperationsMetaBox( $this->workflow_store, $this->state_machine, $audit, $weight_unit, $evidence_download, $request_repository ) )->register();
		( new WorkflowActionHandler( $this->workflow_service, $this->workflow_control, $this->authorization, $evidence, new WorkflowOperationLock( $wpdb ), $request_repository ) )->register();
		( new OrderWorkflowPanel( $this->workflow_store, $this->authorization, $weight_unit, $request_repository ) )->register();
		$evidence_download->register();
		( new WorkflowController( $this->workflow_store, $this->workflow_service, $this->workflow_control, $this->authorization, $request_repository, new WorkflowOperationLock( $wpdb ) ) )->register();

		$yith->register();
		$managed_orders->register();
		( new DokanManagedOrderGuard( $managed_orders, $request_repository, $manual_settlements ) )->register();
		( new ZarinpalAdapter( $deposit_payments, $managed_orders, $request_repository, $zarinpal_verifier, new ManagedCallbackLock( $operation_lock ), $manual_routes, $online_availability, $payment_repository ) )->register();
		( new BuyerRequestController( $request_service, $vendor_eligibility, $shipping_rates, $pickup_address ) )->register();
		( new RequestResponse() )->register();
		( new BuyerAccountProfileController() )->register();
		( new BuyerRequestEndpoint( $request_repository, $delivery_crypto, $request_presenter, $dependency_health, $managed_panel ) )->register();
		( new VendorRequestController( $request_service, $vendor_eligibility ) )->register();
		( new DokanPurchaseRequestEndpoint( $request_repository, $delivery_crypto, $request_presenter, $dependency_health, $vendor_eligibility, $managed_panel ) )->register();
		( new ManagedCaseActionController( $request_repository, $cases, $managed_flow, $manual_payments, $vendor_eligibility, $finance_service, $balance_service ) )->register();
		( new \Zarbalman\Core\Http\BalancePaymentController( $balance_service, $balance_repository, $request_repository ) )->register();
		( new RequestPageBridge() )->register();
		( new PurchaseRequestScheduler( $request_repository, $request_service ) )->register();
		( new ManagedFlowScheduler( $request_repository, $cases, $managed_flow, $manual_payments ) )->register();
		( new \Zarbalman\Core\Infrastructure\Spec4Scheduler( $wpdb, $cases, $finance_service, $balance_service ) )->register();
		( new ManagedRetention( $request_repository, $cases, $managed_evidence ) )->register();
		( new NotificationWorker(
			$outbox,
			$request_repository,
			$recipient_resolver,
			$message_catalog,
			new PwSmsAdapter( $dependency_health ),
			$audit,
			$cases,
			$protected,
			$delivery_code_secret
		) )->register();
		( new PurchaseRequestAdmin( $request_repository, $request_service, $request_presenter, $admin_test_advance, $admin_test_mode, $delivery_crypto ) )->register();
		( new Spec3Settings( $dependency_health, $message_catalog ) )->register();
		( new ManualPaymentsPage( $request_repository, $request_presenter, $managed_panel, $manual_settings, $cases, $payment_repository, $dependency_health, $online_availability ) )->register();
		( new \Zarbalman\Core\Admin\Spec4FinancePage( $wpdb, $request_repository, $cases, $request_presenter, $managed_panel, $finance_service ) )->register();

		do_action( 'zbm_core_ready', $this );
	}

	public function state_machine(): StateMachine {
		return $this->state_machine;
	}

	public function workflow_store(): WorkflowStore {
		return $this->workflow_store;
	}

	public function authorization(): AuthorizationPolicy {
		return $this->authorization;
	}

	public function workflow_service(): WorkflowService {
		return $this->workflow_service;
	}

	public function workflow_control(): WorkflowControlService {
		return $this->workflow_control;
	}

	public static function instance(): ?self {
		return self::$instance;
	}

	public static function render_dependency_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Zarbalman Core برای اجرا به WooCommerce فعال نیاز دارد.', 'zarbalman-core' );
		echo '</p></div>';
	}
}
