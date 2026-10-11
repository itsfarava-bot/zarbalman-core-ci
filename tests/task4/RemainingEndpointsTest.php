<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/CandidateFixture.php';
require_once __DIR__ . '/../support/HttpClient.php';
require_once __DIR__ . '/../support/Task4EndpointHttp.php';
require_once __DIR__ . '/../support/Task4RemainingHttp.php';
require_once __DIR__ . '/../support/Task4RemainingFixture.php';
require_once __DIR__ . '/../support/Task4ManagedFixture.php';
use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Infrastructure\{Schema, DependencyHealth, WorkflowStore, VendorShippingRateRepository, ProtectedText, DeliveryDataCrypto};
use Zarbalman\Core\Domain\Shipping\IranProvinceCatalog;
use Zarbalman\Core\Application\AdminTestModeManager;

final class RemainingEndpointsTest extends TestCase {
    private array $users;
    private array $previousOptions = [];
    protected function setUp(): void {
        parent::setUp();
        foreach (['woocommerce_weight_unit' => 'kg', 'woocommerce_currency' => 'IRT'] as $name => $value) {
            $this->previousOptions[$name] = get_option($name, null); update_option($name, $value);
        }
        $this->users = Task4ManagedFixture::users();
    }
    protected function tearDown(): void {
        foreach ($this->previousOptions as $name => $value) { $value === null ? delete_option($name) : update_option($name, $value); }
        wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]); parent::tearDown();
    }
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        file_put_contents(__DIR__ . '/../../evidence/task4-remaining-http.json', wp_json_encode([
            '_status' => 'running; only completed assertions are recorded as passed',
            '_absent_classes' => ['ShippingRateController' => 'absent from production source; actual shipping handler is DokanShippingRateSettings', 'DeliveryCodeController' => 'absent from production source; delivery operations use ManagedCaseActionController'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    private function row(string $endpoint, string $scenario, callable $check): void {
        $path = __DIR__ . '/../../evidence/task4-remaining-http.json'; $rows = json_decode((string) file_get_contents($path), true);
        try { $details = $check(); $rows[$endpoint][$scenario] = ['assertion_status' => 'passed'] + $details; }
        catch (Throwable $error) { $rows[$endpoint][$scenario] = ['assertion_status' => 'failed', 'reason' => $error->getMessage()]; file_put_contents($path, wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); throw $error; }
        file_put_contents($path, wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    private function auth(string $actor, string $nonceAction): array { return $actor === 'guest' ? ['', ''] : CandidateHttp::auth($this->users[$actor], $nonceAction); }
    private function requestDependency(): WP_Error {
        $json = $this->json(Task4RemainingHttp::request('GET', 'task4-remaining-harness.php', ['view' => 'health']), 200);
        self::assertTrue($json['report']['weight']['ok']); self::assertSame('kg', $json['report']['weight']['detected']);
        self::assertTrue($json['report']['currency']['ok']); self::assertSame('IRT', $json['report']['currency']['detected']);
        self::assertSame('zbm_request_dependencies_unavailable', $json['request_creation_error']['code']);
        $labels = array_column(array_filter($json['report'], static fn(array $row): bool => $row['scope'] === 'request' && !$row['ok']), 'label');
        self::assertNotEmpty($labels, 'Actual HTTP sandbox lacks commercial request dependencies.');
        $message = __('ثبت درخواست تا رفع ناسازگاری تنظیمات پایه متوقف است.', 'zarbalman-core') . ' ' . implode('، ', $labels);
        self::assertSame($message, $json['request_creation_error']['message']);
        return new WP_Error($json['request_creation_error']['code'], $message);
    }
    private function denied(array $http, int $status, string $message): void {
        self::assertSame($status, $http['status']);
        self::assertStringContainsString($message, html_entity_decode(strip_tags($http['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    private function json(array $http, int $status, ?string $code = null): array {
        self::assertSame($status, $http['status']); $json = json_decode($http['body'], true); self::assertIsArray($json, substr($http['body'], 0, 500));
        if ($code !== null) { self::assertSame($code, $json['code'] ?? null); }
        return $json;
    }
    private function notice(string $prefix, string $actor): array {
        $notice = Task4EndpointHttp::option('_transient_' . $prefix . $this->users[$actor]); self::assertIsArray($notice); return $notice;
    }
    private function stable(): array {
        global $wpdb;
        $schema = new Schema($wpdb); $snapshot = CandidateFixture::financialSnapshot();
        foreach ([$schema->workflows_table(), $schema->audit_table()] as $table) { $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY 1', $table), ARRAY_A); self::assertIsArray($rows); self::assertSame('', $wpdb->last_error); $snapshot[$table] = hash('sha256', wp_json_encode($rows)); }
        return $snapshot;
    }
    private function audit(string $event, int $actor, ?int $resource = null): array {
        global $wpdb;
        $where = $resource === null ? '' : $wpdb->prepare(' AND resource_id=%d', $resource);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE event_key=%s AND actor_user_id=%d' . $where . ' ORDER BY id DESC LIMIT 1', (new Schema($wpdb))->audit_table(), $event, $actor), ARRAY_A);
        self::assertIsArray($row); return $row;
    }
    private function adminNegatives(string $action, string $nonceAction, array $fields, string $nonceField, string $denial, int $roleStatus = 403, string $nonceMessage = ''): void {
        foreach (['guest', 'wrong_role', 'other_buyer', 'other_vendor', 'no_nonce', 'invalid_nonce'] as $scenario) {
            $this->row($action, $scenario, function () use ($action, $nonceAction, $fields, $nonceField, $denial, $roleStatus, $nonceMessage, $scenario): array {
                $actor = match ($scenario) { 'guest' => 'guest', 'wrong_role' => 'buyer_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', default => 'admin' };
                [$cookie, $nonce] = $this->auth($actor, $nonceAction); $payload = ['action' => $action] + $fields;
                if ($scenario !== 'no_nonce') { $payload[$nonceField] = $scenario === 'invalid_nonce' ? 'invalid' : $nonce; }
                $before = $this->stable(); $http = CandidateHttp::post('wp-admin/admin-post.php', $payload, $cookie);
                if ($scenario === 'guest') { self::assertFalse(has_action('admin_post_nopriv_' . $action)); self::assertSame(400, $http['status']); $reason = 'WordPress dispatcher: no public handler'; }
                elseif ($scenario === 'no_nonce' || $scenario === 'invalid_nonce') { $reason = $nonceMessage !== '' ? $nonceMessage : __('The link you followed has expired.'); $this->denied($http, 403, $reason); }
                else { $reason = $denial; $this->denied($http, $roleStatus, $reason); }
                self::assertSame($before, $this->stable()); return ['http' => $http['status'], 'effect' => 'denied-no-persisted-effect', 'reason' => $reason];
            });
        }
    }

    public function test_admin_selftest_has_capability_nonce_matrix_and_real_positive_result(): void {
        $action = 'zbm_core_self_test'; $this->adminNegatives($action, $action, [], '_wpnonce', 'دسترسی غیرمجاز است.', 500);
        $this->row($action, 'authorized_positive', function () use ($action): array {
            [$cookie, $nonce] = $this->auth('admin', $action); $before = CandidateFixture::financialSnapshot();
            $http = CandidateHttp::post('wp-admin/admin-post.php', ['action' => $action, '_wpnonce' => $nonce], $cookie);
            self::assertSame(302, $http['status']); $result = $this->notice('zbm_core_self_test_', 'admin'); self::assertNotEmpty($result);
            $names = array_column($result, 'name'); self::assertContains('کنترل هم‌زمانی', $names); self::assertContains('بازپخش امن عملیات', $names);
            foreach ($result as $check) { self::assertIsBool($check['passed']); self::assertNotSame('', $check['message']); }
            foreach (['تراکنش اتمیک گردش', 'بازپخش امن عملیات', 'کنترل هم‌زمانی'] as $name) { $check = $result[array_search($name, $names, true)]; self::assertTrue($check['passed'], $check['message']); }
            self::assertSame($before, CandidateFixture::financialSnapshot());
            return ['http' => 302, 'effect' => 'real selftest result stored', 'checks' => count($result), 'reported_failed_checks' => array_values(array_column(array_filter($result, static fn($r) => !$r['passed']), 'name'))];
        });
    }

    public function test_testmode_wrong_role_preserves_each_legacy_setting(): void {
        $previous = get_option(AdminTestModeManager::OPTION, null);
        try {
            foreach (['yes', 'no'] as $setting) {
                update_option(AdminTestModeManager::OPTION, $setting, false);
                [$cookie, $nonce] = $this->auth('buyer_1', 'zbm_core_test_mode');
                $before = $this->stable();
                $http = CandidateHttp::post('wp-admin/admin-post.php', ['action' => 'zbm_core_test_mode', '_zbm_nonce' => $nonce, 'operation' => 'disable', 'reason' => 'Synthetic setting preservation'], $cookie);
                self::assertSame(400, $http['status']); self::assertSame($setting, Task4EndpointHttp::option(AdminTestModeManager::OPTION));
                self::assertSame($before, $this->stable());
            }
        } finally {
            $previous === null ? delete_option(AdminTestModeManager::OPTION) : update_option(AdminTestModeManager::OPTION, $previous, false);
        }
    }

    public function test_admin_testmode_disable_is_durable_and_enable_is_retired(): void {
        $action = 'zbm_core_test_mode'; $fields = ['operation' => 'disable', 'reason' => 'Synthetic legacy setting retirement'];
        foreach (['guest', 'wrong_role', 'other_buyer', 'other_vendor', 'no_nonce', 'invalid_nonce'] as $scenario) {
            $this->row($action, $scenario, function () use ($action, $fields, $scenario): array {
                $actor = match ($scenario) { 'guest' => 'guest', 'wrong_role' => 'buyer_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', default => 'admin' };
                [$cookie, $nonce] = $this->auth($actor, $action);
                $payload = ['action' => $action] + $fields;
                if ($scenario !== 'no_nonce') { $payload['_zbm_nonce'] = $scenario === 'invalid_nonce' ? 'invalid' : $nonce; }
                self::assertFalse(has_action('admin_post_' . $action)); self::assertFalse(has_action('admin_post_nopriv_' . $action));
                $before = $this->stable(); $option = Task4EndpointHttp::option(AdminTestModeManager::OPTION);
                $http = CandidateHttp::post('wp-admin/admin-post.php', $payload, $cookie);
                self::assertSame(400, $http['status']); self::assertStringContainsString('wp-die-message', $http['body']);
                self::assertSame($option, Task4EndpointHttp::option(AdminTestModeManager::OPTION)); self::assertSame($before, $this->stable());
                return ['http' => 400, 'effect' => 'no-persisted-effect', 'registered' => false];
            });
        }
        $this->row($action, 'authorized_disable_positive', function () use ($action, $fields): array {
            update_option(AdminTestModeManager::OPTION, 'yes', false); [$cookie, $nonce] = $this->auth('admin', $action);
            $http = CandidateHttp::post('wp-admin/admin-post.php', ['action' => $action, '_zbm_nonce' => $nonce] + $fields, $cookie);
            self::assertSame(302, $http['status']); self::assertSame('no', Task4EndpointHttp::option(AdminTestModeManager::OPTION));
            self::assertSame('success', $this->notice('zbm_core_test_mode_notice_', 'admin')['type']);
            $audit = $this->audit('admin_test_mode_disabled', $this->users['admin']); self::assertSame($fields['reason'], json_decode($audit['metadata_json'], true)['reason']);
            return ['http' => 302, 'effect' => 'legacy setting disabled with audit', 'audit_id' => (int) $audit['id']];
        });
        $this->row($action, 'authorized_enable_retired', function () use ($action): array {
            [$cookie, $nonce] = $this->auth('admin', $action); $before = $this->stable();
            $http = CandidateHttp::post('wp-admin/admin-post.php', ['action' => $action, '_zbm_nonce' => $nonce, 'operation' => 'enable', 'reason' => 'Synthetic retired route'], $cookie);
            self::assertSame(302, $http['status']); $notice = $this->notice('zbm_core_test_mode_notice_', 'admin'); self::assertSame('error', $notice['type']);
            self::assertSame('شروع حالت آزمایشی جدید غیرفعال است؛ از پرداخت دستی واقعی استفاده کنید.', $notice['message']); self::assertSame($before, $this->stable());
            return ['http' => 302, 'effect' => 'retired mutation correctly denied', 'reason' => $notice['message']];
        });
    }

    public function test_finance_export_and_destination_have_exact_matrix_and_positive_controls(): void {
        global $wpdb;
        [$request, $actionId, $iban, $repos] = Task4RemainingFixture::refund($this->users['buyer_1'], $this->users['admin']); $id = $request->id();
        $fields = ['request_id' => (string) $id, 'financial_action_id' => (string) $actionId, 'version' => '0', 'idempotency_key' => wp_generate_uuid4(), 'reason' => 'Synthetic private destination view'];
        $this->adminNegatives('zbm_finance_destination', 'zbm_finance_destination_' . $id . '_' . $actionId, $fields, '_zbm_nonce', 'دسترسی مالی لازم است.', 403, 'فرم معتبر نیست.');
        $this->adminNegatives('zbm_finance_export', 'zbm_finance_export', [], '_zbm_nonce', 'دسترسی مالی لازم است.', 403, 'فرم معتبر نیست.');
        $this->row('zbm_finance_destination', 'changed_action_binding', function () use ($fields, $id): array {
            [$cookie, $nonce] = $this->auth('admin', 'zbm_finance_destination_' . $id . '_2147483646'); $before = $this->stable();
            $http = CandidateHttp::post('wp-admin/admin-post.php', array_replace($fields, ['action' => 'zbm_finance_destination', 'financial_action_id' => '2147483646', '_zbm_nonce' => $nonce]), $cookie);
            $this->denied($http, 409, 'اقدام مالی با وضعیت فعلی پرونده سازگار نیست.'); self::assertSame($before, $this->stable());
            return ['http' => 409, 'effect' => 'valid changed-ID nonce reaches action-binding denial'];
        });
        $this->row('zbm_finance_destination', 'authorized_positive', function () use ($fields, $id, $actionId, $iban, $repos): array {
            [$cookie, $nonce] = $this->auth('admin', 'zbm_finance_destination_' . $id . '_' . $actionId);
            $http = CandidateHttp::post('wp-admin/admin-post.php', ['action' => 'zbm_finance_destination', '_zbm_nonce' => $nonce] + $fields, $cookie);
            self::assertSame(200, $http['status']); self::assertStringContainsString($iban, $http['body']); self::assertSame(1, $repos['cases']->find($id)['version']);
            $audit = $this->audit('spec4_destination_viewed', $this->users['admin'], $id); $metadata = json_decode($audit['metadata_json'], true);
            self::assertSame($this->users['admin'], $metadata['actor_id']); self::assertSame($actionId, $metadata['financial_action_id']);
            $finance = $repos['cases']->find($id)['data']['spec4']; self::assertCount(1, $finance['decision_history']); $decision = $finance['decision_history'][0];
            self::assertSame('destination_viewed', $decision['action']); self::assertSame($this->users['admin'], $decision['actor_id']); self::assertSame($actionId, $decision['financial_action_id']);
            self::assertNotSame($fields['reason'], $decision['reason_encrypted']); self::assertStringNotContainsString($fields['reason'], $audit['metadata_json']);
            self::assertSame($fields['reason'], (new ProtectedText(new DeliveryDataCrypto()))->decrypt('finance_reason', $id, $finance['offer_id'], $decision['reason_encrypted']));
            return ['http' => 200, 'effect' => 'private destination returned only with durable view audit', 'audit_id' => (int) $audit['id']];
        });
        $this->row('zbm_finance_export', 'authorized_positive', function () use ($wpdb, $id): array {
            $schema = new Schema($wpdb); $now = current_time('mysql', true);
            self::assertSame(1, $wpdb->insert($schema->ledger_table(), ['request_id' => $id, 'order_id' => 0, 'payment_id' => null, 'entry_type' => '=synthetic_formula', 'owner_type' => 'employer', 'vendor_id' => 0, 'amount_toman' => 731123, 'currency' => 'IRT', 'status' => 'recorded', 'allocation_key' => hash('sha256', 'task4-csv-' . wp_generate_uuid4()), 'created_at_gmt' => $now]));
            $ledgerId = (int) $wpdb->insert_id; [$cookie, $nonce] = $this->auth('admin', 'zbm_finance_export'); $before = $this->stable();
            $http = CandidateHttp::post('wp-admin/admin-post.php', ['action' => 'zbm_finance_export', '_zbm_nonce' => $nonce], $cookie);
            self::assertSame(200, $http['status']); self::assertStringContainsString('text/csv', Task4EndpointHttp::header($http, 'Content-Type'));
            $stream = fopen('php://temp', 'w+'); fwrite($stream, substr($http['body'], 3)); rewind($stream); $headers = fgetcsv($stream, 0, ',', '"', ''); self::assertSame('ledger_id', $headers[0]);
            $found = null; while (($csv = fgetcsv($stream, 0, ',', '"', '')) !== false) { if ((int) $csv[0] === $ledgerId) { $found = array_combine($headers, $csv); break; } } fclose($stream);
            self::assertIsArray($found); self::assertSame("'=synthetic_formula", $found['entry_type']); self::assertSame('731123', $found['amount_toman']); self::assertSame('IRT', $found['currency']); self::assertSame($before, $this->stable());
            return ['http' => 200, 'effect' => 'read-only actual CSV; dangerous formula field escaped', 'ledger_id' => $ledgerId];
        });
    }

    public function test_vendor_decisions_use_valid_owned_fixtures_and_exact_dependency_limitation(): void {
        $dependency = $this->requestDependency();
        foreach (['accept', 'reject'] as $decision) {
            [$request] = CandidateFixture::request(['current_state' => 'supplier_review', 'supplier_deadline_gmt' => gmdate('Y-m-d H:i:s', time() + 3600)]); $id = $request->id();
            foreach (['guest', 'wrong_role', 'other_buyer', 'other_vendor', 'no_nonce', 'invalid_nonce', 'authorized_dependency_control'] as $scenario) {
                $this->row('zbm_vendor_request_decision/' . $decision, $scenario, function () use ($decision, $id, $dependency, $scenario): array {
                    $actor = match ($scenario) { 'guest' => 'guest', 'wrong_role' => 'buyer_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', default => 'vendor_1' };
                    [$cookie, $nonce] = $this->auth($actor, 'zbm_vendor_request_' . $id);
                    $fields = ['action' => 'zbm_vendor_request_decision', 'decision' => $decision, 'request_id' => (string) $id, 'version' => '0', 'idempotency_key' => wp_generate_uuid4(), 'reason' => 'Synthetic vendor decision'];
                    if ($scenario !== 'no_nonce') { $fields['_zbm_nonce'] = $scenario === 'invalid_nonce' ? 'invalid' : $nonce; }
                    $before = $this->stable(); $http = CandidateHttp::post('wp-admin/admin-post.php', $fields, $cookie);
                    if ($scenario === 'guest') { self::assertSame(400, $http['status']); $message = 'WordPress dispatcher: no public handler'; }
                    else { self::assertSame(303, $http['status']); $notice = $this->notice('zbm_vendor_notice_', $actor); self::assertSame('error', $notice['type']);
                        $message = match ($scenario) { 'no_nonce', 'invalid_nonce' => 'اعتبار فرم پایان یافته یا حساب ورود تغییر کرده است. وضعیت تازهٔ درخواست را بررسی و سپس دوباره اقدام کنید.', 'authorized_dependency_control' => $dependency->get_error_message(), default => 'درخواست در دسترس نیست.' };
                        self::assertSame($message, $notice['message']); }
                    self::assertSame($before, $this->stable());
                    return ['http' => $http['status'], 'effect' => $scenario === 'authorized_dependency_control' ? 'commercial dependency limitation tested with valid owned request; successful decision untested' : 'denied-no-persisted-effect', 'reason' => $message, 'dependency_code' => $scenario === 'authorized_dependency_control' ? $dependency->get_error_code() : null];
                });
            }
        }
    }

    public function test_vendor_shipping_rate_add_update_delete_and_owner_scope(): void {
        $catalog = IranProvinceCatalog::all(); self::assertTrue(IranProvinceCatalog::isComplete($catalog)); $province = (string) array_key_first($catalog);
        $target = $this->users['vendor_1']; $other = $this->users['vendor_2']; $key = VendorShippingRateRepository::RATES_META_KEY;
        delete_user_meta($target, $key); delete_user_meta($other, $key); $action = 'zbm_save_vendor_shipping_rate';
        foreach (['guest', 'wrong_role', 'other_buyer', 'other_vendor', 'no_nonce', 'invalid_nonce'] as $scenario) {
            $this->row($action, $scenario, function () use ($scenario, $province, $action, $target, $other, $key): array {
                $actor = match ($scenario) { 'guest' => 'guest', 'wrong_role' => 'buyer_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', default => 'vendor_1' }; [$cookie, $nonce] = $this->auth($actor, $action);
                $fields = ['action' => $action, 'operation' => 'add', 'province_code' => $province, 'rate' => '731', 'vendor_id' => (string) $target];
                if ($scenario !== 'no_nonce') { $fields['_zbm_shipping_rate_nonce'] = $scenario === 'invalid_nonce' ? 'invalid' : $nonce; }
                $before = $this->stable(); $http = CandidateHttp::post('wp-admin/admin-post.php', $fields, $cookie);
                if ($scenario === 'guest') { self::assertSame(400, $http['status']); $reason = 'no public handler'; }
                elseif ($scenario === 'other_vendor') {
                    self::assertSame(303, $http['status']); self::assertSame('success', $this->notice('zbm_vendor_shipping_rate_notice_', $actor)['type']);
                    wp_cache_delete($other, 'user_meta'); self::assertSame(731, (new VendorShippingRateRepository())->rateFor($other, $province)); $reason = 'other approved vendor writes own rate; supplied target vendor ID ignored';
                } else { $reason = str_contains($scenario, 'nonce') ? 'درخواست نامعتبر است.' : 'دسترسی غیرمجاز است.'; $this->denied($http, 403, $reason); }
                wp_cache_delete($target, 'user_meta'); self::assertSame('', get_user_meta($target, $key, true)); self::assertSame($before, $this->stable());
                return ['http' => $http['status'], 'effect' => $scenario === 'other_vendor' ? 'allowed-own-scope-no-target-mutation' : 'denied-no-persisted-effect', 'reason' => $reason];
            });
        }
        foreach (['add' => 1731, 'update' => 2987, 'delete' => null] as $operation => $amount) {
            $this->row($action, 'authorized_' . $operation, function () use ($action, $operation, $amount, $target, $province): array {
                [$cookie, $nonce] = $this->auth('vendor_1', $action);
                $http = CandidateHttp::post('wp-admin/admin-post.php', ['action' => $action, 'operation' => $operation, 'province_code' => $province, 'rate' => (string) $amount, '_zbm_shipping_rate_nonce' => $nonce], $cookie);
                self::assertSame(303, $http['status']); self::assertSame('success', $this->notice('zbm_vendor_shipping_rate_notice_', 'vendor_1')['type']);
                wp_cache_delete($target, 'user_meta'); self::assertSame($amount, (new VendorShippingRateRepository())->rateFor($target, $province));
                return ['http' => 303, 'effect' => 'real vendor shipping rate ' . $operation . ' persisted'];
            });
        }
        $this->row($action, 'invalid_province', function () use ($action): array { [$cookie, $nonce] = $this->auth('vendor_1', $action); $http = CandidateHttp::post('wp-admin/admin-post.php', ['action' => $action, 'operation' => 'add', 'province_code' => 'not_a_province', 'rate' => '10', '_zbm_shipping_rate_nonce' => $nonce], $cookie); $this->denied($http, 400, 'استان انتخاب‌شده معتبر نیست.'); return ['http' => 400, 'reason' => 'invalid province rejected']; });
    }

    public function test_three_rest_methods_have_nonce_ownership_matrix_and_http_positives(): void {
        global $wpdb;
        $order = wc_create_order(['customer_id' => $this->users['buyer_1']]); $id = $order->get_id();
        $store = new WorkflowStore($wpdb, CandidateFixture::repositories()['audit']);
        foreach (['GET' => 'workflow', 'POST' => 'workflow', 'CONTROL' => 'workflow/control'] as $verb => $suffix) {
            $method = $verb === 'CONTROL' ? 'POST' : $verb;
            $endpoint = 'REST ' . $method . ' /zarbalman/v1/orders/{id}/' . $suffix;
            foreach (['guest', 'wrong_role', 'other_buyer', 'other_vendor', 'no_nonce', 'invalid_nonce', 'changed_id'] as $scenario) {
                $this->row($endpoint, $scenario, function () use ($method, $suffix, $verb, $id, $store, $scenario): array {
                    // Buyer cannot control or create->deposit_pending; GET admits the owner by design.
                    $actor = match ($scenario) { 'guest' => 'guest', 'wrong_role' => $verb === 'GET' ? 'buyer_2' : 'buyer_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', default => 'admin' };
                    [$cookie, $nonce] = $this->auth($actor, 'wp_rest');
                    if ($scenario === 'no_nonce' || $scenario === 'guest') { $nonce = null; }
                    if ($scenario === 'invalid_nonce') { $nonce = 'invalid'; }
                    $target = $scenario === 'changed_id' ? 2147483646 : $id;
                    $path = 'index.php?rest_route=' . rawurlencode('/zarbalman/v1/orders/' . $target . '/' . $suffix);
                    $fields = $method === 'GET' ? [] : ['to_state' => 'deposit_pending', 'idempotency_key' => wp_generate_uuid4(), 'metadata' => ['reason' => 'Synthetic REST workflow authorization'], 'paused' => '1', 'reason' => 'Synthetic REST workflow pause', 'correlation_id' => wp_generate_uuid4()];
                    $before = $this->stable(); $http = Task4RemainingHttp::request($method, $path, $fields, $cookie, $nonce);
                    $code = match ($scenario) { 'invalid_nonce' => 'rest_cookie_invalid_nonce', 'guest', 'no_nonce' => 'rest_forbidden', default => $verb === 'CONTROL' && $scenario !== 'changed_id' ? 'rest_forbidden' : ($scenario === 'wrong_role' && $verb === 'POST' ? 'zbm_transition_forbidden' : 'zbm_resource_not_found') };
                    $status = match ($code) { 'rest_cookie_invalid_nonce', 'zbm_transition_forbidden' => 403, 'rest_forbidden' => ($scenario === 'guest' || $scenario === 'no_nonce') ? 401 : 403, default => 404 };
                    $json = $this->json($http, $status, $code); self::assertNotSame('', $json['message']); self::assertSame($before, $this->stable());
                    return ['http' => $status, 'effect' => 'denied-no-persisted-effect', 'reason' => $code, 'message' => $json['message']];
                });
            }
            $this->row($endpoint, 'authorized_positive', function () use ($method, $suffix, $verb, $id, $store): array {
                $actor = $verb === 'GET' ? 'buyer_1' : 'admin'; [$cookie, $nonce] = $this->auth($actor, 'wp_rest');
                $path = 'index.php?rest_route=' . rawurlencode('/zarbalman/v1/orders/' . $id . '/' . $suffix);
                $fields = $method === 'GET' ? [] : ['to_state' => 'deposit_pending', 'idempotency_key' => wp_generate_uuid4(), 'metadata' => ['reason' => 'Synthetic REST workflow authorized'], 'paused' => '1', 'reason' => 'Synthetic REST workflow pause', 'correlation_id' => wp_generate_uuid4()];
                $before = $this->stable(); $http = Task4RemainingHttp::request($method, $path, $fields, $cookie, $nonce); $json = $this->json($http, 200);
                if ($verb === 'GET') { self::assertSame($id, $json['order_id']); self::assertSame('created', $json['state']); self::assertArrayNotHasKey('pause_reason', $json); self::assertSame($before, $this->stable()); }
                elseif ($verb === 'POST') { self::assertSame('deposit_pending', $json['to_state']); self::assertSame('deposit_pending', $store->snapshot($id)['state']); }
                else { self::assertTrue($json['is_paused']); self::assertTrue($store->snapshot($id)['is_paused']); }
                return ['http' => 200, 'effect' => $verb === 'GET' ? 'owner-scoped read; private pause reason omitted' : 'actual REST workflow mutation persisted', 'result' => $json];
            });
        }
    }

    public function test_ajax_auth_pricing_and_catalogue_are_real_public_or_scoped_reads(): void {
        $product = Task4RemainingFixture::product($this->users['vendor_1'], 'Task4 target vendor catalogue product');
        $otherProduct = Task4RemainingFixture::product($this->users['vendor_2'], 'Task4 OTHER vendor catalogue secret');
        foreach (['zbm_request_auth_state', 'zbm_request_pricing_preview', 'zbm_request_vendor_products'] as $action) {
            foreach (['guest', 'wrong_role', 'other_buyer', 'other_vendor', 'no_nonce', 'invalid_nonce', 'authorized_positive'] as $scenario) {
                $this->row($action, $scenario, function () use ($action, $scenario, $product, $otherProduct): array {
                    $actor = match ($scenario) { 'guest' => 'guest', 'wrong_role' => 'vendor_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', default => 'buyer_1' };
                    [$cookie] = $this->auth($actor, 'unused-public-read');
                    $fields = ['action' => $action, 'product_id' => (string) $product->get_id(), 'variation_id' => '0', 'quantity' => '2', 'search' => 'Task4'];
                    if ($scenario === 'invalid_nonce') { $fields['_zbm_nonce'] = 'invalid'; }
                    $before = $this->stable(); $http = CandidateHttp::post('wp-admin/admin-ajax.php', $fields, $cookie);
                    if ($action === 'zbm_request_vendor_products' && $scenario === 'guest') { self::assertSame(400, $http['status']); self::assertSame('0', trim($http['body'])); $effect = 'guest dispatcher denial: authenticated handler only'; }
                    else { $json = $this->json($http, 200); self::assertTrue($json['success']);
                        if ($action === 'zbm_request_auth_state') { self::assertSame($actor !== 'guest', $json['data']['authenticated']); if ($actor === 'guest') { self::assertArrayNotHasKey('create_nonce', $json['data']); self::assertArrayNotHasKey('idempotency_key', $json['data']); } else { self::assertNotSame('', $json['data']['create_nonce']); self::assertTrue(wp_is_uuid($json['data']['idempotency_key'])); } }
                        elseif ($action === 'zbm_request_pricing_preview') { self::assertSame($this->users['vendor_1'], $json['data']['vendor_id']); self::assertSame(2000, $json['data']['estimated_product_toman']); self::assertSame(20, $json['data']['reference_weight_tenths']); }
                        else { $ids = array_column($json['data']['items'], 'product_id'); self::assertContains($product->get_id(), $ids); self::assertNotContains($otherProduct->get_id(), $ids); }
                        $effect = 'allowed read; nonce N/A by source contract; submitted role/vendor does not change server product scope'; }
                    self::assertSame($before, $this->stable()); return ['http' => $http['status'], 'effect' => $effect, 'nonce_contract' => 'N/A: endpoint does not verify a form nonce'];
                });
            }
            if ($action !== 'zbm_request_auth_state') {
                $this->row($action, 'changed_product', function () use ($action): array { [$cookie] = $this->auth('buyer_1', 'unused'); $before = $this->stable(); $http = CandidateHttp::post('wp-admin/admin-ajax.php', ['action' => $action, 'product_id' => '2147483646', 'quantity' => '1'], $cookie); $json = $this->json($http, 400); self::assertFalse($json['success']); self::assertSame($action === 'zbm_request_vendor_products' ? 'فهرست محصولات در دسترس نیست؛ مشخصات محصول فعلی را انتخاب و دوباره تلاش کنید.' : 'محصول یا تعداد درخواست معتبر نیست.', $json['data']['message']); self::assertSame($before, $this->stable()); return ['http' => 400, 'reason' => $json['data']['message']]; });
            }
        }
    }

    public function test_buyer_creation_valid_form_reaches_real_commercial_dependency_denial(): void {
        $product = Task4RemainingFixture::product($this->users['vendor_1']);
        $fields = Task4RemainingFixture::createFields($product, $this->users['vendor_1']);
        $this->row('zbm_create_purchase_request', 'valid_input_precondition', function () use ($fields, $product): array {
            [$cookie] = $this->auth('admin', 'unused-read-validation'); $before = $this->stable();
            $input = $this->json(Task4RemainingHttp::request('POST', 'task4-remaining-harness.php?view=validate-input', $fields, $cookie), 200);
            self::assertSame($product->get_id(), $input['product_id']); self::assertSame($this->users['vendor_1'], $input['vendor_id']); self::assertSame(1, $input['quantity']);
            self::assertSame('delivery_to_buyer_address', $input['delivery_method']); self::assertSame($fields['province_code'], $input['province_code']); self::assertSame(100, $input['shipping_rate_toman']);
            self::assertSame($before, $this->stable()); return ['http' => 200, 'effect' => 'registered production validatedInput accepts complete form in actual HTTP process; no creation service invoked'];
        });
        $dependency = $this->requestDependency();
        foreach (['wrong_role', 'other_buyer', 'other_vendor', 'no_nonce', 'invalid_nonce', 'authorized_dependency_control'] as $scenario) {
            $this->row('zbm_create_purchase_request', $scenario, function () use ($scenario, $fields, $dependency): array {
                $actor = match ($scenario) { 'wrong_role' => 'vendor_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', default => 'buyer_1' };
                [$cookie, $nonce] = $this->auth($actor, 'zbm_create_purchase_request'); $payload = $fields;
                if ($scenario !== 'no_nonce') { $payload['_zbm_nonce'] = $scenario === 'invalid_nonce' ? 'invalid' : $nonce; }
                $before = $this->stable(); $http = CandidateHttp::post('wp-admin/admin-post.php', $payload, $cookie); self::assertSame(303, $http['status']);
                $notice = $this->notice('zbm_request_notice_', $actor); self::assertSame('error', $notice['type']);
                if (str_contains($scenario, 'nonce')) { self::assertSame('zbm_request_nonce_invalid', $notice['code']); self::assertSame('اعتبار فرم پایان یافته یا حساب ورود تغییر کرده است. صفحهٔ تازه بارگذاری شد؛ درخواست را دوباره از همین صفحه ثبت کنید.', $notice['message']); }
                else { self::assertSame($dependency->get_error_code(), $notice['code'], 'Valid fixture must get beyond input validation.'); self::assertSame($dependency->get_error_message(), $notice['message']); }
                self::assertSame($before, $this->stable()); return ['http' => 303, 'effect' => str_contains($scenario, 'nonce') ? 'nonce denied' : 'valid form; actual commercial dependency denial tested; successful creation untested', 'reason' => $notice['code'], 'message' => $notice['message']];
            });
        }
        $this->row('zbm_create_purchase_request', 'guest', function () use ($fields): array { $before = $this->stable(); $http = CandidateHttp::post('wp-admin/admin-post.php', $fields); self::assertSame(302, $http['status']); self::assertSame(parse_url(home_url('/'), PHP_URL_HOST), parse_url(Task4EndpointHttp::header($http, 'Location'), PHP_URL_HOST)); self::assertStringContainsString('zbm_request_return=', $http['headers']); self::assertSame($before, $this->stable()); return ['http' => 302, 'effect' => 'authorized public login preparation only; no request created']; });
    }

    public function test_balance_start_valid_delivered_paid_fixture_has_exact_gateway_denial(): void {
        $fixture = new Task4ManagedFixture('spec4_create_refund'); $case = $fixture->case(); self::assertSame('balance_due', $case['state']);
        self::assertFalse((new DependencyHealth())->onlineAvailableForNewPayment(), 'No real provider may be configured for this sandbox.');
        foreach (['guest', 'wrong_role', 'other_buyer', 'other_vendor', 'no_nonce', 'invalid_nonce', 'authorized_dependency_control'] as $scenario) {
            $this->row('zbm_balance_start', $scenario, function () use ($fixture, $scenario): array {
                $actor = match ($scenario) { 'guest' => 'guest', 'wrong_role' => 'vendor_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', default => 'buyer_1' };
                [$cookie, $nonce] = $this->auth($actor, 'zbm_managed_case_' . $fixture->id);
                $fields = ['action' => 'zbm_balance_start', 'request_id' => (string) $fixture->id, 'version' => (string) $fixture->case()['version'], 'idempotency_key' => wp_generate_uuid4()];
                if ($scenario !== 'no_nonce') { $fields['_zbm_nonce'] = $scenario === 'invalid_nonce' ? 'invalid' : $nonce; }
                $before = $this->stable(); $http = CandidateHttp::post('wp-admin/admin-post.php', $fields, $cookie);
                if ($scenario === 'guest') { self::assertSame(400, $http['status']); $reason = 'no public start handler'; }
                elseif (str_contains($scenario, 'nonce')) { $this->denied($http, 403, 'اعتبار فرم پایان یافته است؛ صفحه را تازه کنید.'); $reason = 'form nonce rejected'; }
                else { self::assertSame(303, $http['status']); $notice = $this->notice('zbm_managed_case_notice_', $actor); self::assertSame('error', $notice['type']); self::assertSame('نتیجهٔ پرداخت مانده هنوز قطعی نیست. آخرین وضعیت را بررسی کنید و تا تعیین نتیجه دوباره واریز نکنید.', $notice['message']); $reason = 'actual application not_payable denial; unavailable gateway or actor ownership'; }
                self::assertSame($before, $this->stable()); return ['http' => $http['status'], 'effect' => $scenario === 'authorized_dependency_control' ? 'valid delivered/final/paid-deposit fixture; issuance positive untested due to real unavailable gateway' : 'denied-no-persisted-effect', 'reason' => $reason];
            });
        }
    }

    public function test_balance_callback_public_contract_rejects_forged_identity_without_money(): void {
        $fixture = new Task4ManagedFixture('spec4_create_refund');
        foreach (['guest', 'wrong_role', 'other_buyer', 'other_vendor', 'no_nonce', 'invalid_nonce'] as $scenario) {
            $this->row('zbm_balance_callback', $scenario, function () use ($scenario, $fixture): array {
                $actor = match ($scenario) { 'guest' => 'guest', 'wrong_role' => 'vendor_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', default => 'buyer_1' }; [$cookie] = $this->auth($actor, 'unused-callback');
                $query = ['action' => 'zbm_balance_callback', 'payment' => '2147483646', 'offer' => wp_generate_uuid4(), 'Authority' => 'SYNTHETIC000000000000000000000001']; if ($scenario === 'invalid_nonce') { $query['_wpnonce'] = 'invalid'; }
                $before = $this->stable(); $http = Task4RemainingHttp::request('GET', 'wp-admin/admin-post.php', $query, $cookie); self::assertSame(303, $http['status']);
                $location = Task4EndpointHttp::header($http, 'Location'); self::assertSame(parse_url(home_url('/'), PHP_URL_HOST), parse_url($location, PHP_URL_HOST)); parse_str((string) parse_url($location, PHP_URL_QUERY), $args); self::assertArrayNotHasKey('request', $args);
                self::assertSame($before, $this->stable()); return ['http' => 303, 'effect' => 'forged payment identity receives generic local callback result; no money', 'nonce_contract' => 'N/A: public provider callback relies on persisted provider identity, not form nonce', 'verified_provider_positive' => 'untested: real payment verification unavailable'];
            });
        }
        $this->row('zbm_balance_callback', 'invalid_authority', function (): array { $http = Task4RemainingHttp::request('GET', 'wp-admin/admin-post.php', ['action' => 'zbm_balance_callback', 'payment' => '1', 'offer' => wp_generate_uuid4(), 'Authority' => 'https://evil.example.invalid']); $this->denied($http, 400, 'بازگشت پرداخت معتبر نیست.'); return ['http' => 400, 'reason' => 'callback syntax validation']; });
    }

    public function test_dependency_manual_verify_is_retired_for_real_managed_order(): void {
        $fixture = new Task4ManagedFixture('spec4_create_refund'); $order = wc_get_order($fixture->orderId); $order->update_meta_data('_zbm_managed_order', 'yes'); $order->save();
        foreach (['guest', 'wrong_role', 'other_buyer', 'other_vendor', 'no_nonce', 'invalid_nonce', 'authorized_retired_control'] as $scenario) {
            $this->row('zpal_manual_verify', $scenario, function () use ($fixture, $scenario): array {
                $actor = match ($scenario) { 'guest' => 'guest', 'wrong_role' => 'buyer_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', default => 'admin' }; [$cookie, $nonce] = $this->auth($actor, 'unused-retired');
                $fields = ['action' => 'zpal_manual_verify', 'order_id' => (string) $fixture->orderId]; if ($scenario !== 'no_nonce') { $fields['_wpnonce'] = $scenario === 'invalid_nonce' ? 'invalid' : $nonce; }
                $before = $this->stable(); $http = CandidateHttp::post('wp-admin/admin-ajax.php', $fields, $cookie);
                if ($scenario === 'guest') { self::assertSame(400, $http['status']); self::assertSame('0', trim($http['body'])); $reason = 'no public AJAX handler'; }
                else { $reason = 'برای این سفارش فقط «پذیرش پرداخت دیرهنگام» در صفحه درخواست‌های زربال‌من مجاز است؛ آن مسیر پرداخت را مستقیماً از زرین‌پال استعلام می‌کند.'; $this->denied($http, 403, $reason); }
                self::assertSame($before, $this->stable()); return ['http' => $http['status'], 'effect' => 'retired dependency manual mutation intercepted for managed order', 'reason' => $reason, 'nonce_contract' => 'N/A: early guard rejects all authenticated managed-order manual-verification attempts'];
            });
        }
    }

    public function test_registered_shortcodes_return_owner_reads_and_explicit_commercial_view_limitation(): void {
        $order = wc_create_order(['customer_id' => $this->users['buyer_1']]);
        [$request] = CandidateFixture::request(['current_state' => 'supplier_review', 'supplier_deadline_gmt' => gmdate('Y-m-d H:i:s', time() + 3600)]);
        foreach (['zbm_buyer_requests', 'zbm_supplier_requests', 'zbm_order_workflow'] as $shortcode) {
            foreach (['guest', 'wrong_role', 'other_buyer', 'other_vendor', 'no_nonce', 'invalid_nonce', 'authorized_positive'] as $scenario) {
                $this->row($shortcode, $scenario, function () use ($shortcode, $scenario, $request, $order): array {
                    $owner = $shortcode === 'zbm_supplier_requests' ? 'vendor_1' : 'buyer_1';
                    $actor = match ($scenario) { 'guest' => 'guest', 'wrong_role' => $owner === 'buyer_1' ? 'vendor_1' : 'buyer_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', default => $owner };
                    [$cookie] = $this->auth($actor, 'unused-shortcode');
                    $query = ['shortcode' => $shortcode, 'request' => (string) $request->id(), 'order_id' => (string) $order->get_id()]; if ($scenario === 'invalid_nonce') { $query['_wpnonce'] = 'invalid'; }
                    // Clear flash notices before the read so persisted business/audit snapshot remains the concern.
                    if ($actor !== 'guest') { foreach (['zbm_vendor_notice_', 'zbm_request_notice_'] as $prefix) { delete_transient($prefix . $this->users[$actor]); } }
                    $before = $this->stable(); $http = Task4RemainingHttp::request('GET', 'task4-remaining-harness.php', $query, $cookie); self::assertSame(200, $http['status']);
                    if ($shortcode === 'zbm_supplier_requests') {
                        self::assertFalse((new DependencyHealth())->canUseDokanBridge()); self::assertStringContainsString('نمای درخواست‌های فروشنده تا بازگرداندن نسخه تأییدشده دکان موقتاً متوقف است.', $http['body']); $effect = 'real vendor shortcode commercial-version gate tested; owner detail positive untested';
                    } elseif ($shortcode === 'zbm_buyer_requests') {
                        if ($actor === 'guest') { self::assertStringContainsString('برای مشاهده درخواست‌ها وارد حساب خود شوید.', $http['body']); $effect = 'guest login message'; }
                        elseif ($actor === 'buyer_1') { self::assertStringContainsString($request->requestNumber(), $http['body']); self::assertStringNotContainsString('این درخواست پیدا نشد یا دیگر در دسترس نیست.', $http['body']); $effect = 'owner request detail rendered'; }
                        else { self::assertStringContainsString('این درخواست پیدا نشد یا دیگر در دسترس نیست.', $http['body']); $effect = 'owner-scoped not-found rendered'; }
                    } else {
                        if ($actor === 'buyer_1') { self::assertStringContainsString('class="zbm-order-workflow"', $http['body']); $effect = 'owner legacy workflow rendered'; }
                        else { self::assertSame('', trim($http['body'])); $effect = 'unauthorized workflow shortcode empty by contract'; }
                    }
                    self::assertSame($before, $this->stable()); return ['http' => 200, 'effect' => $effect, 'nonce_contract' => 'N/A: read-only shortcode verifies ownership or real dependency gate'];
                });
            }
        }
    }
}
