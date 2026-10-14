<?php
declare(strict_types=1);

require_once __DIR__ . '/Task4ManagedFixture.php';
require_once __DIR__ . '/Task4EndpointHttp.php';

use PHPUnit\Framework\Assert;
use Zarbalman\Core\Infrastructure\{Schema, ProtectedText, DeliveryDataCrypto, EvidenceCrypto};

/** Real database fixtures and loopback HTTP only; no dependency/provider substitutes. */
final class Task5HttpRiskFixture {
    public static function post(Task4ManagedFixture $fixture, string $operation, string $actor, string $transport, array $overrides = []): array {
        [$cookie, $nonce] = CandidateHttp::auth($fixture->users[$actor], 'zbm_managed_case_' . $fixture->id);
        $fields = array_replace($fixture->fields($operation), $overrides, ['_zbm_nonce' => $nonce]);
        $http = CandidateHttp::post('wp-admin/' . $transport, $fields, $cookie);
        return [$http, $fields];
    }

    public static function code(Task4ManagedFixture $fixture): string {
        $delivery = $fixture->case()['data']['delivery'];
        $plain = (new ProtectedText(new DeliveryDataCrypto()))->decrypt('delivery_code', $fixture->id,
            $fixture->orderId . ':' . $delivery['generation'], $delivery['encrypted_code']);
        Assert::assertIsString($plain);
        Assert::assertMatchesRegularExpression('/\A[0-9]{6}\z/', $plain);
        return $plain;
    }

    public static function evidence(Task4ManagedFixture $fixture, string $field = 'vendor_evidence'): int {
        $lines = $fixture->case()['data']['lines'];
        $line = reset($lines);
        Assert::assertIsArray($line);
        Assert::assertNotEmpty($line[$field]);
        return $line[$field][0];
    }

    /** Nonce belongs to the actual requested pair, so a denial proves binding/ownership. */
    public static function download(int $requestId, int $attachmentId, int $actor): array {
        [$cookie, $nonce] = CandidateHttp::auth($actor, 'zbm_core_managed_evidence_' . $requestId . '_' . $attachmentId);
        return Task4EndpointHttp::get('wp-admin/admin-post.php', ['action' => 'zbm_core_download_managed_evidence',
            'request_id' => $requestId, 'attachment_id' => $attachmentId, '_wpnonce' => $nonce], $cookie);
    }

    public static function binary(int $attachmentId): string {
        $binary = (new EvidenceCrypto())->decrypt_file(get_attached_file($attachmentId, true));
        Assert::assertIsString($binary);
        return $binary;
    }

    public static function legacyDownload(int $attachmentId, int $actor): array {
        [$cookie, $nonce] = CandidateHttp::auth($actor, 'zbm_core_evidence_' . $attachmentId);
        return Task4EndpointHttp::get('wp-admin/admin-post.php', ['action' => 'zbm_core_download_evidence',
            'attachment_id' => $attachmentId, '_wpnonce' => $nonce], $cookie);
    }

    /** Raw SQL avoids object-cache snapshots after another PHP process commits. */
    public static function rows(string $table, string $column, int $id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE %i=%d ORDER BY 1', $table, $column, $id), ARRAY_A);
        Assert::assertIsArray($rows);
        Assert::assertSame('', $wpdb->last_error, $table);
        return $rows;
    }

    public static function money(Task4ManagedFixture $fixture): array {
        global $wpdb;
        $schema = new Schema($wpdb);
        return ['payments' => self::rows($schema->payments_table(), 'request_id', $fixture->id),
            'ledger' => self::rows($schema->ledger_table(), 'request_id', $fixture->id),
            'actions' => self::rows($schema->financial_actions_table(), 'request_id', $fixture->id),
            'funds' => $fixture->case()['data']['funds'], 'final' => $fixture->case()['data']['final']];
    }

    /** Global financial/order facts; delivery commands may change only delivery/operations data. */
    public static function allMoney(): array {
        global $wpdb;
        $schema = new Schema($wpdb);
        Assert::assertTrue(\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'Native HPOS order facts must be present.');
        $tables = [$schema->payments_table(), $schema->ledger_table(), $schema->financial_actions_table(),
            $schema->dokan_credit_mirrors_table(), $wpdb->posts, $wpdb->postmeta,
            $wpdb->prefix . 'woocommerce_order_items', $wpdb->prefix . 'woocommerce_order_itemmeta'];
        foreach (['wc_orders', 'wc_order_addresses', 'wc_order_operational_data', 'wc_orders_meta'] as $suffix) {
            $tables[] = $wpdb->prefix . $suffix;
        }
        foreach (['dokan_vendor_balance', 'dokan_orders', 'wc_order_tax_lookup', 'wc_order_product_lookup', 'wc_order_coupon_lookup', 'wc_order_stats'] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) { $tables[] = $table; }
            Assert::assertSame('', $wpdb->last_error, $table);
        }
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = self::allRows($table);
        }
        // Whole case JSON cannot be compared: attempts, generation and handoff data change legitimately.
        $cases = self::allRows($schema->managed_cases_table());
        $snapshot['all_managed_case_financial_data'] = [];
        foreach ($cases as $row) {
            $data = json_decode($row['data_json'], true, 64, JSON_THROW_ON_ERROR);
            Assert::assertIsArray($data['funds']); Assert::assertIsArray($data['final']);
            $snapshot['all_managed_case_financial_data'][(string) $row['request_id']] = ['funds' => $data['funds'], 'final' => $data['final']];
        }
        ksort($snapshot['all_managed_case_financial_data']);
        return $snapshot;
    }

    /** Order by every primary-key column, including composite keys; retain exact SQL rows. */
    private static function allRows(string $table): array {
        global $wpdb;
        $indexes = $wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i WHERE Key_name=%s', $table, 'PRIMARY'), ARRAY_A);
        Assert::assertIsArray($indexes); Assert::assertSame('', $wpdb->last_error, $table);
        usort($indexes, static fn(array $a, array $b): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);
        $keys = array_column($indexes, 'Column_name'); Assert::assertNotEmpty($keys, $table);
        $order = implode(', ', array_map(static fn(string $key): string => $wpdb->prepare('%i', $key), $keys));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i', $table) . ' ORDER BY ' . $order, ARRAY_A);
        Assert::assertIsArray($rows); Assert::assertSame('', $wpdb->last_error, $table);
        return $rows;
    }

    public static function audit(Task4ManagedFixture $fixture): array {
        global $wpdb;
        return self::rows((new Schema($wpdb))->audit_table(), 'resource_id', $fixture->id);
    }

    public static function commands(Task4ManagedFixture $fixture): array {
        global $wpdb;
        return self::rows((new Schema($wpdb))->managed_case_commands_table(), 'request_id', $fixture->id);
    }
}
