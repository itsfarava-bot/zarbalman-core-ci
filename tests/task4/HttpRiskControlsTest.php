<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/Task5HttpRiskFixture.php';

use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Application\ManualPaymentSettings;
use Zarbalman\Core\Infrastructure\{ProtectedText, DeliveryDataCrypto};

/** Extra authenticated controls. These do not erase unregistered nopriv/guest gaps. */
final class HttpRiskControlsTest extends TestCase {
    private mixed $previousUnit;
    private mixed $previousSettings;
    private static array $evidence = [];
    private const FINANCE_MESSAGE = 'اقدام مالی با وضعیت فعلی پرونده سازگار نیست. آخرین وضعیت، مدرک و نتیجهٔ پرداخت را بررسی کنید؛ مبلغی تغییر نکرد.';
    private const FLOW_MESSAGE = 'عملیات پرونده انجام نشد؛ وضعیت، مهلت، نسخه و دسترسی را دوباره بررسی کنید.';
    private const WRONG_CODE_MESSAGE = 'کد صحیح نبود؛ تحویل ثبت نشده است. کد فعلی را هنگام دریافت کالا از خریدار بگیرید.';
    private const LOCKED_MESSAGE = 'کد صحیح نبود و سقف تلاش‌ها پایان یافت. پرونده برای بررسی به مدیریت ارجاع شد.';

    protected function setUp(): void {
        parent::setUp();
        $this->previousUnit = get_option('woocommerce_weight_unit', null);
        $this->previousSettings = get_option('zbm_core_real_manual_payment', null);
        update_option('woocommerce_weight_unit', 'kg');
        $repos = CandidateFixture::repositories();
        $users = Task4ManagedFixture::users();
        $protected = new ProtectedText(new DeliveryDataCrypto());
        self::assertTrue($protected->ensureKey());
        self::assertTrue((new ManualPaymentSettings($protected, $repos['audit']))->save($users['admin'],
            ['enabled' => true, 'card_number' => '4242424242424242', 'holder_name' => 'Synthetic HTTP risk fixture'],
            'Synthetic isolated HTTP risk settings'));
    }

    protected function tearDown(): void {
        $this->previousUnit === null ? delete_option('woocommerce_weight_unit') : update_option('woocommerce_weight_unit', $this->previousUnit);
        $this->previousSettings === null ? delete_option('zbm_core_real_manual_payment') : update_option('zbm_core_real_manual_payment', $this->previousSettings);
        wp_set_current_user(0);
        unset($_COOKIE[LOGGED_IN_COOKIE]);
        parent::tearDown();
    }

    public static function financialBindings(): array {
        $rows = [];
        foreach (['spec4_approve_action', 'spec4_execute_action', 'spec4_fail_action', 'spec4_reconcile_action'] as $operation) {
            foreach (['admin-ajax.php', 'admin-post.php'] as $transport) { $rows[$operation . '/' . $transport] = [$operation, $transport]; }
        }
        return $rows;
    }

    /** @dataProvider financialBindings */
    public function test_foreign_financial_action_id_with_valid_target_nonce_changes_neither_request(string $operation, string $transport): void {
        $target = new Task4ManagedFixture($operation);
        $foreign = new Task4ManagedFixture($operation);
        $targetId = (int) $target->extra['financial_action_id'];
        $foreignId = (int) $foreign->extra['financial_action_id'];
        self::assertNotSame($targetId, $foreignId);
        $targetBefore = $target->case(); $foreignBefore = $foreign->case();
        $targetMoney = Task5HttpRiskFixture::money($target); $foreignMoney = Task5HttpRiskFixture::money($foreign);
        $before = Task4ManagedFixture::snapshot();
        [$http] = Task5HttpRiskFixture::post($target, $operation, 'admin', $transport, ['financial_action_id' => (string) $foreignId]);
        $this->assertRejected($http, $transport, $target->users['admin'], 'zbm_spec4_action_binding', self::FINANCE_MESSAGE);
        self::assertSame($before, Task4ManagedFixture::snapshot(), 'Foreign ID must not change either request, action, ledger, audit, command or attachment.');
        self::assertSame($targetBefore, $target->case()); self::assertSame($foreignBefore, $foreign->case());
        self::assertSame($targetMoney, Task5HttpRiskFixture::money($target)); self::assertSame($foreignMoney, Task5HttpRiskFixture::money($foreign));
        $this->record($operation . '/' . $transport, ['result' => 'exact-authenticated-binding-denial', 'http' => $http['status'],
            'nonce_scope' => 'target-request', 'target_request_id' => $target->id, 'foreign_request_id' => $foreign->id,
            'foreign_action_id' => $foreignId, 'all_business_rows_unchanged' => true]);
    }

    public function test_private_weight_evidence_altered_id_and_other_owners_with_valid_nonce(): void {
        // Each attachment was uploaded and canonically bound by a genuine HTTP prerequisite.
        $target = new Task4ManagedFixture('flow_ready_pickup');
        $foreign = new Task4ManagedFixture('flow_ready_pickup');
        $ownId = Task5HttpRiskFixture::evidence($target); $foreignId = Task5HttpRiskFixture::evidence($foreign);
        self::assertNotSame($ownId, $foreignId);
        foreach (['buyer_1', 'vendor_1'] as $actor) {
            $before = Task4ManagedFixture::snapshot();
            $own = Task5HttpRiskFixture::download($target->id, $ownId, $target->users[$actor]);
            self::assertSame(200, $own['status'], $own['body']);
            self::assertSame(Task5HttpRiskFixture::binary($ownId), $own['body']);
            self::assertSame('private, no-store, max-age=0', Task4EndpointHttp::header($own, 'Cache-Control'));
            self::assertSame('nosniff', Task4EndpointHttp::header($own, 'X-Content-Type-Options'));
            self::assertSame($before, Task4ManagedFixture::snapshot());
            // These users own both requests: attachment/request binding must still reject substitution.
            $altered = Task5HttpRiskFixture::download($target->id, $foreignId, $target->users[$actor]);
            $this->assertEvidenceDenied($altered); self::assertSame($before, Task4ManagedFixture::snapshot());
            $changedRequest = Task5HttpRiskFixture::download($foreign->id, $ownId, $target->users[$actor]);
            $this->assertEvidenceDenied($changedRequest); self::assertSame($before, Task4ManagedFixture::snapshot());
            $legacy = Task5HttpRiskFixture::legacyDownload($ownId, $target->users[$actor]);
            self::assertSame(403, $legacy['status'], $legacy['body']);
            self::assertStringContainsString('مدرک مدیریت‌شده فقط از مسیر خصوصی درخواست قابل مشاهده است.',
                html_entity_decode(strip_tags($legacy['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            self::assertSame($before, Task4ManagedFixture::snapshot());
            $this->record('evidence/altered-id/' . $actor, ['result' => 'exact-binding-denial', 'http' => 403,
                'positive_http' => 200, 'nonce_scope' => 'target-request-and-altered-attachment', 'all_business_rows_unchanged' => true]);
            $this->record('evidence/changed-request-and-legacy/' . $actor, ['result' => 'exact-binding-and-downgrade-denial',
                'changed_request_http' => 403, 'legacy_http' => 403, 'nonce_scope' => 'actual-requested-tuple-or-legacy-attachment',
                'positive_no_store' => true, 'all_business_rows_unchanged' => true]);
        }
        foreach (['buyer_2', 'vendor_2'] as $actor) {
            $before = Task4ManagedFixture::snapshot();
            $http = Task5HttpRiskFixture::download($target->id, $ownId, $target->users[$actor]);
            $this->assertEvidenceDenied($http); self::assertSame($before, Task4ManagedFixture::snapshot());
            $this->record('evidence/other-owner/' . $actor, ['result' => 'exact-ownership-denial', 'http' => 403,
                'nonce_scope' => 'actual-target-request-and-attachment', 'all_business_rows_unchanged' => true]);
        }
    }

    public static function transports(): array { return ['ajax' => ['admin-ajax.php'], 'post' => ['admin-post.php']]; }

    /** @dataProvider transports */
    public function test_delivery_wrong_attempts_lockout_reissue_generation_and_consumed_code_replay(string $transport): void {
        $fixture = new Task4ManagedFixture('flow_deliver');
        $originalCode = Task5HttpRiskFixture::code($fixture);
        $generation = $fixture->case()['data']['delivery']['generation'];
        self::assertSame(1, $generation);
        $money = Task5HttpRiskFixture::money($fixture);
        $foreign = new Task4ManagedFixture('flow_deliver');
        $foreignCode = Task5HttpRiskFixture::code($foreign);
        // Snapshot all financial and Woo/Dokan facts only after both fixtures have been created.
        $allMoney = Task5HttpRiskFixture::allMoney();
        for ($issuance = 0; $issuance < 3 && $foreignCode === $originalCode; ++$issuance) {
            [$http] = Task5HttpRiskFixture::post($foreign, 'flow_reissue_code', 'admin', $transport);
            $this->assertAccepted($http, $transport, $foreign->users['admin']);
            self::assertSame($allMoney, Task5HttpRiskFixture::allMoney(), 'Foreign reissue changed global financial/order/evidence facts.');
            $foreignCode = Task5HttpRiskFixture::code($foreign);
        }
        self::assertNotSame($originalCode, $foreignCode);
        // The real target code and nonce cannot authorize a different vendor.
        $otherOwnerBefore = Task4ManagedFixture::snapshot();
        [$otherVendor] = Task5HttpRiskFixture::post($fixture, 'flow_deliver', 'vendor_2', $transport, ['code' => $originalCode]);
        self::assertSame(403, $otherVendor['status'], $otherVendor['body']);
        if ($transport === 'admin-ajax.php') {
            $json = json_decode($otherVendor['body'], true, 32, JSON_THROW_ON_ERROR);
            self::assertFalse($json['success']);
            self::assertSame(['message' => 'درخواست معتبر یا مجاز نیست؛ صفحه را تازه کنید.'], $json['data']);
        } else {
            self::assertStringContainsString('درخواست معتبر یا مجاز نیست.', html_entity_decode(strip_tags($otherVendor['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        self::assertSame($otherOwnerBefore, Task4ManagedFixture::snapshot());
        $foreignBefore = $foreign->case(); $foreignMoney = Task5HttpRiskFixture::money($foreign);
        // Deterministically different valid-shape code; never assume a fixed code is wrong.
        $wrongCode = $originalCode === '000000' ? '000001' : '000000';
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $before = $fixture->case(); $audit = Task5HttpRiskFixture::audit($fixture); $commands = Task5HttpRiskFixture::commands($fixture);
            [$http, $fields] = Task5HttpRiskFixture::post($fixture, 'flow_deliver', 'vendor_1', $transport, ['code' => $attempt === 1 ? $foreignCode : $wrongCode]);
            $this->assertAccepted($http, $transport, $fixture->users['vendor_1'], 'error', $attempt === 5 ? self::LOCKED_MESSAGE : self::WRONG_CODE_MESSAGE);
            $after = $fixture->case();
            self::assertSame($attempt, $after['data']['delivery']['attempts']);
            self::assertSame($attempt === 5 ? 'admin_review' : 'ready_for_pickup', $after['state']);
            self::assertNull($after['data']['delivery']['delivered_at']);
            self::assertSame($before['data']['delivery']['hmac'], $after['data']['delivery']['hmac']);
            self::assertSame($before['data']['delivery']['encrypted_code'], $after['data']['delivery']['encrypted_code']);
            $this->assertCommittedCommand($fixture, $before, $audit, $commands, $fields, 'managed_deliver', $fixture->users['vendor_1']);
            self::assertSame($money, Task5HttpRiskFixture::money($fixture), 'Failed code attempts commit counters/audit, never money.');
            self::assertSame($foreignBefore, $foreign->case()); self::assertSame($foreignMoney, Task5HttpRiskFixture::money($foreign));
            self::assertSame($allMoney, Task5HttpRiskFixture::allMoney(), 'Wrong attempt changed global financial/order/evidence facts.');
            // Replay exactly the old request: stale version must prevent a second attempt.
            $replayBefore = Task4ManagedFixture::snapshot();
            [$replay] = Task5HttpRiskFixture::post($fixture, 'flow_deliver', 'vendor_1', $transport, $fields);
            $this->assertRejected($replay, $transport, $fixture->users['vendor_1'], '', self::FLOW_MESSAGE);
            self::assertSame($replayBefore, Task4ManagedFixture::snapshot());
        }
        // A correct code with a fresh key/current version cannot bypass the fifth-attempt lock.
        $before = Task4ManagedFixture::snapshot();
        [$locked] = Task5HttpRiskFixture::post($fixture, 'flow_deliver', 'vendor_1', $transport, ['code' => $originalCode]);
        $this->assertRejected($locked, $transport, $fixture->users['vendor_1'], '', self::FLOW_MESSAGE);
        self::assertSame($before, Task4ManagedFixture::snapshot());

        // Random issuance can legally repeat digits. If it does, reissue again via real HTTP.
        $newCode = $originalCode;
        for ($issuance = 0; $issuance < 3 && $newCode === $originalCode; ++$issuance) {
            $caseBefore = $fixture->case(); $audit = Task5HttpRiskFixture::audit($fixture); $commands = Task5HttpRiskFixture::commands($fixture);
            [$http, $fields] = Task5HttpRiskFixture::post($fixture, 'flow_reissue_code', 'admin', $transport, ['resume_state' => 'ready_for_pickup']);
            $this->assertAccepted($http, $transport, $fixture->users['admin']);
            $after = $fixture->case();
            self::assertSame($caseBefore['data']['delivery']['generation'] + 1, $after['data']['delivery']['generation']);
            self::assertNotSame($caseBefore['data']['delivery']['hmac'], $after['data']['delivery']['hmac']);
            self::assertSame(0, $after['data']['delivery']['attempts']); self::assertSame('ready_for_pickup', $after['state']);
            $this->assertCommittedCommand($fixture, $caseBefore, $audit, $commands, $fields, 'managed_reissue_code', $fixture->users['admin']);
            self::assertSame($allMoney, Task5HttpRiskFixture::allMoney(), 'Reissue changed global financial/order/evidence facts.');
            $newCode = Task5HttpRiskFixture::code($fixture);
        }
        self::assertNotSame($originalCode, $newCode, 'Three real issuances must supply a distinguishable retired code.');
        self::assertSame($money, Task5HttpRiskFixture::money($fixture));
        $caseBefore = $fixture->case(); $audit = Task5HttpRiskFixture::audit($fixture); $commands = Task5HttpRiskFixture::commands($fixture);
        [$retired, $retiredFields] = Task5HttpRiskFixture::post($fixture, 'flow_deliver', 'vendor_1', $transport, ['code' => $originalCode]);
        $this->assertAccepted($retired, $transport, $fixture->users['vendor_1'], 'error', self::WRONG_CODE_MESSAGE);
        self::assertSame(1, $fixture->case()['data']['delivery']['attempts']); self::assertNull($fixture->case()['data']['delivery']['delivered_at']);
        $this->assertCommittedCommand($fixture, $caseBefore, $audit, $commands, $retiredFields, 'managed_deliver', $fixture->users['vendor_1']);
        self::assertSame($allMoney, Task5HttpRiskFixture::allMoney(), 'Retired-code attempt changed global financial/order/evidence facts.');

        $caseBefore = $fixture->case(); $audit = Task5HttpRiskFixture::audit($fixture); $commands = Task5HttpRiskFixture::commands($fixture);
        [$delivered, $deliveredFields] = Task5HttpRiskFixture::post($fixture, 'flow_deliver', 'vendor_1', $transport, ['code' => $newCode]);
        $this->assertAccepted($delivered, $transport, $fixture->users['vendor_1']);
        $after = $fixture->case();
        self::assertSame('buyer_review', $after['state']); self::assertNotNull($after['data']['delivery']['delivered_at']);
        self::assertNull($after['data']['delivery']['hmac']); self::assertNull($after['data']['delivery']['encrypted_code']);
        self::assertSame(1, $after['data']['delivery']['attempts']);
        $this->assertCommittedCommand($fixture, $caseBefore, $audit, $commands, $deliveredFields, 'managed_deliver', $fixture->users['vendor_1']);
        self::assertSame($money, Task5HttpRiskFixture::money($fixture));
        self::assertSame($allMoney, Task5HttpRiskFixture::allMoney(), 'Delivery consumption changed global financial/order/evidence facts.');
        foreach (['exact-old-request' => $deliveredFields, 'fresh-key-consumed-code' => ['code' => $newCode]] as $name => $overrides) {
            $before = Task4ManagedFixture::snapshot();
            [$replay] = Task5HttpRiskFixture::post($fixture, 'flow_deliver', 'vendor_1', $transport, $overrides);
            $this->assertRejected($replay, $transport, $fixture->users['vendor_1'], '', self::FLOW_MESSAGE);
            self::assertSame($before, Task4ManagedFixture::snapshot(), $name);
        }
        $fixture->validate();
        $this->record('delivery/' . $transport, ['result' => 'native-durable-controls', 'wrong_attempts' => 5,
            'wrong_attempt_response' => 'accepted-command-with-error-notice', 'wrong_attempt_sql_counter_and_audit' => true,
            'wrong_attempt_replay_unchanged' => true, 'fifth_attempt_admin_review' => true, 'locked_correct_code_rejected' => true,
            'foreign_request_code_commits_only_target_failure' => true, 'other_vendor_valid_code_nonce_forbidden' => true,
            'reissue_generation' => $after['data']['delivery']['generation'], 'retired_code_cannot_deliver' => true,
            'current_code_consumed_once' => true, 'consumed_code_replay_unchanged' => true, 'money_rows_unchanged' => true,
            'all_financial_woo_dokan_and_attachment_rows_unchanged_after_every_accepted_transition' => true,
            'all_managed_case_funds_and_final_unchanged' => true,
            'global_stable_tables' => array_values(array_filter(array_keys($allMoney), static fn(string $key): bool => $key !== 'all_managed_case_financial_data'))]);
    }

    private function assertCommittedCommand(Task4ManagedFixture $fixture, array $before, array $auditBefore, array $commandsBefore, array $fields, string $event, int $actor): void {
        $after = $fixture->case();
        self::assertSame($before['version'] + 1, $after['version']);
        $audit = Task5HttpRiskFixture::audit($fixture); $commands = Task5HttpRiskFixture::commands($fixture);
        self::assertCount(count($auditBefore) + 1, $audit); self::assertCount(count($commandsBefore) + 1, $commands);
        $matching = array_values(array_filter($commands, static fn(array $row): bool => $row['key_hash'] === hash('sha256', strtolower($fields['idempotency_key']))));
        self::assertCount(1, $matching); $command = $matching[0];
        self::assertSame($event, $command['event_key']); self::assertSame($actor, (int) $command['actor_id']);
        self::assertSame($after['version'], (int) $command['result_version']);
        $events = array_values(array_filter($audit, static fn(array $row): bool => $row['event_uuid'] === $command['event_uuid']));
        self::assertCount(1, $events); self::assertSame($event, $events[0]['event_key']);
        self::assertSame($actor, (int) $events[0]['actor_user_id']);
        self::assertSame($fixture->id, (int) $events[0]['resource_id']);
    }

    private function assertAccepted(array $http, string $transport, int $actor, string $noticeType = 'success', ?string $message = null): void {
        if ($transport === 'admin-ajax.php') {
            self::assertSame(200, $http['status'], $http['body']);
            $json = json_decode($http['body'], true, 32, JSON_THROW_ON_ERROR);
            self::assertTrue($json['success']); self::assertSame($noticeType, $json['data']['notice_type']);
            if ($message !== null) { self::assertSame($message, $json['data']['message']); }
        } else {
            self::assertSame(303, $http['status'], $http['headers'] . $http['body']);
            self::assertNotSame('', Task4EndpointHttp::header($http, 'Location'));
            $notice = Task4ManagedFixture::notice($actor); self::assertIsArray($notice);
            self::assertSame($noticeType, $notice['type']);
            if ($message !== null) { self::assertSame($message, $notice['message']); }
        }
    }

    private function assertRejected(array $http, string $transport, int $actor, string $code, string $message): void {
        if ($transport === 'admin-ajax.php') {
            self::assertSame(409, $http['status'], $http['body']);
            $json = json_decode($http['body'], true, 32, JSON_THROW_ON_ERROR);
            self::assertFalse($json['success']); self::assertSame(['message' => $message, 'code' => $code], $json['data']);
        } else {
            self::assertSame(303, $http['status'], $http['headers'] . $http['body']);
            self::assertNotSame('', Task4EndpointHttp::header($http, 'Location'));
            self::assertSame(['type' => 'error', 'message' => $message], Task4ManagedFixture::notice($actor));
        }
    }

    private function assertEvidenceDenied(array $http): void {
        self::assertSame(403, $http['status'], $http['body']);
        self::assertStringContainsString('مدرک خصوصی در دسترس نیست یا مالک و هدف آن معتبر نیست.',
            html_entity_decode(strip_tags($http['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function record(string $key, array $row): void {
        self::$evidence[$key] = $row;
        $path = __DIR__ . '/../../evidence/task5-http-risk-controls.json';
        if (!is_dir(dirname($path))) { self::assertTrue(mkdir(dirname($path), 0755, true)); }
        $json = wp_json_encode(['scope' => 'additive-authenticated-HTTP-risk-controls', 'native_http' => true,
            'dependency_doubles' => false, 'guest_matrix_gaps_closed' => false, 'results' => self::$evidence], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertSame(strlen($json), file_put_contents($path, $json, LOCK_EX));
    }
}
