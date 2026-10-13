<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/CandidateFixture.php';
require_once __DIR__ . '/../support/Task4ManagedFixture.php';
require_once __DIR__ . '/../support/Task4EndpointHttp.php';

use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Domain\PurchaseRequest\RequestNumber;
use Zarbalman\Core\Domain\Workflow\{AuthorizationPolicy, WorkflowDefinition};
use Zarbalman\Core\Infrastructure\{Schema, WorkflowStore};

/** Native form submissions against independent historical records. */
final class FormBranchControlsTest extends TestCase {
    private const EVIDENCE = __DIR__ . '/../../evidence/http-form-branch-controls.json';
    private array $oldOptions = [];

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        file_put_contents(self::EVIDENCE, wp_json_encode(['_meta' => [
            'schema_version' => 1, 'run_id' => getenv('GITHUB_RUN_ID') ?: 'local',
            'run_attempt' => getenv('GITHUB_RUN_ATTEMPT') ?: 'local', 'created_at_gmt' => gmdate('c'),
        ]], JSON_THROW_ON_ERROR));
        $admin = get_user_by('login', 'zbm_test_admin'); self::assertInstanceOf(WP_User::class, $admin);
        [$cookie] = CandidateHttp::auth((int) $admin->ID, 'unused-native-admin-start');
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $http = Task4EndpointHttp::get('wp-admin/index.php', [], $cookie);
            if ($http['status'] === 200) { break; }
            self::assertSame(302, $http['status']); $location = Task4EndpointHttp::header($http, 'Location');
            self::assertSame(parse_url(home_url('/'), PHP_URL_HOST), parse_url($location, PHP_URL_HOST));
            self::assertStringStartsWith('/wp-admin/', (string) parse_url($location, PHP_URL_PATH));
        }
        self::assertSame(200, Task4EndpointHttp::get('wp-admin/index.php', [], $cookie)['status']);
    }
    protected function setUp(): void {
        parent::setUp();
        foreach (['woocommerce_weight_unit' => 'kg', 'woocommerce_currency' => 'IRT'] as $key => $value) {
            $this->oldOptions[$key] = get_option($key, null); update_option($key, $value);
        }
        Task4ManagedFixture::users();
    }
    protected function tearDown(): void {
        foreach ($this->oldOptions as $key => $value) { $value === null ? delete_option($key) : update_option($key, $value); }
        wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]); parent::tearDown();
    }
    private function user(string $actor): int {
        $user = get_user_by('login', 'zbm_test_' . $actor); self::assertInstanceOf(WP_User::class, $user);
        return (int) $user->ID;
    }
    private function write(string $case, array $record): void {
        $data = json_decode((string) file_get_contents(self::EVIDENCE), true, 64, JSON_THROW_ON_ERROR);
        $data[$case] = $record; $json = wp_json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        self::assertSame(strlen($json), file_put_contents(self::EVIDENCE, $json, LOCK_EX));
    }
    private function runCase(string $case, string $test, callable $run): void {
        $this->write($case, ['state' => 'running', 'assertion_status' => 'pending', 'test' => $test, 'dataset' => $case]);
        try { $proof = $run(); $this->write($case, ['state' => 'passed', 'assertion_status' => 'passed',
            'actual_http' => true, 'test' => $test, 'dataset' => $case, 'case' => $case] + $proof); }
        catch (Throwable $error) { $this->write($case, ['state' => 'failed', 'assertion_status' => 'failed',
            'test' => $test, 'dataset' => $case, 'exception' => get_class($error)]); throw $error; }
    }
    private function form(string $html, string $name, string $value, int $id): array {
        $dom = new DOMDocument(); $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        foreach ($dom->getElementsByTagName('form') as $node) {
            $fields = [];
            foreach ($node->getElementsByTagName('input') as $input) {
                if ($input->getAttribute('name') !== '') { $fields[$input->getAttribute('name')] = $input->getAttribute('value'); }
            }
            foreach ($node->getElementsByTagName('textarea') as $input) {
                if ($input->getAttribute('name') !== '') { $fields[$input->getAttribute('name')] = $input->textContent; }
            }
            if (($fields[$name] ?? '') !== $value || ($fields[$name === 'operation' ? 'request_id' : 'order_id'] ?? '') !== (string) $id) { continue; }
            self::assertSame('post', strtolower($node->getAttribute('method')));
            self::assertSame('/wp-admin/admin-post.php', parse_url($node->getAttribute('action'), PHP_URL_PATH));
            return $fields;
        }
        self::fail('Required native form is not rendered.');
    }
    private function rows(string $table, string $key = 'id'): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY %i', $table, $key), ARRAY_A);
        self::assertSame('', $wpdb->last_error); self::assertIsArray($rows);
        return array_column($rows, null, $key);
    }
    private function outside(string $table, string $column, int $id): string {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE %i<>%d OR %i IS NULL ORDER BY 1', $table, $column, $id, $column), ARRAY_A);
        self::assertSame('', $wpdb->last_error); self::assertIsArray($rows);
        return hash('sha256', wp_json_encode($rows, JSON_THROW_ON_ERROR));
    }
    private function exactRow(array $before, array $after, array $allowed): void {
        self::assertSame(array_keys($before), array_keys($after));
        foreach ($before as $column => $value) {
            if (!in_array($column, $allowed, true)) { self::assertSame($value, $after[$column], 'Unexpected changed column: ' . $column); }
        }
    }
    private function stable(array $before, array $after, array $allowed): void {
        self::assertSame(array_keys($before), array_keys($after));
        foreach ($before as $table => $hash) { if (!in_array($table, $allowed, true)) { self::assertSame($hash, $after[$table], 'Unexpected persisted table change: ' . $table); } }
    }
    private function appended(array $before, array $after, int $count): array {
        self::assertCount(count($before) + $count, $after);
        foreach ($before as $key => $row) { self::assertArrayHasKey($key, $after); self::assertSame($row, $after[$key]); }
        $new = array_diff_key($after, $before); self::assertCount($count, $new);
        return array_values($new);
    }
    private function result(array $http, string $code): void {
        self::assertSame(302, $http['status']); $location = Task4EndpointHttp::header($http, 'Location');
        self::assertSame($location, wp_validate_redirect($location, ''));
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query); self::assertSame($code, $query['zbm_result'] ?? null);
    }
    private function notice(array $http, string $type, ?string $message = null): array {
        self::assertSame(302, $http['status']); self::assertSame(admin_url('admin.php?page=zbm-core-requests'), Task4EndpointHttp::header($http, 'Location'));
        $notice = Task4EndpointHttp::option('_transient_zbm_admin_request_notice_' . $this->user('admin'));
        self::assertIsArray($notice); self::assertSame($type, $notice['type']); self::assertNotSame('', $notice['message']);
        if ($message !== null) { self::assertSame($message, $notice['message']); }
        return $notice;
    }
    private function denied(array $http, int $status, string $message): void {
        self::assertSame($status, $http['status']);
        self::assertStringContainsString($message, html_entity_decode(strip_tags($http['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    private function audited(array $before, Schema $schema, int $id, string $event, string $actor, int $actorId, string $from, string $to, string $reason, ?string $correlation): array {
        $events = $this->appended($before, $this->rows($schema->audit_table()), 1); $row = $events[0];
        self::assertSame($event, $row['event_key']); self::assertSame($actor, $row['actor_type']);
        self::assertSame($actorId, (int) $row['actor_user_id']); self::assertSame($from, $row['from_state']); self::assertSame($to, $row['to_state']);
        self::assertSame($id, (int) $row[$actor === 'admin' ? 'resource_id' : 'order_id']);
        self::assertTrue(wp_is_uuid($row['event_uuid'])); self::assertTrue(wp_is_uuid($row['correlation_id']));
        if ($correlation !== null) { self::assertSame($correlation, $row['correlation_id']); }
        $meta = json_decode($row['metadata_json'], true, 32, JSON_THROW_ON_ERROR); self::assertSame($reason, $meta['reason'] ?? '');
        return ['event_key' => $event, 'event_uuid' => $row['event_uuid'], 'correlation_id' => $row['correlation_id'],
            'count' => 1, 'actor_type' => $actor, 'actor_id' => $actorId, 'resource_id' => $id, 'reason_matches' => true];
    }
    private function command(array $before, Schema $schema, array $audit, string $namespace): array {
        $new = $this->appended($before, $this->rows($schema->idempotency_table(), 'key_hash'), 1); $row = $new[0];
        self::assertSame($namespace, $row['namespace']); self::assertSame('completed', $row['status']);
        self::assertSame($audit['event_uuid'], $row['result_event_uuid']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $row['key_hash']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $row['payload_hash']);
        if ($namespace === 'purchase_request_transition') { self::assertSame($audit['resource_id'], (int) $row['result_resource_id']); }
        return ['appended_rows' => 1, 'existing_rows_unchanged' => true, 'namespace' => $namespace,
            'status' => 'completed', 'result_event_uuid' => $row['result_event_uuid']];
    }
    public static function adminBranches(): array {
        return ['resume_supplier' => ['resume_supplier', 'resume', 'supplier_review', 'supplier_review', 'request_resumed'],
            'reopen_supplier' => ['reopen_supplier', 'reopen_supplier', 'supplier_response_expired', 'supplier_review', 'supplier_deadline_reopened'],
            'cancel_preorder' => ['cancel_preorder', 'cancel_review', 'admin_review', 'cancelled', 'admin_review_cancelled']];
    }
    /** @dataProvider adminBranches */
    public function test_rendered_admin_branch(string $case, string $operation, string $from, string $to, string $event): void {
        $this->runCase($case, __METHOD__, function () use ($case, $operation, $from, $to, $event): array {
            global $wpdb; $schema = new Schema($wpdb); $proof = []; $negatives = [];
            $scenarios = ['authorized_positive', 'wrong_role', 'other_buyer', 'other_vendor', 'guest', 'no_nonce', 'invalid_nonce', 'missing_reason'];
            $scenarios[] = $operation === 'reopen_supplier' ? 'invalid_hours' : 'stale_version';
            foreach ($scenarios as $scenario) {
                $fields = ['current_state' => $from, 'request_number' => RequestNumber::generate(), 'wc_order_id' => null,
                    'payment_ready' => 0, 'stock_reserved' => 0, 'stock_released' => 0];
                if ($operation === 'resume') { $fields += ['is_paused' => 1, 'paused_at_gmt' => gmdate('Y-m-d H:i:s', time() - 60),
                    'paused_remaining_seconds' => 1200, 'pause_reason' => 'Synthetic historical paused interval']; }
                [$request] = CandidateFixture::request($fields); $id = $request->id();
                [$cookie] = CandidateHttp::auth($this->user('admin'), 'zbm_admin_request_control_' . $id);
                $render = Task4EndpointHttp::get('wp-admin/admin.php', ['page' => 'zbm-core-requests', 'request_number' => $request->requestNumber()], $cookie);
                self::assertSame(200, $render['status']); $payload = $this->form($render['body'], 'operation', $operation, $id);
                self::assertSame('0', $payload['version']); self::assertSame('zbm_admin_request_control', $payload['action']);
                $actor = match ($scenario) { 'wrong_role' => 'buyer_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', 'guest' => 'guest', default => 'admin' };
                if ($actor === 'guest') { $cookie = ''; $payload['_zbm_nonce'] = ''; }
                elseif ($actor !== 'admin') { [$cookie, $payload['_zbm_nonce']] = CandidateHttp::auth($this->user($actor), 'zbm_admin_request_control_' . $id); }
                if ($actor !== 'guest') { self::assertNotFalse(wp_verify_nonce($payload['_zbm_nonce'], 'zbm_admin_request_control_' . $id)); }
                $payload['reason'] = 'Synthetic native administrative form';
                if ($operation === 'reopen_supplier') { self::assertSame('1', $payload['hours']); }
                if ($scenario === 'no_nonce') { unset($payload['_zbm_nonce']); }
                if ($scenario === 'invalid_nonce') { $payload['_zbm_nonce'] = 'invalid'; }
                if ($scenario === 'missing_reason') { $payload['reason'] = ''; }
                if ($scenario === 'stale_version') { $payload['version'] = '9'; }
                if ($scenario === 'invalid_hours') { $payload['hours'] = '0'; }
                $before = Task4ManagedFixture::snapshot(); $rows = $this->rows($schema->purchase_requests_table());
                $outside = $this->outside($schema->purchase_requests_table(), 'id', $id);
                $audits = $this->rows($schema->audit_table()); $commands = $this->rows($schema->idempotency_table(), 'key_hash');
                $outbox = $this->rows($schema->notification_outbox_table());
                $start = time(); $http = CandidateHttp::post('wp-admin/admin-post.php', $payload, $cookie); $end = time();
                $after = Task4ManagedFixture::snapshot();
                if ($scenario !== 'authorized_positive') {
                    $control = ['actual_http' => true, 'http' => $http['status'], 'unchanged' => true];
                    if ($actor === 'guest') { self::assertSame(400, $http['status']); self::assertStringContainsString('wp-die-message', $http['body']); $control['generic_dispatcher_only'] = true; }
                    elseif ($actor !== 'admin') { $this->denied($http, 500, 'دسترسی غیرمجاز است.'); $control['message'] = 'دسترسی غیرمجاز است.'; }
                    elseif (in_array($scenario, ['no_nonce', 'invalid_nonce'], true)) { $message = __('The link you followed has expired.'); $this->denied($http, 403, $message); $control['message'] = $message; }
                    else {
                        $message = $operation === 'reopen_supplier' ? 'دلیل و مدت معتبر برای بازگشایی لازم است.'
                            : ($operation === 'cancel_review' ? 'درخواست، نسخه، دسترسی یا دلیل لغو معتبر نیست.'
                            : ($scenario === 'missing_reason' ? 'دسترسی، درخواست یا دلیل مدیریت معتبر نیست.' : 'وضعیت کنترل مدیریت تغییر کرده است؛ صفحه را تازه کنید.'));
                        $notice = $this->notice($http, 'error', $message); $control['notice'] = $notice; $control['message'] = $message;
                    }
                    self::assertSame($before, $after); self::assertSame($rows, $this->rows($schema->purchase_requests_table()));
                    $negatives[$scenario] = $control; continue;
                }
                $this->notice($http, 'success', 'عملیات مدیریت همراه با دلیل و سابقه حسابرسی ثبت شد.');
                $newRows = $this->rows($schema->purchase_requests_table()); self::assertSame(array_keys($rows), array_keys($newRows));
                foreach ($rows as $rowId => $row) { if ((int) $rowId !== $id) { self::assertSame($row, $newRows[$rowId]); } }
                $new = $newRows[$id]; self::assertSame($to, $new['current_state']); self::assertSame(1, (int) $new['version']);
                self::assertSame($payload['reason'], $new['pause_reason']); self::assertSame(0, (int) $new['is_paused']);
                self::assertNull($new['paused_at_gmt']); self::assertNull($new['paused_remaining_seconds']);
                $changedColumns = ['current_state', 'version', 'updated_at_gmt', 'is_paused', 'pause_reason',
                    'paused_at_gmt', 'paused_remaining_seconds'];
                if ($operation !== 'cancel_review') { $changedColumns[] = 'supplier_deadline_gmt'; }
                $this->exactRow($rows[$id], $new, $changedColumns);
                self::assertSame($outside, $this->outside($schema->purchase_requests_table(), 'id', $id));
                $specific = [];
                if ($operation !== 'cancel_review') {
                    $seconds = $operation === 'resume' ? 1200 : 3600;
                    $deadline = strtotime($new['supplier_deadline_gmt'] . ' UTC');
                    self::assertGreaterThanOrEqual($start + $seconds, $deadline); self::assertLessThanOrEqual($end + $seconds, $deadline);
                    $specific = $operation === 'resume'
                        ? ['paused_before' => true, 'paused_after' => false, 'remaining_before' => 1200, 'remaining_after' => null, 'deadline_in_range' => true]
                        : ['deadline_seconds' => 3600, 'deadline_in_range' => true];
                } else {
                    self::assertNull($new['wc_order_id']); self::assertSame(0, (int) $new['payment_ready']);
                    self::assertSame('0', $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE request_id=%d', $schema->payments_table(), $id)));
                    self::assertSame('0', $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE request_id=%d', $schema->ledger_table(), $id)));
                    $specific = ['wc_order_id_before' => null, 'payment_ready_before' => false, 'payment_count' => 0, 'ledger_count' => 0];
                }
                $audit = $this->audited($audits, $schema, $id, $event, 'admin', $this->user('admin'), $from, $to, $payload['reason'], null);
                $command = $this->command($commands, $schema, $audit, 'purchase_request_transition');
                $notifications = $this->appended($outbox, $this->rows($schema->notification_outbox_table()), $operation === 'reopen_supplier' ? 3 : 2);
                $templates = [];
                foreach ($notifications as $notification) {
                    self::assertSame($id, (int) $notification['request_id']);
                    self::assertSame('pending', $notification['status']); $templates[] = $notification['template_key'];
                    self::assertSame($this->user($notification['recipient_role'] === 'buyer' ? 'buyer_1' : 'vendor_1'), (int) $notification['recipient_user_id']);
                    $variables = json_decode($notification['payload_json'], true, 32, JSON_THROW_ON_ERROR);
                    if ($notification['template_key'] === 'supplier_response_reminder_vendor') {
                        self::assertSame('reopen_supplier', $operation); self::assertSame('vendor', $notification['recipient_role']);
                        self::assertTrue(wp_is_uuid($notification['event_uuid'])); self::assertNotSame($audit['event_uuid'], $notification['event_uuid']);
                        self::assertSame(['deadline' => $new['supplier_deadline_gmt']], $variables);
                        self::assertSame(hash('sha256', 'supplier_response_reminder_vendor|' . $id . '|' . $new['supplier_deadline_gmt']), $notification['dedupe_hash']);
                        self::assertSame(gmdate('Y-m-d H:i:s', strtotime($new['supplier_deadline_gmt'] . ' UTC') - 600), $notification['next_attempt_gmt']);
                    } else {
                        self::assertSame($audit['event_uuid'], $notification['event_uuid']);
                        if ($operation === 'reopen_supplier') { self::assertSame(['deadline' => $new['supplier_deadline_gmt']], $variables); }
                    }
                }
                $expectedTemplates = match ($operation) {
                    'resume' => ['request_resumed', 'request_resumed_vendor'],
                    'reopen_supplier' => ['supplier_deadline_reopened', 'supplier_deadline_reopened_vendor', 'supplier_response_reminder_vendor'],
                    default => ['admin_review_cancelled_buyer', 'admin_review_cancelled_vendor'],
                };
                foreach ($notifications as $notification) {
                    self::assertSame(str_ends_with($notification['template_key'], '_vendor') ? 'vendor' : 'buyer', $notification['recipient_role']);
                }
                sort($templates); sort($expectedTemplates); self::assertSame($expectedTemplates, $templates);
                $this->stable($before, $after, [$schema->purchase_requests_table(), $schema->audit_table(), $schema->idempotency_table(), $schema->notification_outbox_table()]);
                $replayBefore = Task4ManagedFixture::snapshot(); $replay = CandidateHttp::post('wp-admin/admin-post.php', $payload, $cookie);
                $replayMessage = match ($operation) { 'resume' => 'وضعیت کنترل مدیریت تغییر کرده است؛ صفحه را تازه کنید.',
                    'reopen_supplier' => 'این مهلت فروشنده قابل بازگشایی نیست.', default => 'درخواست، نسخه، دسترسی یا دلیل لغو معتبر نیست.' };
                $this->notice($replay, 'error', $replayMessage); self::assertSame($replayBefore, Task4ManagedFixture::snapshot());
                $proof = ['catalog_ids' => ['http-013'], 'branch' => $operation, 'http' => 302, 'actor_type' => 'admin',
                    'actor_id' => $this->user('admin'), 'nonce_valid' => true, 'resource_id' => $id, 'from_state' => $from, 'to_state' => $to,
                    'before_version' => 0, 'after_version' => 1, 'row_changes_verified' => true, 'unrelated_rows_unchanged' => true,
                    'financial_and_orders_unchanged' => true, 'before_snapshot' => $before, 'after_snapshot' => $after,
                    'audit' => $audit, 'idempotency' => $command, 'specific' => $specific,
                    'replay_preserved' => true, 'replay_http' => 302, 'replay_result' => 'error'];
            }
            return $proof + ['negative_controls' => $negatives];
        });
    }

    public static function frontendBranches(): array {
        return [
            'vendor_prepare' => ['vendor_prepare', 'vendor_simple_transition', 'vendor', 'supplier_review', 'preparing'],
            'vendor_deliver' => ['vendor_deliver', 'vendor_simple_transition', 'vendor', 'in_transit', 'delivered'],
            'vendor_confirm' => ['vendor_confirm', 'vendor_simple_transition', 'vendor', 'buyer_weight_recorded', 'seller_weight_confirmed'],
            'vendor_cancel_review' => ['vendor_cancel_review', 'vendor_simple_transition', 'vendor', 'supplier_review', 'cancellation_requested'],
            'vendor_cancel_preparing' => ['vendor_cancel_preparing', 'vendor_simple_transition', 'vendor', 'preparing', 'cancellation_requested'],
            'vendor_weight' => ['vendor_weight', 'weight_upload_forms', 'vendor', 'preparing', 'in_transit'],
            'buyer_weight' => ['buyer_weight', 'weight_upload_forms', 'buyer', 'delivered', 'buyer_weight_recorded'],
            'buyer_dispute' => ['buyer_dispute', 'evidenced_dispute_forms', 'buyer', 'delivered', 'dispute_open'],
            'vendor_dispute' => ['vendor_dispute', 'evidenced_dispute_forms', 'vendor', 'buyer_weight_recorded', 'weight_disputed'],
        ];
    }
    /** @dataProvider frontendBranches */
    public function test_rendered_frontend_branch(string $case, string $branch, string $role, string $from, string $to): void {
        $this->runCase($case, __METHOD__, function () use ($case, $branch, $role, $from, $to): array {
            global $wpdb; $schema = new Schema($wpdb); $proof = []; $negatives = [];
            $isWeight = $branch === 'weight_upload_forms'; $isEvidence = $branch === 'evidenced_dispute_forms';
            $reasonNeeded = $isEvidence || $to === 'cancellation_requested';
            $scenarios = ['authorized_positive', 'wrong_role', 'other_buyer', 'other_vendor', 'guest', 'no_nonce', 'invalid_nonce'];
            if ($isWeight) { $scenarios[] = 'invalid_weight'; }
            if ($isEvidence) { $scenarios[] = 'missing_evidence'; }
            if ($reasonNeeded) { $scenarios[] = 'missing_reason'; }
            foreach ($scenarios as $scenario) {
                $buyer = $this->user('buyer_1'); $vendor = $this->user('vendor_1');
                $product = new WC_Product_Simple(); $product->set_name('Synthetic native form item'); $product->set_regular_price('1000');
                $product->set_weight('1.0'); $product->save(); self::assertGreaterThan(0, wp_update_post(['ID' => $product->get_id(), 'post_author' => $vendor]));
                $order = wc_create_order(['customer_id' => $buyer]); self::assertInstanceOf(WC_Order::class, $order);
                $itemId = $order->add_product($product, 1); self::assertGreaterThan(0, $itemId);
                $order->update_meta_data('_dokan_vendor_id', $vendor); $order->calculate_totals(); $order->save(); $id = $order->get_id();
                self::assertSame('buyer', (new AuthorizationPolicy())->actor_type_for($order, $buyer));
                self::assertSame('vendor', (new AuthorizationPolicy())->actor_type_for($order, $vendor));
                $now = current_time('mysql', true); $deadline = gmdate('Y-m-d H:i:s', time() + 1200);
                self::assertSame(1, $wpdb->insert($schema->workflows_table(), ['order_id' => $id, 'current_state' => $from,
                    'version' => 0, 'is_paused' => 0, 'buyer_deadline_gmt' => $from === 'delivered' ? $deadline : null,
                    'vendor_deadline_gmt' => $from === 'buyer_weight_recorded' ? $deadline : null,
                    'delivered_at_gmt' => in_array($from, ['delivered', 'buyer_weight_recorded'], true) ? gmdate('Y-m-d H:i:s', time() - 60) : null,
                    'created_at_gmt' => $now, 'updated_at_gmt' => $now]));
                if (in_array($from, ['in_transit', 'delivered', 'buyer_weight_recorded'], true)) {
                    self::assertSame(1, $wpdb->insert($schema->weights_table(), ['order_id' => $id, 'order_item_id' => $itemId,
                        'vendor_weight_tenths' => 10, 'buyer_weight_tenths' => $from === 'buyer_weight_recorded' ? 8 : null,
                        'updated_at_gmt' => $now]));
                }
                self::assertContains($role, (new WorkflowDefinition())->transitions()[$from][$to]);
                $owner = $role === 'buyer' ? 'buyer_1' : 'vendor_1';
                [$cookie] = CandidateHttp::auth($this->user($owner), 'zbm_core_order_' . $id);
                $render = Task4EndpointHttp::get('task4-remaining-harness.php', ['shortcode' => 'zbm_order_workflow', 'order_id' => $id], $cookie);
                self::assertSame(200, $render['status']); $payload = $this->form($render['body'], 'to_state', $to, $id);
                self::assertSame('zbm_core_transition', $payload['action']); self::assertTrue(wp_is_uuid($payload['correlation_id']));
                $actor = match ($scenario) { 'wrong_role' => $role === 'buyer' ? 'vendor_1' : 'buyer_1',
                    'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', 'guest' => 'guest', default => $owner };
                if ($actor === 'guest') { $cookie = ''; $payload['_wpnonce'] = ''; }
                elseif ($actor !== $owner) { [$cookie, $payload['_wpnonce']] = CandidateHttp::auth($this->user($actor), 'zbm_core_order_' . $id); }
                if ($actor !== 'guest') { self::assertNotFalse(wp_verify_nonce($payload['_wpnonce'], 'zbm_core_order_' . $id)); }
                $reason = $reasonNeeded ? 'Synthetic native evidenced form reason' : '';
                if ($reasonNeeded) { $payload['reason'] = $reason; }
                if ($isWeight) { self::assertArrayHasKey('weights[' . $itemId . ']', $payload); $payload['weights[' . $itemId . ']'] = '۰٫۸'; }
                if ($isEvidence && $scenario !== 'missing_evidence') { $payload['zbm_evidence[0]'] = Task4ManagedFixture::file(); }
                if ($scenario === 'no_nonce') { unset($payload['_wpnonce']); }
                if ($scenario === 'invalid_nonce') { $payload['_wpnonce'] = 'invalid'; }
                if ($scenario === 'invalid_weight') { $payload['weights[' . $itemId . ']'] = '0'; }
                if ($scenario === 'missing_reason') { $payload['reason'] = ''; }
                $before = Task4ManagedFixture::snapshot(); $wfRows = $this->rows($schema->workflows_table(), 'order_id');
                $otherWorkflow = $this->outside($schema->workflows_table(), 'order_id', $id);
                $otherWeights = $this->outside($schema->weights_table(), 'order_id', $id);
                $audits = $this->rows($schema->audit_table()); $commands = $this->rows($schema->idempotency_table(), 'key_hash');
                $posts = $this->rows($wpdb->posts, 'ID'); $metas = $this->rows($wpdb->postmeta, 'meta_id');
                $start = time(); $http = CandidateHttp::post('wp-admin/admin-post.php', $payload, $cookie); $end = time();
                $after = Task4ManagedFixture::snapshot();
                if ($scenario !== 'authorized_positive') {
                    $control = ['actual_http' => true, 'http' => $http['status'], 'unchanged' => true];
                    if ($actor === 'guest') { self::assertSame(400, $http['status']); self::assertStringContainsString('wp-die-message', $http['body']); $control['generic_dispatcher_only'] = true; }
                    elseif (in_array($scenario, ['no_nonce', 'invalid_nonce'], true)) { $message = __('The link you followed has expired.'); $this->denied($http, 403, $message); $control['message'] = $message; }
                    else {
                        $code = match ($scenario) { 'wrong_role' => 'zbm_transition_forbidden', 'other_buyer', 'other_vendor' => 'forbidden',
                            'missing_reason' => 'zbm_reason_too_short', 'missing_evidence' => 'zbm_evidence_count',
                            'invalid_weight' => \Zarbalman\Core\Domain\Order\WeightValue::parse_tenths('0')->get_error_code() };
                        $this->result($http, $code); $control['code'] = $code;
                    }
                    self::assertSame($before, $after); self::assertSame($wfRows, $this->rows($schema->workflows_table(), 'order_id'));
                    $negatives[$scenario] = $control; continue;
                }
                $this->result($http, 'success'); $newRows = $this->rows($schema->workflows_table(), 'order_id');
                self::assertSame(array_keys($wfRows), array_keys($newRows)); $new = $newRows[$id];
                self::assertSame($to, $new['current_state']); self::assertSame(1, (int) $new['version']);
                $changedColumns = ['current_state', 'version', 'updated_at_gmt'];
                if (!in_array($case, ['vendor_prepare', 'vendor_weight'], true)) {
                    $changedColumns[] = 'buyer_deadline_gmt'; $changedColumns[] = 'vendor_deadline_gmt';
                }
                if ($case === 'vendor_deliver') { $changedColumns[] = 'delivered_at_gmt'; }
                $this->exactRow($wfRows[$id], $new, $changedColumns);
                self::assertSame($otherWorkflow, $this->outside($schema->workflows_table(), 'order_id', $id));
                self::assertSame($otherWeights, $this->outside($schema->weights_table(), 'order_id', $id));
                $audit = $this->audited($audits, $schema, $id, 'workflow_transitioned', $role, $this->user($owner), $from, $to, $reason, $payload['correlation_id']);
                $command = $this->command($commands, $schema, $audit, 'workflow'); $specific = [];
                if ($isWeight || $case === 'vendor_confirm') {
                    $weights = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE order_id=%d ORDER BY order_item_id', $schema->weights_table(), $id), ARRAY_A);
                    self::assertSame('', $wpdb->last_error); self::assertCount(1, $weights); self::assertSame($itemId, (int) $weights[0]['order_item_id']);
                    if ($case === 'vendor_weight') { self::assertSame(8, (int) $weights[0]['vendor_weight_tenths']); self::assertNull($weights[0]['buyer_weight_tenths']); self::assertNull($weights[0]['final_weight_tenths']); }
                    elseif ($case === 'buyer_weight') { self::assertSame(10, (int) $weights[0]['vendor_weight_tenths']); self::assertSame(8, (int) $weights[0]['buyer_weight_tenths']); self::assertNull($weights[0]['final_weight_tenths']); }
                    else { self::assertSame(10, (int) $weights[0]['vendor_weight_tenths']); self::assertSame(8, (int) $weights[0]['buyer_weight_tenths']); self::assertSame(8, (int) $weights[0]['final_weight_tenths']); }
                    $specific = $isWeight ? ['stored_weight_tenths' => 8, 'weight_role' => $role, 'weights_verified' => true]
                        : ['buyer_weight_tenths' => 8, 'final_weight_tenths' => 8, 'weights_verified' => true];
                }
                if ($case === 'vendor_deliver') {
                    self::assertNotNull($new['delivered_at_gmt']); self::assertNull($new['vendor_deadline_gmt']);
                    self::assertGreaterThanOrEqual($start, strtotime($new['delivered_at_gmt'] . ' UTC'));
                    self::assertLessThanOrEqual($end, strtotime($new['delivered_at_gmt'] . ' UTC'));
                    self::assertSame(strtotime($new['delivered_at_gmt'] . ' UTC') + 7200, strtotime($new['buyer_deadline_gmt'] . ' UTC'));
                }
                if ($case === 'buyer_weight') {
                    self::assertNull($new['buyer_deadline_gmt']); self::assertNotNull($new['vendor_deadline_gmt']);
                    self::assertGreaterThanOrEqual($start + 1800, strtotime($new['vendor_deadline_gmt'] . ' UTC'));
                    self::assertLessThanOrEqual($end + 1800, strtotime($new['vendor_deadline_gmt'] . ' UTC'));
                }
                if (in_array($to, ['cancellation_requested', 'seller_weight_confirmed', 'dispute_open', 'weight_disputed'], true)) {
                    self::assertNull($new['buyer_deadline_gmt']); self::assertNull($new['vendor_deadline_gmt']);
                }
                $allowed = [$schema->workflows_table(), $schema->audit_table(), $schema->idempotency_table()];
                if ($isWeight || $case === 'vendor_confirm') { $allowed[] = $schema->weights_table(); }
                if ($isEvidence) {
                    $newPosts = $this->appended($posts, $this->rows($wpdb->posts, 'ID'), 1); $attachment = $newPosts[0];
                    self::assertSame('attachment', $attachment['post_type']); $attachmentId = (int) $attachment['ID'];
                    $newMeta = $this->rows($wpdb->postmeta, 'meta_id');
                    foreach ($metas as $key => $row) { self::assertArrayHasKey($key, $newMeta); self::assertSame($row, $newMeta[$key]); }
                    foreach (array_diff_key($newMeta, $metas) as $row) { self::assertSame($attachmentId, (int) $row['post_id']); }
                    self::assertSame($id, (int) get_post_meta($attachmentId, '_zbm_evidence_order_id', true));
                    self::assertSame($this->user($owner), (int) get_post_meta($attachmentId, '_zbm_evidence_actor_id', true));
                    self::assertSame(1, (int) get_post_meta($attachmentId, '_zbm_evidence_private', true));
                    self::assertSame(1, (int) get_post_meta($attachmentId, '_zbm_evidence_encrypted', true));
                    $binary = file_get_contents(Task4ManagedFixture::file()->getFilename()); self::assertIsString($binary); $hash = hash('sha256', $binary);
                    self::assertSame($hash, get_post_meta($attachmentId, '_zbm_evidence_sha256', true));
                    [$readCookie, $readNonce] = CandidateHttp::auth($this->user($owner), 'zbm_core_evidence_' . $attachmentId);
                    $download = Task4EndpointHttp::get('wp-admin/admin-post.php', ['action' => 'zbm_core_download_evidence', 'attachment_id' => $attachmentId, '_wpnonce' => $readNonce], $readCookie);
                    self::assertSame(200, $download['status']); self::assertSame($binary, $download['body']);
                    $allowed[] = $wpdb->posts; $allowed[] = $wpdb->postmeta;
                    $specific = ['attachment_count' => 1, 'encrypted' => true, 'private_flags_verified' => true,
                        'download_bytes_match' => true, 'existing_attachment_rows_unchanged' => true, 'file_sha256' => $hash];
                }
                $this->stable($before, $after, $allowed);
                $replayBefore = Task4ManagedFixture::snapshot(); $replay = CandidateHttp::post('wp-admin/admin-post.php', $payload, $cookie);
                $this->result($replay, 'success'); self::assertSame($replayBefore, Task4ManagedFixture::snapshot());
                $proof = ['catalog_ids' => ['http-031'], 'branch' => $branch, 'http' => 302, 'actor_type' => $role, 'actor_id' => $this->user($owner),
                    'nonce_valid' => true, 'resource_id' => $id, 'from_state' => $from, 'to_state' => $to, 'before_version' => 0, 'after_version' => 1,
                    'row_changes_verified' => true, 'unrelated_rows_unchanged' => true, 'financial_and_orders_unchanged' => true,
                    'before_snapshot' => $before, 'after_snapshot' => $after, 'audit' => $audit, 'idempotency' => $command, 'specific' => $specific,
                    'replay_preserved' => true, 'replay_http' => 302, 'replay_result' => 'success'];
            }
            return $proof + ['negative_controls' => $negatives];
        });
    }
}
