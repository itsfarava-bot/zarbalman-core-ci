<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/Task5FinancialFixture.php';

use PHPUnit\Framework\TestCase;

/** Native callback with a previously issued identity; provider transport is doubled. */
final class DependencyPaymentTest extends TestCase {
    public function test_missing_yith_preserves_independent_verification_of_an_existing_payment(): void {
        $doubles = Task5DependencyDoubles::install(['supporting' => true]);
        try {
            $fixture = new Task5FinancialFixture();
            $doubles->configure(['supporting' => true, 'yith' => false, 'gateway' => true,
                'callback_probe_order_id' => $fixture->orderId,
                'responses' => ['verify.json' => ['status' => 200,
                    'body' => wp_json_encode(['data' => ['code' => 100, 'ref_id' => (int) $fixture->reference], 'errors' => []], JSON_THROW_ON_ERROR),
                    'expected_body' => ['amount' => 1000, 'authority' => $fixture->authority,
                        'merchant_id' => 'TASK5-SYNTHETIC-NOT-A-PROVIDER-CREDENTIAL']]]]);
            [$cookie] = CandidateHttp::auth($fixture->users['admin'], 'task7-payment-health');
            $facts = $doubles->facts($cookie);
            self::assertFalse($facts['report']['yith']['ok']);
            self::assertNull($facts['verification_error']); self::assertNotNull($facts['deposit_error']);
            self::assertSame(['yith'], array_keys(array_filter($facts['report'], static fn(array $row): bool => !$row['ok'])));
            $before = count($doubles->calls());
            $http = Task4EndpointHttp::get('', $fixture->callback());
            self::assertSame(302, $http['status']);
            $captured = $fixture->assertCaptured(10, 90);
            $calls = array_slice($doubles->calls(), $before);
            self::assertCount(1, $calls); self::assertSame('verify.json', $calls[0]['path']);
            self::assertSame(1000, $calls[0]['amount']); self::assertTrue($calls[0]['expected_binding_matches']);
            self::assertTrue($calls[0]['merchant_is_synthetic']);
            $events = $doubles->events($fixture->id); self::assertCount(1, $events);
            $replay = Task4EndpointHttp::get('', $fixture->callback());
            self::assertSame(200, $replay['status']);
            self::assertSame($captured, $fixture->assertCaptured(10, 90));
            self::assertSame($events, $doubles->events($fixture->id));
            self::assertSame($before + 1, count($doubles->calls()));
            self::assertNotFalse(file_put_contents(__DIR__ . '/../../evidence/task7-yith-payment.json',
                wp_json_encode(['actual_http' => true, 'dependency_mode' => 'double-backed', 'real_provider_called' => false,
                    'yith_missing' => true, 'new_order_gate_blocked' => true, 'existing_payment_verified' => true,
                    'captured_payments' => 1, 'ledger_legs' => 3, 'paid_events' => 1, 'replay_preserved' => true], JSON_THROW_ON_ERROR)));
        } finally { $doubles->restore(); wp_set_current_user(0); }
    }
}
