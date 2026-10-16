<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/CandidateFixture.php';
require_once __DIR__ . '/../support/Task4RemainingFixture.php';

use Automattic\WooCommerce\Caches\OrderCache;
use Automattic\WooCommerce\Utilities\{FeaturesUtil, OrderUtil};
use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Infrastructure\Schema;
use Zarbalman\Core\Integration\{ManagedOrderSnapshot, WooCommerceOrderAdapter};

/** Real Woo CRUD and the registered Core adapter; no commercial dependency doubles. */
final class HposLifecycleTest extends TestCase {
    private int $previousUser = 0;
    private array $previousOptions = [];

    protected function setUp(): void {
        parent::setUp();
        $this->previousUser = get_current_user_id();
        wp_set_current_user(0);
        self::assertTrue(OrderUtil::custom_orders_table_usage_is_enabled());
        self::assertSame('yes', get_option('woocommerce_custom_orders_table_enabled'));
        self::assertSame('no', get_option('woocommerce_custom_orders_table_data_sync_enabled'));
    }

    protected function tearDown(): void {
        try {
            foreach ($this->previousOptions as $key => $previous) {
                if ($previous['exists']) { update_option($key, $previous['value'], false); }
                else { delete_option($key); }
            }
        } finally {
            wp_set_current_user($this->previousUser);
            parent::tearDown();
        }
    }

    public function test_core_declares_custom_order_tables_compatibility_in_native_registry(): void {
        self::assertGreaterThan(0, did_action('before_woocommerce_init'));
        $registry = FeaturesUtil::get_compatible_plugins_for_feature('custom_order_tables');
        $plugin = plugin_basename(ZBM_CORE_FILE);
        self::assertContains($plugin, $registry['compatible']);
        self::assertNotContains($plugin, $registry['incompatible']);
    }

    public function test_native_legacy_stock_metadata_maps_to_operational_property(): void {
        global $wpdb;
        $order = wc_create_order(['status' => 'pending']);
        self::assertInstanceOf(WC_Order::class, $order);
        $id = $order->get_id();
        self::assertFalse(method_exists($order->get_data_store(), 'get_stock_reduced'));
        self::assertTrue($order->get_data_store()->has_callable('get_stock_reduced'));
        $order->update_meta_data('_order_stock_reduced', 'yes');
        self::assertTrue($order->get_order_stock_reduced());
        self::assertTrue($order->get_meta('_order_stock_reduced', true));
        $order->save_meta_data();
        $stored = $wpdb->get_var($wpdb->prepare('SELECT order_stock_reduced FROM %i WHERE order_id=%d',
            $wpdb->prefix . 'wc_order_operational_data', $id));
        self::assertSame('', $wpdb->last_error); self::assertSame('0', $stored);
        self::assertFalse($this->freshOrder($id)->get_order_stock_reduced());
        $order->save();
        self::assertTrue($this->freshOrder($id)->get_order_stock_reduced());
        $stored = $wpdb->get_var($wpdb->prepare('SELECT order_stock_reduced FROM %i WHERE order_id=%d',
            $wpdb->prefix . 'wc_order_operational_data', $id));
        self::assertSame('', $wpdb->last_error); self::assertSame('1', $stored);
    }

    public function test_native_hpos_create_save_reload_update_status_meta_and_order_note_without_sync(): void {
        $buyer = $this->phoneOnlyCustomer();
        $order = wc_create_order(['customer_id' => $buyer, 'status' => 'pending']);
        self::assertInstanceOf(WC_Order::class, $order);
        $id = $order->get_id();
        self::assertGreaterThan(0, $id);
        $key = '_task7_hpos_lifecycle';
        $marker = 'created-' . wp_generate_uuid4();
        $order->set_currency('IRT');
        $order->set_total('123');
        $order->update_meta_data($key, $marker);
        self::assertSame($id, $order->save());

        $fresh = $this->freshOrder($id);
        self::assertNotSame($order, $fresh);
        self::assertSame('pending', $fresh->get_status());
        self::assertSame($buyer, $fresh->get_customer_id());
        self::assertSame(123.0, (float) $fresh->get_total());
        self::assertSame($marker, $fresh->get_meta($key, true));
        $this->assertOrderSql($id, 'pending', $buyer, 123);
        $this->assertMetaSql($id, $key, $marker);

        $updated = 'updated-' . wp_generate_uuid4();
        $fresh->set_status('on-hold');
        $fresh->update_meta_data($key, $updated);
        self::assertSame($id, $fresh->save());
        $note = 'Synthetic HPOS lifecycle note ' . wp_generate_uuid4();
        $noteId = $fresh->add_order_note($note, false, false);
        self::assertGreaterThan(0, $noteId);

        $reloaded = $this->freshOrder($id);
        self::assertSame('on-hold', $reloaded->get_status());
        self::assertSame($updated, $reloaded->get_meta($key, true));
        self::assertSame('', $reloaded->get_billing_email('edit'));
        self::assertFalse($reloaded->is_paid());
        $this->assertOrderSql($id, 'on-hold', $buyer, 123);
        $this->assertMetaSql($id, $key, $updated);
        $this->assertNoteSqlAndApi($id, (int) $noteId, $note);
        $this->assertNoLegacyOrderStorage($id, [$key]);
        self::assertSame('no', get_option('woocommerce_custom_orders_table_data_sync_enabled'));
    }

    /** Exercises the adapter itself, not an HTTP/dependency-health or provider contract. */
    public function test_registered_adapter_creates_recovers_and_cancels_native_hpos_test_order(): void {
        global $wpdb;
        // Scope the adapter prerequisites and restore the original options in tearDown.
        foreach (['woocommerce_currency' => 'IRT', 'woocommerce_manage_stock' => 'yes'] as $key => $value) {
            $missing = new stdClass();
            $previous = get_option($key, $missing);
            $this->previousOptions[$key] = ['exists' => $previous !== $missing, 'value' => $previous === $missing ? null : $previous];
            update_option($key, $value, false);
        }
        $buyer = $this->phoneOnlyCustomer();
        $vendor = get_user_by('login', 'zbm_test_vendor_1');
        self::assertInstanceOf(WP_User::class, $vendor);
        $product = Task4RemainingFixture::product((int) $vendor->ID, 'Task 7 native HPOS product');
        self::assertSame('yes', get_option('woocommerce_manage_stock', 'yes'));
        [$request] = CandidateFixture::request([
            'buyer_id' => $buyer, 'vendor_id' => (int) $vendor->ID,
            'product_id' => $product->get_id(), 'execution_mode' => 'test_pending', 'pricing_version' => 2,
        ]);
        $adapter = $this->registeredAdapter();
        $id = $adapter->createTestForAcceptedRequest($request);
        self::assertGreaterThan(0, $id);
        $order = $this->freshOrder($id);
        self::assertTrue(ManagedOrderSnapshot::matches($order, $request));
        self::assertSame('test', ManagedOrderSnapshot::route($order));
        self::assertSame('no', $order->get_meta('_zbm_payment_enabled', true));
        self::assertSame('', $order->get_billing_email('edit'));
        self::assertSame('', $order->get_billing_phone('edit'));
        self::assertFalse($order->is_paid());
        self::assertNull($order->get_date_paid());
        self::assertSame('', $order->get_transaction_id());
        $this->assertOrderSql($id, 'pending', $buyer, $request->depositToman());
        $this->assertMetaSql($id, '_zbm_request_id', (string) $request->id());
        $this->assertMetaSql($id, '_zbm_execution_mode', 'test');
        $this->assertStockSql($id, $product->get_id(), true, 99);
        $this->assertNoLegacyOrderStorage($id, ['_zbm_request_id', '_zbm_execution_mode', '_zbm_payment_enabled']);

        // The request intentionally remains unlinked so this invokes native HPOS
        // meta_query recovery rather than merely returning its existing order ID.
        self::assertNull($request->wcOrderId());
        self::assertSame($id, $adapter->createTestForAcceptedRequest($request));
        $matches = wc_get_orders(['type' => 'shop_order', 'limit' => -1, 'return' => 'ids',
            'meta_query' => [['key' => '_zbm_request_id', 'value' => (string) $request->id(), 'compare' => '=']]]);
        self::assertSame([$id], array_map('intval', $matches));
        $this->assertStockSql($id, $product->get_id(), true, 99);

        $note = 'Synthetic HPOS adapter cancellation ' . wp_generate_uuid4();
        $adapter->cancelUnpaidOrder($id, $note);
        $cancelled = $this->freshOrder($id);
        self::assertSame('cancelled', $cancelled->get_status());
        self::assertTrue(ManagedOrderSnapshot::matches($cancelled, $request));
        self::assertSame('yes', $cancelled->get_meta('_zbm_stock_released', true));
        self::assertSame('no', $cancelled->get_meta('_zbm_payment_enabled', true));
        self::assertFalse($cancelled->is_paid());
        $this->assertOrderSql($id, 'cancelled', $buyer, $request->depositToman());
        $this->assertMetaSql($id, '_zbm_authoritative_wc_status', 'cancelled');
        $this->assertMetaSql($id, '_zbm_stock_released', 'yes');
        $this->assertStockSql($id, $product->get_id(), false, 100);
        $notes = wc_get_order_notes(['order_id' => $id, 'limit' => 100]);
        $matching = array_values(array_filter($notes, static fn ($row): bool => str_contains($row->content, $note)));
        self::assertCount(1, $matching);
        $this->assertNoteSqlAndApi($id, (int) $matching[0]->id, $matching[0]->content);
        $schema = new Schema($wpdb);
        foreach ([$schema->payments_table(), $schema->ledger_table(), $schema->financial_actions_table()] as $table) {
            $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE request_id=%d', $table, $request->id()));
            self::assertSame('', $wpdb->last_error);
            self::assertSame('0', $count);
        }
        self::assertSame('', get_userdata($buyer)->user_email);
        self::assertSame('no', get_option('woocommerce_custom_orders_table_data_sync_enabled'));
    }

    private function phoneOnlyCustomer(): int {
        $id = wp_insert_user(['user_login' => 'task7_phone_only_' . str_replace('-', '', wp_generate_uuid4()),
            'user_pass' => wp_generate_password(32, true, true), 'user_email' => '', 'role' => 'customer']);
        self::assertFalse(is_wp_error($id));
        self::assertGreaterThan(0, $id);
        update_user_meta($id, 'billing_phone', '09120000000');
        self::assertSame('', get_userdata($id)->user_email);
        return (int) $id;
    }

    private function registeredAdapter(): WooCommerceOrderAdapter {
        global $wp_filter;
        $adapters = [];
        foreach ($wp_filter['woocommerce_can_reduce_order_stock']->callbacks ?? [] as $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'];
                if (is_array($function) && $function[0] instanceof WooCommerceOrderAdapter) {
                    $adapters[spl_object_id($function[0])] = $function[0];
                }
            }
        }
        self::assertCount(1, $adapters, 'Use the real registered adapter recognized by native managed-order guards.');
        return array_values($adapters)[0];
    }

    private function freshOrder(int $id): WC_Order {
        // Drop Woo's object cache and all WP cache entries in this disposable
        // sandbox, then force a new object and a fresh metadata read.
        wc_get_container()->get(OrderCache::class)->remove($id);
        wp_cache_flush();
        $order = new WC_Order($id);
        self::assertSame($id, $order->get_id());
        $order->read_meta_data(true);
        return $order;
    }

    private function assertOrderSql(int $id, string $status, int $customer, int $total): void {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT id,type,status,customer_id,total_amount,currency FROM %i WHERE id=%d',
            $wpdb->prefix . 'wc_orders', $id), ARRAY_A);
        self::assertSame('', $wpdb->last_error);
        self::assertIsArray($row);
        self::assertSame($id, (int) $row['id']);
        self::assertSame('shop_order', $row['type']);
        self::assertSame('wc-' . $status, $row['status']);
        self::assertSame($customer, (int) $row['customer_id']);
        self::assertSame((float) $total, (float) $row['total_amount']);
        self::assertSame('IRT', $row['currency']);
    }

    private function assertMetaSql(int $id, string $key, string $value): void {
        global $wpdb;
        $values = $wpdb->get_col($wpdb->prepare('SELECT meta_value FROM %i WHERE order_id=%d AND meta_key=%s',
            $wpdb->prefix . 'wc_orders_meta', $id, $key));
        self::assertSame('', $wpdb->last_error);
        self::assertSame([$value], $values);
    }

    private function assertStockSql(int $id, int $product, bool $reduced, int $quantity): void {
        global $wpdb;
        $stored = $wpdb->get_var($wpdb->prepare('SELECT order_stock_reduced FROM %i WHERE order_id=%d',
            $wpdb->prefix . 'wc_order_operational_data', $id));
        self::assertSame('', $wpdb->last_error);
        self::assertSame($reduced ? '1' : '0', $stored);
        // Products still correctly use postmeta; this ID is never an order ID.
        $stock = $wpdb->get_var($wpdb->prepare('SELECT meta_value FROM %i WHERE post_id=%d AND meta_key=%s',
            $wpdb->postmeta, $product, '_stock'));
        self::assertSame('', $wpdb->last_error);
        self::assertSame((string) $quantity, $stock);
    }

    private function assertNoteSqlAndApi(int $id, int $noteId, string $content): void {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT comment_post_ID,comment_type,comment_content FROM %i WHERE comment_ID=%d',
            $wpdb->comments, $noteId), ARRAY_A);
        self::assertSame('', $wpdb->last_error);
        self::assertIsArray($row);
        self::assertSame($id, (int) $row['comment_post_ID']);
        self::assertSame('order_note', $row['comment_type']);
        self::assertSame($content, $row['comment_content']);
        $notes = wc_get_order_notes(['order_id' => $id, 'limit' => 100]);
        $matching = array_values(array_filter($notes, static fn ($note): bool => (int) $note->id === $noteId));
        self::assertCount(1, $matching);
        self::assertSame($content, $matching[0]->content);
    }

    private function assertNoLegacyOrderStorage(int $id, array $keys): void {
        global $wpdb;
        // HPOS may reserve this ID with shop_order_placehold; that is not a
        // synchronized legacy order and must not be mistaken for one.
        $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE ID=%d AND post_type=%s',
            $wpdb->posts, $id, 'shop_order'));
        self::assertSame('', $wpdb->last_error);
        self::assertSame('0', $count);
        foreach ($keys as $key) {
            $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE post_id=%d AND meta_key=%s',
                $wpdb->postmeta, $id, $key));
            self::assertSame('', $wpdb->last_error);
            self::assertSame('0', $count);
        }
    }
}
