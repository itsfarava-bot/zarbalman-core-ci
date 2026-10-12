<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/CandidateFixture.php';
require_once __DIR__ . '/../support/Task4RemainingFixture.php';
require_once __DIR__ . '/../support/Task4ManagedFixture.php';
require_once __DIR__ . '/../support/Task4EndpointHttp.php';

use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Application\PurchaseRequestInput;
use Zarbalman\Core\Domain\PurchaseRequest\{DeliveryWindow, RequestNumber};
use Zarbalman\Core\Infrastructure\{Schema, DependencyHealth, DeliveryDataCrypto, PaymentRepository, LedgerRepository};
use Zarbalman\Core\Integration\{ManagedOrderSnapshot, YithDepositAdapter};

/** Historical, internally consistent fixtures; no provider/dependency stand-ins. */
final class LegacyRequestFormControlsTest extends TestCase {
    private const EVIDENCE = __DIR__ . '/../../evidence/http-legacy-request-form-controls.json';
    private array $previous = [];

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass(); self::assertTrue(is_dir(dirname(self::EVIDENCE)));
        self::write(['_meta' => ['schema_version' => 1, 'run_id' => getenv('GITHUB_RUN_ID') ?: 'local',
            'run_attempt' => getenv('GITHUB_RUN_ATTEMPT') ?: 'local', 'created_at_gmt' => gmdate('c')],
            'http-014' => ['state' => 'pending', 'controls' => []], 'http-015' => ['state' => 'pending', 'controls' => []]]);
    }
    protected function setUp(): void {
        parent::setUp();
        foreach (['woocommerce_currency' => 'IRT', 'woocommerce_weight_unit' => 'kg', 'woocommerce_manage_stock' => 'yes'] as $option => $value) {
            $this->previous[$option] = get_option($option, $value); update_option($option, $value);
        }
        $this->primeAdmin();
    }
    protected function tearDown(): void {
        foreach ($this->previous as $option => $value) { update_option($option, $value); }
        wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]); parent::tearDown();
    }
    private static function write(array $rows): void {
        $json = wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertSame(strlen($json), file_put_contents(self::EVIDENCE, $json, LOCK_EX));
    }
    private function record(string $id, string $scenario, string $method, array $details): void {
        $rows = json_decode((string) file_get_contents(self::EVIDENCE), true, 32, JSON_THROW_ON_ERROR);
        $rows[$id]['controls'][$scenario] = ['state' => 'passed', 'assertion_status' => 'passed', 'actual_http' => true,
            'catalog_ids' => [$id], 'test' => $method] + $details; self::write($rows);
    }
    private function complete(string $id, string $method, array $details): void {
        $rows = json_decode((string) file_get_contents(self::EVIDENCE), true, 32, JSON_THROW_ON_ERROR);
        $rows[$id] = array_replace($rows[$id], ['state' => 'passed', 'assertion_status' => 'passed', 'actual_http' => true,
            'catalog_ids' => [$id], 'test' => $method], $details); self::write($rows);
    }
    private function user(string $name): int {
        $user = get_user_by('login', 'zbm_test_' . $name); self::assertInstanceOf(WP_User::class, $user); return (int) $user->ID;
    }
    private function auth(string $actor, string $action): array {
        if ($actor === 'guest') { wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]); return ['', '']; }
        return CandidateHttp::auth($this->user($actor), $action);
    }
    private function primeAdmin(): void {
        [$cookie] = $this->auth('admin', 'unused-admin-bootstrap');
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $http = Task4EndpointHttp::get('wp-admin/index.php', [], $cookie);
            if ($http['status'] === 200) { break; }
            self::assertSame(302, $http['status']); $location = Task4EndpointHttp::header($http, 'Location');
            self::assertSame(parse_url(home_url('/'), PHP_URL_HOST), parse_url($location, PHP_URL_HOST));
            self::assertStringStartsWith('/wp-admin/', (string) parse_url($location, PHP_URL_PATH));
        }
        self::assertSame(200, Task4EndpointHttp::get('wp-admin/index.php', [], $cookie)['status']);
    }
    private function fixture(string $mode = 'test_pending', string $state = 'supplier_accepted'): array {
        global $wpdb; $vendor = $this->user('vendor_1');
        $product = Task4RemainingFixture::product($vendor, 'Synthetic historical request product');
        $start = (new DateTimeImmutable('today', wp_timezone()))->modify('+5 days')->setTime(12, 0)->setTimezone(new DateTimeZone('UTC'));
        $window = DeliveryWindow::exact($start->format('Y-m-d H:i:s'), $start->modify('+2 hours')->format('Y-m-d H:i:s'), current_time('mysql', true), wp_timezone_string());
        $address = ['store_name' => 'Synthetic vendor', 'street_1' => 'Synthetic street', 'street_2' => '', 'city' => 'Synthetic city', 'zip' => '00000', 'state' => 'TEH', 'country' => 'IR'];
        $input = new PurchaseRequestInput($product->get_id(), 0, $vendor, 1, 1000, 10, 'pickup_from_vendor', $window,
            'Synthetic Buyer', null, null, null, null, null, null, null, null, true, $address);
        $crypto = new DeliveryDataCrypto(); self::assertTrue($crypto->ensureKey());
        $schema = new Schema($wpdb);
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $number = RequestNumber::generate(); $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE request_number=%s', $schema->purchase_requests_table(), $number));
            self::assertSame('', $wpdb->last_error); self::assertNotNull($count); if (0 === (int) $count) { break; }
        }
        self::assertSame(0, (int) $count); self::assertTrue(RequestNumber::isValid($number));
        // Legacy pricing1:1000 product total/100 deposit; canonical validated pickup window and encrypted delivery facts.
        [$request, $repos] = CandidateFixture::request(['request_number' => $number, 'product_id' => $product->get_id(),
            'vendor_id' => $vendor, 'execution_mode' => $mode, 'current_state' => $state, 'pricing_version' => 1,
            'delivery_start_gmt' => $window->startGmt(), 'delivery_end_gmt' => $window->endGmt(),
            'delivery_payload_encrypted' => $crypto->encrypt($input->deliveryPayload())]);
        self::assertSame(1000, $request->estimatedTotalToman()); self::assertSame(100, $request->depositToman());
        self::assertSame($product->get_id(), $request->productId()); self::assertSame($vendor, (int) dokan_get_vendor_by_product($product->get_id(), true));
        self::assertTrue($product->is_in_stock()); self::assertSame(100, $product->get_stock_quantity());
        return [$request, $repos, $product];
    }
    private function renderedForm($request, string $operation, string $cookie): array {
        $http = Task4EndpointHttp::get('wp-admin/admin.php', ['page' => 'zbm-core-requests', 'request_number' => $request->requestNumber()], $cookie);
        self::assertSame(200, $http['status']);
        $dom = new DOMDocument(); $dom->loadHTML('<?xml encoding="UTF-8">' . $http['body'], LIBXML_NOERROR | LIBXML_NOWARNING);
        foreach ($dom->getElementsByTagName('form') as $form) {
            $fields = [];
            foreach ($form->getElementsByTagName('input') as $node) { if ($node->getAttribute('name') !== '') { $fields[$node->getAttribute('name')] = $node->getAttribute('value'); } }
            foreach ($form->getElementsByTagName('textarea') as $node) { if ($node->getAttribute('name') !== '') { $fields[$node->getAttribute('name')] = $node->textContent; } }
            if (($fields['action'] ?? '') === 'zbm_admin_request_control' && ($fields['operation'] ?? '') === $operation && ($fields['request_id'] ?? '') === (string) $request->id()) {
                self::assertSame('post', strtolower($form->getAttribute('method'))); self::assertSame('/wp-admin/admin-post.php', parse_url($form->getAttribute('action'), PHP_URL_PATH));
                return $fields;
            }
        }
        self::fail('Required actual historical request form missing: ' . $operation);
    }
    private function message(array $http, int $status, string $message): void {
        self::assertSame($status, $http['status']);
        self::assertTrue(str_contains(html_entity_decode(strip_tags($http['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $message), 'Expected named response: ' . $message);
    }
    private function notice(array $http, string $message, string $type): array {
        self::assertSame(302, $http['status']); self::assertSame(admin_url('admin.php?page=zbm-core-requests'), Task4EndpointHttp::header($http, 'Location'));
        $notice = Task4EndpointHttp::option('_transient_zbm_admin_request_notice_' . $this->user('admin'));
        self::assertIsArray($notice); self::assertSame($message, $notice['message']); self::assertSame($type, $notice['type']); return $notice;
    }
    private function financialKeys(Schema $schema, array $snapshot): array {
        global $wpdb;
        $keys = [$schema->payments_table(), $schema->ledger_table(), $schema->financial_actions_table(), $schema->dokan_credit_mirrors_table(), $schema->managed_cases_table(), $schema->managed_case_commands_table()];
        foreach (['dokan_vendor_balance', 'dokan_orders'] as $suffix) { if (isset($snapshot[$wpdb->prefix . $suffix])) { $keys[] = $wpdb->prefix . $suffix; } }
        return array_intersect_key($snapshot, array_flip($keys));
    }

    public function test_actual_legacy_recovery_form_creates_only_quarantined_test_order_and_stock_reservation(): void {
        global $wpdb; $schema = new Schema($wpdb);
        foreach (['authorized_positive', 'wrong_role', 'other_buyer', 'other_vendor', 'guest', 'no_nonce', 'invalid_nonce', 'missing_reason', 'stale_version'] as $scenario) {
            [$request, $repos, $product] = $this->fixture(); $id = $request->id();
            [$adminCookie] = $this->auth('admin', 'zbm_admin_request_control_' . $id);
            $fields = $this->renderedForm($request, 'test_advance', $adminCookie); self::assertTrue(wp_is_uuid($fields['idempotency_key']));
            $actor = match ($scenario) { 'wrong_role' => 'buyer_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', 'guest' => 'guest', default => 'admin' };
            if ($actor === 'admin') { $cookie = $adminCookie; $nonce = $fields['_zbm_nonce']; }
            else { [$cookie, $nonce] = $this->auth($actor, 'zbm_admin_request_control_' . $id); }
            if ($actor !== 'guest') { self::assertNotFalse(wp_verify_nonce($nonce, 'zbm_admin_request_control_' . $id)); }
            $fields['_zbm_nonce'] = $nonce; $fields['reason'] = 'Synthetic legacy test recovery';
            if ($scenario === 'no_nonce') { unset($fields['_zbm_nonce']); }
            if ($scenario === 'invalid_nonce') { $fields['_zbm_nonce'] = 'invalid'; }
            if ($scenario === 'missing_reason') { $fields['reason'] = ''; }
            if ($scenario === 'stale_version') { $fields['version'] = '9'; }
            $before = Task4ManagedFixture::snapshot(); $financialBefore = $this->financialKeys($schema, $before);
            $ordersBefore = [];
            foreach (wc_get_orders(['limit' => -1, 'return' => 'objects']) as $existingOrder) {
                $ordersBefore[$existingOrder->get_id()] = $existingOrder->get_data();
            }
            $requestRowsBefore = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id', $schema->purchase_requests_table()), ARRAY_A);
            self::assertIsArray($requestRowsBefore); self::assertSame('', $wpdb->last_error);
            $http = CandidateHttp::post('wp-admin/admin-post.php', $fields, $cookie); $notice = null; $message = '';
            if ($actor === 'guest') { self::assertSame(400, $http['status']); self::assertTrue(str_contains($http['body'], 'wp-die-message')); $message = 'dispatcher_no_public_handler'; }
            elseif ($actor !== 'admin') { $message = 'دسترسی غیرمجاز است.'; $this->message($http, 500, $message); }
            elseif (in_array($scenario, ['no_nonce', 'invalid_nonce'], true)) { $message = __('The link you followed has expired.'); $this->message($http, 403, $message); }
            else {
                $message = match ($scenario) { 'missing_reason' => 'دلیل اجباری معتبر بنویسید و اطلاعات تماس در آن قرار ندهید.',
                    'stale_version' => 'نسخه یا وضعیت پرونده تغییر کرده است؛ صفحه را تازه کنید.', default => 'عملیات مدیریت همراه با دلیل و سابقه حسابرسی ثبت شد.' };
                $notice = $this->notice($http, $message, $scenario === 'authorized_positive' ? 'success' : 'error');
            }
            $fresh = $repos['requests']->find($id); self::assertNotNull($fresh); $after = Task4ManagedFixture::snapshot();
            self::assertSame($financialBefore, $this->financialKeys($schema, $after));
            if ($scenario === 'authorized_positive') {
                self::assertSame('test', $fresh->executionMode()); self::assertSame('deposit_paid', $fresh->state()); self::assertSame(1, $fresh->version());
                self::assertFalse($fresh->paymentReady()); self::assertTrue($fresh->stockReserved()); self::assertFalse($fresh->stockReleased()); self::assertNotNull($fresh->wcOrderId());
                $order = wc_get_order($fresh->wcOrderId()); self::assertInstanceOf(WC_Order::class, $order); self::assertTrue(ManagedOrderSnapshot::matches($order, $fresh));
                self::assertSame('test', ManagedOrderSnapshot::route($order)); self::assertSame('test', $order->get_meta('_zbm_execution_mode', true));
                self::assertSame('no', $order->get_meta('_zbm_payment_enabled', true)); self::assertSame('pending', $order->get_status()); self::assertFalse($order->is_paid());
                self::assertNull($order->get_date_paid()); self::assertSame('', $order->get_transaction_id());
                $items = array_values($order->get_items('line_item')); self::assertCount(1, $items); self::assertSame(1, (int) $items[0]->get_meta('_reduced_stock', true));
                clean_post_cache($product->get_id()); $stock = $wpdb->get_var($wpdb->prepare('SELECT meta_value FROM %i WHERE post_id=%d AND meta_key=%s', $wpdb->postmeta, $product->get_id(), '_stock')); self::assertSame('99', $stock);
                foreach ([$schema->payments_table(), $schema->ledger_table()] as $table) { self::assertSame(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE request_id=%d', $table, $id))); }
                $events = $wpdb->get_results($wpdb->prepare('SELECT actor_type,actor_user_id,metadata_json FROM %i WHERE resource_id=%d AND event_key=%s', $schema->audit_table(), $id, 'admin_test_deposit_advanced'), ARRAY_A);
                self::assertSame('', $wpdb->last_error); self::assertCount(1, $events); self::assertSame('admin', $events[0]['actor_type']); self::assertSame($this->user('admin'), (int) $events[0]['actor_user_id']);
                self::assertSame($fields['reason'], json_decode($events[0]['metadata_json'], true)['reason']); self::assertNotSame($before, $after);
                $ordersAfter = [];
                foreach (wc_get_orders(['limit' => -1, 'return' => 'objects']) as $persistedOrder) { $ordersAfter[$persistedOrder->get_id()] = $persistedOrder->get_data(); }
                self::assertCount(count($ordersBefore) + 1, $ordersAfter);
                foreach ($ordersBefore as $oldOrderId => $oldData) { self::assertSame($oldData, $ordersAfter[$oldOrderId]); }
                foreach ($requestRowsBefore as $oldRow) {
                    if ((int) $oldRow['id'] !== $id) {
                        self::assertSame($oldRow, $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $schema->purchase_requests_table(), $oldRow['id']), ARRAY_A));
                    }
                }
            } else { self::assertSame('test_pending', $fresh->executionMode()); self::assertSame('supplier_accepted', $fresh->state()); self::assertSame(0, $fresh->version()); self::assertNull($fresh->wcOrderId()); self::assertSame($before, $after); }
            $this->record('http-015', $scenario, __METHOD__, ['type' => $scenario === 'authorized_positive' ? 'mutation' : 'validation',
                'http' => $http['status'], 'actor' => $actor, 'actor_valid' => $actor === 'admin',
                'nonce_valid' => $actor !== 'guest' && !in_array($scenario, ['no_nonce', 'invalid_nonce'], true), 'key_valid' => true, 'persisted_assertions' => true,
                'financial_unchanged' => true, 'before_snapshot' => $before, 'after_snapshot' => $after,
                'observed_response' => ['message' => $message, 'notice' => $notice], 'message' => $message, 'notice' => $notice,
                'before_state' => 'supplier_accepted', 'after_state' => $fresh->state(), 'before_version' => 0, 'after_version' => $fresh->version(),
                'before_execution_mode' => 'test_pending', 'execution_mode' => $fresh->executionMode(),
                'submitted_version' => (int) $fields['version'], 'reason_present' => $fields['reason'] !== '',
                'payment_ready' => $fresh->paymentReady(),
                'payment_count' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE request_id=%d', $schema->payments_table(), $id)),
                'ledger_count' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE request_id=%d', $schema->ledger_table(), $id)),
                'order_contract' => $scenario === 'authorized_positive' ? ['order_id' => $order->get_id(), 'snapshot_matches' => ManagedOrderSnapshot::matches($order, $fresh), 'route' => ManagedOrderSnapshot::route($order), 'execution_mode' => $order->get_meta('_zbm_execution_mode', true), 'payment_enabled' => $order->get_meta('_zbm_payment_enabled', true), 'status' => $order->get_status(), 'is_paid' => $order->is_paid(), 'paid_at' => $order->get_date_paid(), 'transaction_id' => $order->get_transaction_id(), 'line_item_count' => count($items), 'other_orders_unchanged' => true, 'other_requests_unchanged' => true] : null,
                'stock_contract' => $scenario === 'authorized_positive' ? ['before' => 100, 'after' => (int) $stock, 'reduced_stock' => (int) $items[0]->get_meta('_reduced_stock', true), 'request_reserved' => $fresh->stockReserved(), 'request_released' => $fresh->stockReleased()] : null,
                'audit_contract' => $scenario === 'authorized_positive' ? ['event' => 'admin_test_deposit_advanced', 'count' => count($events), 'actor_type' => $events[0]['actor_type'], 'actor_user_id' => (int) $events[0]['actor_user_id'], 'reason_matches' => json_decode($events[0]['metadata_json'], true)['reason'] === $fields['reason']] : null,
                'generic_dispatcher_only' => $actor === 'guest',
                'effect' => $scenario === 'authorized_positive' ? 'actual rendered legacy recovery form created quarantined unpaid test order with one stock reservation; zero payment/ledger/Dokan credit' : 'named validation denial with unchanged durable state; guest only dispatcher rejection']);
        }
        $this->complete('http-015', __METHOD__, ['type' => 'mutation', 'covered_operations' => ['test_advance'], 'covered_states' => ['supplier_accepted/test_pending'],
            'remaining_gaps' => ['guest POST handler capability/nonce checks stop at generic dispatcher']]);
    }

    public function test_rendered_late_cancel_preserves_valid_issued_online_identity_before_verification(): void {
        global $wpdb; $schema = new Schema($wpdb);
        [$request, $repos, $product] = $this->fixture('live', 'late_payment_review');
        // Historical canonical online order with an issued Authority, without any captured money or successful verification.
        $order = wc_create_order(['customer_id' => $request->buyerId(), 'status' => 'pending']); $order->set_currency('IRT');
        $order->set_created_via('zarbalman-core-spec3'); $order->set_payment_method('WC_ZPal');
        foreach (['_zbm_managed_order' => 'yes', '_zbm_request_id' => $request->id(), '_zbm_request_public_id' => $request->publicId(),
            '_zbm_vendor_id' => $request->vendorId(), '_dokan_vendor_id' => $request->vendorId(), '_zbm_authoritative_wc_status' => 'pending',
            '_zbm_payment_enabled' => 'no', '_zbm_stock_released' => 'no', '_zbm_execution_mode' => 'live', '_zbm_test_advance' => 'no',
            '_zbm_payment_route' => 'online', '_zbm_estimated_total_toman' => 1000, '_zbm_deposit_toman' => 100,
            '_zbm_reference_weight_tenths' => 10, '_zbm_pricing_version' => 1] as $key => $value) { $order->update_meta_data($key, $value); }
        foreach (['billing_first_name','billing_last_name','billing_email','billing_phone','customer_ip_address','customer_user_agent'] as $field) { $method = 'set_' . $field; $order->$method(''); }
        $item = new WC_Order_Item_Product(); $item->set_product($product); $item->set_quantity(1); $item->set_subtotal('1000'); $item->set_total('100');
        foreach (['_zbm_package_price_toman' => 1000, '_zbm_nominal_package_weight_tenths' => 10, '_zbm_reference_weight_tenths' => 10] as $key => $value) { $item->update_meta_data($key, $value); }
        (new YithDepositAdapter())->decorate($order, $item, $request->pricingSnapshot(), 1); $order->add_item($item); $order->set_total('100');
        $authority = 'TASK4-' . str_replace('-', '', wp_generate_uuid4()); $order->update_meta_data('_zarinpal_authority', $authority); $order->save();
        self::assertSame(1, $wpdb->update($schema->purchase_requests_table(), ['wc_order_id' => $order->get_id(), 'payment_ready' => 1], ['id' => $request->id()]));
        $request = $repos['requests']->find($request->id()); self::assertNotNull($request); self::assertTrue(ManagedOrderSnapshot::matches($order, $request));
        $payments = new PaymentRepository($wpdb); self::assertTrue($payments->prepareExpectedDeposit($request, $order->get_id()));
        $payment = $payments->findExpectedByOrder($order->get_id()); self::assertIsArray($payment);
        self::assertSame(1, $wpdb->update($schema->payments_table(), ['status' => 'callback_received_late', 'authority' => $authority, 'callback_at_gmt' => current_time('mysql', true)], ['id' => $payment['id']]));
        $payment = $payments->findExpectedByOrder($order->get_id()); self::assertSame('callback_received_late', $payment['status']); self::assertNull($payment['paid_amount_toman']); self::assertNull($payment['reference']); self::assertNull($payment['paid_at_gmt']);
        [$cookie] = $this->auth('admin', 'zbm_admin_request_control_' . $request->id());
        $fields = $this->renderedForm($request, 'cancel_review', $cookie); $fields['reason'] = 'Synthetic review of issued online identity';
        self::assertNotFalse(wp_verify_nonce($fields['_zbm_nonce'], 'zbm_admin_request_control_' . $request->id()));
        $registration = $GLOBALS['wp_filter']['admin_post_zbm_admin_request_control']; $actualCreator = null;
        foreach ($registration->callbacks as $callbacks) { foreach ($callbacks as $callback) {
            $target = $callback['function'];
            if (is_array($target) && ($target[0] ?? null) instanceof Zarbalman\Core\Admin\PurchaseRequestAdmin) {
                $actualService = (new ReflectionProperty($target[0], 'service'))->getValue($target[0]);
                $actualCreator = (new ReflectionProperty($actualService, 'order_creator'))->getValue($actualService);
            }
        } }
        self::assertNotNull($actualCreator); self::assertTrue($actualCreator->hasZarinpalPaymentIdentity($order->get_id()));
        $before = Task4ManagedFixture::snapshot(); $http = CandidateHttp::post('wp-admin/admin-post.php', $fields, $cookie);
        $message = 'لغو امن انجام نشد؛ وجود پرداخت قطعی یا وضعیت موجودی/سفارش باید بررسی شود.';
        $notice = $this->notice($http, $message, 'error'); $after = Task4ManagedFixture::snapshot(); self::assertSame($before, $after);
        $fresh = $repos['requests']->find($request->id()); self::assertSame('late_payment_review', $fresh->state()); self::assertSame(0, $fresh->version()); self::assertSame($payment, $payments->findExpectedByOrder($order->get_id()));
        $this->record('http-014', 'authorized_identity_safety_control', __METHOD__, ['type' => 'validation', 'http' => $http['status'], 'actor' => 'admin',
            'actor_valid' => true, 'nonce_valid' => true, 'stored_binding_valid' => true, 'persisted_assertions' => true,
            'financial_unchanged' => true, 'before_snapshot' => $before, 'after_snapshot' => $after, 'message' => $message, 'notice' => $notice,
            'observed_response' => ['notice' => $notice], 'effect' => 'valid issued online identity blocked cancellation before provider verifier; no order/payment/ledger mutation',
            'identity_contract' => ['snapshot_matches' => ManagedOrderSnapshot::matches($order, $request), 'route' => ManagedOrderSnapshot::route($order), 'execution_mode' => 'live', 'payment_status' => $payment['status'], 'authority_matches' => $payment['authority'] === $order->get_meta('_zarinpal_authority', true), 'paid_amount' => $payment['paid_amount_toman'], 'reference' => $payment['reference'], 'paid_at' => $payment['paid_at_gmt']],
            'before_state' => 'late_payment_review', 'after_state' => $fresh->state(), 'before_version' => 0, 'after_version' => $fresh->version(),
            'identity_guard_before_verifier' => true, 'provider_verification_verified' => false, 'dependency_control_claimed' => false]);
        $this->complete('http-014', __METHOD__, ['type' => 'validation', 'covered_operations' => ['cancel_review_identity_safety_denial'], 'covered_states' => ['late_payment_review'],
            'remaining_gaps' => ['no cancellation mutation positive', 'provider reverify unavailable branch cannot be claimed for valid online issued identity: earlier identity guard wins', 'negative capability/nonce matrix for this branch not covered']]);
    }
}
