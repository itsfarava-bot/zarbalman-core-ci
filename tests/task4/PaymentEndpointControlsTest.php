<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/PaymentEndpointFixture.php';
require_once __DIR__ . '/../support/Task4EndpointHttp.php';
require_once __DIR__ . '/../support/Task4RemainingHttp.php';

use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Infrastructure\{DependencyHealth, Schema};
use Zarbalman\Core\Integration\DokanPurchaseRequestEndpoint;

/** Additive exact dependency controls; no provider issuance/capture success is simulated. */
final class PaymentEndpointControlsTest extends TestCase {
    private array $previous = [];
    private const EVIDENCE = __DIR__ . '/../../evidence/http-payment-controls.json';

    protected function setUp(): void {
        parent::setUp();
        foreach (['woocommerce_currency' => 'IRT', 'woocommerce_weight_unit' => 'kg'] as $option => $value) {
            $this->previous[$option] = get_option($option, null); update_option($option, $value);
        }
    }
    protected function tearDown(): void {
        foreach ($this->previous as $option => $value) { $value === null ? delete_option($option) : update_option($option, $value); }
        wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]); parent::tearDown();
    }
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::assertTrue(is_dir(dirname(self::EVIDENCE)));
        self::write(['_meta' => ['schema_version' => 1, 'run_id' => getenv('GITHUB_RUN_ID') ?: 'local',
            'run_attempt' => getenv('GITHUB_RUN_ATTEMPT') ?: 'local', 'created_at_gmt' => gmdate('c'),
            'scope' => 'Only completed assertions; actual local HTTP; no provider issuance or capture success claimed']]);
    }
    private static function write(array $rows): void {
        $json = wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertSame(strlen($json), file_put_contents(self::EVIDENCE, $json, LOCK_EX));
    }
    /** Called only after all control assertions have completed. */
    private function record(string $endpoint, string $scenario, array $details): void {
        $rows = json_decode((string) file_get_contents(self::EVIDENCE), true, 32, JSON_THROW_ON_ERROR);
        $rows[$endpoint][$scenario] = ['state' => 'passed', 'assertion_status' => 'passed', 'actual_http' => true] + $details;
        self::write($rows);
    }
    private function dependencies(DependencyHealth $health): array {
        $http = Task4RemainingHttp::request('GET', 'task4-remaining-harness.php', ['view' => 'health']);
        self::assertSame(200, $http['status']);
        $json = json_decode($http['body'], true, 32, JSON_THROW_ON_ERROR);
        $report = $health->report();
        // These rows contain only health labels/version/status summaries, never merchant values.
        foreach (['currency', 'zarinpal_version', 'zarinpal_settings', 'dokan_lite', 'dokan_pro', 'yith'] as $key) {
            self::assertSame($report[$key], $json['report'][$key], 'Registered dependency health must agree with actual HTTP process.');
        }
        self::assertTrue($report['currency']['ok']); self::assertSame('IRT', $report['currency']['detected']);
        return array_intersect_key($report, array_flip(['currency', 'zarinpal_version', 'zarinpal_settings', 'dokan_lite', 'dokan_pro', 'yith']));
    }
    private function missing(array $report, array $keys): array {
        $missing = [];
        foreach ($keys as $key) { if (!$report[$key]['ok']) { $missing[$key] = $report[$key]; } }
        self::assertNotEmpty($missing, 'Exact unavailable dependencies must be named.');
        return $missing;
    }
    private function localRedirect(array $http, ?int $request): void {
        self::assertSame(303, $http['status']);
        $location = Task4EndpointHttp::header($http, 'Location');
        self::assertSame(parse_url(home_url('/'), PHP_URL_HOST), parse_url($location, PHP_URL_HOST));
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        if ($request === null) { self::assertArrayNotHasKey('request', $query); }
        else { self::assertSame((string) $request, $query['request'] ?? null); }
    }

    public function test_balance_start_is_payable_and_only_real_gateway_health_blocks_authorized_http(): void {
        $fixture = new PaymentEndpointFixture(); $contract = $fixture->contract();
        $report = $this->dependencies($fixture->health);
        $missing = $this->missing($report, ['currency', 'zarinpal_version', 'zarinpal_settings']);
        self::assertFalse($fixture->health->onlineAvailableForNewPayment());
        $buyer = $fixture->managed->users['buyer_1'];
        [$cookie, $nonce] = CandidateHttp::auth($buyer, 'zbm_managed_case_' . $fixture->managed->id);
        self::assertSame($buyer, get_current_user_id());
        self::assertNotFalse(wp_verify_nonce($nonce, 'zbm_managed_case_' . $fixture->managed->id));
        $key = wp_generate_uuid4(); self::assertTrue(wp_is_uuid($key));
        $fields = ['action' => 'zbm_balance_start', 'request_id' => (string) $fixture->managed->id,
            'version' => (string) $contract['version'], 'idempotency_key' => $key, '_zbm_nonce' => $nonce];
        $before = Task4ManagedFixture::snapshot(); $caseBefore = $fixture->managed->case();
        $http = CandidateHttp::post('wp-admin/admin-post.php', $fields, $cookie);
        $this->localRedirect($http, $fixture->managed->id);
        $notice = Task4ManagedFixture::notice($buyer); self::assertIsArray($notice);
        $message = 'نتیجهٔ پرداخت مانده هنوز قطعی نیست. آخرین وضعیت را بررسی کنید و تا تعیین نتیجه دوباره واریز نکنید.';
        self::assertSame(['type' => 'error', 'message' => $message], $notice);
        $after = Task4ManagedFixture::snapshot(); self::assertSame($before, $after);
        self::assertSame($caseBefore, $fixture->managed->case()); self::assertSame($contract, $fixture->contract());
        $this->record('zbm_balance_start', 'authorized_dependency_control', [
            'catalog_ids' => ['http-033', 'http-061'], 'test' => __METHOD__, 'type' => 'dependency', 'http' => 303,
            'effect' => 'canonical payable owned fixture blocked only by confirmed unavailable gateway health',
            'reason' => 'actual payable=true; actor/key/nonce valid; actual health onlineAvailableForNewPayment=false',
            'observed_response' => ['notice' => $notice], 'notice' => $notice, 'fixture_contract' => $contract,
            'actor_valid' => true, 'key_valid' => true, 'nonce_valid' => true,
            'online_available' => false, 'dependency_report' => $report, 'missing_dependencies' => $missing,
            'financial_unchanged' => true, 'case_unchanged' => true, 'audit_unchanged' => true,
            'before_version' => $caseBefore['version'], 'after_version' => $fixture->managed->case()['version'],
            'before_snapshot' => $before, 'after_snapshot' => $after, 'provider_issuance_verified' => false]);
    }

    public function test_callback_valid_stored_binding_enters_real_provider_unavailable_review_without_credit(): void {
        foreach (['authorized_dependency_control' => 'buyer_1', 'public_dependency_control' => 'guest'] as $scenario => $actor) {
            $fixture = new PaymentEndpointFixture();
            $report = $this->dependencies($fixture->health);
            $missing = $this->missing($report, ['zarinpal_version', 'zarinpal_settings']);
            // Assert the real adapter cannot even construct a merchant fingerprint.
            // This guarantees verify exits before its outbound post; no provider mock is installed.
            $gateway = PaymentEndpointFixture::property($fixture->service, 'gateway');
            try { $gateway->fingerprint(); self::fail('Real provider credentials must remain unavailable in CI.'); }
            catch (RuntimeException $error) { self::assertSame('Provider verification settings unavailable.', $error->getMessage()); }
            self::assertInstanceOf(WP_Error::class, $fixture->health->paymentVerificationError());
            $identity = $fixture->issuedObligation();
            self::assertSame(PaymentEndpointFixture::controller('admin_post_zbm_balance_start'), PaymentEndpointFixture::controller($actor === 'guest' ? 'admin_post_nopriv_zbm_balance_callback' : 'admin_post_zbm_balance_callback'));
            if ($actor === 'guest') { wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]); $cookie = ''; }
            else { [$cookie] = CandidateHttp::auth($fixture->managed->users[$actor], 'unused-public-callback'); }
            $before = Task4ManagedFixture::snapshot(); $caseBefore = $fixture->managed->case();
            $financialBefore = CandidateFixture::financialSnapshot();
            $http = Task4RemainingHttp::request('GET', 'wp-admin/admin-post.php', ['action' => 'zbm_balance_callback'] + $identity, $cookie);
            $this->localRedirect($http, $actor === 'guest' ? null : $fixture->managed->id);
            $caseAfter = $fixture->managed->case(); $row = $fixture->payments->get((int) $identity['payment']); self::assertIsArray($row);
            self::assertSame('verification_pending', $row['status']); self::assertNotNull($row['callback_at_gmt']);
            self::assertNull($row['paid_amount_toman']); self::assertNull($row['reference']); self::assertNull($row['paid_at_gmt']);
            self::assertSame('admin_review', $caseAfter['state']); self::assertNull($caseAfter['deadline_gmt']);
            self::assertSame('payment_review', $caseAfter['data']['spec4']['substate']);
            self::assertSame('review', $caseAfter['data']['spec4']['reconciliation_status']);
            self::assertSame($caseBefore['version'] + 2, $caseAfter['version']);
            self::assertSame($caseBefore['data']['funds'], $caseAfter['data']['funds']);
            self::assertSame(0, $caseAfter['data']['funds']['balance']['amount']);
            self::assertSame(900, $caseAfter['data']['spec4']['remaining_toman']);
            self::assertSame($caseBefore['data']['final'], $caseAfter['data']['final']);
            $financialAfter = CandidateFixture::financialSnapshot();
            global $wpdb; $schema = new Schema($wpdb);
            foreach ([$schema->ledger_table(), $schema->financial_actions_table(), $schema->purchase_requests_table(), 'orders'] as $key) {
                self::assertSame($financialBefore[$key], $financialAfter[$key], 'Unverified callback must not credit amounts, ledgers, requests or Woo orders.');
            }
            $events = $wpdb->get_results($wpdb->prepare('SELECT event_key FROM %i WHERE resource_id=%d AND event_key IN (%s,%s) ORDER BY id',
                $schema->audit_table(), $fixture->managed->id, 'spec4_callback_received', 'spec4_payment_review'), ARRAY_A);
            self::assertSame('', $wpdb->last_error);
            self::assertSame(['spec4_callback_received', 'spec4_payment_review'], array_column($events, 'event_key'));
            $notice = null;
            if ($actor !== 'guest') {
                $notice = Task4ManagedFixture::notice($fixture->managed->users[$actor]);
                self::assertSame(['type' => 'error', 'message' => 'نتیجهٔ پرداخت مانده نیازمند بررسی است. تا روشن‌شدن نتیجه دوباره واریز نکنید.'], $notice);
            }
            $after = Task4ManagedFixture::snapshot();
            $allowed = [$schema->payments_table(), $schema->managed_cases_table(), $schema->audit_table(), $schema->managed_case_commands_table(), $schema->notification_outbox_table()];
            foreach ($before as $table => $hash) { if (!in_array($table, $allowed, true)) { self::assertSame($hash, $after[$table], $table); } }
            $this->record('zbm_balance_callback', $scenario, [
                'catalog_ids' => [$actor === 'guest' ? 'http-035' : 'http-034'], 'test' => __METHOD__, 'type' => 'dependency', 'http' => 303,
                'effect' => 'valid stored identity durably observed then reviewed by real unavailable provider adapter; no credit',
                'role_contract' => 'N/A: public provider callback authenticates persisted payment/offer/Authority binding',
                'nonce_contract' => 'N/A: public provider callback does not use a form nonce',
                'stored_binding_valid' => true, 'historical_synthetic_obligation' => true,
                'provider_credentials_configured' => false, 'provider_verified' => false, 'outbound_attempted' => false,
                'provider_prerequisite_error' => 'Provider verification settings unavailable.',
                'dependency_report' => $report, 'missing_dependencies' => $missing, 'notice' => $notice,
                'before_version' => $caseBefore['version'], 'after_version' => $caseAfter['version'],
                'before_state' => $caseBefore['state'], 'after_state' => $caseAfter['state'],
                'payment_status' => $row['status'], 'callback_observed' => true, 'substate' => 'payment_review',
                'audit_events' => array_column($events, 'event_key'), 'balance_toman' => 0, 'remaining_toman' => 900,
                'amounts_and_ledger_unchanged' => true, 'before_snapshot' => $before, 'after_snapshot' => $after]);
        }
    }

    public function test_invalid_authority_has_specific_actual_http_validation_message_and_no_writes(): void {
        foreach (['buyer_1', 'guest'] as $actor) {
            $users = Task4ManagedFixture::users();
            if ($actor === 'guest') { wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]); $cookie = ''; }
            else { [$cookie] = CandidateHttp::auth($users[$actor], 'unused-public-callback'); }
            $before = Task4ManagedFixture::snapshot();
            $http = Task4RemainingHttp::request('GET', 'wp-admin/admin-post.php', ['action' => 'zbm_balance_callback',
                'payment' => '1', 'offer' => wp_generate_uuid4(), 'Authority' => 'https://evil.example.invalid'], $cookie);
            self::assertSame(400, $http['status']); self::assertStringContainsString('wp-die-message', $http['body']);
            $message = 'بازگشت پرداخت معتبر نیست.';
            self::assertStringContainsString($message, html_entity_decode(strip_tags($http['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $after = Task4ManagedFixture::snapshot(); self::assertSame($before, $after);
            $this->record('zbm_balance_callback', $actor === 'guest' ? 'invalid_authority_guest' : 'invalid_authority', [
                'catalog_ids' => [$actor === 'guest' ? 'http-035' : 'http-034'], 'test' => __METHOD__, 'type' => 'validation', 'http' => 400,
                'effect' => 'specific syntax denial before callback service; no persisted effect',
                'observed_response' => ['message' => $message], 'message' => $message,
                'role_contract' => 'N/A: public provider callback', 'nonce_contract' => 'N/A: provider callback identity contract',
                'financial_unchanged' => true, 'before_snapshot' => $before, 'after_snapshot' => $after]);
        }
    }

    public function test_supplier_shortcode_exact_real_pinned_dependency_gate_preserves_financial_state(): void {
        // Resolve the production endpoint through its registered Dokan hook;
        // the shortcode itself is dispatched and verified in the HTTP process.
        $registration = $GLOBALS['wp_filter']['dokan_load_custom_template'] ?? null;
        self::assertInstanceOf(WP_Hook::class, $registration);
        $matches = [];
        foreach ($registration->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                $target = $callback['function'];
                if (is_array($target) && ($target[0] ?? null) instanceof DokanPurchaseRequestEndpoint && ($target[1] ?? null) === 'loadTemplate') { $matches[] = $target[0]; }
            }
        }
        self::assertCount(1, $matches, 'Exactly one real Dokan purchase-request endpoint must be registered.');
        $endpoint = $matches[0];
        $health = PaymentEndpointFixture::property($endpoint, 'health'); self::assertInstanceOf(DependencyHealth::class, $health);
        $report = $this->dependencies($health); self::assertFalse($health->canUseDokanBridge());
        self::assertFalse($report['dokan_pro']['ok']); self::assertSame('', $report['dokan_pro']['detected']); self::assertSame('5.0.8', $report['dokan_pro']['expected']);
        self::assertFalse($report['yith']['ok']); self::assertSame('', $report['yith']['detected']); self::assertSame('2.41.0', $report['yith']['expected']);
        $missing = $this->missing($report, ['dokan_lite', 'dokan_pro']);
        $users = Task4ManagedFixture::users();
        $eligibility = PaymentEndpointFixture::property($endpoint, 'vendor_eligibility');
        self::assertTrue($eligibility->isApprovedVendor($users['vendor_1']));
        [$request] = CandidateFixture::request(['current_state' => 'supplier_review', 'supplier_deadline_gmt' => gmdate('Y-m-d H:i:s', time() + 3600)]);
        foreach (['guest' => 'guest', 'wrong_role' => 'buyer_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2',
            'no_nonce' => 'vendor_1', 'invalid_nonce' => 'vendor_1', 'authorized_dependency_control' => 'vendor_1'] as $scenario => $actor) {
            if ($actor === 'guest') { wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]); $cookie = ''; }
            else { [$cookie] = CandidateHttp::auth($users[$actor], 'unused-read-only-shortcode'); }
            $query = ['shortcode' => 'zbm_supplier_requests', 'request' => (string) $request->id()];
            if ($scenario === 'invalid_nonce') { $query['_wpnonce'] = 'invalid'; }
            $before = Task4ManagedFixture::snapshot();
            $http = Task4RemainingHttp::request('GET', 'task4-remaining-harness.php', $query, $cookie);
            self::assertSame(200, $http['status']);
            $message = 'نمای درخواست‌های فروشنده تا بازگرداندن نسخه تأییدشده دکان موقتاً متوقف است.';
            self::assertSame('<p>' . $message . '</p>', trim($http['body']));
            $after = Task4ManagedFixture::snapshot(); self::assertSame($before, $after);
            $this->record('zbm_supplier_requests', $scenario, [
                'catalog_ids' => ['http-042'], 'test' => __METHOD__, 'type' => 'dependency', 'http' => 200,
                'effect' => 'exact real Dokan pinned-version dependency gate; vendor view unavailable',
                'message' => $message, 'observed_response' => ['message' => $message], 'can_use_dokan_bridge' => false,
                'dependency_report' => $report, 'missing_dependencies' => $missing,
                'yith_missing_separate_from_bridge_cause' => true, 'approved_owner_vendor' => true,
                'role_contract' => 'N/A: real early dependency gate runs before vendor eligibility and ownership checks',
                'nonce_contract' => 'N/A: read-only shortcode does not verify a form nonce',
                'financial_unchanged' => true, 'case_unchanged' => true, 'before_snapshot' => $before, 'after_snapshot' => $after,
                'owner_detail_rendered' => false]);
        }
    }
}
