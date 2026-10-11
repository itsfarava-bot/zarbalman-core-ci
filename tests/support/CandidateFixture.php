<?php
declare(strict_types=1);

use Zarbalman\Core\Infrastructure\{Schema, AuditLogRepository, NotificationOutboxRepository, PurchaseRequestRepository, PurchaseRequestOperationLock, ManagedCaseRepository};

final class CandidateFixture {
    public static function repositories(?wpdb $db = null): array {
        global $wpdb;
        $db = $db ?? $wpdb;
        $audit = new AuditLogRepository($db);
        $outbox = new NotificationOutboxRepository($db);
        $requests = new PurchaseRequestRepository($db, $audit, $outbox);
        $lock = new PurchaseRequestOperationLock($db);
        $cases = new ManagedCaseRepository($db, $requests, $lock, $audit, $outbox);
        return compact('audit', 'outbox', 'requests', 'lock', 'cases');
    }

    public static function request(array $overrides = []): array {
        global $wpdb;
        $schema = new Schema($wpdb);
        $now = current_time('mysql', true);
        $row = array_replace([
            'public_id' => wp_generate_uuid4(), 'buyer_id' => (int) get_user_by('login', 'zbm_test_buyer_1')->ID,
            'vendor_id' => (int) get_user_by('login', 'zbm_test_vendor_1')->ID,
            'product_id' => 1, 'variation_id' => 0, 'quantity' => 1, 'package_price_toman' => 1000,
            'nominal_package_weight_tenths' => 10, 'reference_weight_tenths' => 10,
            'estimated_total_toman' => 1000, 'deposit_toman' => 100, 'delivery_method' => 'pickup_from_vendor',
            'delivery_window_type' => 'exact', 'delivery_start_gmt' => $now, 'delivery_end_gmt' => $now,
            'delivery_payload_encrypted' => '', 'current_state' => 'supplier_accepted', 'version' => 0,
            'is_paused' => 0, 'execution_mode' => 'live', 'created_at_gmt' => $now, 'updated_at_gmt' => $now,
        ], $overrides);
        if (1 !== $wpdb->insert($schema->purchase_requests_table(), $row)) {
            throw new RuntimeException('Synthetic request insert failed.');
        }
        $repos = self::repositories();
        $request = $repos['requests']->find((int) $wpdb->insert_id);
        if (!$request) { throw new RuntimeException('Synthetic request missing.'); }
        return [$request, $repos];
    }

    public static function caseFor($request, array $overrides = []): array {
        return array_replace([
            'request_id' => $request->id(), 'version' => 0, 'state' => 'awaiting_deposit', 'mode' => $request->executionMode(),
            'sync_pending' => 0, 'deadline_gmt' => null, 'closed_at_gmt' => null,
            'data' => ManagedCaseRepository::initialData($request),
        ], $overrides);
    }

    public static function saveCase(array $case): void {
        global $wpdb;
        $now = current_time('mysql', true);
        $fields = $case;
        $fields['data_json'] = wp_json_encode($fields['data']); unset($fields['data']);
        $fields['created_at_gmt'] = $now; $fields['updated_at_gmt'] = $now;
        if (false === $wpdb->replace((new Schema($wpdb))->managed_cases_table(), $fields)) {
            throw new RuntimeException('Synthetic case insert failed.');
        }
    }

    public static function financialSnapshot(): array {
        global $wpdb;
        $schema = new Schema($wpdb);
        $snapshot = [];
        foreach ([$schema->payments_table(), $schema->ledger_table(), $schema->financial_actions_table(), $schema->managed_cases_table(), $schema->purchase_requests_table()] as $table) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY 1', $table), ARRAY_A);
            if (!is_array($rows) || $wpdb->last_error !== '') { throw new RuntimeException('Snapshot unavailable.'); }
            $snapshot[$table] = hash('sha256', wp_json_encode($rows));
        }
        $orders = wc_get_orders(['limit' => -1, 'return' => 'objects', 'orderby' => 'ID', 'order' => 'ASC']);
        $snapshot['orders'] = hash('sha256', wp_json_encode(array_map(static fn($o) => $o->get_data(), $orders)));
        return $snapshot;
    }
}
