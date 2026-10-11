<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/CandidateFixture.php';
require_once __DIR__ . '/../support/HttpClient.php';
use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Http\ManagedCaseActionController;
use Zarbalman\Core\Infrastructure\Schema;

final class EndpointSecurityTest extends TestCase {
    public function test_real_http_negative_matrix_has_no_financial_or_order_effects(): void {
        global $wpdb;
        $users = [];
        foreach (['admin', 'buyer_1', 'buyer_2', 'vendor_1', 'vendor_2'] as $name) { $users[$name] = (int) get_user_by('login', 'zbm_test_' . $name)->ID; }
        update_user_meta($users['vendor_1'], 'dokan_enable_selling', 'yes');
        update_user_meta($users['vendor_2'], 'dokan_enable_selling', 'yes');
        $order = wc_create_order(['customer_id' => $users['buyer_1']]);
        $order->update_meta_data('_dokan_vendor_id', $users['vendor_1']); $order->save();
        [$request] = CandidateFixture::request(['wc_order_id' => $order->get_id()]);
        CandidateFixture::saveCase(CandidateFixture::caseFor($request));
        $id = $request->id(); $orderId = $order->get_id();
        $schema = new Schema($wpdb);
        $finance = $wpdb->get_row($wpdb->prepare('SELECT id,request_id FROM %i WHERE action_type=%s ORDER BY id LIMIT 1', $schema->financial_actions_table(), 'cancellation_refund'), ARRAY_A);
        self::assertIsArray($finance, 'Earlier finance regression must provide a real eligible destination.');
        $financeVersion = (int) $wpdb->get_var($wpdb->prepare('SELECT version FROM %i WHERE request_id=%d', $schema->managed_cases_table(), (int) $finance['request_id']));
        $rows = [
            ['BuyerRequest/create', 'zbm_create_purchase_request', 'zbm_create_purchase_request', 'buyer_1', []],
            ['VendorRequest/accept', 'zbm_vendor_request_decision', 'zbm_vendor_request_' . $id, 'vendor_1', ['decision' => 'accept']],
            ['VendorRequest/reject', 'zbm_vendor_request_decision', 'zbm_vendor_request_' . $id, 'vendor_1', ['decision' => 'reject']],
            ['BalancePayment/start', 'zbm_balance_start', 'zbm_managed_case_' . $id, 'buyer_1', []],
            ['WorkflowActionHandler/transition', 'zbm_core_transition', 'zbm_core_order_' . $orderId, 'buyer_1', ['to_state' => 'buyer_weight_recorded']],
            ['WorkflowActionHandler/control', 'zbm_core_control', 'zbm_core_order_' . $orderId, 'admin', ['command' => 'pause']],
            ['Spec4FinancePage/destination', 'zbm_finance_destination', 'zbm_finance_destination_' . $finance['request_id'] . '_' . $finance['id'], 'admin', ['financial_action_id' => (string) $finance['id'], 'request_id' => (string) $finance['request_id'], 'version' => (string) $financeVersion]],
            ['Spec4FinancePage/export-read-only', 'zbm_finance_export', 'zbm_finance_export', 'admin', []],
        ];
        $constants = (new ReflectionClass(ManagedCaseActionController::class))->getConstants();
        $operations = [];
        foreach (['ADMIN_FLOW' => 'admin', 'VENDOR_FLOW' => 'vendor_1', 'BUYER_FLOW' => 'buyer_1'] as $key => $owner) { foreach (array_keys($constants[$key]) as $operation) { $operations[$operation] = $owner; } }
        foreach ($constants['FINANCE_ACTIONS'] as $operation) { $operations[$operation] = 'admin'; }
        $operations += ['flow_accept_weight' => 'vendor_1', 'flow_object' => 'buyer_1', 'manual_offer' => 'buyer_1', 'manual_receipt' => 'buyer_1', 'manual_approve' => 'admin', 'manual_reject' => 'admin', 'manual_credit_seller' => 'admin'];
        foreach ($operations as $operation => $owner) { $rows[] = ['ManagedCaseAction/' . $operation, 'zbm_managed_case_action', 'zbm_managed_case_' . $id, $owner, ['operation' => $operation]]; }
        $results = [];
        foreach ($rows as [$label, $action, $nonceAction, $owner, $extra]) {
            $paths = $action === 'zbm_managed_case_action' ? ['wp-admin/admin-post.php', 'wp-admin/admin-ajax.php'] : ['wp-admin/admin-post.php'];
            foreach ($paths as $path) {
                foreach (['no_nonce', 'invalid_nonce', 'wrong_role', 'other_buyer', 'other_vendor', 'guest'] as $scenario) {
                    $actor = match ($scenario) { 'wrong_role' => $owner === 'buyer_1' ? 'vendor_1' : 'buyer_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', default => $owner };
                    [$cookie, $nonce] = $scenario === 'guest' ? ['', 'invalid'] : CandidateHttp::auth($users[$actor], $nonceAction);
                    $fields = array_replace(['action' => $action, 'request_id' => (string) $id, 'order_id' => (string) $orderId, 'version' => '0', 'product_id' => '1', 'quantity' => '1', 'idempotency_key' => wp_generate_uuid4(), 'correlation_id' => wp_generate_uuid4(), 'reason' => 'Synthetic negative security scenario'], $extra);
                    if ($scenario !== 'no_nonce') { $fields[$action === 'zbm_core_transition' || $action === 'zbm_core_control' ? '_wpnonce' : '_zbm_nonce'] = $scenario === 'invalid_nonce' ? 'invalid' : $nonce; }
                    $before = CandidateFixture::financialSnapshot();
                    $http = CandidateHttp::post($path, $fields, $cookie);
                    self::assertGreaterThanOrEqual(200, $http['status'], $label . '/' . $scenario);
                    self::assertLessThan(500, $http['status'], $label . '/' . $scenario);
                    self::assertSame($before, CandidateFixture::financialSnapshot(), $label . '/' . $scenario . ': unauthorized request changed persisted facts.');
                    if ($path === 'wp-admin/admin-ajax.php') { $json = json_decode($http['body'], true); self::assertFalse($json['success'] ?? false); }
                    $results[$label . '/' . basename($path)][$scenario] = ['result' => 'rejected-no-persisted-effect', 'http' => $http['status']];
                }
            }
        }
        // Public provider callback has a different contract: forged callback identity must not record money.
        $before = CandidateFixture::financialSnapshot();
        $http = CandidateHttp::post('wp-admin/admin-post.php?action=zbm_balance_callback&payment=999999&offer=' . wp_generate_uuid4() . '&Authority=MOCK12345678901234567890', []);
        self::assertLessThan(500, $http['status']); self::assertSame($before, CandidateFixture::financialSnapshot());
        $results['BalancePayment/forged-callback']['guest'] = ['result' => 'rejected-no-persisted-effect', 'http' => $http['status']];
        // Guest create branch at BuyerRequestController:497 only redirects; no business record is created.
        $before = CandidateFixture::financialSnapshot();
        $http = CandidateHttp::post('wp-admin/admin-post.php', ['action' => 'zbm_create_purchase_request', 'product_id' => '1']);
        self::assertContains($http['status'], [302, 303]); self::assertSame($before, CandidateFixture::financialSnapshot());
        $results['BuyerRequest/guest-login-preparation']['guest'] = ['result' => 'accepted-login-redirect-only', 'http' => $http['status']];
        // Positive control on the same real destination proves valid nonce/cookie and eligible state.
        [$cookie, $nonce] = CandidateHttp::auth($users['admin'], 'zbm_finance_destination_' . $finance['request_id'] . '_' . $finance['id']);
        $fields = ['action' => 'zbm_finance_destination', 'request_id' => (string) $finance['request_id'], 'financial_action_id' => (string) $finance['id'], 'version' => (string) $financeVersion, 'idempotency_key' => wp_generate_uuid4(), 'reason' => 'Synthetic positive HTTP authorization control', '_zbm_nonce' => $nonce];
        $http = CandidateHttp::post('wp-admin/admin-post.php', $fields, $cookie);
        self::assertSame(200, $http['status']);
        self::assertStringContainsString('IR000000000000000000000000', $http['body']);
        self::assertSame($financeVersion + 1, (int) $wpdb->get_var($wpdb->prepare('SELECT version FROM %i WHERE request_id=%d', $schema->managed_cases_table(), (int) $finance['request_id'])));
        $results['Spec4FinancePage/positive-control']['valid_admin_nonce'] = ['result' => 'accepted-durably-audited', 'http' => $http['status']];
        file_put_contents(__DIR__ . '/../../evidence/candidate-http-matrix.json', wp_json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        printf("HTTP matrix: %d endpoint/transport rows, %d negative scenarios per form row; no financial mutation observed. Positive business paths requiring commercial dependencies remain unverified.\n", count($results), 6);
        wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]);
    }
}
