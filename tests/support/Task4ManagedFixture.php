<?php
declare(strict_types=1);

require_once __DIR__ . '/CandidateFixture.php';
require_once __DIR__ . '/HttpClient.php';

use PHPUnit\Framework\Assert;
use Zarbalman\Core\Application\ManualPaymentSettings;
use Zarbalman\Core\Domain\Finance\Spec4Data;
use Zarbalman\Core\Domain\ManagedFlow\OperationsData;
use Zarbalman\Core\Infrastructure\{Schema, ManagedCaseRepository, DeliveryDataCrypto, ProtectedText, LedgerRepository, DeliveryCodeSecret, ManagedEvidence};
use Zarbalman\Core\Security\AdminCapabilities;

/** Disposable database facts only. No provider, SDK, commercial class, or eligibility stub. */
final class Task4ManagedFixture {
    public array $users;
    public array $repos;
    public int $id;
    public int $orderId;
    public array $extra = [];
    public ?string $blockedCode = null;
    public ?string $blockedMessage = null;
    public ?string $limitation = null;
    private ProtectedText $protected;
    private static ?string $pngPath = null;

    public static function users(): array {
        $users = [];
        foreach (['admin', 'buyer_1', 'buyer_2', 'vendor_1', 'vendor_2'] as $name) {
            $user = get_user_by('login', 'zbm_test_' . $name);
            Assert::assertInstanceOf(WP_User::class, $user, 'Required disposable user is missing: ' . $name);
            $users[$name] = (int) $user->ID;
        }
        Assert::assertTrue(AdminCapabilities::userCanOperate($users['admin']));
        Assert::assertTrue(AdminCapabilities::userCanManageFinance($users['admin']));
        foreach (['vendor_1', 'vendor_2'] as $name) { update_user_meta($users[$name], 'dokan_enable_selling', 'yes'); }
        return $users;
    }

    public static function file(): CURLFile {
        if (self::$pngPath === null) {
            self::$pngPath = tempnam(sys_get_temp_dir(), 'zbm-task4-managed-');
            Assert::assertIsString(self::$pngPath);
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==', true);
            Assert::assertIsString($png);
            Assert::assertSame(strlen($png), file_put_contents(self::$pngPath, $png));
        }
        return new CURLFile(self::$pngPath, 'image/png', 'task4-proof.png');
    }

    public function __construct(string $operation) {
        global $wpdb;
        Assert::assertTrue(defined('ZBM_TEST_SANDBOX') && ZBM_TEST_SANDBOX === true);
        $this->users = self::users();
        $this->repos = CandidateFixture::repositories();
        $crypto = new DeliveryDataCrypto();
        Assert::assertTrue($crypto->ensureKey(), 'Real delivery encryption key must be healthy.');
        $this->protected = new ProtectedText($crypto);
        Assert::assertTrue($this->protected->ensureKey());
        Assert::assertNotNull((new DeliveryCodeSecret($wpdb))->loadOrCreate(), 'Real scoped delivery HMAC key must exist.');
        $product = new WC_Product_Simple();
        $product->set_name('Task 4 managed HTTP synthetic product');
        $product->set_regular_price('1000'); $product->set_weight('1');
        $productId = $product->save();
        Assert::assertGreaterThan(0, $productId);
        $updated = wp_update_post(['ID' => $productId, 'post_author' => $this->users['vendor_1']], true);
        Assert::assertFalse(is_wp_error($updated));
        $order = wc_create_order(['customer_id' => $this->users['buyer_1']]);
        Assert::assertInstanceOf(WC_Order::class, $order);
        $order->add_product($product, 1);
        $order->update_meta_data('_dokan_vendor_id', $this->users['vendor_1']);
        $order->save();
        $this->orderId = $order->get_id();
        $delivery = in_array($operation, ['flow_ready_delivery', 'flow_dispatch'], true);
        $payload = ['recipient_name' => 'Synthetic buyer', 'address' => 'Synthetic test address',
            'pickup_visit_confirmed' => true, 'pickup_note' => '',
            'pickup_address' => ['store_name' => 'Synthetic vendor', 'street_1' => 'Test street', 'street_2' => '', 'city' => 'Test city', 'zip' => '00000', 'state' => 'TEH', 'country' => 'IR']];
        [$request] = CandidateFixture::request([
            'buyer_id' => $this->users['buyer_1'], 'vendor_id' => $this->users['vendor_1'],
            'product_id' => $productId, 'wc_order_id' => $this->orderId, 'current_state' => 'deposit_paid',
            'delivery_method' => $delivery ? 'delivery_to_buyer_address' : 'pickup_from_vendor',
            'delivery_window_type' => 'range', 'delivery_start_gmt' => gmdate('Y-m-d H:i:s', time() - 60),
            'delivery_end_gmt' => gmdate('Y-m-d H:i:s', time() + 86400),
            'delivery_payload_encrypted' => $crypto->encrypt($payload),
        ]);
        $this->id = $request->id();
        $case = CandidateFixture::caseFor($request, ['state' => 'preparing']);
        $case['data']['operations'] = OperationsData::initial($request, current_time('mysql', true));
        $paymentId = $this->payment('deposit', 'captured', 100);
        $case['data']['funds']['deposit'] = ['amount' => 100, 'provider' => 'zarinpal', 'payment_id' => $paymentId];
        $case['data']['delivery']['address_kind'] = $delivery ? 'buyer_delivery' : 'vendor_pickup';
        $case['data']['delivery']['address_encrypted'] = $this->protected->encrypt('delivery_address_snapshot', $this->id, $request->deliveryMethod() . ':' . $this->orderId, wp_json_encode($payload, JSON_THROW_ON_ERROR));
        $this->save($case);
        $this->configure($operation);
        $this->validate();
    }

    /** A real captured row and actual canonical cash ledger; uniqueness prevents fixture leakage. */
    private function payment(string $phase, string $status, int $amount): int {
        global $wpdb;
        $schema = new Schema($wpdb); $now = current_time('mysql', true);
        $row = ['request_id' => $this->id, 'order_id' => $this->orderId, 'provider' => 'zarinpal', 'phase' => $phase,
            'expected_amount_toman' => $amount, 'paid_amount_toman' => $status === 'authority_issued' ? null : $amount,
            'status' => $status, 'authority' => 'TASK4' . str_replace('-', '', wp_generate_uuid4()),
            'reference' => $status === 'authority_issued' ? null : (string) $this->id . ($phase === 'deposit' ? '01' : '02'),
            'idempotency_hash' => hash('sha256', wp_generate_uuid4()), 'intent_deadline_gmt' => gmdate('Y-m-d H:i:s', time() + 3600),
            'paid_at_gmt' => $status === 'authority_issued' ? null : $now, 'created_at_gmt' => $now, 'updated_at_gmt' => $now];
        Assert::assertSame(1, $wpdb->insert($schema->payments_table(), $row), $wpdb->last_error);
        $id = (int) $wpdb->insert_id;
        if ($status !== 'authority_issued') {
            (new LedgerRepository($wpdb))->append($this->id, $this->orderId, $id, 'employer_cash_received', 'employer', 0, $amount, 'recorded', $phase === 'deposit' ? 'payment:' . $id . ':cash' : 'spec4-balance-cash:' . $id);
        }
        return $id;
    }

    private function configure(string $operation): void {
        if (in_array($operation, ['flow_record_weight', 'flow_pause', 'flow_resume', 'flow_extend_delivery', 'flow_note', 'flow_refer_return', 'flow_cancel'], true)) {
            if ($operation === 'flow_resume') {
                global $wpdb;
                Assert::assertSame(1, $wpdb->update((new Schema($wpdb))->purchase_requests_table(), ['is_paused' => 1, 'pause_reason' => 'Synthetic fixture pause', 'paused_at_gmt' => current_time('mysql', true)], ['id' => $this->id]));
                $case = $this->case(); $case['data']['pause'] = ['active' => true, 'remaining' => null]; $this->save($case);
            }
            if ($operation === 'flow_cancel') { $this->stage('return_review'); }
            return;
        }
        $this->prepareHttp('flow_record_weight', 'vendor_1', ['weight' => '1.0', 'evidence' => self::file()]);
        if (in_array($operation, ['flow_ready_delivery', 'flow_ready_pickup', 'flow_reopen_preparation'], true)) { return; }
        if ($operation === 'flow_dispatch') { $this->stage('ready_for_delivery'); return; }
        if (in_array($operation, ['flow_deliver', 'flow_reissue_code', 'flow_override_delivery'], true)) {
            $this->prepareHttp('flow_ready_pickup', 'vendor_1');
            $case = $this->case();
            $this->extra['code'] = $this->protected->decrypt('delivery_code', $this->id, $this->orderId . ':' . $case['data']['delivery']['generation'], $case['data']['delivery']['encrypted_code']);
            if ($operation === 'flow_override_delivery') {
                $this->stage('admin_review');
                $this->blockedCode = 'zbm_managed_service_code_required';
                $this->blockedMessage = 'عملیات پرونده انجام نشد؛ وضعیت، مهلت، نسخه و دسترسی را دوباره بررسی کنید.';
                $this->limitation = 'Retired override: the buyer delivery code is mandatory; success must never be introduced.';
            }
            return;
        }
        $case = $this->case(); $case['state'] = 'buyer_review';
        $case['deadline_gmt'] = gmdate('Y-m-d H:i:s', time() + 7200);
        $case['data']['delivery']['generation'] = 1;
        $case['data']['delivery']['delivered_at'] = current_time('mysql', true);
        $this->save($case);
        if (in_array($operation, ['flow_propose_weight', 'flow_object', 'flow_extend'], true)) { return; }
        if (in_array($operation, ['flow_reopen_buyer', 'flow_set_final_weight'], true)) { $this->stage('admin_review'); return; }
        $this->prepareHttp('flow_propose_weight', 'buyer_1', ['weight' => '1.0', 'evidence' => self::file()]);
        if ($operation === 'flow_accept_weight') { return; }
        if ($operation === 'flow_reopen_seller') { $this->stage('admin_review'); return; }
        $this->prepareHttp('flow_accept_weight', 'vendor_1');
        $case = $this->case();
        if ($operation === 'manual_credit_seller') {
            // Legacy settlement has no Spec4 adoption. Preserve valid historic legacy pricing.
            unset($case['data']['spec4']);
            foreach (['lock_version', 'pricing_version', 'product', 'shipping', 'remaining'] as $key) { unset($case['data']['final'][$key]); }
            $balance = $this->payment('balance', 'captured', 900);
            $case['data']['funds']['balance'] = ['amount' => 900, 'provider' => 'zarinpal', 'payment_id' => $balance];
            $case['state'] = 'completed'; $case['data']['final']['settlement'] = 'pending';
            $this->save($case);
            global $wpdb;
            $native = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s', $wpdb->prefix . 'dokan_vendor_balance'), ARRAY_A);
            if (!function_exists('dokan_withdraw_get_active_order_status') || !in_array('wc-completed', dokan_withdraw_get_active_order_status(), true)) {
                $this->blockedCode = 'zbm_manual_dokan_withdraw_status';
                $this->limitation = 'Native Dokan completed-order withdrawal status support is unavailable in the installed sandbox; seller credit cannot be proved.';
            } elseif (!is_array($native) || strtolower((string) ($native['Engine'] ?? '')) !== 'innodb') {
                $this->blockedCode = 'zbm_manual_dokan_native_engine';
                $this->limitation = 'The real Dokan vendor balance table is absent or nontransactional; no replacement table is fabricated.';
            } elseif (!class_exists(WeDevs\Dokan\Models\VendorBalance::class)) {
                $this->blockedCode = 'zbm_manual_dokan_dependency';
                $this->limitation = 'The real native Dokan VendorBalance model is unavailable; no compatibility class is fabricated.';
            }
            if ($this->blockedCode !== null) { $this->blockedMessage = 'شارژ قطعی فروشنده در Dokan ثبت نشد؛ پرونده برای تلاش امن با همان شناسه بدون تغییر باقی ماند.'; }
            return;
        }
        $case['data']['spec4'] = Spec4Data::initial($this->request(), $case, current_time('mysql', true), wp_generate_uuid4());
        $case['state'] = 'balance_due'; $case['data']['spec4']['substate'] = 'balance_due';
        $case['deadline_gmt'] = gmdate('Y-m-d H:i:s', time() + 7200);
        $this->save($case);
        if (in_array($operation, ['manual_offer', 'manual_receipt', 'manual_approve', 'manual_reject'], true)) {
            if ($operation === 'manual_offer') {
                $error = (new Zarbalman\Core\Infrastructure\DependencyHealth())->manualOrderError();
                Assert::assertInstanceOf(WP_Error::class, $error, 'Sandbox must expose unavailable commercial Dokan Pro/YITH without stand-ins.');
                $this->blockedCode = $error->get_error_code(); $this->blockedMessage = $error->get_error_message();
                $this->limitation = 'New manual offers require commercial Dokan Pro 5.0.8 and YITH 2.41.0; these are absent in CI. Existing obligations below are valid persisted fixtures, not compatibility substitutes.';
                return;
            }
            $case = $this->case(); $offerId = wp_generate_uuid4();
            $case['data']['manual']['balance'] = ['offer_id' => $offerId, 'amount' => 900, 'status' => 'offered',
                'account_encrypted' => $this->protected->encrypt('manual_account_snapshot', $this->id, $offerId, wp_json_encode(['card_number' => '4242424242424242', 'holder_name' => 'Synthetic test holder'], JSON_THROW_ON_ERROR)),
                'receipt_ids' => [], 'receipt_history' => [], 'offered_at' => current_time('mysql', true),
                'submitted_at' => null, 'reviewed_at' => null, 'reviewed_by' => 0, 'reason' => ''];
            $this->save($case); $this->extra['offer_id'] = $offerId;
            if ($operation !== 'manual_receipt') { $this->prepareHttp('manual_receipt', 'buyer_1', ['offer_id' => $offerId, 'receipt' => self::file()]); }
            return;
        }
        if ($operation === 'spec4_contact' || $operation === 'spec4_confirm_return') {
            $case = $this->case(); $case['state'] = 'admin_review'; $case['deadline_gmt'] = null;
            $case['data']['spec4']['substate'] = $operation === 'spec4_contact' ? 'contact_due' : 'warning_elapsed';
            if ($operation === 'spec4_confirm_return') {
                $f =& $case['data']['spec4'];
                $f['warning_started_at_gmt'] = gmdate('Y-m-d H:i:s', time() - 3600);
                $f['warning_deadline_gmt'] = gmdate('Y-m-d H:i:s', time() - 1800);
                $f['contact_history'][] = ['actor_id' => $this->users['admin'], 'at_gmt' => $f['warning_started_at_gmt'], 'reason_encrypted' => $this->protected->encrypt('finance_reason', $this->id, $f['offer_id'], 'Synthetic completed contact')];
            }
            $this->save($case); return;
        }
        if ($operation === 'spec4_accept_late' || $operation === 'spec4_reverify') {
            $case = $this->case(); $late = $operation === 'spec4_accept_late';
            $case['data']['spec4']['payment_id'] = $this->payment('balance', $late ? 'late_captured' : 'authority_issued', 900);
            $case['data']['spec4']['substate'] = 'payment_review'; $case['state'] = 'admin_review'; $case['deadline_gmt'] = null;
            $this->save($case);
            if (!$late) { $this->limitation = 'Reverification durably audits the attempt and enters payment_review. Actual provider verification is unavailable; no captured balance is expected or claimed.'; }
            return;
        }
        $this->extra += ['trigger' => 'seller_incapacity', 'iban' => 'IR' . '49' . str_repeat('0', 22), 'finance_confirmed' => '1'];
        // A mathematically valid synthetic IBAN; no transfer is performed by this service.
        if ($operation === 'spec4_create_refund') { return; }
        $this->prepareHttp('spec4_create_refund', 'admin', ['trigger' => 'seller_incapacity', 'iban' => $this->extra['iban'], 'finance_confirmed' => '1', 'evidence' => self::file()]);
        $case = $this->case(); $this->extra['financial_action_id'] = (string) end($case['data']['spec4']['finance_action_ids']);
        if ($operation === 'spec4_approve_action') { return; }
        $this->prepareHttp('spec4_approve_action', 'admin', ['financial_action_id' => $this->extra['financial_action_id']]);
        if ($operation === 'spec4_reconcile_action') {
            $this->prepareHttp('spec4_execute_action', 'admin', ['financial_action_id' => $this->extra['financial_action_id'], 'finance_confirmed' => '1', 'reference' => 'synthetic-bank-' . $this->id, 'evidence' => self::file()]);
        }
    }

    public function stage(string $state): void {
        $case = $this->case(); $case['state'] = $state;
        $case['deadline_gmt'] = in_array($state, ['buyer_review', 'seller_review'], true) ? gmdate('Y-m-d H:i:s', time() + 7200) : null;
        $this->save($case);
    }
    public function request(): Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest {
        $request = $this->repos['requests']->find($this->id);
        Assert::assertNotNull($request); return $request;
    }
    public function case(): array { $case = $this->repos['cases']->find($this->id); Assert::assertIsArray($case); return $case; }
    public function save(array $case): void {
        $this->validate($case); CandidateFixture::saveCase($case);
        Assert::assertSame($case, $this->case(), 'Fixture must round-trip exactly.');
    }
    public function validate(?array $case = null): void {
        $case = $case ?? $this->case(); $request = $this->request();
        (new ReflectionMethod(ManagedCaseRepository::class, 'validateCase'))->invoke($this->repos['cases'], $case, $request);
        (new ReflectionMethod(ManagedCaseRepository::class, 'assertEvidence'))->invoke($this->repos['cases'], $case, $request);
        Assert::assertTrue($this->repos['cases']->depositVerified($request, $case), 'Captured deposit, cash ledger, identity and amount must prove the fixture.');
    }
    public function fields(string $operation): array {
        $fields = ['action' => 'zbm_managed_case_action', 'operation' => $operation, 'request_id' => (string) $this->id,
            'version' => (string) $this->case()['version'], 'idempotency_key' => wp_generate_uuid4(), 'reason' => 'Synthetic Task 4 HTTP decision',
            'line_id' => (string) array_key_first($this->case()['data']['lines']), 'weight' => '1.0', 'acknowledged' => '1',
            'minutes' => '5', 'visibility' => 'private', 'phase' => 'balance', 'confirmed' => '1', 'confirmed_amount_toman' => '900',
            'finance_confirmed' => '1', 'reference' => 'synthetic-bank-' . $this->id];
        $fields = array_replace($fields, $this->extra);
        if (in_array($operation, ['flow_record_weight', 'flow_propose_weight', 'flow_object', 'spec4_confirm_return', 'spec4_create_refund', 'spec4_execute_action'], true)) { $fields['evidence'] = self::file(); }
        if ($operation === 'manual_receipt') { $fields['receipt'] = self::file(); }
        return $fields;
    }
    private function prepareHttp(string $operation, string $actor, array $extra = []): void {
        [$cookie, $nonce] = CandidateHttp::auth($this->users[$actor], 'zbm_managed_case_' . $this->id);
        $fields = array_replace($this->fields($operation), $extra, ['_zbm_nonce' => $nonce]);
        $before = $this->case()['version'];
        $http = CandidateHttp::post('wp-admin/admin-ajax.php', $fields, $cookie);
        Assert::assertSame(200, $http['status'], 'Fixture HTTP prerequisite failed: ' . $operation . ': ' . $http['body']);
        $json = json_decode($http['body'], true, 32, JSON_THROW_ON_ERROR);
        Assert::assertTrue($json['success'], 'Fixture prerequisite must be a real successful operation.');
        Assert::assertGreaterThan($before, $this->case()['version']);
        $this->validate();
    }
    /** Include audit, outbox, commands, finance and real Woo/attachment rows, excluding expected notice transients. */
    public static function snapshot(): array {
        global $wpdb;
        $tables = (new Schema($wpdb))->tables();
        $tables = array_merge($tables, [$wpdb->posts, $wpdb->postmeta, $wpdb->prefix . 'woocommerce_order_items', $wpdb->prefix . 'woocommerce_order_itemmeta']);
        foreach (['wc_orders', 'wc_order_addresses', 'wc_order_operational_data', 'wc_orders_meta', 'dokan_vendor_balance', 'dokan_orders'] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) { $tables[] = $table; }
        }
        $snapshot = [];
        foreach ($tables as $table) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY 1', $table), ARRAY_A);
            Assert::assertIsArray($rows); Assert::assertSame('', $wpdb->last_error, $table);
            $snapshot[$table] = hash('sha256', wp_json_encode($rows, JSON_THROW_ON_ERROR));
        }
        return $snapshot;
    }
    public static function notice(int $user): mixed {
        global $wpdb;
        return maybe_unserialize($wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, '_transient_zbm_managed_case_notice_' . $user)));
    }
}
