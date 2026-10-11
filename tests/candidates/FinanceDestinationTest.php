<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Application\{Spec4FinanceService, ManualPaymentSettings};
use Zarbalman\Core\Domain\Finance\Spec4Data;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Infrastructure\{AuditLogRepository, NotificationOutboxRepository, PurchaseRequestRepository, PurchaseRequestOperationLock, ManagedCaseRepository, FinancialActionRepository, PaymentRepository, LedgerRepository, ManagedEvidence, EvidenceUploader, EvidenceCrypto, ProtectedText, DeliveryDataCrypto, DependencyHealth, Schema};
use Zarbalman\Core\Integration\DokanManualSettlementAdapter;

final class FinanceDestinationTest extends TestCase {
    public function test_authorized_destination_is_returned_only_after_durable_audit(): void {
        global $wpdb;
        $schema = new Schema($wpdb);
        $admin = (int) get_user_by('login', 'zbm_test_admin')->ID;
        wp_set_current_user($admin);
        $audit = new AuditLogRepository($wpdb);
        $outbox = new NotificationOutboxRepository($wpdb);
        $requests = new PurchaseRequestRepository($wpdb, $audit, $outbox);
        $lock = new PurchaseRequestOperationLock($wpdb);
        $cases = new ManagedCaseRepository($wpdb, $requests, $lock, $audit, $outbox);
        $actions = new FinancialActionRepository($wpdb, $cases);
        $ledger = new LedgerRepository($wpdb);
        $protected = new ProtectedText(new DeliveryDataCrypto());
        self::assertTrue($protected->ensureKey());
        $order = wc_create_order(['customer_id' => (int) get_user_by('login', 'zbm_test_buyer_1')->ID]);
        self::assertInstanceOf(WC_Order::class, $order);
        $now = current_time('mysql', true);
        $row = [
            'public_id' => wp_generate_uuid4(), 'buyer_id' => $order->get_customer_id(),
            'vendor_id' => (int) get_user_by('login', 'zbm_test_vendor_1')->ID,
            'product_id' => 1, 'variation_id' => 0, 'quantity' => 1, 'package_price_toman' => 1000,
            'nominal_package_weight_tenths' => 10, 'reference_weight_tenths' => 10,
            'estimated_total_toman' => 1000, 'deposit_toman' => 100, 'delivery_method' => 'pickup_from_vendor',
            'delivery_window_type' => 'exact', 'delivery_start_gmt' => $now, 'delivery_end_gmt' => $now,
            'delivery_payload_encrypted' => '', 'current_state' => 'supplier_accepted', 'version' => 0,
            'is_paused' => 0, 'execution_mode' => 'live', 'wc_order_id' => $order->get_id(),
            'created_at_gmt' => $now, 'updated_at_gmt' => $now,
        ];
        self::assertSame(1, $wpdb->insert($schema->purchase_requests_table(), $row), $wpdb->last_error);
        $id = (int) $wpdb->insert_id;
        $request = $requests->find($id);
        self::assertInstanceOf(PurchaseRequest::class, $request);
        $case = ['request_id' => $id, 'version' => 0, 'state' => 'awaiting_deposit', 'mode' => 'live', 'sync_pending' => 0, 'deadline_gmt' => null, 'closed_at_gmt' => null, 'data' => ManagedCaseRepository::initialData($request)];
        $binding = wp_generate_uuid4();
        $case['data']['spec4'] = Spec4Data::initial($request, $case, $now, $binding, 'estimate');
        $destination = 'IR000000000000000000000000'; // Synthetic protected text, never sent to a bank.
        $encrypted = $protected->encrypt('refund_destination', $id, $binding, $destination);
        $hash = hash('sha256', wp_generate_uuid4());
        self::assertSame(1, $wpdb->insert($schema->financial_actions_table(), [
            'request_id' => $id, 'order_id' => $order->get_id(), 'phase' => 'allocation', 'action_type' => 'cancellation_refund',
            'status' => 'pending', 'base_amount_toman' => 1000, 'buyer_refund_amount_toman' => 100,
            'allocation_key' => $hash, 'idempotency_key' => $hash, 'destination_ciphertext' => $encrypted,
            'created_by' => $admin, 'created_at_gmt' => $now, 'updated_at_gmt' => $now,
        ]), $wpdb->last_error);
        $actionId = (int) $wpdb->insert_id;
        $case['data']['spec4']['finance_action_ids'] = [$actionId];
        self::assertSame(1, $wpdb->insert($schema->managed_cases_table(), [
            'request_id' => $id, 'state' => $case['state'], 'mode' => 'live', 'data_json' => wp_json_encode($case['data']),
            'created_at_gmt' => $now, 'updated_at_gmt' => $now,
        ]), $wpdb->last_error);
        // Prove the fixture passes the real aggregate validator before testing the suspect callback.
        $validator = new ReflectionMethod($cases, 'validateCase');
        $validator->invoke($cases, $case, $request);
        $crypto = new EvidenceCrypto();
        $evidence = new ManagedEvidence(new EvidenceUploader($crypto), $crypto, $requests, $cases, $lock);
        $service = new Spec4FinanceService($requests, $cases, $actions, new PaymentRepository($wpdb), $ledger,
            new DokanManualSettlementAdapter($wpdb, $ledger), $evidence, $protected, new DependencyHealth(), new ManualPaymentSettings($protected, $audit));
        $key = wp_generate_uuid4();
        $result = $service->destination($id, 0, $actionId, $admin, $key, 'Synthetic authorized audit test');
        self::assertFalse(is_wp_error($result), is_wp_error($result) ? $result->get_error_code() : '');
        self::assertSame($destination, $result);
        $saved = $cases->find($id);
        self::assertSame(1, $saved['version']);
        self::assertSame('destination_viewed', $saved['data']['spec4']['decision_history'][0]['action']);
        self::assertSame(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE resource_id=%d AND event_key=%s', $schema->audit_table(), $id, 'spec4_destination_viewed')));
        self::assertSame($destination, $service->destination($id, 0, $actionId, $admin, $key, 'Synthetic authorized audit test'));
        self::assertSame(1, $cases->find($id)['version'], 'Replay must not append another audit decision.');
        self::assertSame('pending', $actions->get($actionId, $id)['status']);
        self::assertSame('pending', wc_get_order($order->get_id())->get_status());
        wp_set_current_user(0);
    }
}
