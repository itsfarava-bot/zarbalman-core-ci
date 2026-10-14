<?php
declare(strict_types=1);
require_once __DIR__ . '/CandidateFixture.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/Task4EndpointHttp.php';
require_once __DIR__ . '/Task5DependencyDoubles.php';

use PHPUnit\Framework\Assert;
use Automattic\WooCommerce\Caches\OrderCache;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Zarbalman\Core\Application\PurchaseRequestService;
use Zarbalman\Core\Admin\PurchaseRequestAdmin;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Infrastructure\{DeliveryDataCrypto, PaymentRepository, Schema};
use Zarbalman\Core\Integration\{DokanVendorEligibility, ManagedOrderSnapshot, WooCommerceOrderAdapter};
use Zarbalman\Core\Security\AdminCapabilities;

/** Real Core/Woo/SQL fixtures with explicitly synthetic historical provider identity.
 * Acceptance uses the actual registered HTTP handler and real seller membership.
 * Nothing here replaces a health, authorization, mode or snapshot predicate.
 */
final class Task5FinancialFixture {
    public int $id;
    public int $orderId;
    public string $authority;
    public string $reference;
    public array $repos;
    public array $users;
    public int $deposit;
    private array $originalAccountIds = [];
    private string $originalEmailFingerprint;

    public static function users(): array {
        $users = [];
        foreach (['admin', 'buyer_1', 'vendor_1'] as $name) {
            $user = get_user_by('login', 'zbm_test_' . $name);
            Assert::assertInstanceOf(WP_User::class, $user);
            $users[$name] = (int) $user->ID;
        }
        Assert::assertTrue(AdminCapabilities::userCanOperate($users['admin']));
        update_user_meta($users['vendor_1'], 'dokan_enable_selling', 'yes');
        Assert::assertTrue((new DokanVendorEligibility())->isApprovedVendor($users['vendor_1']));
        return $users;
    }

    public static function registered(string $hook, string $class): object {
        $matches = [];
        foreach (($GLOBALS['wp_filter'][$hook]->callbacks ?? []) as $callbacks) {
            foreach ($callbacks as $registration) {
                $target = $registration['function'];
                if (is_array($target) && ($target[0] ?? null) instanceof $class) { $matches[spl_object_id($target[0])] = $target[0]; }
            }
        }
        Assert::assertCount(1, $matches, 'A unique production object must already be registered: ' . $hook);
        return array_values($matches)[0];
    }

    public static function service(): PurchaseRequestService {
        // Read only: use the exact registered production service; never replace it.
        $admin = self::registered('admin_post_zbm_admin_request_control', PurchaseRequestAdmin::class);
        $service = (new ReflectionProperty($admin, 'service'))->getValue($admin);
        Assert::assertInstanceOf(PurchaseRequestService::class, $service);
        return $service;
    }

    private function accountEmails(): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT ID,user_email FROM %i ORDER BY ID', $wpdb->users), ARRAY_A);
        Assert::assertIsArray($rows); Assert::assertSame('', $wpdb->last_error);
        return $rows;
    }
    private function preserveOriginalAccountEmails(): void {
        $rows = array_values(array_filter($this->accountEmails(), fn (array $row): bool => isset($this->originalAccountIds[(int) $row['ID']])));
        Assert::assertCount(count($this->originalAccountIds), $rows, 'Every original account must still exist.');
        Assert::assertSame($this->originalEmailFingerprint, hash('sha256', wp_json_encode($rows, JSON_THROW_ON_ERROR)),
            'Fixture and financial handlers must preserve every original account email.');
    }

    /** Native Woo may source billing email from the account during save. Use
     * a separate legal phone-only customer instead of altering existing accounts.
     */
    private function ownPhoneOnlyBuyer(): void {
        $original = $this->accountEmails();
        foreach ($original as $row) { $this->originalAccountIds[(int) $row['ID']] = true; }
        $this->originalEmailFingerprint = hash('sha256', wp_json_encode($original, JSON_THROW_ON_ERROR));
        $buyer = wp_insert_user(['user_login' => 'task5_finance_phone_' . str_replace('-', '', wp_generate_uuid4()),
            'user_pass' => wp_generate_password(32, true, true), 'user_email' => '', 'role' => 'customer']);
        Assert::assertFalse(is_wp_error($buyer)); Assert::assertGreaterThan(0, $buyer);
        $user = get_userdata($buyer); Assert::assertInstanceOf(WP_User::class, $user);
        Assert::assertSame('', $user->user_email); Assert::assertContains('customer', $user->roles);
        update_user_meta($buyer, 'billing_phone', '09120000000');
        $this->users['buyer_1'] = (int) $buyer;
        $this->preserveOriginalAccountEmails();
    }

    public function __construct(int $productToman = 1000, int $depositToman = 100, int $shippingToman = 0, int $pricingVersion = 2, bool $issued = true) {
        Assert::assertSame(8, PHP_INT_SIZE, 'The financial boundary suite requires 64-bit PHP.');
        $this->users = self::users(); $this->repos = CandidateFixture::repositories(); $this->deposit = $depositToman;
        $this->ownPhoneOnlyBuyer();
        $product = new WC_Product_Simple();
        $product->set_name('Task 5 synthetic financial boundary product');
        $product->set_regular_price((string) $productToman); $product->set_weight('1');
        $product->set_manage_stock(false); $product->set_virtual(false); $product->set_tax_status('none');
        $productId = $product->save(); Assert::assertGreaterThan(0, $productId);
        Assert::assertFalse(is_wp_error(wp_update_post(['ID' => $productId, 'post_author' => $this->users['vendor_1']], true)));
        $crypto = new DeliveryDataCrypto(); Assert::assertTrue($crypto->ensureKey());
        $payload = ['recipient_name' => 'Synthetic buyer', 'address' => 'Synthetic test address',
            'pickup_visit_confirmed' => true, 'pickup_note' => '', 'pickup_address' => [
                'store_name' => 'Synthetic vendor', 'street_1' => 'Test street', 'street_2' => '', 'city' => 'Test city',
                'zip' => '00000', 'state' => 'TEH', 'country' => 'IR']];
        [$request] = CandidateFixture::request([
            'product_id' => $productId, 'buyer_id' => $this->users['buyer_1'], 'vendor_id' => $this->users['vendor_1'],
            'package_price_toman' => $productToman, 'estimated_total_toman' => $productToman, 'deposit_toman' => $depositToman,
            'pricing_version' => $pricingVersion, 'estimated_shipping_toman' => $shippingToman,
            'shipping_rate_per_kg_toman' => $shippingToman,
            'delivery_method' => $shippingToman ? 'delivery_to_buyer_address' : 'pickup_from_vendor',
            'delivery_payload_encrypted' => $crypto->encrypt($payload),
            'current_state' => 'supplier_review', 'supplier_deadline_gmt' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
        $this->id = $request->id();
        [$cookie, $nonce] = CandidateHttp::auth($this->users['vendor_1'], 'zbm_vendor_request_' . $this->id);
        $http = CandidateHttp::post('wp-admin/admin-post.php', ['action' => 'zbm_vendor_request_decision',
            'request_id' => (string) $this->id, 'decision' => 'accept', 'version' => (string) $request->version(),
            'idempotency_key' => wp_generate_uuid4(), '_zbm_nonce' => $nonce], $cookie);
        Assert::assertSame(303, $http['status'], 'Registered vendor acceptance HTTP must use the native POST/redirect/GET contract.');
        $notice = Task4EndpointHttp::option('_transient_zbm_vendor_notice_' . $this->users['vendor_1']);
        Assert::assertIsArray($notice); Assert::assertSame('success', $notice['type'], 'Native acceptance must succeed before a callback hypothesis is tested.');
        $this->preserveOriginalAccountEmails();
        $request = $this->request();
        Assert::assertSame('deposit_pending', $request->state(), 'Acceptance must actually create a payable native order.');
        Assert::assertTrue($request->paymentReady()); Assert::assertFalse($request->needsReconciliation());
        $this->orderId = $request->wcOrderId() ?? 0; Assert::assertGreaterThan(0, $this->orderId);
        Assert::assertTrue(ManagedOrderSnapshot::matches($this->order(), $request));
        Assert::assertSame('', $this->order()->get_billing_email('edit')); Assert::assertSame('', $this->order()->get_billing_phone('edit'));
        $this->authority = 'TASK5HISTORICAL' . str_replace('-', '', wp_generate_uuid4());
        // Disjoint from Task 4's small concatenated IDs; exact on 64-bit PHP.
        $this->reference = (string) (1000000000000000 + $this->id);
        global $wpdb;
        Assert::assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE provider='zarinpal' AND reference=%s",
            (new Schema($wpdb))->payments_table(), $this->reference)), 'Synthetic provider reference must be globally unused.');
        Assert::assertSame('', $wpdb->last_error);
        if ($issued) { $this->historicalIssuedIdentity(); }
        wp_set_current_user(0);
    }

    private function historicalIssuedIdentity(): void {
        global $wpdb;
        $orders = self::registered('woocommerce_available_payment_gateways', WooCommerceOrderAdapter::class);
        $order = $this->order();
        $this->repos['lock']->synchronized($this->id, function () use ($orders, $order, $wpdb): void {
            $orders->beginInternalPaymentIdentityChange($this->orderId);
            try { $order->update_meta_data('_zarinpal_authority', $this->authority); $order->save_meta_data(); }
            finally { $orders->endInternalPaymentIdentityChange($this->orderId); }
            Assert::assertTrue((new PaymentRepository($wpdb))->persistIssuedAuthority($this->request(), $this->orderId, $this->authority));
        });
        Assert::assertSame($this->authority, $this->order()->get_meta('_zarinpal_authority', true));
        Assert::assertSame('authority_issued', $this->facts()['payment'][0]['status']);
    }

    public function request(): PurchaseRequest {
        $request = $this->repos['requests']->find($this->id); Assert::assertInstanceOf(PurchaseRequest::class, $request); return $request;
    }
    public function order(): WC_Order {
        // Woo 10.9.4 factory objects and HPOS datastore records have distinct,
        // prefixed cache keys. Use their native invalidators after HTTP writes.
        clean_post_cache($this->orderId);
        wc_get_container()->get(OrderCache::class)->remove($this->orderId);
        $store = WC_Data_Store::load('order');
        Assert::assertTrue(OrderUtil::custom_orders_table_usage_is_enabled(), 'Actual HPOS datastore required.');
        $store->clear_cached_data([$this->orderId]);
        $order = wc_get_order($this->orderId); Assert::assertInstanceOf(WC_Order::class, $order);
        $order->read_meta_data(true); return $order;
    }
    /** Observe the runner's native factory cache before refreshing it. */
    public function cacheObservation(): array {
        $cached = wc_get_order($this->orderId); Assert::assertInstanceOf(WC_Order::class, $cached);
        $before = ['status' => $cached->get_status(), 'is_paid' => $cached->is_paid(), 'transaction_id' => $cached->get_transaction_id()];
        $raw = $this->rawHposOrder();
        $fresh = $this->order();
        return ['before_native_invalidation' => $before, 'raw_hpos_before_invalidation' => $raw, 'after_native_invalidation' => [
            'status' => $fresh->get_status(), 'is_paid' => $fresh->is_paid(), 'transaction_id' => $fresh->get_transaction_id()]];
    }
    private function rawHposOrder(): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT id,status,currency,total_amount,transaction_id FROM %i WHERE id=%d',
            $wpdb->prefix . 'wc_orders', $this->orderId), ARRAY_A);
        Assert::assertIsArray($row); Assert::assertSame('', $wpdb->last_error);
        return $row;
    }
    /** Independent, authenticated native read in a fresh HTTP worker. */
    private function authenticatedOrderFacts(): array {
        $root = getenv('ZBM_TEST_WP_ROOT');
        Assert::assertIsString($root); Assert::assertFileExists($root . '/wp-load.php');
        $name = 'task5-finance-read-' . str_replace('-', '', wp_generate_uuid4()) . '.php';
        $path = rtrim($root, '/') . '/' . $name;
        $source = <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/wp-load.php';
if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true || !current_user_can('manage_options')) {
    wp_send_json(['code' => 'sandbox_admin_required'], 403);
}
$order = wc_get_order(absint($_GET['order_id'] ?? 0));
if (!$order instanceof WC_Order || 'yes' !== $order->get_meta('_zbm_managed_order', true)) {
    wp_send_json(['code' => 'managed_order_required'], 400);
}
$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d AND wc_order_id=%d',
    (new Zarbalman\Core\Infrastructure\Schema($wpdb))->purchase_requests_table(),
    (int) $order->get_meta('_zbm_request_id', true), $order->get_id()), ARRAY_A);
if (!is_array($row) || $wpdb->last_error !== '') { wp_send_json(['code' => 'request_read_failed'], 500); }
$request = Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest::fromRow($row);
wp_send_json(['actual_http' => true, 'authenticated_admin' => true, 'order_id' => $order->get_id(),
    'status' => $order->get_status(), 'is_paid' => $order->is_paid(), 'total' => (string) $order->get_total(),
    'transaction_id' => $order->get_transaction_id(),
    'snapshot_matches' => Zarbalman\Core\Integration\ManagedOrderSnapshot::matches($order, $request)]);
PHP;
        $handle = fopen($path, 'x'); Assert::assertIsResource($handle);
        $actor = get_current_user_id(); $hadCookie = isset($_COOKIE[LOGGED_IN_COOKIE]); $oldCookie = $_COOKIE[LOGGED_IN_COOKIE] ?? null;
        try {
            Assert::assertSame(strlen($source), fwrite($handle, $source)); fclose($handle); $handle = null;
            Assert::assertTrue(chmod($path, 0600));
            [$cookie] = CandidateHttp::auth($this->users['admin'], 'task5-finance-read');
            $http = Task4EndpointHttp::get($name, ['order_id' => $this->orderId], $cookie);
            Assert::assertSame(200, $http['status']);
            $facts = json_decode($http['body'], true, 32, JSON_THROW_ON_ERROR);
            Assert::assertTrue($facts['actual_http']); Assert::assertTrue($facts['authenticated_admin']);
            Assert::assertSame($this->orderId, $facts['order_id']);
            return $facts;
        } finally {
            if (is_resource($handle)) { fclose($handle); }
            $removed = unlink($path);
            wp_set_current_user($actor);
            if ($hadCookie) { $_COOKIE[LOGGED_IN_COOKIE] = $oldCookie; } else { unset($_COOKIE[LOGGED_IN_COOKIE]); }
            Assert::assertTrue($removed, 'Owned read-only HTTP probe must be removed.');
        }
    }
    public function configure(Task5DependencyDoubles $doubles, int $providerCode = 100, bool $concurrent = false, ?int $expectedRial = null): void {
        $doubles->configure(['supporting' => true, 'callback_probe_order_id' => $this->orderId,
            'callback_barrier_count' => $concurrent ? 2 : 0, 'responses' => ['verify.json' => [
                'status' => 200, 'body' => wp_json_encode($providerCode > 0
                    ? ['data' => ['code' => $providerCode, 'ref_id' => (int) $this->reference], 'errors' => []]
                    : ['data' => [], 'errors' => ['code' => $providerCode, 'message' => 'Synthetic rejected payment']], JSON_THROW_ON_ERROR),
                'expected_body' => ['amount' => $expectedRial ?? $this->deposit * 10, 'authority' => $this->authority,
                    'merchant_id' => 'TASK5-SYNTHETIC-NOT-A-PROVIDER-CREDENTIAL'],
            ]]]);
    }
    public function callback(string $status = 'OK', ?string $authority = null): array {
        return ['wc-api' => 'WC_ZPal', 'wc_order' => $this->orderId, 'Status' => $status, 'Authority' => $authority ?? $this->authority];
    }
    /** Explicit stale/corrupt state or simulated clock fixture; no native-transition claim. */
    public function persistedFixtureFields(array $fields): void {
        global $wpdb;
        Assert::assertSame(1, $wpdb->update((new Schema($wpdb))->purchase_requests_table(), $fields, ['id' => $this->id]), $wpdb->last_error);
    }
    public function expireNatively(string $expectedState = 'deposit_expired'): array {
        $before = $this->facts();
        $this->persistedFixtureFields(['deposit_deadline_gmt' => gmdate('Y-m-d H:i:s', time() - 30)]);
        $request = $this->request();
        $expired = self::service()->expireDeposit($this->id, $request->version());
        $after = $this->facts();
        self::evidence('expiry-observation-' . $this->id, ['before' => $before, 'after' => $after,
            'clock_fixture_deadline_in_past' => true, 'returned_error' => $expired instanceof WP_Error ? $expired->get_error_code() : null]);
        Assert::assertInstanceOf(PurchaseRequest::class, $expired);
        Assert::assertSame($expectedState, $this->request()->state());
        return ['before' => $before, 'after' => $after];
    }

    /** Exact persisted request-scoped facts, independent of HTTP response text. */
    public function facts(): array {
        global $wpdb;
        $this->preserveOriginalAccountEmails();
        $schema = new Schema($wpdb); $facts = [];
        foreach (['payment' => $schema->payments_table(), 'ledger' => $schema->ledger_table()] as $kind => $table) {
            $facts[$kind] = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE request_id=%d ORDER BY id', $table, $this->id), ARRAY_A);
            Assert::assertIsArray($facts[$kind]); Assert::assertSame('', $wpdb->last_error);
        }
        $facts['paid_events'] = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE resource_type='request' AND resource_id=%d AND event_key IN ('deposit_paid','late_deposit_captured') ORDER BY id", $schema->audit_table(), $this->id), ARRAY_A);
        Assert::assertIsArray($facts['paid_events']); Assert::assertSame('', $wpdb->last_error);
        $orders = wc_get_orders(['limit' => -1, 'return' => 'ids', 'type' => 'shop_order',
            'meta_query' => [['key' => '_zbm_request_id', 'value' => (string) $this->id, 'compare' => '=']]]);
        sort($orders); $facts['order_ids'] = array_map('intval', $orders);
        $facts['state'] = $this->request()->state(); $facts['order_total'] = (string) $this->order()->get_total();
        $facts['order_status'] = $this->order()->get_status();
        $facts['raw_hpos_order'] = $this->rawHposOrder();
        $facts['authenticated_http_order'] = $this->authenticatedOrderFacts();
        $facts['fixture_new_empty_email_customer'] = true; $facts['original_account_emails_unchanged'] = true;
        return $facts;
    }
    public function assertNoCash(): array {
        $facts = $this->facts(); Assert::assertSame([$this->orderId], $facts['order_ids']);
        Assert::assertCount(1, $facts['payment']); Assert::assertNull($facts['payment'][0]['paid_amount_toman']);
        Assert::assertNotContains($facts['payment'][0]['status'], ['captured', 'late_captured', 'late_accepted']);
        Assert::assertSame([], $facts['ledger']); Assert::assertSame([], $facts['paid_events']);
        Assert::assertFalse($this->order()->is_paid()); return $facts;
    }
    public function assertCaptured(int $platformToman, int $vendorToman, bool $late = false): array {
        $facts = $this->facts(); Assert::assertSame([$this->orderId], $facts['order_ids']); Assert::assertCount(1, $facts['payment']);
        $payment = $facts['payment'][0]; Assert::assertSame($late ? 'late_captured' : 'captured', $payment['status']);
        Assert::assertSame($this->deposit, (int) $payment['expected_amount_toman']); Assert::assertSame($this->deposit, (int) $payment['paid_amount_toman']);
        Assert::assertSame($this->authority, $payment['authority']); Assert::assertSame($this->reference, $payment['reference']);
        Assert::assertSame($late ? 'late_payment_review' : 'deposit_paid', $facts['state']);
        Assert::assertTrue($this->order()->is_paid()); Assert::assertTrue(ManagedOrderSnapshot::matches($this->order(), $this->request()));
        Assert::assertSame('wc-' . $facts['order_status'], $facts['raw_hpos_order']['status']);
        Assert::assertSame($this->reference, $facts['raw_hpos_order']['transaction_id']);
        Assert::assertTrue($facts['authenticated_http_order']['is_paid']);
        Assert::assertTrue($facts['authenticated_http_order']['snapshot_matches']);
        Assert::assertSame($facts['order_status'], $facts['authenticated_http_order']['status']);
        Assert::assertSame($this->reference, $facts['authenticated_http_order']['transaction_id']);
        Assert::assertMatchesRegularExpression('/\A' . $this->deposit . '(?:\.0+)?\z/', $facts['order_total']);
        Assert::assertMatchesRegularExpression('/\A' . $this->deposit . '(?:\.0+)?\z/', $facts['raw_hpos_order']['total_amount']);
        Assert::assertMatchesRegularExpression('/\A' . $this->deposit . '(?:\.0+)?\z/', $facts['authenticated_http_order']['total']);
        Assert::assertSame('IRT', $facts['raw_hpos_order']['currency']);
        Assert::assertCount($late ? 1 : 3, $facts['ledger']);
        $expected = ['employer_cash_received' => [$this->deposit, 'recorded', 'employer', 0, 'cash']];
        if (!$late) {
            $expected['platform_share_provisional'] = [$platformToman, 'provisional', 'platform', 0, 'platform'];
            $expected['vendor_share_provisional'] = [$vendorToman, 'provisional', 'vendor', $this->users['vendor_1'], 'vendor'];
            Assert::assertSame($this->deposit, $platformToman + $vendorToman);
        }
        foreach ($facts['ledger'] as $leg) {
            Assert::assertArrayHasKey($leg['entry_type'], $expected); [$amount, $status, $beneficiary, $owner, $suffix] = $expected[$leg['entry_type']];
            Assert::assertSame($amount, (int) $leg['amount_toman']); Assert::assertSame((int) $payment['id'], (int) $leg['payment_id']);
            Assert::assertSame($this->orderId, (int) $leg['order_id']); Assert::assertSame($status, $leg['status']);
            Assert::assertSame('IRT', $leg['currency']);
            Assert::assertSame($beneficiary, $leg['owner_type']); Assert::assertSame($owner, (int) $leg['vendor_id']);
            Assert::assertSame(hash('sha256', 'payment:' . $payment['id'] . ':' . $suffix), $leg['allocation_key']);
            unset($expected[$leg['entry_type']]);
        }
        Assert::assertSame([], $expected); Assert::assertCount(1, $facts['paid_events']);
        Assert::assertSame($late ? 'late_deposit_captured' : 'deposit_paid', $facts['paid_events'][0]['event_key']);
        return $facts;
    }
    public static function evidence(string $scenario, array $facts): void {
        $path = __DIR__ . '/../../evidence/task5-finance-' . $scenario . '.json';
        Assert::assertNotFalse(file_put_contents($path, wp_json_encode(['scenario' => $scenario, 'dependency_mode' => 'double-backed',
            'commercial_dependency_native' => false, 'real_provider_called' => false, 'provider_verification' => false,
            'simulated_provider_response' => true, 'facts' => $facts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)));
    }
}
