<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/CandidateFixture.php';

use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Infrastructure\{LedgerRepository, Schema};
use Zarbalman\Core\Integration\{DokanCreditAdapter, DokanManualSettlementAdapter};

/** Native Dokan Lite model and SQL precision; does not prove Dokan Pro compatibility. */
final class DokanAmountContractTest extends TestCase {
    public function test_native_adapters_persist_exact_integer_boundaries_and_replay_once(): void {
        global $wpdb;
        self::assertSame(8, PHP_INT_SIZE);
        self::assertTrue(class_exists(\WeDevs\Dokan\Models\VendorBalance::class));
        self::assertContains('wc-completed', dokan_withdraw_get_active_order_status());
        $schema = new Schema($wpdb);
        foreach ([999999999991, 999999999999, 1000000000000] as $amount) {
            foreach (['credit', 'manual'] as $route) {
                [$request] = CandidateFixture::request();
                $order = wc_create_order(['status' => 'pending']); self::assertInstanceOf(WC_Order::class, $order);
                $key = 'task6-native-' . wp_generate_uuid4(); $hash = hash('sha256', $key);
                if ($route === 'credit') {
                    $adapter = new DokanCreditAdapter($wpdb);
                    $adapter->creditOnce($request->vendorId(), $order->get_id(), $amount, $key);
                    $adapter->creditOnce($request->vendorId(), $order->get_id(), $amount, $key);
                } else {
                    $adapter = new DokanManualSettlementAdapter($wpdb, new LedgerRepository($wpdb));
                    self::assertNotFalse($wpdb->query('START TRANSACTION'));
                    try {
                        $first = $adapter->credit($request, $order->get_id(), $amount, $hash);
                        self::assertIsArray($first); self::assertFalse($first['replayed']);
                        $repeat = $adapter->credit($request, $order->get_id(), $amount, $hash);
                        self::assertIsArray($repeat); self::assertTrue($repeat['replayed']);
                        self::assertSame($first['balance_id'], $repeat['balance_id']);
                        self::assertNotFalse($wpdb->query('COMMIT'));
                    } catch (Throwable $error) { $wpdb->query('ROLLBACK'); throw $error; }
                }
                $rows = $wpdb->get_results($wpdb->prepare('SELECT debit,credit FROM %i WHERE trn_id=%d AND trn_type=%s',
                    $wpdb->prefix . 'dokan_vendor_balance', $order->get_id(), 'dokan_orders'), ARRAY_A);
                self::assertSame('', $wpdb->last_error); self::assertCount(1, $rows);
                self::assertSame((string) $amount . '.0000', $rows[0]['debit']);
                self::assertSame('0.0000', $rows[0]['credit']);
                self::assertSame((string) $amount, $wpdb->get_var($wpdb->prepare('SELECT amount_toman FROM %i WHERE allocation_hash=%s', $schema->dokan_credit_mirrors_table(), $hash)));
                if ($route === 'manual') {
                    self::assertSame(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE request_id=%d AND amount_toman=%d', $schema->ledger_table(), $request->id(), $amount)));
                }
            }
        }
    }
}
