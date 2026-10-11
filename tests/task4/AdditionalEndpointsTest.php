<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/CandidateFixture.php';
require_once __DIR__ . '/../support/HttpClient.php';
require_once __DIR__ . '/../support/Task4EndpointHttp.php';
require_once __DIR__ . '/../support/Task4EndpointFixture.php';
use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Infrastructure\{Schema, WorkflowStore, ProtectedText, DeliveryDataCrypto};
use Zarbalman\Core\Application\ManualPaymentSettings;
use Zarbalman\Core\Integration\{DokanVendorEligibility, ZarinpalBalanceAdapter};

final class AdditionalEndpointsTest extends TestCase {
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $names = ['evidence-download-legacy-and-managed', 'manual-settings-and-online-control',
            'purchase-request-admin-control', 'hpos-metabox-and-workflow-forms',
            'approved-vendor-reports-isolation', 'guest-login-return-open-redirect', 'balance-url-constructor'];
        $rows = array_fill_keys($names, ['status' => 'pending', 'reason' => 'Assertions have not executed in this run.']);
        $rows['balance-provider-issuance-http'] = ['status' => 'untested', 'reason' => 'Commercial gateway dependencies unavailable; constructor-only coverage is separate.'];
        file_put_contents(__DIR__ . '/../../evidence/task4-additional-http.json', wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    private function user(string $name): int {
        $user = get_user_by('login', 'zbm_test_' . $name);
        self::assertInstanceOf(WP_User::class, $user, 'Required synthetic user missing.');
        return (int) $user->ID;
    }
    private function scenario(string $name, callable $test): void {
        $file = __DIR__ . '/../../evidence/task4-additional-http.json';
        $rows = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        if (!is_array($rows)) { $rows = []; }
        try { $detail = $test(); $rows[$name] = ['status' => 'passed', 'detail' => $detail]; }
        catch (Throwable $error) {
            $rows[$name] = ['status' => 'failed', 'reason' => $error->getMessage()];
            file_put_contents($file, wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            throw $error;
        }
        file_put_contents($file, wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    private function denied(array $http, int $status, string $message): void {
        self::assertSame($status, $http['status']);
        self::assertStringContainsString($message, html_entity_decode(strip_tags($http['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    private function audit(string $event, int $actor, ?int $resourceId = null): array {
        global $wpdb;
        $schema = new Schema($wpdb);
        $where = $resourceId === null ? '' : $wpdb->prepare(' AND resource_id=%d', $resourceId);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE event_key=%s AND actor_user_id=%d' . $where . ' ORDER BY id DESC LIMIT 1', $schema->audit_table(), $event, $actor), ARRAY_A);
        self::assertIsArray($row, 'Durable audit row missing for ' . $event);
        self::assertSame('admin', $row['actor_type']);
        return $row;
    }
    private function auditCount(): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', (new Schema($wpdb))->audit_table()));
    }
    private function notice(string $prefix, int $actor): array {
        $value = Task4EndpointHttp::option('_transient_' . $prefix . $actor);
        self::assertIsArray($value, 'Controller notice was not durably written.');
        return $value;
    }
    private function result(array $http, string $result): void {
        self::assertSame(302, $http['status']);
        $location = Task4EndpointHttp::header($http, 'Location');
        self::assertSame($location, wp_validate_redirect($location, ''));
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame($result, $query['zbm_result'] ?? null);
        self::assertSame(parse_url(home_url('/'), PHP_URL_HOST), parse_url(wp_validate_redirect($location, home_url('/')), PHP_URL_HOST) ?? parse_url(home_url('/'), PHP_URL_HOST));
    }
    protected function tearDown(): void {
        wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]);
        parent::tearDown();
    }

    public function test_private_legacy_and_managed_downloads_have_real_binary_positives_and_exact_denials(): void {
        $this->scenario('evidence-download-legacy-and-managed', function (): array {
            $buyer = $this->user('buyer_1'); $vendor = $this->user('vendor_1');
            update_user_meta($vendor, 'dokan_enable_selling', 'yes');
            $order = wc_create_order(['customer_id' => $buyer]);
            $order->update_meta_data('_dokan_vendor_id', $vendor); $order->save();
            [$request] = CandidateFixture::request(['wc_order_id' => $order->get_id()]);
            [$legacyId, $binary] = Task4EndpointFixture::evidence($order->get_id(), $buyer);
            [$managedId] = Task4EndpointFixture::evidence($order->get_id(), $buyer, $request->id());
            $case = CandidateFixture::caseFor($request);
            foreach ($case['data']['lines'] as &$line) { $line['buyer_evidence'] = [$managedId]; } unset($line);
            CandidateFixture::saveCase($case);
            $rows = [];
            foreach (['legacy' => $legacyId, 'managed' => $managedId] as $kind => $id) {
                $action = $kind === 'legacy' ? 'zbm_core_download_evidence' : 'zbm_core_download_managed_evidence';
                $nonceAction = $kind === 'legacy' ? 'zbm_core_evidence_' . $id : 'zbm_core_managed_evidence_' . $request->id() . '_' . $id;
                $base = ['action' => $action, 'attachment_id' => $id];
                if ($kind === 'managed') { $base['request_id'] = $request->id(); }
                foreach (['buyer_1', 'admin'] as $name) {
                    [$cookie, $nonce] = CandidateHttp::auth($this->user($name), $nonceAction);
                    $http = Task4EndpointHttp::get('wp-admin/admin-post.php', $base + ['_wpnonce' => $nonce], $cookie);
                    self::assertSame(200, $http['status'], $kind . '/' . $name);
                    self::assertSame($binary, $http['body'], 'Download must return verified plaintext binary.');
                    self::assertSame('image/png', Task4EndpointHttp::header($http, 'Content-Type'));
                    self::assertSame((string) strlen($binary), Task4EndpointHttp::header($http, 'Content-Length'));
                    self::assertSame('nosniff', Task4EndpointHttp::header($http, 'X-Content-Type-Options'));
                    self::assertSame("default-src 'none'; sandbox", Task4EndpointHttp::header($http, 'Content-Security-Policy'));
                    if ($kind === 'managed') { self::assertStringContainsString('private, no-store', Task4EndpointHttp::header($http, 'Cache-Control')); }
                    $rows[$kind . '/' . $name] = ['http' => 200, 'reason' => 'verified binary returned', 'sha256' => hash('sha256', $binary)];
                }
                foreach (['buyer_2', 'vendor_2'] as $name) {
                    [$cookie, $nonce] = CandidateHttp::auth($this->user($name), $nonceAction);
                    $http = Task4EndpointHttp::get('wp-admin/admin-post.php', $base + ['_wpnonce' => $nonce], $cookie);
                    $this->denied($http, 403, $kind === 'legacy' ? 'اجازه مشاهده این مدرک را ندارید.' : 'مدرک خصوصی در دسترس نیست یا مالک و هدف آن معتبر نیست.');
                    $rows[$kind . '/' . $name] = ['http' => 403, 'reason' => 'ownership denied'];
                }
                [$cookie, $nonce] = CandidateHttp::auth($buyer, $nonceAction);
                foreach (['missing_nonce' => [], 'invalid_nonce' => ['_wpnonce' => 'invalid']] as $name => $extra) {
                    $http = Task4EndpointHttp::get('wp-admin/admin-post.php', $base + $extra, $cookie);
                    $this->denied($http, 403, __('The link you followed has expired.'));
                    $rows[$kind . '/' . $name] = ['http' => 403, 'reason' => 'nonce rejected'];
                }
                // A valid nonce for a changed attachment proves the scope/ownership check, rather than only nonce binding.
                [$cookie, $changedNonce] = CandidateHttp::auth($buyer, $kind === 'legacy' ? 'zbm_core_evidence_2147483646' : 'zbm_core_managed_evidence_' . $request->id() . '_2147483646');
                $http = Task4EndpointHttp::get('wp-admin/admin-post.php', array_replace($base, ['attachment_id' => 2147483646, '_wpnonce' => $changedNonce]), $cookie);
                $this->denied($http, 403, $kind === 'legacy' ? 'اجازه مشاهده این مدرک را ندارید.' : 'مدرک خصوصی در دسترس نیست یا مالک و هدف آن معتبر نیست.');
                $rows[$kind . '/changed_attachment'] = ['http' => 403, 'reason' => 'changed attachment scope denied with valid changed-ID nonce'];
                self::assertFalse(has_action('admin_post_nopriv_' . $action));
                $http = Task4EndpointHttp::get('wp-admin/admin-post.php', $base);
                self::assertSame(400, $http['status'], 'Guest dispatcher rejects unregistered public handler.');
                self::assertNotSame($binary, $http['body']);
                $rows[$kind . '/guest'] = ['http' => 400, 'reason' => 'no public handler registered'];
            }
            [$other] = CandidateFixture::request(); CandidateFixture::saveCase(CandidateFixture::caseFor($other));
            [$cookie, $nonce] = CandidateHttp::auth($buyer, 'zbm_core_managed_evidence_' . $other->id() . '_' . $managedId);
            $http = Task4EndpointHttp::get('wp-admin/admin-post.php', ['action' => 'zbm_core_download_managed_evidence', 'request_id' => $other->id(), 'attachment_id' => $managedId, '_wpnonce' => $nonce], $cookie);
            $this->denied($http, 403, 'مدرک خصوصی در دسترس نیست یا مالک و هدف آن معتبر نیست.');
            $rows['managed/changed_request'] = ['http' => 403, 'reason' => 'request binding denied with valid changed-ID nonce'];
            [$cookie, $nonce] = CandidateHttp::auth($buyer, 'zbm_core_evidence_' . $managedId);
            $http = Task4EndpointHttp::get('wp-admin/admin-post.php', ['action' => 'zbm_core_download_evidence', 'attachment_id' => $managedId, '_wpnonce' => $nonce], $cookie);
            $this->denied($http, 403, 'مدرک مدیریت‌شده فقط از مسیر خصوصی درخواست قابل مشاهده است.');
            $rows['managed/legacy_downgrade'] = ['http' => 403, 'reason' => 'managed route required'];
            return $rows;
        });
    }

    public function test_manual_settings_and_online_stop_require_finance_nonce_and_commit_audit(): void {
        $this->scenario('manual-settings-and-online-control', function (): array {
            $admin = $this->user('admin');
            $rows = [];
            foreach (['zbm_manual_payment_settings' => 'zbm_core_real_manual_payment', 'zbm_online_payment_control' => 'zbm_core_online_payment_control'] as $action => $option) {
                $old = Task4EndpointHttp::option($option);
                $revision = is_array($old) ? ($old['revision'] ?? 0) : 0;
                $reason = 'Task4 synthetic finance authorization ' . wp_generate_uuid4();
                $fields = ['action' => $action, 'enabled' => '1', 'card_number' => '4242424242424242', 'holder_name' => 'Synthetic Sandbox Account', 'stopped' => '1', 'revision' => (string) $revision, 'reason' => $reason];
                foreach (['wrong_role', 'other_buyer', 'other_vendor', 'guest', 'missing_nonce', 'invalid_nonce'] as $name) {
                    $roleDenied = in_array($name, ['wrong_role', 'other_buyer', 'other_vendor'], true);
                    $actor = match ($name) { 'wrong_role' => $this->user('buyer_1'), 'other_buyer' => $this->user('buyer_2'), 'other_vendor' => $this->user('vendor_2'), default => $admin };
                    [$cookie, $nonce] = $name === 'guest' ? ['', ''] : CandidateHttp::auth($actor, $action);
                    $payload = $fields;
                    if ($name !== 'missing_nonce') { $payload['_zbm_nonce'] = $name === 'invalid_nonce' ? 'invalid' : $nonce; }
                    $count = $this->auditCount();
                    $http = CandidateHttp::post('wp-admin/admin-post.php', $payload, $cookie);
                    if ($name === 'guest') { self::assertFalse(has_action('admin_post_nopriv_' . $action)); self::assertSame(400, $http['status']); self::assertStringContainsString('wp-die-message', $http['body']); }
                    else { $this->denied($http, 403, $roleDenied ? 'دسترسی غیرمجاز است.' : __('The link you followed has expired.')); }
                    self::assertSame($old, Task4EndpointHttp::option($option)); self::assertSame($count, $this->auditCount());
                    $rows[$action . '/' . $name] = ['http' => $http['status'], 'reason' => $name === 'guest' ? 'WordPress dispatcher: no public handler' : ($roleDenied ? 'finance capability denied' : 'nonce rejected')];
                }
                [$cookie, $nonce] = CandidateHttp::auth($admin, $action);
                $http = CandidateHttp::post('wp-admin/admin-post.php', $fields + ['_zbm_nonce' => $nonce], $cookie);
                self::assertSame(303, $http['status']);
                $notice = $this->notice('zbm_manual_settings_notice_', $admin); self::assertSame('success', $notice['type']);
                $stored = Task4EndpointHttp::option($option); self::assertIsArray($stored);
                $event = $action === 'zbm_manual_payment_settings' ? 'manual_payment_settings_saved' : 'online_payment_control_changed';
                $audit = $this->audit($event, $admin); $metadata = json_decode($audit['metadata_json'], true);
                self::assertSame(hash('sha256', $reason), $metadata['reason_hash']);
                if ($action === 'zbm_manual_payment_settings') {
                    self::assertTrue($stored['enabled']); self::assertSame($admin, $stored['updated_by']);
                    self::assertStringNotContainsString('4242424242424242', $stored['account_encrypted']);
                    wp_cache_delete($option, 'options'); wp_cache_delete('alloptions', 'options'); wp_cache_delete('notoptions', 'options');
                    $settings = new ManualPaymentSettings(new ProtectedText(new DeliveryDataCrypto()), CandidateFixture::repositories()['audit']);
                    self::assertSame(['card_number' => '4242424242424242', 'holder_name' => 'Synthetic Sandbox Account'], $settings->publicAccount());
                } else {
                    self::assertTrue($stored['stopped']); self::assertSame($revision + 1, $stored['revision']); self::assertSame($admin, $stored['actor_id']);
                    self::assertSame($reason, $stored['reason']); self::assertSame($revision + 1, $metadata['revision']);
                }
                $rows[$action . '/admin_positive'] = ['http' => 303, 'reason' => 'durable setting and audit committed', 'audit_id' => (int) $audit['id']];
                // Restore isolated test option after positive assertions; keep its audit evidence.
                wp_cache_delete($option, 'options'); wp_cache_delete('alloptions', 'options'); wp_cache_delete('notoptions', 'options');
                $old === null ? delete_option($option) : update_option($option, $old, false);
            }
            return $rows;
        });
    }

    public function test_purchase_request_pause_positive_and_named_rejection_reasons(): void {
        $this->scenario('purchase-request-admin-control', function (): array {
            [$request, $repos] = CandidateFixture::request(['current_state' => 'supplier_review', 'supplier_deadline_gmt' => gmdate('Y-m-d H:i:s', time() + 1200)]);
            $id = $request->id(); $admin = $this->user('admin'); $action = 'zbm_admin_request_control';
            $fields = ['action' => $action, 'request_id' => (string) $id, 'version' => '0', 'operation' => 'pause', 'reason' => 'Synthetic Task4 timed request pause'];
            $rows = [];
            foreach (['wrong_role', 'other_buyer', 'other_vendor', 'guest', 'missing_nonce', 'invalid_nonce', 'stale_version', 'unknown_operation', 'missing_reason'] as $name) {
                $roleDenied = in_array($name, ['wrong_role', 'other_buyer', 'other_vendor'], true);
                $actor = match ($name) { 'wrong_role' => $this->user('buyer_1'), 'other_buyer' => $this->user('buyer_2'), 'other_vendor' => $this->user('vendor_2'), default => $admin };
                [$cookie, $nonce] = $name === 'guest' ? ['', ''] : CandidateHttp::auth($actor, $action . '_' . $id);
                $payload = $fields;
                if ($name !== 'missing_nonce') { $payload['_zbm_nonce'] = $name === 'invalid_nonce' ? 'invalid' : $nonce; }
                if ($name === 'stale_version') { $payload['version'] = '5'; }
                if ($name === 'unknown_operation') { $payload['operation'] = 'unknown'; }
                if ($name === 'missing_reason') { $payload['reason'] = ''; }
                $count = $this->auditCount();
                $http = CandidateHttp::post('wp-admin/admin-post.php', $payload, $cookie);
                if ($name === 'guest') { self::assertFalse(has_action('admin_post_nopriv_' . $action)); self::assertSame(400, $http['status']); self::assertStringContainsString('wp-die-message', $http['body']); }
                elseif ($roleDenied) { $this->denied($http, 500, 'دسترسی غیرمجاز است.'); }
                elseif (str_contains($name, 'nonce')) { $this->denied($http, 403, __('The link you followed has expired.')); }
                else {
                    self::assertSame(302, $http['status']); $notice = $this->notice('zbm_admin_request_notice_', $admin); self::assertSame('error', $notice['type']);
                    self::assertSame(match ($name) { 'stale_version' => 'وضعیت کنترل مدیریت تغییر کرده است؛ صفحه را تازه کنید.', 'unknown_operation' => 'عملیات مدیریت معتبر نیست.', default => 'دسترسی، درخواست یا دلیل مدیریت معتبر نیست.' }, $notice['message']);
                }
                self::assertFalse($repos['requests']->find($id)->isPaused()); self::assertSame(0, $repos['requests']->find($id)->version()); self::assertSame($count, $this->auditCount());
                $rows[$name] = ['http' => $http['status'], 'reason' => $name];
            }
            [$cookie, $nonce] = CandidateHttp::auth($admin, $action . '_' . $id);
            $http = CandidateHttp::post('wp-admin/admin-post.php', $fields + ['_zbm_nonce' => $nonce], $cookie);
            self::assertSame(302, $http['status']); self::assertSame('success', $this->notice('zbm_admin_request_notice_', $admin)['type']);
            $fresh = $repos['requests']->find($id); self::assertTrue($fresh->isPaused()); self::assertSame(1, $fresh->version()); self::assertNull($fresh->supplierDeadlineGmt()); self::assertGreaterThan(0, $fresh->pausedRemainingSeconds());
            $audit = $this->audit('request_paused', $admin, $id);
            self::assertSame($fields['reason'], json_decode($audit['metadata_json'], true)['reason']);
            $rows['admin_positive'] = ['http' => 302, 'reason' => 'pause/version/deadline persisted with audit', 'audit_id' => (int) $audit['id']];
            return $rows;
        });
    }

    public function test_hpos_metabox_actual_forms_submit_through_workflow_handler(): void {
        $this->scenario('hpos-metabox-and-workflow-forms', function (): array {
            global $wpdb;
            self::assertTrue(wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled(), 'HPOS must be active, not silently skipped.');
            $order = wc_create_order(['customer_id' => $this->user('buyer_1')]);
            $id = $order->get_id(); $admin = $this->user('admin');
            $store = new WorkflowStore($wpdb, CandidateFixture::repositories()['audit']);
            [$cookie] = CandidateHttp::auth($admin, 'zbm_core_order_' . $id);
            $render = Task4EndpointHttp::get('task4-endpoint-harness.php', ['view' => 'metabox', 'order_id' => $id], $cookie);
            self::assertSame(200, $render['status'], $render['body']); self::assertMatchesRegularExpression('/data-task4-screen="[a-zA-Z0-9_-]*wc-orders"/', $render['body']);
            $forms = Task4EndpointHttp::forms($render['body']);
            self::assertArrayHasKey('zbm_core_transition:deposit_pending', $forms); self::assertArrayHasKey('zbm_core_control:', $forms);
            $rows = [];
            foreach (['zbm_core_transition:deposit_pending', 'zbm_core_control:'] as $key) {
                $valid = $forms[$key] + ['reason' => 'Synthetic Task4 submitted rendered HPOS form'];
                foreach (['wrong_buyer', 'wrong_vendor', 'other_buyer', 'other_vendor', 'guest', 'missing_nonce', 'invalid_nonce', 'changed_order'] as $name) {
                    $roleDenied = in_array($name, ['wrong_buyer', 'wrong_vendor', 'other_buyer', 'other_vendor'], true);
                    $actor = match ($name) { 'wrong_buyer' => $this->user('buyer_1'), 'wrong_vendor' => $this->user('vendor_1'), 'other_buyer' => $this->user('buyer_2'), 'other_vendor' => $this->user('vendor_2'), default => $admin };
                    [$actorCookie, $nonce] = $name === 'guest' ? ['', ''] : CandidateHttp::auth($actor, 'zbm_core_order_' . $id);
                    $payload = array_replace($valid, ['_wpnonce' => $nonce]);
                    if ($name === 'missing_nonce') { unset($payload['_wpnonce']); }
                    if ($name === 'invalid_nonce') { $payload['_wpnonce'] = 'invalid'; }
                    if ($name === 'changed_order') { $payload['order_id'] = '2147483646'; }
                    $before = $store->snapshot($id); $count = $this->auditCount(); $money = CandidateFixture::financialSnapshot();
                    $http = CandidateHttp::post('wp-admin/admin-post.php', $payload, $actorCookie);
                    if ($name === 'guest') { self::assertFalse(has_action('admin_post_nopriv_' . $valid['action'])); self::assertSame(400, $http['status']); self::assertStringContainsString('wp-die-message', $http['body']); }
                    elseif ($roleDenied) { $this->result($http, $name === 'wrong_buyer' && $valid['action'] === 'zbm_core_transition' ? 'zbm_transition_forbidden' : 'forbidden'); }
                    else { $this->denied($http, 403, __('The link you followed has expired.')); }
                    self::assertSame($before, $store->snapshot($id)); self::assertSame($count, $this->auditCount()); self::assertSame($money, CandidateFixture::financialSnapshot());
                    $rows[$key . '/' . $name] = ['http' => $http['status'], 'reason' => $name === 'guest' ? 'WordPress dispatcher: no public handler' : ($roleDenied ? 'forbidden' : 'nonce rejected')];
                }
            }
            // Refresh auth/form together: each authentication creates a new nonce-bound session.
            [$cookie] = CandidateHttp::auth($admin, 'zbm_core_order_' . $id);
            $render = Task4EndpointHttp::get('task4-endpoint-harness.php', ['view' => 'metabox', 'order_id' => $id], $cookie);
            $form = Task4EndpointHttp::forms($render['body'])['zbm_core_transition:deposit_pending'];
            $http = CandidateHttp::post('wp-admin/admin-post.php', $form + ['reason' => 'Synthetic Task4 begin deposit workflow'], $cookie);
            $this->result($http, 'success'); self::assertSame('deposit_pending', $store->snapshot($id)['state']);
            $transitionEvents = CandidateFixture::repositories()['audit']->for_order($id, 20);
            $matching = array_values(array_filter($transitionEvents, static fn($row) => $row->event_key === 'workflow_transitioned' && (int) $row->actor_user_id === $admin && $row->to_state === 'deposit_pending' && $row->correlation_id === $form['correlation_id']));
            self::assertCount(1, $matching);
            self::assertSame('Synthetic Task4 begin deposit workflow', json_decode($matching[0]->metadata_json, true)['reason']);
            $rows['transition_positive'] = ['http' => 302, 'reason' => 'rendered transition persisted deposit_pending with exact correlation audit', 'audit_id' => (int) $matching[0]->id];
            $render = Task4EndpointHttp::get('task4-endpoint-harness.php', ['view' => 'metabox', 'order_id' => $id], $cookie);
            $form = Task4EndpointHttp::forms($render['body'])['zbm_core_control:']; self::assertSame('1', $form['paused']);
            $http = CandidateHttp::post('wp-admin/admin-post.php', $form + ['reason' => 'Synthetic Task4 pause legacy workflow'], $cookie);
            $this->result($http, 'success'); self::assertTrue($store->snapshot($id)['is_paused']);
            $events = CandidateFixture::repositories()['audit']->for_order($id, 20);
            self::assertNotEmpty(array_filter($events, static fn($row) => (int) $row->actor_user_id === $admin && str_contains($row->event_key, 'paused')));
            $rows['control_positive'] = ['http' => 302, 'reason' => 'rendered paused=1 control durably paused with audit'];
            return $rows;
        });
    }

    public function test_approved_vendor_reports_allow_own_report_and_ignore_target_vendor_parameter(): void {
        $this->scenario('approved-vendor-reports-isolation', function (): array {
            global $wpdb;
            $previousCurrency = get_option('woocommerce_currency');
            update_option('woocommerce_currency', 'IRT');
            $schema = new Schema($wpdb); $today = new DateTimeImmutable('today', wp_timezone());
            $from = \Zarbalman\Core\Support\JalaliDate::format($today->modify('-1 day'), '/', false);
            $to = \Zarbalman\Core\Support\JalaliDate::format($today, '/', false);
            $amounts = ['vendor_1' => 731123, 'vendor_2' => 419987];
            foreach ($amounts as $name => $amount) {
                $vendor = $this->user($name); update_user_meta($vendor, 'dokan_enable_selling', 'yes');
                self::assertTrue((new DokanVendorEligibility())->isApprovedVendor($vendor), 'Real Dokan vendor approval is required.');
                [$request] = CandidateFixture::request(['vendor_id' => $vendor]);
                $now = current_time('mysql', true);
                self::assertSame(1, $wpdb->insert($schema->payments_table(), ['request_id' => $request->id(), 'order_id' => 0, 'provider' => 'manual', 'phase' => 'deposit', 'expected_amount_toman' => $amount, 'paid_amount_toman' => $amount, 'status' => 'captured', 'idempotency_hash' => hash('sha256', wp_generate_uuid4()), 'paid_at_gmt' => $now, 'created_at_gmt' => $now, 'updated_at_gmt' => $now]));
            }
            $rows = [];
            // Expected aggregate includes earlier test fixtures; derive it from the persisted scoped SQL facts.
            foreach ($amounts as $name => $amount) {
                $vendor = $this->user($name); [$cookie] = CandidateHttp::auth($vendor, 'unused-read-only');
                $expected = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(p.paid_amount_toman),0) FROM %i p JOIN %i r ON r.id=p.request_id WHERE r.vendor_id=%d AND r.execution_mode='live' AND p.phase='deposit' AND p.status IN ('captured','late_captured','late_accepted') AND p.paid_at_gmt>=%s AND p.paid_at_gmt<%s", $schema->payments_table(), $schema->purchase_requests_table(), $vendor, $today->modify('-1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $today->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')));
                $before = CandidateFixture::financialSnapshot();
                $http = Task4EndpointHttp::get('task4-endpoint-harness.php', ['view' => 'reports', 'from' => $from, 'to' => $to, 'vendor_id' => $this->user('vendor_1')], $cookie);
                self::assertSame(200, $http['status']); self::assertStringNotContainsString('داده‌های گزارش در حال حاضر خوانده نشد.', $http['body']);
                self::assertSame(1, preg_match('/data-chart="([^"]+)"/', $http['body'], $match), 'Real report chart JSON must render.');
                $chart = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                self::assertIsArray($chart); self::assertSame($expected, array_sum(array_column($chart['rows'], 'deposit'))); self::assertGreaterThanOrEqual($amount, $expected);
                self::assertSame($before, CandidateFixture::financialSnapshot());
                $rows[$name] = ['http' => 200, 'reason' => 'own scoped report rendered; target vendor query ignored', 'deposit_toman' => $expected];
            }
            foreach (['guest' => '', 'buyer' => $this->user('buyer_1')] as $name => $actor) {
                $cookie = $actor === '' ? '' : CandidateHttp::auth($actor, 'unused-read-only')[0];
                $http = Task4EndpointHttp::get('task4-endpoint-harness.php', ['view' => 'reports', 'from' => $from, 'to' => $to], $cookie);
                $this->denied($http, 403, 'دسترسی به این گزارش مجاز نیست.');
                $rows[$name] = ['http' => 403, 'reason' => 'approved vendor required'];
            }
            update_option('woocommerce_currency', $previousCurrency);
            return $rows;
        });
    }

    public function test_guest_login_return_cookie_rejects_external_hosts_and_consumes_safe_return(): void {
        $this->scenario('guest-login-return-open-redirect', function (): array {
            $rows = [];
            foreach (['external' => 'https://evil.example.invalid/takeover', 'scheme_relative' => '//evil.example.invalid/takeover', 'host_lookalike' => 'https://' . parse_url(home_url('/'), PHP_URL_HOST) . '.evil.example.invalid/takeover', 'javascript' => 'javascript:alert(1)', 'userinfo' => 'https://' . parse_url(home_url('/'), PHP_URL_HOST) . '@evil.example.invalid/takeover', 'safe' => home_url('/synthetic-product/')] as $name => $target) {
                $http = Task4EndpointHttp::get('wp-admin/admin-post.php', ['action' => 'zbm_prepare_request_auth', 'return_to' => $target]);
                self::assertSame(302, $http['status']);
                self::assertSame(parse_url(home_url('/'), PHP_URL_HOST), parse_url(Task4EndpointHttp::header($http, 'Location'), PHP_URL_HOST));
                $set = Task4EndpointHttp::header($http, 'Set-Cookie');
                self::assertSame(1, preg_match('/zbm_request_return=([^;]+)/', $set, $match));
                self::assertStringContainsString('httponly', strtolower($set));
                $safe = $name === 'safe' ? $target : home_url('/');
                // PHP setcookie percent-encodes a value already encoded by the controller.
                self::assertSame($safe, rawurldecode(rawurldecode($match[1])));
                $consume = Task4EndpointHttp::get('task4-endpoint-harness.php', ['view' => 'login-return'], 'zbm_request_return=' . $match[1]);
                self::assertSame(200, $consume['status']);
                self::assertSame(add_query_arg('zbm_resume_request', '1', $safe), json_decode($consume['body'], true)['return_to']);
                self::assertStringContainsString('zbm_request_return=', $consume['headers']);
                $rows[$name] = ['http' => 302, 'reason' => 'safe local return encoded and login filter consumes cookie'];
            }
            $http = Task4EndpointHttp::get('task4-endpoint-harness.php', ['view' => 'login-return'], 'zbm_request_return=' . rawurlencode(rawurlencode('https://evil.example.invalid/takeover')));
            self::assertSame(home_url('/safe-login-fallback/'), json_decode($http['body'], true)['return_to']);
            $rows['forged_cookie'] = ['http' => 200, 'reason' => 'login filter retains local fallback'];
            return $rows;
        });
    }

    public function test_balance_provider_url_constructor_is_fixed_host_and_rejects_url_authorities(): void {
        $this->scenario('balance-url-constructor', function (): array {
            $authority = 'SYNTHETIC000000000000000000000001';
            self::assertSame('https://payment.zarinpal.com/pg/StartPay/' . $authority, ZarinpalBalanceAdapter::url($authority));
            foreach (['https://evil.example.invalid/path', '//evil.example.invalid/path', $authority . '?next=https://evil.example.invalid', $authority . "\r\nLocation: https://evil.example.invalid", $authority . '/extra'] as $bad) {
                try { ZarinpalBalanceAdapter::url($bad); self::fail('Invalid Authority must be rejected.'); }
                catch (RuntimeException $error) { self::assertSame('Invalid payment URL authority.', $error->getMessage()); }
            }
            return ['status' => 'constructor-only', 'provider_issuance_http' => 'untested: real commercial gateway unavailable; no provider impersonation or external payment attempted'];
        });
    }
}
