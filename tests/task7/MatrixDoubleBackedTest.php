<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/Task5FinancialFixture.php';
require_once __DIR__ . '/../support/Task4ManagedFixture.php';
require_once __DIR__ . '/../support/Task4RemainingFixture.php';

use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Domain\PurchaseRequest\RequestNumber;
use Zarbalman\Core\Infrastructure\Schema;
use Zarbalman\Core\Integration\ManagedOrderSnapshot;

/** Real HTTP/native persistence with the established disposable dependency doubles. */
final class MatrixDoubleBackedTest extends TestCase {
    private const EVIDENCE = __DIR__ . '/../../evidence/task7-matrix-double-backed.json';
    private const SUCCESS = 'عملیات مدیریت همراه با دلیل و سابقه حسابرسی ثبت شد.';
    private ?Task5DependencyDoubles $doubles = null;
    private array $previous = [];
    private array $phones = [];

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        if (!is_dir(dirname(self::EVIDENCE))) { self::assertTrue(mkdir(dirname(self::EVIDENCE), 0770, true)); }
        self::write(['_meta' => ['schema_version' => 1, 'dependency_mode' => 'double-backed',
            'commercial_compatibility_proved' => false, 'real_provider_called' => false,
            'run_id' => getenv('GITHUB_RUN_ID') ?: 'local', 'run_attempt' => getenv('GITHUB_RUN_ATTEMPT') ?: 'local'], 'controls' => []]);
    }
    protected function setUp(): void {
        parent::setUp();
        foreach (['zbm_core_online_payment_control', '_transient_zbm_core_online_payment_observation', '_transient_timeout_zbm_core_online_payment_observation'] as $name) {
            $missing = new stdClass(); $value = get_option($name, $missing);
            $this->previous[$name] = ['exists' => $value !== $missing, 'value' => $value === $missing ? null : $value];
            delete_option($name);
        }
        $this->doubles = Task5DependencyDoubles::install(['supporting' => true]);
        [$cookie] = CandidateHttp::auth($this->user('admin'), 'task7-native-health');
        $facts = $this->doubles->facts($cookie);
        self::assertNull($facts['request_error']); self::assertNull($facts['deposit_error']); self::assertNull($facts['verification_error']);
        self::assertTrue($facts['gateway_registered']); self::assertTrue($facts['online_available']);
        self::assertSame([0], $facts['managed_callback_priorities']); self::assertTrue($facts['request_control_registered']);
    }
    protected function tearDown(): void {
        try {
            $this->doubles?->restore();
            foreach ($this->previous as $name => $row) {
                if ($row['exists']) { update_option($name, $row['value'], false); } else { delete_option($name); }
            }
            foreach ($this->phones as $id => $rows) {
                delete_user_meta($id, 'billing_phone'); foreach ($rows as $phone) { add_user_meta($id, 'billing_phone', $phone); }
            }
        } finally { $this->doubles = null; wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]); parent::tearDown(); }
    }
    private static function write(array $rows): void {
        $json = wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertSame(strlen($json), file_put_contents(self::EVIDENCE, $json, LOCK_EX));
    }
    private function record(string $key, array $details, string $state = 'passed'): void {
        $rows = json_decode((string) file_get_contents(self::EVIDENCE), true, 64, JSON_THROW_ON_ERROR);
        $rows['controls'][$key] = ['state' => $state, 'assertion_status' => $state, 'actual_http' => true,
            'dependency_mode' => 'double-backed', 'commercial_compatibility_proved' => false, 'real_provider_called' => false] + $details;
        self::write($rows);
    }
    private function user(string $name): int {
        $user = get_user_by('login', 'zbm_test_' . $name); self::assertInstanceOf(WP_User::class, $user); return (int) $user->ID;
    }
    private function rows(string $table, string $where = '', array $args = []): array {
        global $wpdb;
        $sql = 'SELECT * FROM %i' . ($where !== '' ? ' WHERE ' . $where : '') . ' ORDER BY 1';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $table, ...$args), ARRAY_A);
        self::assertIsArray($rows); self::assertSame('', $wpdb->last_error); return $rows;
    }
    private function emailFingerprint(): string {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT ID,user_email FROM %i ORDER BY ID', $wpdb->users), ARRAY_A);
        self::assertIsArray($rows); self::assertSame('', $wpdb->last_error);
        return hash('sha256', wp_json_encode($rows, JSON_THROW_ON_ERROR));
    }
    private function fixture(bool $issued): Task5FinancialFixture {
        global $wpdb;
        $fixture = new Task5FinancialFixture(1000, 100, 0, 2, $issued);
        // Existing financial fixtures predate the public admin-search reference.
        // Add a unique valid reference without changing lifecycle/payment facts.
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $number = RequestNumber::generate();
            $found = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE request_number=%s', (new Schema($wpdb))->purchase_requests_table(), $number));
            self::assertSame('', $wpdb->last_error); self::assertNotNull($found); if ((int) $found === 0) { break; }
        }
        self::assertSame(0, (int) $found); $fixture->persistedFixtureFields(['request_number' => $number]);
        self::assertTrue(ManagedOrderSnapshot::matches($fixture->order(), $fixture->request())); return $fixture;
    }
    private function form(string $html, string $operation, ?int $id = null): array {
        $dom = new DOMDocument(); $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        foreach ($dom->getElementsByTagName('form') as $form) {
            $fields = [];
            foreach (['input', 'textarea'] as $tag) { foreach ($form->getElementsByTagName($tag) as $node) {
                $name = $node->getAttribute('name'); if ($name !== '') { $fields[$name] = $tag === 'input' ? $node->getAttribute('value') : $node->textContent; }
            } }
            if (($fields['action'] ?? '') !== ($id === null ? 'zbm_create_purchase_request' : 'zbm_admin_request_control')) { continue; }
            if ($id !== null && (($fields['operation'] ?? '') !== $operation || ($fields['request_id'] ?? '') !== (string) $id)) { continue; }
            self::assertSame('post', strtolower($form->getAttribute('method')));
            self::assertSame('/wp-admin/admin-post.php', parse_url($form->getAttribute('action'), PHP_URL_PATH));
            return $fields;
        }
        self::fail('Required actual native form missing: ' . $operation);
    }
    private function rendered(Task5FinancialFixture $fixture, string $operation): array {
        [$cookie] = CandidateHttp::auth($this->user('admin'), 'zbm_admin_request_control_' . $fixture->id);
        $http = Task4EndpointHttp::get('wp-admin/admin.php', ['page' => 'zbm-core-requests', 'request_number' => $fixture->request()->requestNumber()], $cookie);
        self::assertSame(200, $http['status']); $fields = $this->form($http['body'], $operation, $fixture->id);
        self::assertSame((string) $fixture->request()->version(), $fields['version']);
        self::assertNotFalse(wp_verify_nonce($fields['_zbm_nonce'], 'zbm_admin_request_control_' . $fixture->id));
        $fields['reason'] = 'Task seven synthetic documented administrative action'; return [$cookie, $fields];
    }
    private function notice(array $http, string $type, string $message): array {
        self::assertSame(302, $http['status']);
        self::assertSame(admin_url('admin.php?page=zbm-core-requests'), Task4EndpointHttp::header($http, 'Location'));
        $notice = Task4EndpointHttp::option('_transient_zbm_admin_request_notice_' . $this->user('admin'));
        self::assertIsArray($notice); $expected = ['type' => $type, 'message' => $message];
        ksort($expected); ksort($notice); self::assertSame($expected, $notice); return $notice;
    }
    private function deny(array $http, int $status, string $message): void {
        self::assertSame($status, $http['status']);
        self::assertStringContainsString($message, html_entity_decode(strip_tags($http['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    private function negatives(Task5FinancialFixture $fixture, string $operation, array $ids): array {
        $controls = []; $scenarios = ['wrong_role', 'other_buyer', 'other_vendor', 'guest', 'no_nonce', 'invalid_nonce', 'missing_reason'];
        if ($operation === 'reopen_deposit') { $scenarios[] = 'invalid_hours'; }
        if ($operation === 'cancel_review') { $scenarios[] = 'stale_version'; }
        foreach ($scenarios as $scenario) {
            [$cookie, $fields] = $this->rendered($fixture, $operation);
            $actor = match ($scenario) { 'wrong_role' => 'buyer_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', 'guest' => 'guest', default => 'admin' };
            if ($actor === 'guest') { $cookie = ''; }
            elseif ($actor !== 'admin') { [$cookie, $fields['_zbm_nonce']] = CandidateHttp::auth($this->user($actor), 'zbm_admin_request_control_' . $fixture->id); }
            if ($scenario === 'no_nonce') { unset($fields['_zbm_nonce']); }
            if ($scenario === 'invalid_nonce') { $fields['_zbm_nonce'] = 'task7-invalid-nonce'; }
            if ($scenario === 'missing_reason') { $fields['reason'] = ''; }
            if ($scenario === 'invalid_hours') { $fields['hours'] = '0'; }
            if ($scenario === 'stale_version') { $fields['version'] = (string) ($fixture->request()->version() + 9); }
            $before = Task4ManagedFixture::snapshot(); $emails = $this->emailFingerprint(); $calls = count($this->doubles->calls());
            $http = CandidateHttp::post('wp-admin/admin-post.php', $fields, $cookie);
            $notice = null;
            if ($actor === 'guest') { self::assertSame(400, $http['status']); self::assertStringContainsString('wp-die-message', $http['body']); }
            elseif ($actor !== 'admin') { $this->deny($http, 500, 'دسترسی غیرمجاز است.'); }
            elseif (in_array($scenario, ['no_nonce', 'invalid_nonce'], true)) { $this->deny($http, 403, __('The link you followed has expired.')); }
            else {
                $message = match ($operation) { 'reopen_deposit' => 'دلیل و مدت معتبر برای بازگشایی لازم است.',
                    'accept_late' => 'دسترسی و دلیل مستند مدیریت معتبر نیست.', default => 'درخواست، نسخه، دسترسی یا دلیل لغو معتبر نیست.' };
                $notice = $this->notice($http, 'error', $message);
            }
            $after = Task4ManagedFixture::snapshot(); self::assertSame($before, $after);
            self::assertSame($emails, $this->emailFingerprint()); self::assertSame($calls, count($this->doubles->calls()));
            $controls[$scenario] = ['state' => 'passed', 'assertion_status' => 'passed', 'actual_http' => true,
                'catalog_ids' => $ids, 'http' => $http['status'], 'actor' => $actor,
                'response_contract' => $actor === 'guest' ? 'guest-native-dispatcher' : ($actor !== 'admin' ? 'unauthorized' : (in_array($scenario, ['no_nonce', 'invalid_nonce'], true) ? 'wordpress-expired-link' : 'admin-notice')), 'before_snapshot' => $before, 'after_snapshot' => $after,
                'notice' => $notice, 'snapshot_unchanged' => true, 'financial_unchanged' => true, 'provider_calls_added' => 0];
            $this->record($operation . ':negative:' . $scenario, ['test' => __CLASS__ . '::test_rendered_native_lifecycle_branch', 'branch' => $operation] + $controls[$scenario]);
        }
        return $controls;
    }
    private function audit(int $id, string $event, string $from, string $to): array {
        global $wpdb;
        $rows = $this->rows((new Schema($wpdb))->audit_table(), 'resource_id=%d AND event_key=%s', [$id, $event]);
        self::assertCount(1, $rows); $row = $rows[0]; self::assertSame('admin', $row['actor_type']);
        self::assertSame($this->user('admin'), (int) $row['actor_user_id']); self::assertSame($from, $row['from_state']); self::assertSame($to, $row['to_state']);
        self::assertTrue(wp_is_uuid($row['event_uuid'])); $meta = json_decode($row['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('Task seven synthetic documented administrative action', $meta['reason']);
        return ['event_key' => $event, 'event_uuid' => $row['event_uuid'], 'actor_type' => 'admin', 'actor_id' => (int) $row['actor_user_id'], 'count' => 1];
    }
    public static function branches(): array { return ['reopen_deposit' => ['reopen_deposit'], 'accept_late' => ['accept_late'], 'cancel_review' => ['cancel_review']]; }
    /** @dataProvider branches */
    public function test_rendered_native_lifecycle_branch(string $operation): void {
        global $wpdb; $ids = $operation === 'cancel_review' ? ['http-014'] : ['http-013'];
        $this->record($operation, ['test' => __METHOD__, 'catalog_ids' => $ids, 'branch' => $operation], 'pending');
        try {
            $fixture = $this->fixture($operation === 'accept_late');
            if ($operation === 'reopen_deposit') { $fixture->expireNatively(); }
            elseif ($operation === 'accept_late') { $fixture->expireNatively('late_payment_review'); $fixture->configure($this->doubles); }
            else {
                // Explicit historical unpaid review state: there is no issued identity.
                $fixture->persistedFixtureFields(['current_state' => 'late_payment_review']);
            }
            $negative = $this->negatives($fixture, $operation, $ids);
            [$cookie, $fields] = $this->rendered($fixture, $operation); $request = $fixture->request();
            $before = Task4ManagedFixture::snapshot(); $version = $request->version(); $emails = $this->emailFingerprint();
            $callsBefore = count($this->doubles->calls()); $start = time();
            $http = CandidateHttp::post('wp-admin/admin-post.php', $fields, $cookie); $end = time();
            $notice = $this->notice($http, 'success', self::SUCCESS); $fresh = $fixture->request();
            self::assertGreaterThan($version, $fresh->version()); self::assertSame($emails, $this->emailFingerprint());
            self::assertSame($fixture->orderId, $fresh->wcOrderId()); self::assertFalse($fresh->needsReconciliation());
            $schema = new Schema($wpdb); $payments = $this->rows($schema->payments_table(), 'request_id=%d', [$fixture->id]);
            self::assertCount(1, $payments); $ledger = $this->rows($schema->ledger_table(), 'request_id=%d', [$fixture->id]);
            $effects = ['payment_status' => $payments[0]['status'], 'payment_count' => count($payments),
                'paid_amount_toman' => $payments[0]['paid_amount_toman'] === null ? null : (int) $payments[0]['paid_amount_toman'],
                'ledger_count' => count($ledger), 'order_status' => $fixture->order()->get_status(),
                'order_id_matches' => $fixture->orderId === $fresh->wcOrderId(), 'reconciliation_required' => $fresh->needsReconciliation(),
                'stock_reserved' => $fresh->stockReserved(), 'stock_released' => $fresh->stockReleased(),
                'payment_ready' => $fresh->paymentReady(), 'provider_calls' => []];
            if ($operation === 'reopen_deposit') {
                self::assertSame('deposit_pending', $fresh->state()); self::assertTrue($fresh->paymentReady());
                self::assertTrue($fresh->stockReserved()); self::assertFalse($fresh->stockReleased());
                self::assertSame('expected', $payments[0]['status']); self::assertNull($payments[0]['paid_amount_toman']); self::assertSame([], $ledger);
                self::assertSame('pending', $fixture->order()->get_status()); self::assertSame('yes', $fixture->order()->get_meta('_zbm_payment_enabled', true));
                $deadline = strtotime($fresh->depositDeadlineGmt() . ' UTC'); self::assertGreaterThanOrEqual($start + 86400, $deadline); self::assertLessThanOrEqual($end + 86400, $deadline);
                self::assertSame(0, count($this->doubles->calls()) - $callsBefore);
                $effects['deadline_in_window'] = $deadline >= $start + 86400 && $deadline <= $end + 86400;
                $audit = $this->audit($fixture->id, 'deposit_deadline_reopen_requested', 'deposit_expired', 'deposit_pending');
            } elseif ($operation === 'accept_late') {
                self::assertSame('deposit_paid', $fresh->state()); self::assertSame('late_accepted', $payments[0]['status']);
                self::assertSame(100, (int) $payments[0]['paid_amount_toman']); self::assertSame($fixture->authority, $payments[0]['authority']); self::assertSame($fixture->reference, $payments[0]['reference']);
                self::assertTrue($fixture->order()->is_paid()); self::assertSame($fixture->reference, $fixture->order()->get_transaction_id());
                self::assertTrue($fresh->stockReserved()); self::assertFalse($fresh->stockReleased()); self::assertCount(3, $ledger);
                $amounts = []; foreach ($ledger as $leg) { $amounts[$leg['entry_type']] = (int) $leg['amount_toman']; self::assertSame((int) $payments[0]['id'], (int) $leg['payment_id']); }
                ksort($amounts); self::assertSame(['employer_cash_received' => 100, 'platform_share_provisional' => 10, 'vendor_share_provisional' => 90], $amounts);
                $calls = array_slice($this->doubles->calls(), $callsBefore); self::assertCount(1, $calls);
                self::assertSame('verify.json', $calls[0]['path']); self::assertSame(1000, $calls[0]['amount']); self::assertTrue($calls[0]['expected_binding_matches']); self::assertTrue($calls[0]['merchant_is_synthetic']);
                $effects['ledger_amounts'] = $amounts; $effects['simulated_verification_calls'] = 1;
                $effects['authority_matches'] = $fixture->authority === $payments[0]['authority'];
                $effects['reference_matches'] = $fixture->reference === $payments[0]['reference'];
                $effects['order_paid'] = $fixture->order()->is_paid();
                $effects['transaction_matches'] = $fixture->reference === $fixture->order()->get_transaction_id();
                $effects['provider_calls'] = array_map(static fn(array $call): array => array_intersect_key($call,
                    array_flip(['path', 'amount', 'expected_binding_matches', 'merchant_is_synthetic'])), $calls);
                $audit = $this->audit($fixture->id, 'late_deposit_accepted', 'late_payment_review', 'deposit_paid');
            } else {
                self::assertSame('cancelled', $fresh->state()); self::assertTrue($fresh->stockReleased());
                self::assertSame('cancelled', $fixture->order()->get_status()); self::assertSame('expired', $payments[0]['status']);
                self::assertNull($payments[0]['paid_amount_toman']); self::assertSame([], $ledger); self::assertSame(0, count($this->doubles->calls()) - $callsBefore);
                $audit = $this->audit($fixture->id, 'admin_review_cancelled', 'late_payment_review', 'cancelled');
            }
            self::assertTrue(ManagedOrderSnapshot::matches($fixture->order(), $fresh));
            $after = Task4ManagedFixture::snapshot(); self::assertNotSame($before, $after);
            foreach ([$schema->financial_actions_table(), $schema->dokan_credit_mirrors_table()] as $table) { self::assertSame($before[$table], $after[$table]); }
            $replayCalls = count($this->doubles->calls()); $replayEmails = $this->emailFingerprint();
            $replay = CandidateHttp::post('wp-admin/admin-post.php', $fields, $cookie);
            $replayMessage = match ($operation) { 'reopen_deposit' => 'این مهلت پرداخت قابل بازگشایی نیست.', 'accept_late' => 'این پرونده پرداخت دیرهنگام قابل پذیرش نیست.', default => 'درخواست، نسخه، دسترسی یا دلیل لغو معتبر نیست.' };
            $replayNotice = $this->notice($replay, 'error', $replayMessage); $replaySnapshot = Task4ManagedFixture::snapshot(); self::assertSame($after, $replaySnapshot);
            self::assertSame($replayCalls, count($this->doubles->calls())); self::assertSame($replayEmails, $this->emailFingerprint());
            $this->record($operation, ['test' => __METHOD__, 'dataset' => $operation, 'catalog_ids' => $ids, 'branch' => $operation,
                'http' => 302, 'actor' => 'admin', 'nonce_valid' => true, 'actual_form_submitted' => true,
                'request_id' => $fixture->id, 'order_id' => $fixture->orderId, 'before_state' => $request->state(), 'after_state' => $fresh->state(),
                'before_version' => $version, 'after_version' => $fresh->version(), 'before_snapshot' => $before, 'after_snapshot' => $after,
                'notice' => $notice, 'effects' => $effects, 'audit' => $audit, 'negative_controls' => $negative,
                'snapshot_matches' => true, 'replay_preserved' => true, 'replay_http' => $replay['status'],
                'replay_provider_calls_added' => count($this->doubles->calls()) - $replayCalls, 'replay_account_emails_unchanged' => $replayEmails === $this->emailFingerprint(),
                'replay_notice' => $replayNotice, 'replay_snapshot' => $replaySnapshot, 'account_emails_unchanged' => true,
                'historical_unissued_review_fixture' => $operation === 'cancel_review']);
        } catch (Throwable $error) {
            $this->record($operation, ['test' => __METHOD__, 'dataset' => $operation, 'catalog_ids' => $ids, 'branch' => $operation, 'failure_class' => get_class($error)], 'failed'); throw $error;
        }
    }
    public function test_rendered_cancellation_preserves_issued_identity_before_any_verifier(): void {
        global $wpdb; $key = 'cancel_review_identity_safety';
        $this->record($key, ['test' => __METHOD__, 'catalog_ids' => ['http-014']], 'pending');
        try {
            $fixture = $this->fixture(true); $fixture->persistedFixtureFields(['current_state' => 'late_payment_review']);
            $schema = new Schema($wpdb); $payments = $this->rows($schema->payments_table(), 'request_id=%d', [$fixture->id]);
            self::assertCount(1, $payments);
            self::assertSame(1, $wpdb->update($schema->payments_table(), ['status' => 'callback_received_late', 'callback_at_gmt' => current_time('mysql', true)], ['id' => $payments[0]['id']]));
            $payment = $this->rows($schema->payments_table(), 'request_id=%d', [$fixture->id])[0];
            self::assertNull($payment['paid_amount_toman']); self::assertNull($payment['reference']); self::assertNull($payment['paid_at_gmt']);
            self::assertSame($fixture->authority, $payment['authority']); self::assertTrue(ManagedOrderSnapshot::matches($fixture->order(), $fixture->request()));
            $fixture->configure($this->doubles);
            [$cookie, $fields] = $this->rendered($fixture, 'cancel_review');
            $before = Task4ManagedFixture::snapshot(); $version = $fixture->request()->version(); $emails = $this->emailFingerprint();
            $calls = count($this->doubles->calls()); $http = CandidateHttp::post('wp-admin/admin-post.php', $fields, $cookie);
            $notice = $this->notice($http, 'error', 'لغو امن انجام نشد؛ وجود پرداخت قطعی یا وضعیت موجودی/سفارش باید بررسی شود.');
            $after = Task4ManagedFixture::snapshot(); self::assertSame($before, $after); self::assertSame($version, $fixture->request()->version());
            self::assertSame('late_payment_review', $fixture->request()->state()); self::assertSame($emails, $this->emailFingerprint());
            self::assertSame($calls, count($this->doubles->calls()), 'A configured successful simulated verifier must not be reached before issued-identity safety guard.');
            $this->record($key, ['test' => __METHOD__, 'catalog_ids' => ['http-014'], 'type' => 'validation', 'http' => 302,
                'actor' => 'admin', 'nonce_valid' => true, 'actor_valid' => true, 'stored_binding_valid' => true, 'persisted_assertions' => true,
                'financial_unchanged' => true, 'before_snapshot' => $before, 'after_snapshot' => $after, 'notice' => $notice,
                'request_id' => $fixture->id, 'before_state' => 'late_payment_review', 'after_state' => 'late_payment_review',
                'before_version' => $version, 'after_version' => $fixture->request()->version(),
                'identity_guard_before_verifier' => true, 'provider_verification_verified' => false, 'dependency_control_claimed' => false,
                'identity_contract' => ['snapshot_matches' => true, 'authority_matches' => true, 'route' => 'online', 'execution_mode' => 'live',
                    'payment_status' => 'callback_received_late', 'paid_amount' => null, 'reference' => null, 'paid_at' => null],
                'provider_calls_added' => 0, 'account_emails_unchanged' => true]);
        } catch (Throwable $error) { $this->record($key, ['test' => __METHOD__, 'catalog_ids' => ['http-014'], 'failure_class' => get_class($error)], 'failed'); throw $error; }
    }
    public function test_rendered_creation_resolves_actor_scope_after_dependency_gate(): void {
        global $wpdb; $key = 'zbm_create_purchase_request'; $ids = ['http-020', 'http-021', 'http-048'];
        $this->record($key, ['test' => __METHOD__, 'catalog_ids' => $ids], 'pending');
        try {
            $schema = new Schema($wpdb); $vendor = $this->user('vendor_1');
            foreach (['buyer_1', 'buyer_2', 'vendor_1', 'vendor_2'] as $name) {
                $id = $this->user($name); $this->phones[$id] = get_user_meta($id, 'billing_phone', false); update_user_meta($id, 'billing_phone', '09120000000');
            }
            $product = Task4RemainingFixture::product($vendor, 'Task seven native creation matrix product');
            $values = Task4RemainingFixture::createFields($product, $vendor); $controls = [];
            // Capture the native form as a buyer who may purchase this vendor's product.
            // Actor controls submit that form after changing the authenticated session;
            // the owning vendor need not have a purchase form rendered on its own product.
            [$renderCookie] = CandidateHttp::auth($this->user('buyer_1'), 'zbm_create_purchase_request');
            $productUrl = get_permalink($product->get_id()); self::assertIsString($productUrl);
            $productQuery = []; parse_str((string) parse_url($productUrl, PHP_URL_QUERY), $productQuery);
            $render = Task4EndpointHttp::get((string) parse_url($productUrl, PHP_URL_PATH), $productQuery, $renderCookie);
            self::assertSame(200, $render['status']); $form = $this->form($render['body'], 'create');
            self::assertSame((string) $product->get_id(), $form['product_id']);
            self::assertSame('', $form['_zbm_nonce']); self::assertSame('', $form['idempotency_key']);
            foreach (['wrong_role', 'other_buyer', 'other_vendor', 'no_nonce', 'invalid_nonce', 'guest'] as $scenario) {
                $actor = match ($scenario) { 'wrong_role' => 'vendor_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', 'guest' => 'guest', default => 'buyer_1' };
                if ($actor === 'guest') { $cookie = ''; }
                else { [$cookie] = CandidateHttp::auth($this->user($actor), 'zbm_create_purchase_request'); }
                // Preserve native routing/identity fields, fill user controls, and omit inactive pickup inputs.
                $fields = array_replace($values, array_intersect_key($form, array_flip(['action', 'product_id', 'variation_id', '_zbm_nonce', 'idempotency_key'])));
                // Match the browser's native auth-state refresh: the rendered fields
                // start empty and the public AJAX endpoint supplies this session's nonce.
                $auth = CandidateHttp::post('wp-admin/admin-ajax.php', ['action' => 'zbm_request_auth_state', 'product_id' => (string) $product->get_id()], $cookie);
                self::assertSame(200, $auth['status']); $authState = json_decode($auth['body'], true, 32, JSON_THROW_ON_ERROR);
                self::assertTrue($authState['success']); self::assertSame($actor !== 'guest', $authState['data']['authenticated']);
                if ($actor !== 'guest') {
                    $fields['_zbm_nonce'] = $authState['data']['create_nonce']; $fields['idempotency_key'] = $authState['data']['idempotency_key'];
                    self::assertTrue(wp_is_uuid($fields['idempotency_key'])); self::assertNotFalse(wp_verify_nonce($fields['_zbm_nonce'], 'zbm_create_purchase_request'));
                }
                // A posted identity must never select the stored buyer or product vendor.
                $fields['buyer_id'] = (string) $this->user('admin'); $fields['vendor_id'] = (string) $this->user('vendor_2');
                if ($scenario === 'no_nonce') { unset($fields['_zbm_nonce']); }
                if ($scenario === 'invalid_nonce') { $fields['_zbm_nonce'] = 'task7-invalid-nonce'; }
                $before = Task4ManagedFixture::snapshot(); $emails = $this->emailFingerprint();
                $requestsBefore = $this->rows($schema->purchase_requests_table()); $calls = count($this->doubles->calls());
                $http = CandidateHttp::post('wp-admin/admin-post.php', $fields, $cookie);
                $after = Task4ManagedFixture::snapshot(); self::assertSame($emails, $this->emailFingerprint()); self::assertSame($calls, count($this->doubles->calls()));
                $control = ['test' => __METHOD__, 'catalog_ids' => $ids, 'actual_http' => true, 'assertion_status' => 'passed',
                    'http' => $http['status'], 'actor' => $actor, 'before_snapshot' => $before, 'after_snapshot' => $after,
                    'dependency_error_absent' => true, 'actual_form_submitted' => true, 'account_emails_unchanged' => true, 'provider_calls_added' => 0];
                if (in_array($scenario, ['other_buyer', 'other_vendor'], true)) {
                    self::assertSame(303, $http['status']); $location = Task4EndpointHttp::header($http, 'Location'); $query = [];
                    self::assertSame(parse_url(home_url('/'), PHP_URL_HOST), parse_url($location, PHP_URL_HOST)); parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
                    self::assertSame('1', (string) ($query['zbm_created'] ?? '')); $created = absint($query['request'] ?? 0); self::assertGreaterThan(0, $created);
                    $rows = $this->rows($schema->purchase_requests_table()); self::assertCount(count($requestsBefore) + 1, $rows);
                    $existing = array_column($requestsBefore, null, 'id'); $current = array_column($rows, null, 'id');
                    foreach ($existing as $id => $row) { self::assertSame($row, $current[$id]); }
                    $request = CandidateFixture::repositories()['requests']->find($created); self::assertNotNull($request);
                    self::assertSame($this->user($actor), $request->buyerId()); self::assertSame($vendor, $request->vendorId());
                    self::assertSame($product->get_id(), $request->productId()); self::assertSame('supplier_review', $request->state()); self::assertSame('live', $request->executionMode());
                    self::assertSame(0, $request->version()); self::assertNull($request->wcOrderId()); self::assertFalse($request->stockReserved());
                    self::assertSame([], $this->rows($schema->payments_table(), 'request_id=%d', [$created])); self::assertSame([], $this->rows($schema->ledger_table(), 'request_id=%d', [$created]));
                    foreach ([$schema->payments_table(), $schema->ledger_table(), $schema->financial_actions_table(), $schema->dokan_credit_mirrors_table(),
                        $wpdb->prefix . 'wc_orders', $wpdb->prefix . 'wc_orders_meta', $wpdb->prefix . 'woocommerce_order_items', $wpdb->prefix . 'woocommerce_order_itemmeta'] as $table) { self::assertSame($before[$table], $after[$table]); }
                    $control += ['result' => 'allowed-own-scope-native-creation', 'request_id' => $created, 'stored_buyer_id' => $request->buyerId(),
                        'stored_vendor_id' => $request->vendorId(), 'forged_buyer_ignored' => true, 'forged_vendor_ignored' => true, 'financial_and_orders_unchanged' => true,
                        'actor_user_id' => $this->user($actor), 'product_vendor_id' => $vendor, 'product_id' => $product->get_id(),
                        'posted_buyer_id' => (int) $fields['buyer_id'], 'posted_vendor_id' => (int) $fields['vendor_id'],
                        'requests_before_count' => count($requestsBefore), 'requests_after_count' => count($rows), 'existing_requests_unchanged' => true,
                        'request_contract' => ['id' => $created, 'buyer_id' => $request->buyerId(), 'vendor_id' => $request->vendorId(),
                            'product_id' => $request->productId(), 'state' => $request->state(), 'execution_mode' => $request->executionMode(),
                            'version' => $request->version(), 'wc_order_id' => $request->wcOrderId(), 'stock_reserved' => $request->stockReserved(),
                            'payments_count' => 0, 'ledger_count' => 0],
                        'role_contract' => 'Native source permits every logged-in actor to buy another vendor product; buyer is derived from the authenticated session.'];
                } elseif ($scenario === 'guest') {
                    self::assertSame(302, $http['status']); $location = Task4EndpointHttp::header($http, 'Location');
                    self::assertSame(parse_url(home_url('/'), PHP_URL_HOST), parse_url($location, PHP_URL_HOST)); self::assertStringContainsString('zbm_request_return=', $location);
                    self::assertSame($before, $after); $control += ['result' => 'guest-login-preparation', 'snapshot_unchanged' => true];
                } else {
                    self::assertSame(303, $http['status']); $notice = Task4EndpointHttp::option('_transient_zbm_request_notice_' . $this->user($actor));
                    self::assertIsArray($notice); self::assertSame('error', $notice['type']);
                    $nonce = in_array($scenario, ['no_nonce', 'invalid_nonce'], true);
                    self::assertSame($nonce ? 'zbm_request_nonce_invalid' : 'zbm_self_purchase_forbidden', $notice['code']);
                    self::assertSame($nonce ? 'اعتبار فرم پایان یافته یا حساب ورود تغییر کرده است. صفحهٔ تازه بارگذاری شد؛ درخواست را دوباره از همین صفحه ثبت کنید.' : 'فروشنده نمی‌تواند برای محصول خودش درخواست خرید ثبت کند.', $notice['message']);
                    self::assertSame($before, $after); $control += ['result' => 'rejected-exact-contract-no-persisted-effect', 'notice' => $notice, 'snapshot_unchanged' => true];
                }
                $controls[$scenario] = $control; $this->record($key . ':' . $scenario, $control);
            }
            $this->record($key, ['test' => __METHOD__, 'catalog_ids' => $ids, 'controls' => $controls,
                'role_contract' => 'Self purchase is forbidden; a different buyer and a different vendor may create their own new request.']);
        } catch (Throwable $error) { $this->record($key, ['test' => __METHOD__, 'catalog_ids' => $ids, 'failure_class' => get_class($error)], 'failed'); throw $error; }
    }
}
