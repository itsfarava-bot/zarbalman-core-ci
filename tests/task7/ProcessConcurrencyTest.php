<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/Task7ProcessHarness.php';
require_once __DIR__ . '/../support/Task5FinancialFixture.php';
require_once __DIR__ . '/../support/Task4ManagedFixture.php';

use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Infrastructure\Schema;

/** Real WordPress/Woo/SQL separate-process service races. Provider responses are explicit doubles.
 * Task5 FinancialDoubleBackedTest separately covers the actual deposit HTTP callback race.
 */
final class ProcessConcurrencyTest extends TestCase {
    private ?Task5DependencyDoubles $doubles = null;
    private array $previousOptions = [];

    protected function setUp(): void {
        parent::setUp();
        foreach (['woocommerce_currency' => 'IRT', 'woocommerce_weight_unit' => 'kg'] as $key => $value) {
            $missing = new stdClass(); $previous = get_option($key, $missing);
            $this->previousOptions[$key] = ['exists' => $previous !== $missing, 'value' => $previous === $missing ? null : $previous];
            update_option($key, $value, false);
        }
    }

    protected function tearDown(): void {
        try { $this->doubles?->restore(); }
        finally {
            $this->doubles = null;
            foreach ($this->previousOptions as $key => $previous) {
                if ($previous['exists']) { update_option($key, $previous['value'], false); } else { delete_option($key); }
            }
            wp_set_current_user(0); parent::tearDown();
        }
    }

    private function install(): Task5DependencyDoubles {
        return $this->doubles = Task5DependencyDoubles::install(['supporting' => true]);
    }

    private static function evidence(string $scenario, array $facts): void {
        $path = __DIR__ . '/../../evidence/task7-process-' . $scenario . '.json';
        self::assertNotFalse(file_put_contents($path, wp_json_encode([
            'scenario' => $scenario, 'real_wordpress' => true, 'real_woocommerce' => true, 'real_sql' => true,
            'real_provider_called' => false, 'commercial_dependency_native' => false,
            'service_boundary' => true, 'http_boundary' => false,
            'limitations' => 'Two workers rendezvous before real GET_LOCK; no lock-wait duration or commercial provider compatibility claim.',
            'facts' => $facts,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)));
    }

    private static function audit(int $requestId, array $events): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_key,from_state,to_state FROM %i WHERE resource_type='request' AND resource_id=%d ORDER BY id",
            (new Schema($wpdb))->audit_table(), $requestId), ARRAY_A);
        self::assertIsArray($rows); self::assertSame('', $wpdb->last_error);
        return array_values(array_filter($rows, static fn (array $row): bool => in_array($row['event_key'], $events, true)));
    }

    private static function orders(int $requestId): array {
        global $wpdb;
        $ids = wc_get_orders(['limit' => -1, 'return' => 'ids', 'type' => 'shop_order',
            'meta_query' => [['key' => '_zbm_request_id', 'value' => (string) $requestId, 'compare' => '=']]]);
        // Task4's historical managed fixtures bind through the canonical request
        // rather than managed-order meta. Include that real order as well.
        $linked = (int) $wpdb->get_var($wpdb->prepare('SELECT wc_order_id FROM %i WHERE id=%d',
            (new Schema($wpdb))->purchase_requests_table(), $requestId));
        self::assertSame('', $wpdb->last_error);
        if ($linked > 0) { self::assertInstanceOf(WC_Order::class, wc_get_order($linked)); $ids[] = $linked; }
        $ids = array_values(array_unique(array_map('intval', $ids))); sort($ids); return $ids;
    }

    private static function flow(Task4ManagedFixture $fixture, string $operation, string $role = 'vendor', ?int $actor = null): array {
        return ['kind' => 'flow', 'request_id' => $fixture->id, 'version' => $fixture->case()['version'],
            'actor_id' => $actor ?? $fixture->users['vendor_1'], 'role' => $role, 'key' => wp_generate_uuid4(),
            'operation' => $operation, 'payload' => ['line_id' => (string) array_key_first($fixture->case()['data']['lines']),
                'reason' => 'Synthetic process concurrency decision', 'code' => $fixture->extra['code'] ?? '']];
    }

    private static function assertOneWinner(array $race): array {
        $winners = array_values(array_filter($race['workers'], static fn (array $row): bool => $row['success']));
        self::assertCount(1, $winners, 'Conflicting same-version commands must commit exactly once.');
        $losers = array_values(array_filter($race['workers'], static fn (array $row): bool => !$row['success']));
        self::assertCount(1, $losers);
        self::assertSame('zbm_managed_stale', $losers[0]['error'], 'The contender must lose on the native locked version check.');
        return $winners[0];
    }

    public function test_duplicate_seller_acceptance_in_two_php_processes_creates_one_order_and_decision(): void {
        global $wpdb;
        $this->install();
        // Reuse Task5's legal phone-only buyer/product/encrypted delivery setup.
        // Its already accepted request remains separate from the fresh race row.
        $source = new Task5FinancialFixture(1000, 100, 0, 2, false);
        $schema = new Schema($wpdb);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $schema->purchase_requests_table(), $source->id), ARRAY_A);
        self::assertIsArray($row); self::assertSame('', $wpdb->last_error);
        unset($row['id']);
        $row = array_replace($row, ['public_id' => wp_generate_uuid4(), 'request_number' => null, 'current_state' => 'supplier_review',
            'version' => 0, 'wc_order_id' => null, 'payment_ready' => 0, 'accepted_at_gmt' => null,
            'stock_reserved' => 0, 'stock_released' => 0,
            'deposit_deadline_gmt' => null, 'supplier_deadline_gmt' => gmdate('Y-m-d H:i:s', time() + 3600)]);
        self::assertSame(1, $wpdb->insert($schema->purchase_requests_table(), $row), $wpdb->last_error);
        $id = (int) $wpdb->insert_id;
        $spec = ['kind' => 'accept', 'request_id' => $id, 'actor_id' => $source->users['vendor_1'], 'version' => 0, 'key' => wp_generate_uuid4()];
        self::assertSame([], self::orders($id));
        $race = Task7ProcessHarness::run([$spec, $spec]);
        foreach ($race['workers'] as $worker) { self::assertTrue($worker['success'], 'Duplicate acceptance returns the one current request.'); }
        $request = $source->repos['requests']->find($id); self::assertNotNull($request);
        self::assertSame('deposit_pending', $request->state()); self::assertTrue($request->paymentReady());
        self::assertSame([$request->wcOrderId()], self::orders($id));
        $events = self::audit($id, ['supplier_accepted_request']); self::assertCount(1, $events);
        $payments = $wpdb->get_results($wpdb->prepare('SELECT id,phase,status FROM %i WHERE request_id=%d', $schema->payments_table(), $id), ARRAY_A);
        self::assertIsArray($payments); self::assertSame('', $wpdb->last_error); self::assertCount(1, $payments);
        self::assertSame('deposit', $payments[0]['phase']); self::assertSame('expected', $payments[0]['status']);
        self::evidence('duplicate-seller-accept', ['race' => $race, 'orders' => 1, 'acceptance_events' => count($events), 'deposit_intents' => count($payments)]);
    }

    public function test_duplicate_balance_callbacks_in_two_php_processes_capture_one_payment_and_cash_event(): void {
        global $wpdb;
        $doubles = $this->install();
        $fixture = new Task4ManagedFixture('spec4_reverify');
        $case = $fixture->case(); $case['state'] = 'balance_due'; $case['data']['spec4']['substate'] = 'balance_due';
        $case['deadline_gmt'] = gmdate('Y-m-d H:i:s', time() + 7200); $fixture->save($case);
        $paymentId = $case['data']['spec4']['payment_id']; $schema = new Schema($wpdb);
        self::assertSame(1, $wpdb->update($schema->payments_table(), [
            'merchant_fingerprint' => hash('sha256', 'zarinpal-production|TASK5-SYNTHETIC-NOT-A-PROVIDER-CREDENTIAL'),
        ], ['id' => $paymentId]));
        $payment = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $schema->payments_table(), $paymentId), ARRAY_A);
        self::assertIsArray($payment); self::assertSame('', $wpdb->last_error);
        $reference = 2000000000000000 + $fixture->id;
        $doubles->configure(['supporting' => true, 'responses' => ['verify.json' => ['status' => 200,
            'body' => wp_json_encode(['data' => ['code' => 100, 'ref_id' => $reference], 'errors' => []], JSON_THROW_ON_ERROR),
            'expected_body' => ['amount' => 9000, 'authority' => $payment['authority'],
                'merchant_id' => 'TASK5-SYNTHETIC-NOT-A-PROVIDER-CREDENTIAL']]]]);
        $spec = ['kind' => 'balance', 'request_id' => $fixture->id, 'payment_id' => $paymentId,
            'offer' => $case['data']['spec4']['offer_id'], 'authority' => $payment['authority']];
        $offset = count($doubles->calls());
        $race = Task7ProcessHarness::run([$spec, $spec]);
        self::assertGreaterThanOrEqual(1, count(array_filter($race['workers'], static fn (array $row): bool => $row['success'])));
        $after = $fixture->case(); self::assertSame('completed', $after['state']);
        self::assertSame(900, $after['data']['funds']['balance']['amount']); self::assertSame($paymentId, $after['data']['funds']['balance']['payment_id']);
        $payments = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE request_id=%d AND phase='balance'", $schema->payments_table(), $fixture->id), ARRAY_A);
        self::assertIsArray($payments); self::assertSame('', $wpdb->last_error); self::assertCount(1, $payments);
        self::assertSame('captured', $payments[0]['status']); self::assertSame(900, (int) $payments[0]['paid_amount_toman']);
        self::assertSame((string) $reference, $payments[0]['reference']);
        $ledger = $wpdb->get_results($wpdb->prepare('SELECT entry_type,amount_toman FROM %i WHERE request_id=%d AND payment_id=%d ORDER BY id', $schema->ledger_table(), $fixture->id, $paymentId), ARRAY_A);
        self::assertIsArray($ledger); self::assertSame('', $wpdb->last_error); self::assertCount(3, $ledger);
        $amounts = array_map('intval', array_column($ledger, 'amount_toman', 'entry_type')); ksort($amounts);
        self::assertSame(['employer_cash_received' => 900, 'platform_share_provisional' => 90, 'vendor_share_provisional' => 810], $amounts);
        $events = self::audit($fixture->id, ['spec4_balance_verified']); self::assertCount(1, $events);
        $calls = array_values(array_filter(array_slice($doubles->calls(), $offset), static fn (array $row): bool => $row['path'] === 'verify.json'));
        self::assertCount(1, $calls); self::assertTrue($calls[0]['expected_binding_matches']); self::assertSame(9000, $calls[0]['amount']);
        self::assertContains($calls[0]['pid'], array_column($race['workers'], 'pid'));
        self::assertCount(1, self::orders($fixture->id));
        self::evidence('duplicate-balance-callback', ['race' => $race, 'captured_balance_payments' => 1, 'cash_receipts' => 1,
            'ledger_amounts' => $amounts, 'balance_verified_events' => count($events), 'provider_double_verifications' => count($calls), 'orders' => 1]);
    }

    public function test_simultaneous_delivery_code_consumption_has_one_commit_and_no_reusable_code(): void {
        $fixture = new Task4ManagedFixture('flow_deliver'); $before = $fixture->case();
        self::assertNotNull($before['data']['delivery']['hmac']); self::assertNotEmpty($fixture->extra['code']);
        $first = self::flow($fixture, 'deliver'); $second = self::flow($fixture, 'deliver');
        $race = Task7ProcessHarness::run([$first, $second]); self::assertOneWinner($race);
        $after = $fixture->case(); self::assertSame($before['version'] + 1, $after['version']);
        self::assertSame('buyer_review', $after['state']); self::assertNotNull($after['data']['delivery']['delivered_at']);
        self::assertNull($after['data']['delivery']['hmac']); self::assertNull($after['data']['delivery']['encrypted_code']);
        self::assertSame($before['data']['delivery']['generation'], $after['data']['delivery']['generation']);
        $events = self::audit($fixture->id, ['managed_deliver']); self::assertCount(1, $events);
        self::assertCount(1, self::orders($fixture->id));
        self::evidence('simultaneous-code-consume', ['race' => $race, 'version_delta' => 1, 'delivery_events' => count($events),
            'code_hmac_cleared' => true, 'encrypted_code_cleared' => true, 'orders' => 1]);
    }

    public function test_conflicting_same_version_status_commands_have_one_winner_and_one_audit_event(): void {
        $fixture = new Task4ManagedFixture('flow_ready_pickup'); $before = $fixture->case();
        self::assertSame('preparing', $before['state']); self::assertSame(0, $before['data']['delivery']['generation']);
        $ready = self::flow($fixture, 'ready_pickup');
        $reopen = self::flow($fixture, 'reopen_preparation', 'admin', $fixture->users['admin']);
        $race = Task7ProcessHarness::run([$ready, $reopen]); $winner = self::assertOneWinner($race);
        $after = $fixture->case(); self::assertSame($before['version'] + 1, $after['version']); self::assertSame($winner['state'], $after['state']);
        $events = self::audit($fixture->id, ['managed_ready_pickup', 'managed_reopen_preparation']); self::assertCount(1, $events);
        if ($events[0]['event_key'] === 'managed_ready_pickup') {
            self::assertSame('ready_for_pickup', $after['state']); self::assertSame(1, $after['data']['delivery']['generation']);
            self::assertNotNull($after['data']['delivery']['hmac']);
        } else {
            self::assertSame('preparing', $after['state']); self::assertSame(0, $after['data']['delivery']['generation']);
            self::assertNull($after['data']['delivery']['hmac']);
            foreach ($after['data']['lines'] as $line) { self::assertNull($line['vendor_tenths']); }
        }
        self::assertCount(1, self::orders($fixture->id));
        self::evidence('conflicting-status', ['race' => $race, 'version_delta' => 1, 'winning_event' => $events[0]['event_key'],
            'final_state' => $after['state'], 'transition_events' => count($events), 'orders' => 1]);
    }
}
