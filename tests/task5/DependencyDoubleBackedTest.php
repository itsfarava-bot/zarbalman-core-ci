<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/Task5DependencyDoubles.php';
require_once __DIR__ . '/../support/CandidateFixture.php';
require_once __DIR__ . '/../support/Task4RemainingFixture.php';
require_once __DIR__ . '/../support/Task4ManagedFixture.php';

use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Application\{ManualPaymentSettings, PurchaseRequestInput};
use Zarbalman\Core\Domain\PurchaseRequest\{DeliveryWindow, RequestNumber};
use Zarbalman\Core\Infrastructure\{DeliveryDataCrypto, OnlinePaymentAvailability, ProtectedText, Schema};
use Zarbalman\Core\Integration\ManagedOrderSnapshot;

/** Double-backed seam tests. Native WP/Woo/Core handlers remain unmodified. */
final class DependencyDoubleBackedTest extends TestCase {
    private const EVIDENCE = __DIR__ . '/../../evidence/task5-dependency-double-backed.json';
    private ?Task5DependencyDoubles $doubles = null;
    private array $previous = [];

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        if (!is_dir(dirname(self::EVIDENCE))) { self::assertTrue(mkdir(dirname(self::EVIDENCE), 0770, true)); }
        self::write(['_meta' => ['schema_version' => 1, 'dependency_mode' => 'double-backed',
            'commercial_compatibility_proved' => false, 'real_provider_called' => false,
            'run_id' => getenv('GITHUB_RUN_ID') ?: 'local', 'run_attempt' => getenv('GITHUB_RUN_ATTEMPT') ?: 'local',
            'created_at_gmt' => gmdate('c')], 'controls' => []]);
    }
    protected function setUp(): void {
        parent::setUp();
        foreach (['zbm_core_real_manual_payment', 'zbm_core_online_payment_control'] as $name) {
            $missing = new stdClass(); $value = get_option($name, $missing);
            $this->previous[$name] = ['exists' => $value !== $missing, 'value' => $value === $missing ? null : $value];
        }
    }
    protected function tearDown(): void {
        try {
            if ($this->doubles) { $this->doubles->restore(); $this->doubles = null; }
            foreach ($this->previous as $name => $previous) {
                if ($previous['exists']) { update_option($name, $previous['value'], false); } else { delete_option($name); }
            }
        } finally { wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]); parent::tearDown(); }
    }
    private static function write(array $rows): void {
        $json = wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertSame(strlen($json), file_put_contents(self::EVIDENCE, $json, LOCK_EX));
    }
    private function record(string $name, array $details, string $status = 'passed'): void {
        $rows = json_decode((string) file_get_contents(self::EVIDENCE), true, 64, JSON_THROW_ON_ERROR);
        $rows['controls'][$name] = ['dependency_mode' => 'double-backed', 'assertion_status' => $status,
            'actual_http' => true, 'commercial_compatibility_proved' => false, 'real_provider_called' => false] + $details;
        self::write($rows);
    }
    private function user(string $name): int {
        $user = get_user_by('login', 'zbm_test_' . $name); self::assertInstanceOf(WP_User::class, $user); return (int) $user->ID;
    }
    private function facts(array $config): array {
        $this->doubles = Task5DependencyDoubles::install($config);
        [$cookie] = CandidateHttp::auth($this->user('admin'), 'task5-double-backed-facts');
        $facts = $this->doubles->facts($cookie);
        $this->record('observed-native-registry:' . $this->getName(false), ['facts' => $facts], 'pending');
        self::assertSame('double-backed', $facts['dependency_mode']); self::assertTrue($facts['actual_http']);
        self::assertSame([0], $facts['managed_callback_priorities']);
        self::assertTrue($facts['request_control_registered']); self::assertTrue($facts['balance_callback_registered']);
        self::assertSame(['admin_post_zbm_balance_callback' => true, 'admin_post_nopriv_zbm_balance_callback' => true], $facts['balance_callback_hooks']);
        self::assertTrue($facts['yith_physical_guard_registered']); self::assertTrue($facts['yith_balance_guard_registered']);
        return $facts;
    }
    private function onlyFailures(array $facts, array $keys): void {
        $failed = [];
        foreach ($facts['report'] as $key => $row) { if (!$row['ok']) { $failed[] = $key; } }
        sort($failed); sort($keys); self::assertSame($keys, $failed, 'Health must isolate the missing dependency rather than fail earlier at another seam.');
    }

    public function test_double_backed_native_health_uses_exact_class_header_and_gateway_registry_seams(): void {
        $facts = $this->facts(['yith' => true, 'gateway' => true, 'supporting' => true]);
        $this->onlyFailures($facts, []); self::assertTrue($facts['gateway_registered']);
        foreach (['request_error', 'deposit_error', 'manual_error', 'test_managed_error', 'verification_error'] as $key) { self::assertNull($facts[$key]); }
        self::assertTrue($facts['online_available']); self::assertSame([], $this->doubles->calls());
        $this->record('double-backed-all-present-native-health', ['test' => __METHOD__, 'facts' => $facts,
            'effect' => 'Only documented runtime class/function/header/registry seams are supplied; no request or money mutation is claimed.',
            'legacy_registry_dependency_claimed' => false]);
    }

    public function test_double_backed_missing_gateway_fails_new_links_and_verification_but_not_nonfinancial_test_health(): void {
        $facts = $this->facts(['yith' => true, 'gateway' => false, 'supporting' => true]);
        $this->onlyFailures($facts, ['zarinpal_version', 'zarinpal_settings']);
        self::assertFalse($facts['gateway_registered']); self::assertFalse($facts['online_available']);
        self::assertSame('zbm_payment_dependencies_unavailable', $facts['deposit_error']['code']);
        self::assertSame('zbm_payment_verification_unavailable', $facts['verification_error']['code']);
        self::assertNull($facts['manual_error']); self::assertNull($facts['test_managed_error']); self::assertNull($facts['request_error']);
        self::assertSame([], $this->doubles->calls());
        $this->record('double-backed-missing-gateway-native-health', ['test' => __METHOD__, 'facts' => $facts,
            'effect' => 'Native health fails gateway-only scopes while preserving the nonfinancial test/manual scope.']);
    }

    /** Creates a separate legal phone-only customer; existing account emails are never changed. */
    private function request(string $mode, bool $trackStock = true): array {
        global $wpdb;
        $buyer = wp_insert_user(['user_login' => 'task5_phone_only_' . str_replace('-', '', wp_generate_uuid4()),
            'user_pass' => wp_generate_password(32, true, true), 'user_email' => '', 'role' => 'customer']);
        self::assertFalse(is_wp_error($buyer)); self::assertGreaterThan(0, $buyer);
        self::assertSame('', get_userdata($buyer)->user_email);
        update_user_meta($buyer, 'billing_phone', '09120000000');
        $vendor = $this->user('vendor_1'); $product = Task4RemainingFixture::product($vendor, 'Task 5 double-backed dependency product');
        if (!$trackStock) {
            $product->set_manage_stock(false); $product->save();
            $product = wc_get_product($product->get_id()); self::assertInstanceOf(WC_Product::class, $product);
            self::assertFalse($product->managing_stock()); self::assertFalse($product->is_virtual());
        }
        $start = (new DateTimeImmutable('today', wp_timezone()))->modify('+5 days')->setTime(12, 0)->setTimezone(new DateTimeZone('UTC'));
        $window = DeliveryWindow::exact($start->format('Y-m-d H:i:s'), $start->modify('+2 hours')->format('Y-m-d H:i:s'), current_time('mysql', true), wp_timezone_string());
        $address = ['store_name' => 'Synthetic vendor', 'street_1' => 'Synthetic street', 'street_2' => '', 'city' => 'Synthetic city', 'zip' => '00000', 'state' => 'TEH', 'country' => 'IR'];
        $input = new PurchaseRequestInput($product->get_id(), 0, $vendor, 1, 1000, 10, 'pickup_from_vendor', $window,
            'Synthetic Buyer', null, null, null, null, null, null, null, null, true, $address);
        $crypto = new DeliveryDataCrypto(); self::assertTrue($crypto->ensureKey());
        $schema = new Schema($wpdb);
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $number = RequestNumber::generate();
            $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE request_number=%s', $schema->purchase_requests_table(), $number));
            self::assertSame('', $wpdb->last_error); self::assertNotNull($count); if ((int) $count === 0) { break; }
        }
        self::assertSame(0, (int) $count); self::assertTrue(RequestNumber::isValid($number));
        [$request, $repos] = CandidateFixture::request(['request_number' => $number, 'buyer_id' => $buyer,
            'vendor_id' => $vendor, 'product_id' => $product->get_id(), 'execution_mode' => $mode,
            'current_state' => 'supplier_accepted', 'pricing_version' => 1,
            'delivery_start_gmt' => $window->startGmt(), 'delivery_end_gmt' => $window->endGmt(),
            'delivery_payload_encrypted' => $crypto->encrypt($input->deliveryPayload())]);
        self::assertSame(1000, $request->estimatedTotalToman()); self::assertSame(100, $request->depositToman());
        self::assertTrue($product->is_in_stock());
        if ($trackStock) { self::assertSame(100, $product->get_stock_quantity()); }
        else { self::assertNull($product->get_stock_quantity()); }
        return [$request, $repos, $product];
    }
    private function emailFingerprint(): string {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT ID,user_email FROM %i ORDER BY ID', $wpdb->users), ARRAY_A);
        self::assertSame('', $wpdb->last_error); return hash('sha256', wp_json_encode($rows, JSON_THROW_ON_ERROR));
    }
    private function financialCounts(int $request): array {
        global $wpdb; $schema = new Schema($wpdb); $counts = [];
        foreach (['payments' => $schema->payments_table(), 'ledger' => $schema->ledger_table(), 'actions' => $schema->financial_actions_table()] as $name => $table) {
            $counts[$name] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE request_id=%d', $table, $request)); self::assertSame('', $wpdb->last_error);
        }
        $counts['dokan_mirrors'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i mirror INNER JOIN %i request ON request.wc_order_id=mirror.order_id WHERE request.id=%d', $schema->dokan_credit_mirrors_table(), $schema->purchase_requests_table(), $request));
        self::assertSame('', $wpdb->last_error);
        return $counts;
    }
    public function test_double_backed_missing_yith_blocks_actual_live_order_creation_without_a_gateway_failure(): void {
        $facts = $this->facts(['yith' => false, 'gateway' => true, 'supporting' => true]); $this->onlyFailures($facts, ['yith']);
        self::assertTrue($facts['gateway_registered']); self::assertTrue($facts['online_available']); self::assertNull($facts['request_error']); self::assertNull($facts['verification_error']);
        self::assertSame('zbm_manual_order_dependencies_unavailable', $facts['deposit_error']['code']);
        [$request, $repos] = $this->request('live');
        [$cookie, $nonce] = CandidateHttp::auth($request->vendorId(), 'zbm_vendor_request_' . $request->id());
        $before = Task4ManagedFixture::snapshot(); $emails = $this->emailFingerprint();
        $http = CandidateHttp::post('wp-admin/admin-post.php', ['action' => 'zbm_vendor_request_decision', 'decision' => 'accept',
            'request_id' => (string) $request->id(), 'version' => '0', 'idempotency_key' => wp_generate_uuid4(), '_zbm_nonce' => $nonce], $cookie);
        self::assertSame(303, $http['status']);
        $notice = Task4EndpointHttp::option('_transient_zbm_vendor_notice_' . $request->vendorId());
        $this->record('observed-live-order-control', ['http' => $http['status'], 'observed_notice' => $notice,
            'projection_diagnostics' => $this->doubles->projectionDiagnostics(),
            'location_path' => parse_url(Task4EndpointHttp::header($http, 'Location'), PHP_URL_PATH)], 'pending');
        self::assertIsArray($notice);
        self::assertSame('success', $notice['type']); self::assertSame('تصمیم شما با موفقیت و به‌صورت غیرقابل‌برگشت ثبت شد.', $notice['message']);
        self::assertSame($before, Task4ManagedFixture::snapshot()); self::assertSame($emails, $this->emailFingerprint());
        $fresh = $repos['requests']->find($request->id()); self::assertNull($fresh->wcOrderId()); self::assertFalse($fresh->stockReserved());
        self::assertSame(['payments' => 0, 'ledger' => 0, 'actions' => 0, 'dokan_mirrors' => 0], $this->financialCounts($request->id()));
        self::assertSame([], $this->doubles->calls());
        $this->record('double-backed-missing-yith-live-creation', ['test' => __METHOD__, 'facts' => $facts, 'http' => $http['status'],
            'observed_notice' => $notice, 'request_id' => $request->id(), 'snapshot_unchanged' => true, 'before_snapshot' => $before, 'after_snapshot' => Task4ManagedFixture::snapshot(), 'account_emails_unchanged' => true,
            'effect' => 'Existing accepted live request remains waiting: no order, reservation, payment, ledger or gateway transport.']);
    }
    public function test_double_backed_missing_yith_blocks_actual_manual_offer_at_the_named_dependency_gate(): void {
        global $wpdb;
        $facts = $this->facts(['yith' => false, 'gateway' => true, 'supporting' => true]); $this->onlyFailures($facts, ['yith']);
        [$request, $repos] = $this->request('live');
        $settings = new ManualPaymentSettings(new ProtectedText(new DeliveryDataCrypto()), $repos['audit']);
        self::assertTrue($settings->save($this->user('admin'), ['enabled' => true, 'card_number' => '4242424242424242', 'holder_name' => 'Synthetic Holder'], 'Task five synthetic manual setup'));
        $availability = new OnlinePaymentAvailability($wpdb, $repos['audit']);
        self::assertTrue($availability->setStopped($this->user('admin'), true, $availability->control()['revision'], 'Task five controlled manual route'));
        self::assertFalse($availability->permitsNewLink()); self::assertTrue($settings->enabled());
        [$cookie, $nonce] = CandidateHttp::auth($request->buyerId(), 'zbm_managed_case_' . $request->id());
        $before = Task4ManagedFixture::snapshot(); $emails = $this->emailFingerprint();
        $http = CandidateHttp::post('wp-admin/admin-ajax.php', ['action' => 'zbm_managed_case_action', 'operation' => 'manual_offer',
            'request_id' => (string) $request->id(), 'version' => '0', 'idempotency_key' => wp_generate_uuid4(), '_zbm_nonce' => $nonce], $cookie);
        self::assertSame(409, $http['status']); $json = json_decode($http['body'], true, 32, JSON_THROW_ON_ERROR);
        self::assertFalse($json['success']); self::assertSame($facts['manual_error']['message'], $json['data']['message']);
        self::assertSame($before, Task4ManagedFixture::snapshot()); self::assertSame($emails, $this->emailFingerprint());
        self::assertNull($repos['requests']->find($request->id())->wcOrderId()); self::assertNull($repos['cases']->find($request->id()));
        self::assertSame([], $this->doubles->calls());
        $this->record('double-backed-missing-yith-manual-offer', ['test' => __METHOD__, 'facts' => $facts, 'http' => $http['status'],
            'observed_response' => $json, 'request_id' => $request->id(), 'snapshot_unchanged' => true, 'before_snapshot' => $before, 'after_snapshot' => Task4ManagedFixture::snapshot(), 'account_emails_unchanged' => true,
            'effect' => 'Normal settings API selects manual route; native missing-YITH error precedes offer/order/finance mutation.']);
    }
    private function recoverTest($request): array {
        [$cookie, $nonce] = CandidateHttp::auth($this->user('admin'), 'zbm_admin_request_control_' . $request->id());
        return CandidateHttp::post('wp-admin/admin-post.php', ['action' => 'zbm_admin_request_control', 'operation' => 'test_advance',
            'request_id' => (string) $request->id(), 'version' => '0', 'idempotency_key' => wp_generate_uuid4(),
            'reason' => 'Task five synthetic legacy recovery', '_zbm_nonce' => $nonce], $cookie);
    }
    public function test_double_backed_present_yith_native_legacy_recovery_positive_with_new_phone_only_customer(): void {
        global $wpdb;
        $facts = $this->facts(['yith' => true, 'gateway' => true, 'supporting' => true]); $this->onlyFailures($facts, []);
        [$request, $repos, $product] = $this->request('test_pending'); $emails = $this->emailFingerprint(); $before = Task4ManagedFixture::snapshot();
        $http = $this->recoverTest($request); self::assertSame(302, $http['status']);
        $notice = Task4EndpointHttp::option('_transient_zbm_admin_request_notice_' . $this->user('admin'));
        $this->record('observed-native-legacy-recovery', ['http' => $http['status'], 'observed_notice' => $notice,
            'projection_diagnostics' => $this->doubles->projectionDiagnostics(),
            'location_path' => parse_url(Task4EndpointHttp::header($http, 'Location'), PHP_URL_PATH)], 'pending');
        self::assertIsArray($notice); self::assertSame('success', $notice['type']);
        $fresh = $repos['requests']->find($request->id()); self::assertSame('test', $fresh->executionMode()); self::assertSame('deposit_paid', $fresh->state()); self::assertSame(1, $fresh->version());
        $order = wc_get_order($fresh->wcOrderId()); self::assertInstanceOf(WC_Order::class, $order); self::assertTrue(ManagedOrderSnapshot::matches($order, $fresh));
        self::assertSame('test', ManagedOrderSnapshot::route($order)); self::assertSame('pending', $order->get_status()); self::assertFalse($order->is_paid());
        self::assertSame('no', $order->get_meta('_zbm_payment_enabled', true)); self::assertSame('', $order->get_billing_email('edit')); self::assertSame('', $order->get_billing_phone('edit'));
        self::assertNull($order->get_date_paid()); self::assertSame('', $order->get_transaction_id());
        self::assertTrue($fresh->stockReserved()); self::assertFalse($fresh->stockReleased());
        $stock = $wpdb->get_var($wpdb->prepare('SELECT meta_value FROM %i WHERE post_id=%d AND meta_key=%s', $wpdb->postmeta, $product->get_id(), '_stock'));
        self::assertSame('', $wpdb->last_error); self::assertSame('99', $stock);
        self::assertSame(['payments' => 0, 'ledger' => 0, 'actions' => 0, 'dokan_mirrors' => 0], $this->financialCounts($request->id()));
        $after = Task4ManagedFixture::snapshot(); $schema = new Schema($wpdb);
        foreach ([$schema->payments_table(), $schema->ledger_table(), $schema->financial_actions_table(), $schema->dokan_credit_mirrors_table()] as $table) {
            self::assertSame($before[$table], $after[$table], 'Legacy recovery must preserve all financial rows.');
        }
        self::assertSame($emails, $this->emailFingerprint()); self::assertSame([], $this->doubles->calls());
        [$adminCookie] = CandidateHttp::auth($this->user('admin'), 'task5-native-yith-guards');
        $guardFacts = $this->doubles->facts($adminCookie, $order->get_id());
        self::assertFalse($guardFacts['native_yith_guard_probe']['physical_result']); self::assertTrue($guardFacts['native_yith_guard_probe']['skip_balance_result']);
        $this->record('double-backed-native-legacy-recovery-positive', ['test' => __METHOD__, 'facts' => $facts, 'http' => $http['status'],
            'request_id' => $request->id(), 'order_id' => $order->get_id(), 'snapshot_matches' => true,
            'fixture_new_empty_email_customer' => true, 'account_emails_unchanged' => true, 'financial_counts' => $this->financialCounts($request->id()), 'native_yith_guard_probe' => $guardFacts['native_yith_guard_probe'],
            'effect' => 'Real native handler creates one unpaid quarantined test order and reserves stock; zero provider transport or cash.']);
    }
    /** Native missing-dependency contract. */
    public function test_double_backed_missing_yith_native_legacy_recovery_must_fail_closed(): void {
        $facts = $this->facts(['yith' => false, 'gateway' => true, 'supporting' => true]); $this->onlyFailures($facts, ['yith']);
        self::assertSame('zbm_test_order_dependencies_unavailable', $facts['test_managed_error']['code']);
        [$request, $repos] = $this->request('test_pending'); $before = Task4ManagedFixture::snapshot(); $emails = $this->emailFingerprint();
        $http = $this->recoverTest($request); $fresh = $repos['requests']->find($request->id());
        $notice = Task4EndpointHttp::option('_transient_zbm_admin_request_notice_' . $this->user('admin')); $after = Task4ManagedFixture::snapshot();
        $closed = $before === $after && null === $fresh->wcOrderId() && 'test_pending' === $fresh->executionMode() && 'supplier_accepted' === $fresh->state()
            && 0 === $fresh->version() && !$fresh->stockReserved() && 'error' === ($notice['type'] ?? null);
        $this->record('double-backed-missing-yith-native-legacy-recovery-regression', ['test' => __METHOD__, 'facts' => $facts, 'http' => $http['status'],
            'request_id' => $request->id(), 'observed_notice' => $notice, 'observed_order_id' => $fresh->wcOrderId(),
            'observed_state' => $fresh->state(), 'observed_mode' => $fresh->executionMode(), 'observed_version' => $fresh->version(),
            'observed_stock_reserved' => $fresh->stockReserved(), 'projection_diagnostics' => $this->doubles->projectionDiagnostics(), 'snapshot_unchanged' => $before === $after, 'before_snapshot' => $before, 'after_snapshot' => $after,
            'financial_counts' => $this->financialCounts($request->id()), 'account_emails_unchanged' => $emails === $this->emailFingerprint(),
            'expected_fail_closed' => true, 'observed_fail_closed' => $closed,
            'effect' => $closed ? 'Native recovery denied without mutation.' : 'Native recovery observation did not satisfy the dependency contract.'], $closed ? 'passed' : 'failed');
        self::assertSame(302, $http['status']); self::assertSame($emails, $this->emailFingerprint()); self::assertSame([], $this->doubles->calls());
        self::assertTrue($closed, 'Unavailable dependencies must preserve the pending request, stock and order identity. Observed notice=' . ($notice['type'] ?? '[missing]') . ', state=' . $fresh->state() . ', order=' . ($fresh->wcOrderId() ?? 0));
    }
    private function productStockRows(int $productId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT meta_id,meta_key,meta_value FROM %i WHERE post_id=%d AND meta_key IN (%s,%s,%s) ORDER BY meta_id',
            $wpdb->postmeta, $productId, '_manage_stock', '_stock', '_stock_status'), ARRAY_A);
        self::assertIsArray($rows); self::assertSame('', $wpdb->last_error); self::assertNotEmpty($rows); return $rows;
    }
    public function test_double_backed_present_yith_native_legacy_recovery_positive_without_physical_stock_tracking(): void {
        global $wpdb;
        $facts = $this->facts(['yith' => true, 'gateway' => true, 'supporting' => true]); $this->onlyFailures($facts, []);
        [$request, $repos, $product] = $this->request('test_pending', false); $emails = $this->emailFingerprint(); $before = Task4ManagedFixture::snapshot(); $initialStockRows = $this->productStockRows($product->get_id());
        $http = $this->recoverTest($request); self::assertSame(302, $http['status']);
        $notice = Task4EndpointHttp::option('_transient_zbm_admin_request_notice_' . $this->user('admin'));
        $this->record('observed-native-legacy-recovery-without-physical-stock-tracking', ['http' => $http['status'], 'observed_notice' => $notice,
            'projection_diagnostics' => $this->doubles->projectionDiagnostics(),
            'location_path' => parse_url(Task4EndpointHttp::header($http, 'Location'), PHP_URL_PATH)], 'pending');
        self::assertIsArray($notice); self::assertSame('success', $notice['type']);
        $fresh = $repos['requests']->find($request->id()); self::assertSame('test', $fresh->executionMode()); self::assertSame('deposit_paid', $fresh->state()); self::assertSame(1, $fresh->version());
        $order = wc_get_order($fresh->wcOrderId()); self::assertInstanceOf(WC_Order::class, $order); self::assertTrue(ManagedOrderSnapshot::matches($order, $fresh));
        self::assertSame('test', ManagedOrderSnapshot::route($order)); self::assertSame('pending', $order->get_status()); self::assertFalse($order->is_paid());
        self::assertSame('no', $order->get_meta('_zbm_payment_enabled', true)); self::assertSame('', $order->get_billing_email('edit')); self::assertSame('', $order->get_billing_phone('edit'));
        self::assertNull($order->get_date_paid()); self::assertSame('', $order->get_transaction_id());
        self::assertTrue($fresh->stockReserved()); self::assertFalse($fresh->stockReleased());
        $stockRows = $this->productStockRows($product->get_id()); self::assertSame($initialStockRows, $stockRows);
        $freshProduct = wc_get_product($product->get_id()); self::assertInstanceOf(WC_Product::class, $freshProduct);
        self::assertFalse($freshProduct->managing_stock()); self::assertFalse($freshProduct->is_virtual()); self::assertNull($freshProduct->get_stock_quantity());
        foreach ($order->get_items('line_item') as $item) { self::assertSame(0, (int) $item->get_meta('_reduced_stock', true)); }
        self::assertSame(['payments' => 0, 'ledger' => 0, 'actions' => 0, 'dokan_mirrors' => 0], $this->financialCounts($request->id()));
        $after = Task4ManagedFixture::snapshot(); $schema = new Schema($wpdb);
        foreach ([$schema->payments_table(), $schema->ledger_table(), $schema->financial_actions_table(), $schema->dokan_credit_mirrors_table()] as $table) {
            self::assertSame($before[$table], $after[$table], 'Legacy recovery must preserve all financial rows.');
        }
        self::assertSame($emails, $this->emailFingerprint()); self::assertSame([], $this->doubles->calls());
        [$adminCookie] = CandidateHttp::auth($this->user('admin'), 'task5-native-yith-guards');
        $guardFacts = $this->doubles->facts($adminCookie, $order->get_id());
        self::assertFalse($guardFacts['native_yith_guard_probe']['physical_result']); self::assertTrue($guardFacts['native_yith_guard_probe']['skip_balance_result']);
        $this->record('double-backed-native-legacy-recovery-positive-without-physical-stock-tracking', ['test' => __METHOD__, 'facts' => $facts, 'http' => $http['status'],
            'request_id' => $request->id(), 'order_id' => $order->get_id(), 'snapshot_matches' => true,
            'fixture_new_empty_email_customer' => true, 'fixture_physical_stock_tracking' => false, 'product_stock_before' => $initialStockRows, 'product_stock_after' => $stockRows, 'observed_logical_stock_reserved' => $fresh->stockReserved(), 'account_emails_unchanged' => true, 'financial_counts' => $this->financialCounts($request->id()), 'native_yith_guard_probe' => $guardFacts['native_yith_guard_probe'],
            'effect' => 'Real native handler creates one unpaid quarantined test order and records logical reservation for a physical product without stock tracking; quantity is unchanged and no provider transport or cash occurs.']);
    }
    /** Native missing-dependency contract. */
    public function test_double_backed_missing_yith_native_legacy_recovery_without_physical_stock_tracking_must_fail_closed(): void {
        global $wpdb;
        $facts = $this->facts(['yith' => false, 'gateway' => true, 'supporting' => true]); $this->onlyFailures($facts, ['yith']);
        self::assertSame('zbm_test_order_dependencies_unavailable', $facts['test_managed_error']['code']);
        [$request, $repos, $product] = $this->request('test_pending', false); $before = Task4ManagedFixture::snapshot(); $initialStockRows = $this->productStockRows($product->get_id()); $emails = $this->emailFingerprint();
        $http = $this->recoverTest($request); $fresh = $repos['requests']->find($request->id());
        $notice = Task4EndpointHttp::option('_transient_zbm_admin_request_notice_' . $this->user('admin')); $after = Task4ManagedFixture::snapshot();
        $stockRows = $this->productStockRows($product->get_id());
        $financialCounts = $this->financialCounts($request->id());
        $closed = $initialStockRows === $stockRows && ['payments' => 0, 'ledger' => 0, 'actions' => 0, 'dokan_mirrors' => 0] === $financialCounts && $before === $after && null === $fresh->wcOrderId() && 'test_pending' === $fresh->executionMode() && 'supplier_accepted' === $fresh->state()
            && 0 === $fresh->version() && !$fresh->stockReserved() && 'error' === ($notice['type'] ?? null);
        $this->record('double-backed-missing-yith-native-legacy-recovery-without-physical-stock-tracking-regression', ['test' => __METHOD__, 'facts' => $facts, 'http' => $http['status'],
            'request_id' => $request->id(), 'observed_notice' => $notice, 'observed_order_id' => $fresh->wcOrderId(),
            'observed_state' => $fresh->state(), 'observed_mode' => $fresh->executionMode(), 'observed_version' => $fresh->version(),
            'observed_stock_reserved' => $fresh->stockReserved(), 'fixture_physical_stock_tracking' => false, 'product_stock_before' => $initialStockRows, 'product_stock_after' => $stockRows, 'projection_diagnostics' => $this->doubles->projectionDiagnostics(), 'snapshot_unchanged' => $before === $after, 'before_snapshot' => $before, 'after_snapshot' => $after,
            'financial_counts' => $this->financialCounts($request->id()), 'account_emails_unchanged' => $emails === $this->emailFingerprint(),
            'expected_fail_closed' => true, 'observed_fail_closed' => $closed,
            'effect' => $closed ? 'Native recovery denied without mutation.' : 'Native recovery observation did not satisfy the dependency contract.'], $closed ? 'passed' : 'failed');
        self::assertSame(302, $http['status']); self::assertSame($emails, $this->emailFingerprint()); self::assertSame([], $this->doubles->calls());
        self::assertSame($before, $after, 'Missing YITH must preserve every request, order, stock and financial row.');
        self::assertNull($fresh->wcOrderId()); self::assertSame('test_pending', $fresh->executionMode()); self::assertSame('supplier_accepted', $fresh->state()); self::assertSame(0, $fresh->version());
        self::assertFalse($fresh->stockReserved()); self::assertFalse($fresh->stockReleased());
        self::assertSame(['payments' => 0, 'ledger' => 0, 'actions' => 0, 'dokan_mirrors' => 0], $financialCounts);
        self::assertSame($initialStockRows, $stockRows);
        $freshProduct = wc_get_product($product->get_id()); self::assertInstanceOf(WC_Product::class, $freshProduct);
        self::assertFalse($freshProduct->managing_stock()); self::assertFalse($freshProduct->is_virtual()); self::assertNull($freshProduct->get_stock_quantity());
        self::assertTrue($closed, 'Unavailable dependencies must preserve the pending request, stock and order identity. Observed notice=' . ($notice['type'] ?? '[missing]') . ', state=' . $fresh->state() . ', order=' . ($fresh->wcOrderId() ?? 0));
    }
}
