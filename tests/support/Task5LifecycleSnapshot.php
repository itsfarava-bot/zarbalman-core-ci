<?php
declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Zarbalman\Core\Infrastructure\Schema;

/** Exact Core, order, and attachment rows; exclude scheduler/option housekeeping. */
final class Task5LifecycleSnapshot {
    public static function rows(wpdb $db, Schema $schema): array {
        $snapshot = [];
        Assert::assertTrue(\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled());
        $tables = array_merge($schema->tables(), [$db->posts, $db->postmeta,
            $db->prefix . 'woocommerce_order_items', $db->prefix . 'woocommerce_order_itemmeta']);
        foreach (['wc_orders', 'wc_order_addresses', 'wc_order_operational_data', 'wc_orders_meta'] as $suffix) {
            $tables[] = $db->prefix . $suffix;
        }
        foreach (['dokan_vendor_balance', 'dokan_orders'] as $suffix) {
            $table = $db->prefix . $suffix;
            if ($db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($table))) === $table) { $tables[] = $table; }
            Assert::assertSame('', $db->last_error, $table);
        }
        foreach ($tables as $table) {
            $indexes = $db->get_results($db->prepare('SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'PRIMARY'), ARRAY_A);
            Assert::assertIsArray($indexes); Assert::assertSame('', $db->last_error, $table);
            usort($indexes, static fn(array $a, array $b): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);
            $keys = array_column($indexes, 'Column_name'); Assert::assertNotEmpty($keys, $table);
            $order = implode(', ', array_map(static fn(string $key): string => $db->prepare('%i', $key), $keys));
            $rows = $db->get_results($db->prepare('SELECT * FROM %i', $table) . ' ORDER BY ' . $order, ARRAY_A);
            Assert::assertIsArray($rows); Assert::assertSame('', $db->last_error, $table);
            $snapshot[$table] = [];
            foreach ($rows as $row) {
                $identity = array_map(static fn(string $key) => $row[$key], $keys);
                $rowKey = count($identity) === 1 ? (string) $identity[0] : json_encode($identity, JSON_THROW_ON_ERROR);
                Assert::assertArrayNotHasKey($rowKey, $snapshot[$table], 'Duplicate snapshot identity: ' . $table);
                $snapshot[$table][$rowKey] = $row;
            }
            ksort($snapshot[$table]);
        }
        return $snapshot;
    }

    /** Values are disclosed only for the explicitly safe synthetic lifecycle columns. */
    public static function columns(array $before, array $after): array {
        $safe = ['id', 'order_id', 'request_id', 'request_number', 'current_state', 'version', 'is_paused',
            'buyer_deadline_gmt', 'vendor_deadline_gmt', 'delivered_at_gmt', 'created_at_gmt', 'updated_at_gmt',
            'event_key', 'from_state', 'to_state', 'actor_type', 'actor_user_id', 'resource_type', 'resource_id', 'namespace', 'status'];
        $changes = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $rowKey) {
            $old = $before[$rowKey] ?? []; $new = $after[$rowKey] ?? [];
            foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $column) {
                $oldExists = array_key_exists($column, $old); $newExists = array_key_exists($column, $new);
                $a = $old[$column] ?? null; $b = $new[$column] ?? null;
                if ($oldExists === $newExists && $a === $b) { continue; }
                $private = !in_array($column, $safe, true);
                $changes[] = ['id' => ctype_digit((string) $rowKey) ? (int) $rowKey : '[redacted primary key]',
                    'column' => $column, 'before_present' => $oldExists, 'after_present' => $newExists,
                    'before' => $private && $a !== null ? '[redacted]' : $a,
                    'after' => $private && $b !== null ? '[redacted]' : $b];
            }
        }
        return $changes;
    }

    public static function tables(array $before, array $after): array {
        $changes = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $table) {
            $columns = self::columns($before[$table] ?? [], $after[$table] ?? []);
            if ($columns !== []) { $changes[$table] = $columns; }
        }
        return $changes;
    }

    public static function hashes(array $before, array $after): array {
        $changes = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $table) {
            if (($before[$table] ?? null) !== ($after[$table] ?? null)) {
                $changes[] = ['table' => $table, 'before_hash' => $before[$table] ?? null, 'after_hash' => $after[$table] ?? null];
            }
        }
        return $changes;
    }
}
