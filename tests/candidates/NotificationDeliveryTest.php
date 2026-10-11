<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/CandidateFixture.php';
require_once __DIR__ . '/../support/RecordingSms.php';
use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Infrastructure\{Schema, NotificationWorker, DependencyHealth, ProtectedText, DeliveryDataCrypto, DeliveryCodeSecret, LedgerRepository};
use Zarbalman\Core\Integration\PwSmsAdapter;
use Zarbalman\Core\Notification\{MessageCatalog, RecipientResolver};
use Zarbalman\Core\Domain\ManagedFlow\DeliveryCode;

final class NotificationDeliveryTest extends TestCase {
    public function test_current_delivery_links_send_once_but_retired_secret_notice_is_cancelled(): void {
        global $wpdb;
        PWSMS()->sent = [];
        $schema = new Schema($wpdb);
        foreach (['managed_pickup_available' => 'ready_for_pickup', 'managed_dispatch_available' => 'in_transit'] as $template => $state) {
            $order = wc_create_order(['customer_id' => (int) get_user_by('login', 'zbm_test_buyer_1')->ID]);
            [$request, $r] = CandidateFixture::request(['wc_order_id' => $order->get_id(), 'current_state' => 'deposit_paid']);
            update_user_meta($request->buyerId(), 'billing_phone', '09120000000');
            $now = current_time('mysql', true);
            $uid = wp_generate_uuid4();
            self::assertSame(1, $wpdb->insert($schema->payments_table(), ['request_id' => $request->id(), 'order_id' => $order->get_id(), 'provider' => 'zarinpal', 'phase' => 'deposit', 'expected_amount_toman' => 100, 'paid_amount_toman' => 100, 'status' => 'captured', 'authority' => 'MOCK-' . $uid, 'reference' => 'MOCK-' . $uid, 'idempotency_hash' => hash('sha256', $uid), 'paid_at_gmt' => $now, 'created_at_gmt' => $now, 'updated_at_gmt' => $now]));
            $paymentId = (int) $wpdb->insert_id;
            (new LedgerRepository($wpdb))->append($request->id(), $order->get_id(), $paymentId, 'employer_cash_received', 'employer', 0, 100, 'recorded', 'payment:' . $paymentId . ':cash');
            $case = CandidateFixture::caseFor($request, ['state' => $state]);
            $case['data']['funds']['deposit'] = ['amount' => 100, 'provider' => 'zarinpal', 'payment_id' => $paymentId];
            $protected = new ProtectedText(new DeliveryDataCrypto());
            self::assertTrue($protected->ensureKey());
            $engine = new DeliveryCode(random_bytes(32));
            $secretCode = '012345';
            $case['data']['delivery']['generation'] = 1;
            $case['data']['delivery']['hmac'] = $engine->sign($secretCode, $request->id(), $order->get_id(), 1);
            $case['data']['delivery']['encrypted_code'] = $protected->encrypt('delivery_code', $request->id(), $order->get_id() . ':1', $secretCode);
            $case['data']['delivery']['code_issued_at'] = $now;
            CandidateFixture::saveCase($case);
            self::assertTrue($r['cases']->depositVerified($request, $case));
            $event = wp_generate_uuid4();
            self::assertTrue($r['outbox']->enqueue($event, $request->id(), 'buyer', $request->buyerId(), $template, ['generation' => 1]));
            $worker = new NotificationWorker($r['outbox'], $r['requests'], new RecipientResolver(), new MessageCatalog(), new PwSmsAdapter(new DependencyHealth()), $r['audit'], $r['cases'], $protected, new DeliveryCodeSecret($wpdb));
            $before = count(PWSMS()->sent);
            $worker->dispatch();
            self::assertCount($before + 1, PWSMS()->sent);
            $sent = PWSMS()->sent[$before];
            self::assertStringContainsString('پنل', $sent['message']);
            self::assertStringContainsString('request=' . $request->id(), $sent['message']);
            self::assertStringNotContainsString($secretCode, $sent['message']);
            self::assertSame('sent', $wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE event_uuid=%s', $schema->notification_outbox_table(), $event)));
            $worker->dispatch();
            self::assertCount($before + 1, PWSMS()->sent, 'Dispatch replay must not send again.');
            // Pre-upgrade pending retired notification: exercise actual cancellation, not just policy.
            $retired = wp_generate_uuid4();
            self::assertSame(1, $wpdb->insert($schema->notification_outbox_table(), ['dedupe_hash' => hash('sha256', $retired), 'event_uuid' => $retired, 'request_id' => $request->id(), 'recipient_role' => 'buyer', 'recipient_user_id' => $request->buyerId(), 'template_key' => 'managed_delivery_code', 'payload_json' => '{"generation":1}', 'status' => 'pending', 'next_attempt_gmt' => $now, 'created_at_gmt' => $now]));
            $worker->dispatch();
            self::assertSame('cancelled', $wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE event_uuid=%s', $schema->notification_outbox_table(), $retired)));
            self::assertCount($before + 1, PWSMS()->sent);
        }
    }
}
