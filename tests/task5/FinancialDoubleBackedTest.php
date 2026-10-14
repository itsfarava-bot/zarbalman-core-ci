<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/Task5FinancialFixture.php';
require_once __DIR__ . '/../support/Task5FinancialHttp.php';

use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Application\DepositPaymentService;
use Zarbalman\Core\Domain\Finance\{DepositAllocation, ManualSettlementPolicy};
use Zarbalman\Core\Domain\ManagedFlow\Amounts;
use Zarbalman\Core\Domain\PurchaseRequest\PricingSnapshot;
use Zarbalman\Core\Infrastructure\Schema;
use Zarbalman\Core\Integration\ZarinpalAdapter;

/** Core production handlers + real WP/Woo/MySQL + explicit commercial/HTTP doubles.
 * This suite does not establish commercial YITH/Zarinpal runtime compatibility.
 */
final class FinancialDoubleBackedTest extends TestCase {
    private ?Task5DependencyDoubles $doubles = null;

    protected function tearDown(): void {
        try { $this->doubles?->restore(); }
        finally { $this->doubles = null; wp_set_current_user(0); }
    }
    private function install(): Task5DependencyDoubles {
        $this->doubles = Task5DependencyDoubles::install(['supporting' => true]);
        [$cookie] = CandidateHttp::auth(Task5FinancialFixture::users()['admin'], 'unused-task5-finance-facts');
        $facts = $this->doubles->facts($cookie);
        self::assertNull($facts['request_error']); self::assertNull($facts['deposit_error']); self::assertNull($facts['verification_error']);
        self::assertTrue($facts['gateway_registered']); self::assertSame([0], $facts['managed_callback_priorities']);
        self::assertTrue($facts['online_available']); wp_set_current_user(0);
        return $this->doubles;
    }
    private static function verifyCalls(Task5DependencyDoubles $doubles, int $offset = 0): array {
        return array_values(array_filter(array_slice($doubles->calls(), $offset), static fn (array $call): bool => $call['path'] === 'verify.json'));
    }
    private static function safeCallFacts(array $calls): array {
        return array_map(static fn (array $call): array => array_intersect_key($call, array_flip([
            'path', 'pid', 'at', 'amount', 'expected_binding_matches', 'authority_present', 'merchant_is_synthetic', 'dependency_mode',
        ])), $calls);
    }
    /** Closed response classifier: never persist HTML, cookies or provider keys. */
    private static function safeResponseFacts(array $http): array {
        $body = html_entity_decode(strip_tags($http['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $reasons = [
            'Two distinct callback workers did not enter the barrier.' => 'two_worker_barrier_timeout',
            'Callback barrier read failed closed.' => 'barrier_read_failed',
            'هویت callback یا درخواست متناظر معتبر نیست؛ هیچ استعلام مالی انجام نشد.' => 'callback_identity_invalid',
            'پرداخت دیگری برای این درخواست در حال بررسی است؛ چند لحظه بعد دوباره وضعیت درخواست را مشاهده کنید.' => 'callback_lock_busy',
            'این درخواست در وضعیت قابل پرداخت نیست و هیچ استعلام مالی انجام نشد.' => 'request_not_payable',
            'ثبت امن ورود پرداخت انجام نشد؛ برای جلوگیری از برداشت تکراری، عملیات متوقف شد.' => 'callback_receipt_failed',
            'زرین‌پال این پرداخت را قطعی اعلام نکرد؛ می‌توانید از صفحه درخواست دوباره تلاش کنید.' => 'provider_rejected',
            'نتیجهٔ پرداخت موقتاً قابل استعلام نیست؛ شناسه callback ذخیره شد و بازیابی خودکار ادامه پیدا می‌کند.' => 'verification_unavailable',
            'پرداخت قطعی است اما ثبت داخلی آن کامل نشد؛ پرونده برای بازیابی امن نگه داشته شد.' => 'verified_journal_failed',
            'پرداخت تأیید شده است اما ادامهٔ امن سفارش نیاز به بازیابی خودکار یا بررسی مدیریت دارد.' => 'paid_order_recovery_required',
        ];
        $reason = $http['status'] === 302 ? 'success_redirect' : ($http['status'] === 200 ? 'callback_passthrough' : 'unclassified');
        foreach ($reasons as $message => $code) { if (str_contains($body, $message)) { $reason = $code; break; } }
        return ['status' => $http['status'], 'duration_seconds' => $http['duration_seconds'] ?? null, 'response_reason' => $reason];
    }
    private function observe(string $scenario, Task5FinancialFixture $fixture, Task5DependencyDoubles $doubles, array $responses): void {
        $observation = [
            'http' => array_map(self::safeResponseFacts(...), $responses),
            'simulated_verify_calls' => self::safeCallFacts(self::verifyCalls($doubles)),
            'callback_probes' => array_values(array_filter($doubles->probes(), static fn (array $probe): bool => $probe['order_id'] === $fixture->orderId)),
            'native_paid_hook_events' => $doubles->events($fixture->id),
        ];
        // Keep transport witnesses even if a subsequent native read fails.
        Task5FinancialFixture::evidence('observation-' . $scenario, $observation);
        $observation['cache'] = $fixture->cacheObservation();
        $observation['financial'] = $fixture->facts();
        Task5FinancialFixture::evidence('observation-' . $scenario, $observation);
    }
    private function performObservedCallback(string $scenario, Task5FinancialFixture $fixture, Task5DependencyDoubles $doubles, string $status = 'OK'): array {
        $started = microtime(true);
        $http = Task5FinancialHttp::callback($fixture->callback($status));
        $http['duration_seconds'] = microtime(true) - $started;
        $this->observe($scenario, $fixture, $doubles, [$http]);
        return $http;
    }

    public function test_integer_10_percent_boundaries_and_rejected_minimum_are_exact(): void {
        self::assertSame(8, PHP_INT_SIZE);
        // Hardcoded independent contract oracle; these are not values read from Core.
        $cases = [
            [1, 0, null, null], [9, 1, null, null], [10, 1, null, null], [11, 1, null, null], [94, 9, null, null],
            [95, 10, 1, 9], [99, 10, 1, 9], [104, 10, 1, 9], [105, 11, 1, 10],
            [1000005, 100001, 10000, 90001], [123456789, 12345679, 1234568, 11111111],
            [999999999995, 100000000000, 10000000000, 90000000000],
            [999999999999, 100000000000, 10000000000, 90000000000],
            [1000000000000, 100000000000, 10000000000, 90000000000],
            [1000000000005, 100000000001, 10000000000, 90000000001],
        ];
        $doubles = $this->install(); $evidence = [];
        foreach ($cases as [$gross, $candidate, $platform, $vendor]) {
            self::assertSame($candidate, intdiv($gross + 5, 10), 'Independent half-up integer oracle.');
            if ($platform === null) {
                $before = CandidateFixture::financialSnapshot();
                try { PricingSnapshot::fromProduct(1, $gross, 10); self::fail('Deposit below ten Toman must be rejected.'); }
                catch (InvalidArgumentException $error) { self::assertSame('Calculated deposit must be at least ten Toman.', $error->getMessage()); }
                self::assertSame($before, CandidateFixture::financialSnapshot());
                $evidence[] = ['gross' => $gross, 'half_up_candidate' => $candidate, 'snapshot' => 'rejected_minimum_10', 'business_delta' => false];
                continue;
            }
            $pricing = PricingSnapshot::fromProduct(1, $gross, 10);
            self::assertSame($gross, $pricing->estimatedGrossToman()); self::assertSame($candidate, $pricing->depositToman());
            self::assertSame($gross, $pricing->finalTotalToman(10));
            self::assertSame($gross, Amounts::finalTotal($gross, 10, 10));
            self::assertSame(['commission' => $candidate, 'net' => $gross - $candidate], Amounts::commission($gross));
            $split = DepositAllocation::fromPaidAmount($candidate);
            self::assertSame($platform, $split->platformShareToman()); self::assertSame($vendor, $split->vendorShareToman());
            self::assertSame($gross, $candidate + Amounts::remaining($gross, $candidate));
            $fixture = new Task5FinancialFixture($gross, $candidate); $fixture->configure($doubles);
            $offset = count($doubles->calls());
            self::assertSame(302, $this->performObservedCallback('integer-' . $gross, $fixture, $doubles)['status']);
            $facts = $fixture->assertCaptured($platform, $vendor); $calls = self::verifyCalls($doubles, $offset);
            self::assertCount(1, $calls); self::assertIsInt($calls[0]['amount']); self::assertSame($candidate * 10, $calls[0]['amount']);
            self::assertTrue($calls[0]['expected_binding_matches']); self::assertTrue($calls[0]['merchant_is_synthetic']);
            $evidence[] = ['gross' => $gross, 'deposit' => $candidate, 'platform' => $platform, 'vendor' => $vendor,
                'gateway_rial' => $calls[0]['amount'], 'payment_count' => count($facts['payment']), 'ledger_legs' => count($facts['ledger']),
                'cash_receipts' => 1, 'persisted_paid_events' => count($facts['paid_events']), 'orders' => count($facts['order_ids'])];
        }
        Task5FinancialFixture::evidence('integer-boundaries', $evidence);
    }

    public function test_checked_integer_overflows_and_exact_weight_reject_float(): void {
        self::assertSame(8, PHP_INT_SIZE);
        foreach ([static fn () => PricingSnapshot::fromProduct(2, PHP_INT_MAX, 10),
            static fn () => PricingSnapshot::fromProduct(1, PHP_INT_MAX, 10),
            static fn () => PricingSnapshot::fromProduct(1, PHP_INT_MAX - 10, 10, 20),
            static fn () => Amounts::multiply(PHP_INT_MAX, 2)] as $operation) {
            try { $operation(); self::fail('Integer overflow must throw before PHP can produce float.'); }
            catch (OverflowException) { self::assertTrue(true); }
        }
        self::assertSame(12345678901, Amounts::weight('1234567890.1'));
        foreach ([1.0, '1.01', '1e3', '0.0'] as $badWeight) {
            try { Amounts::weight($badWeight); self::fail('Nonexact or nonpositive weight must be rejected.'); }
            catch (InvalidArgumentException) { self::assertTrue(true); }
        }
        self::assertTrue(ManualSettlementPolicy::nativeAmountAllowed(999999999999999));
        self::assertFalse(ManualSettlementPolicy::nativeAmountAllowed(1000000000000000));
        // Actual financial API conversion points exist in both Dokan adapters.
        // These bounded PHP casts establish integer representability only;
        // they do not execute or validate the commercial/native model storage.
        foreach ([999999999995, 999999999999, 1000000000000, 1000000000005,
            999999999999999, 9007199254740991] as $boundedNativeAmount) {
            $nativeFloat = (float) $boundedNativeAmount;
            self::assertIsFloat($nativeFloat); self::assertSame($boundedNativeAmount, (int) $nativeFloat);
        }
        try { DepositAllocation::fromPaidAmount(intdiv(PHP_INT_MAX - 50, 10) + 1); self::fail('Allocation multiplication overflow must reject.'); }
        catch (InvalidArgumentException) { self::assertTrue(true); }
    }

    public function test_yith_legacy_exact_decimals_and_shipping_inclusive_core_projection(): void {
        $doubles = $this->install();
        $legacy = new Task5FinancialFixture(99, 10, 0, 1, false); $order = $legacy->order(); $item = array_values($order->get_items())[0];
        self::assertSame('9.9', $item->get_meta('_deposit_value', true)); self::assertSame('89.1', $item->get_meta('_deposit_balance', true));
        self::assertSame(10, (int) $item->get_meta('_deposit_rate', true)); self::assertTrue((bool) $item->get_meta('_deposit', true));
        self::assertMatchesRegularExpression('/\A10(?:\.0+)?\z/', (string) $order->get_total());
        $new = new Task5FinancialFixture(95, 11, 10); $order = $new->order(); $item = array_values($order->get_items())[0];
        self::assertSame(105, $new->request()->estimatedGrossToman()); self::assertSame(11, $new->request()->depositToman());
        self::assertSame('core', $order->get_meta('_zbm_deposit_engine', true)); self::assertFalse((bool) $item->get_meta('_deposit', true));
        self::assertSame('', $item->get_meta('_deposit_value', true));
        self::assertFalse(apply_filters('yith_wcdp_virtual_on_deposit', true, $order));
        self::assertTrue(apply_filters('yith_wcdp_skip_suborder_creation', false, $item->get_id(), $item, $order->get_id(), $order, $item->get_product()));
        self::assertFalse($item->get_product()->is_virtual());
        $new->configure($doubles); self::assertSame(302, $this->performObservedCallback('yith-projection', $new, $doubles)['status']);
        $facts = $new->assertCaptured(1, 10);
        self::assertSame([], wc_get_orders(['parent' => $new->orderId, 'return' => 'ids', 'limit' => -1]));
        Task5FinancialFixture::evidence('yith-projection', ['legacy_unit_deposit' => '9.9', 'legacy_unit_balance' => '89.1',
            'legacy_authoritative_total' => 10, 'v2_gross' => 105, 'v2_deposit' => 11, 'physical_guard' => true,
            'balance_suborder_guard' => true, 'observed_child_orders' => 0, 'financial' => $facts,
            'limitation' => 'Core projection and guards executed with explicit YITH class-presence double; commercial YITH behavior is unproved.']);
    }

    public function test_actual_http_sequential_duplicate_persists_one_payment_three_legs_one_event(): void {
        $doubles = $this->install(); $fixture = new Task5FinancialFixture(); $fixture->configure($doubles);
        self::assertSame(302, $this->performObservedCallback('sequential-first', $fixture, $doubles)['status']);
        $first = $fixture->assertCaptured(10, 90); $callsBefore = count($doubles->calls());
        $firstEvents = $doubles->events($fixture->id); self::assertCount(1, $firstEvents, 'The first actual HTTP callback must announce the native paid hook once.');
        $secondHttp = $this->performObservedCallback('sequential-replay', $fixture, $doubles);
        self::assertSame(200, $secondHttp['status']);
        self::assertSame($first, $fixture->assertCaptured(10, 90)); self::assertSame($callsBefore, count($doubles->calls()));
        self::assertSame($firstEvents, $doubles->events($fixture->id), 'Paid HTTP replay must not announce the native paid hook again.');
        // Diagnostic only: direct service replay currently announces twice.
        // This is reported separately from the one-event actual HTTP contract.
        $adapter = Task5FinancialFixture::registered('woocommerce_api_wc_zpal', ZarinpalAdapter::class);
        $service = (new ReflectionProperty($adapter, 'payments'))->getValue($adapter); self::assertInstanceOf(DepositPaymentService::class, $service);
        $announcements = 0;
        $listener = static function (int $id) use ($fixture, &$announcements): void { if ($id === $fixture->id) { ++$announcements; } };
        add_action('zbm_purchase_request_deposit_paid', $listener, PHP_INT_MAX, 1);
        try {
            self::assertTrue($service->recordSuccessfulDeposit($fixture->orderId, $fixture->authority, $fixture->reference));
            self::assertTrue($service->recordSuccessfulDeposit($fixture->orderId, $fixture->authority, $fixture->reference));
        } finally { remove_action('zbm_purchase_request_deposit_paid', $listener, PHP_INT_MAX); }
        self::assertSame(2, $announcements); self::assertSame($first, $fixture->assertCaptured(10, 90));
        Task5FinancialFixture::evidence('sequential-duplicate', ['http_statuses' => [302, $secondHttp['status']], 'financial' => $first,
            'simulated_verify_calls' => self::safeCallFacts(self::verifyCalls($doubles)), 'http_paid_replay_verifies_again' => false,
            'native_http_paid_hook_events' => $firstEvents, 'direct_service_replay_hook_announcements' => $announcements,
            'direct_service_replay_announcements_are_single_event_contract' => false, 'persisted_paid_events' => 1]);
    }

    public function test_actual_http_concurrent_duplicate_proves_distinct_workers_and_prelock_overlap(): void {
        $doubles = $this->install(); $fixture = new Task5FinancialFixture(); $fixture->configure($doubles, 100, true);
        $responses = Task5FinancialHttp::concurrent($fixture->callback());
        $this->observe('concurrent-duplicate', $fixture, $doubles, $responses);
        // Both native order reads observe pending before the lock. The winner
        // captures once; after the lock, DepositCapturePolicy allows only
        // deposit_pending, deposit_expired, admin_review and late_payment_review.
        // The other callback therefore receives the named deposit_paid denial.
        $statuses = array_column($responses, 'status'); sort($statuses); self::assertSame([302, 409], $statuses,
            'Overlapping pending-order callbacks must capture once and deny the already-paid request without a second financial effect.');
        $denied = array_values(array_filter($responses, static fn (array $http): bool => $http['status'] === 409));
        self::assertCount(1, $denied);
        self::assertSame('request_not_payable', self::safeResponseFacts($denied[0])['response_reason']);
        $deadline = microtime(true) + 5;
        do {
            $probes = array_values(array_filter($doubles->probes(), static fn (array $probe): bool => $probe['order_id'] === $fixture->orderId));
            $starts = array_values(array_filter($probes, static fn (array $probe): bool => $probe['event'] === 'start'));
            $ends = array_values(array_filter($probes, static fn (array $probe): bool => $probe['event'] === 'end'));
            $prelockReads = array_values(array_filter($probes, static fn (array $probe): bool => $probe['event'] === 'prelock_read'));
            if (count($ends) >= 2) { break; }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::assertCount(2, $starts); self::assertCount(2, $ends);
        self::assertCount(1, array_unique(array_column($starts, 'epoch')));
        self::assertCount(2, array_unique(array_column($starts, 'pid'))); self::assertCount(2, array_unique(array_column($starts, 'identity')));
        $calls = self::verifyCalls($doubles); self::assertCount(1, $calls);
        self::assertCount(2, $prelockReads, 'Both callback workers must read native pending status before Core acquires its callback lock.');
        self::assertCount(2, array_unique(array_column($prelockReads, 'pid')));
        self::assertCount(1, array_unique(array_column($prelockReads, 'epoch')));
        self::assertSame($starts[0]['epoch'], $prelockReads[0]['epoch']);
        $startPids = array_column($starts, 'pid'); sort($startPids);
        $readPids = array_column($prelockReads, 'pid'); sort($readPids); self::assertSame($startPids, $readPids);
        foreach ($prelockReads as $read) {
            self::assertSame('pending', $read['status'], 'Timing observer must report the actual native status unchanged.');
            self::assertLessThan($calls[0]['at'], $read['at'], 'Each native pending read must precede provider verification.');
        }
        self::assertLessThanOrEqual($calls[0]['at'], max(array_column($starts, 'at')), 'Both workers arrived before provider verification under the native lock.');
        self::assertLessThan(min(array_column($ends, 'at')), max(array_column($starts, 'at')), 'Request lifetimes genuinely overlapped.');
        foreach ($starts as $start) {
            $matching = array_values(array_filter($ends, static fn (array $end): bool => $end['identity'] === $start['identity']));
            self::assertCount(1, $matching); self::assertSame($start['pid'], $matching[0]['pid']); self::assertGreaterThan($start['at'], $matching[0]['at']);
        }
        $facts = $fixture->assertCaptured(10, 90);
        $events = $doubles->events($fixture->id); self::assertCount(1, $events, 'Overlapping callbacks must announce the native paid hook once.');
        Task5FinancialFixture::evidence('concurrent-duplicate', ['transport' => 'curl_multi', 'callback_hook' => 'woocommerce_api_wc_zpal',
            'core_callback_priority' => 0, 'distinct_worker_pids' => array_column($starts, 'pid'), 'prelock_barrier' => true,
            'lifetimes_overlap' => true, 'probes' => $probes, 'http_statuses' => $statuses, 'financial' => $facts,
            'simulated_verify_calls' => self::safeCallFacts($calls),
            'native_paid_hook_events' => $events,
            'prelock_pending_reads' => $prelockReads, 'duplicate_response_reason' => 'request_not_payable',
            'limitation' => 'Overlapping actual registered callbacks and idempotent SQL effects proved; no measured lock-wait duration is claimed.']);
    }

    public function test_amount_mismatch_rejects_before_verify_and_bound_rial_mismatch_cannot_capture(): void {
        global $wpdb;
        $doubles = $this->install(); $fixture = new Task5FinancialFixture(); $fixture->configure($doubles);
        self::assertSame(1, $wpdb->update((new Schema($wpdb))->payments_table(), ['expected_amount_toman' => 101], ['request_id' => $fixture->id]));
        self::assertSame(409, $this->performObservedCallback('amount-intent-mismatch', $fixture, $doubles)['status']);
        $corrupt = $fixture->assertNoCash(); self::assertSame('authority_issued', $corrupt['payment'][0]['status']);
        self::assertCount(0, self::verifyCalls($doubles));
        $bound = new Task5FinancialFixture(); $bound->configure($doubles, 100, false, 1001);
        self::assertSame(503, $this->performObservedCallback('amount-bound-mismatch', $bound, $doubles)['status']);
        $boundFacts = $bound->assertNoCash(); self::assertSame('callback_received_on_time', $boundFacts['payment'][0]['status']);
        $calls = self::verifyCalls($doubles); self::assertCount(1, $calls); self::assertSame(1000, $calls[0]['amount']);
        self::assertFalse($calls[0]['expected_binding_matches']);
        Task5FinancialFixture::evidence('amount-mismatch', ['corrupt_intent_fixture' => true, 'intent_http_status' => 409,
            'intent_facts' => $corrupt, 'binding_http_status' => 503, 'binding_facts' => $boundFacts,
            'simulated_verify_calls' => self::safeCallFacts($calls), 'bound_double_returned_success' => false]);
    }

    public function test_provider_errors_code_rejection_must_release_receipt_without_cash(): void {
        $doubles = $this->install(); $failed = new Task5FinancialFixture(); $failed->configure($doubles, -51);
        // Explicit errors.code rejection keeps the exact negative contract.
        self::assertSame(400, $this->performObservedCallback('provider-errors-envelope', $failed, $doubles)['status']);
        $failedFacts = $failed->assertNoCash(); self::assertSame('authority_issued', $failedFacts['payment'][0]['status']);
        self::assertSame('deposit_pending', $failedFacts['state']); self::assertCount(1, self::verifyCalls($doubles));
        Task5FinancialFixture::evidence('provider-errors-envelope', ['failed_http_status' => 400, 'failed' => $failedFacts]);
    }

    public function test_explicit_normalized_data_code_rejection_releases_receipt_without_cash(): void {
        $doubles = $this->install(); $failed = new Task5FinancialFixture();
        // Separate synthetic envelope exercises the native data.code rejection
        // branch; it makes no claim that this is the provider's canonical payload.
        $doubles->configure(['supporting' => true, 'callback_probe_order_id' => $failed->orderId,
            'responses' => ['verify.json' => ['status' => 200,
                'body' => wp_json_encode(['data' => ['code' => -51], 'errors' => []], JSON_THROW_ON_ERROR),
                'expected_body' => ['amount' => 1000, 'authority' => $failed->authority,
                    'merchant_id' => 'TASK5-SYNTHETIC-NOT-A-PROVIDER-CREDENTIAL']]]]);
        self::assertSame(400, $this->performObservedCallback('normalized-data-code-rejected', $failed, $doubles)['status']);
        $facts = $failed->assertNoCash(); self::assertSame('authority_issued', $facts['payment'][0]['status']);
        self::assertSame('deposit_pending', $facts['state']); self::assertCount(1, self::verifyCalls($doubles));
        Task5FinancialFixture::evidence('normalized-data-code-rejected', ['explicit_normalized_double_envelope' => true,
            'provider_payload_compatibility_proved' => false, 'http_status' => 400, 'financial' => $facts]);
    }

    public function test_provider_cancelled_return_produces_no_cash_or_verification(): void {
        $doubles = $this->install();
        $cancelled = new Task5FinancialFixture(); $cancelled->configure($doubles); $before = $cancelled->facts(); $callsBefore = count($doubles->calls());
        self::assertSame(200, $this->performObservedCallback('cancelled-provider', $cancelled, $doubles, 'NOK')['status']);
        self::assertSame($before, $cancelled->assertNoCash()); self::assertSame($callsBefore, count($doubles->calls()));
        Task5FinancialFixture::evidence('provider-cancelled', ['provider_cancelled_http_status' => 200,
            'cancelled' => $before, 'cancelled_verify_calls' => 0,
            'limitation' => 'Status=NOK returns before Core verification; commercial dependency fallback behavior is unproved.']);
    }

    public function test_unresolved_issued_identity_expiry_enters_review_then_records_late_cash_without_allocations(): void {
        $doubles = $this->install(); $fixture = new Task5FinancialFixture();
        // Native expiry first attempts to recover the issued financial identity.
        // This runner has no definitive verification, so Core preserves review.
        $expiry = $fixture->expireNatively('late_payment_review');
        self::assertSame($fixture->authority, $expiry['after']['payment'][0]['authority']);
        self::assertNotSame('expired', $expiry['after']['payment'][0]['status'], 'Unresolved issued identity must stay held for recovery.');
        $fixture->assertNoCash(); $fixture->configure($doubles);
        self::assertSame(302, $this->performObservedCallback('issued-review-late-first', $fixture, $doubles)['status']);
        $facts = $fixture->assertCaptured(0, 0, true); self::assertCount(1, self::verifyCalls($doubles));
        $callsBefore = count($doubles->calls()); self::assertSame(200, $this->performObservedCallback('issued-review-late-replay', $fixture, $doubles)['status']);
        self::assertSame($facts, $fixture->assertCaptured(0, 0, true)); self::assertSame($callsBefore, count($doubles->calls()));
        Task5FinancialFixture::evidence('late-after-issued-identity-review', ['native_expiry_attempt' => true,
            'native_deposit_expired_transition' => false, 'unresolved_issued_identity_retained_for_review' => true,
            'clock_fixture_deadline_in_past' => true, 'expiry' => $expiry,
            'financial' => $facts, 'cash_receipts' => 1, 'provisional_legs' => 0, 'ordinary_deposit_paid_events' => 0]);
    }

    public function test_native_expiry_without_issued_identity_rejects_forged_callback_without_cash(): void {
        $doubles = $this->install(); $fixture = new Task5FinancialFixture(1000, 100, 0, 2, false);
        $expiry = $fixture->expireNatively(); self::assertSame('expired', $expiry['after']['payment'][0]['status']);
        $fixture->configure($doubles); $before = $fixture->assertNoCash();
        self::assertSame(400, $this->performObservedCallback('native-expired-no-identity', $fixture, $doubles)['status']);
        self::assertSame($before, $fixture->assertNoCash()); self::assertCount(0, self::verifyCalls($doubles));
        Task5FinancialFixture::evidence('native-expired-no-identity', ['native_deposit_expired_transition' => true,
            'issued_identity_present' => false, 'expiry' => $expiry, 'callback_http_status' => 400, 'financial' => $before]);
    }

    public function test_issued_identity_cannot_normally_cancel_but_stale_cancelled_fixture_fails_closed(): void {
        $doubles = $this->install(); $fixture = new Task5FinancialFixture(); $fixture->persistedFixtureFields(['current_state' => 'admin_review']);
        $request = $fixture->request();
        $result = Task5FinancialFixture::service()->cancelUnpaidAdminReview($fixture->id, $fixture->users['admin'], $request->version(), 'Synthetic refused cancellation');
        self::assertInstanceOf(WP_Error::class, $result); self::assertSame('zbm_admin_cancel_paid_or_failed', $result->get_error_code());
        self::assertSame('admin_review', $fixture->request()->state()); $fixture->assertNoCash();
        // Defensive test ONLY: normal lifecycle above explicitly refused this state.
        $fixture->persistedFixtureFields(['current_state' => 'cancelled']); $fixture->configure($doubles); $before = $fixture->facts();
        self::assertSame(409, $this->performObservedCallback('stale-cancelled', $fixture, $doubles)['status']);
        self::assertSame($before, $fixture->assertNoCash()); self::assertCount(0, self::verifyCalls($doubles));
        Task5FinancialFixture::evidence('stale-cancelled-fixture', ['native_cancel_refused_issued_identity' => true,
            'native_error' => $result->get_error_code(), 'stale_corrupt_cancelled_fixture' => true, 'callback_http_status' => 409, 'financial' => $before]);
    }

    public function test_normal_cancellation_without_issued_identity_rejects_forged_callback(): void {
        $doubles = $this->install(); $fixture = new Task5FinancialFixture(1000, 100, 0, 2, false);
        $fixture->persistedFixtureFields(['current_state' => 'admin_review']); $request = $fixture->request();
        $result = Task5FinancialFixture::service()->cancelUnpaidAdminReview($fixture->id, $fixture->users['admin'], $request->version(), 'Synthetic native cancellation before issuance');
        self::assertInstanceOf(\Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest::class, $result);
        self::assertSame('cancelled', $fixture->request()->state()); $fixture->configure($doubles); $before = $fixture->facts();
        self::assertSame(400, $this->performObservedCallback('normal-cancel-no-identity', $fixture, $doubles)['status']);
        self::assertSame($before, $fixture->assertNoCash()); self::assertCount(0, self::verifyCalls($doubles));
        Task5FinancialFixture::evidence('normal-cancel-no-identity', ['admin_review_setup_fixture' => true, 'native_cancel_succeeded' => true,
            'authority_was_issued' => false, 'forged_callback_http_status' => 400, 'financial' => $before]);
    }
}
