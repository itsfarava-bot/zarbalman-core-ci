<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/CandidateFixture.php';
require_once __DIR__ . '/../support/RecordingSms.php';

use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Application\AdminTestModeManager;
use Zarbalman\Core\Domain\PurchaseRequest\RequestNumber;
use Zarbalman\Core\Infrastructure\{DependencyHealth, NotificationWorker, Schema};
use Zarbalman\Core\Integration\PwSmsAdapter;
use Zarbalman\Core\Notification\{MessageCatalog, RecipientResolver};

final class LegacyNotificationSettingTest extends TestCase {
    public function test_each_legacy_global_setting_keeps_live_delivery_and_test_record_cancellation(): void {
        global $wpdb;
        $previous = get_option(AdminTestModeManager::OPTION, 'no');
        $previousSent = PWSMS()->sent;
        $previousFail = PWSMS()->fail;
        $schema = new Schema($wpdb);
        $references = [];

        try {
            foreach (['yes', 'no'] as $setting) {
                update_option(AdminTestModeManager::OPTION, $setting, false);
                self::assertSame($setting, get_option(AdminTestModeManager::OPTION));
                PWSMS()->sent = [];
                PWSMS()->fail = false;
                $fixtures = [];

                foreach (['test_pending', 'test', 'live'] as $mode) {
                    $reference = $this->availableReference($schema);
                    self::assertTrue(RequestNumber::isValid($reference));
                    self::assertNotSame('', $reference);
                    [$request, $repos] = CandidateFixture::request([
                        'request_number' => $reference,
                        'execution_mode' => $mode,
                        'current_state' => 'supplier_rejected',
                    ]);
                    self::assertSame($reference, $request->requestNumber());
                    self::assertSame($reference, $wpdb->get_var($wpdb->prepare(
                        'SELECT request_number FROM %i WHERE id=%d',
                        $schema->purchase_requests_table(), $request->id()
                    )));
                    $references[] = $reference;
                    update_user_meta($request->buyerId(), 'billing_phone', '09120000000');
                    $event = wp_generate_uuid4();
                    $now = current_time('mysql', true);
                    // Pre-existing queued records exercise the real worker's mode guard.
                    self::assertSame(1, $wpdb->insert($schema->notification_outbox_table(), [
                        'dedupe_hash' => hash('sha256', $event),
                        'event_uuid' => $event,
                        'request_id' => $request->id(),
                        'recipient_role' => 'buyer',
                        'recipient_user_id' => $request->buyerId(),
                        'template_key' => 'request_rejected',
                        'payload_json' => '{}',
                        'status' => 'pending',
                        'attempt_count' => 0,
                        'next_attempt_gmt' => $now,
                        'created_at_gmt' => $now,
                    ]));
                    self::assertSame('pending', $this->outboxStatus($schema, $event));
                    $fixtures[$mode] = [$request, $event, $reference];
                }

                $worker = new NotificationWorker(
                    $repos['outbox'], $repos['requests'], new RecipientResolver(),
                    new MessageCatalog(), new PwSmsAdapter(new DependencyHealth()),
                    $repos['audit'], $repos['cases']
                );
                $worker->dispatch();
                self::assertCount(1, PWSMS()->sent, 'Live delivery must occur with legacy setting ' . $setting);
                $liveReference = $fixtures['live'][2];
                self::assertTrue(RequestNumber::isValid($liveReference));
                self::assertNotSame('', $liveReference);
                self::assertStringContainsString($liveReference, PWSMS()->sent[0]['message']);
                foreach (['test_pending', 'test'] as $testMode) {
                    self::assertStringNotContainsString($fixtures[$testMode][2], PWSMS()->sent[0]['message']);
                }

                foreach ($fixtures as $mode => [$request, $event, $reference]) {
                    $expected = 'live' === $mode ? 'sent' : 'cancelled';
                    self::assertSame($expected, $this->outboxStatus($schema, $event), $setting . '/' . $mode);
                    $persisted = $repos['requests']->find($request->id());
                    self::assertNotNull($persisted);
                    self::assertSame($mode, $persisted->executionMode());
                    self::assertSame('supplier_rejected', $persisted->state());
                    self::assertSame($reference, $persisted->requestNumber());
                }
                self::assertSame($setting, get_option(AdminTestModeManager::OPTION), 'Worker must preserve the legacy setting.');

                $worker->dispatch();
                self::assertCount(1, PWSMS()->sent, 'Dispatch replay must not send again with legacy setting ' . $setting);
                foreach ($fixtures as $mode => [$request, $event]) {
                    self::assertSame('live' === $mode ? 'sent' : 'cancelled', $this->outboxStatus($schema, $event));
                }
            }
            self::assertCount(6, array_unique($references), 'All six stored synthetic references must be distinct.');
        } finally {
            update_option(AdminTestModeManager::OPTION, $previous, false);
            PWSMS()->sent = $previousSent;
            PWSMS()->fail = $previousFail;
        }
    }

    private function availableReference(Schema $schema): string {
        global $wpdb;
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $reference = RequestNumber::generate();
            $count = $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE request_number=%s',
                $schema->purchase_requests_table(), $reference
            ));
            if (null === $count || '' !== $wpdb->last_error) {
                throw new RuntimeException('Synthetic request reference availability could not be checked.');
            }
            if (0 === (int) $count) { return $reference; }
        }
        throw new RuntimeException('A unique synthetic request reference could not be allocated.');
    }

    private function outboxStatus(Schema $schema, string $event): ?string {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM %i WHERE event_uuid=%s',
            $schema->notification_outbox_table(), $event
        ));
    }
}
